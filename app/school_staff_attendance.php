<?php
declare(strict_types=1);

function school_staff_attendance_can_manage(array $user, int $schoolId): bool
{
    $role = school_user_role_for_school((int) ($user['id'] ?? 0), $schoolId);
    if (!in_array($role, ['school_headmaster', 'school_proprietor'], true)
        || !school_has_permission($user, $schoolId, 'attendance.manage')) {
        return false;
    }
    $supportContext = active_it_support_context($user);
    return $supportContext === null
        || ((int) $supportContext['school_id'] === $schoolId
            && in_array((string) $supportContext['preview_role'], ['school_headmaster', 'school_proprietor'], true));
}

function school_staff_attendance_schedule(PDO $pdo, int $schoolId, string $roleName): array
{
    static $scheduleCache = [];
    $cacheKey = $schoolId . ':' . $roleName;
    if (isset($scheduleCache[$cacheKey])) {
        return $scheduleCache[$cacheKey];
    }
    $stmt = $pdo->prepare(
        'SELECT reporting_time, morning_open_time, morning_close_time,
                afternoon_open_time, afternoon_close_time,
                grace_minutes, very_late_after_minutes, closing_time,
                early_departure_threshold_minutes, working_weekdays, clock_out_enabled,
                failed_pin_attempt_limit, lockout_minutes
         FROM school_staff_attendance_schedules WHERE school_id = ? AND role_name = ? LIMIT 1'
    );
    $stmt->execute([$schoolId, $roleName]);
    $schedule = $stmt->fetch();
    if (!$schedule) {
        $legacy = school_staff_attendance_policy($schoolId);
        $schedule = [
            'reporting_time' => $legacy['reporting_time'],
            'morning_open_time' => '05:45:00',
            'morning_close_time' => '09:00:00',
            'afternoon_open_time' => '14:30:00',
            'afternoon_close_time' => '16:00:00',
            'grace_minutes' => $legacy['late_grace_minutes'],
            'very_late_after_minutes' => 60,
            'closing_time' => '15:00:00',
            'early_departure_threshold_minutes' => 60,
            'working_weekdays' => '[1,2,3,4,5]',
            'clock_out_enabled' => 0,
            'failed_pin_attempt_limit' => 5,
            'lockout_minutes' => 15,
        ];
    }

    $weekdays = json_decode((string) $schedule['working_weekdays'], true);
    if (!is_array($weekdays)) {
        $weekdays = [1, 2, 3, 4, 5];
    }
    $weekdays = array_values(array_unique(array_filter(
        array_map('intval', $weekdays),
        static fn (int $day): bool => $day >= 1 && $day <= 7
    )));
    if ($weekdays === []) {
        $weekdays = [1, 2, 3, 4, 5];
    }
    $schedule['working_weekdays'] = $weekdays;
    $schedule['reporting_time'] = substr((string) $schedule['reporting_time'], 0, 5);
    $schedule['closing_time'] = substr((string) $schedule['closing_time'], 0, 5);
    foreach (['morning_open_time', 'morning_close_time', 'afternoon_open_time', 'afternoon_close_time'] as $field) {
        $schedule[$field] = substr((string) $schedule[$field], 0, 5);
    }
    foreach (['grace_minutes', 'very_late_after_minutes', 'early_departure_threshold_minutes', 'failed_pin_attempt_limit', 'lockout_minutes'] as $field) {
        $schedule[$field] = (int) $schedule[$field];
    }
    $schedule['clock_out_enabled'] = (bool) $schedule['clock_out_enabled'];
    $scheduleCache[$cacheKey] = $schedule;
    return $schedule;
}

function school_staff_attendance_timezone(PDO $pdo, int $schoolId): DateTimeZone
{
    $stmt = $pdo->prepare(
        'SELECT setting_value FROM school_settings
         WHERE school_id = ? AND setting_key = "staff_attendance_timezone" LIMIT 1'
    );
    $stmt->execute([$schoolId]);
    $timezone = trim((string) ($stmt->fetchColumn() ?: 'Africa/Accra'));
    try {
        return new DateTimeZone($timezone);
    } catch (Throwable $exception) {
        throw new RuntimeException('The school attendance timezone is invalid.', 0, $exception);
    }
}

function school_staff_attendance_kiosk_device(PDO $pdo, int $schoolId): ?array
{
    if ($schoolId < 1) {
        return null;
    }
    $agentVerifiedId = (int) ($_SESSION['kiosk_agent_verified'][$schoolId]['device_id'] ?? 0);
    $agentVerifiedAt = (int) ($_SESSION['kiosk_agent_verified'][$schoolId]['verified_at'] ?? 0);
    if ($agentVerifiedId > 0 && $agentVerifiedAt >= time() - 3600) {
        $agentStmt = $pdo->prepare(
            'SELECT id, device_name FROM school_staff_attendance_kiosk_devices
             WHERE id = ? AND school_id = ? AND deactivated_at IS NULL LIMIT 1'
        );
        $agentStmt->execute([$agentVerifiedId, $schoolId]);
        $agentDevice = $agentStmt->fetch();
        if ($agentDevice) {
            return $agentDevice;
        }
        unset($_SESSION['kiosk_agent_verified'][$schoolId]);
    }

    $cookieName = 'staff_attendance_kiosk_device_' . $schoolId;
    $token = (string) ($_COOKIE[$cookieName] ?? '');
    if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT id, device_name FROM school_staff_attendance_kiosk_devices
         WHERE school_id = ? AND token_hash = ? AND deactivated_at IS NULL LIMIT 1'
    );
    $stmt->execute([$schoolId, keyed_hash($token)]);
    $device = $stmt->fetch();
    return $device ?: null;
}

