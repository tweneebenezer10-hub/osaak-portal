<?php
declare(strict_types=1);

function school_grade_for_percent(int $schoolId, float $percent): string
{
    return (string) school_grade_rule_for_percent($schoolId, $percent)['grade'];
}

function school_insert_grade_rules(PDO $pdo, int $schoolId, array $rules): void
{
    $columns = $pdo->query('SHOW COLUMNS FROM school_grade_rules')->fetchAll(PDO::FETCH_COLUMN);
    $hasGradeCode = in_array('grade_code', $columns, true);
    $insert = $pdo->prepare($hasGradeCode
        ? 'INSERT INTO school_grade_rules (school_id, grade_code, grade, minimum_percent, grade_order, remark)
           VALUES (?, ?, ?, ?, ?, ?)'
        : 'INSERT INTO school_grade_rules (school_id, grade, minimum_percent, grade_order, remark)
           VALUES (?, ?, ?, ?, ?)');

    foreach ($rules as [$grade, $minimum, $order, $remark]) {
        $values = [$schoolId];
        if ($hasGradeCode) {
            $values[] = $grade;
        }
        array_push($values, $grade, $minimum, $order, $remark);
        $insert->execute($values);
    }
}

function school_grade_rule_for_percent(int $schoolId, float $percent): array
{
    if (!is_finite($percent) || $percent < 0 || $percent > 100) {
        throw new InvalidArgumentException('A grade percentage must be between 0 and 100.');
    }
    static $rulesBySchool = [];
    if (!array_key_exists($schoolId, $rulesBySchool)) {
        $stmt = db()->prepare(
            'SELECT grade, minimum_percent, remark FROM school_grade_rules
             WHERE school_id = ? ORDER BY minimum_percent DESC, grade_order'
        );
        $stmt->execute([$schoolId]);
        $rulesBySchool[$schoolId] = $stmt->fetchAll();
    }
    foreach ($rulesBySchool[$schoolId] as $rule) {
        if ((float) $rule['minimum_percent'] <= $percent) {
            return [
                'grade' => (string) $rule['grade'],
                'remark' => (string) ($rule['remark'] ?? ''),
            ];
        }
    }
    throw new RuntimeException('No grading rule covers this score. Configure the school grading scale.');
}

function school_assessment_result_grade(int $schoolId, float $score, float $totalMarks): string
{
    if (!is_finite($score) || !is_finite($totalMarks) || $totalMarks <= 0 || $score < 0 || $score > $totalMarks) {
        throw new InvalidArgumentException('The score must be within the assessment mark range.');
    }
    return (string) school_grade_rule_for_percent($schoolId, ($score / $totalMarks) * 100)['grade'];
}

function school_assessment_mark(mixed $rawMark, float $maximum): float
{
    if (!is_finite($maximum) || $maximum < 0
        || (!is_string($rawMark) && !is_int($rawMark) && !is_float($rawMark))
        || trim((string) $rawMark) === ''
        || !is_numeric(trim((string) $rawMark))) {
        throw new InvalidArgumentException('Enter a valid numeric mark.');
    }
    $mark = (float) trim((string) $rawMark);
    if (!is_finite($mark) || $mark < 0 || $mark > $maximum) {
        throw new InvalidArgumentException('Marks must be between zero and the displayed maximum.');
    }
    return round($mark, 2);
}

function school_class_level_is_jhs(string $level, string $academicStage = ''): bool
{
    if ($academicStage !== '') {
        return $academicStage === 'secondary';
    }

    return preg_match('/^(?:jhs|junior\s+high|shs|senior\s+high|high\s+school)(?:\s|$)/iu', trim($level)) === 1;
}

function school_class_level_is_primary(string $level, string $academicStage = ''): bool
{
    if ($academicStage !== '') {
        return $academicStage === 'primary';
    }

    return preg_match('/^(?:creche|crèche|nursery|kg\s*\d*|kindergarten|primary|elementary|early\s+childhood)(?:\s|$)/iu', trim($level)) === 1;
}

function school_rank_positions(array $scoresByStudent, string $rankingPolicy): array
{
    if (!in_array($rankingPolicy, ['competition', 'dense'], true)) {
        $rankingPolicy = 'competition';
    }
    if ($scoresByStudent === []) {
        return [];
    }
    foreach ($scoresByStudent as $studentId => $score) {
        if (!is_numeric($score) || !is_finite((float) $score)) {
            throw new InvalidArgumentException('Ranking scores must be finite numbers.');
        }
        $scoresByStudent[$studentId] = (float) $score;
    }
    arsort($scoresByStudent, SORT_NUMERIC);
    $positions = [];
    $index = 0;
    $competitionPosition = 0;
    $densePosition = 0;
    $lastScore = null;
    foreach ($scoresByStudent as $studentId => $score) {
        $index++;
        if ($lastScore === null || $score !== $lastScore) {
            $competitionPosition = $index;
            $densePosition++;
            $lastScore = $score;
        }
        $positions[(int) $studentId] = $rankingPolicy === 'dense'
            ? $densePosition
            : $competitionPosition;
    }
    return $positions;
}

function school_rank_position_label(?int $position): string
{
    if ($position === null || $position < 1) {
        return '—';
    }
    $lastTwoDigits = $position % 100;
    $suffix = $lastTwoDigits >= 11 && $lastTwoDigits <= 13
        ? 'th'
        : match ($position % 10) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th',
        };
    return $position . $suffix;
}

