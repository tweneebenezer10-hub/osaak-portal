<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = require_school_context();
$school = active_school_context();
if (!$school) {
    flash('error', 'Choose a school before managing class subjects.');
    redirect('schools.php');
}

$schoolId = (int) $school['id'];
require_school_permission($schoolId, 'classes.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add_subject') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $code = trim((string) ($_POST['code'] ?? ''));

        if ($name === '') {
            flash('error', 'Add a subject name before saving it.');
            redirect('subjects.php');
        }
        if (strlen($name) > 120 || strlen($code) > 30) {
            flash('error', 'Subject names must be 120 characters or fewer and subject codes must be 30 characters or fewer.');
            redirect('subjects.php');
        }

        $code = $code === '' ? null : strtoupper($code);
        $duplicateStmt = db()->prepare(
            'SELECT id FROM school_subjects
             WHERE school_id = ? AND name = ? AND code <=> ?
             LIMIT 1'
        );
        $duplicateStmt->execute([$schoolId, $name, $code]);
        if ($duplicateStmt->fetchColumn()) {
            flash('error', 'That subject already exists in this school. Select it from the existing subjects list instead of creating a duplicate.');
            redirect('subjects.php');
        }
        try {
            $stmt = db()->prepare(
                'INSERT INTO school_subjects (school_id, name, code) VALUES (?, ?, ?)'
            );
            $stmt->execute([$schoolId, $name, $code]);
        } catch (PDOException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062) {
                flash('error', 'That subject already exists in this school. Select it from the existing subjects list instead of creating a duplicate.');
                redirect('subjects.php');
            }
            error_log('School subject creation failed: ' . $exception->getMessage());
            flash('error', 'The subject could not be saved. No subject data was changed.');
            redirect('subjects.php');
        }
        flash('success', 'Subject added successfully.');
        redirect('subjects.php');
    }

    if ($action === 'assign_subject') {
        $classId = filter_var($_POST['class_id'] ?? null, FILTER_VALIDATE_INT);
        $subjectId = filter_var($_POST['subject_id'] ?? null, FILTER_VALIDATE_INT);
        $teacherId = filter_var($_POST['teacher_user_id'] ?? null, FILTER_VALIDATE_INT);

        if ($classId === false || $classId < 1 || $subjectId === false || $subjectId < 1) {
            flash('error', 'Choose a valid class and subject to assign.');
            redirect('subjects.php');
        }

        $classCheck = db()->prepare('SELECT 1 FROM school_classes WHERE id = ? AND school_id = ? LIMIT 1');
        $classCheck->execute([$classId, $schoolId]);
        $subjectCheck = db()->prepare('SELECT 1 FROM school_subjects WHERE id = ? AND school_id = ? LIMIT 1');
        $subjectCheck->execute([$subjectId, $schoolId]);

        if (!$classCheck->fetchColumn() || !$subjectCheck->fetchColumn()) {
            flash('error', 'That class or subject does not belong to this school.');
            redirect('subjects.php');
        }

        $teacherUserId = null;
        if (is_string($_POST['teacher_user_id'] ?? null) && $_POST['teacher_user_id'] !== '') {
            if ($teacherId === false || $teacherId < 1) {
                flash('error', 'Choose a valid teacher assigned to this school.');
                redirect('subjects.php');
            }
            $teacherCheck = db()->prepare(
                "SELECT 1 FROM school_memberships
                 WHERE school_id = ? AND user_id = ? AND role_name IN ('class_teacher','subject_teacher')
                   AND status = 'active'
                 LIMIT 1"
            );
            $teacherCheck->execute([$schoolId, $teacherId]);
            if (!$teacherCheck->fetchColumn()) {
                flash('error', 'The selected teacher is not actively assigned to this school.');
                redirect('subjects.php');
            }
            $teacherUserId = $teacherId;
        }

        $stmt = db()->prepare(
            'INSERT INTO class_subjects (school_id, class_id, subject_id, teacher_user_id)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE teacher_user_id = VALUES(teacher_user_id)'
        );
        $stmt->execute([
            $schoolId,
            $classId,
            $subjectId,
            $teacherUserId,
        ]);

        audit((int) $user['id'], 'school_class_subject_assignment_saved', 'school_id=' . $schoolId . '; class_id=' . $classId . '; subject_id=' . $subjectId . '; teacher_id=' . ($teacherUserId ?? 'none'));
        flash('success', 'Subject assigned to class.');
        redirect('subjects.php');
    }
}

$classStmt = db()->prepare('SELECT id, level, section, academic_year FROM school_classes WHERE school_id = ? ORDER BY level, section, academic_year');
$classStmt->execute([$schoolId]);
$classes = $classStmt->fetchAll();

$subjectStmt = db()->prepare('SELECT id, name, code FROM school_subjects WHERE school_id = ? ORDER BY name ASC');
$subjectStmt->execute([$schoolId]);
$subjects = $subjectStmt->fetchAll();

$teacherStmt = db()->prepare(
    "SELECT DISTINCT sm.user_id, u.full_name
     FROM school_memberships sm JOIN users u ON u.id = sm.user_id
     WHERE sm.school_id = ? AND sm.role_name IN ('class_teacher','subject_teacher') AND sm.status = 'active'
     ORDER BY u.full_name ASC"
);
$teacherStmt->execute([$schoolId]);
$teachers = $teacherStmt->fetchAll();

