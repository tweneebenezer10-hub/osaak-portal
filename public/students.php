<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/image_uploads.php';

$user = require_school_context();
$school = active_school_context();
$schoolId = (int) $school['id'];
$schoolRole = (string) ($user['active_school_role'] ?? school_user_role_for_school((int) $user['id'], $schoolId));
$canManageStudents = school_has_permission($user, $schoolId, 'students.manage');
$canViewFormerStudents = school_has_permission($user, $schoolId, 'students.lifecycle.manage');
if (!$canManageStudents && !school_has_permission($user, $schoolId, 'students.view')) {
    require_school_permission($schoolId, 'students.view');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if (!$canManageStudents) {
        http_response_code(403);
        exit('Your role allows viewing assigned student records but not changing them.');
    }
    if ($action === 'update_student_photo') {
        $studentId = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);
        if ($studentId === false || $studentId < 1) {
            flash('error', 'Choose a valid student record.');
            redirect('students.php');
        }
        $studentPhotoStmt = db()->prepare('SELECT photo_path FROM school_students WHERE id = ? AND school_id = ? LIMIT 1');
        $studentPhotoStmt->execute([$studentId, $schoolId]);
        $oldPhoto = $studentPhotoStmt->fetchColumn();
        if ($oldPhoto === false) {
            flash('error', 'The student record does not belong to this school.');
            redirect('students.php');
        }
        $removePhoto = ($_POST['remove_photo'] ?? '') === '1';
        $newPhoto = null;
        $upload = $_FILES['student_photo'] ?? ['error' => UPLOAD_ERR_NO_FILE];
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $newPhoto = store_uploaded_image($upload, 'student-photos', 2097152);
            } catch (InvalidArgumentException | RuntimeException $exception) {
                flash('error', $exception->getMessage());
                redirect('students.php');
            }
        } elseif (!$removePhoto) {
            flash('error', 'Choose a student photo to upload or select the remove option.');
            redirect('students.php');
        }
        $photoPath = $newPhoto['path'] ?? ($removePhoto ? null : (string) $oldPhoto);
        try {
            db()->prepare('UPDATE school_students SET photo_path = ? WHERE id = ? AND school_id = ?')
                ->execute([$photoPath, $studentId, $schoolId]);
        } catch (Throwable $exception) {
            if ($newPhoto !== null) {
                remove_stored_image($newPhoto['path'], 'student-photos');
            }
            throw $exception;
        }
        if (is_string($oldPhoto) && $oldPhoto !== '' && $oldPhoto !== $photoPath) {
            remove_stored_image($oldPhoto, 'student-photos');
        }
        audit((int) $user['id'], $removePhoto ? 'student_photo_removed' : 'student_photo_updated', 'school_id=' . $schoolId . ';student_id=' . $studentId);
        flash('success', $removePhoto ? 'Student photo removed.' : 'Student photo updated.');
        redirect('students.php');
    }
    http_response_code(400);
    exit('This student directory action is no longer available. Register new students from Admissions.');
}

$search = substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$selectedLevel = trim((string) ($_GET['class_level'] ?? ''));
$selectedYear = trim((string) ($_GET['academic_year'] ?? ''));
$classLevels = school_class_levels($schoolId, true);
if ($selectedLevel !== '' && !in_array($selectedLevel, $classLevels, true)) {
    $selectedLevel = '';
}
$selectedClassId = filter_var($_GET['class_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$selectedClass = null;
if ($selectedClassId !== false) {
    $selectedClassStmt = db()->prepare(
        'SELECT id, level, section, academic_year
         FROM school_classes WHERE id = ? AND school_id = ? LIMIT 1'
    );
    $selectedClassStmt->execute([$selectedClassId, $schoolId]);
    $selectedClass = $selectedClassStmt->fetch() ?: null;
    if ($selectedClass === null) {
        $selectedClassId = false;
    }
}

$teacherScopeSql = '';
$teacherScopeParams = [];
if (!$canManageStudents && $schoolRole === 'class_teacher') {
    $teacherScopeSql = ' AND EXISTS (
        SELECT 1 FROM teacher_class_assignments tca
        WHERE tca.school_id = c.school_id AND tca.class_id = c.id
          AND tca.teacher_user_id = ? AND tca.academic_year = c.academic_year
    )';
    $teacherScopeParams[] = (int) $user['id'];
} elseif (!$canManageStudents && $schoolRole === 'subject_teacher') {
    $teacherScopeSql = ' AND EXISTS (
        SELECT 1 FROM class_subjects cs
        WHERE cs.school_id = c.school_id AND cs.class_id = c.id AND cs.teacher_user_id = ?
    )';
    $teacherScopeParams[] = (int) $user['id'];
}

