<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/school_attendance.php';
require_once APP_ROOT . '/app/school_staff_attendance.php';

$schoolKey = trim((string) ($_GET['school'] ?? $_POST['school'] ?? ''));
$school = null;
$device = null;
$authorizedKioskDevices = [];
if ($schoolKey !== '' && strlen($schoolKey) <= 40) {
    $schoolStmt = db()->prepare(
        'SELECT id, name, short_name FROM schools
         WHERE short_name = ? AND status = "active" AND deleted_at IS NULL LIMIT 1'
    );
    $schoolStmt->execute([$schoolKey]);
    $school = $schoolStmt->fetch() ?: null;
}
$pdo = db();
if ($school !== null) {
    $device = school_staff_attendance_kiosk_device($pdo, (int) $school['id']);
} elseif ($schoolKey === '') {
    $authorizedKioskDevices = school_staff_attendance_authorized_kiosk_devices($pdo);
    if (count($authorizedKioskDevices) === 1) {
        $authorizedDevice = $authorizedKioskDevices[0];
        $school = [
            'id' => (int) $authorizedDevice['school_id'],
            'name' => (string) $authorizedDevice['school_name'],
            'short_name' => (string) $authorizedDevice['short_name'],
        ];
        $device = [
            'id' => (int) $authorizedDevice['id'],
            'device_name' => (string) $authorizedDevice['device_name'],
        ];
    }
}
$deviceAuthorized = $school !== null && $device !== null;
$deviceSelectionRequired = $school === null && count($authorizedKioskDevices) > 1;
$unauthorizedKiosk = !$deviceAuthorized && !$deviceSelectionRequired;
$employmentReady = school_staff_employment_schema_ready();
$employmentEligibilitySql = $employmentReady
    ? ' AND (staff.employment_status = "active"
        OR (staff.employment_status = "termination_scheduled" AND staff.employment_effective_on > ?))'
    : '';
