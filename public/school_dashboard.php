<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/school_attendance.php';
require_once APP_ROOT . '/app/school_staff_attendance.php';

$user = require_school_context();
$school = active_school_context();
if (!$school) {
    flash('error', 'No active school was found for your account.');
    redirect('schools.php');
}

$schoolId = (int) $school['id'];
$schoolClassLevels = school_class_levels($schoolId, true);
$schoolRole = (string) ($user['active_school_role'] ?? school_user_role_for_school((int) $user['id'], $schoolId));
$hasOwnStaffWorkspace = false;
$ownStaffRecord = null;
if (active_it_support_context($user) === null) {
    $ownStaffWorkspaceStmt = db()->prepare(
        'SELECT staff.id, staff.employee_number, staff.role_name, staff.created_at
         FROM school_staff staff
         JOIN users account ON account.id = staff.user_id
           AND account.status = "active" AND account.email_verified_at IS NOT NULL
         WHERE staff.school_id = ? AND staff.user_id = ? AND staff.status = "active"
           AND EXISTS (
               SELECT 1 FROM school_memberships membership
               WHERE membership.school_id = staff.school_id
                 AND membership.user_id = staff.user_id AND membership.status = "active"
                 AND membership.role_name NOT IN ("school_parent", "school_student")
           )
         LIMIT 1'
    );
    $ownStaffWorkspaceStmt->execute([$schoolId, (int) $user['id']]);
    $ownStaffRecord = $ownStaffWorkspaceStmt->fetch() ?: null;
    $hasOwnStaffWorkspace = $ownStaffRecord !== null;
}
$ownStaffAttendance = null;
if ($ownStaffRecord !== null) {
    $ownAttendanceTimezone = school_staff_attendance_timezone(db(), $schoolId);
    $ownAttendanceNow = new DateTimeImmutable('now', $ownAttendanceTimezone);
    $ownAttendanceToday = $ownAttendanceNow->format('Y-m-d');
    $ownAttendanceSchedule = school_staff_attendance_schedule(
        db(),
        $schoolId,
        (string) $ownStaffRecord['role_name']
    );
    $ownTodayAttendanceStmt = db()->prepare(
        'SELECT arrival_at, departure_at, status, punctuality_status
         FROM school_staff_attendance
         WHERE school_id = ? AND staff_id = ? AND attendance_date = ? LIMIT 1'
    );
    $ownTodayAttendanceStmt->execute([
        $schoolId,
        (int) $ownStaffRecord['id'],
        $ownAttendanceToday,
    ]);
    $ownTodayAttendance = $ownTodayAttendanceStmt->fetch() ?: null;
    $ownStaffAttendance = [
        'timezone' => $ownAttendanceTimezone,
        'today' => $ownAttendanceToday,
        'today_is_scheduled' => school_staff_attendance_is_working_day(
            db(),
            $schoolId,
            $ownAttendanceToday,
            $ownAttendanceSchedule
        ),
        'today_record' => $ownTodayAttendance,
    ];
}
if ($schoolRole === 'school_student') {
    redirect('student_dashboard.php');
}
if (!school_has_permission($user, $schoolId, 'school.view')
    && !school_has_permission($user, $schoolId, 'students.view_own')) {
    http_response_code(403);
    render_header('School permission denied');
    echo '<section class="panel"><h1>School permission denied</h1><p>Your role does not allow access to this school dashboard.</p><a class="button" href="schools.php">Return to school workspaces</a></section>';
    render_footer();
    exit;
}
$canManageStudents = school_has_permission($user, $schoolId, 'students.manage');
$canManageClasses = school_has_permission($user, $schoolId, 'classes.manage');
$canManageStaff = school_has_permission($user, $schoolId, 'staff.manage');
$canViewStaff = school_has_permission($user, $schoolId, 'staff.view');
$canManageAttendance = $schoolRole === 'class_teacher'
    && school_has_permission($user, $schoolId, 'attendance.manage');
$canManageStaffAttendance = in_array($schoolRole, ['school_headmaster', 'school_proprietor'], true)
    && school_has_permission($user, $schoolId, 'attendance.manage');
$canManageResults = school_has_permission($user, $schoolId, 'results.manage');
$canManageFees = school_has_permission($user, $schoolId, 'fees.manage');
$canViewAssignedFees = school_has_permission($user, $schoolId, 'fees.view_assigned');
$canManageAnnouncements = school_has_permission($user, $schoolId, 'announcements.manage');
$canViewReportCards = school_has_permission($user, $schoolId, 'report_cards.view');
$canManageSupport = school_has_permission($user, $schoolId, 'support.manage');
$isTeacher = in_array($schoolRole, ['class_teacher', 'subject_teacher'], true);
$canManageSchoolAttendance = !$isTeacher
    && school_has_permission($user, $schoolId, 'attendance.manage');

