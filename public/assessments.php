<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/school_attendance.php';

function school_result_grade(float $score, float $totalMarks): string
{
    return school_assessment_result_grade((int) active_school_context()['id'], $score, $totalMarks);
}

function school_register_score_label(?float $score): string
{
    if ($score === null) {
        return '—';
    }
    return rtrim(rtrim(number_format($score, 2, '.', ''), '0'), '.');
}

function school_assessment_transition_submission(
    PDO $pdo,
    PDOStatement $assessmentUpdate,
    array $assessmentParameters,
    int $schoolId,
    int $assessmentId,
    string $submissionStatus,
    int $reviewerId,
    ?string $correctionReason
): bool {
    if (!in_array($submissionStatus, ['correction_requested', 'reviewed', 'published'], true)) {
        throw new InvalidArgumentException('Unsupported assessment submission transition.');
    }
    try {
        $pdo->beginTransaction();
        $assessmentUpdate->execute($assessmentParameters);
        if ($assessmentUpdate->rowCount() !== 1) {
            $pdo->rollBack();
            return false;
        }
        $historyUpdate = $pdo->prepare(
            'UPDATE school_assessment_submissions
             SET workflow_status = ?, reviewed_by = ?, reviewed_at = UTC_TIMESTAMP(),
                 correction_reason = ?
             WHERE school_id = ? AND assessment_id = ?
             ORDER BY submission_number DESC LIMIT 1'
        );
        $historyUpdate->execute([
            $submissionStatus,
            $reviewerId,
            $correctionReason,
            $schoolId,
            $assessmentId,
        ]);
        if ($historyUpdate->rowCount() !== 1) {
            throw new RuntimeException('The latest submission history row is missing.');
        }
        $pdo->commit();
        return true;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Assessment workflow transition failed for assessment ' . $assessmentId . ': ' . $exception->getMessage());
        return false;
    }
}