function school_staff_attendance_authorized_kiosk_devices(PDO $pdo): array
{
    $authorized = [];
    if (!empty($_SESSION['kiosk_agent_verified']) && is_array($_SESSION['kiosk_agent_verified'])) {
        $agentLookup = $pdo->prepare(
            'SELECT device.id, device.school_id, device.device_name,
                    school.name AS school_name, school.short_name
             FROM school_staff_attendance_kiosk_devices device
             JOIN schools school ON school.id = device.school_id
               AND school.status = "active" AND school.deleted_at IS NULL
             WHERE device.id = ? AND device.school_id = ?
               AND device.deactivated_at IS NULL
             LIMIT 1'
        );
        foreach ($_SESSION['kiosk_agent_verified'] as $sid => $entry) {
            $sidInt = (int) $sid;
            $devId = (int) ($entry['device_id'] ?? 0);
            $verifiedAt = (int) ($entry['verified_at'] ?? 0);
            if ($sidInt < 1 || $devId < 1 || $verifiedAt < time() - 3600) {
                continue;
            }
            $agentLookup->execute([$devId, $sidInt]);
            $dev = $agentLookup->fetch();
            if ($dev) {
                $authorized[(int) $dev['school_id']] = $dev;
            }
        }
    }

    $stmt = $pdo->prepare(
        'SELECT device.id, device.school_id, device.device_name,
                school.name AS school_name, school.short_name
         FROM school_staff_attendance_kiosk_devices device
         JOIN schools school ON school.id = device.school_id
           AND school.status = "active" AND school.deleted_at IS NULL
         WHERE device.school_id = ? AND device.token_hash = ?
           AND device.deactivated_at IS NULL
         LIMIT 1'
    );
    foreach ($_COOKIE as $cookieName => $token) {
        if (!is_string($cookieName)
            || !is_string($token)
            || preg_match('/^staff_attendance_kiosk_device_([1-9]\d*)$/D', $cookieName, $matches) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            continue;
        }
        $schoolId = filter_var($matches[1], FILTER_VALIDATE_INT);
        if ($schoolId === false || $schoolId < 1 || isset($authorized[$schoolId])) {
            continue;
        }
        $stmt->execute([$schoolId, keyed_hash($token)]);
        $device = $stmt->fetch();
        if ($device) {
            $authorized[(int) $device['school_id']] = $device;
        }
    }

    return array_values($authorized);
}

function school_staff_attendance_security_event(
    PDO $pdo,
    int $schoolId,
    string $eventType,
    ?int $deviceId = null,
    ?int $staffId = null,
    ?int $actorUserId = null,
    ?string $clientKey = null,
    ?string $reason = null,
    ?string $occurredAt = null
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO school_staff_attendance_security_events
            (school_id, device_id, staff_id, actor_user_id, event_type, client_key, reason, occurred_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, COALESCE(?, NOW()))'
    );
    $stmt->execute([
        $schoolId,
        $deviceId,
        $staffId,
        $actorUserId,
        $eventType,
        $clientKey,
        $reason === null ? null : mb_substr($reason, 0, 255),
        $occurredAt,
    ]);
}

function school_staff_attendance_classify_arrival(
    DateTimeImmutable $arrival,
    string $attendanceDate,
    array $schedule
): array {
    $reporting = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i',
        $attendanceDate . ' ' . (string) $schedule['reporting_time'],
        $arrival->getTimezone()
    );
    if (!$reporting) {
        throw new RuntimeException('The staff reporting schedule is invalid.');
    }
    $graceEnd = $reporting->modify('+' . (int) $schedule['grace_minutes'] . ' minutes');
    $veryLateEnd = $graceEnd->modify('+' . (int) $schedule['very_late_after_minutes'] . ' minutes');
    if ($arrival < $reporting) {
        return ['status' => 'early', 'minutes_late' => 0, 'legacy_status' => 'present'];
    }
    if ($arrival <= $graceEnd) {
        return ['status' => 'punctual', 'minutes_late' => 0, 'legacy_status' => 'present'];
    }
    $minutesLate = (int) floor(($arrival->getTimestamp() - $reporting->getTimestamp()) / 60);
    return [
        'status' => $arrival > $veryLateEnd ? 'very_late' : 'late',
        'minutes_late' => max(1, $minutesLate),
        'legacy_status' => 'late',
    ];
}