$teacherClasses = [];
$teacherSubjects = [];
$teacherStudents = [];
$teacherStudentPreview = [];
$assessmentInbox = [];
$teacherAttendanceChart = [];
$teacherSubjectPerformanceChart = [];
$teacherGradeDistributionChart = [];
$teacherPagePeriod = null;
if ($isTeacher) {
    $teacherClassStmt = db()->prepare(
        'SELECT sc.id, sc.level, sc.section, sc.academic_year
         FROM teacher_class_assignments tca
         JOIN school_classes sc ON sc.id = tca.class_id AND sc.school_id = tca.school_id
         WHERE tca.school_id = ? AND tca.teacher_user_id = ? AND tca.academic_year = sc.academic_year
         ORDER BY sc.academic_year DESC, sc.level, sc.section'
    );
    $teacherClassStmt->execute([$schoolId, (int) $user['id']]);
    $teacherClasses = $teacherClassStmt->fetchAll();
    $teacherSubjectStmt = db()->prepare(
        'SELECT cs.class_id, cs.subject_id, sc.level, sc.section, sc.academic_year,
                s.name AS subject_name, s.code
         FROM class_subjects cs
         JOIN school_classes sc ON sc.id = cs.class_id AND sc.school_id = cs.school_id
         JOIN school_subjects s ON s.id = cs.subject_id AND s.school_id = cs.school_id
         WHERE cs.school_id = ? AND cs.teacher_user_id = ?
         ORDER BY sc.academic_year DESC, sc.level, s.name'
    );
    $teacherSubjectStmt->execute([$schoolId, (int) $user['id']]);
    $teacherSubjects = $teacherSubjectStmt->fetchAll();
    $teacherStudentsStmt = db()->prepare(
        'SELECT DISTINCT ss.id, sc.id AS class_id, sc.academic_year
         FROM school_students ss
         JOIN school_classes sc ON sc.id = ss.class_id AND sc.school_id = ss.school_id
         WHERE ss.school_id = ? AND ss.school_status = "active"
           AND (EXISTS (
                SELECT 1 FROM teacher_class_assignments tca
                WHERE tca.school_id = ss.school_id AND tca.class_id = sc.id
                  AND tca.teacher_user_id = ? AND tca.academic_year = sc.academic_year
           ) OR EXISTS (
                SELECT 1 FROM class_subjects cs
                WHERE cs.school_id = ss.school_id AND cs.class_id = sc.id AND cs.teacher_user_id = ?
           ))
         ORDER BY sc.academic_year DESC, sc.level, ss.full_name'
    );
    $teacherStudentsStmt->execute([$schoolId, (int) $user['id'], (int) $user['id']]);
    $teacherStudents = $teacherStudentsStmt->fetchAll();
    $teacherStudentPreviewStmt = db()->prepare(
        'SELECT DISTINCT ss.id, ss.full_name, ss.admission_number, ss.photo_path, ss.gender,
                sc.id AS class_id, sc.level, sc.section, sc.academic_year,
                guardian.full_name AS guardian_name, guardian.phone AS guardian_phone,
                guardian.email AS guardian_email
         FROM school_students ss
         JOIN school_classes sc ON sc.id = ss.class_id AND sc.school_id = ss.school_id
         LEFT JOIN school_guardians guardian ON guardian.id = ss.guardian_id AND guardian.school_id = ss.school_id
         WHERE ss.school_id = ? AND ss.school_status = "active"
           AND (EXISTS (
                SELECT 1 FROM teacher_class_assignments tca
                WHERE tca.school_id = ss.school_id AND tca.class_id = sc.id
                  AND tca.teacher_user_id = ? AND tca.academic_year = sc.academic_year
           ) OR EXISTS (
                SELECT 1 FROM class_subjects cs
                WHERE cs.school_id = ss.school_id AND cs.class_id = sc.id AND cs.teacher_user_id = ?
           ))
         ORDER BY sc.academic_year DESC, sc.level, ss.full_name
         LIMIT 100'
    );
    $teacherStudentPreviewStmt->execute([$schoolId, (int) $user['id'], (int) $user['id']]);
    $teacherStudentPreview = $teacherStudentPreviewStmt->fetchAll();
    $teacherPagePeriod = school_page_academic_term($schoolId);
    if ($teacherStudents !== [] && $teacherPagePeriod !== null) {
        $periodStudents = array_values(array_filter(
            $teacherStudents,
            static fn(array $student): bool => (string) $student['academic_year'] === (string) $teacherPagePeriod['academic_year']
        ));
        $studentIds = array_values(array_unique(array_map(
            static fn(array $student): int => (int) $student['id'],
            $periodStudents
        )));
        $attendancePeriods = school_attendance_date_periods($teacherPagePeriod, null, $schoolId);
        $attendanceSummaries = school_attendance_summaries($schoolId, $studentIds, $attendancePeriods);
        $classAttendanceTotals = [];
        foreach ($periodStudents as $student) {
            $termAttendance = $attendanceSummaries[(int) $student['id']]['term'] ?? null;
            if (!is_array($termAttendance) || $termAttendance['percentage'] === null) {
                continue;
            }
            $classId = (int) $student['class_id'];
            $classLabel = school_class_label($student);
            $classAttendanceTotals[$classId]['label'] = $classLabel;
            $classAttendanceTotals[$classId]['total']
                = ($classAttendanceTotals[$classId]['total'] ?? 0) + (float) $termAttendance['percentage'];
            $classAttendanceTotals[$classId]['students']
                = ($classAttendanceTotals[$classId]['students'] ?? 0) + 1;
        }
        foreach ($classAttendanceTotals as $attendanceTotal) {
            $teacherAttendanceChart[$attendanceTotal['label']]
                = $attendanceTotal['total'] / $attendanceTotal['students'];
        }

        if ($schoolRole === 'class_teacher') {
            $teacherAssignmentSql = 'EXISTS (
                SELECT 1 FROM teacher_class_assignments assignment
                WHERE assignment.school_id = assessment.school_id
                  AND assignment.class_id = assessment.class_id
                  AND assignment.academic_year = assessment.academic_year
                  AND assignment.teacher_user_id = ?
            )';
        } else {
            $teacherAssignmentSql = 'EXISTS (
                SELECT 1 FROM class_subjects assignment
                JOIN school_classes assigned_class
                  ON assigned_class.id = assignment.class_id
                 AND assigned_class.school_id = assignment.school_id
                 AND assigned_class.academic_year = assessment.academic_year
                WHERE assignment.school_id = assessment.school_id
                  AND assignment.class_id = assessment.class_id
                  AND assignment.subject_id = assessment.subject_id
                  AND assignment.teacher_user_id = ?
            )';
        }
        $performanceStmt = db()->prepare(
            'SELECT subject.name AS subject_name,
                    AVG((result.score / assessment.total_marks) * 100) AS average_percent,
                    COUNT(*) AS result_count
             FROM assessment_results result
             JOIN school_assessments assessment
               ON assessment.id = result.assessment_id AND assessment.school_id = result.school_id
             JOIN school_subjects subject
               ON subject.id = assessment.subject_id AND subject.school_id = assessment.school_id
             JOIN school_students student
               ON student.id = result.student_id AND student.school_id = result.school_id
              AND student.class_id = assessment.class_id AND student.school_status = "active"
             WHERE result.school_id = ? AND assessment.published_at IS NOT NULL
               AND assessment.total_marks > 0 AND assessment.academic_year = ?
               AND assessment.term_name = ? AND ' . $teacherAssignmentSql . '
             GROUP BY subject.id, subject.name
             ORDER BY subject.name'
        );
        $performanceStmt->execute([
            $schoolId,
            (string) $teacherPagePeriod['academic_year'],
            (string) $teacherPagePeriod['term_name'],
            (int) $user['id'],
        ]);
        foreach ($performanceStmt->fetchAll() as $performance) {
            $teacherSubjectPerformanceChart[(string) $performance['subject_name']]
                = (float) $performance['average_percent'];
        }

        $gradeStmt = db()->prepare(
            'SELECT COALESCE(NULLIF(TRIM(result.grade), ""), "Ungraded") AS grade_label,
                    COUNT(*) AS result_count
             FROM assessment_results result
             JOIN school_assessments assessment
               ON assessment.id = result.assessment_id AND assessment.school_id = result.school_id
             JOIN school_students student
               ON student.id = result.student_id AND student.school_id = result.school_id
              AND student.class_id = assessment.class_id AND student.school_status = "active"
             WHERE result.school_id = ? AND assessment.published_at IS NOT NULL
               AND assessment.total_marks > 0 AND assessment.academic_year = ?
               AND assessment.term_name = ? AND ' . $teacherAssignmentSql . '
             GROUP BY grade_label
             ORDER BY grade_label'
        );
        $gradeStmt->execute([
            $schoolId,
            (string) $teacherPagePeriod['academic_year'],
            (string) $teacherPagePeriod['term_name'],
            (int) $user['id'],
        ]);
        foreach ($gradeStmt->fetchAll() as $grade) {
            $teacherGradeDistributionChart[(string) $grade['grade_label']]
                = (int) $grade['result_count'];
        }
    }
    if ($schoolRole === 'class_teacher') {
        $assessmentInboxStmt = db()->prepare(
            'SELECT assessment.id, assessment.class_id, assessment.term_id,
                    assessment.academic_year, assessment.term_name,
                    assessment.assessment_name, assessment.workflow_status,
                    assessment.submitted_at, class.level, class.section,
                    subject.name AS subject_name, submitter.full_name AS submitter_name,
                    intended_teacher.full_name AS originally_assigned_teacher,
                    (SELECT COUNT(*) FROM assessment_results result
                     JOIN school_students result_student
                       ON result_student.id = result.student_id
                      AND result_student.school_id = result.school_id
                     WHERE result.school_id = assessment.school_id
                       AND result.assessment_id = assessment.id
                       AND result_student.class_id = assessment.class_id
                       AND result_student.school_status = "active") AS recorded_students,
                    (SELECT COUNT(*) FROM school_students student
                     WHERE student.school_id = assessment.school_id
                       AND student.class_id = assessment.class_id
                       AND student.school_status = "active") AS eligible_students
             FROM school_assessments assessment
             JOIN school_classes class ON class.id = assessment.class_id
               AND class.school_id = assessment.school_id
               AND class.academic_year = assessment.academic_year
             JOIN school_subjects subject ON subject.id = assessment.subject_id
               AND subject.school_id = assessment.school_id
             LEFT JOIN users submitter ON submitter.id = assessment.submitted_by
             LEFT JOIN users intended_teacher ON intended_teacher.id = assessment.submitted_to_user_id
             WHERE assessment.school_id = ?
               AND assessment.submitted_to_user_id = ?
               AND (assessment.submitted_by IS NULL OR assessment.submitted_by <> ?)
               AND assessment.workflow_status IN
                   ("submitted", "correction_requested", "corrected", "reviewed", "published")
               AND EXISTS (
                   SELECT 1 FROM teacher_class_assignments assignment
                   WHERE assignment.school_id = assessment.school_id
                     AND assignment.class_id = assessment.class_id
                     AND assignment.academic_year = assessment.academic_year
                     AND assignment.teacher_user_id = ?
               )
             ORDER BY (assessment.workflow_status IN ("submitted", "correction_requested", "corrected")) DESC,
                      assessment.submitted_at DESC, assessment.id DESC
             LIMIT 100'
        );
        $assessmentInboxStmt->execute([$schoolId, (int) $user['id'], (int) $user['id'], (int) $user['id']]);
        $assessmentInbox = $assessmentInboxStmt->fetchAll();
    }
}

