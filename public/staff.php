<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/school_staff.php';

$user = require_school_context();
$school = active_school_context();
$schoolId = (int) $school['id'];
require_school_permission($schoolId, 'staff.manage');
$schoolRole = (string) ($user['active_school_role'] ?? school_user_role_for_school((int) $user['id'], $schoolId));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save_appointment_date') {
        $staffUserId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $appointmentDate = trim((string) ($_POST['appointed_on'] ?? ''));
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $appointmentDate);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if ($staffUserId === false || $staffUserId < 1 || $date === false
            || $date->format('Y-m-d') !== $appointmentDate
            || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            || $date > new DateTimeImmutable('today')) {
            flash('error', 'Enter a valid appointment date that is not in the future.');
            redirect('staff.php');
        }
        $staffRoleStmt = db()->prepare(
            'SELECT MIN(role_name) FROM school_memberships
             WHERE school_id = ? AND user_id = ? AND status = "active"
               AND role_name IN ("school_proprietor", "school_headmaster", "class_teacher",
                   "subject_teacher", "school_accountant", "school_assistant_headmaster",
                   "school_secretary")'
        );
        $staffRoleStmt->execute([$schoolId, $staffUserId]);
        $staffRole = $staffRoleStmt->fetchColumn();
        if ($staffRole === false) {
            flash('error', 'Appointment dates can only be recorded for an active approved staff member at this school.');
            redirect('staff.php');
        }
        $saveAppointment = db()->prepare(
            'INSERT INTO school_staff (school_id, user_id, employee_number, appointed_on, role_name, status)
             VALUES (?, ?, NULL, ?, ?, "active")
             ON DUPLICATE KEY UPDATE appointed_on = VALUES(appointed_on)'
        );
        $saveAppointment->execute([$schoolId, $staffUserId, $appointmentDate, (string) $staffRole]);
        $verifyAppointment = db()->prepare(
            'SELECT appointed_on FROM school_staff WHERE school_id = ? AND user_id = ? LIMIT 1'
        );
        $verifyAppointment->execute([$schoolId, $staffUserId]);
        if ($verifyAppointment->fetchColumn() !== $appointmentDate) {
            flash('error', 'The staff record could not be updated. Refresh and try again.');
            redirect('staff.php');
        }
        audit((int) $user['id'], 'school_staff_appointment_date_saved', 'school_id=' . $schoolId . ';user_id=' . $staffUserId);
        flash('success', 'Appointment date saved. Existing employee numbers were not changed.');
        redirect('staff.php');
    }
    if ($action === 'backfill_employee_numbers') {
        $dryRun = (string) ($_POST['mode'] ?? '') !== 'apply';
        try {
            $backfillResult = school_staff_employee_number_backfill($schoolId, (int) $user['id'], $dryRun);
        } catch (Throwable $exception) {
            error_log('School employee-number backfill failed: ' . $exception->getMessage());
            flash('error', 'Employee numbers could not be processed. Existing staff records were preserved.');
            redirect('staff.php');
        }
        $processed = $dryRun ? (int) $backfillResult['would_assign'] : (int) $backfillResult['assigned'];
        $summary = $dryRun ? 'Preview complete: ' : 'Backfill complete: ';
        $summary .= $processed . ' number(s) ' . ($dryRun ? 'ready to assign' : 'assigned')
            . ', ' . (int) $backfillResult['already_valid'] . ' already valid, '
            . count($backfillResult['needs_review']) . ' need review.';
        if ($backfillResult['needs_review'] !== []) {
            $reviewItems = array_slice(array_map(
                static fn(array $item): string => 'staff #' . (int) $item['staff_id'] . ': ' . $item['reason'],
                $backfillResult['needs_review']
            ), 0, 4);
            $summary .= ' ' . implode(' ', $reviewItems);
            if (count($backfillResult['needs_review']) > count($reviewItems)) {
                $summary .= ' See Needs Review statuses below for remaining records.';
            }
        }
        flash('success', $summary);
        audit((int) $user['id'], 'school_employee_number_backfill', 'school_id=' . $schoolId . ';mode=' . ($dryRun ? 'preview' : 'apply') . ';assigned=' . $processed . ';needs_review=' . count($backfillResult['needs_review']));
        redirect('staff.php');
    }
    if (in_array($action, ['add_staff', 'approve_membership'], true)) {
        audit((int) $user['id'], 'legacy_direct_school_role_change_denied', 'school_id=' . $schoolId . ';action=' . $action);
        flash('error', 'School roles can only be requested by the account holder and changed through the reviewed role-request workflow.');
        redirect('school_role_requests.php');
    }
    if ($action === 'add_staff') {
        $identifier = trim((string) ($_POST['identifier'] ?? ''));
        $roleName = trim((string) ($_POST['role_name'] ?? ''));
        $employeeNumber = trim((string) ($_POST['employee_number'] ?? ''));
        $assignableRoles = [
            'school_proprietor',
            'school_headmaster',
            'school_assistant_headmaster',
            'school_secretary',
            'class_teacher',
            'subject_teacher',
            'school_accountant',
        ];
        if ($identifier === '' || !in_array($roleName, $assignableRoles, true)) {
            flash('error', 'School staff requires a valid account identifier and role.');
            redirect('staff.php');
        }

        $userStmt = db()->prepare(
            'SELECT id, email, status, email_verified_at
             FROM users WHERE username = ? OR email = ? LIMIT 1'
        );
        $userStmt->execute([$identifier, $identifier]);
        $account = $userStmt->fetch();
        if (!$account || $account['status'] !== 'active' || $account['email_verified_at'] === null) {
            flash('error', 'A verified, active account is required before assigning school access.');
            redirect('staff.php');
        }

        $staffUserId = (int) $account['id'];
        if ($staffUserId === (int) $user['id']
            || strtolower(trim((string) $account['email'])) === PLATFORM_SUPER_ADMIN_EMAIL
            || platform_super_admin_user(['id' => $staffUserId])) {
            flash('error', 'The Platform Super Admin account cannot be assigned as school staff.');
            redirect('staff.php');
        }
        db()->beginTransaction();
        try {
            $lockUser = db()->prepare(
                "SELECT id FROM users
                 WHERE id = ? AND status = 'active' AND email_verified_at IS NOT NULL
                 FOR UPDATE"
            );
            $lockUser->execute([$staffUserId]);
            if (!$lockUser->fetchColumn()) {
                throw new RuntimeException('A verified, active account is required before assigning school access.');
            }
            $schoolRequestStmt = db()->prepare(
                'SELECT role_name, status FROM school_memberships
                 WHERE school_id = ? AND user_id = ? FOR UPDATE'
            );
            $schoolRequestStmt->execute([$schoolId, $staffUserId]);
            $schoolMemberships = $schoolRequestStmt->fetchAll();
            $hasPendingRequest = false;
            $hasActiveAtSchool = false;
            foreach ($schoolMemberships as $schoolMembership) {
                $hasPendingRequest = $hasPendingRequest
                    || ($schoolMembership['status'] === 'pending' && $schoolMembership['role_name'] === $roleName);
                $hasActiveAtSchool = $hasActiveAtSchool
                    || ($schoolMembership['status'] === 'active');
            }
            if (!$hasPendingRequest && !$hasActiveAtSchool) {
                throw new RuntimeException('The account holder must first select this school and request the same role before access can be assigned.');
            }
            $existingAssignments = db()->prepare(
                "SELECT school_id, role_name FROM school_memberships
                 WHERE user_id = ? AND status = 'active' FOR UPDATE"
            );
            $existingAssignments->execute([$staffUserId]);
            foreach ($existingAssignments->fetchAll() as $assignment) {
                if ((int) $assignment['school_id'] !== $schoolId) {
                    throw new RuntimeException('This account is assigned to another school. Only the Platform Super Admin can transfer school assignments.');
                }
            }
            $disableOldRoles = db()->prepare(
                "UPDATE school_memberships SET status = 'disabled'
                 WHERE school_id = ? AND user_id = ? AND status = 'active' AND role_name <> ?"
            );
            $disableOldRoles->execute([$schoolId, $staffUserId, $roleName]);
            $membershipStmt = db()->prepare(
                'INSERT INTO school_memberships (school_id, user_id, role_name, status, approved_by)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE status = VALUES(status), approved_by = VALUES(approved_by)'
            );
            $membershipStmt->execute([$schoolId, $staffUserId, $roleName, 'active', (int) $user['id']]);

            $staffStmt = db()->prepare(
                'INSERT INTO school_staff (school_id, user_id, employee_number, role_name, status)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE employee_number = VALUES(employee_number), role_name = VALUES(role_name), status = VALUES(status)'
            );
            $staffStmt->execute([$schoolId, $staffUserId, $employeeNumber === '' ? null : $employeeNumber, $roleName, 'active']);
            audit((int) $user['id'], 'school_staff_role_assigned', 'school_id=' . $schoolId . ';user_id=' . $staffUserId . ';role=' . $roleName);
            db()->commit();
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            if ($exception instanceof RuntimeException) {
                flash('error', $exception->getMessage());
                redirect('staff.php');
            }
            throw $exception;
        }
        flash('success', 'Staff assignment saved.');
        redirect('staff.php');
    }

    if ($action === 'approve_membership') {
        $membershipId = filter_var($_POST['membership_id'] ?? null, FILTER_VALIDATE_INT);
        if ($membershipId === false || $membershipId < 1) {
            flash('error', 'Choose a valid school registration request.');
            redirect('staff.php');
        }

        db()->beginTransaction();
        try {
            $requestStmt = db()->prepare(
                "SELECT sm.id, sm.user_id, sm.role_name, u.email, u.status AS user_status,
                        u.email_verified_at
                 FROM school_memberships sm
                 JOIN users u ON u.id = sm.user_id
                 WHERE sm.id = ? AND sm.school_id = ? AND sm.status = 'pending'
                 FOR UPDATE"
            );
            $requestStmt->execute([$membershipId, $schoolId]);
            $request = $requestStmt->fetch();
            $requestRoles = [
                'school_proprietor',
                'school_headmaster',
                'school_assistant_headmaster',
                'school_secretary',
                'class_teacher',
                'subject_teacher',
                'school_accountant',
                'school_parent',
                'school_student',
            ];
            if (!$request || $request['user_status'] !== 'active'
                || $request['email_verified_at'] === null
                || !in_array($request['role_name'], $requestRoles, true)) {
                throw new RuntimeException('This request is unavailable, unverified, or no longer eligible for approval.');
            }

            if ($request['role_name'] === 'school_parent') {
                $guardianStmt = db()->prepare(
                    'SELECT 1 FROM school_guardians
                     WHERE school_id = ? AND LOWER(TRIM(email)) = ? LIMIT 1'
                );
                $guardianStmt->execute([$schoolId, strtolower(trim((string) $request['email']))]);
                if (!$guardianStmt->fetchColumn()) {
                    throw new RuntimeException('A parent account can only be approved after the verified email matches a guardian record at this school.');
                }
            }

            $studentLink = null;
            if ($request['role_name'] === 'school_student') {
                $linkStmt = db()->prepare(
                    'SELECT id, student_id, requested_admission_number, requested_date_of_birth, status
                     FROM school_student_account_links
                     WHERE school_id = ? AND user_id = ? AND status = "pending"
                     FOR UPDATE'
                );
                $linkStmt->execute([$schoolId, (int) $request['user_id']]);
                $studentLink = $linkStmt->fetch();
                if (!$studentLink) {
                    throw new RuntimeException('A student account request requires a pending student-record link.');
                }
                $selectedStudentId = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);
                if ($selectedStudentId === false || $selectedStudentId < 1
                    || ($studentLink['student_id'] !== null && (int) $studentLink['student_id'] !== $selectedStudentId)) {
                    throw new RuntimeException('Select the student record matched to the applicant or review the admission details before approving.');
                }
                $studentStmt = db()->prepare(
                    'SELECT id FROM school_students
                     WHERE id = ? AND school_id = ? AND school_status = "active"
                     FOR UPDATE'
                );
                $studentStmt->execute([$selectedStudentId, $schoolId]);
                if (!$studentStmt->fetchColumn()) {
                    throw new RuntimeException('The selected student record is not active in this school.');
                }
                $existingLinkStmt = db()->prepare(
                    'SELECT user_id FROM school_student_account_links
                     WHERE student_id = ? AND status IN ("pending", "active") AND id <> ?
                     LIMIT 1 FOR UPDATE'
                );
                $existingLinkStmt->execute([$selectedStudentId, (int) $studentLink['id']]);
                if ($existingLinkStmt->fetchColumn()) {
                    throw new RuntimeException('This student record already has another pending or active account link.');
                }
            }

            $existingAssignments = db()->prepare(
                "SELECT school_id, role_name FROM school_memberships
                 WHERE user_id = ? AND status = 'active' FOR UPDATE"
            );
            $existingAssignments->execute([(int) $request['user_id']]);
            foreach ($existingAssignments->fetchAll() as $assignment) {
                if ((int) $assignment['school_id'] !== $schoolId) {
                    throw new RuntimeException('This account is already assigned to another school. Only the Platform Super Admin can transfer school assignments.');
                }
            }
            $disableOldRoles = db()->prepare(
                "UPDATE school_memberships SET status = 'disabled'
                 WHERE school_id = ? AND user_id = ? AND status = 'active' AND role_name <> ?"
            );
            $disableOldRoles->execute([$schoolId, (int) $request['user_id'], (string) $request['role_name']]);
            $approveStmt = db()->prepare(
                "UPDATE school_memberships SET status = 'active', approved_by = ?
                 WHERE id = ? AND school_id = ? AND status = 'pending'"
            );
            $approveStmt->execute([(int) $user['id'], $membershipId, $schoolId]);

            if (!in_array($request['role_name'], ['school_parent', 'school_student'], true)) {
                $staffStmt = db()->prepare(
                    'INSERT INTO school_staff (school_id, user_id, role_name, status)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE role_name = VALUES(role_name), status = VALUES(status)'
                );
                $staffStmt->execute([$schoolId, (int) $request['user_id'], (string) $request['role_name'], 'active']);
            } elseif ($request['role_name'] === 'school_parent') {
                $disableStaff = db()->prepare(
                    "UPDATE school_staff SET status = 'inactive'
                     WHERE school_id = ? AND user_id = ?"
                );
                $disableStaff->execute([$schoolId, (int) $request['user_id']]);
                $clearClassTeacher = db()->prepare(
                    'UPDATE school_classes SET class_teacher_id = NULL
                     WHERE school_id = ? AND class_teacher_id = ?'
                );
                $clearClassAssignments = db()->prepare(
                    'DELETE FROM teacher_class_assignments WHERE school_id = ? AND teacher_user_id = ?'
                );
                $clearClassAssignments->execute([$schoolId, (int) $request['user_id']]);
                $clearClassTeacher->execute([$schoolId, (int) $request['user_id']]);
                $clearSubjectTeacher = db()->prepare(
                    'UPDATE class_subjects SET teacher_user_id = NULL
                     WHERE school_id = ? AND teacher_user_id = ?'
                );
                $clearSubjectTeacher->execute([$schoolId, (int) $request['user_id']]);
            } else {
                $linkApproveStmt = db()->prepare(
                    'UPDATE school_student_account_links
                     SET student_id = ?, status = "active", requested_date_of_birth = NULL,
                         reviewed_by = ?, reviewed_at = UTC_TIMESTAMP()
                     WHERE id = ? AND school_id = ? AND user_id = ? AND status = "pending"'
                );
                $linkApproveStmt->execute([
                    $selectedStudentId, (int) $user['id'], (int) $studentLink['id'],
                    $schoolId, (int) $request['user_id'],
                ]);
                if ($linkApproveStmt->rowCount() !== 1) {
                    throw new RuntimeException('The student record link could not be approved. Refresh and review the request again.');
                }
            }
            audit(
                (int) $user['id'],
                'school_registration_approved',
                'school_id=' . $schoolId . ';user_id=' . (int) $request['user_id'] . ';role=' . (string) $request['role_name']
            );
            db()->commit();
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            if ($exception instanceof RuntimeException) {
                flash('error', $exception->getMessage());
                redirect('staff.php');
            }
            throw $exception;
        }
        flash('success', 'School account request approved.');
        redirect('staff.php');
    }
}