function school_staff_attendance_check_in_time_allowed(DateTimeImmutable $arrival): bool
{
    $time = $arrival->format('H:i');
    return $time >= '05:30' && $time < '14:00';
}

function school_staff_attendance_snapshot(array $record): array
{
    return [
        'attendance_date' => (string) ($record['attendance_date'] ?? ''),
        'arrival_at' => $record['arrival_at'] ?? null,
        'departure_at' => $record['departure_at'] ?? null,
        'status' => (string) ($record['status'] ?? ''),
        'punctuality_status' => $record['punctuality_status'] ?? null,
        'minutes_late' => (int) ($record['minutes_late'] ?? 0),
        'working_minutes' => ($record['working_minutes'] ?? null) === null ? null : (int) $record['working_minutes'],
        'early_departure' => (bool) ($record['early_departure'] ?? false),
        'verification_method' => (string) ($record['verification_method'] ?? 'administrator_recorded'),
        'departure_verification_method' => $record['departure_verification_method'] ?? null,
        'expected_reporting_time' => $record['expected_reporting_time'] ?? null,
        'remarks' => $record['remarks'] ?? null,
    ];
}

function school_staff_attendance_write_audit(
    PDO $pdo,
    int $schoolId,
    int $attendanceId,
    int $staffId,
    ?int $actorId,
    string $changeType,
    ?array $original,
    array $updated,
    ?string $reason,
    string $verificationMethod
): void {
    $changedAt = (new DateTimeImmutable('now', school_staff_attendance_timezone($pdo, $schoolId)))
        ->format('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        'INSERT INTO school_staff_attendance_audit
            (school_id, attendance_id, staff_id, changed_by, change_type,
             original_snapshot, updated_snapshot, reason, verification_method, changed_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $schoolId,
        $attendanceId,
        $staffId,
        $actorId,
        $changeType,
        $original === null ? null : json_encode($original, JSON_THROW_ON_ERROR),
        json_encode($updated, JSON_THROW_ON_ERROR),
        $reason,
        $verificationMethod,
        $changedAt,
    ]);
}

function school_staff_attendance_is_working_day(
    PDO $pdo,
    int $schoolId,
    string $date,
    array $schedule
): bool {
    static $workingDayCache = [];
    $cacheKey = $schoolId . ':' . $date . ':' . implode(',', $schedule['working_weekdays']);
    if (array_key_exists($cacheKey, $workingDayCache)) {
        return $workingDayCache[$cacheKey];
    }
    $weekday = (int) (new DateTimeImmutable($date))->format('N');
    if (!in_array($weekday, $schedule['working_weekdays'], true)) {
        $override = $pdo->prepare(
            'SELECT day_type FROM school_calendar_days WHERE school_id = ? AND calendar_date = ? LIMIT 1'
        );
        $override->execute([$schoolId, $date]);
        if ($override->fetchColumn() !== 'school_day') {
            return $workingDayCache[$cacheKey] = false;
        }
    }
    $calendar = $pdo->prepare(
        'SELECT day_type FROM school_calendar_days
         WHERE school_id = ? AND calendar_date = ? LIMIT 1'
    );
    $calendar->execute([$schoolId, $date]);
    if (in_array($calendar->fetchColumn(), ['holiday', 'closure'], true)) {
        return $workingDayCache[$cacheKey] = false;
    }
    $closure = $pdo->prepare(
        'SELECT 1 FROM school_calendar_events
         WHERE school_id = ? AND status = "published"
           AND category IN ("holiday", "public_holiday", "school_closure")
           AND audience = "school" AND starts_on <= ? AND ends_on >= ? LIMIT 1'
    );
    $closure->execute([$schoolId, $date, $date]);
    if ($closure->fetchColumn()) {
        return $workingDayCache[$cacheKey] = false;
    }
    $hasTerms = $pdo->prepare('SELECT 1 FROM school_terms WHERE school_id = ? LIMIT 1');
    $hasTerms->execute([$schoolId]);
    if ($hasTerms->fetchColumn()) {
        $inTerm = $pdo->prepare(
            'SELECT 1 FROM school_terms WHERE school_id = ? AND starts_on <= ? AND ends_on >= ? LIMIT 1'
        );
        $inTerm->execute([$schoolId, $date, $date]);
        return $workingDayCache[$cacheKey] = (bool) $inTerm->fetchColumn();
    }
    return $workingDayCache[$cacheKey] = true;
}

function school_staff_attendance_day_is_elapsed(
    string $date,
    DateTimeImmutable $now,
    string $closingTime,
    ?array $record = null
): bool {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1
        || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $closingTime) !== 1) {
        throw new InvalidArgumentException('The attendance date or closing time is invalid.');
    }
    $today = $now->format('Y-m-d');
    if ($date < $today) {
        return true;
    }
    if ($date > $today) {
        return false;
    }
    if ($now->format('H:i') >= school_staff_attendance_absence_cutoff($closingTime)) {
        return true;
    }
    return is_array($record)
        && in_array((string) ($record['status'] ?? ''), ['present', 'late', 'excused'], true);
}