$countForSchool = static function (string $table) use ($schoolId): int {
    $allowedTables = ['school_students', 'school_classes', 'school_staff', 'school_support_tickets', 'school_announcements'];
    if (!in_array($table, $allowedTables, true)) {
        throw new InvalidArgumentException('Unsupported school dashboard count.');
    }
    $where = $table === 'school_students' ? ' WHERE school_id = ? AND school_status = "active"' : ' WHERE school_id = ?';
    $stmt = db()->prepare("SELECT COUNT(*) FROM {$table}{$where}");
    $stmt->execute([$schoolId]);
    return (int) $stmt->fetchColumn();
};
$studentPopulation = null;
$studentPopulationByClass = [];
$adminAttendanceChart = [];
$assessmentWorkflowChart = [];
$adminPagePeriod = null;
$schoolFeeCharts = ['invoiced' => 0.0, 'collected' => 0.0, 'outstanding' => 0.0];
$schoolFeeCollectionTrend = [];
$schoolFeeOutstandingByClass = [];
if ($canManageStudents) {
    $populationStmt = db()->prepare(
        'SELECT COUNT(*) AS total_active,
                SUM(gender = "male") AS male,
                SUM(gender = "female") AS female,
                SUM(gender = "other") AS other_gender,
                SUM(gender = "prefer_not_to_say") AS prefer_not_to_say,
                SUM(gender IS NULL) AS gender_not_recorded
         FROM school_students
         WHERE school_id = ? AND school_status = "active"'
    );
    $populationStmt->execute([$schoolId]);
    $studentPopulation = $populationStmt->fetch() ?: [];

    $classPopulationStmt = db()->prepare(
        'SELECT class.level, COUNT(student.id) AS active_students
         FROM school_classes class
         LEFT JOIN school_students student
           ON student.school_id = class.school_id AND student.class_id = class.id
          AND student.school_status = "active"
         WHERE class.school_id = ? AND class.status = "active"
         GROUP BY class.level
         ORDER BY class.level'
    );
    $classPopulationStmt->execute([$schoolId]);
    foreach ($classPopulationStmt->fetchAll() as $classPopulation) {
        $studentPopulationByClass[(string) $classPopulation['level']] = (int) $classPopulation['active_students'];
    }
}
if (!$isTeacher && ($canManageSchoolAttendance || $canManageResults || $canManageFees)) {
    $adminPagePeriod = school_page_academic_term($schoolId);
    if ($adminPagePeriod !== null) {
        if ($canManageSchoolAttendance) {
            $periodStudentsStmt = db()->prepare(
                'SELECT student.id, student.class_id, class.level, class.section
                 FROM school_students student
                 JOIN school_classes class
                   ON class.id = student.class_id AND class.school_id = student.school_id
                 WHERE student.school_id = ? AND student.school_status = "active"
                   AND class.academic_year = ? AND class.status = "active"
                 ORDER BY class.level, class.section, student.id'
            );
            $periodStudentsStmt->execute([$schoolId, (string) $adminPagePeriod['academic_year']]);
            $periodStudents = $periodStudentsStmt->fetchAll();
            $periodStudentIds = array_values(array_map(
                static fn(array $student): int => (int) $student['id'],
                $periodStudents
            ));
            $attendancePeriods = school_attendance_date_periods($adminPagePeriod, null, $schoolId);
            $attendanceSummaries = school_attendance_summaries($schoolId, $periodStudentIds, $attendancePeriods);
            $attendanceByClass = [];
            foreach ($periodStudents as $student) {
                $termAttendance = $attendanceSummaries[(int) $student['id']]['term'] ?? null;
                if (!is_array($termAttendance) || $termAttendance['percentage'] === null) {
                    continue;
                }
                $classId = (int) $student['class_id'];
                $attendanceByClass[$classId]['label'] = school_class_label($student);
                $attendanceByClass[$classId]['total']
                    = ($attendanceByClass[$classId]['total'] ?? 0) + (float) $termAttendance['percentage'];
                $attendanceByClass[$classId]['students']
                    = ($attendanceByClass[$classId]['students'] ?? 0) + 1;
            }
            foreach ($attendanceByClass as $attendance) {
                $adminAttendanceChart[$attendance['label']] = $attendance['total'] / $attendance['students'];
            }
        }
        if ($canManageResults) {
            $assessmentWorkflowStmt = db()->prepare(
                'SELECT workflow_status, COUNT(*) AS assessment_count
                 FROM school_assessments
                 WHERE school_id = ? AND academic_year = ? AND term_name = ?
                 GROUP BY workflow_status
                 ORDER BY workflow_status'
            );
            $assessmentWorkflowStmt->execute([
                $schoolId,
                (string) $adminPagePeriod['academic_year'],
                (string) $adminPagePeriod['term_name'],
            ]);
            foreach ($assessmentWorkflowStmt->fetchAll() as $workflowStatus) {
                $statusLabel = ucwords(str_replace('_', ' ', (string) $workflowStatus['workflow_status']));
                $assessmentWorkflowChart[$statusLabel] = (int) $workflowStatus['assessment_count'];
            }
        }
    }
}
if ($canManageFees && $adminPagePeriod !== null) {
    $feeSummaryStmt = db()->prepare(
        'SELECT COALESCE(SUM(invoice.amount), 0) AS invoiced,
                COALESCE(SUM(invoice.balance), 0) AS outstanding
         FROM school_invoices invoice
         WHERE invoice.school_id = ? AND invoice.academic_year = ?
           AND invoice.period_name = ? AND invoice.status <> "draft"'
    );
    $feeSummaryStmt->execute([
        $schoolId,
        (string) $adminPagePeriod['academic_year'],
        (string) $adminPagePeriod['term_name'],
    ]);
    $feeSummary = $feeSummaryStmt->fetch() ?: [];
    $schoolFeeCharts['invoiced'] = (float) ($feeSummary['invoiced'] ?? 0);
    $schoolFeeCharts['outstanding'] = (float) ($feeSummary['outstanding'] ?? 0);

    $feeCollectionStmt = db()->prepare(
        'SELECT COALESCE(SUM(allocation.amount), 0)
         FROM school_payment_allocations allocation
         JOIN school_payments payment
           ON payment.id = allocation.payment_id AND payment.school_id = allocation.school_id
          AND payment.student_id = allocation.student_id AND payment.status = "verified"
         JOIN school_invoices invoice
           ON invoice.id = allocation.invoice_id AND invoice.school_id = allocation.school_id
          AND invoice.student_id = allocation.student_id
         WHERE allocation.school_id = ? AND allocation.status = "active"
           AND invoice.academic_year = ? AND invoice.period_name = ?'
    );
    $feeCollectionStmt->execute([
        $schoolId,
        (string) $adminPagePeriod['academic_year'],
        (string) $adminPagePeriod['term_name'],
    ]);
    $schoolFeeCharts['collected'] = (float) $feeCollectionStmt->fetchColumn();

    $monthlyFeesStmt = db()->prepare(
        'SELECT DATE_FORMAT(payment.received_at, "%Y-%m") AS month_label,
                COALESCE(SUM(allocation.amount), 0) AS collected
         FROM school_payment_allocations allocation
         JOIN school_payments payment
           ON payment.id = allocation.payment_id AND payment.school_id = allocation.school_id
          AND payment.student_id = allocation.student_id AND payment.status = "verified"
         JOIN school_invoices invoice
           ON invoice.id = allocation.invoice_id AND invoice.school_id = allocation.school_id
          AND invoice.student_id = allocation.student_id
         WHERE allocation.school_id = ? AND allocation.status = "active"
           AND invoice.academic_year = ? AND invoice.period_name = ?
         GROUP BY DATE_FORMAT(payment.received_at, "%Y-%m")
         ORDER BY month_label DESC LIMIT 12'
    );
    $monthlyFeesStmt->execute([
        $schoolId,
        (string) $adminPagePeriod['academic_year'],
        (string) $adminPagePeriod['term_name'],
    ]);
    $monthlyFeeRows = array_reverse($monthlyFeesStmt->fetchAll());
    foreach ($monthlyFeeRows as $monthlyFee) {
        $schoolFeeCollectionTrend[(string) $monthlyFee['month_label']] = (float) $monthlyFee['collected'];
    }

    $outstandingFeesStmt = db()->prepare(
        'SELECT invoice.class_level, COALESCE(SUM(invoice.balance), 0) AS outstanding
         FROM school_invoices invoice
         WHERE invoice.school_id = ? AND invoice.academic_year = ?
           AND invoice.period_name = ? AND invoice.status <> "draft"
         GROUP BY invoice.class_level ORDER BY invoice.class_level'
    );
    $outstandingFeesStmt->execute([
        $schoolId,
        (string) $adminPagePeriod['academic_year'],
        (string) $adminPagePeriod['term_name'],
    ]);
    foreach ($outstandingFeesStmt->fetchAll() as $feeRow) {
        $schoolFeeOutstandingByClass[(string) ($feeRow['class_level'] ?: 'No class recorded')]
            = (float) $feeRow['outstanding'];
    }
}
$activeStudentsWithoutCurrentClass = $studentPopulation === null
    ? 0
    : max(0, (int) ($studentPopulation['total_active'] ?? 0) - array_sum($studentPopulationByClass));

