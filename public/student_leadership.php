<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = require_school_context();
$school = active_school_context();
if (!$school) {
    flash('error', 'No active school was found for your account.');
    redirect('schools.php');
}

$schoolId = (int) $school['id'];
$actorId = (int) $user['id'];
$role = school_user_role_for_school($actorId, $schoolId);
$supportContext = active_it_support_context($user);
if ($supportContext !== null || !in_array($role, ['school_proprietor', 'school_headmaster'], true)) {
    http_response_code(403);
    render_header('Student leadership permission denied');
    echo '<section class="panel"><h1>Student leadership permission denied</h1><p>Only the school&apos;s Headmaster or Proprietor can manage student leadership assignments.</p><a class="button" href="school_dashboard.php">Return to school dashboard</a></section>';
    render_footer();
    exit;
}

$redirectToRoster = static function (): never {
    redirect('student_leadership.php');
};
$validateDate = static function (string $value, string $field): DateTimeImmutable {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false || $date->format('Y-m-d') !== $value
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        throw new RuntimeException('Enter a valid ' . $field . ' date.');
    }
    return $date;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if (!in_array($action, ['assign_position', 'end_position'], true)) {
        flash('error', 'Choose a valid student leadership action.');
        $redirectToRoster();
    }

    $assignmentId = filter_var($_POST['assignment_id'] ?? null, FILTER_VALIDATE_INT);
    try {
        db()->beginTransaction();
        if ($action === 'assign_position') {
            $studentId = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);
            $academicYear = trim((string) ($_POST['academic_year'] ?? ''));
            $positionTitle = trim((string) ($_POST['position_title'] ?? ''));
            $startsOn = trim((string) ($_POST['starts_on'] ?? ''));
            $endsOn = trim((string) ($_POST['ends_on'] ?? ''));
            preg_match_all('/./us', $positionTitle, $positionCharacters);
            $positionLength = count($positionCharacters[0]);
            if ($studentId === false || $studentId < 1 || $academicYear === ''
                || $positionLength < 2 || $positionLength > 100
                || preg_match('/[\x00-\x1F\x7F]/u', $positionTitle) === 1) {
                throw new RuntimeException('Choose an active student and academic year, and enter a position title between 2 and 100 characters.');
            }
            $startDate = $validateDate($startsOn, 'appointment');
            $endDate = $endsOn === '' ? null : $validateDate($endsOn, 'end');
            if ($endDate !== null && $endDate < $startDate) {
                throw new RuntimeException('The appointment end date cannot be before its start date.');
            }

            $yearStmt = db()->prepare(
                'SELECT academic_year, starts_on, ends_on
                 FROM school_academic_years
                 WHERE school_id = ? AND academic_year = ? AND status = "open"
                 LIMIT 1 FOR UPDATE'
            );
            $yearStmt->execute([$schoolId, $academicYear]);
            $year = $yearStmt->fetch();
            if (!$year) {
                throw new RuntimeException('Choose an open academic year in this school.');
            }
            if (($year['starts_on'] !== null && $startsOn < (string) $year['starts_on'])
                || ($year['ends_on'] !== null && $startsOn > (string) $year['ends_on'])
                || ($endDate !== null && $year['ends_on'] !== null && $endsOn > (string) $year['ends_on'])) {
                throw new RuntimeException('The appointment dates must fall within the selected academic year.');
            }

            $studentStmt = db()->prepare(
                'SELECT student.id, student.class_id, class.academic_year
                 FROM school_students student
                 JOIN school_classes class
                   ON class.id = student.class_id AND class.school_id = student.school_id
                 WHERE student.id = ? AND student.school_id = ?
                   AND student.school_status = "active"
                   AND class.academic_year = ? AND class.status = "active"
                 LIMIT 1 FOR UPDATE'
            );
            $studentStmt->execute([(int) $studentId, $schoolId, $academicYear]);
            $student = $studentStmt->fetch();
            if (!$student) {
                throw new RuntimeException('Select an active student with an active class assignment in the selected academic year.');
            }

            $duplicateStmt = db()->prepare(
                'SELECT id FROM school_student_leadership_assignments
                 WHERE school_id = ? AND student_id = ? AND academic_year = ?
                   AND position_title = ? AND status = "active"
                 LIMIT 1 FOR UPDATE'
            );
            $duplicateStmt->execute([$schoolId, (int) $studentId, $academicYear, $positionTitle]);
            if ($duplicateStmt->fetchColumn()) {
                throw new RuntimeException('That student already holds this position for the selected academic year.');
            }

            $insertStmt = db()->prepare(
                'INSERT INTO school_student_leadership_assignments
                    (school_id, student_id, class_id, academic_year, position_title,
                     starts_on, ends_on, assigned_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insertStmt->execute([
                $schoolId,
                (int) $studentId,
                (int) $student['class_id'],
                $academicYear,
                $positionTitle,
                $startsOn,
                $endsOn === '' ? null : $endsOn,
                $actorId,
            ]);
            $newAssignmentId = (int) db()->lastInsertId();
            audit($actorId, 'school_student_leadership_assigned',
                'school_id=' . $schoolId . ';assignment_id=' . $newAssignmentId . ';student_id=' . (int) $studentId . ';academic_year=' . $academicYear . ';position=' . $positionTitle);
            db()->commit();
            flash('success', 'Student leadership assignment recorded. It does not grant account access or system permissions.');
            $redirectToRoster();
        }

        if ($assignmentId === false || $assignmentId < 1) {
            throw new RuntimeException('Choose a valid student leadership assignment.');
        }
        $assignmentStmt = db()->prepare(
            'SELECT id, student_id, position_title
             FROM school_student_leadership_assignments
             WHERE id = ? AND school_id = ? AND status = "active"
             LIMIT 1 FOR UPDATE'
        );
        $assignmentStmt->execute([(int) $assignmentId, $schoolId]);
        $assignment = $assignmentStmt->fetch();
        if (!$assignment) {
            throw new RuntimeException('That active student leadership assignment was not found in this school.');
        }
        $endReason = trim((string) ($_POST['end_reason'] ?? ''));
        preg_match_all('/./us', $endReason, $reasonCharacters);
        if ($endReason === '' || count($reasonCharacters[0]) > 500) {
            throw new RuntimeException('Enter a reason for ending this appointment (maximum 500 characters).');
        }
        db()->prepare(
            'UPDATE school_student_leadership_assignments
             SET status = "ended",
                 ends_on = CASE WHEN ends_on IS NULL OR ends_on > UTC_DATE()
                                THEN UTC_DATE() ELSE ends_on END,
                 ended_by = ?, end_reason = ?
             WHERE id = ? AND school_id = ? AND status = "active"'
        )->execute([$actorId, $endReason, (int) $assignment['id'], $schoolId]);
        audit($actorId, 'school_student_leadership_ended',
            'school_id=' . $schoolId . ';assignment_id=' . (int) $assignment['id'] . ';student_id=' . (int) $assignment['student_id'] . ';position=' . (string) $assignment['position_title']);
        db()->commit();
        flash('success', 'Student leadership assignment ended. Its history has been retained.');
        $redirectToRoster();
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        if ($exception instanceof PDOException) {
            throw $exception;
        }
        if ($exception instanceof RuntimeException) {
            flash('error', $exception->getMessage());
            $redirectToRoster();
        }
        throw $exception;
    }
}