$classSubjectStmt = db()->prepare(
    'SELECT cs.id, c.level, c.section, c.academic_year, s.name AS subject_name, s.code, u.full_name AS teacher_name
     FROM class_subjects cs
     JOIN school_classes c ON c.id = cs.class_id AND c.school_id = cs.school_id
     JOIN school_subjects s ON s.id = cs.subject_id AND s.school_id = cs.school_id
     LEFT JOIN users u ON u.id = cs.teacher_user_id
     WHERE cs.school_id = ? ORDER BY c.level, c.name, s.name'
);
$classSubjectStmt->execute([$schoolId]);
$classSubjects = $classSubjectStmt->fetchAll();

render_header('Subjects');
?>
<section class="panel">
    <p class="eyebrow">CURRICULUM</p>
    <h1>Subjects and class mapping</h1>
</section>

<section class="report-action-grid" aria-label="Subject management actions">
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('book') ?></span>
        <div><h2>Add a Subject</h2><p>Create a subject in this school’s curriculum.</p></div>
        <a class="button button-primary" href="#add-subject" data-action-open="add-subject">Add Subject</a>
    </article>
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('classroom') ?></span>
        <div><h2>Assign a Subject</h2><p>Map a subject and teacher to an authorized class.</p></div>
        <a class="button" href="#assign-subject" data-action-open="assign-subject">Assign Subject</a>
    </article>
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('book') ?></span>
        <div><h2>School Subjects</h2><p>Review the subjects configured for this school.</p></div>
        <a class="button" href="#school-subject-list" data-action-open="school-subject-list">View Subjects</a>
    </article>
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('classroom') ?></span>
        <div><h2>Class Assignments</h2><p>Review class, subject, and teacher mappings.</p></div>
        <a class="button" href="#class-subject-list" data-action-open="class-subject-list">View Assignments</a>
    </article>
</section>

<section class="panel panel-top-gap" id="add-subject" data-action-view="add-subject">
    <h2>Add a subject</h2>
    <form method="post" action="subjects.php" class="form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_subject">
        <div class="form-grid two-columns">
            <div><label for="name">Subject name</label><input id="name" name="name" type="text" required></div>
            <div><label for="code">Subject code</label><input id="code" name="code" type="text" maxlength="30" placeholder="ENG, MATH"></div>
        </div>
        <button class="button button-primary" type="submit">Save subject</button>
    </form>
</section>

<section class="panel panel-top-gap" id="assign-subject" data-action-view="assign-subject">
    <h2>Assign a subject to a class</h2>
    <p class="muted">Assign each teacher to the specific class and subject they teach. Class-teacher status alone does not grant marks entry for every subject; assign each subject they teach here.</p>
    <form method="post" action="subjects.php" class="form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="assign_subject">
        <div class="form-grid two-columns">
            <div>
                <label for="class_id">Class</label>
                <select id="class_id" name="class_id" required>
                    <option value="">Select class</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?= (int) $class['id'] ?>"><?= e(school_class_label($class)) ?> · <?= e(school_academic_year_label((string) $class['academic_year'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="subject_id">Subject</label>
                <select id="subject_id" name="subject_id" required>
                    <option value="">Select subject</option>
                    <?php foreach ($subjects as $subject): ?>
                        <option value="<?= (int) $subject['id'] ?>"><?= e((string) $subject['name']) ?><?= $subject['code'] ? ' (' . e((string) $subject['code']) . ')' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="span-full">
                <label for="teacher_user_id">Teacher</label>
                <select id="teacher_user_id" name="teacher_user_id">
                    <option value="">Unassigned</option>
                    <?php foreach ($teachers as $teacher): ?>
                        <option value="<?= (int) $teacher['user_id'] ?>"><?= e((string) $teacher['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <button class="button button-primary" type="submit">Assign subject</button>
    </form>
</section>

<section class="panel panel-top-gap" id="school-subject-list" data-action-view="school-subject-list">
    <h2>School subjects</h2>
    <?php if ($subjects === []): ?>
        <p class="muted">No subjects have been created for this school yet.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr><th>Subject</th><th>Code</th></tr>
            </thead>
            <tbody>
                <?php foreach ($subjects as $subject): ?>
                    <tr>
                        <td><?= e((string) $subject['name']) ?></td>
                        <td><?= e((string) ($subject['code'] ?: '—')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<section class="panel panel-top-gap" id="class-subject-list" data-action-view="class-subject-list">
    <h2>Class subject assignments</h2>
    <?php if ($classSubjects === []): ?>
        <p class="muted">No subjects have been assigned to classes yet.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr><th>Class</th><th>Subject</th><th>Teacher</th></tr>
            </thead>
            <tbody>
                <?php foreach ($classSubjects as $row): ?>
                    <tr>
                        <td><?= e(school_class_label($row)) ?> · <?= e(school_academic_year_label((string) $row['academic_year'])) ?></td>
                        <td><?= e((string) $row['subject_name']) ?><?= $row['code'] ? ' (' . e((string) $row['code']) . ')' : '' ?></td>
                        <td><?= e((string) ($row['teacher_name'] ?: 'Unassigned')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