render_header('School dashboard');
?>
<section class="school-identity-banner">
    <p class="eyebrow">ACTIVE SCHOOL</p>
    <?= school_identity_markup($school) ?>
    <h1>School dashboard</h1>
    <p>Status: <?= e((string) $school['status']) ?> · Subscription: <?= e((string) $school['subscription_status']) ?></p>
</section>

<?php render_dashboard_profile_card($user); ?>
<?php render_school_calendar_preview($schoolId, $user); ?>

<?php if ($schoolRole === 'school_proprietor'): ?><div class="proprietor-dashboard"><?php endif; ?>
<?php if (is_array($ownStaffAttendance)): ?>
<?php
$ownTodayRecord = $ownStaffAttendance['today_record'];
$ownTodayStatus = $ownTodayRecord === null
    ? ($ownStaffAttendance['today_is_scheduled'] ? 'Not recorded' : 'Not scheduled')
    : ucwords(str_replace(
        '_',
        ' ',
        (string) ($ownTodayRecord['punctuality_status'] ?: $ownTodayRecord['status'])
    ));
$ownAttendanceTime = static function (?string $value) use ($ownStaffAttendance): string {
    return $value === null
        ? '—'
        : (new DateTimeImmutable($value, $ownStaffAttendance['timezone']))->format('g:i A');
};
?>
<section class="dashboard-grid spaced-top" aria-label="My staff attendance">
    <article class="panel stat-card">
        <h2>My attendance today</h2>
        <strong><?= e($ownTodayStatus) ?></strong>
        <span class="muted">Check-in <?= e($ownAttendanceTime($ownTodayRecord['arrival_at'] ?? null)) ?>
            · Check-out <?= e($ownAttendanceTime($ownTodayRecord['departure_at'] ?? null)) ?></span>
    </article>
    <article class="panel">
        <h2>Attendance history</h2>
        <p class="muted">View your saved daily attendance records.</p>
        <p><a class="button" href="staff_self_service.php#my-attendance">View my attendance history</a></p>
    </article>