$academicYears = school_academic_years_for_school($schoolId, true);
$studentStmt = db()->prepare(
    'SELECT student.id, student.full_name, student.admission_number,
            class.level, class.section, class.academic_year
     FROM school_students student
     JOIN school_classes class
       ON class.id = student.class_id AND class.school_id = student.school_id
     WHERE student.school_id = ? AND student.school_status = "active"
       AND class.status = "active"
     ORDER BY class.academic_year DESC, class.level, class.section, student.full_name'
);
$studentStmt->execute([$schoolId]);
$students = $studentStmt->fetchAll();

$selectedYear = trim((string) ($_GET['academic_year'] ?? ''));
$validYears = array_map(static fn(array $year): string => (string) $year['academic_year'], $academicYears);
if (!in_array($selectedYear, $validYears, true)) {
    $activeYear = school_active_academic_year($schoolId);
    $selectedYear = $activeYear === null
        ? (string) ($validYears[0] ?? '')
        : (string) $activeYear['academic_year'];
}

$assignmentsStmt = db()->prepare(
    'SELECT assignment.id, assignment.student_id, assignment.class_id,
            assignment.academic_year, assignment.position_title,
            assignment.starts_on, assignment.ends_on, assignment.status,
            assignment.end_reason, assignment.created_at,
            student.full_name, student.admission_number, class.level, class.section,
            assigner.full_name AS assigner_name, ender.full_name AS ender_name
     FROM school_student_leadership_assignments assignment
     JOIN school_students student
       ON student.id = assignment.student_id AND student.school_id = assignment.school_id
     LEFT JOIN school_classes class
       ON class.id = assignment.class_id AND class.school_id = assignment.school_id
     LEFT JOIN users assigner ON assigner.id = assignment.assigned_by
     LEFT JOIN users ender ON ender.id = assignment.ended_by
     WHERE assignment.school_id = ? AND assignment.academic_year = ?
     ORDER BY (assignment.status = "active") DESC, assignment.position_title,
              class.level, class.section, student.full_name, assignment.id'
);
$assignmentsStmt->execute([$schoolId, $selectedYear]);
$assignments = $assignmentsStmt->fetchAll();
$positionCounts = [];
foreach ($assignments as $assignment) {
    if ($assignment['status'] === 'active'
        && (string) $assignment['starts_on'] <= gmdate('Y-m-d')
        && ($assignment['ends_on'] === null || (string) $assignment['ends_on'] >= gmdate('Y-m-d'))) {
        $positionCounts[(string) $assignment['position_title']]
            = ($positionCounts[(string) $assignment['position_title']] ?? 0) + 1;
    }
}