$user = require_school_context();
$school = active_school_context();
$schoolId = (int) $school['id'];
require_school_permission($schoolId, 'results.manage');
$pageAcademicPeriod = school_page_academic_period($schoolId);
$schoolRole = (string) ($user['active_school_role'] ?? school_user_role_for_school((int) $user['id'], $schoolId));
$teacherAccount = in_array($schoolRole, ['class_teacher', 'subject_teacher'], true);
$assessmentScope = '';
$assessmentScopeParams = [];
if ($teacherAccount) {
    $assessmentScope = $schoolRole === 'class_teacher'
        ? ' AND (
        EXISTS (
            SELECT 1 FROM teacher_class_assignments scope_class
            WHERE scope_class.school_id = sa.school_id AND scope_class.class_id = sa.class_id
              AND scope_class.academic_year = sa.academic_year
              AND scope_class.teacher_user_id = ?
        ) OR EXISTS (
            SELECT 1 FROM class_subjects scope_assignment
            WHERE scope_assignment.school_id = sa.school_id
              AND scope_assignment.class_id = sa.class_id
              AND scope_assignment.subject_id = sa.subject_id
              AND scope_assignment.teacher_user_id = ?
        ))'
        : ' AND EXISTS (
        SELECT 1 FROM class_subjects scope_assignment
        WHERE scope_assignment.school_id = sa.school_id
          AND scope_assignment.class_id = sa.class_id
          AND scope_assignment.subject_id = sa.subject_id
          AND scope_assignment.teacher_user_id = ?
    )';
    $assessmentScopeParams = $schoolRole === 'class_teacher'
        ? [(int) $user['id'], (int) $user['id']]
        : [(int) $user['id']];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_result_settings') {
        if (!in_array($schoolRole, ['school_proprietor', 'school_headmaster'], true)) {
            http_response_code(403);
            exit('Only the Headmaster or Proprietor may configure grading and ranking rules.');
        }
        $rankingPolicy = (string) ($_POST['ranking_policy'] ?? '');
        $classScoreWeight = filter_var($_POST['class_score_weight'] ?? null, FILTER_VALIDATE_FLOAT);
        $rankingEnabled = isset($_POST['ranking_enabled']) && (string) $_POST['ranking_enabled'] === '1' ? 1 : 0;
        $grades = $_POST['grade'] ?? [];
        $minimums = $_POST['minimum_percent'] ?? [];
        $orders = $_POST['grade_order'] ?? [];
        $remarks = $_POST['grade_remark'] ?? [];
        if (!in_array($rankingPolicy, ['competition', 'dense'], true)
            || $classScoreWeight === false || $classScoreWeight < 0 || $classScoreWeight > 100
            || !is_array($grades) || !is_array($minimums) || !is_array($orders) || !is_array($remarks)
            || count($grades) < 1 || count($grades) > 12
            || count($grades) !== count($minimums) || count($grades) !== count($orders) || count($grades) !== count($remarks)) {
            flash('error', 'Provide a valid ranking policy, score weighting, and complete grading scale.');
            redirect('assessments.php');
        }
        $validatedRules = [];
        $seenGrades = [];
        $seenMinimums = [];
        $seenOrders = [];
        $hasZeroThreshold = false;
        foreach ($grades as $index => $rawGrade) {
            $grade = trim((string) $rawGrade);
            $minimum = filter_var($minimums[$index] ?? null, FILTER_VALIDATE_FLOAT);
            $order = filter_var($orders[$index] ?? null, FILTER_VALIDATE_INT);
            $remark = trim((string) ($remarks[$index] ?? ''));
            $minimumKey = $minimum === false ? '' : number_format((float) $minimum, 2, '.', '');
            if (preg_match('/^[A-Za-z0-9+-]{1,10}$/', $grade) !== 1
                || $minimum === false || $minimum < 0 || $minimum > 100
                || $order === false || $order < 1 || $order > 12
                || strlen($remark) > 120
                || isset($seenGrades[strtolower($grade)])
                || isset($seenMinimums[$minimumKey])
                || isset($seenOrders[$order])) {
                flash('error', 'Grade labels, thresholds, and order must be valid and unique.');
                redirect('assessments.php');
            }
            $seenGrades[strtolower($grade)] = true;
            $seenMinimums[$minimumKey] = true;
            $seenOrders[$order] = true;
            $hasZeroThreshold = $hasZeroThreshold || (float) $minimum === 0.0;
            $validatedRules[] = [$grade, $minimumKey, $order, $remark === '' ? null : $remark];
        }
        if (!$hasZeroThreshold) {
            flash('error', 'The grading scale must include a rule beginning at 0 percent.');
            redirect('assessments.php');
        }
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM school_grade_rules WHERE school_id = ?')->execute([$schoolId]);
            school_insert_grade_rules($pdo, $schoolId, $validatedRules);
            $settingsStmt = $pdo->prepare(
                 'INSERT INTO school_result_settings (school_id, ranking_policy, class_score_weight, ranking_enabled, updated_by)
                  VALUES (?, ?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE ranking_policy = VALUES(ranking_policy), class_score_weight = VALUES(class_score_weight), ranking_enabled = VALUES(ranking_enabled), updated_by = VALUES(updated_by)'
            );
              $settingsStmt->execute([$schoolId, $rankingPolicy, number_format((float) $classScoreWeight, 2, '.', ''), $rankingEnabled, (int) $user['id']]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('School grading configuration update failed: ' . $exception->getMessage());
            flash('error', 'Grading rules could not be saved. The existing configuration was retained.');
            redirect('assessments.php');
        }
        audit((int) $user['id'], 'school_result_settings_updated', 'school_id=' . $schoolId . ';ranking_policy=' . $rankingPolicy . ';ranking_enabled=' . $rankingEnabled . ';class_score_weight=' . number_format((float) $classScoreWeight, 2, '.', ''));
        flash('success', 'School grading, ranking, and score weighting rules saved.');
        redirect('assessments.php');
    }

    if ($action === 'create_assessment') {
        $classId = filter_var($_POST['class_id'] ?? null, FILTER_VALIDATE_INT);
        $subjectId = filter_var($_POST['subject_id'] ?? null, FILTER_VALIDATE_INT);
        $termId = filter_var($_POST['term_id'] ?? null, FILTER_VALIDATE_INT);
        $academicYear = trim((string) ($_POST['academic_year'] ?? ''));
        $assessmentName = trim((string) ($_POST['assessment_name'] ?? ''));
        $totalMarks = (float) ($_POST['total_marks'] ?? 100);

        if ($classId === false || $classId < 1 || $subjectId === false || $subjectId < 1
            || $termId === false || $termId < 1 || $academicYear === ''
            || $assessmentName === '' || strlen($assessmentName) > 120) {
            flash('error', 'Choose an academic year, term, class, examination type, and subject.');
            redirect('assessments.php');
        }
        if (!is_finite($totalMarks) || $totalMarks !== 100.0) {
            flash('error', 'Examination records use a total maximum of 100 marks.');
            redirect('assessments.php');
        }

        $classCheck = db()->prepare('SELECT academic_year, level, academic_stage, status FROM school_classes WHERE id = ? AND school_id = ? LIMIT 1');
        $classCheck->execute([$classId, $schoolId]);
        $classDetails = $classCheck->fetch();
        $subjectCheck = db()->prepare('SELECT 1 FROM school_subjects WHERE id = ? AND school_id = ? LIMIT 1');
        $subjectCheck->execute([$subjectId, $schoolId]);

        if (!$classDetails || !$subjectCheck->fetchColumn()) {
            flash('error', 'The selected class or subject is not valid for this school or your assigned role.');
            redirect('assessments.php');
        }
        $classSubjectStmt = db()->prepare(
            'SELECT 1 FROM class_subjects
             WHERE school_id = ? AND class_id = ? AND subject_id = ? LIMIT 1'
        );
        $classSubjectStmt->execute([$schoolId, $classId, $subjectId]);
        if (!$classSubjectStmt->fetchColumn()) {
            flash('error', 'The selected subject is not configured for this class. Ask an authorized school administrator to update the class-subject assignment.');
            redirect('assessments.php');
        }
        $classYear = (string) $classDetails['academic_year'];
        $classLevel = (string) $classDetails['level'];
        if (!hash_equals($classYear, $academicYear)) {
            flash('error', 'The selected academic year does not match the selected class. Choose the class again.');
            redirect('assessments.php');
        }
        $assessmentTypeCheck = db()->prepare(
            'SELECT 1 FROM school_assessments
             WHERE school_id = ? AND assessment_name = ? LIMIT 1'
        );
        $assessmentTypeCheck->execute([$schoolId, $assessmentName]);
        if (!$assessmentTypeCheck->fetchColumn()) {
            flash('error', 'Choose an examination type already configured for this school.');
            redirect('assessments.php');
        }
        $termCheck = db()->prepare(
            'SELECT t.term_name, y.status AS academic_year_status FROM school_terms t
             JOIN school_academic_years y
               ON y.id = t.academic_year_id AND y.school_id = t.school_id
             WHERE t.id = ? AND t.school_id = ? AND y.academic_year = ? LIMIT 1'
        );
        $termCheck->execute([$termId, $schoolId, $classYear]);
        $term = $termCheck->fetch();
        if (!$term) {
            flash('error', 'Choose a term configured for this class’s academic year. Manage terms in Academic Years & Terms.');
            redirect('assessments.php');
        }
        $termName = (string) $term['term_name'];
        if ($teacherAccount) {
            $isPrimaryClass = school_class_level_is_primary(
                $classLevel,
                (string) $classDetails['academic_stage']
            );
            $directClassTeacher = $schoolRole === 'class_teacher'
                && $isPrimaryClass
                && school_teacher_has_class_assignment((int) $user['id'], $schoolId, $classId, $classYear);
            $subjectTeacherAssigned = school_teacher_has_subject_assignment(
                (int) $user['id'],
                $schoolId,
                $classId,
                $subjectId,
                $classYear
            );
            if (($schoolRole === 'subject_teacher' && $isPrimaryClass)
                || ($isPrimaryClass && $schoolRole === 'class_teacher' && !$directClassTeacher)
                || (!$directClassTeacher && !$subjectTeacherAssigned)) {
                flash('error', 'You can only create assessments for subjects specifically assigned to you in that class.');
                redirect('assessments.php');
            }
        }
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $classLock = $pdo->prepare('SELECT id, status FROM school_classes WHERE id = ? AND school_id = ? FOR UPDATE');
            $classLock->execute([$classId, $schoolId]);
            $lockedClass = $classLock->fetch();
            if (!$lockedClass) {
                throw new RuntimeException('The selected class is no longer available in this school.');
            }
            $existingAssessmentStmt = $pdo->prepare(
                'SELECT assessment.id, assessment.total_marks, assessment.class_score_weight,
                        assessment.exam_score_weight, assessment.workflow_status,
                        (SELECT COUNT(*) FROM assessment_results result
                         WHERE result.school_id = assessment.school_id
                           AND result.assessment_id = assessment.id) AS result_count
                 FROM school_assessments assessment
                  WHERE assessment.school_id = ? AND assessment.class_id = ?
                    AND assessment.subject_id = ? AND assessment.term_name = ?
                    AND assessment.assessment_name = ?
                    AND assessment.academic_year = ?
                    AND (assessment.term_id = ? OR assessment.term_id IS NULL)
                  ORDER BY assessment.created_at DESC, assessment.id DESC LIMIT 1 FOR UPDATE'
            );
            $existingAssessmentStmt->execute([$schoolId, $classId, $subjectId, $termName, $assessmentName, $academicYear, $termId]);
            $existingAssessment = $existingAssessmentStmt->fetch();
            $scoreSettingsStmt = $pdo->prepare(
                'SELECT class_score_weight, updated_by FROM school_result_settings WHERE school_id = ?'
            );
            $scoreSettingsStmt->execute([$schoolId]);
            $scoreSettings = $scoreSettingsStmt->fetch();
            $classScoreWeight = $scoreSettings === false
                || ((float) $scoreSettings['class_score_weight'] === 0.0 && $scoreSettings['updated_by'] === null)
                ? 30.0
                : (float) $scoreSettings['class_score_weight'];
            $examScoreWeight = 100 - $classScoreWeight;

            if ($existingAssessment) {
                $existingAssessmentId = (int) $existingAssessment['id'];
                $reconfiguredEmptyDraft = false;
                if ((int) $existingAssessment['result_count'] === 0
                    && (string) $existingAssessment['workflow_status'] === 'draft'
                    && ((float) $existingAssessment['total_marks'] !== 100.0
                        || (float) $existingAssessment['class_score_weight'] !== $classScoreWeight
                        || (float) $existingAssessment['exam_score_weight'] !== $examScoreWeight)) {
                    $reconfigureStmt = $pdo->prepare(
                        'UPDATE school_assessments
                         SET total_marks = 100.00, class_score_weight = ?, exam_score_weight = ?
                         WHERE id = ? AND school_id = ? AND workflow_status = "draft"
                           AND NOT EXISTS (
                               SELECT 1 FROM assessment_results result
                               WHERE result.school_id = ? AND result.assessment_id = ?
                           )'
                    );
                    $reconfigureStmt->execute([
                        number_format($classScoreWeight, 2, '.', ''),
                        number_format($examScoreWeight, 2, '.', ''),
                        $existingAssessmentId,
                        $schoolId,
                        $schoolId,
                        $existingAssessmentId,
                    ]);
                    $reconfiguredEmptyDraft = $reconfigureStmt->rowCount() === 1;
                }
                if ($reconfiguredEmptyDraft) {
                    audit((int) $user['id'], 'empty_assessment_draft_reconfigured', 'school_id=' . $schoolId . ';assessment_id=' . $existingAssessmentId . ';total_marks=100;class_weight=' . number_format($classScoreWeight, 2, '.', '') . ';exam_weight=' . number_format($examScoreWeight, 2, '.', ''));
                }
                $pdo->commit();
                if ($reconfiguredEmptyDraft) {
                    flash('success', 'The existing empty draft now uses 100 total marks with ' . number_format($classScoreWeight, 2) . '/' . number_format($examScoreWeight, 2) . ' weighting. No student results were changed.');
                } else {
                    flash('success', 'The examination record already exists for this subject, type, term, and academic year. Continue entering marks in that record.');
                }
                redirect('assessments.php?assessment_id=' . (int) $existingAssessmentId);
            }
            $academicYearStatusStmt = $pdo->prepare(
                'SELECT year.status
                 FROM school_terms term
                 JOIN school_academic_years year
                   ON year.id = term.academic_year_id AND year.school_id = term.school_id
                 WHERE term.id = ? AND term.school_id = ? AND year.academic_year = ?
                 LIMIT 1 FOR UPDATE'
            );
            $academicYearStatusStmt->execute([$termId, $schoolId, $classYear]);
            $academicYearStatus = $academicYearStatusStmt->fetchColumn();
            if ((string) $lockedClass['status'] !== 'active' || $academicYearStatus !== 'open') {
                $pdo->rollBack();
                flash('error', 'Archived classes and closed academic years can only be used to reopen an existing examination; no new examination was created.');
                redirect('assessments.php');
            }
            $rosterStmt = $pdo->prepare(
                'SELECT SUM(CASE WHEN student.school_status = "active" THEN 1 ELSE 0 END) AS active_students,
                        SUM(CASE WHEN student.school_status <> "active" THEN 1 ELSE 0 END) AS inactive_students,
                        COUNT(*) AS assigned_students
                 FROM school_students student
                 JOIN school_classes class
                   ON class.id = student.class_id AND class.school_id = student.school_id
                 WHERE student.school_id = ? AND student.class_id = ?
                   AND class.academic_year = ? AND class.status = "active"'
            );
            $rosterStmt->execute([$schoolId, $classId, $classYear]);
            $roster = $rosterStmt->fetch() ?: ['active_students' => 0, 'inactive_students' => 0, 'assigned_students' => 0];
            $activeRosterStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM school_students student
                 JOIN school_classes class
                   ON class.id = student.class_id AND class.school_id = student.school_id
                 WHERE student.school_id = ? AND student.class_id = ? AND class.academic_year = ?
                   AND class.status = "active" AND student.school_status = "active"'
            );
            $activeRosterStmt->execute([$schoolId, $classId, $classYear]);
            $activeStudents = (int) $activeRosterStmt->fetchColumn();
            if ($activeStudents === 0) {
                error_log(
                    'Assessment roster empty: school_id=' . $schoolId
                    . ';class_id=' . $classId . ';academic_year=' . $classYear
                    . ';assigned_students=' . (int) $roster['assigned_students']
                    . ';inactive_students=' . (int) ($roster['inactive_students'] ?? 0)
                );
                $pdo->rollBack();
                flash(
                    'error',
                    'No active students are assigned to this class for ' . $classYear
                    . '. The class has ' . (int) $roster['assigned_students']
                    . ' student record(s), including ' . (int) ($roster['inactive_students'] ?? 0)
                    . ' inactive. Check the existing student-to-class assignments and student statuses; no examination record was created.'
                );
                redirect('assessments.php');
            }
            $stmt = $pdo->prepare(
                'INSERT INTO school_assessments
                 (school_id, class_id, academic_year, term_id, subject_id, assessment_name, term_name,
                  total_marks, class_score_weight, exam_score_weight)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $schoolId,
                $classId,
                $classYear,
                $termId,
                $subjectId,
                $assessmentName,
                $termName,
                number_format($totalMarks, 2, '.', ''),
                number_format($classScoreWeight, 2, '.', ''),
                number_format($examScoreWeight, 2, '.', ''),
            ]);
            $assessmentId = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Subject examination record creation failed: ' . $exception->getMessage());
            flash('error', 'The subject examination record could not be opened. No partial record was created.');
            redirect('assessments.php');
        }
        audit((int) $user['id'], 'subject_examination_record_opened', 'school_id=' . $schoolId . ';assessment_id=' . $assessmentId . ';class_id=' . $classId . ';subject_id=' . $subjectId . ';term=' . $termName);
        flash('success', 'Subject examination record opened.');
        redirect('assessments.php?assessment_id=' . $assessmentId);
    }

    if ($action === 'publish_results') {
        if (!in_array($schoolRole, ['school_proprietor', 'school_headmaster'], true)) {
            http_response_code(403);
            exit('Only the Headmaster or Proprietor may publish reviewed results.');
        }
        $assessmentId = filter_var($_POST['assessment_id'] ?? null, FILTER_VALIDATE_INT);
        if ($assessmentId === false || $assessmentId < 1) {
            flash('error', 'Choose a valid assessment to publish.');
            redirect('assessments.php');
        }
        $publishStmt = db()->prepare(
            'SELECT sa.id, sa.class_id, sa.published_at, sa.workflow_status
             FROM school_assessments sa
             WHERE sa.id = ? AND sa.school_id = ?' . $assessmentScope . ' LIMIT 1'
        );
        $publishStmt->execute(array_merge([$assessmentId, $schoolId], $assessmentScopeParams));
        $publishAssessment = $publishStmt->fetch();
        if (!$publishAssessment) {
            flash('error', 'The selected assessment does not exist in this school.');
            redirect('assessments.php');
        }
        if ($publishAssessment['published_at'] !== null) {
            flash('error', 'This assessment has already been published.');
            redirect('assessments.php?assessment_id=' . $assessmentId);
        }
        if ((string) $publishAssessment['workflow_status'] !== 'reviewed') {
            flash('error', 'Results must be reviewed before they can be published.');
            redirect('assessments.php?assessment_id=' . $assessmentId);
        }
        $countsStmt = db()->prepare(
            'SELECT
                (SELECT COUNT(*) FROM school_students
                 WHERE school_id = ? AND class_id = ? AND school_status = "active") AS active_students,
                (SELECT COUNT(*) FROM assessment_results ar
                 JOIN school_students ss ON ss.id = ar.student_id AND ss.school_id = ar.school_id
                 WHERE ar.school_id = ? AND ar.assessment_id = ?
                   AND ss.class_id = ? AND ss.school_status = "active") AS recorded_results'
        );
        $countsStmt->execute([
            $schoolId,
            (int) $publishAssessment['class_id'],
            $schoolId,
            $assessmentId,
            (int) $publishAssessment['class_id'],
        ]);
        $resultCounts = $countsStmt->fetch();
        if ((int) $resultCounts['active_students'] === 0
            || (int) $resultCounts['active_students'] !== (int) $resultCounts['recorded_results']) {
            flash('error', 'Record a result for every active student in the class before publishing.');
            redirect('assessments.php?assessment_id=' . $assessmentId);
        }
        $publishUpdate = db()->prepare(
            'UPDATE school_assessments sa SET published_at = NOW(), published_by = ?, workflow_status = "published"
             WHERE sa.id = ? AND sa.school_id = ? AND sa.published_at IS NULL' . $assessmentScope
        );
        if (!school_assessment_transition_submission(
            db(),
            $publishUpdate,
            array_merge([(int) $user['id'], $assessmentId, $schoolId], $assessmentScopeParams),
            $schoolId,
            $assessmentId,
            'published',
            (int) $user['id'],
            null
        )) {
            flash('error', 'Results could not be published safely. Refresh and try again; no partial workflow change was saved.');
            redirect('assessments.php?assessment_id=' . $assessmentId);
        }
        $submissionInfo = db()->prepare(
            'SELECT class_id, subject_id FROM school_assessments WHERE id = ? AND school_id = ?'
        );
        $submissionInfo->execute([$assessmentId, $schoolId]);
        $publishedInfo = $submissionInfo->fetch();
        if ($publishedInfo) {
            $classTeacherStmt = db()->prepare(
                'SELECT teacher_user_id FROM teacher_class_assignments
                 WHERE school_id = ? AND class_id = ?
                   AND academic_year = (SELECT academic_year FROM school_classes WHERE id = ? AND school_id = ?)
                 LIMIT 1'
            );
            $classTeacherStmt->execute([$schoolId, $publishedInfo['class_id'], $publishedInfo['class_id'], $schoolId]);
            $classTeacherId = $classTeacherStmt->fetchColumn();
            if ($classTeacherId !== false && (int) $classTeacherId !== (int) $user['id']) {
                school_notification_create(
                    $schoolId,
                    (int) $classTeacherId,
                    (int) $user['id'],
                    'assessment-published:' . $assessmentId,
                    'assessment',
                    'Assessment results published',
                    'Reviewed assessment results have been published.',
                    'assessments.php?assessment_id=' . $assessmentId,
                    (int) $publishedInfo['class_id'],
                    (int) $publishedInfo['subject_id'],
                    $assessmentId
                );
            }
        }
        flash('success', 'Assessment results are now published to students.');
        redirect('assessments.php?assessment_id=' . $assessmentId);
    }

    if ($action === 'unpublish_results' || $action === 'reopen_reviewed_results') {
        $assessmentId = filter_var($_POST['assessment_id'] ?? null, FILTER_VALIDATE_INT);
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if (!in_array($schoolRole, ['school_proprietor', 'school_headmaster'], true)) {
            http_response_code(403);
            exit('Only a proprietor or headmaster can reopen reviewed or published results.');
        }
        if ($assessmentId === false || $assessmentId < 1 || $reason === '' || strlen($reason) > 500) {
            flash('error', 'Choose a valid assessment and provide a correction reason (up to 500 characters).');
            redirect('assessments.php');
        }
        $unpublishStmt = db()->prepare(
            'UPDATE school_assessments SET published_at = NULL, published_by = NULL,
                 workflow_status = "correction_requested", correction_reason = ?
             WHERE id = ? AND school_id = ?
               AND (published_at IS NOT NULL OR workflow_status = "reviewed")'
        );
        if (!school_assessment_transition_submission(
            db(),
            $unpublishStmt,
            array_merge([$reason, $assessmentId, $schoolId], $teacherAccount ? $assessmentScopeParams : []),
            $schoolId,
            $assessmentId,
            'correction_requested',
            (int) $user['id'],
            $reason
        )) {
            flash('error', 'The results could not be reopened safely. Refresh and try again; no partial workflow change was saved.');
            redirect('assessments.php?assessment_id=' . $assessmentId);
        }
        audit((int) $user['id'], 'assessment_results_reopened_for_correction', 'school_id=' . $schoolId . ';assessment_id=' . $assessmentId . ';reason=' . $reason);
        $assessmentTeacherStmt = db()->prepare(
            'SELECT sa.class_id, sa.subject_id, cs.teacher_user_id
             FROM school_assessments sa LEFT JOIN class_subjects cs
               ON cs.school_id = sa.school_id AND cs.class_id = sa.class_id
              AND cs.subject_id = sa.subject_id
             WHERE sa.id = ? AND sa.school_id = ? LIMIT 1'
        );
        $assessmentTeacherStmt->execute([$assessmentId, $schoolId]);
        $assessmentTeacher = $assessmentTeacherStmt->fetch();
        if ($assessmentTeacher && $assessmentTeacher['teacher_user_id'] !== null) {
            school_notification_create(
                $schoolId,
                (int) $assessmentTeacher['teacher_user_id'],
                (int) $user['id'],
                'assessment-correction:' . $assessmentId,
                'assessment',
                'Correction requested for published results',
                $reason,
                'assessments.php?assessment_id=' . $assessmentId,
                (int) $assessmentTeacher['class_id'],
                (int) $assessmentTeacher['subject_id'],
                $assessmentId
            );
        }
        flash('success', 'Results are reopened for correction. The saved marks are available to edit.');
        redirect('assessments.php?assessment_id=' . $assessmentId);
    }

    if (in_array($action, ['review_results', 'request_correction'], true)) {
        $assessmentId = filter_var($_POST['assessment_id'] ?? null, FILTER_VALIDATE_INT);
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($assessmentId === false || $assessmentId < 1
            || ($action === 'request_correction' && ($reason === '' || strlen($reason) > 500))) {
            flash('error', 'Choose a valid assessment and provide a correction reason when requesting changes.');
            redirect('assessments.php');
        }
        $reviewAssessmentStmt = db()->prepare(
            'SELECT id, class_id, subject_id, workflow_status, submitted_by
             FROM school_assessments WHERE id = ? AND school_id = ? LIMIT 1'
        );
        $reviewAssessmentStmt->execute([$assessmentId, $schoolId]);
        $reviewAssessment = $reviewAssessmentStmt->fetch();
        if (!$reviewAssessment || !in_array((string) $reviewAssessment['workflow_status'], ['submitted', 'corrected'], true)) {
            flash('error', 'Only submitted results can be reviewed or returned for correction.');
            redirect('assessments.php?assessment_id=' . $assessmentId);
        }
        $classDetailsStmt = db()->prepare(
            'SELECT level, academic_year, academic_stage
             FROM school_classes
             WHERE id = ? AND school_id = ?
             LIMIT 1'
        );
        $classDetailsStmt->execute([(int) $reviewAssessment['class_id'], $schoolId]);
        $classDetails = $classDetailsStmt->fetch();
        if (!$classDetails) {
            throw new RuntimeException('The assessment class could not be found in this school.');
        }
        $isJhsAssessment = school_class_level_is_jhs(
            (string) $classDetails['level'],
            (string) $classDetails['academic_stage']
        );
        $isAssignedJhsClassTeacher = $schoolRole === 'class_teacher'
            && $isJhsAssessment
            && (int) $reviewAssessment['submitted_by'] !== (int) $user['id']
            && school_teacher_has_class_assignment(
                (int) $user['id'],
                $schoolId,
                (int) $reviewAssessment['class_id'],
                (string) $classDetails['academic_year']
            );
        if (!in_array($schoolRole, ['school_proprietor', 'school_headmaster'], true)
            && !$isAssignedJhsClassTeacher) {
            http_response_code(403);
            exit('Only the assigned JHS Class Teacher, Headmaster, or Proprietor may review assessment submissions.');
        }
        if ($action === 'review_results') {
            $countsStmt = db()->prepare(
                'SELECT
                    (SELECT COUNT(*) FROM school_students
                     WHERE school_id = ? AND class_id = ? AND school_status = "active") AS active_students,
                    (SELECT COUNT(*) FROM assessment_results result
                     JOIN school_students student ON student.id = result.student_id AND student.school_id = result.school_id
                     WHERE result.school_id = ? AND result.assessment_id = ?
                       AND student.class_id = ? AND student.school_status = "active") AS recorded_results'
            );
            $countsStmt->execute([$schoolId, $reviewAssessment['class_id'], $schoolId, $assessmentId, $reviewAssessment['class_id']]);
            $counts = $countsStmt->fetch();
            if ((int) $counts['active_students'] === 0 || (int) $counts['active_students'] !== (int) $counts['recorded_results']) {
                flash('error', 'All active students must have marks before results can be reviewed.');
                redirect('assessments.php?assessment_id=' . $assessmentId);
            }
            $reviewStmt = db()->prepare(
                'UPDATE school_assessments SET workflow_status = "reviewed", reviewed_by = ?, reviewed_at = UTC_TIMESTAMP(),
                     correction_reason = NULL
                 WHERE id = ? AND school_id = ? AND workflow_status IN ("submitted", "corrected")'
            );
            if (!school_assessment_transition_submission(
                db(),
                $reviewStmt,
                [(int) $user['id'], $assessmentId, $schoolId],
                $schoolId,
                $assessmentId,
                'reviewed',
                (int) $user['id'],
                null
            )) {
                flash('error', 'The assessment could not be reviewed safely. Refresh and try again; no partial workflow change was saved.');
                redirect('assessments.php?assessment_id=' . $assessmentId);
            }
            audit((int) $user['id'], 'assessment_results_reviewed', 'school_id=' . $schoolId . ';assessment_id=' . $assessmentId);
            $reviewContextStmt = db()->prepare(
                'SELECT sa.class_id, sa.subject_id, sa.assessment_name, sa.term_name, sa.academic_year,
                        subject.name AS subject_name
                 FROM school_assessments sa
                 JOIN school_classes class ON class.id = sa.class_id AND class.school_id = sa.school_id
                 JOIN school_subjects subject ON subject.id = sa.subject_id AND subject.school_id = sa.school_id
                 WHERE sa.id = ? AND sa.school_id = ?'
            );
            $reviewContextStmt->execute([$assessmentId, $schoolId]);
            $reviewContext = $reviewContextStmt->fetch();
            if ($reviewContext) {
                $group = school_assessment_group(
                    $schoolId,
                    (int) $reviewContext['class_id'],
                    (string) $reviewContext['academic_year'],
                    (string) $reviewContext['term_name'],
                    (string) $reviewContext['assessment_name']
                );
                if ($group['ready_for_report_cards']) {
                    $classTeacherStmt = db()->prepare(
                        'SELECT teacher_user_id FROM teacher_class_assignments
                         WHERE school_id = ? AND class_id = ? AND academic_year = ? LIMIT 1'
                    );
                    $classTeacherStmt->execute([$schoolId, $reviewContext['class_id'], $reviewContext['academic_year']]);
                    $classTeacherId = $classTeacherStmt->fetchColumn();
                    if ($classTeacherId !== false && (int) $classTeacherId !== (int) $user['id']) {
                        school_notification_create(
                            $schoolId,
                            (int) $classTeacherId,
                            (int) $user['id'],
                            'report-cards-ready:' . hash(
                                'sha256',
                                $reviewContext['class_id'] . '|' . $reviewContext['term_name'] . '|' . $reviewContext['assessment_name']
                            ),
                            'report_card',
                            'Class results ready for report cards',
                            'All required subject marks are complete and reviewed. Report cards can now be prepared.',
                            'report_cards.php?generate=1&prepare_class_id=' . (int) $reviewContext['class_id']
                                . '&prepare_term=' . rawurlencode((string) $reviewContext['term_name'])
                                . '&prepare_assessment=' . rawurlencode((string) $reviewContext['assessment_name']),
                            (int) $reviewContext['class_id']
                        );
                    }
                }
                $teacherStmt = db()->prepare(
                    'SELECT teacher_user_id FROM class_subjects
                     WHERE school_id = ? AND class_id = ? AND subject_id = ? LIMIT 1'
                );
                $teacherStmt->execute([$schoolId, $reviewContext['class_id'], $reviewContext['subject_id']]);
                $submittedTeacher = $teacherStmt->fetchColumn();
                if ($submittedTeacher !== false) {
                    school_notification_create(
                        $schoolId,
                        (int) $submittedTeacher,
                        (int) $user['id'],
                        'assessment-reviewed:' . $assessmentId,
                        'assessment',
                        'Assessment marks reviewed',
                        'The submitted marks have been reviewed. They remain unpublished until an authorized administrator publishes them.',
                        'assessments.php?assessment_id=' . $assessmentId,
                        (int) $reviewContext['class_id'],
                        (int) $reviewContext['subject_id'],
                        $assessmentId
                    );
                }
            }
            flash('success', 'Assessment results reviewed. An authorized administrator may publish them.');
        } else {
            $requestStmt = db()->prepare(
                'UPDATE school_assessments SET workflow_status = "correction_requested", correction_reason = ?,
                     reviewed_by = ?, reviewed_at = UTC_TIMESTAMP()
                 WHERE id = ? AND school_id = ? AND workflow_status IN ("submitted", "corrected")'
            );
            if (!school_assessment_transition_submission(
                db(),
                $requestStmt,
                [$reason, (int) $user['id'], $assessmentId, $schoolId],
                $schoolId,
                $assessmentId,
                'correction_requested',
                (int) $user['id'],
                $reason
            )) {
                flash('error', 'The correction request could not be saved safely. Refresh and try again; no partial workflow change was saved.');
                redirect('assessments.php?assessment_id=' . $assessmentId);
            }
            audit((int) $user['id'], 'assessment_correction_requested', 'school_id=' . $schoolId . ';assessment_id=' . $assessmentId . ';reason=' . $reason);
            $assignedTeacherStmt = db()->prepare(
                'SELECT teacher_user_id FROM class_subjects
                 WHERE school_id = ? AND class_id = ? AND subject_id = ? LIMIT 1'
            );
            $assignedTeacherStmt->execute([$schoolId, $reviewAssessment['class_id'], $reviewAssessment['subject_id']]);
            $assignedTeacherId = $assignedTeacherStmt->fetchColumn();
            if ($assignedTeacherId !== false) {
                school_notification_create(
                    $schoolId,
                    (int) $assignedTeacherId,
                    (int) $user['id'],
                    'assessment-correction:' . $assessmentId,
                    'assessment',
                    'Correction requested for assessment marks',
                    $reason,
                    'assessments.php?assessment_id=' . $assessmentId,
                    (int) $reviewAssessment['class_id'],
                    (int) $reviewAssessment['subject_id'],
                    $assessmentId
                );
            }
            flash('success', 'The correction request was recorded and the assigned teacher was notified.');
        }
        redirect('assessments.php?assessment_id=' . $assessmentId);
    }

    if (in_array($action, ['save_results', 'save_results_continue', 'submit_results'], true)) {
        $assessmentId = filter_var($_POST['assessment_id'] ?? null, FILTER_VALIDATE_INT);
        if ($assessmentId === false || $assessmentId < 1) {
            flash('error', 'Choose a valid assessment to save results for.');
            redirect('assessments.php');
        }

        $assessmentStmt = db()->prepare(
                'SELECT sa.id, sa.class_id, sa.subject_id, sa.academic_year, sa.total_marks, sa.class_score_weight,
                    sa.exam_score_weight, sa.published_at, sa.workflow_status, sa.correction_reason,
                    sa.submitted_to_user_id
             FROM school_assessments sa
             WHERE sa.id = ? AND sa.school_id = ?' . $assessmentScope . '
             LIMIT 1'
        );
        $assessmentStmt->execute(array_merge([$assessmentId, $schoolId], $assessmentScopeParams));
        $assessment = $assessmentStmt->fetch();

        if (!$assessment) {
            flash('error', 'The selected assessment does not exist in this school.');
            redirect('assessments.php');
        }
        $canManageAssessmentCorrections = in_array($schoolRole, ['school_proprietor', 'school_headmaster'], true);
        $classContextStmt = db()->prepare('SELECT academic_year, level, academic_stage FROM school_classes WHERE id = ? AND school_id = ?');
        $classContextStmt->execute([(int) $assessment['class_id'], $schoolId]);
        $classContext = $classContextStmt->fetch();
        $assignmentYear = $assessment['academic_year'] ?? false;
        $editableWorkflowStates = ['draft', 'correction_requested', 'corrected'];
        $approvedWorkflowStates = ['submitted', 'reviewed', 'published'];
        $assessmentRosterCountsStmt = db()->prepare(
            'SELECT COUNT(*) AS eligible_students, COUNT(result.id) AS recorded_results
             FROM school_students student
             JOIN school_classes class
               ON class.id = student.class_id AND class.school_id = student.school_id
             LEFT JOIN assessment_results result
               ON result.school_id = student.school_id
              AND result.assessment_id = ? AND result.student_id = student.id
             WHERE student.school_id = ? AND student.class_id = ?
               AND student.school_status = "active" AND class.status = "active"
               AND class.academic_year = ?'
        );
        $assessmentRosterCountsStmt->execute([
            $assessmentId,
            $schoolId,
            (int) $assessment['class_id'],
            (string) $assignmentYear,
        ]);
        $assessmentRosterCounts = $assessmentRosterCountsStmt->fetch() ?: [];
        $hasIncompleteRoster = (int) ($assessmentRosterCounts['eligible_students'] ?? 0) > 0
            && (int) ($assessmentRosterCounts['recorded_results'] ?? 0)
                < (int) ($assessmentRosterCounts['eligible_students'] ?? 0);
        $isIncompleteSubmittedAssessment = in_array(
            (string) $assessment['workflow_status'],
            $approvedWorkflowStates,
            true
        ) && $hasIncompleteRoster;
        $isIncompleteEditingWorkflow = in_array((string) $assessment['workflow_status'], $editableWorkflowStates, true)
            || $isIncompleteSubmittedAssessment;
        $isPrimaryAssessment = $classContext !== false
        && school_class_level_is_primary(
            (string) $classContext['level'],
            (string) $classContext['academic_stage']
        );
        $isDirectClassTeacher = false;
        if ($teacherAccount) {
            $isDirectClassTeacher = $schoolRole === 'class_teacher'
                && ($isPrimaryAssessment || $isIncompleteEditingWorkflow)
                && school_teacher_has_class_assignment(
                    (int) $user['id'],
                    $schoolId,
                    (int) $assessment['class_id'],
                    (string) $assignmentYear
                );
            if ($isDirectClassTeacher) {
                $classSubjectCheck = db()->prepare(
                    'SELECT 1 FROM class_subjects
                     WHERE school_id = ? AND class_id = ? AND subject_id = ? LIMIT 1'
                );
                $classSubjectCheck->execute([$schoolId, (int) $assessment['class_id'], (int) $assessment['subject_id']]);
                $isDirectClassTeacher = (bool) $classSubjectCheck->fetchColumn();
            }
            $hasSubjectAssignment = $assignmentYear !== false && school_teacher_has_subject_assignment(
                (int) $user['id'],
                $schoolId,
                (int) $assessment['class_id'],
                (int) $assessment['subject_id'],
                (string) $assignmentYear
            );
            if ($classContext === false
                || ($schoolRole === 'subject_teacher' && $isPrimaryAssessment)
                || ($schoolRole === 'class_teacher' && $isPrimaryAssessment && !$isDirectClassTeacher)
                || (!$isDirectClassTeacher && !$hasSubjectAssignment)) {
                http_response_code(403);
                exit('You may only enter marks for an authorized class and subject assignment.');
            }
        }
        if ($isIncompleteEditingWorkflow && $schoolRole === 'school_headmaster') {
            http_response_code(403);
            exit('Headmasters cannot edit incomplete examination records through this workflow. The assigned Class Teacher must complete them.');
        }
        if ($isIncompleteEditingWorkflow && $schoolRole === 'class_teacher' && !$isDirectClassTeacher) {
            http_response_code(403);
            exit('You may only edit incomplete examination records for a class assigned to you in this academic year.');
        }
        $canClassTeacherCorrectIncompleteAssessment = $isIncompleteSubmittedAssessment
            && $schoolRole === 'class_teacher'
            && $isDirectClassTeacher;
        if ($assessment['published_at'] !== null
            && !$canManageAssessmentCorrections
            && !$canClassTeacherCorrectIncompleteAssessment) {
            flash('error', 'Published results can only be corrected by the assigned Class Teacher when active student marks are incomplete. Otherwise contact an authorized school administrator.');
            redirect('assessments.php?assessment_id=' . $assessmentId);
        }
        $canCorrectApprovedAssessment = $canManageAssessmentCorrections
            && in_array((string) $assessment['workflow_status'], $approvedWorkflowStates, true);
        if (!in_array((string) $assessment['workflow_status'], $editableWorkflowStates, true)
            && !$canCorrectApprovedAssessment
            && !($isIncompleteSubmittedAssessment && $schoolRole === 'class_teacher' && $isDirectClassTeacher)) {
            flash('error', 'Submitted results are locked until an administrator requests correction.');
            redirect('assessments.php?assessment_id=' . $assessmentId);
        }

        $results = $_POST['student_result'] ?? [];
        $classScores = $_POST['class_score'] ?? [];
        $examScores = $_POST['exam_score'] ?? [];
        if (!is_array($results) || !is_array($classScores) || !is_array($examScores)) {
            flash('error', 'Student result data is invalid.');
            redirect('assessments.php?assessment_id=' . $assessmentId);
        }

        $totalMarks = (float) $assessment['total_marks'];
        $classWeight = (float) $assessment['class_score_weight'];
        $examWeight = (float) $assessment['exam_score_weight'];
        if ($classWeight < 0 || $examWeight < 0 || abs($classWeight + $examWeight - 100) > 0.001) {
            flash('error', 'The assessment has invalid score weighting. Ask an authorized administrator to review the configuration.');
            redirect('assessments.php?assessment_id=' . $assessmentId);
        }
        $classScoreMaximum = $totalMarks * $classWeight / 100;
        $examScoreMaximum = $totalMarks * $examWeight / 100;
        $studentList = db()->prepare(
            'SELECT student.id, student.full_name
             FROM school_students student
             JOIN school_classes class
               ON class.id = student.class_id AND class.school_id = student.school_id
             WHERE student.school_id = ? AND student.class_id = ?
               AND class.academic_year = ? AND class.status = "active"
               AND student.school_status = "active"
             ORDER BY student.full_name ASC'
        );
        $studentList->execute([$schoolId, (int) $assessment['class_id'], (string) $assignmentYear]);
        $students = $studentList->fetchAll();
        if ($students === []) {
            flash('error', 'No enrolled students were found for the selected class and academic year. Check student enrolment records.');
            redirect('assessments.php?assessment_id=' . $assessmentId);
        }
        $missed = false;
        $firstIncompleteStudentId = null;
        $assignedClassTeacherId = null;
        $changedMarks = false;
        $gradeRuleCountStmt = db()->prepare('SELECT COUNT(*) FROM school_grade_rules WHERE school_id = ?');
        $gradeRuleCountStmt->execute([$schoolId]);
        $gradingConfigured = (int) $gradeRuleCountStmt->fetchColumn() > 0;
        if (!$gradingConfigured && $action === 'submit_results') {
            flash('error', 'Configure the school grading scale before submitting examination results.');
            redirect('assessments.php?assessment_id=' . $assessmentId);
        }
        if ($action === 'submit_results') {
            $classTeacherStmt = db()->prepare(
                'SELECT assignment.teacher_user_id
                 FROM teacher_class_assignments assignment
                 JOIN school_memberships membership
                   ON membership.school_id = assignment.school_id
                  AND membership.user_id = assignment.teacher_user_id
                  AND membership.role_name = "class_teacher" AND membership.status = "active"
                 JOIN users teacher ON teacher.id = assignment.teacher_user_id AND teacher.status = "active"
                 WHERE assignment.school_id = ? AND assignment.class_id = ? AND assignment.academic_year = ?
                 LIMIT 1'
            );
            $classTeacherStmt->execute([$schoolId, (int) $assessment['class_id'], (string) $assignmentYear]);
            $assignedClassTeacherId = $classTeacherStmt->fetchColumn();
            if ($assignedClassTeacherId === false) {
                flash('error', 'Submission is blocked because no active Class Teacher is assigned to this class and academic year. Ask an authorized administrator to update the assignment.');
                redirect('assessments.php?assessment_id=' . $assessmentId);
            }
            $assignedClassTeacherId = (int) $assignedClassTeacherId;
        }
        if ($action === 'submit_results'
            && (count($classScores) !== count($students) || count($examScores) !== count($students))) {
            flash('error', 'Enter a mark for every active student before submitting results.');
            redirect('assessments.php?assessment_id=' . $assessmentId);
        }

        $revisionReason = trim((string) ($_POST['revision_reason'] ?? ''));
        if ($revisionReason === '') {
            $revisionReason = trim((string) ($assessment['correction_reason'] ?? ''));
        }
        if (strlen($revisionReason) > 500) {
            flash('error', 'The correction reason cannot exceed 500 characters.');
            redirect('assessments.php?assessment_id=' . $assessmentId);
        }
        $pdo = db();
        $pdo->beginTransaction();
        $revisionStmt = $pdo->prepare(
            'INSERT INTO school_assessment_result_revisions
             (school_id, assessment_id, student_id, changed_by, previous_score, new_score,
              previous_class_score, new_class_score, previous_exam_score, new_exam_score,
              previous_remarks, new_remarks, reason)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        try {
            $lockedAssessmentStmt = $pdo->prepare(
                'SELECT workflow_status, published_at FROM school_assessments
                 WHERE id = ? AND school_id = ? FOR UPDATE'
            );
            $lockedAssessmentStmt->execute([$assessmentId, $schoolId]);
            $lockedAssessment = $lockedAssessmentStmt->fetch();
            $lockedWorkflowStatus = (string) ($lockedAssessment['workflow_status'] ?? '');
            $isLockedApprovedCorrection = $canManageAssessmentCorrections
                && in_array($lockedWorkflowStatus, $approvedWorkflowStates, true);
            $lockedRosterCountsStmt = $pdo->prepare(
                'SELECT COUNT(*) AS eligible_students, COUNT(result.id) AS recorded_results
                 FROM school_students student
                 JOIN school_classes class
                   ON class.id = student.class_id AND class.school_id = student.school_id
                 LEFT JOIN assessment_results result
                   ON result.school_id = student.school_id
                  AND result.assessment_id = ? AND result.student_id = student.id
                 WHERE student.school_id = ? AND student.class_id = ?
                   AND student.school_status = "active" AND class.status = "active"
                   AND class.academic_year = ?'
            );
            $lockedRosterCountsStmt->execute([
                $assessmentId,
                $schoolId,
                (int) $assessment['class_id'],
                (string) $assignmentYear,
            ]);
            $lockedRosterCounts = $lockedRosterCountsStmt->fetch() ?: [];
            $isLockedIncompleteClassTeacherEdit = $schoolRole === 'class_teacher'
                && $isDirectClassTeacher
                && in_array($lockedWorkflowStatus, $approvedWorkflowStates, true)
                && (int) ($lockedRosterCounts['eligible_students'] ?? 0) > 0
                && (int) ($lockedRosterCounts['recorded_results'] ?? 0)
                    < (int) ($lockedRosterCounts['eligible_students'] ?? 0);
            if (!in_array($lockedWorkflowStatus, $editableWorkflowStates, true)
                && !$isLockedApprovedCorrection
                && !$isLockedIncompleteClassTeacherEdit) {
                throw new RuntimeException('The assessment was submitted or reviewed by another request. Refresh before editing.');
            }
            foreach ($students as $student) {
            $studentId = (int) $student['id'];
            $rawClassScore = $classScores[(string) $studentId] ?? ($classWeight === 0.0 ? '0' : null);
            $rawExamScore = $examScores[(string) $studentId] ?? ($results[(string) $studentId] ?? ($examWeight === 0.0 ? '0' : null));
            if (is_array($rawClassScore) || is_object($rawClassScore)
                || is_array($rawExamScore) || is_object($rawExamScore)) {
                throw new InvalidArgumentException('Student marks must be submitted as numeric values.');
            }
            $classMarkBlank = $rawClassScore === null
                || (is_string($rawClassScore) && trim($rawClassScore) === '');
            $examMarkBlank = $rawExamScore === null
                || (is_string($rawExamScore) && trim($rawExamScore) === '');
            if ($classMarkBlank || $examMarkBlank) {
                $firstIncompleteStudentId ??= $studentId;
                if ($action === 'submit_results') {
                    $missed = true;
                }
                continue;
            }

            $classScore = school_assessment_mark($rawClassScore, $classScoreMaximum);
            $examScore = school_assessment_mark($rawExamScore, $examScoreMaximum);

            $score = round($classScore + $examScore, 2);
            $grading = $gradingConfigured
                ? school_grade_rule_for_percent($schoolId, ($score / $totalMarks) * 100)
                : null;
            $grade = $grading === null ? null : (string) $grading['grade'];
            $remarks = $grading === null ? '' : (string) $grading['remark'];
            $oldResultStmt = $pdo->prepare(
                'SELECT score, class_score, exam_score, remarks FROM assessment_results
                 WHERE school_id = ? AND assessment_id = ? AND student_id = ? LIMIT 1'
            );
            $oldResultStmt->execute([$schoolId, $assessmentId, $studentId]);
            $oldResult = $oldResultStmt->fetch();
            $resultChanged = $oldResult && ((float) $oldResult['score'] !== $score
                || (float) ($oldResult['class_score'] ?? 0) !== $classScore
                || (float) ($oldResult['exam_score'] ?? $oldResult['score']) !== $examScore
                || (string) ($oldResult['remarks'] ?? '') !== $remarks);
            $changedMarks = $changedMarks || !$oldResult || $resultChanged;
            $approvedMissingResult = ($isLockedApprovedCorrection || $isLockedIncompleteClassTeacherEdit) && !$oldResult;
            if (($resultChanged || $approvedMissingResult) && $revisionReason === '') {
                throw new InvalidArgumentException('Enter a correction reason before changing an existing mark or recording a missing mark in an approved assessment.');
            }
            if ($resultChanged || $approvedMissingResult) {
                $revisionStmt->execute([
                    $schoolId,
                    $assessmentId,
                    $studentId,
                    (int) $user['id'],
                    $oldResult ? number_format((float) $oldResult['score'], 2, '.', '') : null,
                    number_format($score, 2, '.', ''),
                    !$oldResult || $oldResult['class_score'] === null ? null : number_format((float) $oldResult['class_score'], 2, '.', ''),
                    number_format($classScore, 2, '.', ''),
                    !$oldResult ? null : ($oldResult['exam_score'] === null ? number_format((float) $oldResult['score'], 2, '.', '') : number_format((float) $oldResult['exam_score'], 2, '.', '')),
                    number_format($examScore, 2, '.', ''),
                    $oldResult ? $oldResult['remarks'] : null,
                    $remarks === '' ? null : $remarks,
                    $revisionReason === '' ? null : $revisionReason,
                ]);
            }
                $stmt = $pdo->prepare(
                    'INSERT INTO assessment_results
                     (school_id, assessment_id, student_id, score, class_score, exam_score, grade, remarks, recorded_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE score = VALUES(score), class_score = VALUES(class_score),
                        exam_score = VALUES(exam_score), grade = VALUES(grade), remarks = VALUES(remarks),
                        recorded_by = VALUES(recorded_by)'
                );
                $stmt->execute([
                    $schoolId,
                    $assessmentId,
                    $studentId,
                    number_format($score, 2, '.', ''),
                    number_format($classScore, 2, '.', ''),
                    number_format($examScore, 2, '.', ''),
                    $grade,
                    $remarks === '' ? null : $remarks,
                    (int) $user['id'],
                ]);
            }

            if ($action === 'submit_results' && $missed) {
                throw new InvalidArgumentException('Every active student needs a valid mark before results can be submitted.');
            }
            $authorizedCorrectionSaved = $isLockedApprovedCorrection && $changedMarks;
            $incompleteSubmissionCorrectionSaved = $isLockedIncompleteClassTeacherEdit && $changedMarks;
            $directPrimaryApproval = $action === 'submit_results'
                && $schoolRole === 'class_teacher'
                && $isPrimaryAssessment
                && $isDirectClassTeacher;
            $newWorkflowStatus = $action === 'submit_results'
                ? ($directPrimaryApproval ? 'reviewed' : 'submitted')
                : (($authorizedCorrectionSaved || $incompleteSubmissionCorrectionSaved)
                    ? 'corrected'
                    : (($isLockedApprovedCorrection || $isLockedIncompleteClassTeacherEdit)
                        ? $lockedWorkflowStatus
                        : 'draft'));
            $correctionWorkflowSaved = $authorizedCorrectionSaved || $incompleteSubmissionCorrectionSaved;
            $reviewMetadataReset = $action === 'submit_results' || $correctionWorkflowSaved;
            $clearCorrectionReason = $action === 'submit_results'
                || (!$isLockedApprovedCorrection && !$isLockedIncompleteClassTeacherEdit)
                || ($correctionWorkflowSaved && !$isLockedIncompleteClassTeacherEdit);
            $statusUpdate = $pdo->prepare(
                'UPDATE school_assessments SET workflow_status = ?,
                     submitted_by = CASE WHEN ? = 1 THEN ? ELSE submitted_by END,
                     submitted_at = CASE WHEN ? = 1 THEN UTC_TIMESTAMP() ELSE submitted_at END,
                     submitted_to_user_id = CASE WHEN ? = 1 THEN ? ELSE submitted_to_user_id END,
                     reviewed_by = CASE WHEN ? = 1 THEN ? WHEN ? = 1 THEN NULL ELSE reviewed_by END,
                     reviewed_at = CASE WHEN ? = 1 THEN UTC_TIMESTAMP() WHEN ? = 1 THEN NULL ELSE reviewed_at END,
                     published_at = CASE WHEN ? = 1 THEN NULL ELSE published_at END,
                     published_by = CASE WHEN ? = 1 THEN NULL ELSE published_by END,
                     correction_reason = CASE WHEN ? = 1 THEN NULL ELSE correction_reason END
                 WHERE id = ? AND school_id = ?
                   AND (workflow_status IN ("draft", "correction_requested", "corrected")
                     OR (? = 1 AND workflow_status IN ("submitted", "reviewed", "published")))'
            );
            $statusUpdate->execute([
                $newWorkflowStatus,
                $action === 'submit_results' ? 1 : 0,
                (int) $user['id'],
                $action === 'submit_results' ? 1 : 0,
                $action === 'submit_results' ? 1 : 0,
                $assignedClassTeacherId,
                (int) $user['id'],
                $directPrimaryApproval ? 1 : 0,
                $reviewMetadataReset ? 1 : 0,
                $directPrimaryApproval ? 1 : 0,
                $reviewMetadataReset ? 1 : 0,
                $correctionWorkflowSaved ? 1 : 0,
                $correctionWorkflowSaved ? 1 : 0,
                $clearCorrectionReason ? 1 : 0,
                $assessmentId,
                $schoolId,
                ($isLockedApprovedCorrection || $isLockedIncompleteClassTeacherEdit) ? 1 : 0,
            ]);
            if (($action === 'submit_results' || $correctionWorkflowSaved) && $statusUpdate->rowCount() !== 1) {
                throw new RuntimeException('The assessment status changed while saving. Refresh and review the latest records.');
            }
            if ($correctionWorkflowSaved) {
                $submissionNumberStmt = $pdo->prepare(
                    'SELECT COALESCE(MAX(submission_number), 0) + 1
                     FROM school_assessment_submissions
                     WHERE school_id = ? AND assessment_id = ?'
                );
                $submissionNumberStmt->execute([$schoolId, $assessmentId]);
                $submissionHistoryStmt = $pdo->prepare(
                    'INSERT INTO school_assessment_submissions
                     (school_id, assessment_id, submission_number, submitted_by,
                      submitted_to_user_id, workflow_status, submitted_at, correction_reason)
                     VALUES (?, ?, ?, ?, ?, "corrected", UTC_TIMESTAMP(), ?)'
                );
                $submissionHistoryStmt->execute([
                    $schoolId,
                    $assessmentId,
                    (int) $submissionNumberStmt->fetchColumn(),
                    (int) $user['id'],
                    $assessment['submitted_to_user_id'] ?? null,
                    $revisionReason,
                ]);
            }
            if ($action === 'submit_results') {
                $submissionNumberStmt = $pdo->prepare(
                    'SELECT COALESCE(MAX(submission_number), 0) + 1
                     FROM school_assessment_submissions
                     WHERE school_id = ? AND assessment_id = ?'
                );
                $submissionNumberStmt->execute([$schoolId, $assessmentId]);
                $submissionNumber = (int) $submissionNumberStmt->fetchColumn();
                $submissionHistoryStmt = $pdo->prepare(
                    'INSERT INTO school_assessment_submissions
                     (school_id, assessment_id, submission_number, submitted_by,
                      submitted_to_user_id, workflow_status, submitted_at,
                      reviewed_by, reviewed_at)
                     VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?, ?)'
                );
                $submissionHistoryStmt->execute([
                    $schoolId,
                    $assessmentId,
                    $submissionNumber,
                    (int) $user['id'],
                    $assignedClassTeacherId,
                    $newWorkflowStatus,
                    $directPrimaryApproval ? (int) $user['id'] : null,
                    $directPrimaryApproval ? gmdate('Y-m-d H:i:s') : null,
                ]);
            }
            if ($changedMarks) {
                $pdo->prepare(
                    'UPDATE school_report_card_batches
                     SET is_outdated = 1, outdated_at = UTC_TIMESTAMP()
                     WHERE school_id = ? AND class_id = ? AND academic_year = ?
                       AND term_name = ? AND assessment_name = ?
                       AND status IN ("ready", "published")'
                )->execute([
                    $schoolId,
                    (int) $assessment['class_id'],
                    (string) $assignmentYear,
                    (string) $assessment['term_name'],
                    (string) $assessment['assessment_name'],
                ]);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception instanceof InvalidArgumentException) {
                flash('error', $exception->getMessage());
            } else {
                error_log('Assessment mark save failed: ' . $exception->getMessage());
                flash('error', 'Assessment marks could not be saved. No partial submission was applied.');
            }
            redirect('assessments.php?assessment_id=' . $assessmentId);
        }

        audit(
            (int) $user['id'],
            $action === 'submit_results'
                ? ($directPrimaryApproval ? 'assessment_results_submitted_and_approved' : 'assessment_results_submitted')
                : ($correctionWorkflowSaved ? 'assessment_results_corrected' : 'assessment_results_draft_saved'),
            'school_id=' . $schoolId . ';assessment_id=' . $assessmentId . ';students=' . count($students)
        );
        if ($directPrimaryApproval) {
            audit((int) $user['id'], 'assessment_results_reviewed', 'school_id=' . $schoolId . ';assessment_id=' . $assessmentId . ';workflow=class_teacher_direct');
        }
        if ($action === 'submit_results') {
            $assignmentStmt = db()->prepare(
                'SELECT sa.class_id, sa.subject_id, sa.assessment_name, sa.term_name, sa.submitted_at,
                        c.level, c.section, subject.name AS subject_name, teacher.full_name AS teacher_name,
                        intended_teacher.full_name AS intended_teacher_name
                 FROM school_assessments sa
                 JOIN school_classes c ON c.id = sa.class_id AND c.school_id = sa.school_id
                 JOIN school_subjects subject ON subject.id = sa.subject_id AND subject.school_id = sa.school_id
                 LEFT JOIN users teacher ON teacher.id = ?
                 LEFT JOIN users intended_teacher ON intended_teacher.id = sa.submitted_to_user_id
                 WHERE sa.id = ? AND sa.school_id = ?'
            );
            $assignmentStmt->execute([(int) $user['id'], $assessmentId, $schoolId]);
            $submittedInfo = $assignmentStmt->fetch();
            if ($submittedInfo) {
                $submissionNotificationKey = ($directPrimaryApproval ? 'assessment-direct-approved:' : 'assessment-submitted:')
                    . $assessmentId . ':at:' . (string) $submittedInfo['submitted_at'];
                $classTeacherId = $assignedClassTeacherId;
                $notificationBody = (string) $submittedInfo['teacher_name']
                    . ($directPrimaryApproval ? ' recorded and approved ' : ' submitted ')
                    . (string) $submittedInfo['subject_name'] . ' marks for '
                    . school_class_label(['level' => $submittedInfo['level'], 'section' => $submittedInfo['section']])
                    . ' · ' . (string) $submittedInfo['assessment_name'] . ' · '
                    . (string) $submittedInfo['term_name'] . ' at '
                    . (string) $submittedInfo['submitted_at'] . ' UTC.'
                    . ($submittedInfo['intended_teacher_name']
                        ? ' Routed to ' . (string) $submittedInfo['intended_teacher_name'] . '.'
                        : '');
                if ($classTeacherId !== null && $classTeacherId !== (int) $user['id']) {
                    school_notification_create(
                        $schoolId,
                        (int) $classTeacherId,
                        (int) $user['id'],
                        $submissionNotificationKey,
                        'assessment',
                        $directPrimaryApproval ? 'Class results approved' : 'Subject marks submitted',
                        $notificationBody,
                        'assessments.php?assessment_id=' . $assessmentId,
                        (int) $submittedInfo['class_id'],
                        (int) $submittedInfo['subject_id'],
                        $assessmentId
                    );
                }
                school_notify_roles(
                    $schoolId,
                    ['school_headmaster', 'school_proprietor'],
                    (int) $user['id'],
                    $submissionNotificationKey,
                    'assessment',
                    $directPrimaryApproval ? 'Class results approved' : 'Subject marks submitted',
                    $notificationBody,
                    'assessments.php?assessment_id=' . $assessmentId,
                    (int) $submittedInfo['class_id'],
                    (int) $submittedInfo['subject_id'],
                    $assessmentId,
                    null,
                    $classTeacherId === null ? [] : [$classTeacherId]
                );
            }
        }
        if ($missed) {
            flash('warning', 'Some scores were skipped because they fell outside the valid range for the assessment.');
        } elseif ($action === 'submit_results' && $directPrimaryApproval) {
            flash('success', 'Student results were recorded and approved by the assigned Class Teacher. Continue with the remaining subjects; report cards are available when all required results are complete.');
        } elseif ($action === 'submit_results') {
            $targetTeacherName = (string) ($submittedInfo['intended_teacher_name'] ?? '');
            flash(
                'success',
                'Assessment results submitted for review to '
                . ($targetTeacherName !== '' ? $targetTeacherName : 'the assigned Class Teacher')
                . ' for ' . school_class_label([
                    'level' => (string) ($submittedInfo['level'] ?? ''),
                    'section' => (string) ($submittedInfo['section'] ?? ''),
                ])
                . ' · ' . (string) ($submittedInfo['subject_name'] ?? 'the selected subject')
                . ' · ' . (string) ($submittedInfo['assessment_name'] ?? 'the selected examination')
                . ' · ' . (string) ($submittedInfo['term_name'] ?? '') . '.'
            );
        } elseif ($action === 'save_results_continue') {
            flash('success', 'Draft saved. You can continue entering the remaining student marks.');
        } else {
            flash('success', 'Assessment draft saved successfully.');
        }
        if ($action === 'save_results_continue') {
            redirect('assessments.php?assessment_id=' . $assessmentId
                . ($firstIncompleteStudentId === null ? '' : '#student-row-' . $firstIncompleteStudentId));
        }
        redirect('assessments.php?assessment_id=' . $assessmentId);
    }
}