$pendingStmt = db()->prepare(
    "SELECT sm.id, sm.user_id, sm.role_name, sm.joined_at, u.full_name, u.email,
            sl.id AS student_link_id, sl.student_id AS matched_student_id,
            sl.requested_admission_number, sl.requested_date_of_birth,
            s.full_name AS matched_student_name, s.date_of_birth AS matched_student_date_of_birth
     FROM school_memberships sm
     JOIN users u ON u.id = sm.user_id
     LEFT JOIN school_student_account_links sl
       ON sl.school_id = sm.school_id AND sl.user_id = sm.user_id AND sl.status = 'pending'
     LEFT JOIN school_students s
       ON s.id = sl.student_id AND s.school_id = sl.school_id
     WHERE sm.school_id = ? AND sm.status = 'pending'
       AND u.status = 'active' AND u.email_verified_at IS NOT NULL
     ORDER BY sm.joined_at ASC"
);
$pendingStmt->execute([$schoolId]);
$pendingRequests = $pendingStmt->fetchAll();
$availableStudentStmt = db()->prepare(
    'SELECT ss.id, ss.full_name, ss.admission_number, ss.date_of_birth
     FROM school_students ss
     WHERE ss.school_id = ? AND ss.school_status = "active"
       AND NOT EXISTS (
           SELECT 1 FROM school_student_account_links sl
           WHERE sl.student_id = ss.id AND sl.status IN ("active", "pending")
       )
     ORDER BY ss.full_name, ss.id'
);
$availableStudentStmt->execute([$schoolId]);
$availableStudentRecords = $availableStudentStmt->fetchAll();

