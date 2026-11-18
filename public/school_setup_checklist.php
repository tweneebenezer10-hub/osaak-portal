<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = require_school_context();
$school = active_school_context();
if ($school === null) {
    http_response_code(403);
    exit('Choose a school workspace before opening its setup checklist.');
}
$schoolId = (int) $school['id'];
$role = school_user_role_for_school((int) $user['id'], $schoolId);
if (!in_array($role, ['school_proprietor', 'school_headmaster'], true)) {
    http_response_code(403);
    exit('Only the school Proprietor or Headmaster can view the setup checklist.');
}

$countForSchool = static function (string $table) use ($schoolId): int {
    $allowedTables = [
        'school_classes',
        'school_subjects',
        'class_subjects',
        'school_staff',
        'school_students',
        'school_fee_structures',
        'school_assessments',
        'school_report_card_batches',
        'school_staff_salary_rates',
    ];
    if (!in_array($table, $allowedTables, true)) {
        throw new InvalidArgumentException('Unsupported school setup checklist table.');
    }
    $tableCheck = db()->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $tableCheck->execute([$table, 'school_id']);
    if ((int) $tableCheck->fetchColumn() !== 1) {
        return 0;
    }
    $stmt = db()->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE school_id = ?');
    $stmt->execute([$schoolId]);
    return (int) $stmt->fetchColumn();
};
$checklistStatus = static function (bool $hasSome, bool $isComplete): string {
    if ($isComplete) {
        return 'Completed';
    }
    return $hasSome ? 'In progress' : 'Not started';
};
$schoolStmt = db()->prepare(
    'SELECT name, short_name, address, contact_email, contact_phone
     FROM schools WHERE id = ? LIMIT 1'
);
$schoolStmt->execute([$schoolId]);
$schoolProfile = $schoolStmt->fetch();
$yearStmt = db()->prepare(
    'SELECT id, academic_year FROM school_academic_years
     WHERE school_id = ? AND status = "open"
     ORDER BY is_active DESC, ends_on DESC LIMIT 1'
);
$yearStmt->execute([$schoolId]);
$activeYear = $yearStmt->fetch() ?: null;
$termCount = 0;
if ($activeYear !== null) {
    $termStmt = db()->prepare(
        'SELECT COUNT(DISTINCT term_name) FROM school_terms
         WHERE school_id = ? AND academic_year_id = ?
           AND term_name IN ("First Term", "Second Term", "Third Term")
           AND starts_on IS NOT NULL AND ends_on IS NOT NULL'
    );
    $termStmt->execute([$schoolId, (int) $activeYear['id']]);
    $termCount = (int) $termStmt->fetchColumn();
}
$schoolSettingsStmt = db()->prepare(
    'SELECT setting_key, setting_value FROM school_settings WHERE school_id = ?'
);
$schoolSettingsStmt->execute([$schoolId]);
$schoolSettings = [];
foreach ($schoolSettingsStmt->fetchAll() as $setting) {
    $schoolSettings[(string) $setting['setting_key']] = (string) $setting['setting_value'];
}
$kioskDeviceSchemaStmt = db()->prepare(
    'SELECT COUNT(DISTINCT column_name) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = ?
       AND column_name IN ("school_id", "deactivated_at")'
);
$kioskDeviceSchemaStmt->execute(['school_staff_attendance_kiosk_devices']);
$kioskDeviceSchemaReady = (int) $kioskDeviceSchemaStmt->fetchColumn() === 2;
$activeKioskDeviceCount = 0;
if ($kioskDeviceSchemaReady) {
    $activeKioskDeviceStmt = db()->prepare(
        'SELECT COUNT(*) FROM school_staff_attendance_kiosk_devices
         WHERE school_id = ? AND deactivated_at IS NULL'
    );
    $activeKioskDeviceStmt->execute([$schoolId]);
    $activeKioskDeviceCount = (int) $activeKioskDeviceStmt->fetchColumn();
}
$attendanceWeekdays = isset($schoolSettings['attendance_working_weekdays'])
    ? json_decode($schoolSettings['attendance_working_weekdays'], true)
    : null;
$hasAttendanceSetting = is_array($attendanceWeekdays)
    && $attendanceWeekdays !== []
    && count(array_filter(
        $attendanceWeekdays,
        static fn(mixed $weekday): bool => filter_var($weekday, FILTER_VALIDATE_INT) !== false
            && (int) $weekday >= 1 && (int) $weekday <= 7
    )) === count($attendanceWeekdays);