$classStmt = db()->prepare(
    'SELECT id, level, section, academic_year, status
     FROM school_classes
     WHERE school_id = ? AND (
        status = "active" OR EXISTS (
            SELECT 1 FROM school_assessments historical_assessment
            WHERE historical_assessment.school_id = school_classes.school_id
              AND historical_assessment.class_id = school_classes.id
        )
     )'
        . ($teacherAccount ? ' AND (
            EXISTS (
                SELECT 1 FROM teacher_class_assignments tca
                WHERE tca.school_id = school_classes.school_id AND tca.class_id = school_classes.id
                  AND tca.teacher_user_id = ? AND tca.academic_year = school_classes.academic_year
            ) OR EXISTS (
                SELECT 1 FROM class_subjects cs
                WHERE cs.school_id = school_classes.school_id AND cs.class_id = school_classes.id
                  AND cs.teacher_user_id = ?
            )
        )' : '') . ' ORDER BY level, name'
);
$classStmt->execute($teacherAccount
    ? [$schoolId, (int) $user['id'], (int) $user['id']]
    : [$schoolId]);
$classes = $classStmt->fetchAll();
$assessmentYears = array_values(array_unique(array_map(
    static fn(array $class): string => (string) $class['academic_year'],
    $classes
)));
rsort($assessmentYears, SORT_NATURAL);
$assessmentTermStmt = db()->prepare(
    'SELECT t.id, y.academic_year, t.term_name, y.status AS academic_year_status
     FROM school_terms t JOIN school_academic_years y
       ON y.id = t.academic_year_id AND y.school_id = t.school_id
     WHERE t.school_id = ? AND (
        y.status = "open" OR EXISTS (
            SELECT 1 FROM school_assessments historical_assessment
            WHERE historical_assessment.school_id = t.school_id
              AND (
                historical_assessment.term_id = t.id
                OR (historical_assessment.term_id IS NULL
                    AND historical_assessment.academic_year = y.academic_year
                    AND historical_assessment.term_name = t.term_name)
              )
        )
     )
     ORDER BY t.starts_on DESC'
);
$assessmentTermStmt->execute([$schoolId]);
$assessmentTerms = $assessmentTermStmt->fetchAll();
$registerTermStmt = db()->prepare(
    'SELECT t.id, y.academic_year, t.term_name
     FROM school_terms t JOIN school_academic_years y
       ON y.id = t.academic_year_id AND y.school_id = t.school_id
     WHERE t.school_id = ?
     ORDER BY t.starts_on DESC'
);
$registerTermStmt->execute([$schoolId]);
$registerTerms = $registerTermStmt->fetchAll();
$assessmentTypeStmt = db()->prepare(
    'SELECT DISTINCT assessment_name FROM school_assessments
     WHERE school_id = ? ORDER BY assessment_name'
);
$assessmentTypeStmt->execute([$schoolId]);
$assessmentTypes = array_map(
    static fn(array $row): string => (string) $row['assessment_name'],
    $assessmentTypeStmt->fetchAll()
);

