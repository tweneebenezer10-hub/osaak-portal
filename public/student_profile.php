<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/student_class_history.php';

$user = require_school_context();
$school = active_school_context();
$schoolId = (int) $school['id'];
if (!school_has_permission($user, $schoolId, 'students.manage')
    && !school_has_permission($user, $schoolId, 'students.view')) {
    require_school_permission($schoolId, 'students.view');
}

$studentId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$studentId) {
    http_response_code(404);
    exit('Student record not found.');
}

$role = (string) ($user['active_school_role'] ?? school_user_role_for_school((int) $user['id'], $schoolId));
$canManageStudents = school_has_permission($user, $schoolId, 'students.manage');
$canViewFormer = school_has_permission($user, $schoolId, 'students.lifecycle.manage');
$scopeSql = '';
$params = [$studentId, $schoolId, $canViewFormer ? 1 : 0];
if (!$canManageStudents && $role === 'class_teacher') {
    $scopeSql = ' AND EXISTS (
        SELECT 1 FROM teacher_class_assignments tca
        WHERE tca.school_id = ss.school_id AND tca.class_id = ss.class_id
          AND tca.teacher_user_id = ? AND tca.academic_year = sc.academic_year
    )';
    $params[] = (int) $user['id'];
} elseif (!$canManageStudents && $role === 'subject_teacher') {
    $scopeSql = ' AND EXISTS (
        SELECT 1 FROM class_subjects cs
        WHERE cs.school_id = ss.school_id AND cs.class_id = ss.class_id AND cs.teacher_user_id = ?
    )';
    $params[] = (int) $user['id'];
} elseif (!$canManageStudents) {
    http_response_code(403);
    exit('Your role does not have access to this student profile.');
}

$studentStmt = db()->prepare(
    'SELECT ss.id, ss.full_name, ss.admission_number, ss.admission_date, ss.date_of_birth, ss.gender,
            ss.school_status, ss.photo_path, sc.level AS class_level, sc.section AS class_section,
            sc.id AS class_id, sc.class_level_id, sc.academic_year,
            g.full_name AS guardian_name, g.email AS guardian_email,
            g.phone AS guardian_phone, g.relationship AS guardian_relationship
     FROM school_students ss
     LEFT JOIN school_classes sc ON sc.id = ss.class_id AND sc.school_id = ss.school_id
     LEFT JOIN school_guardians g ON g.id = ss.guardian_id AND g.school_id = ss.school_id
     WHERE ss.id = ? AND ss.school_id = ?
       AND (ss.school_status = "active" OR ? = 1)' . $scopeSql . '
     LIMIT 1'
);
$studentStmt->execute($params);
$student = $studentStmt->fetch();
if (!$student) {
    http_response_code(404);
    exit('Student record not found in this school or access is not authorized.');
}