$employmentEligibilityParams = $employmentReady ? [date('Y-m-d')] : [];
if ($deviceAuthorized && $employmentReady) {
    school_staff_employment_process_due((int) $school['id']);
}
$kioskConfigured = false;
$timezone = null;
if ($school !== null) {
    $configuredStmt = $pdo->prepare(
        'SELECT setting_value FROM school_settings
         WHERE school_id = ? AND setting_key = "staff_attendance_kiosk_configured" LIMIT 1'
    );
    $configuredStmt->execute([(int) $school['id']]);
    $kioskConfigured = $configuredStmt->fetchColumn() === '1';
    $timezone = school_staff_attendance_timezone($pdo, (int) $school['id']);
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $school !== null) {
    $schoolId = (int) $school['id'];
    $accessNowString = (new DateTimeImmutable('now', $timezone))->format('Y-m-d H:i:s');
    $accessLogKey = 'staff_attendance_kiosk_access_' . $schoolId;
    $lastAccessLogAt = (int) ($_SESSION[$accessLogKey] ?? 0);
    if ($lastAccessLogAt <= time() - 60) {
        school_staff_attendance_security_event(
            $pdo,
            $schoolId,
            $deviceAuthorized ? 'device_accessed' : 'unauthorized_device',
            $deviceAuthorized ? (int) $device['id'] : null,
            null,
            null,
            keyed_hash('staff-attendance-kiosk-access|' . $schoolId . '|' . (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')),
            $deviceAuthorized ? 'Authorized kiosk page opened' : 'Kiosk page opened without an active device credential',
            $accessNowString
        );
        $_SESSION[$accessLogKey] = time();
    }
    if ($deviceAuthorized) {
        $pdo->prepare(
            'UPDATE school_staff_attendance_kiosk_devices
             SET last_used_at = ?
             WHERE id = ? AND school_id = ? AND deactivated_at IS NULL'
        )->execute([$accessNowString, (int) $device['id'], $schoolId]);
    }
}

$message = '';
$messageKind = 'error';
$confirmation = null;
$kioskMode = (string) ($_GET['mode'] ?? '');
$pinHelpMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $pinHelpMessage = (string) ($_SESSION['staff_attendance_pin_help_notice'] ?? '');
    unset($_SESSION['staff_attendance_pin_help_notice']);
    $kioskNotice = $_SESSION['staff_attendance_kiosk_notice'] ?? null;
    unset($_SESSION['staff_attendance_kiosk_notice']);
    if (is_array($kioskNotice) && $school !== null
        && (int) ($kioskNotice['school_id'] ?? 0) === (int) $school['id']) {
        $message = (string) ($kioskNotice['message'] ?? '');
        $messageKind = (string) ($kioskNotice['kind'] ?? 'error');
        $confirmation = is_array($kioskNotice['confirmation'] ?? null)
            ? $kioskNotice['confirmation']
            : null;
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $deviceAuthorized
    && (string) ($_POST['action'] ?? '') === 'request_pin_help') {
    require_csrf();
    $staffIdentifier = trim((string) ($_POST['staff_identifier'] ?? ''));
    $_SESSION['staff_attendance_pin_help_notice'] = $staffIdentifier !== ''
        && strlen($staffIdentifier) <= 254
        ? 'For privacy, the kiosk does not look up or reveal staff details. Please contact the Headmaster or authorized administrator in person to verify your identity and start a PIN reset.'
        : 'Enter your Staff ID. Then contact the Headmaster or authorized administrator in person for identity verification and PIN recovery.';
    redirect('attendance_kiosk.php?school=' . rawurlencode((string) $school['short_name']) . '&mode=forgot-pin');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $deviceAuthorized
    && (string) ($_POST['action'] ?? '') === 'complete_pin_reset') {
    require_csrf();
    $staffIdentifier = trim((string) ($_POST['staff_identifier'] ?? ''));
    $resetCode = trim((string) ($_POST['reset_code'] ?? ''));
    $newPin = (string) ($_POST['new_pin'] ?? '');
    $confirmPin = (string) ($_POST['confirm_pin'] ?? '');
    $recoveryMessage = 'PIN reset could not be completed. Ask the Headmaster or authorized administrator to verify your identity and issue a new code.';
    $recoverySucceeded = false;
    if ($staffIdentifier !== '' && strlen($staffIdentifier) <= 50
        && preg_match('/^\d{8}$/D', $resetCode) === 1
        && preg_match('/^\d{6,10}$/D', $newPin) === 1
        && hash_equals($newPin, $confirmPin)) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $staffStmt = $pdo->prepare(
                'SELECT staff.id, staff.user_id, staff.employee_number
                 FROM school_staff staff
                 WHERE staff.school_id = ? AND staff.status = "active"
                   ' . $employmentEligibilitySql . '
                   AND staff.employee_number IS NOT NULL
                   AND LOWER(TRIM(staff.employee_number)) = LOWER(?)
                   AND staff.role_name NOT IN ("school_parent", "school_student")
                   AND EXISTS (
                       SELECT 1 FROM school_memberships membership
                       WHERE membership.school_id = staff.school_id
                         AND membership.user_id = staff.user_id
                         AND membership.status = "active"
                         AND membership.role_name NOT IN ("school_parent", "school_student")
                   )
                 LIMIT 2 FOR UPDATE'
            );
            $staffStmt->execute(array_merge([(int) $school['id']], $employmentEligibilityParams, [$staffIdentifier]));
            $staffMatches = $staffStmt->fetchAll();
            $staff = count($staffMatches) === 1 ? $staffMatches[0] : null;
            $reset = null;
            if ($staff !== null) {
                $resetStmt = $pdo->prepare(
                    'SELECT id, token_hash, failed_attempts, expires_at
                     FROM school_staff_attendance_pin_resets
                     WHERE school_id = ? AND staff_id = ?
                       AND completed_at IS NULL AND revoked_at IS NULL
                     ORDER BY id DESC LIMIT 1 FOR UPDATE'
                );
                $resetStmt->execute([(int) $school['id'], (int) $staff['id']]);
                $reset = $resetStmt->fetch() ?: null;
            }
            $now = new DateTimeImmutable('now', $timezone);
            $validCode = $reset !== null
                && (int) $reset['failed_attempts'] < 5
                && new DateTimeImmutable((string) $reset['expires_at'], $timezone) >= $now
                && hash_equals((string) $reset['token_hash'], keyed_hash($resetCode));
            if (!$validCode) {
                if ($reset !== null && (int) $reset['failed_attempts'] < 5
                    && new DateTimeImmutable((string) $reset['expires_at'], $timezone) >= $now) {
                    $attempts = (int) $reset['failed_attempts'] + 1;
                    $pdo->prepare(
                        'UPDATE school_staff_attendance_pin_resets
                         SET failed_attempts = ?, revoked_at = IF(? >= 5, NOW(), revoked_at)
                         WHERE id = ? AND school_id = ?'
                    )->execute([$attempts, $attempts, (int) $reset['id'], (int) $school['id']]);
                }
                $pdo->commit();
            } else {
                $existingPins = $pdo->prepare(
                    'SELECT staff_id, pin_hash FROM school_staff_attendance_credentials
                     WHERE school_id = ? FOR UPDATE'
                );
                $existingPins->execute([(int) $school['id']]);
                foreach ($existingPins->fetchAll() as $existingPin) {
                    if ((int) $existingPin['staff_id'] !== (int) $staff['id']
                        && password_verify(password_input($newPin), (string) $existingPin['pin_hash'])) {
                        throw new RuntimeException('That PIN is already in use at this school. Choose a different PIN.');
                    }
                }
                $credentialUpdate = $pdo->prepare(
                    'UPDATE school_staff_attendance_credentials
                     SET pin_hash = ?, failed_attempts = 0, locked_until = NULL,
                         last_failed_at = NULL, pin_changed_at = NOW()
                     WHERE school_id = ? AND staff_id = ?'
                );
                $credentialUpdate->execute([
                    make_password_hash($newPin),
                    (int) $school['id'],
                    (int) $staff['id'],
                ]);
                if ($credentialUpdate->rowCount() !== 1) {
                    throw new RuntimeException('The PIN credential could not be updated.');
                }
                $pdo->prepare(
                    'UPDATE school_staff_attendance_pin_resets
                     SET completed_at = NOW(), completed_by = ?
                     WHERE id = ? AND school_id = ? AND completed_at IS NULL AND revoked_at IS NULL'
                )->execute([(int) $staff['user_id'], (int) $reset['id'], (int) $school['id']]);
                audit(
                    (int) $staff['user_id'],
                    'school_staff_attendance_pin_reset_completed',
                    'school_id=' . (int) $school['id'] . ';staff_id=' . (int) $staff['id']
                        . ';reset_id=' . (int) $reset['id']
                );
                $pdo->commit();
                $recoverySucceeded = true;
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception instanceof RuntimeException
                && $exception->getMessage() === 'That PIN is already in use at this school. Choose a different PIN.') {
                $recoveryMessage = $exception->getMessage();
            } else {
                error_log('Staff attendance PIN recovery failed: ' . $exception->getMessage());
            }
        }
    } elseif (preg_match('/^\d{6,10}$/D', $newPin) !== 1 || !hash_equals($newPin, $confirmPin)) {
        $recoveryMessage = 'Enter matching new PINs containing 6–10 digits.';
    } elseif (preg_match('/^\d{8}$/D', $resetCode) !== 1) {
        $recoveryMessage = 'Enter the 8-digit one-time code supplied by the Headmaster or authorized administrator.';
    }

    if ($recoverySucceeded) {
        $_SESSION['staff_attendance_kiosk_notice'] = [
            'school_id' => (int) $school['id'],
            'message' => 'Your new attendance PIN has been saved. You can now check in using your Staff ID and new PIN.',
            'kind' => 'success',
            'confirmation' => null,
        ];
        redirect('attendance_kiosk.php?school=' . rawurlencode((string) $school['short_name']));
    }
    $_SESSION['staff_attendance_pin_help_notice'] = $recoveryMessage;
    redirect('attendance_kiosk.php?school=' . rawurlencode((string) $school['short_name']) . '&mode=reset-pin');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $school !== null
    && (string) ($_POST['action'] ?? '') === 'attendance_transaction') {
    require_csrf();
    $clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $clientKey = keyed_hash('staff-attendance-kiosk|' . (int) $school['id'] . '|' . ($device['id'] ?? 'unregistered') . '|' . $clientIp);
    if ($device === null) {
        school_staff_attendance_security_event(
            $pdo,
            (int) $school['id'],
            'unauthorized_device',
            null,
            null,
            null,
            $clientKey,
            'Attendance transaction rejected from an unregistered computer'
        );
        $message = 'This device is not authorised for staff attendance. Please contact your school administrator.';
    } elseif (!$kioskConfigured) {
        school_staff_attendance_security_event(
            $pdo,
            (int) $school['id'],
            'kiosk_not_configured',
            (int) $device['id'],
            null,
            null,
            $clientKey,
            'Attendance transaction rejected before administrator setup'
        );
        $message = 'The school attendance kiosk has not been configured. Please contact the Headmaster.';
    } else {
    $staffIdentifier = trim((string) ($_POST['staff_identifier'] ?? $_POST['staff_number'] ?? ''));
    $pin = (string) ($_POST['attendance_pin'] ?? '');
    $attendanceAction = (string) ($_POST['attendance_action'] ?? '');
    if (!in_array($attendanceAction, ['check_in', 'check_out'], true)) {
        $message = 'Choose whether you are checking in or checking out.';
    } elseif ($staffIdentifier === '' || strlen($staffIdentifier) > 50 || preg_match('/^\d{6,10}$/D', $pin) !== 1) {
        $message = 'Enter your Staff ID and your 6–10 digit attendance PIN.';
    } else {
        $now = new DateTimeImmutable('now', $timezone);
        $nowString = $now->format('Y-m-d H:i:s');

        $pdo->beginTransaction();
        try {
            $activeDeviceStmt = $pdo->prepare(
                'SELECT id FROM school_staff_attendance_kiosk_devices
                 WHERE id = ? AND school_id = ? AND deactivated_at IS NULL FOR UPDATE'
            );
            $activeDeviceStmt->execute([(int) $device['id'], (int) $school['id']]);
            if (!$activeDeviceStmt->fetchColumn()) {
                $pdo->commit();
                $device = null;
                $message = 'This device is not authorised for staff attendance. Please contact your school administrator.';
            } else {
            $pdo->prepare(
                'UPDATE school_staff_attendance_kiosk_devices
                 SET last_used_at = ? WHERE id = ? AND school_id = ? AND deactivated_at IS NULL'
            )->execute([$nowString, (int) $device['id'], (int) $school['id']]);
            $pdo->prepare(
                'DELETE FROM school_staff_attendance_kiosk_limits
                 WHERE school_id = ? AND window_started_at < DATE_SUB(?, INTERVAL 1 DAY)'
            )->execute([(int) $school['id'], $nowString]);
            $limitInsert = $pdo->prepare(
                'INSERT INTO school_staff_attendance_kiosk_limits
                    (school_id, client_key, failed_attempts, window_started_at)
                 VALUES (?, ?, 0, ?)
                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
            );
            $limitInsert->execute([(int) $school['id'], $clientKey, $nowString]);
            $limitStmt = $pdo->prepare(
                'SELECT failed_attempts, window_started_at, locked_until
                 FROM school_staff_attendance_kiosk_limits
                 WHERE school_id = ? AND client_key = ? FOR UPDATE'
            );
            $limitStmt->execute([(int) $school['id'], $clientKey]);
            $limit = $limitStmt->fetch();
            $windowStart = new DateTimeImmutable((string) $limit['window_started_at'], $timezone);
            if ($windowStart < $now->modify('-15 minutes')) {
                $pdo->prepare(
                    'UPDATE school_staff_attendance_kiosk_limits
                     SET failed_attempts = 0, window_started_at = ?, locked_until = NULL
                     WHERE school_id = ? AND client_key = ?'
                )->execute([$nowString, (int) $school['id'], $clientKey]);
                $limit['failed_attempts'] = 0;
                $limit['locked_until'] = null;
            }
            if ($limit['locked_until'] !== null && new DateTimeImmutable((string) $limit['locked_until'], $timezone) > $now) {
                $pdo->commit();
                $message = 'Attendance verification is temporarily unavailable from this kiosk. Please contact the school office.';
            } else {
                $staffStmt = $pdo->prepare(
                    'SELECT staff.id, staff.employee_number, staff.role_name, account.full_name,
                            credential.id AS credential_id, credential.pin_hash,
                            credential.failed_attempts, credential.locked_until
                     FROM school_staff staff
                     JOIN users account ON account.id = staff.user_id AND account.status = "active"
                       AND account.email_verified_at IS NOT NULL
                     LEFT JOIN school_staff_attendance_credentials credential
                       ON credential.school_id = staff.school_id AND credential.staff_id = staff.id
                     WHERE staff.school_id = ? AND staff.status = "active"
                       ' . $employmentEligibilitySql . '
                       AND EXISTS (
                           SELECT 1 FROM school_memberships membership
                           WHERE membership.school_id = staff.school_id
                             AND membership.user_id = staff.user_id
                             AND membership.status = "active"
                             AND membership.role_name NOT IN ("school_parent", "school_student")
                       )
                       AND LOWER(TRIM(COALESCE(staff.employee_number, ""))) = LOWER(?)
                       AND staff.role_name NOT IN ("school_parent", "school_student")
                     LIMIT 2 FOR UPDATE'
                );
                $staffStmt->execute(array_merge([(int) $school['id']], $employmentEligibilityParams, [$staffIdentifier]));
                $matches = $staffStmt->fetchAll();
                $staff = count($matches) === 1 ? $matches[0] : null;
                $schedule = $staff !== null
                    ? school_staff_attendance_schedule($pdo, (int) $school['id'], (string) $staff['role_name'])
                    : null;
                $roleScheduleConfigured = false;
                if ($staff !== null) {
                    $roleScheduleStmt = $pdo->prepare(
                        'SELECT 1 FROM school_staff_attendance_schedules
                         WHERE school_id = ? AND role_name = ? LIMIT 1'
                    );
                    $roleScheduleStmt->execute([(int) $school['id'], (string) $staff['role_name']]);
                    $roleScheduleConfigured = (bool) $roleScheduleStmt->fetchColumn();
                }
                $credentialLocked = $staff
                    && $staff['locked_until'] !== null
                    && new DateTimeImmutable((string) $staff['locked_until'], $timezone) > $now;
                if ($staff && $staff['credential_id'] !== null
                    && $staff['locked_until'] !== null && !$credentialLocked) {
                    $pdo->prepare(
                        'UPDATE school_staff_attendance_credentials
                         SET failed_attempts = 0, locked_until = NULL, last_failed_at = NULL
                         WHERE id = ?'
                    )->execute([(int) $staff['credential_id']]);
                    $staff['failed_attempts'] = 0;
                    $staff['locked_until'] = null;
                }
                $validPin = $staff !== null
                    && $staff['credential_id'] !== null
                    && !$credentialLocked
                    && password_verify(password_input($pin), (string) $staff['pin_hash']);

                if (!$validPin) {
                    if ($staff && $staff['credential_id'] !== null && !$credentialLocked) {
                        $attemptLimit = max(3, (int) $schedule['failed_pin_attempt_limit']);
                        $lockoutMinutes = max(1, (int) $schedule['lockout_minutes']);
                        $pdo->prepare(
                            'UPDATE school_staff_attendance_credentials
                             SET locked_until = CASE WHEN failed_attempts >= ?
                                     THEN TIMESTAMPADD(MINUTE, ?, ?) ELSE NULL END,
                                 failed_attempts = failed_attempts + 1,
                                 last_failed_at = ?
                             WHERE id = ?'
                        )->execute([$attemptLimit - 1, $lockoutMinutes, $nowString, $nowString, (int) $staff['credential_id']]);
                        school_staff_attendance_security_event(
                            $pdo,
                            (int) $school['id'],
                            'invalid_pin',
                            (int) $device['id'],
                            (int) $staff['id'],
                            null,
                            $clientKey,
                            'Invalid attendance PIN',
                            $nowString
                        );
                    }
                    $pdo->prepare(
                        'UPDATE school_staff_attendance_kiosk_limits
                         SET locked_until = CASE WHEN failed_attempts >= 19
                                 THEN DATE_ADD(?, INTERVAL 15 MINUTE) ELSE NULL END,
                             failed_attempts = failed_attempts + 1
                         WHERE school_id = ? AND client_key = ?'
                    )->execute([$nowString, (int) $school['id'], $clientKey]);
                    if ($staff === null || $staff['credential_id'] === null || $credentialLocked) {
                        school_staff_attendance_security_event(
                            $pdo,
                            (int) $school['id'],
                            $credentialLocked ? 'pin_lockout' : 'invalid_identifier_or_pin',
                            (int) $device['id'],
                            $staff !== null ? (int) $staff['id'] : null,
                            null,
                            $clientKey,
                            'Attendance credentials could not be verified',
                            $nowString
                        );
                    }
                    $pdo->commit();
                    $message = 'Staff ID or attendance PIN could not be verified.';
                } else {
                    $today = $now->format('Y-m-d');
                    if (!$roleScheduleConfigured) {
                        school_staff_attendance_security_event(
                            $pdo,
                            (int) $school['id'],
                            'role_schedule_missing',
                            (int) $device['id'],
                            (int) $staff['id'],
                            null,
                            $clientKey,
                            'Staff category attendance schedule is not configured',
                            $nowString
                        );
                        $pdo->commit();
                        $message = 'The attendance schedule for your staff category is not configured. Please contact the Headmaster.';
                    } elseif ($attendanceAction === 'check_in'
                        && !school_staff_attendance_check_in_time_allowed($now)) {
                        school_staff_attendance_security_event(
                            $pdo,
                            (int) $school['id'],
                            'check_in_outside_attendance_hours',
                            (int) $device['id'],
                            (int) $staff['id'],
                            null,
                            $clientKey,
                            'Check-in attempt outside 05:30–14:00 school time',
                            $nowString
                        );
                        $pdo->commit();
                        $message = 'Check-in is available from 5:30 AM until before 2:00 PM school time. Choose check-out if you are leaving.';
                    } elseif (!school_staff_attendance_is_working_day($pdo, (int) $school['id'], $today, $schedule)) {
                        $pdo->commit();
                        $message = 'Staff attendance is not scheduled for today. Please contact the school office if this is unexpected.';
                    } else {
                        $existingStmt = $pdo->prepare(
                            'SELECT * FROM school_staff_attendance
                             WHERE school_id = ? AND staff_id = ? AND attendance_date = ? FOR UPDATE'
                        );
                        $existingStmt->execute([(int) $school['id'], (int) $staff['id'], $today]);
                        $existing = $existingStmt->fetch() ?: null;
                        if ($attendanceAction === 'check_out' || $existing !== null) {
                            if ($attendanceAction === 'check_in') {
                                school_staff_attendance_security_event(
                                    $pdo,
                                    (int) $school['id'],
                                    'duplicate_check_in',
                                    (int) $device['id'],
                                    (int) $staff['id'],
                                    null,
                                    $clientKey,
                                    'Second check-in rejected',
                                    $nowString
                                );
                                $pdo->commit();
                                $recordedArrival = !empty($existing['arrival_at'])
                                    ? (new DateTimeImmutable((string) $existing['arrival_at'], $timezone))->format('g:i A')
                                    : null;
                                $message = $recordedArrival !== null
                                    ? 'You have already recorded your arrival today. Your original arrival time is ' . $recordedArrival . '.'
                                    : 'Attendance for today has already been recorded. Contact the school office if you need help.';
                            } elseif ($existing === null || empty($existing['arrival_at'])) {
                                school_staff_attendance_security_event(
                                    $pdo,
                                    (int) $school['id'],
                                    'checkout_without_arrival',
                                    (int) $device['id'],
                                    (int) $staff['id'],
                                    null,
                                    $clientKey,
                                    'Check-out rejected because no arrival record exists',
                                    $nowString
                                );
                                $pdo->commit();
                                $message = 'An arrival must be recorded before checking out. Contact the Headmaster if you need assistance.';
                            } elseif (!empty($existing['departure_at'])) {
                                school_staff_attendance_security_event(
                                    $pdo,
                                    (int) $school['id'],
                                    'duplicate_check_out',
                                    (int) $device['id'],
                                    (int) $staff['id'],
                                    null,
                                    $clientKey,
                                    'Second departure check-out rejected',
                                    $nowString
                                );
                                $pdo->commit();
                                $recordedDeparture = (new DateTimeImmutable((string) $existing['departure_at'], $timezone))->format('g:i A');
                                $message = 'You have already recorded your departure today. Your original departure time is ' . $recordedDeparture . '.';
                            } else {
                                $arrival = new DateTimeImmutable((string) $existing['arrival_at'], $timezone);
                                if ($now <= $arrival) {
                                    throw new RuntimeException('The clock-out time must follow the recorded arrival time.');
                                }
                                $closing = DateTimeImmutable::createFromFormat(
                                    '!Y-m-d H:i',
                                    $today . ' ' . $schedule['closing_time'],
                                    $timezone
                                );
                                if (!$closing) {
                                    throw new RuntimeException('The staff closing-time schedule is invalid.');
                                }
                                $earlyThreshold = $closing->modify('-' . (int) $schedule['early_departure_threshold_minutes'] . ' minutes');
                                $workingMinutes = $schedule['clock_out_enabled']
                                    ? min(65535, (int) floor(($now->getTimestamp() - $arrival->getTimestamp()) / 60))
                                    : null;
                                $earlyDeparture = $schedule['clock_out_enabled'] && $now < $earlyThreshold;
                                $clockOutUpdate = $pdo->prepare(
                                    'UPDATE school_staff_attendance
                                     SET departure_at = ?, working_minutes = ?, early_departure = ?,
                                         departure_verification_method = "kiosk_verified"
                                     WHERE id = ? AND school_id = ? AND departure_at IS NULL'
                                );
                                $clockOutUpdate->execute([
                                    $nowString,
                                    $workingMinutes,
                                    $earlyDeparture ? 1 : 0,
                                    (int) $existing['id'],
                                    (int) $school['id'],
                                ]);
                                if ($clockOutUpdate->rowCount() !== 1) {
                                    throw new RuntimeException('The clock-out could not be recorded. Please contact the school office.');
                                }
                                $updated = $existing;
                                $updated['departure_at'] = $nowString;
                                $updated['working_minutes'] = $workingMinutes;
                                $updated['early_departure'] = $earlyDeparture;
                                $updated['departure_verification_method'] = 'kiosk_verified';
                                school_staff_attendance_write_audit(
                                    $pdo,
                                    (int) $school['id'],
                                    (int) $existing['id'],
                                    (int) $staff['id'],
                                    null,
                                    'kiosk_check_out',
                                    school_staff_attendance_snapshot($existing),
                                    school_staff_attendance_snapshot($updated),
                                    null,
                                    'kiosk_verified'
                                );
                                $pdo->prepare(
                                    'UPDATE school_staff_attendance_credentials
                                     SET failed_attempts = 0, locked_until = NULL
                                     WHERE id = ?'
                                )->execute([(int) $staff['credential_id']]);
                                $pdo->prepare(
                                    'UPDATE school_staff_attendance_kiosk_limits
                                     SET failed_attempts = 0, locked_until = NULL
                                     WHERE school_id = ? AND client_key = ?'
                                )->execute([(int) $school['id'], $clientKey]);
                                $pdo->commit();
                                $confirmation = [
                                    'name' => (string) $staff['full_name'],
                                    'staff_id' => (string) ($staff['employee_number'] ?: 'Not assigned'),
                                    'date' => $now->format('j M Y'),
                                    'time' => $now->format('g:i A'),
                                    'status' => 'Clock-out recorded',
                                ];
                                $messageKind = 'success';
                            }
                        } else {
                            $punctuality = school_staff_attendance_classify_arrival($now, $today, $schedule);
                            $insert = $pdo->prepare(
                                'INSERT INTO school_staff_attendance
                                    (school_id, staff_id, attendance_date, arrival_at, expected_reporting_time,
                                     status, punctuality_status, minutes_late, verification_method, remarks,
                                     recorded_by, recorded_at)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, "kiosk_verified", NULL, NULL, ?)'
                            );
                            $insert->execute([
                                (int) $school['id'],
                                (int) $staff['id'],
                                $today,
                                $nowString,
                                $schedule['reporting_time'],
                                $punctuality['legacy_status'],
                                $punctuality['status'],
                                $punctuality['minutes_late'],
                                $nowString,
                            ]);
                            $attendanceId = (int) $pdo->lastInsertId();
                            $record = [
                                'attendance_date' => $today,
                                'arrival_at' => $nowString,
                                'departure_at' => null,
                                'status' => $punctuality['legacy_status'],
                                'punctuality_status' => $punctuality['status'],
                                'minutes_late' => $punctuality['minutes_late'],
                                'working_minutes' => null,
                                'early_departure' => false,
                                'verification_method' => 'kiosk_verified',
                                'departure_verification_method' => null,
                                'expected_reporting_time' => $schedule['reporting_time'],
                                'remarks' => null,
                            ];
                            school_staff_attendance_write_audit(
                                $pdo,
                                (int) $school['id'],
                                $attendanceId,
                                (int) $staff['id'],
                                null,
                                'kiosk_check_in',
                                null,
                                school_staff_attendance_snapshot($record),
                                null,
                                'kiosk_verified'
                            );
                            if (in_array($punctuality['status'], ['late', 'very_late'], true)) {
                                school_staff_attendance_security_event(
                                    $pdo,
                                    (int) $school['id'],
                                    'late_check_in',
                                    (int) $device['id'],
                                    (int) $staff['id'],
                                    null,
                                    $clientKey,
                                    'Arrival status: ' . $punctuality['status'],
                                    $nowString
                                );
                            }
                            $pdo->prepare(
                                'UPDATE school_staff_attendance_credentials
                                 SET failed_attempts = 0, locked_until = NULL
                                 WHERE id = ?'
                            )->execute([(int) $staff['credential_id']]);
                            $pdo->prepare(
                                'UPDATE school_staff_attendance_kiosk_limits
                                 SET failed_attempts = 0, locked_until = NULL
                                 WHERE school_id = ? AND client_key = ?'
                            )->execute([(int) $school['id'], $clientKey]);
                            $pdo->commit();
                            $confirmation = [
                                'name' => (string) $staff['full_name'],
                                'staff_id' => (string) ($staff['employee_number'] ?: 'Not assigned'),
                                'date' => $now->format('j M Y'),
                                'time' => $now->format('g:i A'),
                                'status' => ucwords(str_replace('_', ' ', $punctuality['status'])),
                            ];
                            $messageKind = 'success';
                        }
                    }
                }
            }
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Staff attendance kiosk request failed: ' . $exception->getMessage());
            if ($exception instanceof PDOException && (int) ($exception->errorInfo[1] ?? 0) === 1062) {
                school_staff_attendance_security_event(
                    $pdo,
                    (int) $school['id'],
                    $attendanceAction === 'check_out' ? 'duplicate_check_out' : 'duplicate_check_in',
                    (int) $device['id'],
                    null,
                    null,
                    $clientKey,
                    'Concurrent duplicate attendance request rejected',
                    $nowString
                );
                $message = $attendanceAction === 'check_out'
                    ? 'Your departure has already been recorded. No second check-out was created.'
                    : 'Your arrival has already been recorded. No second check-in was created.';
            } elseif ($exception instanceof RuntimeException) {
                $message = $exception->getMessage();
            } else {
                $message = 'Attendance could not be recorded. Please contact the school office.';
            }
        }
    }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $school !== null
    && (string) ($_POST['action'] ?? '') === 'attendance_transaction') {
    $_SESSION['staff_attendance_kiosk_notice'] = [
        'school_id' => (int) $school['id'],
        'message' => $message,
        'kind' => $messageKind,
        'confirmation' => $confirmation,
    ];
    redirect('attendance_kiosk.php?school=' . rawurlencode((string) $school['short_name']));
}

header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
render_header('Staff attendance kiosk', false);
?>
<section class="attendance-kiosk">
    <div class="attendance-kiosk-brand">
        <p class="eyebrow">STAFF ATTENDANCE KIOSK</p>
        <?php if ($deviceAuthorized): ?>
            <h1><?= e((string) $school['name']) ?></h1>
            <p>Enter your Staff ID and attendance PIN. The kiosk records the current school time.</p>
        <?php elseif ($deviceSelectionRequired): ?>
            <h1>Choose a school</h1>
            <p>This device is authorised for more than one school attendance kiosk.</p>
        <?php else: ?>
            <h1>Staff attendance</h1>
            <p>Access is limited to school-authorised attendance devices.</p>
        <?php endif; ?>
    </div>
    <?php if ($unauthorizedKiosk): ?>
        <p class="notice notice-error" role="alert">This device is not authorised for staff attendance. Please contact your school administrator.</p>
    <?php elseif ($deviceSelectionRequired): ?>
        <h2>Select your school</h2>
        <div class="attendance-kiosk-school-list">
            <?php foreach ($authorizedKioskDevices as $authorizedDevice): ?>
                <a class="button button-primary" href="attendance_kiosk.php?school=<?= rawurlencode((string) $authorizedDevice['short_name']) ?>">
                    <?= e((string) $authorizedDevice['school_name']) ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php elseif ($school && $kioskMode === 'forgot-pin'): ?>
        <h2>Forgot your attendance PIN?</h2>
        <?php if ($pinHelpMessage !== ''): ?><p class="notice notice-info" role="status"><?= e($pinHelpMessage) ?></p><?php endif; ?>
        <p>Enter your Staff ID. The kiosk will not reveal whether it belongs to a staff member or show any account details. You must speak to the Headmaster or authorized administrator in person for identity verification and PIN recovery.</p>
        <form method="post" action="attendance_kiosk.php?school=<?= rawurlencode((string) $school['short_name']) ?>&amp;mode=forgot-pin" class="attendance-kiosk-form" autocomplete="off">
            <?= csrf_field() ?><input type="hidden" name="action" value="request_pin_help">
            <label for="forgot-pin-staff-id">Staff ID</label>
            <input id="forgot-pin-staff-id" name="staff_identifier" type="text" maxlength="50" autocomplete="off" required autofocus>
            <button class="button button-primary" type="submit">Show recovery instructions</button>
        </form>
        <p class="attendance-kiosk-reset"><a href="attendance_kiosk.php?school=<?= rawurlencode((string) $school['short_name']) ?>&amp;mode=reset-pin">I have a one-time reset code</a></p>
        <p class="attendance-kiosk-reset"><a href="attendance_kiosk.php?school=<?= rawurlencode((string) $school['short_name']) ?>">Return to attendance kiosk</a></p>
    <?php elseif ($school && $kioskMode === 'reset-pin'): ?>
        <h2>Set a new attendance PIN</h2>
        <?php if ($pinHelpMessage !== ''): ?><p class="notice notice-error" role="alert"><?= e($pinHelpMessage) ?></p><?php endif; ?>
        <p>Complete this only after the Headmaster or authorized administrator has verified you in person and given you a one-time code. The code expires after 15 minutes and allows up to five attempts.</p>
        <form method="post" action="attendance_kiosk.php?school=<?= rawurlencode((string) $school['short_name']) ?>&amp;mode=reset-pin" class="attendance-kiosk-form" autocomplete="off" data-kiosk-idle-reset data-reset-url="attendance_kiosk.php?school=<?= rawurlencode((string) $school['short_name']) ?>&amp;mode=reset-pin" data-idle-seconds="120">
            <?= csrf_field() ?><input type="hidden" name="action" value="complete_pin_reset">
            <label for="recovery-staff-id">Staff ID</label>
            <input id="recovery-staff-id" name="staff_identifier" type="text" maxlength="50" autocomplete="off" required autofocus>
            <label for="recovery-code">One-time reset code</label>
            <input id="recovery-code" class="attendance-pin-input" name="reset_code" type="text" inputmode="numeric" pattern="[0-9]{8}" minlength="8" maxlength="8" autocomplete="off" autocorrect="off" spellcheck="false" required>
            <label for="recovery-new-pin">New attendance PIN (6–10 digits)</label>
            <input id="recovery-new-pin" class="attendance-pin-input" name="new_pin" type="text" inputmode="numeric" pattern="[0-9]{6,10}" minlength="6" maxlength="10" autocomplete="off" autocorrect="off" spellcheck="false" required>
            <label for="recovery-confirm-pin">Confirm new attendance PIN</label>
            <input id="recovery-confirm-pin" class="attendance-pin-input" name="confirm_pin" type="text" inputmode="numeric" pattern="[0-9]{6,10}" minlength="6" maxlength="10" autocomplete="off" autocorrect="off" spellcheck="false" required>
            <button class="button button-primary" type="submit">Complete PIN recovery</button>
        </form>
        <p class="attendance-kiosk-reset"><a href="attendance_kiosk.php?school=<?= rawurlencode((string) $school['short_name']) ?>">Return to attendance kiosk</a></p>
    <?php elseif ($school && !$kioskConfigured): ?>
        <h2>Attendance kiosk setup required</h2>
        <p class="notice notice-error" role="alert">A school administrator must configure attendance schedules before check-in is available.</p>
    <?php elseif ($confirmation): ?>
        <div class="attendance-kiosk-confirmation" role="status">
            <span class="attendance-kiosk-check" aria-hidden="true">✓</span>
            <h2>Attendance confirmed</h2>
            <p><strong><?= e($confirmation['name']) ?></strong></p>
            <dl>
                <div><dt>Staff ID</dt><dd><?= e($confirmation['staff_id'] ?? 'Not assigned') ?></dd></div>
                <div><dt>Date</dt><dd><?= e($confirmation['date'] ?? (new DateTimeImmutable('now', $timezone))->format('j M Y')) ?></dd></div>
                <div><dt>Recorded time</dt><dd><?= e($confirmation['time']) ?></dd></div>
                <div><dt>Status</dt><dd><?= e($confirmation['status']) ?></dd></div>
            </dl>
            <p data-kiosk-auto-reset data-reset-url="attendance_kiosk.php?school=<?= rawurlencode((string) $school['short_name']) ?>" data-reset-seconds="7">
                Ready for the next staff member in <span data-kiosk-countdown>7</span> seconds.
            </p>
        </div>
        <p class="attendance-kiosk-reset"><a class="button button-primary" href="attendance_kiosk.php?school=<?= rawurlencode((string) $school['short_name']) ?>">Start next attendance now</a></p>
    <?php elseif ($school): ?>
        <?php if ($message !== ''): ?><p class="notice notice-<?= e($messageKind) ?>" role="alert"><?= e($message) ?></p><?php endif; ?>
        <form method="post" action="attendance_kiosk.php?school=<?= rawurlencode((string) $school['short_name']) ?>" class="attendance-kiosk-form" autocomplete="off" data-kiosk-idle-reset data-reset-url="attendance_kiosk.php?school=<?= rawurlencode((string) $school['short_name']) ?>" data-idle-seconds="120" data-form-type="other">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="attendance_transaction">
            <input type="hidden" name="school" value="<?= e((string) $school['short_name']) ?>">
            <label for="kiosk-staff-code">Staff ID</label>
            <input id="kiosk-staff-code" name="staff_identifier" type="text" maxlength="50" autocomplete="off" autocapitalize="off" spellcheck="false" required autofocus>
            <label for="kiosk-attendance-code">Personal attendance PIN (6–10 digits)</label>
            <input id="kiosk-attendance-code" class="attendance-pin-input" name="attendance_pin" type="text" inputmode="numeric" pattern="[0-9]{6,10}" minlength="6" maxlength="10" autocomplete="off" autocorrect="off" spellcheck="false" required>
            <p class="muted">Use your attendance PIN, not your school account password. Check in from 5:30 AM until before 2:00 PM; check out any time on a scheduled working day.</p>
            <fieldset class="attendance-action-choice">
                <legend>What are you recording?</legend>
                <label><input type="radio" name="attendance_action" value="check_in" required> Check in — I am arriving</label>
                <label><input type="radio" name="attendance_action" value="check_out" required> Check out — I am leaving</label>
            </fieldset>
            <button class="button button-primary" type="submit">Record selected attendance</button>
        </form>
        <p class="attendance-kiosk-reset"><a href="attendance_kiosk.php?school=<?= rawurlencode((string) $school['short_name']) ?>&amp;mode=forgot-pin">Forgot PIN?</a></p>
        <p class="attendance-kiosk-footer"><?= e((new DateTimeImmutable('now', $timezone))->format('l, j F Y')) ?> · School local time</p>
    <?php else: ?>
        <p class="notice notice-error" role="alert">Use the attendance kiosk link provided by your school.</p>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