$subjectSql = 'SELECT subject.id, subject.name, subject.code,
                      GROUP_CONCAT(DISTINCT class.id ORDER BY class.id) AS assigned_class_ids
               FROM school_subjects subject
               JOIN class_subjects assignment
                 ON assignment.school_id = subject.school_id AND assignment.subject_id = subject.id
               JOIN school_classes class
                 ON class.id = assignment.class_id AND class.school_id = assignment.school_id
               WHERE subject.school_id = ? AND class.status = "active"';
$subjectParams = [$schoolId];
if ($teacherAccount && $schoolRole === 'class_teacher') {
    $subjectSql .= ' AND (
        assignment.teacher_user_id = ?
        OR (class.academic_stage = "primary"
            AND EXISTS (
                SELECT 1 FROM teacher_class_assignments class_assignment
                WHERE class_assignment.school_id = class.school_id
                  AND class_assignment.class_id = class.id
                  AND class_assignment.teacher_user_id = ?
                  AND class_assignment.academic_year = class.academic_year
            ))
    )';
    $subjectParams[] = (int) $user['id'];
    $subjectParams[] = (int) $user['id'];
} elseif ($teacherAccount) {
    $subjectSql .= ' AND assignment.teacher_user_id = ?';
    $subjectParams[] = (int) $user['id'];
}
$subjectSql .= ' GROUP BY subject.id, subject.name, subject.code ORDER BY subject.name ASC';
$subjectStmt = db()->prepare($subjectSql);
$subjectStmt->execute($subjectParams);
$subjects = $subjectStmt->fetchAll();