$classHistoryReady = student_class_history_schema_ready();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (!$canManageStudents) {
        http_response_code(403);
        exit('Your role allows viewing assigned student records but not changing class placements.');
    }
    if (!$classHistoryReady) {
        flash('error', 'Apply migration 108_student_class_history.sql before changing a student class placement.');
        redirect('student_profile.php?id=' . (int) $studentId);
    }
    $targetClassId = filter_var($_POST['class_id'] ?? null, FILTER_VALIDATE_INT);
    $effectiveOn = trim((string) ($_POST['effective_on'] ?? ''));
    $reason = trim((string) ($_POST['reason'] ?? ''));
    try {
        $effectiveOn = student_class_history_effective_date($effectiveOn, date('Y-m-d'));
    } catch (InvalidArgumentException $exception) {
        flash('error', $exception->getMessage());
        redirect('student_profile.php?id=' . (int) $studentId);
    }
    if ($targetClassId === false || $targetClassId < 1
        || preg_match('/^.{5,1000}$/us', $reason) !== 1) {
        flash('error', 'Choose a valid school class, a valid effective date no later than today, and a reason of 5 to 1000 characters.');
        redirect('student_profile.php?id=' . (int) $studentId);
    }

    try {
        db()->beginTransaction();
        $lockedStudentStmt = db()->prepare(
            'SELECT school_status, class_id FROM school_students
             WHERE id = ? AND school_id = ? FOR UPDATE'
        );
        $lockedStudentStmt->execute([(int) $studentId, $schoolId]);
        $lockedStudent = $lockedStudentStmt->fetch();
        if (!is_array($lockedStudent) || (string) $lockedStudent['school_status'] !== 'active') {
            throw new RuntimeException('Only a current student can be moved to another class.');
        }
        $previousClassId = $lockedStudent['class_id'] === null ? null : (int) $lockedStudent['class_id'];
        if ($previousClassId === (int) $targetClassId) {
            throw new InvalidArgumentException('The student is already assigned to that class.');
        }
        school_class_lock_for_student_assignment(
            db(),
            $schoolId,
            (int) $targetClassId,
            (int) $studentId
        );
        student_class_history_record(
            db(),
            $schoolId,
            (int) $studentId,
            'class_change',
            $previousClassId,
            (int) $targetClassId,
            $effectiveOn,
            $reason,
            (int) $user['id']
        );
        db()->prepare(
            'UPDATE school_students SET class_id = ? WHERE id = ? AND school_id = ? AND school_status = "active"'
        )->execute([(int) $targetClassId, (int) $studentId, $schoolId]);
        audit((int) $user['id'], 'student_class_changed',
            'school_id=' . $schoolId . ';student_id=' . (int) $studentId . ';from='
            . ($previousClassId ?? 'unassigned') . ';to=' . (int) $targetClassId . ';effective_on=' . $effectiveOn);
        db()->commit();
        flash('success', 'Student class placement updated and retained in class history.');
    } catch (InvalidArgumentException | RuntimeException $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        flash('error', $exception->getMessage());
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        error_log('Student class-history update failed: ' . $exception->getMessage());
        flash('error', 'The class placement could not be updated. No uncommitted changes were retained.');
    }
    redirect('student_profile.php?id=' . (int) $studentId);
}

$classHistory = [];
$lifecycleHistory = [];
$classOptions = [];
$recommendedClassIds = [];
if ($classHistoryReady) {
    $historyStmt = db()->prepare(
        'SELECT history.*, actor.full_name AS actor_name
         FROM school_student_class_history history
         LEFT JOIN users actor ON actor.id = history.actor_user_id
         WHERE history.school_id = ? AND history.student_id = ?
         ORDER BY history.effective_on DESC, history.id DESC'
    );
    $historyStmt->execute([$schoolId, (int) $studentId]);
    $classHistory = $historyStmt->fetchAll();
    $lifecycleStmt = db()->prepare(
        'SELECT event.event_type, event.previous_status, event.new_status, event.effective_on,
                event.reason, event.created_at, actor.full_name AS actor_name
         FROM student_lifecycle_events event
         LEFT JOIN users actor ON actor.id = event.actor_user_id
         WHERE event.school_id = ? AND event.student_id = ?
         ORDER BY event.effective_on DESC, event.id DESC'
    );
    $lifecycleStmt->execute([$schoolId, (int) $studentId]);
    $lifecycleHistory = $lifecycleStmt->fetchAll();
    if ($canManageStudents && $student['school_status'] === 'active') {
        $classOptionsStmt = db()->prepare(
            'SELECT class.id, class.class_level_id, class.level, class.section, class.academic_year,
                    class.capacity,
                    (SELECT COUNT(*) FROM school_students assigned
                     WHERE assigned.school_id = class.school_id AND assigned.class_id = class.id
                       AND assigned.school_status = "active") AS active_student_count
             FROM school_classes class
             WHERE class.school_id = ? AND class.status = "active"
             ORDER BY class.academic_year DESC, class.level, class.section'
        );
        $classOptionsStmt->execute([$schoolId]);
        $classOptions = $classOptionsStmt->fetchAll();
        if ($student['class_level_id'] !== null && $student['academic_year'] !== null) {
            $recommendedStmt = db()->prepare(
                'SELECT next_class.id
                 FROM school_class_levels current_level
                 JOIN school_classes next_class
                   ON next_class.school_id = current_level.school_id
                  AND next_class.class_level_id = current_level.next_level_id
                  AND next_class.status = "active"
                 WHERE current_level.school_id = ? AND current_level.id = ?
                   AND current_level.status = "active"
                   AND next_class.academic_year > ?
                 ORDER BY next_class.academic_year, next_class.level, next_class.section'
            );
            $recommendedStmt->execute([
                $schoolId,
                (int) $student['class_level_id'],
                (string) $student['academic_year'],
            ]);
            $recommendedClassIds = array_fill_keys(
                array_map('intval', $recommendedStmt->fetchAll(PDO::FETCH_COLUMN)),
                true
            );
        }
    }
}

