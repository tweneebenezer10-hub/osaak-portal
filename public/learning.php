<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = require_school_context();
$school = active_school_context();
$schoolId = (int) $school['id'];
require_school_permission($schoolId, 'learning.manage');
$schoolRole = (string) ($user['active_school_role'] ?? school_user_role_for_school((int) $user['id'], $schoolId));
$teacherAccount = in_array($schoolRole, ['class_teacher', 'subject_teacher'], true);
$smartTimetableReady = school_timetable_schema_ready();
$teacherId = (int) $user['id'];
$classScope = '';
$classParams = [$schoolId];
if ($teacherAccount) {
    $classScope = ' AND (
        EXISTS (
            SELECT 1 FROM teacher_class_assignments tca
            WHERE tca.school_id = sc.school_id AND tca.class_id = sc.id
              AND tca.teacher_user_id = ? AND tca.academic_year = sc.academic_year
        ) OR EXISTS (
            SELECT 1 FROM class_subjects cs
            WHERE cs.school_id = sc.school_id AND cs.class_id = sc.id
              AND cs.teacher_user_id = ?
        )
    )';
    $classParams = [$schoolId, $teacherId, $teacherId];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if (in_array($action, ['add_timetable', 'update_timetable'], true)
        && !in_array($schoolRole, ['school_proprietor', 'school_headmaster'], true)) {
        http_response_code(403);
        exit('Only the Proprietor or Headmaster can manage timetable entries.');
    }
    if ($smartTimetableReady && in_array($action, ['add_timetable', 'update_timetable'], true)) {
        flash('error', 'Use Smart timetable to create or edit schedules. This keeps teacher, room, and class conflicts validated.');
        redirect('timetable.php');
    }
    $classId = filter_var($_POST['class_id'] ?? null, FILTER_VALIDATE_INT);
    $subjectId = filter_var($_POST['subject_id'] ?? null, FILTER_VALIDATE_INT);
    $classStmt = db()->prepare(
        'SELECT sc.academic_year, sc.status FROM school_classes sc
         WHERE sc.id = ? AND sc.school_id = ?' . $classScope . ' LIMIT 1'
    );
    $classStmt->execute(array_merge(
        [$classId === false ? 0 : $classId, $schoolId],
        $teacherAccount ? [$teacherId, $teacherId] : []
    ));
    $class = $classStmt->fetch();
    if ($classId === false || $classId < 1 || !$class) {
        flash('error', 'Choose a class in your school that you are authorized to manage.');
        redirect('learning.php');
    }
    if ($action === 'publish_assignment' && (string) $class['status'] !== 'active') {
        flash('error', 'Homework can only be published to an active class.');
        redirect('learning.php#homework-assignment');
    }

    $subjectValue = null;
    if ($subjectId === false) {
        flash('error', 'Choose a valid subject or leave it unselected.');
        redirect('learning.php');
    }
    if ($subjectId !== false && $subjectId > 0) {
        $subjectStmt = db()->prepare(
            'SELECT 1 FROM class_subjects cs
             JOIN school_subjects ss ON ss.id = cs.subject_id AND ss.school_id = cs.school_id
             WHERE cs.school_id = ? AND cs.class_id = ? AND cs.subject_id = ?'
                . ($teacherAccount ? ' AND cs.teacher_user_id = ?' : '') . '
             LIMIT 1'
        );
        $subjectStmt->execute($teacherAccount
            ? [$schoolId, $classId, $subjectId, $teacherId]
            : [$schoolId, $classId, $subjectId]);
        if (!$subjectStmt->fetchColumn()) {
            flash('error', 'Choose a subject assigned to this class and, for teacher accounts, assigned to you.');
            redirect('learning.php');
        }
        $subjectValue = $subjectId;
    } elseif ($schoolRole === 'subject_teacher') {
        flash('error', 'Subject teachers must select one of their assigned subjects.');
        redirect('learning.php');
    }

    if ($action === 'publish_assignment') {
        try {
            $assignment = school_learning_validate_assignment_input(
                $_POST['title'] ?? null,
                $_POST['instructions'] ?? null,
                $_POST['learning_material_url'] ?? '',
                $_POST['due_at'] ?? '',
                new DateTimeZone(date_default_timezone_get())
            );
        } catch (InvalidArgumentException $exception) {
            flash('error', $exception->getMessage());
            redirect('learning.php#homework-assignment');
        }

        db()->beginTransaction();
        try {
            $insert = db()->prepare(
                'INSERT INTO school_assignments
                 (school_id, class_id, subject_id, title, instructions, learning_material_url, due_at, published_at, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)'
            );
            $insert->execute([
                $schoolId,
                $classId,
                $subjectValue,
                $assignment['title'],
                $assignment['instructions'],
                $assignment['learning_material_url'],
                $assignment['due_at'],
                $teacherId,
            ]);
            $assignmentId = (int) db()->lastInsertId();
            audit(
                $teacherId,
                'school_assignment_published',
                'school_id=' . $schoolId . ';class_id=' . $classId . ';assignment_id=' . $assignmentId
            );
            db()->commit();
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            throw $exception;
        }
        flash('success', 'Homework or assignment published to the selected class.');
        redirect('learning.php#published-assignments');
    }

    if (in_array($action, ['add_timetable', 'update_timetable'], true)) {
        $weekday = filter_var($_POST['weekday'] ?? null, FILTER_VALIDATE_INT);
        $startsAt = trim((string) ($_POST['starts_at'] ?? ''));
        $endsAt = trim((string) ($_POST['ends_at'] ?? ''));
        $room = trim((string) ($_POST['room'] ?? ''));
        $entryId = filter_var($_POST['entry_id'] ?? null, FILTER_VALIDATE_INT);
        if ($weekday === false || $weekday < 1 || $weekday > 7
            || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $startsAt) !== 1
            || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $endsAt) !== 1
            || $endsAt <= $startsAt || strlen($room) > 80
            || ($action === 'update_timetable' && ($entryId === false || $entryId < 1))) {
            flash('error', 'Enter a valid weekday, time range, venue, and timetable entry.');
            redirect('learning.php');
        }
        if ($action === 'update_timetable') {
            $entryLock = db()->prepare(
                'SELECT id FROM school_timetable_entries
                 WHERE id = ? AND school_id = ? FOR UPDATE'
            );
            db()->beginTransaction();
            try {
                $entryLock->execute([$entryId, $schoolId]);
                if (!$entryLock->fetchColumn()) {
                    throw new RuntimeException('The selected timetable entry is not available in this school.');
                }
                $update = db()->prepare(
                    'UPDATE school_timetable_entries
                     SET class_id = ?, subject_id = ?, weekday = ?, starts_at = ?,
                         ends_at = ?, room = ?
                     WHERE id = ? AND school_id = ?'
                );
                $update->execute([
                    $classId,
                    $subjectValue,
                    $weekday,
                    $startsAt . ':00',
                    $endsAt . ':00',
                    $room === '' ? null : $room,
                    $entryId,
                    $schoolId,
                ]);
                db()->commit();
            } catch (Throwable $exception) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                if ($exception instanceof RuntimeException) {
                    flash('error', $exception->getMessage());
                } else {
                    error_log('School timetable update failed: ' . $exception->getMessage());
                    flash('error', 'The timetable entry could not be updated. No changes were applied.');
                }
                redirect('learning.php');
            }
            audit($teacherId, 'school_timetable_entry_updated', 'school_id=' . $schoolId . ';entry_id=' . $entryId . ';class_id=' . $classId);
            flash('success', 'Published timetable entry updated successfully.');
        } else {
            $insert = db()->prepare(
                'INSERT INTO school_timetable_entries
                 (school_id, class_id, subject_id, weekday, starts_at, ends_at, room, published_at, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)'
            );
            $insert->execute([
                $schoolId,
                $classId,
                $subjectValue,
                $weekday,
                $startsAt . ':00',
                $endsAt . ':00',
                $room === '' ? null : $room,
                $teacherId,
            ]);
            flash('success', 'Timetable entry published for the selected class.');
        }
        redirect('learning.php');
    }

    flash('error', 'Choose a valid learning-management action.');
    redirect('learning.php');
}