</section>
<?php endif; ?>

<?php if ($isTeacher): ?>
<section class="panel spaced-top">
    <p class="eyebrow">TEACHER WORKSPACE</p>
    <h2>Teacher dashboard</h2>
    <p class="muted">Your work is shown from your active class and subject assignments. Other school classes and subjects are not included.</p>
    <?php if ($teacherClasses === [] && $teacherSubjects === []): ?>
        <p class="notice notice-error">No active teaching assignments are linked to your account in this school. Your class and subject lists are empty because no class-teacher or subject-teacher assignment was found; this is not a student-loading error. Ask the Headmaster or Proprietor to assign your account to the correct class and subjects.<?php if ($canManageClasses): ?> <a href="classes.php">Review class assignments</a>.<?php endif; ?></p>
    <?php elseif ($teacherStudents === []): ?>
        <p class="notice">Your assignments were found, but there are no active student enrolments in those assigned class groups for their academic years. Ask an authorized administrator to verify the class enrolments.</p>
    <?php endif; ?>
</section>
<div class="dashboard-group-heading spaced-top">
    <p class="eyebrow">TEACHING PROGRESS</p>
    <h2>Attendance and results</h2>
</div>
<section class="dashboard-grid teacher-dashboard-grid teacher-analytics-grid spaced-top" aria-label="Assigned teaching analytics">
    <article class="panel">
        <h2>Attendance by assigned class</h2>
        <?php if ($teacherPagePeriod !== null): ?>
            <p class="muted">Complete attendance for assigned students · <?= e($teacherPagePeriod['term_name'] . ' · ' . school_academic_year_label((string) $teacherPagePeriod['academic_year'])) ?></p>
        <?php else: ?>
            <p class="muted">Attendance charts require a configured academic period.</p>
        <?php endif; ?>
        <?php render_dashboard_bar_chart('Completed attendance rate · assigned classes', $teacherAttendanceChart, '%'); ?>
    </article>
    <article class="panel">
        <h2>Published subject performance</h2>
        <?php if ($teacherPagePeriod !== null): ?>
            <p class="muted">Published results for your assigned classes · <?= e($teacherPagePeriod['term_name'] . ' · ' . school_academic_year_label((string) $teacherPagePeriod['academic_year'])) ?></p>
        <?php else: ?>
            <p class="muted">Performance charts require a configured academic period.</p>
        <?php endif; ?>
        <?php render_dashboard_bar_chart('Average published score by subject', $teacherSubjectPerformanceChart, '%'); ?>
        <details>
            <summary>View grade distribution</summary>
        <?php render_dashboard_bar_chart('Published grade distribution', $teacherGradeDistributionChart, 'results'); ?>
        </details>
    </article>
</section>
<?php if ($schoolRole === 'class_teacher'): ?>
<section class="panel spaced-top">
    <p class="eyebrow">CLASS-TEACHER REVIEW</p>
    <h2>Examination results received</h2>
    <p class="muted">Submissions are limited to your assigned classes for the recorded academic year. Returned and previously reviewed records remain available for history.</p>
    <?php if ($assessmentInbox === []): ?>
        <p class="muted">No subject-teacher examination submissions are available for your assigned classes.</p>
    <?php else: ?>
        <div class="table-scroll"><table>
            <thead><tr><th>Academic year / term</th><th>Examination</th><th>Class</th><th>Subject</th><th>Submitted by</th><th>Originally routed to</th><th>Submitted at</th><th>Records</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
                <?php foreach ($assessmentInbox as $submission): ?>
                    <tr>
                        <td><?= e(school_academic_year_label((string) $submission['academic_year'])) ?> · <?= e((string) $submission['term_name']) ?></td>
                        <td><?= e((string) $submission['assessment_name']) ?></td>
                        <td><?= e(school_class_label(['level' => $submission['level'], 'section' => $submission['section']])) ?></td>
                        <td><?= e((string) $submission['subject_name']) ?></td>
                        <td><?= e((string) ($submission['submitter_name'] ?? 'Former staff account')) ?></td>
                        <td><?= e((string) ($submission['originally_assigned_teacher'] ?? 'Assignment no longer active')) ?></td>
                        <td><?= e((string) ($submission['submitted_at'] ?? 'Not recorded')) ?> UTC</td>
                        <td><?= (int) $submission['recorded_students'] ?> / <?= (int) $submission['eligible_students'] ?></td>
                        <td><?= e(ucfirst(str_replace('_', ' ', (string) $submission['workflow_status']))) ?></td>
                        <td>
                            <a class="button button-small" href="assessments.php?assessment_id=<?= (int) $submission['id'] ?>">Open submission</a>
                            <a class="button button-small" href="assessments.php?register=1&amp;register_year=<?= rawurlencode((string) $submission['academic_year']) ?>&amp;register_term_id=<?= (int) $submission['term_id'] ?>&amp;register_class_id=<?= (int) $submission['class_id'] ?>&amp;register_assessment_name=<?= rawurlencode((string) $submission['assessment_name']) ?>">Open class register</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php endif; ?>