render_header('Student profile');
?>
<section class="panel student-profile-heading">
    <div>
        <p class="eyebrow">STUDENT RECORDS</p>
        <h1><?= e((string) $student['full_name']) ?></h1>
        <p class="muted"><?= e($student['class_level'] ? school_class_label([
            'level' => $student['class_level'],
            'section' => $student['class_section'],
        ]) : 'Unassigned') ?>
            <?= $student['academic_year'] ? ' · ' . e(school_academic_year_label((string) $student['academic_year'])) : '' ?>
        </p>
    </div>
    <a class="button button-quiet" href="students.php">Back to directory</a>
</section>

<section class="panel student-profile-card">
    <div class="student-profile-photo">
        <?php if ($student['photo_path']): ?>
            <img class="student-photo" src="student_photo.php?id=<?= (int) $student['id'] ?>" alt="Photo of <?= e((string) $student['full_name']) ?>">
        <?php else: ?>
            <span class="student-photo-placeholder" aria-label="No photo">—</span>
        <?php endif; ?>
    </div>
    <dl class="student-profile-details">
        <div><dt>Admission number</dt><dd><?= e((string) ($student['admission_number'] ?: 'Not recorded')) ?></dd></div>
        <div><dt>Admission date</dt><dd><?= e((string) ($student['admission_date'] ?: 'Not recorded')) ?></dd></div>
        <div><dt>Student ID</dt><dd><?= (int) $student['id'] ?></dd></div>
        <div><dt>Class</dt><dd><?= e($student['class_level'] ? school_class_label([
            'level' => $student['class_level'],
            'section' => $student['class_section'],
        ]) : 'Unassigned') ?></dd></div>
        <div><dt>Academic year</dt><dd><?= e($student['academic_year'] ? school_academic_year_label((string) $student['academic_year']) : 'Not assigned') ?></dd></div>
        <div><dt>Gender</dt><dd><?= e(ucwords(str_replace('_', ' ', (string) ($student['gender'] ?: 'not recorded')))) ?></dd></div>
        <div><dt>Date of birth</dt><dd><?= e((string) ($student['date_of_birth'] ?: 'Not recorded')) ?></dd></div>
        <div><dt>Status</dt><dd><?= e(ucwords(str_replace('_', ' ', (string) $student['school_status']))) ?></dd></div>
        <div><dt>Guardian</dt><dd><?= e((string) ($student['guardian_name'] ?: 'Not recorded')) ?><?= $student['guardian_relationship'] ? ' · ' . e((string) $student['guardian_relationship']) : '' ?></dd></div>
        <div><dt>Guardian phone</dt><dd><?= e((string) ($student['guardian_phone'] ?: 'Not recorded')) ?></dd></div>
        <div><dt>Guardian email</dt><dd><?= e((string) ($student['guardian_email'] ?: 'Not recorded')) ?></dd></div>
    </dl>
</section>

<section class="panel top-gap">
    <h2>Student status history</h2>
    <?php if (!$classHistoryReady): ?>
        <p class="muted">Effective-dated status history is available after migration 108_student_class_history.sql.</p>
    <?php elseif ($lifecycleHistory === []): ?>
        <p class="muted">No student lifecycle events have been recorded.</p>
    <?php else: ?>
        <div class="table-scroll"><table>
            <thead><tr><th>Event</th><th>Previous → new status</th><th>Effective</th><th>Reason</th><th>Recorded by / at</th></tr></thead>
            <tbody><?php foreach ($lifecycleHistory as $event): ?>
                <tr>
                    <td><?= e(ucwords(str_replace('_', ' ', (string) $event['event_type']))) ?></td>
                    <td><?= e(ucwords(str_replace('_', ' ', (string) $event['previous_status']))) ?> → <?= e(ucwords(str_replace('_', ' ', (string) $event['new_status']))) ?></td>
                    <td><?= e((string) $event['effective_on']) ?></td>
                    <td><?= e((string) ($event['reason'] ?: '—')) ?></td>
                    <td><?= e((string) ($event['actor_name'] ?: 'Unavailable')) ?><br><small><?= e((string) $event['created_at']) ?></small></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>