$classesStmt = db()->prepare(
    'SELECT sc.id, sc.level, sc.section, sc.academic_year, sc.status
     FROM school_classes sc WHERE sc.school_id = ?' . $classScope . '
     ORDER BY sc.academic_year DESC, sc.level, sc.section'
);
$classesStmt->execute($classParams);
$classes = $classesStmt->fetchAll();
$assignmentClasses = array_values(array_filter(
    $classes,
    static fn (array $class): bool => (string) $class['status'] === 'active'
));
$subjectsStmt = db()->prepare(
    'SELECT id, name, code FROM school_subjects WHERE school_id = ? ORDER BY name'
);
$subjectsStmt->execute([$schoolId]);
$subjects = $subjectsStmt->fetchAll();
$timetableSubjectScope = $schoolRole === 'subject_teacher'
    ? ' AND EXISTS (
        SELECT 1 FROM class_subjects assigned_subject
        WHERE assigned_subject.school_id = entry.school_id
          AND assigned_subject.class_id = entry.class_id
          AND assigned_subject.subject_id = entry.subject_id
          AND assigned_subject.teacher_user_id = ?
    )'
    : '';
$timetableStmt = db()->prepare(
    'SELECT entry.id, entry.class_id, entry.subject_id, entry.weekday,
            entry.starts_at, entry.ends_at, entry.room,
            class.level, class.section, subject.name AS subject_name
     FROM school_timetable_entries entry
     JOIN school_classes class ON class.id = entry.class_id AND class.school_id = entry.school_id
     LEFT JOIN school_subjects subject ON subject.id = entry.subject_id AND subject.school_id = entry.school_id
     WHERE entry.school_id = ? AND entry.published_at IS NOT NULL'
     . ($smartTimetableReady ? ' AND (
         entry.version_id IS NULL OR EXISTS (
             SELECT 1 FROM school_timetable_versions effective
             WHERE effective.id = entry.version_id AND effective.school_id = entry.school_id
               AND effective.status = "published"
               AND (effective.effective_from IS NULL OR effective.effective_from <= CURDATE())
               AND (effective.effective_to IS NULL OR effective.effective_to >= CURDATE())
         )
     )' : '')
     . str_replace('sc.', 'class.', $classScope) . $timetableSubjectScope . '
     ORDER BY entry.weekday, entry.starts_at LIMIT 100'
);
$timetableStmt->execute(array_merge($classParams, $schoolRole === 'subject_teacher' ? [$teacherId] : []));
$timetable = $timetableStmt->fetchAll();
$assignmentClassScope = str_replace('sc.', 'assigned_class.', $classScope);
$assignmentSubjectScope = $schoolRole === 'subject_teacher'
    ? ' AND EXISTS (
        SELECT 1 FROM class_subjects assigned_subject
        WHERE assigned_subject.school_id = assignment.school_id
          AND assigned_subject.class_id = assignment.class_id
          AND assigned_subject.subject_id = assignment.subject_id
          AND assigned_subject.teacher_user_id = ?
    )'
    : '';