render_header('Student leadership');
?>
<section class="panel">
    <p class="eyebrow">STUDENT LEADERSHIP</p>
    <h1>Student leadership roster</h1>
    <p class="muted">Record and retain school prefect and student leadership appointments for the selected academic year. Appointments are descriptive only; they do not create student accounts, roles, permissions, or access to student records.</p>
</section>

<?php if ($academicYears === []): ?>
    <section class="panel" style="margin-top:1.5rem;">
        <p class="muted">Create an open academic year in Academic Years &amp; Terms before making student leadership appointments.</p>
        <a class="button button-primary" href="term_setup.php">Open Academic Years &amp; Terms</a>
    </section>
<?php else: ?>
    <section class="panel" style="margin-top:1.5rem;">
        <form method="get" class="form-inline">
            <label for="leadership-year">Academic year</label>
            <select id="leadership-year" name="academic_year" required>
                <?php foreach ($academicYears as $academicYear): ?>
                    <option value="<?= e((string) $academicYear['academic_year']) ?>" <?= $selectedYear === (string) $academicYear['academic_year'] ? 'selected' : '' ?>>
                        <?= e(school_academic_year_label((string) $academicYear['academic_year'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="button button-primary" type="submit">Show roster</button>
        </form>
    </section>

    <section class="panel" style="margin-top:1.5rem;">
        <h2>Assign a student leadership position</h2>
        <?php $eligibleStudents = array_values(array_filter(
            $students,
            static fn(array $student): bool => (string) $student['academic_year'] === $selectedYear
        )); ?>
        <?php if ($eligibleStudents === []): ?>
            <p class="muted">No active students are assigned to a class in <?= e($selectedYear) ?>.</p>
        <?php else: ?>
            <form method="post" class="form-stack">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="assign_position">
                <input type="hidden" name="academic_year" value="<?= e($selectedYear) ?>">
                <label for="leadership-student">Active student</label>
                <select id="leadership-student" name="student_id" required>
                    <option value="">Choose a student</option>
                    <?php foreach ($eligibleStudents as $student): ?>
                        <?php $studentClass = school_class_label(['level' => $student['level'], 'section' => $student['section']]); ?>
                        <option value="<?= (int) $student['id'] ?>">
                            <?= e((string) $student['full_name'] . ' · ' . $studentClass . ' · ' . (string) $student['academic_year']
                                . ($student['admission_number'] !== null ? ' · ' . (string) $student['admission_number'] : '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <label for="leadership-position">Position title</label>
                <input id="leadership-position" name="position_title" maxlength="100" placeholder="e.g. Head Prefect, Class Prefect, Sports Prefect" required>
                <label for="leadership-start">Appointment starts</label>
                <input id="leadership-start" name="starts_on" type="date" value="<?= e(gmdate('Y-m-d')) ?>" required>
                <label for="leadership-end">Appointment ends (optional)</label>
                <input id="leadership-end" name="ends_on" type="date">
                <button class="button button-primary" type="submit">Record appointment</button>
            </form>
        <?php endif; ?>
    </section>

    <?php if ($positionCounts !== []): ?>
        <section class="panel" style="margin-top:1.5rem;">
            <?php render_dashboard_bar_chart('Active student leadership appointments · ' . $selectedYear, $positionCounts, 'students'); ?>
        </section>
    <?php endif; ?>

    <section class="panel" style="margin-top:1.5rem;">
        <h2>Leadership roster and history · <?= e(school_academic_year_label($selectedYear)) ?></h2>
        <?php if ($assignments === []): ?>
            <p class="muted">No student leadership appointments have been recorded for this academic year.</p>
        <?php else: ?>
            <div class="table-scroll"><table>
                <thead><tr><th>Student</th><th>Class</th><th>Position</th><th>Appointment dates</th><th>Status / audit</th><th>Actions</th></tr></thead>
                <tbody><?php foreach ($assignments as $assignment): ?>
                    <tr>
                        <td><?= e((string) $assignment['full_name']) ?>
                            <?php if ($assignment['admission_number'] !== null && (string) $assignment['admission_number'] !== ''): ?><br><small><?= e((string) $assignment['admission_number']) ?></small><?php endif; ?>
                        </td>
                        <td><?= $assignment['level'] === null ? 'Class unavailable' : e(school_class_label(['level' => $assignment['level'], 'section' => $assignment['section']])) ?></td>
                        <td><?= e((string) $assignment['position_title']) ?></td>
                        <td><?= e((string) $assignment['starts_on']) ?><?= $assignment['ends_on'] !== null ? ' – ' . e((string) $assignment['ends_on']) : ' – Ongoing' ?></td>
                        <td><?= e(
                            $assignment['status'] !== 'active'
                                ? 'Ended'
                                : ((string) $assignment['starts_on'] > gmdate('Y-m-d') ? 'Scheduled' : 'Active')
                        ) ?>
                            <br><small>Assigned by <?= e((string) ($assignment['assigner_name'] ?? 'Account unavailable')) ?></small>
                            <?php if ($assignment['end_reason'] !== null): ?><br><small>Ended by <?= e((string) ($assignment['ender_name'] ?? 'Account unavailable')) ?>: <?= e((string) $assignment['end_reason']) ?></small><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($assignment['status'] === 'active'): ?>
                                <form method="post" class="form-stack">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="end_position">
                                    <input type="hidden" name="assignment_id" value="<?= (int) $assignment['id'] ?>">
                                    <label for="end-reason-<?= (int) $assignment['id'] ?>">Reason for ending</label>
                                    <input id="end-reason-<?= (int) $assignment['id'] ?>" name="end_reason" maxlength="500" required>
                                    <button class="button button-quiet" type="submit">End appointment</button>
                                </form>
                            <?php else: ?>History retained<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php render_footer(); ?>