$classStmt = db()->prepare(
    'SELECT c.id, c.level, c.section, c.academic_year,
            COUNT(s.id) AS student_count
     FROM school_classes c
     LEFT JOIN school_students s ON s.school_id = c.school_id AND s.class_id = c.id
       AND s.school_status = "active"'
        . ' WHERE c.school_id = ?'
        . ($selectedYear !== '' ? ' AND c.academic_year = ?' : '') . $teacherScopeSql
        . ' GROUP BY c.id, c.level, c.section, c.academic_year
            ORDER BY c.academic_year DESC, c.level, c.section'
);
$classParams = [$schoolId];
if ($selectedYear !== '') {
    $classParams[] = $selectedYear;
}
$classStmt->execute(array_merge($classParams, $teacherScopeParams));
$classes = $classStmt->fetchAll();
$classYears = array_values(array_unique(array_map(static fn(array $class): string => (string) $class['academic_year'], $classes)));

$studentStmt = db()->prepare(
    'SELECT s.id, s.full_name, s.admission_number, s.date_of_birth, s.gender, s.photo_path,
            s.school_status, c.id AS class_id, c.level AS class_level, c.section AS class_section, c.academic_year,
            g.full_name AS guardian_name, g.email AS guardian_email, g.phone AS guardian_phone,
            g.relationship AS guardian_relationship
     FROM school_students s
     LEFT JOIN school_classes c ON c.id = s.class_id AND c.school_id = s.school_id
     LEFT JOIN school_guardians g ON g.id = s.guardian_id AND g.school_id = s.school_id
     WHERE s.school_id = ? AND s.school_status = "active"'
        . ($selectedLevel !== '' ? ' AND c.level = ?' : '')
        . ($selectedClassId !== false ? ' AND s.class_id = ?' : '')
        . ($selectedYear !== '' ? ' AND c.academic_year = ?' : '')
        . ($search !== '' ? ' AND (s.full_name LIKE ? OR s.admission_number LIKE ? OR CAST(s.id AS CHAR) LIKE ?)' : '')
        . (!$canManageStudents && $schoolRole === 'class_teacher' ? ' AND EXISTS (
            SELECT 1 FROM teacher_class_assignments tca
            WHERE tca.school_id = s.school_id AND tca.class_id = s.class_id
              AND tca.teacher_user_id = ? AND tca.academic_year = c.academic_year
        )' : '')
        . (!$canManageStudents && $schoolRole === 'subject_teacher' ? ' AND EXISTS (
            SELECT 1 FROM class_subjects cs
            WHERE cs.school_id = s.school_id AND cs.class_id = s.class_id AND cs.teacher_user_id = ?
        )' : '')
        . ' ORDER BY c.academic_year DESC, c.level, c.section, s.full_name ASC'
);
$studentParams = [$schoolId];
if ($selectedLevel !== '') {
    $studentParams[] = $selectedLevel;
}
if ($selectedClassId !== false) {
    $studentParams[] = $selectedClassId;
}
if ($selectedYear !== '') {
    $studentParams[] = $selectedYear;
}
if ($search !== '') {
    array_push($studentParams, '%' . $search . '%', '%' . $search . '%', '%' . $search . '%');
}
if (!$canManageStudents && in_array($schoolRole, ['class_teacher', 'subject_teacher'], true)) {
    $studentParams[] = (int) $user['id'];
}
$studentStmt->execute($studentParams);
$students = $studentStmt->fetchAll();
$totalStmt = db()->prepare(
    'SELECT COUNT(*) FROM school_students s WHERE s.school_id = ? AND s.school_status = "active"'
        . ($selectedLevel !== '' ? ' AND EXISTS (
            SELECT 1 FROM school_classes c WHERE c.id = s.class_id AND c.school_id = s.school_id AND c.level = ?
        )' : '')
        . ($selectedClassId !== false ? ' AND s.class_id = ?' : '')
        . ($selectedYear !== '' ? ' AND EXISTS (
            SELECT 1 FROM school_classes c WHERE c.id = s.class_id AND c.school_id = s.school_id AND c.academic_year = ?
        )' : '')
        . ($search !== '' ? ' AND (s.full_name LIKE ? OR s.admission_number LIKE ? OR CAST(s.id AS CHAR) LIKE ?)' : '')
        . (!$canManageStudents && in_array($schoolRole, ['class_teacher', 'subject_teacher'], true) ? ' AND EXISTS (
            SELECT 1 FROM school_classes c WHERE c.id = s.class_id AND c.school_id = s.school_id'
            . $teacherScopeSql . '
        )' : '')
);
$totalParams = [$schoolId];
if ($selectedLevel !== '') {
    $totalParams[] = $selectedLevel;
}
if ($selectedClassId !== false) {
    $totalParams[] = $selectedClassId;
}
if ($selectedYear !== '') {
    $totalParams[] = $selectedYear;
}
if ($search !== '') {
    array_push($totalParams, '%' . $search . '%', '%' . $search . '%', '%' . $search . '%');
}
$totalStmt->execute(array_merge($totalParams, $teacherScopeParams));
$totalStudents = (int) $totalStmt->fetchColumn();
$allCardParams = [$schoolId];
$allCardSql = 'SELECT COUNT(*) FROM school_students s WHERE s.school_id = ? AND s.school_status = "active"';
if ($selectedYear !== '') {
    $allCardSql .= ' AND EXISTS (
        SELECT 1 FROM school_classes c WHERE c.id = s.class_id AND c.school_id = s.school_id AND c.academic_year = ?
    )';
    $allCardParams[] = $selectedYear;
}
if (!$canManageStudents && in_array($schoolRole, ['class_teacher', 'subject_teacher'], true)) {
    $allCardSql .= ' AND EXISTS (
        SELECT 1 FROM school_classes c WHERE c.id = s.class_id AND c.school_id = s.school_id'
        . $teacherScopeSql . '
    )';
    array_push($allCardParams, ...$teacherScopeParams);
}
$allCardStmt = db()->prepare($allCardSql);
$allCardStmt->execute($allCardParams);
$allStudentsCount = (int) $allCardStmt->fetchColumn();