$assessmentStmt = db()->prepare(
    'SELECT sa.id, sa.assessment_name, sa.term_name, sa.academic_year, sa.total_marks,
    sa.workflow_status, sa.published_at, c.level AS class_level, c.section AS class_section,
    c.academic_stage AS class_stage,
    s.name AS subject_name, s.code,
            (SELECT COUNT(*) FROM assessment_results ar
             JOIN school_students result_student ON result_student.id = ar.student_id
               AND result_student.school_id = ar.school_id AND result_student.class_id = sa.class_id
               AND result_student.school_status = "active"
             WHERE ar.assessment_id = sa.id AND ar.school_id = sa.school_id) AS result_count,
            (SELECT COUNT(*) FROM school_students eligible_student
             JOIN school_classes eligible_class ON eligible_class.id = eligible_student.class_id
               AND eligible_class.school_id = eligible_student.school_id
             WHERE eligible_student.school_id = sa.school_id
               AND eligible_student.class_id = sa.class_id
               AND eligible_student.school_status = "active"
               AND eligible_class.academic_year = sa.academic_year
               AND eligible_class.status = "active") AS eligible_count
     FROM school_assessments sa
     JOIN school_classes c ON c.id = sa.class_id AND c.school_id = sa.school_id
     JOIN school_subjects s ON s.id = sa.subject_id AND s.school_id = sa.school_id
     WHERE sa.school_id = ?' . $assessmentScope
        . ($pageAcademicPeriod !== null && (string) ($_GET['show_history'] ?? '') !== '1'
            ? ' AND sa.academic_year = ? AND sa.term_name = ?'
            : '')
        . ' ORDER BY sa.created_at DESC'
);
$assessmentParams = array_merge([$schoolId], $assessmentScopeParams);
if ($pageAcademicPeriod !== null && (string) ($_GET['show_history'] ?? '') !== '1') {
    $assessmentParams[] = $pageAcademicPeriod['academic_year'];
    $assessmentParams[] = $pageAcademicPeriod['term_name'];
}
$assessmentStmt->execute($assessmentParams);
$assessments = $assessmentStmt->fetchAll();
$assessmentWorkflowChart = [];
$assessmentCompletionChart = [];
foreach ($assessments as $assessment) {
    $workflowLabel = ucwords(str_replace('_', ' ', (string) $assessment['workflow_status']));
    $assessmentWorkflowChart[$workflowLabel] = ($assessmentWorkflowChart[$workflowLabel] ?? 0) + 1;
}
foreach (array_slice($assessments, 0, 10) as $assessment) {
    $eligibleCount = (int) $assessment['eligible_count'];
    if ($eligibleCount < 1) {
        continue;
    }
    $classLabel = school_class_label([
        'level' => $assessment['class_level'],
        'section' => $assessment['class_section'],
    ]);
    $label = (string) $assessment['assessment_name'] . ' · ' . $classLabel . ' · ' . (string) $assessment['subject_name'];
    $assessmentCompletionChart[$label] = round(
        min(100, ((int) $assessment['result_count'] / $eligibleCount) * 100),
        2
    );
}
$gradeRulesStmt = db()->prepare(
    'SELECT grade, minimum_percent, grade_order, remark
     FROM school_grade_rules WHERE school_id = ? ORDER BY grade_order'
);
$gradeRulesStmt->execute([$schoolId]);
$gradeRules = $gradeRulesStmt->fetchAll();
$resultSettingsStmt = db()->prepare('SELECT ranking_policy, class_score_weight, ranking_enabled, updated_by FROM school_result_settings WHERE school_id = ?');
$resultSettingsStmt->execute([$schoolId]);
$resultSettings = $resultSettingsStmt->fetch() ?: [];
$rankingPolicy = (string) ($resultSettings['ranking_policy'] ?? 'competition');
$classScoreWeight = !array_key_exists('class_score_weight', $resultSettings)
    || ((float) $resultSettings['class_score_weight'] === 0.0 && ($resultSettings['updated_by'] ?? null) === null)
    ? 30.0
    : (float) $resultSettings['class_score_weight'];
$examScoreWeight = 100 - $classScoreWeight;
$rankingEnabled = (bool) ($resultSettings['ranking_enabled'] ?? true);

$registerRequested = (string) ($_GET['register'] ?? '') === '1';
$registerYear = trim((string) ($_GET['register_year'] ?? ''));
$registerTermId = filter_var($_GET['register_term_id'] ?? null, FILTER_VALIDATE_INT);
$registerClassId = filter_var($_GET['register_class_id'] ?? null, FILTER_VALIDATE_INT);
$registerAssessmentName = trim((string) ($_GET['register_assessment_name'] ?? ''));
$registerGroup = null;
$registerClass = null;
$registerSubjects = [];
$registerError = null;
$registerIndividualStudentId = filter_var($_GET['register_student_id'] ?? null, FILTER_VALIDATE_INT);
$registerIndividualStudent = null;

$selectedAssessmentId = filter_var($_GET['assessment_id'] ?? null, FILTER_VALIDATE_INT);
$selectedAssessment = null;
$selectedStudents = [];
$selectedAssessmentHasIncompleteRoster = false;
$existingResults = [];
$selectedRosterDiagnostic = null;
$selectedSubmissionHistory = [];
$selectedSubjectPositions = [];

if ($selectedAssessmentId !== false && $selectedAssessmentId > 0) {
    $selectedAssessmentStmt = db()->prepare(
            'SELECT sa.id, sa.class_id, sa.subject_id, sa.assessment_name, sa.term_name,
                sa.academic_year, sa.term_id, sa.submitted_to_user_id, sa.total_marks,
                sa.class_score_weight, sa.exam_score_weight,
                sa.workflow_status, sa.correction_reason, sa.submitted_by, sa.submitted_at,
                sa.reviewed_at, sa.published_at,
                c.level AS class_level, c.section AS class_section, c.academic_stage AS class_stage,
                s.name AS subject_name, s.code,
                recipient.full_name AS submitted_to_name
         FROM school_assessments sa
         JOIN school_classes c ON c.id = sa.class_id AND c.school_id = sa.school_id
         JOIN school_subjects s ON s.id = sa.subject_id AND s.school_id = sa.school_id
         LEFT JOIN users recipient ON recipient.id = sa.submitted_to_user_id
         WHERE sa.id = ? AND sa.school_id = ?' . $assessmentScope . ' LIMIT 1'
    );
    $selectedAssessmentStmt->execute(array_merge([$selectedAssessmentId, $schoolId], $assessmentScopeParams));
    $selectedAssessment = $selectedAssessmentStmt->fetch();

    if ($selectedAssessment) {
        $submissionHistoryStmt = db()->prepare(
            'SELECT submission.submission_number, submission.workflow_status,
                    submission.submitted_at, submission.reviewed_at,
                    submission.correction_reason, submitter.full_name AS submitted_by_name,
                    recipient.full_name AS recipient_name, reviewer.full_name AS reviewed_by_name
             FROM school_assessment_submissions submission
             LEFT JOIN users submitter ON submitter.id = submission.submitted_by
             LEFT JOIN users recipient ON recipient.id = submission.submitted_to_user_id
             LEFT JOIN users reviewer ON reviewer.id = submission.reviewed_by
             WHERE submission.school_id = ? AND submission.assessment_id = ?
             ORDER BY submission.submission_number DESC'
        );
        $submissionHistoryStmt->execute([$schoolId, (int) $selectedAssessment['id']]);
        $selectedSubmissionHistory = $submissionHistoryStmt->fetchAll();

        $studentList = db()->prepare(
            'SELECT ss.id, ss.full_name, ss.admission_number, ar.score, ar.class_score, ar.exam_score, ar.remarks
             FROM school_students ss
             JOIN school_classes class
               ON class.id = ss.class_id AND class.school_id = ss.school_id
             LEFT JOIN assessment_results ar ON ar.assessment_id = ? AND ar.student_id = ss.id AND ar.school_id = ss.school_id
             WHERE ss.school_id = ? AND ss.class_id = ? AND class.academic_year = ?
               AND class.status = "active" AND ss.school_status = "active"
             ORDER BY ss.full_name ASC'
        );
        $studentList->execute([
            $selectedAssessmentId,
            $schoolId,
            (int) $selectedAssessment['class_id'],
            (string) $selectedAssessment['academic_year'],
        ]);
        $selectedStudents = $studentList->fetchAll();
        $eligibleStudentCount = count($selectedStudents);
        $recordedStudentCount = count(array_filter(
            $selectedStudents,
            static fn(array $student): bool => $student['score'] !== null
        ));
        $selectedAssessmentHasIncompleteRoster = $eligibleStudentCount > 0
            && $recordedStudentCount < $eligibleStudentCount;
        if ($eligibleStudentCount > 0 && $recordedStudentCount === $eligibleStudentCount) {
            $subjectScores = [];
            foreach ($selectedStudents as $student) {
                $subjectScores[(int) $student['id']] = (float) $student['score'];
            }
            $selectedSubjectPositions = school_rank_positions($subjectScores, $rankingPolicy);
        }
        if ($selectedStudents === []) {
            $rosterDiagnosticStmt = db()->prepare(
                'SELECT COUNT(*) AS assigned_students,
                        SUM(CASE WHEN student.school_status = "active" THEN 1 ELSE 0 END) AS active_students,
                        SUM(CASE WHEN student.school_status <> "active" THEN 1 ELSE 0 END) AS inactive_students
                 FROM school_students student
                 JOIN school_classes class
                   ON class.id = student.class_id AND class.school_id = student.school_id
                 WHERE student.school_id = ? AND student.class_id = ?
                   AND class.academic_year = ? AND class.status = "active"'
            );
            $rosterDiagnosticStmt->execute([
                $schoolId,
                (int) $selectedAssessment['class_id'],
                (string) $selectedAssessment['academic_year'],
            ]);
            $selectedRosterDiagnostic = $rosterDiagnosticStmt->fetch()
                ?: ['assigned_students' => 0, 'active_students' => 0, 'inactive_students' => 0];
            error_log(
                'Assessment roster empty: school_id=' . $schoolId
                . ';assessment_id=' . (int) $selectedAssessment['id']
                . ';class_id=' . (int) $selectedAssessment['class_id']
                . ';academic_year=' . (string) $selectedAssessment['academic_year']
                . ';assigned_students=' . (int) ($selectedRosterDiagnostic['assigned_students'] ?? 0)
                . ';active_students=' . (int) ($selectedRosterDiagnostic['active_students'] ?? 0)
            );
        }
    }
}

if ($registerRequested) {
    if ($registerYear === '' || $registerTermId === false || $registerTermId < 1
        || $registerClassId === false || $registerClassId < 1
        || $registerAssessmentName === '') {
        $registerError = 'Select the academic year, term, class, and examination type to open the class register.';
    } else {
        foreach ($classes as $classOption) {
            if ((int) $classOption['id'] === $registerClassId) {
                $registerClass = $classOption;
                break;
            }
        }

        $selectedTerm = null;
        foreach ($registerTerms as $termOption) {
            if ((int) $termOption['id'] === $registerTermId) {
                $selectedTerm = $termOption;
                break;
            }
        }

        if ($registerClass === null
            || (string) $registerClass['academic_year'] !== $registerYear
            || $selectedTerm === null
            || (string) $selectedTerm['academic_year'] !== $registerYear
            || !in_array($registerAssessmentName, $assessmentTypes, true)) {
            $registerError = 'The selected register is not available for this school and academic period.';
        } elseif ($schoolRole === 'class_teacher'
            && !school_teacher_has_class_assignment(
                (int) $user['id'],
                $schoolId,
                $registerClassId,
                $registerYear
            )) {
            $registerError = 'A class-teacher assignment for this exact class and academic year is required to view the complete register.';
        } elseif ($schoolRole === 'subject_teacher') {
            $assignedSubjectsStmt = db()->prepare(
                'SELECT assignment.subject_id
                 FROM class_subjects assignment
                 JOIN school_classes class
                   ON class.id = assignment.class_id AND class.school_id = assignment.school_id
                 WHERE assignment.school_id = ? AND assignment.class_id = ?
                   AND assignment.teacher_user_id = ? AND class.academic_year = ?
                 ORDER BY assignment.subject_id'
            );
            $assignedSubjectsStmt->execute([$schoolId, $registerClassId, (int) $user['id'], $registerYear]);
            $assignedRegisterSubjectIds = array_map('intval', $assignedSubjectsStmt->fetchAll(PDO::FETCH_COLUMN));
            if ($assignedRegisterSubjectIds === []) {
                $registerError = 'You do not have a subject assignment for this class and academic year.';
            }
        } else {
            $assignedRegisterSubjectIds = [];
        }

        if ($registerError === null) {
            try {
                $registerGroup = school_assessment_group(
                    $schoolId,
                    $registerClassId,
                    $registerYear,
                    (string) $selectedTerm['term_name'],
                    $registerAssessmentName
                );
                $registerSubjects = $registerGroup['subjects'];
                if ($schoolRole === 'subject_teacher') {
                    $registerSubjects = array_values(array_filter(
                        $registerSubjects,
                        static fn(array $subject): bool => in_array(
                            (int) $subject['subject_id'],
                            $assignedRegisterSubjectIds,
                            true
                        )
                    ));
                    $registerGroup['outstanding_subjects'] = array_values(array_filter(
                        $registerGroup['outstanding_subjects'],
                        static fn(array $subject): bool => in_array(
                            (int) $subject['subject_id'],
                            $assignedRegisterSubjectIds,
                            true
                        )
                    ));
                    if ($registerSubjects === []) {
                        $registerError = 'No assigned subject is configured for this class register.';
                    }
                }
                if ($registerError === null && $registerIndividualStudentId !== false && $registerIndividualStudentId > 0) {
                    foreach ($registerGroup['students'] as $student) {
                        if ((int) $student['id'] === $registerIndividualStudentId) {
                            $registerIndividualStudent = $student;
                            break;
                        }
                    }
                    if ($registerIndividualStudent === null) {
                        $registerError = 'That student is not in the active roster for this authorized class register.';
                    }
                }
            } catch (PDOException | RuntimeException $exception) {
                error_log(
                    'Class examination register load failed: school_id=' . $schoolId
                    . ';class_id=' . $registerClassId
                    . ';academic_year=' . $registerYear
                    . ';term_id=' . $registerTermId
                    . ';error=' . $exception->getMessage()
                );
                $registerError = 'The register could not be calculated. Verify the school grading configuration and try again.';
                $registerGroup = null;
            }
        }
    }
}

$registerIndividualReport = null;
if ($registerGroup !== null && $registerIndividualStudent !== null && $selectedTerm !== null) {
    $reportTermStmt = db()->prepare(
        'SELECT term.id, term.school_id, year.academic_year, term.term_name, term.starts_on, term.ends_on
         FROM school_terms term
         JOIN school_academic_years year ON year.id = term.academic_year_id AND year.school_id = term.school_id
         WHERE term.id = ? AND term.school_id = ? AND year.academic_year = ? LIMIT 1'
    );
    $reportTermStmt->execute([$registerTermId, $schoolId, $registerYear]);
    $reportTerm = $reportTermStmt->fetch() ?: null;
    $attendanceSummary = [];
    $nextTermDate = 'Not configured';
    if ($reportTerm !== null) {
        $attendanceSummary = school_attendance_summaries(
            $schoolId,
            [(int) $registerIndividualStudent['id']],
            school_attendance_date_periods($reportTerm, null, $schoolId)
        )[(int) $registerIndividualStudent['id']]['term'] ?? [];
        $nextTermStmt = db()->prepare(
            'SELECT starts_on FROM school_terms WHERE school_id = ? AND starts_on > ?
             ORDER BY starts_on ASC LIMIT 1'
        );
        $nextTermStmt->execute([$schoolId, (string) $reportTerm['ends_on']]);
        $nextTermStart = $nextTermStmt->fetchColumn();
        if ($nextTermStart !== false) {
            $nextTermDate = date('d M Y', strtotime((string) $nextTermStart));
        }
    }

    $reportPolicyStmt = db()->prepare(
        'SELECT setting_key, setting_value FROM school_settings
         WHERE school_id = ? AND setting_key = "report_card_promotion_cutoff"'
    );
    $reportPolicyStmt->execute([$schoolId]);
    $reportPolicy = [];
    foreach ($reportPolicyStmt->fetchAll() as $setting) {
        $reportPolicy[(string) $setting['setting_key']] = (string) $setting['setting_value'];
    }
    $promotionCutoff = filter_var($reportPolicy['report_card_promotion_cutoff'] ?? '25', FILTER_VALIDATE_INT);
    if ($promotionCutoff === false || $promotionCutoff < 1 || $promotionCutoff > 500) {
        $promotionCutoff = 25;
    }

    $savedReportNotes = [];
    if ($schoolRole !== 'subject_teacher') {
        $savedNotesStmt = db()->prepare(
            'SELECT note.promotion_override, note.promotion_override_reason,
                    note.teacher_comment, note.headmaster_remarks, note.student_behaviour,
                    note.conduct, note.interest, note.attitude,
                    note.next_term_date, note.class_teacher_signature_name, note.headmaster_signature_name
             FROM school_report_card_batches batch
             JOIN school_report_card_entries entry ON entry.school_id = batch.school_id
               AND entry.batch_id = batch.id AND entry.student_id = ?
             LEFT JOIN school_report_card_notes note ON note.school_id = batch.school_id
               AND note.batch_id = batch.id AND note.student_id = entry.student_id
             WHERE batch.school_id = ? AND batch.class_id = ? AND batch.academic_year = ?
               AND batch.term_name = ? AND batch.assessment_name = ?
               AND batch.status IN ("draft", "ready", "submitted", "reviewed", "correction_requested", "published")
             ORDER BY batch.revision_number DESC, batch.id DESC LIMIT 1'
        );
        $savedNotesStmt->execute([
            (int) $registerIndividualStudent['id'],
            $schoolId,
            $registerClassId,
            $registerYear,
            (string) $selectedTerm['term_name'],
            $registerAssessmentName,
        ]);
        $savedReportNotes = $savedNotesStmt->fetch() ?: [];
    }
    $reportSubjects = [];
    foreach ($registerSubjects as $subject) {
        $subjectScore = $registerIndividualStudent['subject_scores'][(int) $subject['subject_id']] ?? null;
        $reportSubjects[] = [
            'subject_name' => (string) $subject['subject_name'],
            'class_score' => $subjectScore['class_score'] ?? null,
            'exam_score' => $subjectScore['exam_score'] ?? null,
            'score' => $subjectScore['score'] ?? null,
            'total_marks' => $subject['total_marks'] === null ? null : (float) $subject['total_marks'],
            'grade' => $subjectScore['grade'] ?? '',
            'remark' => $subjectScore['remarks'] ?? '',
            'position' => $subjectScore['position'] ?? null,
        ];
    }
    $totalPossible = array_sum(array_map(
        static fn(array $subject): float => (float) ($subject['total_marks'] ?? 0),
        $reportSubjects
    ));
    $registerIndividualReport = [
        'student_name' => (string) $registerIndividualStudent['full_name'],
        'admission_number' => (string) ($registerIndividualStudent['admission_number'] ?? '—'),
        'class_label' => school_class_label($registerGroup['class']),
        'academic_year' => (string) $registerGroup['academic_year'],
        'term_name' => (string) $registerGroup['term_name'],
        'assessment_name' => (string) $registerGroup['assessment_name'],
        'total_score' => (float) ($registerIndividualStudent['total'] ?? 0),
        'total_possible' => $totalPossible,
        'average_percent' => $registerIndividualStudent['average'],
        'overall_grade' => (string) ($registerIndividualStudent['grade'] ?? 'Pending'),
        'class_position' => $registerIndividualStudent['position'],
        'class_size' => count($registerGroup['students']),
        'subjects' => $reportSubjects,
        'attendance' => $attendanceSummary,
        'student_behaviour' => (string) ($savedReportNotes['student_behaviour'] ?? ''),
        'conduct' => (string) ($savedReportNotes['conduct'] ?? $savedReportNotes['student_behaviour'] ?? ''),
        'interest' => (string) ($savedReportNotes['interest'] ?? ''),
        'attitude' => (string) ($savedReportNotes['attitude'] ?? ''),
        'vacation_date' => (string) ($reportTerm['ends_on'] ?? ''),
        'promotion_override' => $savedReportNotes['promotion_override'] ?? null,
        'promotion_override_reason' => (string) ($savedReportNotes['promotion_override_reason'] ?? ''),
        'teacher_comment' => (string) ($savedReportNotes['teacher_comment'] ?? ''),
        'headmaster_remarks' => (string) ($savedReportNotes['headmaster_remarks'] ?? ''),
        'class_teacher_signature_name' => (string) ($savedReportNotes['class_teacher_signature_name'] ?? ''),
        'headmaster_signature_name' => (string) ($savedReportNotes['headmaster_signature_name'] ?? ''),
        'promotion_cutoff' => (int) $promotionCutoff,
        'next_term_date' => (string) (($savedReportNotes['next_term_date'] ?? '') ?: $nextTermDate),
        'show_overall' => $schoolRole !== 'subject_teacher',
    ];
}