$staffStmt = db()->prepare(
    'SELECT sm.user_id, u.full_name, u.email, ss.id AS staff_id,
            ss.employee_number, ss.appointed_on, ss.status AS staff_status,
            GROUP_CONCAT(DISTINCT sm.role_name ORDER BY sm.role_name SEPARATOR ", ") AS roles,
            COALESCE(number_counts.copy_count, 0) AS employee_number_copies
     FROM school_memberships sm
     JOIN users u ON u.id = sm.user_id AND u.status = "active" AND u.email_verified_at IS NOT NULL
     LEFT JOIN school_staff ss ON ss.school_id = sm.school_id AND ss.user_id = sm.user_id
     LEFT JOIN (
         SELECT school_id, employee_number, COUNT(*) AS copy_count
         FROM school_staff
         WHERE employee_number IS NOT NULL AND TRIM(employee_number) <> ""
         GROUP BY school_id, employee_number
     ) number_counts ON number_counts.school_id = sm.school_id
        AND number_counts.employee_number = ss.employee_number
     WHERE sm.school_id = ? AND sm.status = "active"
       AND sm.role_name IN ("school_proprietor", "school_headmaster", "class_teacher",
           "subject_teacher", "school_accountant", "school_assistant_headmaster",
           "school_secretary")
     GROUP BY sm.user_id, u.full_name, u.email, ss.id, ss.employee_number,
              ss.appointed_on, ss.status, number_counts.copy_count
     ORDER BY u.full_name ASC'
);
$staffStmt->execute([$schoolId]);
$staff = $staffStmt->fetchAll();
$staffAbbreviationStmt = db()->prepare('SELECT COALESCE(NULLIF(admission_abbreviation, ""), short_name) FROM schools WHERE id = ?');
$staffAbbreviationStmt->execute([$schoolId]);
$staffAbbreviation = (string) $staffAbbreviationStmt->fetchColumn();
$validEmployeeNumbers = 0;
$readyEmployeeNumbers = 0;
$employeeNumbersNeedingReview = 0;
foreach ($staff as &$member) {
    $member['employee_number_review_reason'] = school_staff_employee_number_review_reason(
        $staffAbbreviation,
        $member['employee_number'] === null ? null : (string) $member['employee_number'],
        $member['appointed_on'] === null ? null : (string) $member['appointed_on'],
        (int) $member['employee_number_copies'] > 1
    );
    if ($member['employee_number_review_reason'] === null) {
        $member['employee_number_status'] = 'Valid';
        $validEmployeeNumbers++;
    } elseif ($member['employee_number_review_reason'] === 'Ready for number generation.') {
        $member['employee_number_status'] = 'Ready to assign';
        $readyEmployeeNumbers++;
    } else {
        $member['employee_number_status'] = 'Needs Review';
        $employeeNumbersNeedingReview++;
    }
}
unset($member);