$assignmentStmt = db()->prepare(
    'SELECT assignment.id, assignment.title, assignment.instructions,
            assignment.learning_material_url, assignment.due_at, assignment.published_at,
            assigned_class.id AS class_id, assigned_class.level, assigned_class.section,
            assigned_class.academic_year, subject.name AS subject_name
     FROM school_assignments assignment
     JOIN school_classes assigned_class
       ON assigned_class.id = assignment.class_id AND assigned_class.school_id = assignment.school_id
     LEFT JOIN school_subjects subject
       ON subject.id = assignment.subject_id AND subject.school_id = assignment.school_id
     WHERE assignment.school_id = ? AND assignment.published_at IS NOT NULL'
        . $assignmentClassScope . $assignmentSubjectScope . '
     ORDER BY assignment.due_at IS NULL, assignment.due_at, assignment.published_at DESC
     LIMIT 100'
);
$assignmentStmt->execute(array_merge(
    $classParams,
    $schoolRole === 'subject_teacher' ? [$teacherId] : []
));
$assignments = $assignmentStmt->fetchAll();
$canManageTimetable = in_array($schoolRole, ['school_proprietor', 'school_headmaster'], true);
$editingTimetableId = filter_var($_GET['edit_timetable'] ?? null, FILTER_VALIDATE_INT);
$editingTimetable = null;
if ($canManageTimetable && $editingTimetableId !== false && $editingTimetableId > 0) {
    $editingStmt = db()->prepare(
        'SELECT id, class_id, subject_id, weekday, starts_at, ends_at, room
         FROM school_timetable_entries
         WHERE id = ? AND school_id = ? AND published_at IS NOT NULL'
            . ($smartTimetableReady ? ' AND version_id IS NULL' : '') . ' LIMIT 1'
    );
    $editingStmt->execute([$editingTimetableId, $schoolId]);
    $editingTimetable = $editingStmt->fetch() ?: null;
    if ($editingTimetable === null) {
        flash('error', 'The requested timetable entry was not found in this school.');
        redirect('learning.php');
    }
}
$weekdays = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
render_header('Timetable');
?>
<section class="panel">
    <p class="eyebrow">TEACHING AND LEARNING</p>
    <h1>School timetable and homework</h1>
    <p class="muted">Published entries are visible only to students in the selected class. Only the Proprietor or Headmaster can create or edit timetable entries.</p>