render_header('Examination Marks Entry');
?>
<section class="panel">
    <p class="eyebrow">EXAMINATION MARKS ENTRY</p>
    <h1>Examination Marks Entry</h1>
</section>

<section class="report-action-grid" aria-label="Examination actions" data-action-return="assessments.php" data-action-start="<?= $selectedAssessment !== null || $registerRequested || (string) ($_GET['show_history'] ?? '') === '1' ? 'examination-register' : '' ?>">
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('results') ?></span>
        <div><h2>Enter or Review Marks</h2><p>Choose the academic period and class to load saved marks and continue editing the examination register.</p></div>
        <a class="button button-primary" href="#examination-register" data-action-open="examination-register">Open Register</a>
    </article>
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('calendar') ?></span>
        <div><h2>Set Up an Examination</h2><p>Configure an assessment for an authorized class and subject.</p></div>
        <a class="button" href="#examination-setup" data-action-open="examination-setup">Set Up Examination</a>
    </article>
    <?php if (in_array($schoolRole, ['school_proprietor', 'school_headmaster'], true)): ?>
        <article class="report-action-card">
            <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('settings') ?></span>
            <div><h2>Grading and Ranking</h2><p>Manage the school grading scale, score weighting, and ranking policy.</p></div>
            <a class="button" href="#grading-settings" data-action-open="grading-settings">Manage Settings</a>
        </article>
    <?php endif; ?>
</section>