render_header('School staff');
?>
<section class="panel">
    <p class="eyebrow">SCHOOL PERSONNEL</p>
    <h1>Staff and employee numbers</h1>
</section>

<section class="report-action-grid" aria-label="Staff management actions">
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('staff') ?></span>
        <div><h2>Manage Staff</h2><p>Review approved staff, appointment dates, and employee numbers.</p></div>
        <a class="button button-primary" href="#current-staff" data-action-open="current-staff">Open Staff Register</a>
    </article>
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('staff') ?></span>
        <div><h2>Employment history</h2><p>Record suspension, termination, resignation, retirement, transfer, and responsibility changes.</p></div>
        <a class="button" href="staff_employment.php">Open Employment History</a>
    </article>
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('attendance') ?></span>
        <div><h2>Staff Attendance</h2><p>Monitor kiosk check-ins, configure schedules, and review or correct attendance.</p></div>
        <a class="button" href="staff_attendance.php">Open Attendance</a>
    </article>
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('person-check') ?></span>
        <div><h2>Role Requests</h2><p>Review requests for approved school roles and access.</p></div>
        <a class="button" href="#staff-requests" data-action-open="staff-requests">Review Requests</a>
    </article>
</section>

<section class="panel" id="staff-requests" data-action-view="staff-requests" style="margin-top:1.5rem;">
    <h2>Verified school account requests</h2>
    <p class="muted">Role requests are reviewed separately, with applicant-provided reasons and eligibility evidence.</p>
    <p><a class="button button-primary" href="school_role_requests.php">Open role requests and access management</a></p>
    <?php if ($pendingRequests === []): ?>
        <p class="muted">There are no verified requests waiting for approval.</p>
    <?php else: ?>
        <table>
            <thead><tr><th>Name</th><th>Email</th><th>Requested role</th><th>Requested</th><th>Action</th></tr></thead>
            <tbody>
                <?php foreach ($pendingRequests as $request): ?>
                    <tr>
                        <td><?= e((string) $request['full_name']) ?></td>
                        <td><?= e((string) $request['email']) ?></td>
                        <td><?= e(role_display_name((string) $request['role_name'])) ?>
                            <?php if ($request['role_name'] === 'school_student'): ?>
                                <br><small>Admission number provided: <?= e((string) ($request['requested_admission_number'] ?: 'Not provided')) ?>
                                    · Date of birth provided: <?= e((string) ($request['requested_date_of_birth'] ?: 'Not provided')) ?>
                                    <?= $request['matched_student_name'] ? ' · Matched record: ' . e((string) $request['matched_student_name']) . ' (DOB ' . e((string) ($request['matched_student_date_of_birth'] ?: 'not recorded')) . ')' : ' · No exact automatic match; verify identity and choose the correct active student record.' ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td><?= e((string) $request['joined_at']) ?></td>
                        <td>
                            <form method="post" action="staff.php">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="approve_membership">
                                <input type="hidden" name="membership_id" value="<?= (int) $request['id'] ?>">
                                <?php if ($request['role_name'] === 'school_student'): ?>
                                    <label for="student-link-<?= (int) $request['id'] ?>">Verified student record</label>
                                    <select id="student-link-<?= (int) $request['id'] ?>" name="student_id" required>
                                        <option value="">Choose active student record</option>
                                        <?php if ($request['matched_student_id'] !== null): ?>
                                            <option value="<?= (int) $request['matched_student_id'] ?>" selected><?= e((string) $request['matched_student_name']) ?> · <?= e((string) $request['requested_admission_number']) ?> · DOB <?= e((string) ($request['matched_student_date_of_birth'] ?: 'not recorded')) ?></option>
                                        <?php endif; ?>
                                        <?php foreach ($availableStudentRecords as $studentRecord): ?>
                                            <?php if ((int) $studentRecord['id'] !== (int) ($request['matched_student_id'] ?? 0)): ?>
                                                <option value="<?= (int) $studentRecord['id'] ?>"><?= e((string) $studentRecord['full_name']) ?><?= $studentRecord['admission_number'] ? ' · ' . e((string) $studentRecord['admission_number']) : '' ?><?= $studentRecord['date_of_birth'] ? ' · DOB ' . e((string) $studentRecord['date_of_birth']) : '' ?></option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                    <small>Verify the applicant’s identity and school record before approving. A student record can only be linked to one account.</small>
                                <?php endif; ?>
                                <button class="button button-primary" type="submit">Verify and approve</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<section class="panel" id="current-staff" data-action-view="current-staff" style="margin-top:1.5rem;">
    <h2>Current staff</h2>
    <p class="muted">One row per person, even when they hold multiple approved roles. Only active, verified staff with a valid appointment date on or before today are eligible. Existing employee numbers are never overwritten; the recorded appointment date determines the joining year.</p>
    <div class="form-row">
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="backfill_employee_numbers"><input type="hidden" name="mode" value="preview">
            <button class="button" type="submit">Preview employee numbers</button>
        </form>
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="backfill_employee_numbers"><input type="hidden" name="mode" value="apply">
            <button class="button button-primary" type="submit" data-confirm="Assign numbers only to eligible staff with valid appointment dates on or before today? Existing numbers will not be overwritten."<?= $readyEmployeeNumbers === 0 ? ' disabled' : '' ?>>Assign eligible numbers</button>
        </form>
    </div>
    <p class="muted">Already valid: <?= $validEmployeeNumbers ?> · Eligible to assign: <?= $readyEmployeeNumbers ?> · Needs review: <?= $employeeNumbersNeedingReview ?>. Preview first to check the next numbers; save each actual appointment date before assigning.</p>
    <?php if ($staff === []): ?>
        <p class="muted">No staff are assigned to this school yet.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr><th>Name</th><th>Email</th><th>Approved roles</th><th>Appointment date</th><th>Employee no.</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php foreach ($staff as $member): ?>
                    <tr>
                        <td><?= e((string) $member['full_name']) ?></td>
                        <td><?= e((string) $member['email']) ?></td>
                        <td><?= e((string) $member['roles']) ?></td>
                        <td>
                            <form method="post" class="inline-form">
                                <?= csrf_field() ?><input type="hidden" name="action" value="save_appointment_date"><input type="hidden" name="user_id" value="<?= (int) $member['user_id'] ?>">
                                <input type="date" name="appointed_on" aria-label="Appointment date for <?= e((string) $member['full_name']) ?>" max="<?= e(date('Y-m-d')) ?>" value="<?= e((string) ($member['appointed_on'] ?? '')) ?>" required>
                                <button class="button button-small" type="submit">Save date</button>
                            </form>
                        </td>
                        <td><?= e((string) ($member['employee_number'] ?: '—')) ?></td>
                        <td><strong><?= e((string) $member['employee_number_status']) ?></strong><?php if ($member['employee_number_review_reason'] !== null && $member['employee_number_review_reason'] !== 'Ready for number generation.'): ?><br><small class="muted"><?= e((string) $member['employee_number_review_reason']) ?></small><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
