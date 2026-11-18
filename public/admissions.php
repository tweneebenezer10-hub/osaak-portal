<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = require_school_context();
$school = active_school_context();
$schoolId = (int) $school['id'];
require_school_permission($schoolId, 'students.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'register_admission') {
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $gender = in_array((string) ($_POST['gender'] ?? ''), ['male','female','other','prefer_not_to_say'], true) ? (string) $_POST['gender'] : 'prefer_not_to_say';
        $dob = trim((string) ($_POST['date_of_birth'] ?? ''));
        $classId = filter_var($_POST['class_id'] ?? null, FILTER_VALIDATE_INT);
        $guardianName = trim((string) ($_POST['guardian_name'] ?? ''));
        $guardianEmail = strtolower(trim((string) ($_POST['guardian_email'] ?? '')));
        $guardianPhone = trim((string) ($_POST['guardian_phone'] ?? ''));
        $admissionDate = trim((string) ($_POST['admission_date'] ?? ''));
        $admissionDateValue = DateTimeImmutable::createFromFormat('!Y-m-d', $admissionDate);

        if ($fullName === '') {
            flash('error', 'The applicant full name is required.');
            redirect('admissions.php?add=1');
        }
        if (!$admissionDateValue || $admissionDateValue->format('Y-m-d') !== $admissionDate
            || (int) $admissionDateValue->format('Y') < 2000 || (int) $admissionDateValue->format('Y') > 2099) {
            flash('error', 'Enter a valid admission date between 2000 and 2099.');
            redirect('admissions.php?add=1');
        }
        if ($guardianEmail !== '' && !filter_var($guardianEmail, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Enter a valid guardian email address or leave it blank.');
            redirect('admissions.php?add=1');
        }
        $identityStmt = db()->prepare(
            'SELECT school_status FROM school_students
             WHERE school_id = ? AND LOWER(TRIM(full_name)) = LOWER(?)
               AND (? IS NULL OR date_of_birth <=> ? OR date_of_birth IS NULL)
             ORDER BY CASE WHEN school_status = "active" THEN 0 ELSE 1 END
             LIMIT 1'
        );
        $normalizedDob = $dob === '' ? null : $dob;
        $identityStmt->execute([$schoolId, $fullName, $normalizedDob, $normalizedDob]);
        $matchingStatus = $identityStmt->fetchColumn();
        if ($matchingStatus !== false) {
            flash(
                'error',
                $matchingStatus === 'active'
                    ? 'A student with this name and date of birth is already active. Review the existing student record instead of registering another.'
                    : 'A matching former student record exists. Reinstate that record from Former students & transfers instead of registering a duplicate.'
            );
            redirect('admissions.php?add=1');
        }
        if (is_string($_POST['class_id'] ?? null) && $_POST['class_id'] !== ''
            && ($classId === false || $classId < 1)) {
            flash('error', 'Choose a valid class belonging to this school.');
            redirect('admissions.php?add=1');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($classId !== false && $classId > 0) {
                school_class_lock_for_student_assignment($pdo, $schoolId, $classId);
            }
            $admissionNumber = school_allocate_admission_number($schoolId, (int) $admissionDateValue->format('Y'));
            $guardianId = null;
            if ($guardianName !== '' || $guardianEmail !== '' || $guardianPhone !== '') {
                $guardianStmt = $pdo->prepare(
                    'INSERT INTO school_guardians (school_id, full_name, email, phone, relationship) VALUES (?, ?, ?, ?, ?)'
                );
                $guardianStmt->execute([
                    $schoolId,
                    $guardianName === '' ? 'Guardian' : $guardianName,
                    $guardianEmail === '' ? null : $guardianEmail,
                    $guardianPhone === '' ? null : $guardianPhone,
                    'Parent',
                ]);
                $guardianId = (int) $pdo->lastInsertId();
            }
            $stmt = $pdo->prepare(
                'INSERT INTO school_students
                 (school_id, class_id, full_name, admission_number, admission_date, date_of_birth, gender, guardian_id, school_status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $schoolId,
                $classId !== false && $classId > 0 ? $classId : null,
                $fullName,
                $admissionNumber,
                $admissionDate,
                $dob === '' ? null : $dob,
                $gender,
                $guardianId,
                'active',
            ]);
            $studentId = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (InvalidArgumentException | LogicException | RuntimeException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', $exception->getMessage());
            redirect('admissions.php?add=1');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Student admission save failed: ' . $exception->getMessage());
            flash('error', 'The student admission could not be saved. Please verify the details and try again.');
            redirect('admissions.php?add=1');
        }
        audit((int) $user['id'], 'school_student_admitted', 'school_id=' . $schoolId . ';student_id=' . $studentId . ';admission_year=' . $admissionDateValue->format('Y'));
        flash('success', 'Student admission recorded. Admission number: ' . $admissionNumber);
        redirect('admissions.php');
    }
}

$classStmt = db()->prepare(
    'SELECT class.id, class.level, class.section, class.academic_year, class.capacity,
            (SELECT COUNT(*) FROM school_students student
             WHERE student.school_id = class.school_id AND student.class_id = class.id
               AND student.school_status = "active") AS active_student_count
     FROM school_classes class
     WHERE class.school_id = ? AND class.status = "active"
     ORDER BY class.academic_year DESC, class.level, class.section'
);
$classStmt->execute([$schoolId]);
$classes = $classStmt->fetchAll();

$admissionStmt = db()->prepare(
    'SELECT ss.id, ss.full_name, ss.admission_number, ss.admission_date, ss.date_of_birth, ss.gender,
            sc.level AS class_level, sc.section AS class_section, g.full_name AS guardian_name, ss.school_status
     FROM school_students ss
     LEFT JOIN school_classes sc ON sc.id = ss.class_id AND sc.school_id = ss.school_id
     LEFT JOIN school_guardians g ON g.id = ss.guardian_id AND g.school_id = ss.school_id
     WHERE ss.school_id = ? AND ss.school_status = "active" ORDER BY ss.created_at DESC'
);
$admissionStmt->execute([$schoolId]);
$admissions = $admissionStmt->fetchAll();
$admissionClassChart = [];
$admissionMonthlyCounts = [];
foreach ($admissions as $admission) {
    $classLabel = $admission['class_level'] !== null
        ? school_class_label([
            'level' => $admission['class_level'],
            'section' => $admission['class_section'],
        ])
        : 'Unassigned';
    $admissionClassChart[$classLabel] = ($admissionClassChart[$classLabel] ?? 0) + 1;
    $admissionDate = (string) ($admission['admission_date'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $admissionDate) === 1) {
        $monthLabel = substr($admissionDate, 0, 7);
        $admissionMonthlyCounts[$monthLabel] = ($admissionMonthlyCounts[$monthLabel] ?? 0) + 1;
    }
}
$admissionTrendChart = [];
$admissionTrendStart = (new DateTimeImmutable('first day of this month'))->modify('-11 months');
for ($month = $admissionTrendStart; $month <= new DateTimeImmutable('first day of this month'); $month = $month->modify('+1 month')) {
    $monthLabel = $month->format('Y-m');
    $admissionTrendChart[$monthLabel] = $admissionMonthlyCounts[$monthLabel] ?? 0;
}
$showAdmissionForm = (string) ($_GET['add'] ?? '') === '1';
$activeAcademicYear = school_active_academic_year($schoolId);
$suggestedAdmissionNumber = '';
$admissionNumberPreviewError = '';
if ($showAdmissionForm) {
    try {
        $suggestedAdmissionNumber = school_preview_admission_number($schoolId, (int) date('Y'));
    } catch (InvalidArgumentException | RuntimeException $exception) {
        $admissionNumberPreviewError = $exception->getMessage();
    }
}

render_header('Admissions');
?>
<section class="panel">
    <p class="eyebrow">ADMISSIONS</p>
    <h1>Student admissions</h1>
    <p class="muted">Register a student with the details grouped below. The server confirms the admission number when you save.</p>
    <?php if ($activeAcademicYear === null): ?><p class="notice notice-error">No active academic year is configured. Set up the school year and current term in <a href="term_setup.php">Academic Years &amp; Terms</a>; historical or backdated admissions may still use their actual admission date.</p><?php endif; ?>
</section>

<section class="report-action-grid" aria-label="Admission actions" data-action-return="admissions.php" data-action-start="<?= $showAdmissionForm ? 'admission-add' : '' ?>">
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('admissions') ?></span>
        <div><h2>Register New Admission</h2><p>Create a new student record using the existing school admission workflow.</p></div>
        <a class="button button-primary" href="admissions.php?add=1#admission-add">Add Student</a>
    </article>
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('students') ?></span>
        <div><h2>Review Admission List</h2><p>View student admissions already recorded for this school.</p></div>
        <a class="button" href="#admission-list" data-action-open="admission-list">View Admissions</a>
    </article>
</section>

<?php if ($showAdmissionForm): ?>
<section class="panel spaced-top" id="admission-add" data-action-view="admission-add">
    <h2>Register new admission</h2>
    <form method="post" action="admissions.php" class="form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="register_admission">
        <fieldset class="admission-form-section">
            <legend>Student information</legend>
            <div class="form-grid two-columns">
                <div><label for="full_name">Student name</label><input id="full_name" name="full_name" type="text" required></div>
                <div><label for="admission_date">Admission date</label><input id="admission_date" name="admission_date" type="date" value="<?= e(date('Y-m-d')) ?>" data-admission-date required></div>
                <div><label for="admission_number">Admission number</label><input id="admission_number" name="admission_number" type="text" value="<?= e($suggestedAdmissionNumber) ?>" readonly aria-describedby="admission-number-help" data-admission-number><small id="admission-number-help" class="muted" aria-live="polite" data-admission-number-status><?= e($admissionNumberPreviewError !== '' ? $admissionNumberPreviewError : ($suggestedAdmissionNumber !== '' ? 'Suggested number; the server confirms the next number when saved.' : 'Loading the next number…')) ?></small></div>
                <div><label for="date_of_birth">Date of birth</label><input id="date_of_birth" name="date_of_birth" type="date"></div>
                <div><label for="gender">Gender</label>
                    <select id="gender" name="gender">
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                        <option value="other">Other</option>
                        <option value="prefer_not_to_say" selected>Prefer not to say</option>
                    </select>
                </div>
            </div>
        </fieldset>
        <fieldset class="admission-form-section">
            <legend>Class and academic year</legend>
            <div class="form-grid two-columns">
                <div>
                <label for="class_id">Assigned class</label>
                <select id="class_id" name="class_id">
                    <option value="">Unassigned</option>
                    <?php foreach ($classes as $class):
                        $classIsFull = $class['capacity'] !== null
                            && (int) $class['active_student_count'] >= (int) $class['capacity'];
                    ?>
                        <option value="<?= (int) $class['id'] ?>" <?= $classIsFull ? 'disabled' : '' ?>><?= e(school_class_label($class)) ?> · <?= e(school_academic_year_label((string) $class['academic_year'])) ?><?= $class['capacity'] !== null ? ' · ' . (int) $class['active_student_count'] . '/' . (int) $class['capacity'] : '' ?><?= $classIsFull ? ' · Full' : '' ?></option>
                    <?php endforeach; ?>
                </select>
                </div>
            </div>
        </fieldset>
        <fieldset class="admission-form-section">
            <legend>Parent or guardian</legend>
            <div class="form-grid two-columns">
                <div><label for="guardian_name">Guardian name</label><input id="guardian_name" name="guardian_name" type="text"></div>
                <div><label for="guardian_email">Guardian email</label><input id="guardian_email" name="guardian_email" type="email" maxlength="254"></div>
                <div><label for="guardian_phone">Guardian phone</label><input id="guardian_phone" name="guardian_phone" type="tel"></div>
            </div>
            <p class="muted">Guardian details are optional. A verified parent portal link must be reviewed separately by the school.</p>
        </fieldset>
        <button class="button button-primary" type="submit">Save admission</button>
        <a class="button button-quiet" href="admissions.php">Cancel</a>
    </form>
</section>
<?php endif; ?>

<section class="dashboard-grid spaced-top" aria-label="Admissions analytics">
    <article class="panel">
        <h2>Recent admissions</h2>
        <p class="muted">Active student admission dates by month for the most recent 12 months. Students without a valid recorded admission date are excluded from this trend.</p>
        <?php render_dashboard_line_chart('Admissions by month · most recent 12 months', $admissionTrendChart, 'students'); ?>
    </article>
    <article class="panel">
        <h2>Active admissions by class</h2>
        <p class="muted">Current active admissions grouped by their assigned class. Unassigned active students remain visible as a separate category.</p>
        <?php render_dashboard_bar_chart('Active admissions by class', $admissionClassChart, 'students'); ?>
    </article>
</section>

<section class="panel spaced-top" id="admission-list" data-action-view="admission-list">
    <h2>Admission list</h2>
    <?php if ($admissions === []): ?>
        <p class="muted">No admissions have been recorded for this school yet.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr><th>Name</th><th>Admission no.</th><th>Admission date</th><th>Class</th><th>Guardian</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php foreach ($admissions as $admission): ?>
                    <tr>
                        <td><a href="student_profile.php?id=<?= (int) $admission['id'] ?>"><?= e((string) $admission['full_name']) ?></a></td>
                        <td><?= e((string) ($admission['admission_number'] ?: '—')) ?></td>
                        <td><?= e((string) ($admission['admission_date'] ?: '—')) ?></td>
                        <td><?= e($admission['class_level'] ? school_class_label(['level' => $admission['class_level'], 'section' => $admission['class_section']]) : 'Unassigned') ?></td>
                        <td><?= e((string) ($admission['guardian_name'] ?: '—')) ?></td>
                        <td><?= e((string) $admission['school_status']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