<section class="panel" id="examination-register" data-action-view="examination-register" style="margin-top:1.5rem;">
    <h2>Consolidated class examination register</h2>
    <p class="muted">Select the examination context to record or review saved student results in the database-backed class register.</p>
    <form method="get" action="assessments.php" class="form-stack">
        <input type="hidden" name="register" value="1">
        <div class="form-grid two-columns">
            <div>
                <label for="register-year">Academic year</label>
                <select id="register-year" name="register_year" data-register-year required>
                    <option value="">Select academic year</option>
                    <?php foreach ($assessmentYears as $year): ?>
                        <option value="<?= e($year) ?>" <?= $registerYear === $year ? 'selected' : '' ?>><?= e(school_academic_year_label($year)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="register-term">Academic term</label>
                <select id="register-term" name="register_term_id" data-register-term required>
                    <option value="">Select term</option>
                    <?php foreach ($registerTerms as $term): ?>
                        <option value="<?= (int) $term['id'] ?>" data-academic-year="<?= e((string) $term['academic_year']) ?>" <?= $registerTermId !== false && (int) $term['id'] === $registerTermId ? 'selected' : '' ?>><?= e((string) $term['term_name']) ?> · <?= e(school_academic_year_label((string) $term['academic_year'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="register-class">Class</label>
                <select id="register-class" name="register_class_id" data-register-class required>
                    <option value="">Select class</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?= (int) $class['id'] ?>" data-academic-year="<?= e((string) $class['academic_year']) ?>" <?= $registerClassId !== false && (int) $class['id'] === $registerClassId ? 'selected' : '' ?>><?= e(school_class_label($class)) ?> · <?= e(school_academic_year_label((string) $class['academic_year'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="register-examination">Examination type</label>
                <select id="register-examination" name="register_assessment_name" required>
                    <option value="">Select examination type</option>
                    <?php foreach ($assessmentTypes as $assessmentType): ?>
                        <option value="<?= e($assessmentType) ?>" <?= $registerAssessmentName === $assessmentType ? 'selected' : '' ?>><?= e($assessmentType) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <?php if ($assessmentTypes === []): ?><p class="notice">No examination type is configured yet. Create an assessment using the existing examination workflow before opening a register.</p><?php endif; ?>
        <button class="button button-primary" type="submit" <?= $assessmentTypes === [] ? 'disabled' : '' ?>>Record Examination Results</button>
    </form>
    <?php if ($registerRequested && $registerError !== null): ?><p class="notice notice-error"><?= e($registerError) ?></p><?php endif; ?>
    <?php if ($registerGroup !== null): ?>
        <p class="muted">
            <?= e(school_class_label($registerGroup['class'])) ?> ·
            <?= e(school_academic_year_label($registerGroup['academic_year'])) ?> ·
            <?= e($registerGroup['term_name']) ?> ·
            <?= e($registerGroup['assessment_name']) ?> ·
            <?= count($registerGroup['students']) ?> active students ·
            <?= count($registerSubjects) ?> configured subject<?= count($registerSubjects) === 1 ? '' : 's' ?>
        </p>
        <?php if ($registerSubjects === []): ?><p class="notice notice-error">No subjects are configured for this class. Add the class-subject assignments before using the consolidated register.</p><?php endif; ?>
        <?php if ($registerGroup['outstanding_subjects'] !== []): ?>
            <p class="notice">Outstanding or incomplete subjects are listed below the register. Draft marks are not shown as submitted results.</p>
        <?php endif; ?>
        <p class="muted register-weighting-note">Configured weighting: <?= e(school_register_score_label($classScoreWeight)) ?>% class assessment and <?= e(school_register_score_label($examScoreWeight)) ?>% examination.</p>
        <div class="table-scroll register-status-scroll">
            <table class="register-status-table">
                <thead><tr><th>Subject</th><th>Status</th><th>Results</th><th>Submitting teacher</th><th>Submitted</th><th>Review / correction</th></tr></thead>
                <tbody>
                    <?php foreach ($registerSubjects as $subject): ?>
                        <tr>
                            <th scope="row"><?= e(mb_strtoupper((string) $subject['subject_name'], 'UTF-8')) ?></th>
                            <td><?= e(ucfirst(str_replace('_', ' ', (string) $subject['status']))) ?></td>
                            <td><?= (int) $subject['recorded_results'] ?> / <?= (int) $subject['active_students'] ?> recorded<?= (int) $subject['missing_results'] > 0 ? ' · ' . (int) $subject['missing_results'] . ' missing' : '' ?></td>
                            <td><?= e((string) ($subject['submitted_by_name'] ?? '—')) ?></td>
                            <td><?= e((string) ($subject['submitted_at'] ?? '—')) ?></td>
                            <td><?= e((string) (($subject['correction_reason'] ?? '') ?: '—')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <form method="get" action="assessments.php" class="form-stack register-individual-report-selector">
            <input type="hidden" name="register" value="1">
            <input type="hidden" name="register_year" value="<?= e($registerYear) ?>">
            <input type="hidden" name="register_term_id" value="<?= (int) $registerTermId ?>">
            <input type="hidden" name="register_class_id" value="<?= (int) $registerClassId ?>">
            <input type="hidden" name="register_assessment_name" value="<?= e($registerAssessmentName) ?>">
            <label for="register-report-student-search">Generate Individual Report</label>
            <input type="search" id="register-report-student-search" data-register-student-search placeholder="Search students by name" autocomplete="off">
            <select id="register-report-student" name="register_student_id" required>
                <option value="">Select one student</option>
                <?php foreach ($registerGroup['students'] as $reportStudent): ?>
                    <option value="<?= (int) $reportStudent['id'] ?>" <?= $registerIndividualStudentId !== false && (int) $registerIndividualStudentId === (int) $reportStudent['id'] ? 'selected' : '' ?>><?= e((string) $reportStudent['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="button" type="submit">Generate Individual Report</button>
        </form>
        <div class="table-scroll examination-register-scroll" role="region" aria-label="Scrollable class examination register" tabindex="0">
            <table class="examination-register-table">
                <thead>
                    <tr>
                        <th rowspan="2" class="register-student-column">Student Name</th>
                        <?php foreach ($registerSubjects as $subject): ?>
                            <th colspan="6" class="register-subject-heading register-group-end">
                                <?= e(mb_strtoupper((string) $subject['subject_name'], 'UTF-8')) ?>
                            </th>
                        <?php endforeach; ?>
                        <?php if ($schoolRole !== 'subject_teacher'): ?>
                            <th rowspan="2">Overall Average</th>
                            <th rowspan="2">Overall Grade</th>
                            <th rowspan="2" class="register-group-end">Overall Class Position</th>
                        <?php endif; ?>
                    </tr>
                    <tr>
                        <?php foreach ($registerSubjects as $_subject): ?>
                            <th>Class Score</th><th>Exam Score</th><th>Total Score</th><th>Grade</th><th>Remarks</th><th class="register-group-end">Subject Position</th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($registerGroup['students'] as $student): ?>
                        <tr>
                            <td class="register-student-column">
                                <strong><?= e(mb_strtoupper((string) $student['full_name'], 'UTF-8')) ?></strong>
                            </td>
                            <?php foreach ($registerSubjects as $subject): ?>
                                <?php $subjectScore = $student['subject_scores'][$subject['subject_id']] ?? null; ?>
                                <?php if ($subjectScore === null): ?>
                                    <td aria-label="No submitted result recorded" title="No submitted result recorded">—</td><td aria-label="No submitted result recorded" title="No submitted result recorded">—</td><td aria-label="No submitted result recorded" title="No submitted result recorded">—</td><td>—</td><td>—</td><td class="register-group-end">—</td>
                                <?php else: ?>
                                    <td><?= e(school_register_score_label($subjectScore['class_score'])) ?></td>
                                    <td><?= e(school_register_score_label($subjectScore['exam_score'])) ?></td>
                                    <td><?= e(school_register_score_label($subjectScore['score'])) ?></td>
                                    <td><?= e($subjectScore['grade']) ?></td>
                                    <td><?= e($subjectScore['remarks'] !== '' ? $subjectScore['remarks'] : '—') ?></td>
                                    <td class="register-group-end"><?= e(school_rank_position_label($subjectScore['position'] ?? null)) ?></td>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if ($schoolRole !== 'subject_teacher'): ?>
                                <td><?= $student['average'] === null ? '—' : e(number_format($student['average'], 2)) . '%' ?></td>
                                <td><?= e($student['grade'] ?? '—') ?></td>
                                <td class="register-group-end"><?= e(school_rank_position_label($student['position'])) ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($registerGroup['students'] === []): ?>
                        <tr><td colspan="<?= 1 + (count($registerSubjects) * 6) + ($schoolRole === 'subject_teacher' ? 0 : 3) ?>">No active students are enrolled in this class for the selected academic year.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($registerGroup['outstanding_subjects'] !== []): ?>
            <h3>Subjects requiring attention</h3>
            <?php foreach ($registerGroup['outstanding_subjects'] as $outstandingSubject): ?>
                <?php
                $missingStudentNames = [];
                foreach ($registerGroup['students'] as $registerStudent) {
                    if (!isset($registerStudent['subject_scores'][$outstandingSubject['subject_id']])) {
                        $missingStudentNames[] = (string) $registerStudent['full_name'];
                    }
                }
                ?>
                <details>
                    <summary>
                        <?= e($outstandingSubject['subject_name']) ?> —
                        <?= e(ucfirst(str_replace('_', ' ', (string) $outstandingSubject['status']))) ?>,
                        <?= (int) $outstandingSubject['missing_results'] ?> missing result(s),
                        teacher: <?= e($outstandingSubject['teacher_name']) ?>
                    </summary>
                    <?php if ($missingStudentNames !== []): ?><p><?= e(implode(', ', $missingStudentNames)) ?></p><?php endif; ?>
                </details>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php if ($schoolRole !== 'subject_teacher' && !$registerGroup['ranking_enabled']): ?>
            <p class="notice">Overall class ranking is disabled in the school’s result settings. No overall position is displayed.</p>
        <?php elseif ($schoolRole !== 'subject_teacher' && !$registerGroup['ready_for_report_cards']): ?>
            <p class="muted">Overall performance and position are withheld until all configured subjects have complete, reviewed results.</p>
        <?php endif; ?>
        <?php if ($registerIndividualStudent !== null): ?>
            <section class="panel register-individual-report individual-report-page" id="individual-report">
                <div class="attendance-tools"><button class="button button-primary" type="button" data-print-individual-report>Print / Save Individual Report</button></div>
                <div class="individual-report-output">
                    <?php
                    $registerSchoolLogoPath = (string) ($school['logo_path'] ?? '');
                    $registerSchoolLogoUrl = (int) ($school['id'] ?? 0) > 0
                        && preg_match('#^school-logos/[a-f0-9]{48}\.(?:jpg|png|webp)$#', $registerSchoolLogoPath) === 1
                        ? 'school_logo.php?school_id=' . (int) $school['id']
                        : null;
                    ?>
                    <article class="terminal-report-card">
                        <header class="terminal-report-header">
                            <div class="terminal-school-identity-row">
                                <div class="terminal-school-logo-slot"><?php if ($registerSchoolLogoUrl !== null): ?><img class="terminal-school-logo" src="<?= e($registerSchoolLogoUrl) ?>" alt=""><?php endif; ?></div>
                                <div class="terminal-school-name-address">
                                    <strong><?= e(strtoupper((string) ($school['name'] ?? 'School'))) ?></strong>
                                    <?php if (!empty($school['address'])): ?><span><?= e((string) $school['address']) ?></span><?php endif; ?>
                                    <?php if (!empty($school['contact_phone']) || !empty($school['contact_email'])): ?><span><?= e(trim((string) ($school['contact_phone'] ?? '') . (!empty($school['contact_email']) ? (!empty($school['contact_phone']) ? ' · ' : '') . $school['contact_email'] : ''))) ?></span><?php endif; ?>
                                </div>
                                <div class="terminal-school-logo-slot"><?php if ($registerSchoolLogoUrl !== null): ?><img class="terminal-school-logo" src="<?= e($registerSchoolLogoUrl) ?>" alt=""><?php endif; ?></div>
                            </div>
                            <h1 class="terminal-report-title">END OF TERM REPORT</h1>
                            <div class="terminal-report-year"><?= e($registerIndividualReport['academic_year']) ?> ACADEMIC YEAR</div>
                        </header>

                        <section class="report-card-section">
                            <div class="terminal-metadata-grid">
                                <div><span>Student Name</span><strong><?= e($registerIndividualReport['student_name']) ?></strong></div>
                                <div><span>Admission Number</span><strong><?= e($registerIndividualReport['admission_number']) ?></strong></div>
                                <div><span>Class</span><strong><?= e($registerIndividualReport['class_label']) ?></strong></div>
                                <div><span>Term</span><strong><?= e($registerIndividualReport['term_name']) ?></strong></div>
                                <div><span>Vacation Date</span><strong><?= e(!empty($registerIndividualReport['vacation_date']) ? date('d M Y', strtotime($registerIndividualReport['vacation_date'])) : 'Not configured') ?></strong></div>
                                <div><span>Next Term Begins</span><strong><?= e((string) $registerIndividualReport['next_term_date']) ?></strong></div>
                            </div>
                        </section>

                        <section class="report-card-section">
                            <table class="terminal-mark-table">
                                <colgroup>
                                    <col class="terminal-mark-subject">
                                    <col class="terminal-mark-class-score">
                                    <col class="terminal-mark-exam-score">
                                    <col class="terminal-mark-total">
                                    <col class="terminal-mark-grade">
                                    <col class="terminal-mark-position">
                                    <col class="terminal-mark-remarks">
                                </colgroup>
                                <thead><tr><th>SUBJECT</th><th>CLASS (30%)</th><th>EXAMS (70%)</th><th>TOTAL (100%)</th><th>GRADE</th><th>POSITION</th><th>REMARKS</th></tr></thead>
                                <tbody>
                                    <?php foreach ($registerIndividualReport['subjects'] as $subject): ?>
                                        <?php
                                        $classScore = $subject['class_score'] === null ? null : (float) $subject['class_score'];
                                        $examScore = $subject['exam_score'] === null ? null : (float) $subject['exam_score'];
                                        $totalScore = $classScore !== null && $examScore !== null ? $classScore + $examScore : null;
                                        ?>
                                        <tr>
                                            <td><?= e($subject['subject_name']) ?></td>
                                            <td><?= $classScore === null ? '—' : e(rtrim(rtrim(number_format($classScore, 2, '.', ''), '0'), '.')) ?></td>
                                            <td><?= $examScore === null ? '—' : e(rtrim(rtrim(number_format($examScore, 2, '.', ''), '0'), '.')) ?></td>
                                            <td><?= $totalScore === null ? '—' : e(rtrim(rtrim(number_format($totalScore, 2, '.', ''), '0'), '.')) ?></td>
                                            <td><?= e($subject['grade'] !== '' ? $subject['grade'] : '—') ?></td>
                                            <td><?= e(school_rank_position_label($subject['position'])) ?></td>
                                            <td><?= e($subject['remark'] !== '' ? $subject['remark'] : '—') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </section>

                        <section class="terminal-summary-strip">
                            <span><strong>Overall Class Position:</strong> <?= $registerIndividualReport['class_position'] === null ? 'Not ranked' : (int) $registerIndividualReport['class_position'] ?></span>
                            <span><strong>Attendance:</strong>
                                <?= $registerIndividualReport['attendance'] !== [] && isset($registerIndividualReport['attendance']['present'], $registerIndividualReport['attendance']['scheduled_days'])
                                    ? (int) $registerIndividualReport['attendance']['present'] . ' / ' . (int) $registerIndividualReport['attendance']['scheduled_days'] . ' days'
                                    : 'Not recorded' ?>
                            </span>
                        </section>

                        <section class="terminal-behaviour-grid report-card-section">
                            <div><strong>Conduct</strong><span><?= e((string) ($registerIndividualReport['conduct'] ?: '—')) ?></span></div>
                            <div><strong>Interest</strong><span><?= e((string) ($registerIndividualReport['interest'] ?: '—')) ?></span></div>
                            <div><strong>Attitude</strong><span><?= e((string) ($registerIndividualReport['attitude'] ?: '—')) ?></span></div>
                        </section>

                        <section class="terminal-comments-grid report-card-section">
                            <div><h2>CLASS TEACHER’S REMARKS</h2><p><?= nl2br(e($registerIndividualReport['teacher_comment'] !== '' ? $registerIndividualReport['teacher_comment'] : '—')) ?></p></div>
                            <div><h2>HEAD TEACHER’S REMARKS</h2><p><?= nl2br(e($registerIndividualReport['headmaster_remarks'] !== '' ? $registerIndividualReport['headmaster_remarks'] : '—')) ?></p></div>
                        </section>

                        <section class="terminal-signatures report-card-section">
                            <div><span>CLASS TEACHER’S SIGNATURE</span><strong><?= e($registerIndividualReport['class_teacher_signature_name']) ?></strong><div class="signature-box"></div><span>Date: ____________________</span></div>
                            <div><span>HEAD TEACHER’S SIGNATURE</span><strong><?= e($registerIndividualReport['headmaster_signature_name']) ?></strong><div class="signature-box"></div><span>Date: ____________________</span></div>
                            <div><span>SCHOOL STAMP</span><strong>&nbsp;</strong><div class="signature-box"></div></div>
                        </section>
                    </article>
                </div>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</section>

<section class="panel" id="examination-setup" data-action-view="examination-setup" style="margin-top:1.5rem;">
    <h2>Examination setup</h2>
    <form method="post" action="assessments.php" class="form-stack" data-assessment-setup-form>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_assessment">
        <div class="form-grid two-columns">
            <div>
                <label for="assessment_year">Academic year</label>
                <select id="assessment_year" name="academic_year" data-assessment-year-select required>
                    <option value="">Select academic year</option>
                    <?php foreach ($assessmentYears as $year): ?>
                        <option value="<?= e($year) ?>" <?= ($selectedAssessment !== null && $selectedAssessment['academic_year'] === $year) || ($selectedAssessment === null && isset($pageAcademicPeriod['academic_year']) && $pageAcademicPeriod['academic_year'] === $year) ? 'selected' : '' ?>><?= e(school_academic_year_label($year)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="assessment_term_id">Term</label>
                <select id="assessment_term_id" name="term_id" data-assessment-term-select required>
                    <option value="">Select term</option>
                    <?php foreach ($assessmentTerms as $term): ?>
                        <option value="<?= (int) $term['id'] ?>" data-academic-year="<?= e((string) $term['academic_year']) ?>" data-term-name="<?= e((string) $term['term_name']) ?>" <?= ($selectedAssessment !== null && $selectedAssessment['academic_year'] === $term['academic_year'] && $selectedAssessment['term_name'] === $term['term_name']) || ($selectedAssessment === null && isset($pageAcademicPeriod['academic_year'], $pageAcademicPeriod['term_name']) && $pageAcademicPeriod['academic_year'] === $term['academic_year'] && $pageAcademicPeriod['term_name'] === $term['term_name']) ? 'selected' : '' ?>><?= e((string) $term['term_name']) ?> · <?= e(school_academic_year_label((string) $term['academic_year'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="class_id">Class level</label>
                <select id="class_id" name="class_id" data-assessment-class-select required>
                    <option value="">Select class</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?= (int) $class['id'] ?>" data-academic-year="<?= e((string) $class['academic_year']) ?>" <?= $selectedAssessment !== null && (int) $selectedAssessment['class_id'] === (int) $class['id'] ? 'selected' : '' ?>><?= e(school_class_label($class)) ?><?= (string) $class['status'] !== 'active' ? ' · Archived (existing examinations only)' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="assessment_name">Examination type</label>
                <select id="assessment_name" name="assessment_name" required>
                    <option value="">Select examination type</option>
                    <?php foreach ($assessmentTypes as $assessmentType): ?>
                        <option value="<?= e($assessmentType) ?>" <?= ($selectedAssessment['assessment_name'] ?? '') === $assessmentType ? 'selected' : '' ?>><?= e($assessmentType) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="subject_id">Subject</label>
                <select id="subject_id" name="subject_id" data-assessment-subject-select required>
                    <option value="">Select subject</option>
                    <?php foreach ($subjects as $subject): ?>
                        <option value="<?= (int) $subject['id'] ?>" data-class-ids="<?= e((string) $subject['assigned_class_ids']) ?>" <?= $selectedAssessment !== null && (int) $selectedAssessment['subject_id'] === (int) $subject['id'] ? 'selected' : '' ?>><?= e((string) $subject['name']) ?><?= $subject['code'] ? ' (' . e((string) $subject['code']) . ')' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label for="total_marks">Total marks</label><input id="total_marks" name="total_marks" type="number" min="100" max="100" step="0.01" value="100" readonly></div>
        </div>
        <?php if ($assessmentTerms === []): ?><p class="muted">No terms are configured for open academic years or existing examinations. Add one in Academic Years &amp; Terms.</p><?php endif; ?>
        <?php if ($assessmentTypes === []): ?><p class="notice notice-error">No examination type is configured for this school. Ask an authorized administrator to configure an examination before recording marks.</p><?php endif; ?>
        <?php if ($teacherAccount && $classes === []): ?><p class="notice notice-error">No active class or subject assignments are linked to your account in this school, so there are no authorized class or subject choices to load. Ask the Headmaster or Proprietor to update your teaching assignments.</p><?php elseif ($teacherAccount && $subjects === []): ?><p class="notice notice-error">Your account has no subject assignments for the available classes. Ask an authorized administrator to assign the subjects you teach before entering marks.</p><?php endif; ?>
        <button class="button button-primary" type="submit" <?= $assessmentTerms === [] || $assessmentTypes === [] ? 'disabled' : '' ?>>LOAD CLASS STUDENTS</button>
    </form>
</section>

<section class="panel" data-action-view="examination-register" style="margin-top:1.5rem;">
    <details>
    <summary>Resume an examination draft or retrieve examination history</summary>
    <?php if ($pageAcademicPeriod !== null && (string) ($_GET['show_history'] ?? '') !== '1'): ?>
        <p><a class="button button-quiet" href="assessments.php?show_history=1">Show records from all academic years and terms</a></p>
    <?php elseif ((string) ($_GET['show_history'] ?? '') === '1' && $pageAcademicPeriod !== null): ?>
        <p><a class="button button-quiet" href="assessments.php">Return to the current academic period</a></p>
    <?php endif; ?>
    <?php if ($assessments === []): ?>
        <p class="muted">No assessments have been created for this school yet.</p>
    <?php else: ?>
        <?php render_dashboard_bar_chart('Assessment records by workflow status', $assessmentWorkflowChart, 'assessments'); ?>
        <?php if ($assessmentCompletionChart !== []): ?>
            <p class="muted">Completion covers up to the 10 most recently created assessments and counts active students in each assessment’s current class roster. Assessments with no active roster are omitted.</p>
            <?php render_dashboard_bar_chart('Roster result completion · 10 latest assessments', $assessmentCompletionChart, '%'); ?>
        <?php endif; ?>
        <table>
            <thead>
                <tr><th>Assessment</th><th>Class</th><th>Academic year</th><th>Subject</th><th>Term</th><th>Marks</th><th>Results</th><th>Status</th><th>Action</th></tr>
            </thead>
            <tbody>
                <?php foreach ($assessments as $assessment): ?>
                    <tr>
                        <td><?= e((string) $assessment['assessment_name']) ?></td>
                        <td><?= e(school_class_label(['level' => $assessment['class_level'], 'section' => $assessment['class_section']])) ?></td>
                        <td><?= e(school_academic_year_label((string) $assessment['academic_year'])) ?></td>
                        <td><?= e((string) $assessment['subject_name']) ?><?= $assessment['code'] ? ' (' . e((string) $assessment['code']) . ')' : '' ?></td>
                        <td><?= e((string) $assessment['term_name']) ?></td>
                        <td><?= e((string) number_format((float) $assessment['total_marks'], 2, '.', ',')) ?></td>
                        <td><?= (int) $assessment['result_count'] ?></td>
                        <td><?= e(ucfirst(str_replace('_', ' ', (string) $assessment['workflow_status']))) ?></td>
                        <td><a href="assessments.php?assessment_id=<?= (int) $assessment['id'] ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    </details>
</section>

<?php if ($selectedAssessment): ?>
    <section class="panel" data-action-view="examination-register" style="margin-top:1.5rem;">
        <h2><?= $selectedAssessment['published_at'] ? 'Published results: ' : 'Results: ' ?><?= e((string) $selectedAssessment['assessment_name']) ?></h2>
        <p class="muted">Class: <?= e(school_class_label(['level' => $selectedAssessment['class_level'], 'section' => $selectedAssessment['class_section']])) ?> · Academic year: <?= e(school_academic_year_label((string) $selectedAssessment['academic_year'])) ?> · Subject: <?= e((string) $selectedAssessment['subject_name']) ?> · Term: <?= e((string) $selectedAssessment['term_name']) ?> · Maximum marks: <?= e((string) number_format((float) $selectedAssessment['total_marks'], 2, '.', ',')) ?></p>
        <p><strong>Status:</strong> <?= e(ucfirst(str_replace('_', ' ', (string) $selectedAssessment['workflow_status']))) ?><?= $selectedAssessment['submitted_at'] ? ' · Submitted ' . e((string) $selectedAssessment['submitted_at']) : '' ?><?= $selectedAssessment['reviewed_at'] ? ' · Reviewed ' . e((string) $selectedAssessment['reviewed_at']) : '' ?></p>
        <?php if ($selectedAssessment['submitted_to_name']): ?><p><strong>Latest submission routed to:</strong> <?= e((string) $selectedAssessment['submitted_to_name']) ?></p><?php endif; ?>
        <?php if ($selectedAssessment['correction_reason']): ?><p class="notice notice-error">Correction requested: <?= e((string) $selectedAssessment['correction_reason']) ?></p><?php endif; ?>
        <?php if ($selectedSubmissionHistory !== []): ?>
            <details>
                <summary>Submission, routing, and review history</summary>
                <div class="table-scroll"><table>
                    <thead><tr><th>Submission</th><th>Submitted by</th><th>Originally routed to</th><th>Submitted at</th><th>Status</th><th>Reviewed by / date</th><th>Return reason</th></tr></thead>
                    <tbody>
                        <?php foreach ($selectedSubmissionHistory as $submissionHistory): ?>
                            <tr>
                                <td><?= (int) $submissionHistory['submission_number'] ?></td>
                                <td><?= e((string) ($submissionHistory['submitted_by_name'] ?? 'Former staff account')) ?></td>
                                <td><?= e((string) ($submissionHistory['recipient_name'] ?? 'Assignment not retained')) ?></td>
                                <td><?= e((string) $submissionHistory['submitted_at']) ?> UTC</td>
                                <td><?= e(ucfirst(str_replace('_', ' ', (string) $submissionHistory['workflow_status']))) ?></td>
                                <td><?= e((string) ($submissionHistory['reviewed_by_name'] ?? '—')) ?><?= $submissionHistory['reviewed_at'] ? ' · ' . e((string) $submissionHistory['reviewed_at']) . ' UTC' : '' ?></td>
                                <td><?= e((string) ($submissionHistory['correction_reason'] ?? '—')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table></div>
            </details>
        <?php endif; ?>
        <?php $canManageSelectedCorrections = in_array($schoolRole, ['school_proprietor', 'school_headmaster'], true); ?>
        <?php $selectedWorkflowStatus = (string) $selectedAssessment['workflow_status']; ?>
        <?php $headmasterMustDelegateIncompleteCorrection = $schoolRole === 'school_headmaster'
            && $selectedAssessmentHasIncompleteRoster
            && in_array($selectedWorkflowStatus, ['submitted', 'reviewed', 'published'], true); ?>
        <?php $isIncompleteEditingWorkflow = in_array($selectedWorkflowStatus, ['draft', 'correction_requested', 'corrected'], true)
            || (in_array($selectedWorkflowStatus, ['submitted', 'reviewed', 'published'], true)
                && $selectedAssessmentHasIncompleteRoster); ?>
        <?php $canEditIncompleteAssessment = $isIncompleteEditingWorkflow && $schoolRole !== 'school_headmaster'; ?>
        <?php if ($canEditIncompleteAssessment && $schoolRole === 'class_teacher'): ?>
            <?php $canEditIncompleteAssessment = school_teacher_has_class_assignment(
                (int) $user['id'],
                $schoolId,
                (int) $selectedAssessment['class_id'],
                (string) $selectedAssessment['academic_year']
            ); ?>
            <?php if ($canEditIncompleteAssessment): ?>
                <?php
                $selectedClassSubjectStmt = db()->prepare(
                    'SELECT 1 FROM class_subjects
                     WHERE school_id = ? AND class_id = ? AND subject_id = ? LIMIT 1'
                );
                $selectedClassSubjectStmt->execute([
                    $schoolId,
                    (int) $selectedAssessment['class_id'],
                    (int) $selectedAssessment['subject_id'],
                ]);
                $canEditIncompleteAssessment = (bool) $selectedClassSubjectStmt->fetchColumn();
                ?>
            <?php endif; ?>
        <?php endif; ?>
        <?php if (
            (in_array((string) $selectedAssessment['workflow_status'], ['submitted', 'corrected', 'reviewed', 'published'], true)
                && !($canManageSelectedCorrections
                    && in_array((string) $selectedAssessment['workflow_status'], ['submitted', 'reviewed', 'published'], true)
                    && !$headmasterMustDelegateIncompleteCorrection))
            || ($isIncompleteEditingWorkflow && !$canEditIncompleteAssessment)
        ): ?>
            <h3>Saved class results</h3>
            <p class="muted"><?= $recordedStudentCount ?> of <?= $eligibleStudentCount ?> active students have saved marks. Subject positions are calculated only when the whole active class has marks.</p>
            <div class="table-scroll"><table>
                <thead><tr><th>#</th><th>Student</th><th>Admission number</th><th>Class score</th><th>Examination score</th><th>Total</th><th>Grade</th><th>Remarks</th><th>Subject position</th></tr></thead>
                <tbody>
                    <?php foreach ($selectedStudents as $studentIndex => $student): ?>
                        <?php $studentScore = $student['score'] === null ? null : (float) $student['score']; $studentGrade = $studentScore !== null && $gradeRules !== [] ? school_grade_rule_for_percent($schoolId, ($studentScore / (float) $selectedAssessment['total_marks']) * 100) : null; ?>
                        <tr>
                            <td><?= $studentIndex + 1 ?></td>
                            <td><?= e((string) $student['full_name']) ?></td>
                            <td><?= e((string) ($student['admission_number'] ?? '—')) ?></td>
                            <td><?= $student['class_score'] === null ? '—' : e(number_format((float) $student['class_score'], 2)) ?></td>
                            <td><?= $student['exam_score'] === null ? '—' : e(number_format((float) $student['exam_score'], 2)) ?></td>
                            <td><?= $studentScore === null ? 'Missing' : e(number_format($studentScore, 2)) . ' / ' . e(number_format((float) $selectedAssessment['total_marks'], 2)) ?></td>
                            <td><?= $studentGrade === null ? '—' : e((string) $studentGrade['grade']) ?></td>
                            <td><?= $studentScore === null ? '—' : e((string) ($student['remarks'] ?? '')) ?></td>
                            <td><?= e(school_rank_position_label($selectedSubjectPositions[(int) $student['id']] ?? null)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
        <?php if ($isIncompleteEditingWorkflow && $schoolRole === 'school_headmaster'): ?>
            <p class="notice">Incomplete examination records must be edited by the Class Teacher assigned to this class. You can still review and manage results through the approved examination workflow.</p>
        <?php elseif ($isIncompleteEditingWorkflow && $schoolRole === 'class_teacher' && !$canEditIncompleteAssessment): ?>
            <p class="notice notice-error">Editing requires an active Class Teacher assignment for this class and academic year.</p>
        <?php elseif (in_array($selectedWorkflowStatus, ['submitted', 'reviewed', 'published'], true)
            && $selectedAssessmentHasIncompleteRoster
            && $schoolRole === 'class_teacher'
            && $canEditIncompleteAssessment): ?>
            <p class="notice">This approved record has missing student marks. Enter a correction reason, complete the assigned class roster, and save; the published result will be withdrawn and returned for review.</p>
        <?php endif; ?>
        <?php if ($headmasterMustDelegateIncompleteCorrection && in_array($selectedWorkflowStatus, ['reviewed', 'published'], true)): ?>
            <p class="notice">This record is already reviewed or published. Reopen it for the assigned Class Teacher to make audited corrections; this does not publish the changes.</p>
            <form method="post" action="assessments.php?assessment_id=<?= (int) $selectedAssessment['id'] ?>" class="form-stack">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reopen_reviewed_results">
                <input type="hidden" name="assessment_id" value="<?= (int) $selectedAssessment['id'] ?>">
                <label for="reopen-assessment-reason">Reason for reopening</label>
                <textarea id="reopen-assessment-reason" name="reason" rows="3" maxlength="500" required></textarea>
                <button class="button" type="submit">Reopen for Class Teacher correction</button>
            </form>
        <?php endif; ?>
        <?php if ($selectedAssessment['published_at'] && !$canManageSelectedCorrections && !$canEditIncompleteAssessment): ?>
            <p class="muted">Published <?= e((string) $selectedAssessment['published_at']) ?>. Results are read-only; contact the Headmaster or Proprietor if a correction is needed.</p>
        <?php elseif ((string) $selectedAssessment['workflow_status'] === 'reviewed'
            && !$canManageSelectedCorrections
            && !$canEditIncompleteAssessment): ?>
            <p class="muted">Reviewed results are read-only. Ask the Headmaster or Proprietor to reopen this incomplete record for the assigned Class Teacher to correct.</p>
        <?php elseif ($canEditIncompleteAssessment
            || ($canManageSelectedCorrections
                && !$headmasterMustDelegateIncompleteCorrection
                && in_array((string) $selectedAssessment['workflow_status'], ['submitted', 'reviewed', 'published'], true))): ?>
        <?php if ($canManageSelectedCorrections && !$headmasterMustDelegateIncompleteCorrection
            && in_array((string) $selectedAssessment['workflow_status'], ['submitted', 'reviewed', 'published'], true)): ?>
            <p class="notice">You may correct these saved marks. Any changed marks require a reason, are recorded in the revision history, and return results to review before they can be published again.</p>
        <?php endif; ?>
        <?php if ($selectedStudents === []): ?>
            <p class="notice notice-error">
                No active student records are assigned to this class for <?= e(school_academic_year_label((string) $selectedAssessment['academic_year'])) ?>.
                The database currently has <?= (int) ($selectedRosterDiagnostic['assigned_students'] ?? 0) ?> student record(s) assigned,
                <?= (int) ($selectedRosterDiagnostic['active_students'] ?? 0) ?> active and
                <?= (int) ($selectedRosterDiagnostic['inactive_students'] ?? 0) ?> inactive.
                Check the existing class assignment and student status in Student Management. No examination marks were changed.
            </p>
        <?php else: ?>
        <form method="post" action="assessments.php?assessment_id=<?= (int) $selectedAssessment['id'] ?>" class="form-stack" data-marks-entry-form data-assessment-summary="<?= e((string) $school['name'] . ' · ' . school_academic_year_label((string) $selectedAssessment['academic_year']) . ' · ' . $selectedAssessment['term_name'] . ' · ' . school_class_label(['level' => $selectedAssessment['class_level'], 'section' => $selectedAssessment['class_section']]) . ' · ' . $selectedAssessment['subject_name'] . ' · ' . $selectedAssessment['assessment_name']) ?>" data-grade-rules="<?= e((string) json_encode(array_map(static fn(array $rule): array => ['grade' => $rule['grade'], 'minimum_percent' => $rule['minimum_percent'], 'remark' => $rule['remark']], $gradeRules), JSON_THROW_ON_ERROR)) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="assessment_id" value="<?= (int) $selectedAssessment['id'] ?>">
            <?php if ($gradeRules === []): ?><p class="notice notice-error">Configure the school grading scale before finalizing results.</p><?php endif; ?>
            <p class="muted"><strong>Batch progress:</strong> <?= $recordedStudentCount ?> of <?= $eligibleStudentCount ?> students recorded. <?= $eligibleStudentCount - $recordedStudentCount ?> still need marks. Drafts can be saved and resumed. <span data-entry-progress aria-live="polite"></span></p>
            <?php if ($recordedStudentCount < $eligibleStudentCount): ?>
                <details><summary>Students awaiting marks (<?= $eligibleStudentCount - $recordedStudentCount ?>)</summary><ul><?php foreach ($selectedStudents as $student): ?><?php if ($student['score'] === null): ?><li><?= e((string) $student['full_name']) ?> · <?= e((string) ($student['admission_number'] ?? 'No admission number')) ?></li><?php endif; ?><?php endforeach; ?></ul></details>
            <?php endif; ?>
            <div class="inline-form">
                <div><label for="assessment-student-picker">Choose an eligible student</label><select id="assessment-student-picker" data-student-picker><option value="">Select a student to enter or edit</option><?php foreach ($selectedStudents as $student): ?><option value="<?= (int) $student['id'] ?>"><?= e((string) $student['full_name']) ?> · <?= e((string) ($student['admission_number'] ?? 'No admission number')) ?><?= $student['score'] !== null ? ' · Recorded' : ' · Awaiting marks' ?></option><?php endforeach; ?></select></div>
                <button class="button" type="button" data-add-another-student>Add Another Student’s Record</button>
                <button class="button button-quiet" type="button" data-review-records>Review Class Results</button>
            </div>
            <table>
                <thead>
                    <tr><th>Number</th><th>Student name</th><th>Admission number</th><th>Class score (Max. <?= e(number_format((float) $selectedAssessment['total_marks'] * (float) $selectedAssessment['class_score_weight'] / 100, 2)) ?>)</th><th>Examination score (Max. <?= e(number_format((float) $selectedAssessment['total_marks'] * (float) $selectedAssessment['exam_score_weight'] / 100, 2)) ?>)</th><th>Total score (Max. <?= e(number_format((float) $selectedAssessment['total_marks'], 2)) ?>)</th><th>Grade</th><th>Remarks</th><th>Record status</th><th>Action</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($selectedStudents as $studentIndex => $student): ?>
                        <?php $score = $student['score'] !== null ? (float) $student['score'] : 0.0; $classScore = (float) ($student['class_score'] ?? 0); $examScore = $student['exam_score'] !== null ? (float) $student['exam_score'] : $score; $classScoreMaximum = (float) $selectedAssessment['total_marks'] * (float) $selectedAssessment['class_score_weight'] / 100; $examScoreMaximum = (float) $selectedAssessment['total_marks'] * (float) $selectedAssessment['exam_score_weight'] / 100; $studentGrading = $student['score'] !== null && $gradeRules !== [] ? school_grade_rule_for_percent($schoolId, ($score / (float) $selectedAssessment['total_marks']) * 100) : null; ?>
                        <tr id="student-row-<?= (int) $student['id'] ?>" data-entry-row data-result-row data-student-id="<?= (int) $student['id'] ?>" data-total-marks="<?= e(number_format((float) $selectedAssessment['total_marks'], 2, '.', '')) ?>" data-class-score-maximum="<?= e(number_format($classScoreMaximum, 2, '.', '')) ?>" data-exam-score-maximum="<?= e(number_format($examScoreMaximum, 2, '.', '')) ?>">
                            <td><?= $studentIndex + 1 ?></td>
                            <td><?= e((string) $student['full_name']) ?></td>
                            <td><?= e((string) ($student['admission_number'] ?? '—')) ?></td>
                            <td><?php if ($classScoreMaximum > 0): ?><input aria-label="Class score for <?= e((string) $student['full_name']) ?>" data-mark-component="class" type="number" name="class_score[<?= (int) $student['id'] ?>]" value="<?= $student['score'] !== null ? e(number_format($classScore, 2, '.', '')) : '' ?>" min="0" max="<?= e(number_format($classScoreMaximum, 2, '.', '')) ?>" step="0.01"><?php else: ?>0 / <?= e(number_format($classScoreMaximum, 2)) ?><input type="hidden" name="class_score[<?= (int) $student['id'] ?>]" value="0"><?php endif; ?></td>
                            <td><?php if ($examScoreMaximum > 0): ?><input aria-label="Examination score for <?= e((string) $student['full_name']) ?>" data-mark-component="exam" type="number" name="exam_score[<?= (int) $student['id'] ?>]" value="<?= $student['exam_score'] !== null || $student['score'] !== null ? e(number_format($examScore, 2, '.', '')) : '' ?>" min="0" max="<?= e(number_format($examScoreMaximum, 2, '.', '')) ?>" step="0.01"><?php else: ?>0 / <?= e(number_format($examScoreMaximum, 2)) ?><input type="hidden" name="exam_score[<?= (int) $student['id'] ?>]" value="0"><?php endif; ?></td>
                            <td><output data-calculated-total><?= $student['score'] !== null ? e(number_format($score, 2)) : '—' ?></output></td>
                            <td><output data-calculated-grade><?= $studentGrading !== null ? e((string) $studentGrading['grade']) : '—' ?></output></td>
                            <td><output data-calculated-remark><?= $studentGrading !== null ? e((string) $studentGrading['remark']) : '—' ?></output></td>
                            <td><?= $student['score'] !== null ? e(ucfirst(str_replace('_', ' ', (string) $selectedAssessment['workflow_status']))) : 'Not Entered' ?></td>
                            <td><button class="button button-quiet" type="button" data-edit-student-record="<?= (int) $student['id'] ?>">Edit record</button></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="form-grid two-columns">
                <div><label for="revision-reason">Correction reason (required when changing saved marks)</label><textarea id="revision-reason" name="revision_reason" rows="2" maxlength="500"><?= e((string) ($selectedAssessment['correction_reason'] ?? '')) ?></textarea></div>
            </div>
            <button class="button" type="submit" name="action" value="save_results"><?= $canManageSelectedCorrections && !$headmasterMustDelegateIncompleteCorrection && in_array((string) $selectedAssessment['workflow_status'], ['submitted', 'reviewed', 'published'], true) ? 'Save Authorized Corrections' : ($schoolRole === 'class_teacher' ? 'Save Changes' : 'Save Draft') ?></button>
            <button class="button" type="submit" name="action" value="save_results_continue">Save and Continue</button>
            <?php if ($canManageSelectedCorrections && !$headmasterMustDelegateIncompleteCorrection && (string) $selectedAssessment['workflow_status'] === 'reviewed'): ?>
                <button class="button button-primary" type="submit" name="action" value="publish_results">Publish reviewed results to students</button>
            <?php endif; ?>
            <?php if (!$canManageSelectedCorrections || $headmasterMustDelegateIncompleteCorrection || !in_array((string) $selectedAssessment['workflow_status'], ['submitted', 'reviewed', 'published'], true)): ?>
                <button class="button button-primary" type="submit" name="action" value="submit_results"><?= $schoolRole === 'class_teacher' && school_class_level_is_primary((string) $selectedAssessment['class_level'], (string) $selectedAssessment['class_stage']) ? 'Save and Approve Student Records' : 'Submit Marks for Review' ?></button>
            <?php endif; ?>
        </form>
        <?php endif; ?>
        <?php elseif (in_array((string) $selectedAssessment['workflow_status'], ['submitted', 'corrected'], true)
            && (in_array($schoolRole, ['school_proprietor', 'school_headmaster'], true)
                || ($schoolRole === 'class_teacher'
                    && school_class_level_is_jhs((string) $selectedAssessment['class_level'], (string) $selectedAssessment['class_stage'])
                    && (int) ($selectedAssessment['submitted_by'] ?? 0) !== (int) $user['id']
                    && school_teacher_has_class_assignment(
                        (int) $user['id'],
                        $schoolId,
                        (int) $selectedAssessment['class_id'],
                        (string) $selectedAssessment['academic_year']
                    )))): ?>
        <div class="inline-form">
        <form method="post" action="assessments.php?assessment_id=<?= (int) $selectedAssessment['id'] ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="review_results">
            <input type="hidden" name="assessment_id" value="<?= (int) $selectedAssessment['id'] ?>">
            <button class="button button-primary" type="submit">Review and approve results</button>
        </form>
        <details><summary>Request corrections</summary>
            <form method="post" action="assessments.php?assessment_id=<?= (int) $selectedAssessment['id'] ?>" class="form-stack">
                <?= csrf_field() ?><input type="hidden" name="action" value="request_correction"><input type="hidden" name="assessment_id" value="<?= (int) $selectedAssessment['id'] ?>">
                <label for="correction-request-reason">Reason</label><textarea id="correction-request-reason" name="reason" rows="3" maxlength="500" required></textarea>
                <button class="button" type="submit">Send correction request</button>
            </form>
        </details>
        </div>
        <?php elseif ((string) $selectedAssessment['workflow_status'] === 'reviewed'
            && $canManageSelectedCorrections): ?>
            <p class="muted">Reviewed results can be corrected above. Saving a change unpublishes/reopens them and records a new correction revision for review.</p>
        <?php elseif ((string) $selectedAssessment['workflow_status'] === 'reviewed'
            && $teacherAccount): ?>
            <p class="muted">These reviewed results are locked. Ask the Headmaster or Proprietor to reopen them for correction.</p>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php if (in_array($schoolRole, ['school_proprietor', 'school_headmaster'], true)): ?>
    <section class="panel" id="grading-settings" data-action-view="grading-settings" style="margin-top:1.5rem;">
        <details>
            <summary>Manage grading, score weighting, and ranking</summary>
            <form method="post" action="assessments.php" class="form-stack">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_result_settings">
                <div class="form-grid two-columns">
                    <div>
                        <label for="class-score-weight">Class assessment weighting (%)</label>
                        <input id="class-score-weight" name="class_score_weight" type="number" min="0" max="100" step="0.01" value="<?= e(number_format($classScoreWeight, 2, '.', '')) ?>" required>
                    </div>
                    <div>
                        <label for="exam-score-weight">Examination weighting (%)</label>
                        <input id="exam-score-weight" type="number" value="<?= e(number_format($examScoreWeight, 2, '.', '')) ?>" readonly>
                    </div>
                    <div>
                        <label for="ranking-policy">Ranking policy</label>
                        <select id="ranking-policy" name="ranking_policy" required>
                            <option value="competition" <?= $rankingPolicy === 'competition' ? 'selected' : '' ?>>Competition</option>
                            <option value="dense" <?= $rankingPolicy === 'dense' ? 'selected' : '' ?>>Dense</option>
                        </select>
                    </div>
                    <div>
                        <label><input type="checkbox" name="ranking_enabled" value="1" <?= $rankingEnabled ? 'checked' : '' ?>> Enable student ranking</label>
                    </div>
                </div>
                <h3>Configured grade rules</h3>
                <p class="muted">Use the school’s configured grade boundaries. Rules are evaluated from the highest minimum percentage down.</p>
                <table>
                    <thead><tr><th>Grade</th><th>Minimum percentage</th><th>Order</th><th>Remark</th><th>Action</th></tr></thead>
                    <tbody data-grade-rule-list>
                        <?php foreach ($gradeRules ?: [['grade' => '', 'minimum_percent' => 0, 'grade_order' => 1, 'remark' => '']] as $rule): ?>
                            <tr data-grade-rule-row>
                                <td><input aria-label="Grade label" name="grade[]" value="<?= e((string) $rule['grade']) ?>" maxlength="10" required></td>
                                <td><input aria-label="Minimum percentage" name="minimum_percent[]" type="number" min="0" max="100" step="0.01" value="<?= e(number_format((float) $rule['minimum_percent'], 2, '.', '')) ?>" required></td>
                                <td><input aria-label="Grade order" name="grade_order[]" type="number" min="1" max="12" value="<?= (int) $rule['grade_order'] ?>" required></td>
                                <td><input aria-label="Grade remark" name="grade_remark[]" value="<?= e((string) ($rule['remark'] ?? '')) ?>" maxlength="120"></td>
                                <td><button class="button button-quiet" type="button" data-remove-grade-rule aria-label="Remove grade rule">Remove</button></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="inline-form">
                    <button class="button" type="button" data-add-grade-rule>Add grade rule</button>
                    <button class="button button-primary" type="submit">Save grading and result settings</button>
                </div>
            </form>
        </details>
    </section>
<?php endif; ?>
<?php render_footer(); ?>