function school_staff_attendance_absence_cutoff(string $closingTime): string
{
    if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $closingTime) !== 1) {
        throw new RuntimeException('The staff closing-time schedule is invalid.');
    }
    return max($closingTime, '16:00');
}

function school_staff_attendance_period_summary(array $scheduledDates, array $records): array
{
    $summary = [
        'scheduled_days' => count($scheduledDates),
        'present_days' => 0,
        'absent_days' => 0,
        'excused_days' => 0,
        'late_days' => 0,
        'unmarked_days' => 0,
        'attendance_rate' => null,
    ];

    foreach ($scheduledDates as $date) {
        $record = $records[$date] ?? null;
        if (!is_array($record)) {
            $summary['absent_days']++;
            $summary['unmarked_days']++;
            continue;
        }

        $status = (string) ($record['status'] ?? '');
        if ($status === 'excused') {
            $summary['excused_days']++;
            continue;
        }
        if ($status === 'absent') {
            $summary['absent_days']++;
            continue;
        }
        if ($status === 'pending') {
            $summary['absent_days']++;
            $summary['unmarked_days']++;
            continue;
        }
        if (in_array($status, ['present', 'late'], true)) {
            $summary['present_days']++;
            if ($status === 'late' || in_array(
                (string) ($record['punctuality_status'] ?? ''),
                ['late', 'very_late'],
                true
            )) {
                $summary['late_days']++;
            }
        }
    }

    $eligibleDays = $summary['scheduled_days'] - $summary['excused_days'];
    if ($eligibleDays > 0) {
        $summary['attendance_rate'] = round($summary['present_days'] / $eligibleDays * 100, 1);
    }

    return $summary;
}

function school_staff_attendance_payroll_month_summary(
    array $scheduledDates,
    array $records,
    int $makeupCredits,
    DateTimeImmutable $now,
    string $closingTime
): array {
    $closingTime = school_staff_attendance_absence_cutoff($closingTime);

    $today = $now->format('Y-m-d');
    $elapsedDays = 0;
    $completedDays = 0;
    $excusedDays = 0;
    $pendingDays = 0;
    foreach ($scheduledDates as $date) {
        if (!is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1) {
            throw new InvalidArgumentException('A scheduled attendance date is invalid.');
        }
        $record = $records[$date] ?? null;
        $status = is_array($record) ? (string) ($record['status'] ?? '') : '';
        $hasRecordedStatus = in_array($status, ['present', 'late', 'excused', 'absent'], true);

        if ($date < $today || ($date === $today
            && ($hasRecordedStatus || $now->format('H:i') >= $closingTime))) {
            $elapsedDays++;
        } else {
            $pendingDays++;
        }

        if (in_array($status, ['present', 'late'], true)) {
            $completedDays++;
        } elseif ($status === 'excused') {
            $excusedDays++;
        }
    }

    $makeupCredits = max(0, $makeupCredits);
    return [
        'required_days' => count($scheduledDates),
        'elapsed_days' => $elapsedDays,
        'completed_days' => $completedDays,
        'excused_days' => $excusedDays,
        'credited_days' => $makeupCredits,
        'pending_days' => $pendingDays,
        'outstanding_days' => max(
            0,
            $elapsedDays - $completedDays - $excusedDays - $makeupCredits
        ),
        'eligible' => $pendingDays === 0
            && $elapsedDays - $completedDays - $excusedDays - $makeupCredits <= 0,
    ];
}