</section>

<section class="panel panel-top-gap" id="homework-assignment">
    <h2>Publish homework or an assignment</h2>
    <p class="muted">Publishing makes the instructions available to active, linked student accounts in the selected class. This workflow does not collect submissions or grades.</p>
    <?php if ($assignmentClasses === []): ?><p class="muted">No active authorized classes are available.</p>
    <?php else: ?>
        <form method="post" action="learning.php#published-assignments" class="form-stack">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="publish_assignment">
            <div class="form-grid two-columns">
                <div><label for="assignment_class">Class</label><select id="assignment_class" name="class_id" required><?php foreach ($assignmentClasses as $class): ?><option value="<?= (int) $class['id'] ?>"><?= e(school_class_label($class)) ?> · <?= e(school_academic_year_label((string) $class['academic_year'])) ?></option><?php endforeach; ?></select></div>
                <div><label for="assignment_subject">Subject</label><select id="assignment_subject" name="subject_id" <?= $schoolRole === 'subject_teacher' ? 'required' : '' ?>><option value="<?= $schoolRole === 'subject_teacher' ? '' : '0' ?>"><?= $schoolRole === 'subject_teacher' ? 'Select an assigned subject' : 'Class assignment / not specified' ?></option><?php foreach ($subjects as $subject): ?><option value="<?= (int) $subject['id'] ?>"><?= e((string) $subject['name']) ?><?= $subject['code'] ? ' (' . e((string) $subject['code']) . ')' : '' ?></option><?php endforeach; ?></select></div>
                <div><label for="assignment_title">Title</label><input id="assignment_title" name="title" type="text" maxlength="180" required></div>
                <div><label for="assignment_due_at">Due date and time (Ghana local time; optional)</label><input id="assignment_due_at" name="due_at" type="datetime-local"></div>
                <div class="span-all"><label for="assignment_material">Learning material link (optional)</label><input id="assignment_material" name="learning_material_url" type="url" maxlength="1000" placeholder="https://example.edu/resource"></div>
                <div class="span-all"><label for="assignment_instructions">Instructions</label><textarea id="assignment_instructions" name="instructions" rows="5" maxlength="12000" required></textarea></div>
            </div>
            <button class="button button-primary" type="submit">Publish assignment</button>
        </form>
    <?php endif; ?>
</section>

<section class="panel panel-top-gap" id="published-assignments">
    <h2>Published homework and assignments</h2>
    <?php if ($assignments === []): ?><p class="muted">No published assignments are available in your authorized classes or subjects.</p>
    <?php else: ?><div class="table-scroll"><table><thead><tr><th>Class</th><th>Subject</th><th>Assignment</th><th>Due</th><th>Published</th></tr></thead><tbody>
        <?php foreach ($assignments as $assignment): $materialUrl = school_learning_external_http_url($assignment['learning_material_url'] === null ? null : (string) $assignment['learning_material_url']); ?>
            <tr>
                <td><?= e(school_class_label(['level' => $assignment['level'], 'section' => $assignment['section']])) ?> · <?= e(school_academic_year_label((string) $assignment['academic_year'])) ?></td>
                <td><?= e((string) ($assignment['subject_name'] ?: 'Class assignment')) ?></td>
                <td><strong><?= e((string) $assignment['title']) ?></strong><details><summary>Instructions</summary><p><?= nl2br(e((string) $assignment['instructions'])) ?></p><?php if ($materialUrl !== null): ?><p><a href="<?= e($materialUrl) ?>" target="_blank" rel="noopener noreferrer">Open learning material</a></p><?php endif; ?></details></td>
                <td><?= e((string) ($assignment['due_at'] ?: 'No due date')) ?></td>
                <td><?= e((string) $assignment['published_at']) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</section>

<section class="report-action-grid" aria-label="Timetable actions">
    <?php if ($canManageTimetable): ?>
        <article class="report-action-card">
            <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('timetable') ?></span>
            <div><h2>Manage class schedules</h2><p>Configure rooms, assigned teachers, periods, and validated schedule versions.</p></div>
                <a class="button button-primary" href="timetable.php">Open Smart timetable</a>
        </article>
    <?php endif; ?>
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('timetable') ?></span>
        <div><h2>View Published Timetable</h2><p>Review timetable entries available for classes in this school.</p></div>
        <a class="button" href="#published-timetable" data-action-open="published-timetable">View Timetable</a>
    </article>
</section>