render_header('Students');
?>
<section class="panel student-directory-heading">
    <div><p class="eyebrow">STUDENT RECORDS</p><h1>Student directory</h1>
        <p class="muted"><?= $canManageStudents ? 'Browse and manage current students by class. Former students are kept in a separate directory.' : 'Browse current students in your assigned classes.' ?></p>
    </div>
    <div class="student-directory-total"><strong><?= $totalStudents ?></strong><span>current students</span></div>
</section>

<?php if ((string) ($_GET['action'] ?? '') !== 'directory'): ?>
<section class="report-action-grid" aria-label="Student management actions">
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('students') ?></span>
        <div><h2>Browse Current Students</h2><p>Search current students by name, admission number, class, or academic year.</p></div>
        <a class="button button-primary" href="students.php?action=directory">Open Directory</a>
    </article>
    <?php if ($canViewFormerStudents): ?>
        <article class="report-action-card">
            <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('students') ?></span>
            <div><h2>Former Students &amp; Transfers</h2><p>View inactive and graduated student records separately from the current directory.</p></div>
            <a class="button" href="former_students.php">Open Former Students</a>
        </article>
    <?php endif; ?>
    <?php if ($canManageStudents): ?>
        <article class="report-action-card">
            <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('admissions') ?></span>
            <div><h2>Register a Student</h2><p>Open the existing admissions workflow to add a new student without creating a duplicate directory.</p></div>
            <a class="button" href="admissions.php">Open Admissions</a>
        </article>
    <?php endif; ?>