function school_staff_attendance_refresh_makeup_obligation(
    PDO $pdo,
    int $schoolId,
    int $staffId,
    string $roleName,
    string $obligationMonth,
    DateTimeImmutable $now
): array {
    $month = DateTimeImmutable::createFromFormat('!Y-m-d', $obligationMonth, $now->getTimezone());
    $monthErrors = DateTimeImmutable::getLastErrors();
    if ($month === false
        || $month->format('Y-m-d') !== $obligationMonth
        || $month->format('d') !== '01'
        || ($monthErrors !== false
            && ($monthErrors['warning_count'] > 0 || $monthErrors['error_count'] > 0))) {
        throw new InvalidArgumentException('The attendance obligation month is invalid.');
    }

    $schedule = school_staff_attendance_schedule($pdo, $schoolId, $roleName);
    $periodEnd = $month->modify('last day of this month');
    $staffStartStmt = $pdo->prepare(
        'SELECT DATE(created_at) FROM school_staff WHERE id = ? AND school_id = ? LIMIT 1'
    );
    $staffStartStmt->execute([$staffId, $schoolId]);
    $staffStart = $staffStartStmt->fetchColumn();
    if (!is_string($staffStart) || $staffStart === '') {
        throw new RuntimeException('The staff attendance obligation does not have a valid staff record.');
    }
    $firstScheduledDate = new DateTimeImmutable(
        max($month->format('Y-m-d'), $staffStart),
        $now->getTimezone()
    );
    $scheduledDates = [];
    for ($date = $firstScheduledDate; $date <= $periodEnd; $date = $date->modify('+1 day')) {
        $dateString = $date->format('Y-m-d');
        if (school_staff_attendance_is_working_day($pdo, $schoolId, $dateString, $schedule)) {
            $scheduledDates[] = $dateString;
        }
    }

    $recordsStmt = $pdo->prepare(
        'SELECT attendance.attendance_date, attendance.status
         FROM school_staff_attendance attendance
         WHERE attendance.school_id = ? AND attendance.staff_id = ?
           AND attendance.attendance_date BETWEEN ? AND ?
           AND NOT EXISTS (
               SELECT 1 FROM school_staff_attendance_makeup_credits credit
               WHERE credit.school_id = attendance.school_id
                 AND credit.attendance_id = attendance.id
           )'
    );
    $recordsStmt->execute([
        $schoolId,
        $staffId,
        $month->format('Y-m-d'),
        $periodEnd->format('Y-m-d'),
    ]);
    $records = [];
    foreach ($recordsStmt->fetchAll() as $record) {
        $records[(string) $record['attendance_date']] = $record;
    }

    $pdo->prepare(
        'INSERT IGNORE INTO school_staff_attendance_makeup_obligations
            (school_id, staff_id, obligation_month)
         VALUES (?, ?, ?)'
    )->execute([$schoolId, $staffId, $obligationMonth]);
    $obligationStmt = $pdo->prepare(
        'SELECT id FROM school_staff_attendance_makeup_obligations
         WHERE school_id = ? AND staff_id = ? AND obligation_month = ? FOR UPDATE'
    );
    $obligationStmt->execute([$schoolId, $staffId, $obligationMonth]);
    $obligationId = (int) $obligationStmt->fetchColumn();
    if ($obligationId < 1) {
        throw new RuntimeException('The staff attendance obligation could not be loaded.');
    }

    $creditStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM school_staff_attendance_makeup_credits
         WHERE school_id = ? AND obligation_id = ?'
    );
    $creditStmt->execute([$schoolId, $obligationId]);
    $summary = school_staff_attendance_payroll_month_summary(
        $scheduledDates,
        $records,
        (int) $creditStmt->fetchColumn(),
        $now,
        (string) $schedule['closing_time']
    );
    $summary['obligation_id'] = $obligationId;

    $updateStmt = $pdo->prepare(
        'UPDATE school_staff_attendance_makeup_obligations
         SET required_days = ?, elapsed_days = ?, completed_days = ?, excused_days = ?,
             credited_days = ?, pending_days = ?, outstanding_days = ?, status = ?
         WHERE id = ? AND school_id = ? AND staff_id = ?'
    );
    $updateStmt->execute([
        $summary['required_days'],
        $summary['elapsed_days'],
        $summary['completed_days'],
        $summary['excused_days'],
        $summary['credited_days'],
        $summary['pending_days'],
        $summary['outstanding_days'],
        $summary['pending_days'] === 0 && $summary['outstanding_days'] === 0 ? 'complete' : 'open',
        $obligationId,
        $schoolId,
        $staffId,
    ]);

    return $summary;
}

function school_staff_attendance_device_extended_columns_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM school_staff_attendance_kiosk_devices')->fetchAll(PDO::FETCH_COLUMN);
        $ready = is_array($cols) && in_array('device_location', $cols, true) && in_array('status', $cols, true);
    } catch (Throwable) {
        $ready = false;
    }
    return $ready;
}

function school_staff_attendance_can_manage_devices(array $user, int $schoolId): bool
{
    $userId = (int) ($user['id'] ?? 0);
    if ($userId < 1 || $schoolId < 1 || platform_super_admin_user($user)) {
        return false;
    }
    if (function_exists('active_it_support_context') && active_it_support_context($user) !== null) {
        return false;
    }
    $role = school_user_role_for_school($userId, $schoolId);
    return in_array($role, ['school_headmaster', 'school_proprietor'], true);
}