$classesCount = $countForSchool('school_classes');
$subjectsCount = $countForSchool('school_subjects');
$subjectAssignmentsCount = $countForSchool('class_subjects');
$staffCount = $countForSchool('school_staff');
$studentCount = $countForSchool('school_students');
$feeCount = $countForSchool('school_fee_structures');
$assessmentCount = $countForSchool('school_assessments');
$reportBatchCount = $countForSchool('school_report_card_batches');
$salaryRateCount = $countForSchool('school_staff_salary_rates');
$steps = [
    [
        'name' => 'School profile and contact details',
        'detail' => 'Add the school’s name, address, and contact information.',
        'status' => $checklistStatus(
            !empty($schoolProfile['name']) || !empty($schoolProfile['short_name']),
            !empty($schoolProfile['name']) && !empty($schoolProfile['short_name'])
                && (!empty($schoolProfile['contact_email']) || !empty($schoolProfile['contact_phone']))
        ),
        'href' => 'school_settings.php',
    ],
    [
        'name' => 'Academic year and terms',
        'detail' => 'Set an academic year and configure First, Second, and Third Term.',
        'status' => $checklistStatus($activeYear !== null, $activeYear !== null && $termCount === 3),
        'href' => 'term_setup.php',
    ],
    [
        'name' => 'Class levels and streams',
        'detail' => 'Create the classes and streams used by this school.',
        'status' => $checklistStatus($classesCount > 0, $classesCount > 0),
        'href' => 'classes.php',
    ],
    [
        'name' => 'Subjects and class assignments',
        'detail' => 'Add subjects and assign them to the school’s classes.',
        'status' => $checklistStatus($subjectsCount > 0 || $subjectAssignmentsCount > 0, $subjectsCount > 0 && $subjectAssignmentsCount > 0),
        'href' => 'subjects.php',
    ],
    [
        'name' => 'Staff and teacher accounts',
        'detail' => 'Add active staff and verify their school roles.',
        'status' => $checklistStatus($staffCount > 0, $staffCount > 0),
        'href' => 'staff.php',
    ],
    [
        'name' => 'Staff attendance kiosk device',
        'detail' => $kioskDeviceSchemaReady
            ? 'Authorize one school device during initial setup before using the kiosk; administrators may add more devices later.'
            : 'Device setup is unavailable until a technical administrator completes the required kiosk database update.',
        'status' => $kioskDeviceSchemaReady
            ? $checklistStatus($activeKioskDeviceCount > 0, $activeKioskDeviceCount > 0)
            : 'Blocked',
        'href' => 'staff_attendance.php#kiosk-setup',
    ],
    [
        'name' => 'Student registration',
        'detail' => 'Register students and assign them to the correct class and year.',
        'status' => $checklistStatus($studentCount > 0, $studentCount > 0),
        'href' => 'students.php',
    ],
    [
        'name' => 'Fee structure',
        'detail' => 'Set the school’s fee schedule and review it before billing.',
        'status' => $checklistStatus($feeCount > 0, $feeCount > 0),
        'href' => 'fee_management.php',
    ],
    [
        'name' => 'Attendance settings',
        'detail' => 'Choose the regular school days used by attendance summaries.',
        'status' => $checklistStatus($hasAttendanceSetting, $hasAttendanceSetting),
        'href' => 'term_setup.php#school-calendar',
    ],
    [
        'name' => 'Examinations and grading',
        'detail' => 'Set up class examinations and subject assessments.',
        'status' => $checklistStatus($assessmentCount > 0, $assessmentCount > 0),
        'href' => 'assessments.php',
    ],
    [
        'name' => 'Report cards',
        'detail' => 'Prepare and review report-card settings and publication steps.',
        'status' => $checklistStatus($reportBatchCount > 0, $reportBatchCount > 0),
        'href' => 'report_cards.php',
    ],
    [
        'name' => 'Payroll settings',
        'detail' => 'Record approved monthly salary rates before preparing payroll.',
        'status' => $checklistStatus($salaryRateCount > 0, $salaryRateCount > 0),
        'href' => 'staff_payroll.php',
    ],
    [
        'name' => 'Notifications and essential settings',
        'detail' => 'Review school settings and confirm that staff can receive important notices.',
        'status' => $schoolSettings !== [] ? 'In progress' : 'Not started',
        'href' => 'school_settings.php',
    ],
];

render_header('School setup checklist');
?>
<section class="school-identity-banner">
    <?= school_identity_markup($school) ?>
    <h1>School setup checklist</h1>
</section>
<section class="panel spaced-top">
    <p class="muted">Use this checklist to see which core school records are configured. Statuses are calculated from saved school data; opening a page does not mark its step complete.</p>
    <div class="table-scroll">
        <table>
            <thead><tr><th>Setup step</th><th>What to do</th><th>Status</th><th>Open</th></tr></thead>
            <tbody>
                <?php foreach ($steps as $step): ?>
                    <tr>
                        <th scope="row"><?= e($step['name']) ?></th>
                        <td><?= e($step['detail']) ?></td>
                        <td><?= e($step['status']) ?></td>
                        <td><a class="button button-small" href="<?= e($step['href']) ?>">Open step</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php render_footer(); ?>