</section>
<?php render_footer(); exit; endif; ?>

<p class="action-task-toolbar"><a class="button button-quiet" href="students.php">Back to Actions</a></p>
<section class="student-class-navigation" aria-label="Filter students by class">
    <?php
    $allCardQuery = ['action' => 'directory'];
    if ($selectedYear !== '') {
        $allCardQuery['academic_year'] = $selectedYear;
    }
    if ($search !== '') {
        $allCardQuery['q'] = $search;
    }
    ?>
    <a class="student-class-card <?= $selectedLevel === '' ? 'is-selected' : '' ?>" href="students.php<?= $allCardQuery !== [] ? '?' . e(http_build_query($allCardQuery)) : '' ?>">
        <span>All Current Students</span><strong><?= $allStudentsCount ?></strong><small><?= $selectedYear !== '' ? e(school_academic_year_label($selectedYear)) : 'Across all classes' ?></small>
    </a>
    <?php foreach ($classLevels as $level):
        $count = 0;
        foreach ($classes as $class) {
            if ((string) $class['level'] === $level) {
                $count += (int) $class['student_count'];
            }
        }
        $cardQuery = ['action' => 'directory', 'class_level' => $level];
        if ($selectedYear !== '') {
            $cardQuery['academic_year'] = $selectedYear;
        }
    ?>
        <a class="student-class-card <?= $selectedLevel === $level ? 'is-selected' : '' ?>" href="students.php?<?= e(http_build_query($cardQuery)) ?>">
            <span><?= e($level) ?></span><strong><?= $count ?></strong><small><?= $count === 1 ? 'student' : 'students' ?></small>
        </a>
    <?php endforeach; ?>
</section>