function school_assessment_group(
    int $schoolId,
    int $classId,
    string $academicYear,
    string $termName,
    string $assessmentName
): array {
    $classStmt = db()->prepare(
        'SELECT id, school_id, level, section, academic_year
         FROM school_classes
         WHERE id = ? AND school_id = ? AND academic_year = ? LIMIT 1'
    );
    $classStmt->execute([$classId, $schoolId, $academicYear]);
    $class = $classStmt->fetch();
    if (!$class) {
        throw new InvalidArgumentException('The requested results group is not available in this school.');
    }

    $subjectStmt = db()->prepare(
        'SELECT cs.subject_id, subject.name AS subject_name, subject.code,
                cs.teacher_user_id, teacher.full_name AS teacher_name,
                assessment.id AS assessment_id, assessment.total_marks,
                assessment.class_score_weight, assessment.exam_score_weight,
                assessment.workflow_status, assessment.published_at,
                assessment.submitted_at, assessment.reviewed_at,
                assessment.correction_reason, submitter.full_name AS submitted_by_name,
                (SELECT COUNT(*) FROM school_students student
                 WHERE student.school_id = cs.school_id AND student.class_id = cs.class_id
                   AND student.school_status = "active") AS active_students,
                (SELECT COUNT(*) FROM assessment_results result
                 JOIN school_students result_student ON result_student.id = result.student_id
                   AND result_student.school_id = result.school_id
                 WHERE result.school_id = cs.school_id AND result.assessment_id = assessment.id
                   AND assessment.workflow_status IN
                       ("submitted", "correction_requested", "corrected", "reviewed", "published")
                   AND result_student.class_id = cs.class_id
                   AND result_student.school_status = "active") AS recorded_results
         FROM class_subjects cs
         JOIN school_subjects subject ON subject.id = cs.subject_id AND subject.school_id = cs.school_id
         LEFT JOIN users teacher ON teacher.id = cs.teacher_user_id
         LEFT JOIN school_assessments assessment ON assessment.school_id = cs.school_id
           AND assessment.class_id = cs.class_id AND assessment.subject_id = cs.subject_id
           AND assessment.term_name = ? AND assessment.assessment_name = ?
           AND assessment.academic_year = ?
         LEFT JOIN users submitter ON submitter.id = assessment.submitted_by
         WHERE cs.school_id = ? AND cs.class_id = ?
         ORDER BY subject.name, assessment.created_at DESC'
    );
    $subjectStmt->execute([$termName, $assessmentName, $academicYear, $schoolId, $classId]);
    $allSubjectRows = $subjectStmt->fetchAll();
    $subjects = [];
    foreach ($allSubjectRows as $row) {
        $subjectId = (int) $row['subject_id'];
        if (isset($subjects[$subjectId])) {
            continue;
        }
        $status = $row['assessment_id'] === null
            ? 'pending'
            : ((string) ($row['published_at'] ?? '') !== ''
                ? 'published'
                : (string) ($row['workflow_status'] ?? 'draft'));
        $activeStudents = (int) $row['active_students'];
        $recordedResults = (int) $row['recorded_results'];
        $subjects[$subjectId] = [
            'subject_id' => $subjectId,
            'subject_name' => (string) $row['subject_name'],
            'code' => $row['code'],
            'teacher_user_id' => $row['teacher_user_id'] === null ? null : (int) $row['teacher_user_id'],
            'teacher_name' => $row['teacher_name'] === null ? 'Unassigned' : (string) $row['teacher_name'],
            'assessment_id' => $row['assessment_id'] === null ? null : (int) $row['assessment_id'],
            'total_marks' => $row['total_marks'] === null ? null : (float) $row['total_marks'],
            'class_score_weight' => $row['class_score_weight'] === null ? 0.0 : (float) $row['class_score_weight'],
            'exam_score_weight' => $row['exam_score_weight'] === null ? 100.0 : (float) $row['exam_score_weight'],
            'status' => $status,
            'submitted_at' => $row['submitted_at'] === null ? null : (string) $row['submitted_at'],
            'submitted_by_name' => $row['submitted_by_name'] === null
                ? null
                : (string) $row['submitted_by_name'],
            'reviewed_at' => $row['reviewed_at'] === null ? null : (string) $row['reviewed_at'],
            'published_at' => $row['published_at'] === null ? null : (string) $row['published_at'],
            'correction_reason' => $row['correction_reason'] === null
                ? null
                : (string) $row['correction_reason'],
            'active_students' => $activeStudents,
            'recorded_results' => $recordedResults,
            'missing_results' => max(0, $activeStudents - $recordedResults),
        ];
    }

    $studentStmt = db()->prepare(
        'SELECT id, full_name, admission_number FROM school_students
         WHERE school_id = ? AND class_id = ? AND school_status = "active"
         ORDER BY full_name, id'
    );
    $studentStmt->execute([$schoolId, $classId]);
    $studentRows = $studentStmt->fetchAll();
    $studentsById = [];
    foreach ($studentRows as $student) {
        $studentsById[(int) $student['id']] = [
            'id' => (int) $student['id'],
            'full_name' => (string) $student['full_name'],
            'admission_number' => $student['admission_number'] === null ? null : (string) $student['admission_number'],
            'total' => 0.0,
            'average' => null,
            'grade' => null,
            'position' => null,
            'subject_scores' => [],
        ];
    }

    $allApproved = $subjects !== [];
    $allComplete = $subjects !== [] && $studentRows !== [];
    $outstanding = [];
    foreach ($subjects as $subjectId => &$subject) {
        $subjectApproved = in_array($subject['status'], ['reviewed', 'published'], true);
        if ($subject['assessment_id'] === null || $subject['missing_results'] > 0) {
            $allComplete = false;
        }
        if (!$subjectApproved) {
            $allApproved = false;
        }
        if ($subject['assessment_id'] === null || $subject['missing_results'] > 0 || !$subjectApproved) {
            $outstanding[] = [
                'subject_id' => $subjectId,
                'subject_name' => $subject['subject_name'],
                'teacher_name' => $subject['teacher_name'],
                'teacher_user_id' => $subject['teacher_user_id'],
                'status' => $subject['assessment_id'] === null ? 'pending' : $subject['status'],
                'missing_results' => $subject['assessment_id'] === null
                    ? count($studentRows)
                    : $subject['missing_results'],
            ];
        }
        if ($subject['assessment_id'] === null
            || !in_array($subject['status'], ['submitted', 'correction_requested', 'corrected', 'reviewed', 'published'], true)) {
            continue;
        }
        $resultStmt = db()->prepare(
            'SELECT result.student_id, result.score, result.class_score, result.exam_score,
                    result.grade, result.remarks
             FROM assessment_results result
             JOIN school_students student ON student.id = result.student_id
               AND student.school_id = result.school_id
             WHERE result.school_id = ? AND result.assessment_id = ?
               AND student.class_id = ? AND student.school_status = "active"'
        );
        $resultStmt->execute([$schoolId, $subject['assessment_id'], $classId]);
        foreach ($resultStmt->fetchAll() as $result) {
            $studentId = (int) $result['student_id'];
            if (!isset($studentsById[$studentId])) {
                continue;
            }
            $scorePercent = ((float) $result['score'] / (float) $subject['total_marks']) * 100;
            $studentsById[$studentId]['subject_scores'][$subjectId] = [
                'score' => (float) $result['score'],
                'class_score' => $result['class_score'] === null ? null : (float) $result['class_score'],
                'exam_score' => $result['exam_score'] === null ? null : (float) $result['exam_score'],
                'total_marks' => (float) $subject['total_marks'],
                'class_score_maximum' => (float) $subject['total_marks'] * (float) $subject['class_score_weight'] / 100,
                'exam_score_maximum' => (float) $subject['total_marks'] * (float) $subject['exam_score_weight'] / 100,
                'percent' => $scorePercent,
                'grade' => school_grade_for_percent($schoolId, $scorePercent),
                'remarks' => (string) ($result['remarks'] ?? ''),
            ];
            $studentsById[$studentId]['total'] += (float) $result['score'];
        }
    }
    unset($subject);

    $rankSettingsStmt = db()->prepare('SELECT ranking_policy, ranking_enabled FROM school_result_settings WHERE school_id = ?');
    $rankSettingsStmt->execute([$schoolId]);
    $rankSettings = $rankSettingsStmt->fetch() ?: [];
    $rankingPolicy = (string) ($rankSettings['ranking_policy'] ?? 'competition');
    $rankingEnabled = (bool) ($rankSettings['ranking_enabled'] ?? true);
    foreach ($subjects as $subjectId => &$subject) {
        if ($subject['missing_results'] !== 0
            || !in_array($subject['status'], ['submitted', 'correction_requested', 'corrected', 'reviewed', 'published'], true)) {
            continue;
        }
        $scoresByStudent = [];
        foreach ($studentsById as $studentId => $student) {
            if (isset($student['subject_scores'][$subjectId])) {
                $scoresByStudent[$studentId] = $student['subject_scores'][$subjectId]['score'];
            }
        }
        if (count($scoresByStudent) !== count($studentRows)) {
            continue;
        }
        $positions = school_rank_positions($scoresByStudent, $rankingPolicy);
        foreach ($positions as $studentId => $position) {
            $studentsById[$studentId]['subject_scores'][$subjectId]['position'] = $position;
        }
    }
    unset($subject);
    $completeStudentIds = [];
    if ($allComplete && $allApproved) {
        foreach ($studentsById as $studentId => &$student) {
            if (count($student['subject_scores']) !== count($subjects)) {
                $allComplete = false;
                break;
            }
            $student['average'] = array_sum(array_column($student['subject_scores'], 'percent')) / count($subjects);
            $student['grade'] = school_grade_for_percent($schoolId, (float) $student['average']);
            $completeStudentIds[] = (int) $studentId;
        }
        unset($student);
    }
    if ($allComplete && $allApproved && $rankingEnabled) {
        usort($completeStudentIds, static fn(int $leftId, int $rightId): int =>
            ($studentsById[$rightId]['average'] <=> $studentsById[$leftId]['average'])
                ?: ($studentsById[$rightId]['total'] <=> $studentsById[$leftId]['total'])
        );
        $position = 0;
        $densePosition = 0;
        $lastAverage = null;
        foreach ($completeStudentIds as $index => $studentId) {
            $student = &$studentsById[$studentId];
            if ($lastAverage === null || abs((float) $student['average'] - $lastAverage) > 0.00001) {
                $position = $index + 1;
                $densePosition++;
                $lastAverage = (float) $student['average'];
            }
            $student['position'] = $rankingPolicy === 'dense' ? $densePosition : $position;
            unset($student);
        }
    }

    return [
        'school_id' => $schoolId,
        'class' => $class,
        'class_id' => $classId,
        'academic_year' => $academicYear,
        'term_name' => $termName,
        'assessment_name' => $assessmentName,
        'subjects' => array_values($subjects),
        'students' => array_values($studentsById),
        'outstanding_subjects' => $outstanding,
        'complete' => $allComplete,
        'approved' => $allApproved,
        'ready_for_report_cards' => $allComplete && $allApproved,
        'ranking_policy' => $rankingPolicy,
        'ranking_enabled' => $rankingEnabled,
        'status' => $allComplete && $allApproved ? 'Ready' : 'Pending Results',
    ];
}