<?php if (school_has_permission($user, $schoolId, 'report_cards.view')): ?>
<section class="panel student-profile-actions">
    <h2>Available records</h2>
    <a class="button button-primary" href="report_cards.php?student_id=<?= (int) $student['id'] ?>">View report card and history</a>
</section>
<?php endif; ?>

<section class="panel top-gap">
    <h2>Class placement history</h2>
    <?php if (!$classHistoryReady): ?>
        <p class="muted">Class history is not configured yet. An administrator can apply migration 108_student_class_history.sql; existing student records remain unchanged.</p>
    <?php elseif ($classHistory === []): ?>
        <p class="muted">No class placement history is available for this student.</p>
    <?php else: ?>
        <div class="table-scroll"><table>
            <thead><tr><th>Previous placement</th><th>New placement</th><th>Effective</th><th>Reason</th><th>Recorded by</th><th>Recorded at</th></tr></thead>
            <tbody><?php foreach ($classHistory as $history): ?>
                <tr>
                    <td><?= e((string) ($history['previous_class_snapshot'] ?: '—')) ?><?= $history['previous_academic_year'] ? ' · ' . e(school_academic_year_label((string) $history['previous_academic_year'])) : '' ?></td>
                    <td><?= e((string) ($history['new_class_snapshot'] ?: 'Unassigned')) ?><?= $history['new_academic_year'] ? ' · ' . e(school_academic_year_label((string) $history['new_academic_year'])) : '' ?></td>
                    <td><?= e((string) $history['effective_on']) ?></td>
                    <td><?= e((string) $history['reason']) ?></td>
                    <td><?= e((string) ($history['actor_name'] ?: 'Historical snapshot')) ?></td>
                    <td><?= e((string) $history['created_at']) ?></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
    <?php if ($canManageStudents && $classHistoryReady && $classOptions !== [] && $student['school_status'] === 'active'): ?>
        <h3>Promote or reassign this student</h3>
        <form method="post" class="form-stack">
            <?= csrf_field() ?>
            <label for="student-new-class">New class
                <select id="student-new-class" name="class_id" required>
                    <option value="">Choose an active class</option>
                    <?php foreach ($classOptions as $classOption):
                        $classOptionId = (int) $classOption['id'];
                        $classIsFull = $classOption['capacity'] !== null
                            && (int) $classOption['active_student_count'] >= (int) $classOption['capacity'];
                    ?>
                        <?php if ((int) $classOption['id'] !== (int) ($student['class_id'] ?? 0)): ?>
                            <option value="<?= $classOptionId ?>" <?= $classIsFull ? 'disabled' : '' ?>><?= e(school_class_label($classOption)) ?> · <?= e(school_academic_year_label((string) $classOption['academic_year'])) ?><?= isset($recommendedClassIds[$classOptionId]) ? ' · Recommended progression' : '' ?><?= $classOption['capacity'] !== null ? ' · ' . (int) $classOption['active_student_count'] . '/' . (int) $classOption['capacity'] : '' ?><?= $classIsFull ? ' · Full' : '' ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </label>
            <label for="class-effective-date">Effective date
                <input id="class-effective-date" type="date" name="effective_on" max="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d')) ?>" required>
            </label>
            <label for="class-change-reason">Reason
                <textarea id="class-change-reason" name="reason" minlength="5" maxlength="1000" required></textarea>
            </label>
            <button class="button button-primary" type="submit" data-confirm="Record this class change? The student's existing academic, attendance, and financial history will be retained.">Save class placement</button>
        </form>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