<div class="dashboard-group-heading spaced-top">
    <p class="eyebrow">YOUR ASSIGNMENTS</p>
    <h2>Classes, subjects, and students</h2>
</div>
<section class="dashboard-grid teacher-dashboard-grid spaced-top">
    <article class="panel">
        <?php dashboard_section_heading('classroom', 'My Class'); ?>
        <strong class="teacher-dashboard-value"><?= count($teacherClasses) ?></strong>
        <p class="muted">Assigned classes</p>
        <?php if ($teacherClasses === []): ?><p class="muted">No class-teacher assignment is active for your account.</p><?php else: ?>
            <details>
                <summary>View class assignments</summary>
            <div class="table-scroll"><table><thead><tr><th>Assigned class</th><th>Academic year</th><th>Students</th></tr></thead><tbody>
            <?php foreach ($teacherClasses as $class): $classStudentCount = count(array_filter($teacherStudents, static fn (array $student): bool => (int) $student['class_id'] === (int) $class['id'])); ?>
                <tr><td><?= e(school_class_label($class)) ?></td><td><?= e(school_academic_year_label((string) $class['academic_year'])) ?></td><td><?= $classStudentCount ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
            </details>
        <?php endif; ?>
    </article>
    <article class="panel">
        <?php dashboard_section_heading('book', 'My Teaching Subjects'); ?>
        <strong class="teacher-dashboard-value"><?= count($teacherSubjects) ?></strong>
        <p class="muted">Subject assignments</p>
        <?php if ($teacherSubjects === []): ?><p class="muted">No class and subject teaching assignments are active for your account.</p><?php else: ?>
            <details>
                <summary>View subject assignments</summary>
            <div class="table-scroll"><table><thead><tr><th>Subject</th><th>Class</th><th>Academic year</th></tr></thead><tbody>
            <?php foreach ($teacherSubjects as $assignment): ?><tr>
                <td><?= e((string) $assignment['subject_name']) ?><?= $assignment['code'] ? ' (' . e((string) $assignment['code']) . ')' : '' ?></td>
                <td><?= e(school_class_label($assignment)) ?></td>
                <td><?= e(school_academic_year_label((string) $assignment['academic_year'])) ?></td>
            </tr><?php endforeach; ?>
            </tbody></table></div>
            </details>
        <?php endif; ?>
    </article>
    <article class="panel">
        <?php dashboard_section_heading('students', 'My Students'); ?>
        <strong class="teacher-dashboard-value"><?= count($teacherStudents) ?></strong>
        <p class="muted">Students in your assigned groups</p>
        <?php if ($teacherStudentPreview !== []): ?>
            <details>
                <summary>View student preview</summary>
            <div class="table-scroll"><table><thead><tr><th>Student</th><th>Class / year</th></tr></thead><tbody data-show-more>
            <?php foreach ($teacherStudentPreview as $student): ?><tr><td><?php if ($student['photo_path']): ?><img class="student-photo" src="student_photo.php?id=<?= (int) $student['id'] ?>" alt="Photo of <?= e((string) $student['full_name']) ?>"><?php else: ?><span class="student-photo-placeholder" aria-label="No photo">—</span><?php endif; ?><strong><?= e((string) $student['full_name']) ?></strong><br><small>ID: <?= e((string) ($student['admission_number'] ?: '—')) ?> · <?= e(ucfirst((string) ($student['gender'] ?: 'not recorded'))) ?></small><br><small>Guardian: <?= e((string) ($student['guardian_name'] ?: '—')) ?><?= $student['guardian_phone'] ? ' · ' . e((string) $student['guardian_phone']) : '' ?><?= $student['guardian_email'] ? ' · ' . e((string) $student['guardian_email']) : '' ?></small></td><td><?= e(school_class_label($student)) ?> · <?= e(school_academic_year_label((string) $student['academic_year'])) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
            <?php if (count($teacherStudents) > count($teacherStudentPreview)): ?><p class="muted">Showing the first <?= count($teacherStudentPreview) ?> of <?= count($teacherStudents) ?> assigned students.</p><?php endif; ?>
            </details>
        <?php endif; ?>
        <?php if ($canManageStudents || school_has_permission($user, $schoolId, 'students.view')): ?><p><a class="button" href="students.php">Open assigned student records</a></p><?php endif; ?>
    </article>
    <?php if ($canManageAttendance && $teacherClasses !== []): ?><article class="panel"><?php dashboard_section_heading('attendance', 'Attendance'); ?><p class="muted">Record and review attendance only for your assigned class groups.</p><p><a href="attendance.php?action=register&amp;month=<?= e(date('Y-m')) ?>">Open assigned-class attendance</a></p></article><?php endif; ?>
    <?php if ($canManageResults && ($teacherClasses !== [] || $teacherSubjects !== [])): ?><article class="panel"><?php dashboard_section_heading('results', 'Examination Records'); ?><p class="muted">Record and review marks for each subject assigned to your classes.</p><p><a href="assessments.php">Open examination records</a></p></article><?php endif; ?>
    <article class="panel">
        <?php dashboard_section_heading('finance', 'My payroll and attendance'); ?>
        <p class="muted">Review your own salary, payroll, and attendance history.</p>
        <p><a class="button" href="staff_self_service.php">Open my staff records</a></p>
    </article>
    <article class="panel">
        <?php dashboard_section_heading('book', 'English Learning'); ?>
        <p class="muted">Open grammar lessons, examples, videos, and practice activities from the English-learning website.</p>
        <p><a class="button button-primary" href="https://syntextgrammar.netlify.app/" target="_blank" rel="noopener noreferrer">Open English Learning ↗</a></p>
        <small class="muted">Learning progress is managed by the external website and is not recorded in this school portal.</small>
    </article>