<section class="panel panel-top-gap" id="timetable-editor" data-action-view="timetable-editor">
    <?php if ($smartTimetableReady): ?>
        <h2>Schedule management</h2>
        <p class="muted">New schedules and edits are managed in Smart timetable so every change is checked against school, teacher, room, and class constraints. Historical entries remain available in the published timetable list.</p>
        <a class="button button-primary" href="timetable.php">Open Smart timetable</a>
    <?php else: ?>
        <h2><?= $editingTimetable ? 'Edit published timetable entry' : 'Add timetable entry' ?></h2>
        <?php if (!$canManageTimetable): ?>
            <p class="muted">Only the Proprietor or Headmaster can create or edit timetable entries. You can view published timetables below.</p>
        <?php elseif ($classes === []): ?><p class="muted">No classes are available.</p><?php else: ?>
            <form method="post" action="learning.php" class="form-stack">
                <?= csrf_field() ?><input type="hidden" name="action" value="<?= $editingTimetable ? 'update_timetable' : 'add_timetable' ?>">
                <?php if ($editingTimetable): ?><input type="hidden" name="entry_id" value="<?= (int) $editingTimetable['id'] ?>"><?php endif; ?>
                <div class="form-grid two-columns">
                    <div><label for="timetable_class">Class</label><select id="timetable_class" name="class_id" required><?php foreach ($classes as $class): ?><option value="<?= (int) $class['id'] ?>" <?= $editingTimetable && (int) $editingTimetable['class_id'] === (int) $class['id'] ? 'selected' : '' ?>><?= e(school_class_label($class)) ?> · <?= e(school_academic_year_label((string) $class['academic_year'])) ?></option><?php endforeach; ?></select></div>
                    <div><label for="timetable_subject">Subject</label><select id="timetable_subject" name="subject_id"><option value="0">Class period / not specified</option><?php foreach ($subjects as $subject): ?><option value="<?= (int) $subject['id'] ?>" <?= $editingTimetable && (int) ($editingTimetable['subject_id'] ?? 0) === (int) $subject['id'] ? 'selected' : '' ?>><?= e((string) $subject['name']) ?><?= $subject['code'] ? ' (' . e((string) $subject['code']) . ')' : '' ?></option><?php endforeach; ?></select></div>
                    <div><label for="weekday">Weekday</label><select id="weekday" name="weekday" required><?php foreach ($weekdays as $dayNumber => $dayName): ?><option value="<?= $dayNumber ?>" <?= $editingTimetable && (int) $editingTimetable['weekday'] === $dayNumber ? 'selected' : '' ?>><?= e($dayName) ?></option><?php endforeach; ?></select></div>
                    <div><label for="starts_at">Starts at</label><input id="starts_at" name="starts_at" type="time" value="<?= $editingTimetable ? e(substr((string) $editingTimetable['starts_at'], 0, 5)) : '' ?>" required></div>
                    <div><label for="ends_at">Ends at</label><input id="ends_at" name="ends_at" type="time" value="<?= $editingTimetable ? e(substr((string) $editingTimetable['ends_at'], 0, 5)) : '' ?>" required></div>
                    <div><label for="room">Venue / room</label><input id="room" name="room" type="text" maxlength="80" value="<?= e((string) ($editingTimetable['room'] ?? '')) ?>"></div>
                </div>
                <div class="inline-form">
                    <button class="button button-primary" type="submit"><?= $editingTimetable ? 'Save timetable changes' : 'Publish timetable entry' ?></button>
                    <?php if ($editingTimetable): ?><a class="button button-quiet" href="learning.php">Cancel edit</a><?php endif; ?>
                </div>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</section>

<section class="panel panel-top-gap" id="published-timetable" data-action-view="published-timetable">
    <h2>Published timetable</h2>
    <?php if ($timetable === []): ?><p class="muted">No timetable entries have been published.</p><?php else: ?><div class="table-scroll"><table><thead><tr><th>Class</th><th>Day</th><th>Time</th><th>Subject</th><th>Venue / room</th></tr></thead><tbody><?php foreach ($timetable as $entry): ?><tr><td><?= e(school_class_label(['level' => $entry['level'], 'section' => $entry['section']])) ?></td><td><?= e($weekdays[(int) $entry['weekday']] ?? '—') ?></td><td><?= e(substr((string) $entry['starts_at'], 0, 5)) ?>–<?= e(substr((string) $entry['ends_at'], 0, 5)) ?></td><td><?= e((string) ($entry['subject_name'] ?: 'Class period')) ?></td><td><?= e((string) ($entry['room'] ?: '—')) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>
<?php render_footer(); ?>