function school_staff_attendance_set_kiosk_cookie(int $schoolId, ?string $deviceToken): bool
{
    $secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0])) === 'https';
    return setcookie('staff_attendance_kiosk_device_' . $schoolId, $deviceToken ?? '', [
        'expires' => $deviceToken === null ? time() - 3600 : time() + 31536000,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function school_staff_attendance_register_device(
    PDO $pdo,
    int $schoolId,
    int $actorUserId,
    string $deviceName,
    string $deviceLocation,
    DateTimeZone $timezone,
    bool $issueBrowserCookie = true
): array {
    $deviceName = trim($deviceName);
    $deviceLocation = trim($deviceLocation);
    if ($deviceName === '' || mb_strlen($deviceName) > 100) {
        throw new RuntimeException('Enter a device name of up to 100 characters.');
    }
    if ($deviceLocation === '' || mb_strlen($deviceLocation) > 120) {
        throw new RuntimeException('Enter a device location of up to 120 characters.');
    }

    $deviceToken = bin2hex(random_bytes(32));
    $enrollmentCode = 'CNZ-' . strtoupper(bin2hex(random_bytes(2))) . '-' . strtoupper(bin2hex(random_bytes(2)));
    $registeredAt = (new DateTimeImmutable('now', $timezone))->format('Y-m-d H:i:s');
    $expiresAt = (new DateTimeImmutable('+15 minutes', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

    $pdo->beginTransaction();
    try {
        if (school_staff_attendance_device_extended_columns_ready($pdo)) {
            $pdo->prepare(
                'INSERT INTO school_staff_attendance_kiosk_devices
                    (school_id, device_name, device_location, token_hash, status, auth_mode,
                     enrollment_code_hash, enrollment_expires_at, registered_by, created_at)
                 VALUES (?, ?, ?, ?, "active", "browser_credential", ?, ?, ?, ?)'
            )->execute([
                $schoolId,
                $deviceName,
                $deviceLocation,
                keyed_hash($deviceToken),
                keyed_hash($enrollmentCode),
                $expiresAt,
                $actorUserId,
                $registeredAt,
            ]);
        } else {
            $pdo->prepare(
                'INSERT INTO school_staff_attendance_kiosk_devices
                    (school_id, device_name, token_hash, registered_by, created_at)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$schoolId, $deviceName, keyed_hash($deviceToken), $actorUserId, $registeredAt]);
        }
        $deviceId = (int) $pdo->lastInsertId();
        if ($issueBrowserCookie && !headers_sent()) {
            school_staff_attendance_set_kiosk_cookie($schoolId, $deviceToken);
        }
        school_staff_attendance_security_event(
            $pdo,
            $schoolId,
            'device_registered',
            $deviceId,
            null,
            $actorUserId,
            null,
            $deviceName . ' (' . $deviceLocation . ')',
            $registeredAt
        );
        audit($actorUserId, 'school_staff_attendance_kiosk_device_registered', 'school_id=' . $schoolId . ';device_id=' . $deviceId);
        $pdo->commit();
        return [
            'device_id' => $deviceId,
            'device_token' => $deviceToken,
            'enrollment_code' => $enrollmentCode,
            'enrollment_expires_at' => $expiresAt,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function school_staff_attendance_set_device_status(
    PDO $pdo,
    int $schoolId,
    int $deviceId,
    int $actorUserId,
    string $targetStatus,
    DateTimeZone $timezone,
    ?string $reason = null
): void {
    if (!in_array($targetStatus, ['active', 'inactive', 'revoked'], true)) {
        throw new InvalidArgumentException('Invalid device status.');
    }
    $nowStr = (new DateTimeImmutable('now', $timezone))->format('Y-m-d H:i:s');
    $extended = school_staff_attendance_device_extended_columns_ready($pdo);
    $currentDevice = school_staff_attendance_kiosk_device($pdo, $schoolId);

    $pdo->beginTransaction();
    try {
        $selectSql = $extended
            ? 'SELECT id, device_name, status, deactivated_at, revoked_at FROM school_staff_attendance_kiosk_devices WHERE id = ? AND school_id = ? FOR UPDATE'
            : 'SELECT id, device_name, deactivated_at FROM school_staff_attendance_kiosk_devices WHERE id = ? AND school_id = ? FOR UPDATE';
        $stmt = $pdo->prepare($selectSql);
        $stmt->execute([$deviceId, $schoolId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('The selected attendance device does not belong to this school.');
        }
        if ($extended && (($row['status'] ?? '') === 'revoked' || $row['revoked_at'] !== null)) {
            throw new RuntimeException('Revoked devices cannot be reactivated. Register or replace the device instead.');
        }

        if ($extended) {
            if ($targetStatus === 'active') {
                $pdo->prepare(
                    'UPDATE school_staff_attendance_kiosk_devices
                     SET status = "active", deactivated_by = NULL, deactivated_at = NULL
                     WHERE id = ? AND school_id = ? AND status <> "revoked"'
                )->execute([$deviceId, $schoolId]);
                $eventType = 'device_activated';
                $eventReason = $reason ?? 'Activated by administrator';
            } elseif ($targetStatus === 'inactive') {
                $pdo->prepare(
                    'UPDATE school_staff_attendance_kiosk_devices
                     SET status = "inactive", deactivated_by = ?, deactivated_at = ?
                     WHERE id = ? AND school_id = ? AND status <> "revoked"'
                )->execute([$actorUserId, $nowStr, $deviceId, $schoolId]);
                $eventType = 'device_deactivated';
                $eventReason = $reason ?? 'Temporarily deactivated by administrator';
            } else {
                $pdo->prepare(
                    'UPDATE school_staff_attendance_kiosk_devices
                     SET status = "revoked", deactivated_by = COALESCE(deactivated_by, ?),
                         deactivated_at = COALESCE(deactivated_at, ?),
                         revoked_by = ?, revoked_at = ?
                     WHERE id = ? AND school_id = ?'
                )->execute([$actorUserId, $nowStr, $actorUserId, $nowStr, $deviceId, $schoolId]);
                $eventType = 'device_revoked';
                $eventReason = $reason ?? 'Permanently revoked by administrator';
            }
        } else {
            if ($targetStatus === 'active') {
                $pdo->prepare('UPDATE school_staff_attendance_kiosk_devices SET deactivated_by = NULL, deactivated_at = NULL WHERE id = ? AND school_id = ?')
                    ->execute([$deviceId, $schoolId]);
                $eventType = 'device_activated';
                $eventReason = $reason ?? 'Activated by administrator';
            } else {
                $pdo->prepare('UPDATE school_staff_attendance_kiosk_devices SET deactivated_by = ?, deactivated_at = ? WHERE id = ? AND school_id = ?')
                    ->execute([$actorUserId, $nowStr, $deviceId, $schoolId]);
                $eventType = $targetStatus === 'revoked' ? 'device_revoked' : 'device_deactivated';
                $eventReason = $reason ?? 'Deactivated by administrator';
            }
        }

        school_staff_attendance_security_event(
            $pdo,
            $schoolId,
            $eventType,
            $deviceId,
            null,
            $actorUserId,
            null,
            $eventReason,
            $nowStr
        );
        audit($actorUserId, 'school_staff_attendance_kiosk_' . $eventType, 'school_id=' . $schoolId . ';device_id=' . $deviceId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    if ($targetStatus !== 'active') {
        if (isset($_SESSION['kiosk_agent_verified'][$schoolId]) && (int) ($_SESSION['kiosk_agent_verified'][$schoolId]['device_id'] ?? 0) === $deviceId) {
            unset($_SESSION['kiosk_agent_verified'][$schoolId]);
        }
        if ($currentDevice !== null && (int) $currentDevice['id'] === $deviceId && !headers_sent()) {
            school_staff_attendance_set_kiosk_cookie($schoolId, null);
        }
    }
}

function school_staff_attendance_replace_device(
    PDO $pdo,
    int $schoolId,
    int $oldDeviceId,
    int $actorUserId,
    string $newName,
    string $newLocation,
    DateTimeZone $timezone,
    bool $issueBrowserCookie = true
): array {
    $newDevice = school_staff_attendance_register_device(
        $pdo,
        $schoolId,
        $actorUserId,
        $newName,
        $newLocation,
        $timezone,
        $issueBrowserCookie
    );
    $newDeviceId = (int) $newDevice['device_id'];
    $nowStr = (new DateTimeImmutable('now', $timezone))->format('Y-m-d H:i:s');
    $extended = school_staff_attendance_device_extended_columns_ready($pdo);

    $pdo->beginTransaction();
    try {
        if ($extended) {
            $pdo->prepare(
                'UPDATE school_staff_attendance_kiosk_devices
                 SET status = "revoked",
                     deactivated_by = COALESCE(deactivated_by, ?),
                     deactivated_at = COALESCE(deactivated_at, ?),
                     revoked_by = COALESCE(revoked_by, ?),
                     revoked_at = COALESCE(revoked_at, ?),
                     replaced_by_device_id = ?
                 WHERE id = ? AND school_id = ?'
            )->execute([$actorUserId, $nowStr, $actorUserId, $nowStr, $newDeviceId, $oldDeviceId, $schoolId]);
        } else {
            $pdo->prepare(
                'UPDATE school_staff_attendance_kiosk_devices
                 SET deactivated_by = COALESCE(deactivated_by, ?),
                     deactivated_at = COALESCE(deactivated_at, ?)
                 WHERE id = ? AND school_id = ?'
            )->execute([$actorUserId, $nowStr, $oldDeviceId, $schoolId]);
        }
        school_staff_attendance_security_event(
            $pdo,
            $schoolId,
            'device_replaced',
            $oldDeviceId,
            null,
            $actorUserId,
            null,
            'Replaced by device #' . $newDeviceId . ' (' . trim($newName) . ')',
            $nowStr
        );
        audit($actorUserId, 'school_staff_attendance_kiosk_device_replaced', 'school_id=' . $schoolId . ';old_device_id=' . $oldDeviceId . ';new_device_id=' . $newDeviceId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return $newDevice;
}

function school_staff_attendance_enroll_agent(
    PDO $pdo,
    int $schoolId,
    string $enrollmentCode,
    string $deviceUid,
    string $agentSecret
): array {
    $enrollmentCode = strtoupper(trim($enrollmentCode));
    $deviceUid = strtolower(trim($deviceUid));
    if (!preg_match('/^[a-f0-9]{64}$/D', $deviceUid) || strlen($agentSecret) < 32) {
        throw new InvalidArgumentException('Invalid physical device identifier or agent secret.');
    }
    if (!school_staff_attendance_device_extended_columns_ready($pdo)) {
        throw new RuntimeException('Attendance device agent columns are not migrated yet.');
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT id, device_name, enrollment_expires_at
             FROM school_staff_attendance_kiosk_devices
             WHERE school_id = ? AND enrollment_code_hash = ? AND status = "active" AND deactivated_at IS NULL
             LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$schoolId, keyed_hash($enrollmentCode)]);
        $row = $stmt->fetch();
        if (!$row || empty($row['enrollment_expires_at'])
            || strtotime((string) $row['enrollment_expires_at'] . ' UTC') < time()) {
            throw new RuntimeException('The device enrollment code is invalid or expired.');
        }

        $deviceId = (int) $row['id'];
        $pdo->prepare(
            'UPDATE school_staff_attendance_kiosk_devices
             SET device_uid = ?, agent_secret_hash = ?, auth_mode = "local_agent",
                 enrollment_code_hash = NULL, enrollment_expires_at = NULL
             WHERE id = ? AND school_id = ?'
        )->execute([$deviceUid, hash('sha256', $agentSecret), $deviceId, $schoolId]);

        school_staff_attendance_security_event(
            $pdo,
            $schoolId,
            'device_agent_enrolled',
            $deviceId,
            null,
            null,
            $deviceUid,
            'Physical device agent paired (' . (string) $row['device_name'] . ')'
        );
        $pdo->commit();
        return ['device_id' => $deviceId, 'device_name' => (string) $row['device_name']];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function school_staff_attendance_verify_agent_challenge(
    PDO $pdo,
    int $schoolId,
    string $deviceUid,
    string $nonce,
    string $signatureHex
): ?array {
    $deviceUid = strtolower(trim($deviceUid));
    $signatureHex = strtolower(trim($signatureHex));
    if (!preg_match('/^[a-f0-9]{64}$/D', $deviceUid)
        || !preg_match('/^[a-f0-9]{64}$/D', $signatureHex)
        || $nonce === ''
        || !school_staff_attendance_device_extended_columns_ready($pdo)) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, device_name, agent_secret_hash
         FROM school_staff_attendance_kiosk_devices
         WHERE school_id = ? AND device_uid = ? AND status = "active" AND deactivated_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([$schoolId, $deviceUid]);
    $device = $stmt->fetch();
    if (!$device || empty($device['agent_secret_hash'])) {
        return null;
    }

    // agent_secret_hash stores sha256(agentSecret); the local agent signs using sha256(agentSecret) or agentSecret
    // Verify HMAC-SHA256(nonce|school_id, agent_secret_hash) so raw agentSecret is never stored in DB.
    $expected = hash_hmac('sha256', $nonce . '|' . $schoolId, (string) $device['agent_secret_hash']);
    if (!hash_equals($expected, $signatureHex)) {
        return null;
    }

    return ['id' => (int) $device['id'], 'device_name' => (string) $device['device_name']];
}

function school_staff_attendance_list_devices(PDO $pdo, int $schoolId): array
{
    $extended = school_staff_attendance_device_extended_columns_ready($pdo);
    $extraCols = $extended
        ? 'device.device_location, device.status, device.auth_mode, device.device_uid, device.revoked_at, device.replaced_by_device_id,'
        : '"Main Office" AS device_location, IF(device.deactivated_at IS NULL, "active", "revoked") AS status, "browser_credential" AS auth_mode, NULL AS device_uid, device.deactivated_at AS revoked_at, NULL AS replaced_by_device_id,';
    $stmt = $pdo->prepare(
        'SELECT device.id, device.device_name, ' . $extraCols . '
                device.created_at, device.last_used_at, device.deactivated_at,
                registrar.full_name AS registered_by_name
         FROM school_staff_attendance_kiosk_devices device
         JOIN users registrar ON registrar.id = device.registered_by
         WHERE device.school_id = ?
         ORDER BY (device.deactivated_at IS NULL) DESC, device.created_at DESC, device.id DESC'
    );
    $stmt->execute([$schoolId]);
    return $stmt->fetchAll();
}

function school_staff_attendance_device_history(PDO $pdo, int $schoolId, int $limit = 30): array
{
    $limit = max(1, min(100, $limit));
    $stmt = $pdo->prepare(
        'SELECT event.id, event.event_type, event.reason, event.occurred_at,
                device.device_name, actor.full_name AS actor_name
         FROM school_staff_attendance_security_events event
         LEFT JOIN school_staff_attendance_kiosk_devices device
           ON device.id = event.device_id AND device.school_id = event.school_id
         LEFT JOIN users actor ON actor.id = event.actor_user_id
         WHERE event.school_id = ?
           AND event.event_type IN ("device_registered", "device_renamed", "device_activated", "device_deactivated", "device_revoked", "device_replaced", "device_agent_enrolled", "unauthorized_device")
         ORDER BY event.occurred_at DESC, event.id DESC
         LIMIT ' . $limit
    );
    $stmt->execute([$schoolId]);
    return $stmt->fetchAll();
}