</section>
<?php else: ?>
<?php if (($canManageSchoolAttendance || $canManageResults) && $adminPagePeriod !== null): ?>
<section class="dashboard-grid spaced-top" aria-label="School academic analytics">
    <div class="dashboard-group-heading">
        <p class="eyebrow">ACADEMIC MANAGEMENT</p>
        <h2>School learning and attendance</h2>
    </div>
    <details class="dashboard-secondary-details">
        <summary>View attendance and examination charts</summary>
        <div class="dashboard-grid">
    <?php if ($canManageSchoolAttendance): ?>
    <article class="panel">
        <h2>Attendance by class</h2>
        <?php if ($adminAttendanceChart !== []): ?>
            <p class="muted">Average attendance among active students with all scheduled attendance recorded for <?= e($adminPagePeriod['term_name'] . ' · ' . school_academic_year_label((string) $adminPagePeriod['academic_year'])) ?>. Incomplete individual records are excluded.</p>
        <?php else: ?>
            <p class="muted">No complete attendance records are available for this academic period.</p>
        <?php endif; ?>
        <?php render_dashboard_bar_chart('Completed student attendance rate by class', $adminAttendanceChart, '%'); ?>
    </article>
    <?php endif; ?>
    <?php if ($canManageResults): ?>
    <article class="panel">
        <h2>Examination completion</h2>
        <p class="muted">Assessment records by workflow status for <?= e($adminPagePeriod['term_name'] . ' · ' . school_academic_year_label((string) $adminPagePeriod['academic_year'])) ?>.</p>
        <?php render_dashboard_bar_chart('Assessments by workflow status', $assessmentWorkflowChart, 'assessments'); ?>
    </article>
    <?php endif; ?>
        </div>
    </details>
</section>
<?php elseif (!$isTeacher && ($canManageSchoolAttendance || $canManageResults)): ?>
<section class="panel spaced-top">
    <h2>School academic analytics</h2>
    <p class="muted">Select an academic year and term above to view attendance and examination progress.</p>
</section>
<?php endif; ?>
<?php if ($canManageFees && $adminPagePeriod !== null): ?>
<section class="dashboard-grid spaced-top" aria-label="School fee analytics">
    <div class="dashboard-group-heading">
        <p class="eyebrow">FINANCIAL MANAGEMENT</p>
        <h2>Fees and collections</h2>
    </div>
    <details class="dashboard-secondary-details">
        <summary>View fee trends and class balances</summary>
        <div class="dashboard-grid">
    <article class="panel">
        <h2>Fees for selected academic period</h2>
        <p class="muted"><?= e($adminPagePeriod['term_name'] . ' · ' . school_academic_year_label((string) $adminPagePeriod['academic_year'])) ?>. Invoices exclude drafts; collections include verified active payment allocations only.</p>
        <?php render_dashboard_bar_chart('Invoiced, collected and outstanding fees', [
            'Invoiced' => $schoolFeeCharts['invoiced'],
            'Verified collections' => $schoolFeeCharts['collected'],
            'Outstanding' => $schoolFeeCharts['outstanding'],
        ], 'GHS'); ?>
        <?php if ($schoolFeeCollectionTrend !== []): ?>
            <?php render_dashboard_line_chart('Verified fee collections by month · selected period', $schoolFeeCollectionTrend, 'GHS'); ?>
        <?php else: ?>
            <p class="muted">No verified fee collections are recorded for this academic period.</p>
        <?php endif; ?>
    </article>
    <article class="panel">
        <h2>Outstanding fees by class</h2>
        <?php render_dashboard_bar_chart('Outstanding invoice balances by class · selected period', $schoolFeeOutstandingByClass, 'GHS'); ?>
        <p><a href="fee_management.php">Open fee management</a></p>
    </article>
        </div>
    </details>
</section>
<?php elseif (!$isTeacher && $canManageFees && $adminPagePeriod === null): ?>
<section class="panel spaced-top">
    <h2>School fee analytics</h2>
    <p class="muted">Select an academic year and term above to view period fee statistics.</p>
    <p><a href="fee_management.php">Open fee management</a></p>
</section>
<?php endif; ?>
<?php if ($canManageStudents): ?>
<section class="dashboard-group spaced-top" aria-labelledby="school-students-heading">
    <div class="dashboard-group-heading"><p class="eyebrow">STUDENT MANAGEMENT</p><h2 id="school-students-heading">Student population</h2></div>
    <div class="dashboard-grid">
    <article class="panel stat-card">
        <?php dashboard_section_heading('students', 'Students'); ?>
        <strong><?= (int) ($studentPopulation['total_active'] ?? 0) ?></strong>
        <details>
            <summary>Demographics</summary>
            <p>Male <?= (int) ($studentPopulation['male'] ?? 0) ?> · Female <?= (int) ($studentPopulation['female'] ?? 0) ?></p>
            <p class="muted">Other <?= (int) ($studentPopulation['other_gender'] ?? 0) ?> · Prefer not to say <?= (int) ($studentPopulation['prefer_not_to_say'] ?? 0) ?> · Gender not recorded <?= (int) ($studentPopulation['gender_not_recorded'] ?? 0) ?></p>
        </details>
        <details>
            <summary>More: class breakdown</summary>
            <ul>
                <?php foreach ($schoolClassLevels as $classLevel): ?>
                    <li><?= e($classLevel) ?>: <?= (int) ($studentPopulationByClass[$classLevel] ?? 0) ?></li>
                <?php endforeach; ?>
                <?php foreach ($studentPopulationByClass as $classLevel => $classCount): ?>
                    <?php if (!in_array($classLevel, $schoolClassLevels, true)): ?><li><?= e($classLevel) ?>: <?= $classCount ?></li><?php endif; ?>
                <?php endforeach; ?>
                <?php if ($activeStudentsWithoutCurrentClass > 0): ?><li>No active class assignment: <?= $activeStudentsWithoutCurrentClass ?></li><?php endif; ?>
            </ul>
        </details>
        <?php
        $classChartData = $studentPopulationByClass;
        if ($activeStudentsWithoutCurrentClass > 0) {
            $classChartData['No class assigned'] = $activeStudentsWithoutCurrentClass;
        }
        ?>
        <details class="dashboard-chart-details">
            <summary>View class enrollment chart</summary>
            <?php render_dashboard_bar_chart('Active students by class', $classChartData, 'students'); ?>
        </details>
        <p><a href="students.php">Manage students</a></p>
    </article>
    </div>