<section class="panel student-directory-panel">
    <form method="get" action="students.php" class="directory-filters student-directory-filters">
        <input type="hidden" name="action" value="directory">
        <?php if ($selectedLevel !== ''): ?><input type="hidden" name="class_level" value="<?= e($selectedLevel) ?>"><?php endif; ?>
        <?php if ($selectedClassId !== false): ?><input type="hidden" name="class_id" value="<?= (int) $selectedClassId ?>"><?php endif; ?>
        <label for="student-search">Search students<input id="student-search" name="q" type="search" value="<?= e($search) ?>" placeholder="Name, admission number, or ID"></label>
        <label for="student-year">Academic year<select id="student-year" name="academic_year"><option value="">All years</option>
            <?php foreach ($classYears as $year): ?><option value="<?= e($year) ?>" <?= $selectedYear === $year ? 'selected' : '' ?>><?= e(school_academic_year_label($year)) ?></option><?php endforeach; ?>
        </select></label>
        <button class="button button-secondary" type="submit">Search</button>
        <?php if ($selectedLevel !== '' || $selectedClassId !== false || $selectedYear !== '' || $search !== ''): ?><a class="button button-quiet" href="students.php?action=directory">Clear filters</a><?php endif; ?>
    </form>
    <h2><?= $selectedClass !== null ? e(school_class_label($selectedClass)) : ($selectedLevel !== '' ? e($selectedLevel) : 'All current students') ?> <span class="muted">· <?= count($students) ?></span></h2>
    <?php if ($canViewFormerStudents): ?><p><a class="button button-secondary" href="former_students.php">View Former Students &amp; Transfers</a></p><?php endif; ?>
    <?php if (!$canManageStudents): ?><p class="muted">Student details and photo access are limited to your assigned classes or subjects.</p><?php endif; ?>
    <?php if ($students === []): ?>
        <div class="student-directory-empty"><strong><?= $selectedLevel !== '' ? 'No students in this class yet' : 'No students match these filters' ?></strong><span><?= $selectedLevel !== '' ? 'This class currently has no students to display.' : 'Try another class, academic year, or search term.' ?></span></div>
    <?php else: ?>
        <div class="table-scroll"><table>
            <thead>
                <tr><th>Photo</th><th>Name</th><th>Admission number</th><th>Class / year</th><th>Gender</th><th>Status</th><th>Profile</th></tr>
            </thead>
            <tbody>
                <?php foreach ($students as $student): ?>
                    <tr>
                        <td><?php if ($canManageStudents): ?>
                            <?php $studentPhotoUrl = 'student_photo.php?id=' . (int) $student['id'] . ($student['photo_path'] ? '&v=' . substr(hash('sha256', (string) $student['photo_path']), 0, 12) : ''); ?>
                            <button class="student-photo-trigger" type="button" data-open-student-photo-editor
                                data-student-id="<?= (int) $student['id'] ?>"
                                data-student-name="<?= e((string) $student['full_name']) ?>"
                                data-student-photo-url="<?= e($studentPhotoUrl) ?>"
                                data-has-student-photo="<?= $student['photo_path'] ? 'true' : 'false' ?>"
                                aria-label="Edit photo for <?= e((string) $student['full_name']) ?>">
                                <?php if ($student['photo_path']): ?><img class="student-photo" src="<?= e($studentPhotoUrl) ?>" alt=""><?php else: ?><span class="student-photo student-photo-placeholder" aria-hidden="true"><?= e(strtoupper(substr((string) $student['full_name'], 0, 1))) ?></span><?php endif; ?>
                            </button>
                        <?php elseif ($student['photo_path']): ?><img class="student-photo" src="student_photo.php?id=<?= (int) $student['id'] ?>" alt="Photo of <?= e((string) $student['full_name']) ?>"><?php else: ?><span class="student-photo student-photo-placeholder" aria-label="No photo"><?= e(strtoupper(substr((string) $student['full_name'], 0, 1))) ?></span><?php endif; ?></td>
                        <td><?= e((string) $student['full_name']) ?></td>
                        <td><?= e((string) ($student['admission_number'] ?: '—')) ?></td>
                        <td><?= e($student['class_level'] ? school_class_label(['level' => $student['class_level'], 'section' => $student['class_section']]) : 'Unassigned') ?><?= $student['academic_year'] ? ' · ' . e(school_academic_year_label((string) $student['academic_year'])) : '' ?></td>
                        <td><?= e(ucwords(str_replace('_', ' ', (string) ($student['gender'] ?: 'not recorded')))) ?></td>
                        <td><?= e(ucwords(str_replace('_', ' ', (string) $student['school_status']))) ?></td>
                        <td><a class="button button-quiet button-small" href="student_profile.php?id=<?= (int) $student['id'] ?>">View profile</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php if ($canManageStudents): ?>
<dialog class="profile-photo-dialog" data-student-photo-dialog aria-labelledby="student-photo-dialog-title">
    <form method="post" action="students.php" enctype="multipart/form-data" data-student-photo-form>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_student_photo">
        <input type="hidden" name="student_id" value="" data-student-photo-id>
        <input type="hidden" name="remove_photo" value="">
        <h2 id="student-photo-dialog-title" data-student-photo-title>Student photo</h2>
        <div class="profile-photo-dialog-preview" data-student-photo-preview>
            <span class="student-photo student-photo-placeholder" aria-hidden="true">?</span>
        </div>
        <p class="muted profile-photo-dialog-help">JPEG, PNG, or WebP · maximum 2 MB</p>
        <input class="visually-hidden" type="file" name="student_photo" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" data-student-photo-input tabindex="-1">
        <div class="profile-photo-dialog-actions">
            <button class="button button-primary" type="button" data-student-photo-change>Change photo</button>
            <a class="button button-quiet" href="#" target="_blank" rel="noopener" data-student-photo-view hidden>View photo</a>
            <button class="button button-quiet" type="button" data-student-photo-remove hidden>Remove photo</button>
            <button class="button button-quiet" type="button" data-student-photo-cancel>Cancel</button>
            <button class="button button-primary" type="submit" data-student-photo-save disabled>Save photo</button>
        </div>
    </form>
</dialog>
<?php endif; ?>
<?php render_footer(); ?>