</section>
<?php endif; ?>
<?php if ($canManageClasses || $canManageAttendance || $canManageStaffAttendance || $canManageResults || $canViewReportCards): ?>
<section class="dashboard-group spaced-top" aria-labelledby="school-academics-heading">
    <div class="dashboard-group-heading"><p class="eyebrow">ACADEMIC MANAGEMENT</p><h2 id="school-academics-heading">Classes and learning</h2></div>
    <div class="dashboard-grid">
    <?php if ($canManageClasses): ?><article class="panel stat-card">
        <?php dashboard_section_heading('classroom', 'Classes'); ?>
        <strong><?= $countForSchool('school_classes') ?></strong>
        <p><a href="classes.php">Manage classes</a></p>
    </article><?php endif; ?>
    <?php if ($canManageAttendance || $canManageStaffAttendance): ?><article class="panel">
        <?php dashboard_section_heading('attendance', 'Attendance'); ?>
        <p><?php if ($canManageAttendance): ?><a href="attendance.php?action=register&amp;month=<?= e(date('Y-m')) ?>">Student register</a><?php endif; ?><?php if ($canManageAttendance && $canManageStaffAttendance): ?> · <?php endif; ?><?php if ($canManageStaffAttendance): ?><a href="staff_attendance.php">Staff attendance</a><?php endif; ?></p>
    </article><?php endif; ?>
    <?php if ($canManageResults): ?><article class="panel">
        <?php dashboard_section_heading('results', 'Examination Records'); ?>
        <p><a href="assessments.php">Open examinations</a></p>
    </article><?php endif; ?>
    <?php if ($canViewReportCards): ?><article class="panel">
        <?php dashboard_section_heading('receipt', 'Report cards'); ?>
        <p><a href="report_cards.php">Open report cards</a></p>
    </article><?php endif; ?>
</div>
</section>
<?php endif; ?>
<?php if (school_has_permission($user, $schoolId, 'students.view_own')): ?>
<section class="dashboard-group spaced-top" aria-labelledby="school-family-heading">
    <div class="dashboard-group-heading"><p class="eyebrow">FAMILY ACCESS</p><h2 id="school-family-heading">Parent and guardian portal</h2></div>
    <div class="dashboard-grid">
    <article class="panel">
        <?php dashboard_section_heading('parent', 'Parent portal'); ?>
        <p class="muted">Review information for your linked children.</p>
        <p><a href="parent_portal.php">Open parent portal</a></p>
    </article>
    </div>
</section>
<?php endif; ?>
<?php if ($canViewAssignedFees && !$canManageFees): ?>
    <section class="dashboard-group spaced-top" aria-labelledby="school-finance-heading">
        <div class="dashboard-group-heading"><p class="eyebrow">FINANCIAL MANAGEMENT</p><h2 id="school-finance-heading">School fees</h2></div>
        <div class="dashboard-grid">
        <article class="panel">
            <?php dashboard_section_heading('finance', 'Fees and balances'); ?>
            <p class="muted"><?= $canManageFees ? 'Fee structures, invoices, and payment records.' : 'Read-only fee status for your assigned classes.' ?></p>
            <p><a href="<?= $canManageFees ? 'fee_management.php' : 'fees.php' ?>"><?= $canManageFees ? 'Open fee management' : 'View assigned-class fee status' ?></a></p>
        </article>
        </div>
    </section>
<?php endif; ?>
<?php if ($hasOwnStaffWorkspace): ?>
    <section class="dashboard-group spaced-top" aria-labelledby="my-staff-workspace-heading">
        <div class="dashboard-group-heading">
            <p class="eyebrow">MY STAFF WORKSPACE</p>
            <h2 id="my-staff-workspace-heading">My staff services</h2>
        </div>
        <div class="dashboard-grid">
            <article class="panel">
                <?php dashboard_section_heading('finance', 'My payroll and attendance'); ?>
                <p class="muted"><?= $schoolRole === 'school_proprietor' ? 'Review your payroll and attendance.' : 'Review your personal salary, payroll, and attendance history.' ?></p>
                <p><a class="button button-primary" href="staff_self_service.php">Open my staff records</a></p>
            </article>
            <article class="panel">
                <?php dashboard_section_heading('staff', 'My staff concerns and records'); ?>
                <?php if (in_array($schoolRole, ['school_proprietor', 'school_headmaster'], true)): ?>
                    <p class="muted"><?= $schoolRole === 'school_proprietor' ? 'Manage staff concerns, documents, and promotion history.' : 'Review employee concerns and manage school staff documents and promotion history.' ?></p>
                    <p><a class="button" href="staff_records.php">Manage staff concerns and records</a></p>
                <?php else: ?>
                    <p class="muted">Submit a confidential concern and view your own staff documents and promotion history.</p>
                    <p><a class="button" href="staff_records.php">View my staff concerns and records</a></p>
                <?php endif; ?>
            </article>
        </div>
    </section>
<?php endif; ?>
    <?php if ($canManageStaff || ($canViewStaff && !$canManageStaff)): ?>
    <section class="dashboard-group spaced-top" aria-labelledby="school-staff-heading">
        <div class="dashboard-group-heading"><p class="eyebrow">STAFF MANAGEMENT</p><h2 id="school-staff-heading">School team</h2></div>
        <div class="dashboard-grid">
        <?php if ($canManageStaff): ?><article class="panel stat-card">
            <?php dashboard_section_heading('staff', 'Staff'); ?>
            <strong><?= $countForSchool('school_staff') ?></strong>
            <p><a href="staff.php">Manage staff</a></p>
        </article><?php else: ?><article class="panel">
            <?php dashboard_section_heading('profile', 'School profiles'); ?>
            <p class="muted">View staff profiles and school roles.</p>
            <p><a href="profiles.php">Open school profiles</a></p>
        </article><?php endif; ?>
        </div>
    </section>
    <?php endif; ?>
    <?php if ($canManageSupport || $canManageAnnouncements || in_array($schoolRole, ['school_proprietor', 'school_headmaster'], true)): ?>
    <section class="dashboard-group spaced-top" aria-labelledby="school-admin-heading">
        <div class="dashboard-group-heading"><p class="eyebrow">SCHOOL ADMINISTRATION</p><h2 id="school-admin-heading">Operations and communication</h2></div>
        <div class="dashboard-grid">
        <?php if (in_array($schoolRole, ['school_proprietor', 'school_headmaster'], true)): ?><article class="panel">
            <?php dashboard_section_heading('settings', 'School setup'); ?>
            <p><a href="school_setup_checklist.php">Open setup checklist</a></p>
        </article><?php endif; ?>
        <?php if ($canManageSupport): ?><article class="panel stat-card">
            <?php dashboard_section_heading('support', 'Support'); ?>
            <strong><?= $countForSchool('school_support_tickets') ?></strong>
            <p><a href="school_support.php">View support</a></p>
        </article><?php endif; ?>
        <?php if ($canManageAnnouncements): ?><article class="panel stat-card">
            <?php dashboard_section_heading('announcement', 'Announcements'); ?>
            <strong><?= $countForSchool('school_announcements') ?></strong>
            <p><a href="announcements.php">Manage announcements</a></p>
        </article><?php endif; ?>
        </div>
    </section>
    <?php endif; ?>
    <?php endif; ?>
    <?php if ($schoolRole === 'school_proprietor'): ?></div><?php endif; ?>
<?php render_footer(); ?>
