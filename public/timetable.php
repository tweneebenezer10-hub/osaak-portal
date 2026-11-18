<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = require_school_context();
$school = active_school_context();
$schoolId = (int) $school['id'];
if (!school_has_permission($user, $schoolId, 'timetable.view')
    && !school_has_permission($user, $schoolId, 'timetable.manage')) {
    http_response_code(403);
    render_header('Timetable permission denied');
    echo '<section class="panel"><h1>Timetable permission denied</h1><p>Your role does not allow access to the smart timetable.</p><a class="button" href="school_dashboard.php">Return to school dashboard</a></section>';
    render_footer();
    exit;
}

$userId = (int) $user['id'];
$schoolRole = (string) ($user['active_school_role'] ?? school_user_role_for_school($userId, $schoolId));
$canManage = school_has_permission($user, $schoolId, 'timetable.manage');
$teacherRole = in_array($schoolRole, ['class_teacher', 'subject_teacher'], true);
if (!school_timetable_schema_ready()) {
    http_response_code(503);
    render_header('Timetable update required');
    echo '<section class="panel"><h1>Smart timetable update required</h1><p>Apply database migration 106_smart_timetable_generator.sql after taking a verified database backup. No timetable data was changed.</p><a class="button" href="learning.php">Open existing timetable</a></section>';
    render_footer();
    exit;
}
$terms = school_academic_terms_for_school($schoolId);
$requestedTermId = filter_var($_POST['term_id'] ?? $_GET['term_id'] ?? null, FILTER_VALIDATE_INT);
$selectedTerm = null;
if ($requestedTermId !== false && $requestedTermId !== null && $requestedTermId > 0) {
    foreach ($terms as $term) {
        if ((int) $term['term_id'] === $requestedTermId) {
            $selectedTerm = $term;
            break;
        }
    }
}
if ($selectedVersion !== null && (string) $selectedVersion['status'] === 'draft') {
    $revision = (int) $selectedVersion['current_revision'];
    $historyAvailability = db()->prepare(
        'SELECT
            EXISTS(SELECT 1 FROM school_timetable_edit_history
                   WHERE school_id = ? AND version_id = ? AND revision_number = ?) AS can_undo,
            EXISTS(SELECT 1 FROM school_timetable_edit_history
                   WHERE school_id = ? AND version_id = ? AND revision_number = ?) AS can_redo'
    );
    $historyAvailability->execute([
        $schoolId, (int) $selectedVersion['id'], $revision,
        $schoolId, (int) $selectedVersion['id'], $revision + 1,
    ]);
    $historyState = $historyAvailability->fetch();
    $canUndo = (bool) ($historyState['can_undo'] ?? false);
    $canRedo = (bool) ($historyState['can_redo'] ?? false);
}
if ($selectedTerm === null
    && !($_SERVER['REQUEST_METHOD'] === 'POST' && $requestedTermId !== null)) {
    $currentPeriod = school_page_academic_term($schoolId);
    if (is_array($currentPeriod)) {
        foreach ($terms as $term) {
            if ((int) $term['term_id'] === (int) $currentPeriod['id']) {
                $selectedTerm = $term;
                break;
            }
        }
    }
}
if ($selectedTerm === null && $terms !== []) {
    $selectedTerm = $terms[0];
}
$termId = $selectedTerm === null ? 0 : (int) $selectedTerm['term_id'];
$returnUrl = 'timetable.php' . ($termId > 0 ? '?term_id=' . $termId : '');
$weekdayNames = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($requestedTermId === false || $requestedTermId === null || $requestedTermId < 1 || $selectedTerm === null)) {
    http_response_code(400);
    exit('A valid school academic term is required for timetable changes.');
}

$assertTeacherAssignment = static function (int $classId, int $subjectId, int $teacherUserId) use ($schoolId, $selectedTerm): bool {
    static $assignmentCache = [];
    if ($selectedTerm === null || $classId < 1 || $subjectId < 1 || $teacherUserId < 1) {
        return false;
    }
    $cacheKey = $schoolId . ':' . $selectedTerm['academic_year'] . ':' . $classId . ':' . $subjectId . ':' . $teacherUserId;
    if (array_key_exists($cacheKey, $assignmentCache)) {
        return $assignmentCache[$cacheKey];
    }
    $stmt = db()->prepare(
        'SELECT 1
         FROM class_subjects offering
         JOIN school_classes class
           ON class.id = offering.class_id AND class.school_id = offering.school_id
         JOIN school_memberships membership
           ON membership.school_id = offering.school_id AND membership.user_id = ?
              AND membership.status = "active"
              AND membership.role_name IN ("class_teacher", "subject_teacher")
         JOIN users teacher ON teacher.id = membership.user_id AND teacher.status = "active"
         WHERE offering.school_id = ? AND offering.class_id = ? AND offering.subject_id = ?
           AND class.academic_year = ?
           AND (
               offering.teacher_user_id = ?
               OR class.class_teacher_id = ?
               OR EXISTS (
                   SELECT 1 FROM teacher_class_assignments assignment
                   WHERE assignment.school_id = offering.school_id AND assignment.class_id = offering.class_id
                     AND assignment.teacher_user_id = ? AND assignment.academic_year = class.academic_year
               )
           )
         LIMIT 1'
    );
    $stmt->execute([
        $teacherUserId,
        $schoolId,
        $classId,
        $subjectId,
        (string) $selectedTerm['academic_year'],
        $teacherUserId,
        $teacherUserId,
        $teacherUserId,
    ]);
    $assignmentCache[$cacheKey] = (bool) $stmt->fetchColumn();
    return $assignmentCache[$cacheKey];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if ($selectedTerm === null || $termId < 1) {
        flash('error', 'Create or select a valid academic term before managing timetables.');
        redirect('timetable.php');
    }
    if (!$canManage) {
        http_response_code(403);
        exit('Only authorized school leaders can manage timetable schedules.');
    }
    $action = (string) ($_POST['action'] ?? '');
    $termId = (int) $selectedTerm['term_id'];
    $returnUrl = 'timetable.php?term_id=' . $termId;

    try {
        if ($action === 'save_settings') {
            $weekdayInput = $_POST['weekdays'] ?? [];
            if (!is_array($weekdayInput)) {
                throw new InvalidArgumentException('Choose at least one valid school day.');
            }
            $settings = [
                'weekdays' => array_map('intval', $weekdayInput),
                'starts_at' => trim((string) ($_POST['starts_at'] ?? '')),
                'lesson_minutes' => filter_var($_POST['lesson_minutes'] ?? null, FILTER_VALIDATE_INT),
                'lesson_count' => filter_var($_POST['lesson_count'] ?? null, FILTER_VALIDATE_INT),
                'assembly_after_period' => $_POST['assembly_after_period'] ?? null,
                'assembly_minutes' => filter_var($_POST['assembly_minutes'] ?? 0, FILTER_VALIDATE_INT),
                'break_after_period' => $_POST['break_after_period'] ?? null,
                'break_minutes' => filter_var($_POST['break_minutes'] ?? 0, FILTER_VALIDATE_INT),
                'lunch_after_period' => $_POST['lunch_after_period'] ?? null,
                'lunch_minutes' => filter_var($_POST['lunch_minutes'] ?? 0, FILTER_VALIDATE_INT),
                'max_periods_per_teacher_day' => filter_var($_POST['max_periods_per_teacher_day'] ?? null, FILTER_VALIDATE_INT),
                'max_periods_per_teacher_week' => filter_var($_POST['max_periods_per_teacher_week'] ?? null, FILTER_VALIDATE_INT),
                'max_periods_per_class_day' => filter_var($_POST['max_periods_per_class_day'] ?? null, FILTER_VALIDATE_INT),
            ];
            foreach (['lesson_minutes', 'lesson_count', 'assembly_minutes', 'break_minutes', 'lunch_minutes',
                'max_periods_per_teacher_day', 'max_periods_per_teacher_week', 'max_periods_per_class_day'] as $field) {
                if ($settings[$field] === false || $settings[$field] === null) {
                    throw new InvalidArgumentException('Enter whole numbers for timetable periods and workload limits.');
                }
            }
            if ($settings['max_periods_per_teacher_day'] < 1 || $settings['max_periods_per_teacher_day'] > 20
                || $settings['max_periods_per_teacher_week'] < 1 || $settings['max_periods_per_teacher_week'] > 100
                || $settings['max_periods_per_class_day'] < 1 || $settings['max_periods_per_class_day'] > 20) {
                throw new InvalidArgumentException('Workload limits are outside the allowed range.');
            }
            $periods = school_timetable_build_periods($settings);
            $weekdayJson = json_encode($settings['weekdays'], JSON_THROW_ON_ERROR);
            db()->beginTransaction();
            $revisionStmt = db()->prepare(
                'SELECT COALESCE(MAX(revision_number), 0) + 1
                 FROM school_timetable_settings WHERE school_id = ? AND term_id = ? FOR UPDATE'
            );
            $revisionStmt->execute([$schoolId, $termId]);
            $revision = (int) $revisionStmt->fetchColumn();
            $insertSettings = db()->prepare(
                'INSERT INTO school_timetable_settings
                 (school_id, academic_year_id, term_id, revision_number, weekdays_json, starts_at,
                  lesson_minutes, lesson_count, assembly_after_period, assembly_minutes,
                  break_after_period, break_minutes, lunch_after_period, lunch_minutes,
                  max_periods_per_teacher_day, max_periods_per_teacher_week, max_periods_per_class_day, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $nullablePeriod = static function (mixed $value): ?int {
                if ($value === '' || $value === null) {
                    return null;
                }
                $number = filter_var($value, FILTER_VALIDATE_INT);
                return $number === false ? null : (int) $number;
            };
            $insertSettings->execute([
                $schoolId,
                (int) $selectedTerm['academic_year_id'],
                $termId,
                $revision,
                $weekdayJson,
                $settings['starts_at'] . ':00',
                $settings['lesson_minutes'],
                $settings['lesson_count'],
                $nullablePeriod($settings['assembly_after_period']),
                $settings['assembly_minutes'],
                $nullablePeriod($settings['break_after_period']),
                $settings['break_minutes'],
                $nullablePeriod($settings['lunch_after_period']),
                $settings['lunch_minutes'],
                $settings['max_periods_per_teacher_day'],
                $settings['max_periods_per_teacher_week'],
                $settings['max_periods_per_class_day'],
                $userId,
            ]);
            $settingsId = (int) db()->lastInsertId();
            $insertPeriod = db()->prepare(
                'INSERT INTO school_timetable_periods
                 (school_id, settings_id, weekday, slot_number, period_number, label, kind, starts_at, ends_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($periods as $period) {
                $insertPeriod->execute([
                    $schoolId,
                    $settingsId,
                    $period['weekday'],
                    $period['slot_number'],
                    $period['period_number'],
                    $period['label'],
                    $period['kind'],
                    $period['starts_at'],
                    $period['ends_at'],
                ]);
            }
            audit($userId, 'school_timetable_settings_saved', 'school_id=' . $schoolId . ';term_id=' . $termId . ';revision=' . $revision);
            db()->commit();
            flash('success', 'Timetable day and period settings saved as revision ' . $revision . '.');
        } elseif ($action === 'save_room') {
            $roomId = filter_var($_POST['room_id'] ?? 0, FILTER_VALIDATE_INT);
            $name = trim((string) ($_POST['name'] ?? ''));
            $building = trim((string) ($_POST['building'] ?? ''));
            $roomType = trim((string) ($_POST['room_type'] ?? ''));
            $capacity = filter_var($_POST['capacity'] ?? null, FILTER_VALIDATE_INT);
            $facilities = trim((string) ($_POST['facilities'] ?? ''));
            if ($roomId === false || $roomId < 0 || $name === '' || strlen($name) > 100
                || strlen($building) > 100 || strlen($roomType) > 60 || strlen($facilities) > 500
                || $capacity === false || $capacity < 1 || $capacity > 65535) {
                throw new InvalidArgumentException('Enter a unique room name and a valid capacity.');
            }
            db()->beginTransaction();
            if ($roomId > 0) {
                $update = db()->prepare(
                    'UPDATE school_timetable_rooms
                     SET name = ?, building = ?, room_type = ?, capacity = ?, facilities = ?, is_active = 1
                     WHERE id = ? AND school_id = ?'
                );
                $update->execute([
                    $name, $building === '' ? null : $building, $roomType === '' ? null : $roomType,
                    $capacity, $facilities === '' ? null : $facilities, $roomId, $schoolId,
                ]);
                if ($update->rowCount() < 1) {
                    $exists = db()->prepare('SELECT 1 FROM school_timetable_rooms WHERE id = ? AND school_id = ?');
                    $exists->execute([$roomId, $schoolId]);
                    if (!$exists->fetchColumn()) {
                        throw new RuntimeException('The selected room was not found in this school.');
                    }
                }
            } else {
                $insert = db()->prepare(
                    'INSERT INTO school_timetable_rooms
                     (school_id, name, building, room_type, capacity, facilities, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $insert->execute([
                    $schoolId, $name, $building === '' ? null : $building, $roomType === '' ? null : $roomType,
                    $capacity, $facilities === '' ? null : $facilities, $userId,
                ]);
            }
            $savedRoomId = $roomId ?: (int) db()->lastInsertId();
            audit($userId, 'school_timetable_room_saved', 'school_id=' . $schoolId . ';room_id=' . $savedRoomId);
            db()->commit();
            flash('success', 'Classroom saved.');
        } elseif ($action === 'deactivate_room') {
            $roomId = filter_var($_POST['room_id'] ?? null, FILTER_VALIDATE_INT);
            if ($roomId === false || $roomId < 1) {
                throw new InvalidArgumentException('Choose a valid classroom.');
            }
            db()->beginTransaction();
            $statement = db()->prepare('UPDATE school_timetable_rooms SET is_active = 0 WHERE id = ? AND school_id = ?');
            $statement->execute([$roomId, $schoolId]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('The selected active classroom was not found.');
            }
            audit($userId, 'school_timetable_room_deactivated', 'school_id=' . $schoolId . ';room_id=' . $roomId);
            db()->commit();
            flash('success', 'Classroom deactivated. Historical timetable entries were preserved.');
        } elseif ($action === 'save_requirement') {
            $offeringValue = (string) ($_POST['offering'] ?? '');
            $offeringParts = explode(':', $offeringValue, 2);
            $classId = filter_var($offeringParts[0] ?? null, FILTER_VALIDATE_INT);
            $subjectId = filter_var($offeringParts[1] ?? null, FILTER_VALIDATE_INT);
            $teacherId = filter_var($_POST['teacher_user_id'] ?? null, FILTER_VALIDATE_INT);
            $periodCount = filter_var($_POST['periods_per_week'] ?? null, FILTER_VALIDATE_INT);
            $doubleCount = filter_var($_POST['double_periods'] ?? 0, FILTER_VALIDATE_INT);
            $preferredRoomId = filter_var($_POST['preferred_room_id'] ?? 0, FILTER_VALIDATE_INT);
            if ($classId === false || $subjectId === false || $teacherId === false
                || $periodCount === false || $periodCount < 1 || $periodCount > 50
                || $doubleCount === false || $doubleCount < 0 || $doubleCount * 2 > $periodCount
                || $preferredRoomId === false || $preferredRoomId < 0
                || !$assertTeacherAssignment((int) $classId, (int) $subjectId, (int) $teacherId)) {
                throw new InvalidArgumentException('Choose a valid class, class subject, assigned teacher, and feasible weekly workload.');
            }
            if ($preferredRoomId > 0) {
                $roomStmt = db()->prepare('SELECT 1 FROM school_timetable_rooms WHERE school_id = ? AND id = ? AND is_active = 1');
                $roomStmt->execute([$schoolId, $preferredRoomId]);
                if (!$roomStmt->fetchColumn()) {
                    throw new InvalidArgumentException('Choose an active room in this school.');
                }
            }
            db()->beginTransaction();
            $upsert = db()->prepare(
                'INSERT INTO school_timetable_requirements
                 (school_id, term_id, class_id, subject_id, teacher_user_id, periods_per_week,
                  double_periods, preferred_room_id, updated_by)
                 SELECT ?, ?, class.id, subject.id, ?, ?, ?, ?, ?
                 FROM school_classes class
                 JOIN class_subjects offering ON offering.class_id = class.id AND offering.school_id = class.school_id
                 JOIN school_subjects subject ON subject.id = offering.subject_id AND subject.school_id = offering.school_id
                 JOIN school_academic_years year ON year.school_id = class.school_id AND year.academic_year = class.academic_year
                 WHERE class.school_id = ? AND class.id = ? AND subject.id = ?
                   AND year.id = ? AND class.status = "active"
                 ON DUPLICATE KEY UPDATE teacher_user_id = VALUES(teacher_user_id),
                   periods_per_week = VALUES(periods_per_week), double_periods = VALUES(double_periods),
                   preferred_room_id = VALUES(preferred_room_id), updated_by = VALUES(updated_by)'
            );
            $upsert->execute([
                $schoolId, $termId, $teacherId, $periodCount, $doubleCount,
                $preferredRoomId > 0 ? $preferredRoomId : null, $userId,
                $schoolId, $classId, $subjectId, (int) $selectedTerm['academic_year_id'],
            ]);
            if ($upsert->rowCount() < 1) {
                $exists = db()->prepare(
                    'SELECT 1 FROM school_timetable_requirements
                     WHERE school_id = ? AND term_id = ? AND class_id = ? AND subject_id = ?'
                );
                $exists->execute([$schoolId, $termId, $classId, $subjectId]);
                if (!$exists->fetchColumn()) {
                    throw new InvalidArgumentException('Choose a class and subject offering from this academic year.');
                }
            }
            audit($userId, 'school_timetable_requirement_saved', 'school_id=' . $schoolId . ';term_id=' . $termId . ';class_id=' . $classId . ';subject_id=' . $subjectId);
            db()->commit();
            flash('success', 'Weekly subject requirement saved.');
        } elseif ($action === 'delete_requirement') {
            $requirementId = filter_var($_POST['requirement_id'] ?? null, FILTER_VALIDATE_INT);
            if ($requirementId === false || $requirementId < 1) {
                throw new InvalidArgumentException('Choose a valid subject requirement.');
            }
            db()->beginTransaction();
            $delete = db()->prepare(
                'DELETE FROM school_timetable_requirements WHERE id = ? AND school_id = ? AND term_id = ?'
            );
            $delete->execute([$requirementId, $schoolId, $termId]);
            if ($delete->rowCount() !== 1) {
                throw new RuntimeException('The selected term requirement was not found.');
            }
            audit($userId, 'school_timetable_requirement_removed', 'school_id=' . $schoolId . ';term_id=' . $termId . ';requirement_id=' . $requirementId);
            db()->commit();
            flash('success', 'Subject requirement removed.');
        } elseif ($action === 'save_unavailability') {
            $entityType = (string) ($_POST['entity_type'] ?? '');
            $entityId = filter_var($_POST['entity_id'] ?? null, FILTER_VALIDATE_INT);
            $weekday = filter_var($_POST['weekday'] ?? null, FILTER_VALIDATE_INT);
            $periodNumber = filter_var($_POST['period_number'] ?? null, FILTER_VALIDATE_INT);
            $reason = trim((string) ($_POST['reason'] ?? ''));
            if (!in_array($entityType, ['teacher', 'class', 'room'], true) || $entityId === false || $entityId < 1
                || $weekday === false || $weekday < 1 || $weekday > 7 || $periodNumber === false
                || $periodNumber < 1 || $periodNumber > 20 || strlen($reason) > 180) {
                throw new InvalidArgumentException('Enter a valid class, teacher, or room unavailability slot.');
            }
            $entitySql = match ($entityType) {
                'class' => 'SELECT 1 FROM school_classes WHERE school_id = ? AND id = ? AND status = "active"',
                'room' => 'SELECT 1 FROM school_timetable_rooms WHERE school_id = ? AND id = ? AND is_active = 1',
                'teacher' => 'SELECT 1 FROM school_memberships WHERE school_id = ? AND user_id = ? AND status = "active" AND role_name IN ("class_teacher", "subject_teacher")',
            };
            $entityCheck = db()->prepare($entitySql);
            $entityCheck->execute([$schoolId, $entityId]);
            if (!$entityCheck->fetchColumn()) {
                throw new InvalidArgumentException('Choose an active resource from this school.');
            }
            db()->beginTransaction();
            $insert = db()->prepare(
                'INSERT INTO school_timetable_unavailable_slots
                 (school_id, term_id, entity_type, entity_id, weekday, period_number, reason, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE reason = VALUES(reason), created_by = VALUES(created_by)'
            );
            $insert->execute([$schoolId, $termId, $entityType, $entityId, $weekday, $periodNumber, $reason === '' ? null : $reason, $userId]);
            audit($userId, 'school_timetable_unavailability_saved', 'school_id=' . $schoolId . ';term_id=' . $termId . ';entity_type=' . $entityType);
            db()->commit();
            flash('success', 'Unavailable timetable slot saved.');
        } elseif ($action === 'delete_unavailability') {
            $unavailableId = filter_var($_POST['unavailable_id'] ?? null, FILTER_VALIDATE_INT);
            if ($unavailableId === false || $unavailableId < 1) {
                throw new InvalidArgumentException('Choose a valid unavailable slot.');
            }
            db()->beginTransaction();
            $delete = db()->prepare(
                'DELETE FROM school_timetable_unavailable_slots
                 WHERE id = ? AND school_id = ? AND term_id = ?'
            );
            $delete->execute([$unavailableId, $schoolId, $termId]);
            if ($delete->rowCount() !== 1) {
                throw new RuntimeException('The selected unavailable slot was not found.');
            }
            audit($userId, 'school_timetable_unavailability_removed', 'school_id=' . $schoolId . ';term_id=' . $termId . ';slot_id=' . $unavailableId);
            db()->commit();
            flash('success', 'Unavailable slot removed.');
        } elseif ($action === 'generate_draft') {
            $settingsStmt = db()->prepare(
                'SELECT * FROM school_timetable_settings
                 WHERE school_id = ? AND term_id = ? ORDER BY revision_number DESC LIMIT 1'
            );
            $settingsStmt->execute([$schoolId, $termId]);
            $settings = $settingsStmt->fetch();
            if (!is_array($settings)) {
                throw new InvalidArgumentException('Save the school day and period settings before generating a timetable.');
            }
            $periodStmt = db()->prepare(
                'SELECT weekday, slot_number, period_number, label, kind, starts_at, ends_at
                 FROM school_timetable_periods WHERE school_id = ? AND settings_id = ?
                 ORDER BY weekday, slot_number'
            );
            $periodStmt->execute([$schoolId, (int) $settings['id']]);
            $periods = $periodStmt->fetchAll();
            $requirementStmt = db()->prepare(
                'SELECT requirement.id, requirement.class_id, requirement.subject_id,
                        requirement.teacher_user_id, requirement.periods_per_week,
                        requirement.double_periods, requirement.preferred_room_id,
                        1 AS teacher_is_assigned
                 FROM school_timetable_requirements requirement
                 JOIN school_classes class
                   ON class.id = requirement.class_id AND class.school_id = requirement.school_id
                 JOIN class_subjects offering
                   ON offering.school_id = requirement.school_id AND offering.class_id = requirement.class_id
                      AND offering.subject_id = requirement.subject_id
                 JOIN school_memberships membership
                   ON membership.school_id = requirement.school_id AND membership.user_id = requirement.teacher_user_id
                      AND membership.status = "active"
                      AND membership.role_name IN ("class_teacher", "subject_teacher")
                 WHERE requirement.school_id = ? AND requirement.term_id = ?
                   AND class.academic_year = ? AND class.status = "active"
                   AND (
                       offering.teacher_user_id = requirement.teacher_user_id
                       OR class.class_teacher_id = requirement.teacher_user_id
                       OR EXISTS (
                           SELECT 1 FROM teacher_class_assignments assignment
                           WHERE assignment.school_id = requirement.school_id AND assignment.class_id = class.id
                             AND assignment.teacher_user_id = requirement.teacher_user_id
                             AND assignment.academic_year = class.academic_year
                       )
                   )
                 ORDER BY requirement.class_id, requirement.subject_id'
            );
            $requirementStmt->execute([$schoolId, $termId, (string) $selectedTerm['academic_year']]);
            $requirements = $requirementStmt->fetchAll();
            if ($requirements === []) {
                throw new InvalidArgumentException('Add at least one assigned class-subject weekly requirement before generating.');
            }
            $roomStmt = db()->prepare(
                'SELECT id, name, room_type, capacity FROM school_timetable_rooms
                 WHERE school_id = ? AND is_active = 1 ORDER BY name'
            );
            $roomStmt->execute([$schoolId]);
            $rooms = $roomStmt->fetchAll();
            $classIds = array_values(array_unique(array_map(static fn(array $item): int => (int) $item['class_id'], $requirements)));
            $classSizeStmt = db()->prepare(
                'SELECT class.id, COUNT(student.id) AS class_size
                 FROM school_classes class
                 LEFT JOIN school_students student
                   ON student.school_id = class.school_id AND student.class_id = class.id
                      AND student.school_status = "active"
                 WHERE class.school_id = ? AND class.id IN (' . implode(',', array_fill(0, count($classIds), '?')) . ')
                 GROUP BY class.id'
            );
            $classSizeStmt->execute(array_merge([$schoolId], $classIds));
            $classSizes = [];
            foreach ($classSizeStmt->fetchAll() as $size) {
                $classSizes[(int) $size['id']] = (int) $size['class_size'];
            }
            $unavailableStmt = db()->prepare(
                'SELECT entity_type, entity_id, weekday, period_number
                 FROM school_timetable_unavailable_slots WHERE school_id = ? AND term_id = ?'
            );
            $unavailableStmt->execute([$schoolId, $termId]);
            $unavailableSlots = $unavailableStmt->fetchAll();
            $limits = [
                'max_periods_per_teacher_day' => (int) $settings['max_periods_per_teacher_day'],
                'max_periods_per_teacher_week' => (int) $settings['max_periods_per_teacher_week'],
                'max_periods_per_class_day' => (int) $settings['max_periods_per_class_day'],
                'node_limit' => 20000,
            ];
            $result = school_timetable_generate_schedule($requirements, $periods, $rooms, $classSizes, $unavailableSlots, $limits);
            if (!$result['ok']) {
                $messages = array_map(static fn(array $error): string => (string) $error['message'], $result['errors']);
                throw new DomainException(implode(' ', array_unique($messages)));
            }
            $periodIds = [];
            $periodIdStmt = db()->prepare(
                'SELECT id, weekday, period_number FROM school_timetable_periods
                 WHERE school_id = ? AND settings_id = ? AND kind = "lesson"'
            );
            $periodIdStmt->execute([$schoolId, (int) $settings['id']]);
            foreach ($periodIdStmt->fetchAll() as $periodRow) {
                $periodIds[(int) $periodRow['weekday'] . ':' . (int) $periodRow['period_number']] = (int) $periodRow['id'];
            }
            db()->beginTransaction();
            $nextVersion = db()->prepare(
                'SELECT COALESCE(MAX(version_number), 0) + 1
                 FROM school_timetable_versions
                 WHERE school_id = ? AND academic_year = ? AND term_name = ? FOR UPDATE'
            );
            $nextVersion->execute([$schoolId, (string) $selectedTerm['academic_year'], (string) $selectedTerm['term_name']]);
            $versionNumber = (int) $nextVersion->fetchColumn();
            $title = trim((string) ($_POST['title'] ?? ''));
            if ($title === '') {
                $title = 'Generated timetable v' . $versionNumber;
            }
            if (strlen($title) > 120) {
                throw new InvalidArgumentException('Timetable title must be 120 characters or fewer.');
            }
            $insertVersion = db()->prepare(
                'INSERT INTO school_timetable_versions
                 (school_id, academic_year_id, academic_year, term_id, term_name, settings_id,
                  version_number, title, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, "draft", ?)'
            );
            $insertVersion->execute([
                $schoolId,
                (int) $selectedTerm['academic_year_id'],
                (string) $selectedTerm['academic_year'],
                $termId,
                (string) $selectedTerm['term_name'],
                (int) $settings['id'],
                $versionNumber,
                $title,
                $userId,
            ]);
            $versionId = (int) db()->lastInsertId();
            $roomNames = [];
            foreach ($rooms as $room) {
                $roomNames[(int) $room['id']] = (string) $room['name'];
            }
            $insertEntry = db()->prepare(
                'INSERT INTO school_timetable_entries
                 (school_id, version_id, class_id, subject_id, period_id, teacher_user_id, weekday,
                  starts_at, ends_at, room, room_id, double_period_group, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($result['entries'] as $entry) {
                $periodKey = (int) $entry['weekday'] . ':' . (int) $entry['period_number'];
                if (!isset($periodIds[$periodKey], $roomNames[(int) $entry['room_id']])) {
                    throw new RuntimeException('The generated schedule referenced an unavailable period or room.');
                }
                $insertEntry->execute([
                    $schoolId, $versionId, (int) $entry['class_id'], (int) $entry['subject_id'],
                    $periodIds[$periodKey], (int) $entry['teacher_user_id'], (int) $entry['weekday'],
                    $entry['starts_at'], $entry['ends_at'], $roomNames[(int) $entry['room_id']],
                    (int) $entry['room_id'], $entry['double_period_group'] === '' ? null : $entry['double_period_group'],
                    $userId,
                ]);
            }
            audit($userId, 'school_timetable_draft_generated', 'school_id=' . $schoolId . ';term_id=' . $termId . ';version_id=' . $versionId . ';entries=' . count($result['entries']));
            db()->commit();
            flash('success', 'Draft timetable generated with ' . count($result['entries']) . ' lesson periods. Review and resolve any remaining preferences before publishing.');
            redirect($returnUrl . '&version_id=' . $versionId);
        } elseif (in_array($action, ['edit_entry', 'undo_version', 'redo_version'], true)) {
            $versionId = filter_var($_POST['version_id'] ?? null, FILTER_VALIDATE_INT);
            if ($versionId === false || $versionId < 1) {
                throw new InvalidArgumentException('Choose a valid draft timetable.');
            }
            db()->beginTransaction();
            $versionStmt = db()->prepare(
                'SELECT id, settings_id, current_revision, status
                 FROM school_timetable_versions
                 WHERE id = ? AND school_id = ? AND term_id = ? FOR UPDATE'
            );
            $versionStmt->execute([$versionId, $schoolId, $termId]);
            $version = $versionStmt->fetch();
            if (!is_array($version) || (string) $version['status'] !== 'draft') {
                throw new RuntimeException('Only a draft timetable in this school and term can be changed.');
            }
            $currentRevision = (int) $version['current_revision'];
            if ($action === 'edit_entry') {
                $entryId = filter_var($_POST['entry_id'] ?? null, FILTER_VALIDATE_INT);
                $periodId = filter_var($_POST['period_id'] ?? null, FILTER_VALIDATE_INT);
                $classId = filter_var($_POST['class_id'] ?? null, FILTER_VALIDATE_INT);
                $subjectId = filter_var($_POST['subject_id'] ?? null, FILTER_VALIDATE_INT);
                $teacherId = filter_var($_POST['teacher_user_id'] ?? null, FILTER_VALIDATE_INT);
                $roomId = filter_var($_POST['room_id'] ?? null, FILTER_VALIDATE_INT);
                if ($entryId === false || $entryId < 1 || $periodId === false || $periodId < 1
                    || $classId === false || $subjectId === false || $teacherId === false
                    || $roomId === false || $roomId < 1) {
                    throw new InvalidArgumentException('Enter a valid class, teacher, room, and lesson period.');
                }
                $entryStmt = db()->prepare(
                    'SELECT id, class_id, subject_id, period_id, teacher_user_id, room, room_id,
                            weekday, starts_at, ends_at, double_period_group
                     FROM school_timetable_entries
                     WHERE id = ? AND school_id = ? AND version_id = ? FOR UPDATE'
                );
                $entryStmt->execute([$entryId, $schoolId, $versionId]);
                $entry = $entryStmt->fetch();
                if (!is_array($entry)) {
                    throw new RuntimeException('The selected lesson is not part of this draft.');
                }
                $periodStmt = db()->prepare(
                    'SELECT id, weekday, period_number, starts_at, ends_at
                     FROM school_timetable_periods
                     WHERE id = ? AND school_id = ? AND settings_id = ? AND kind = "lesson"'
                );
                $periodStmt->execute([$periodId, $schoolId, (int) $version['settings_id']]);
                $period = $periodStmt->fetch();
                if (!is_array($period)
                    || !$assertTeacherAssignment($classId, $subjectId, $teacherId)) {
                    throw new InvalidArgumentException('The selected period or teacher is not valid for this class and subject.');
                }
                $weekday = (int) $period['weekday'];
                $roomStmt = db()->prepare(
                    'SELECT name, capacity FROM school_timetable_rooms
                     WHERE id = ? AND school_id = ? AND is_active = 1'
                );
                $roomStmt->execute([$roomId, $schoolId]);
                $room = $roomStmt->fetch();
                if (!is_array($room)) {
                    throw new InvalidArgumentException('Choose an active classroom in this school.');
                }
                $sizeStmt = db()->prepare(
                    'SELECT COUNT(*) FROM school_students
                     WHERE school_id = ? AND class_id = ? AND school_status = "active"'
                );
                $sizeStmt->execute([$schoolId, $classId]);
                if ((int) $room['capacity'] < (int) $sizeStmt->fetchColumn()) {
                    throw new InvalidArgumentException('The selected room is smaller than the active class size.');
                }
                $before = school_timetable_entry_snapshot(db(), $schoolId, $versionId);
                $update = db()->prepare(
                    'UPDATE school_timetable_entries
                     SET class_id = ?, subject_id = ?, period_id = ?, teacher_user_id = ?,
                         room = ?, room_id = ?, weekday = ?, starts_at = ?, ends_at = ?
                     WHERE id = ? AND school_id = ? AND version_id = ?'
                );
                $targetPeriods = [$period];
                $entryIds = [$entryId];
                if ($entry['double_period_group'] !== null && $entry['double_period_group'] !== '') {
                    $partnerStmt = db()->prepare(
                        'SELECT id FROM school_timetable_entries
                         WHERE school_id = ? AND version_id = ? AND double_period_group = ? AND id <> ?
                         LIMIT 1 FOR UPDATE'
                    );
                    $partnerStmt->execute([$schoolId, $versionId, (string) $entry['double_period_group'], $entryId]);
                    $partnerId = $partnerStmt->fetchColumn();
                    if ($partnerId === false) {
                        throw new RuntimeException('The double-period lesson is incomplete and cannot be moved.');
                    }
                    $secondPeriodStmt = db()->prepare(
                        'SELECT id, weekday, period_number, starts_at, ends_at
                         FROM school_timetable_periods
                         WHERE school_id = ? AND settings_id = ? AND weekday = ?
                           AND period_number = ? AND starts_at = ? AND kind = "lesson"'
                    );
                    $secondPeriodStmt->execute([
                        $schoolId,
                        (int) $version['settings_id'],
                        $weekday,
                        (int) $period['period_number'] + 1,
                        (string) $period['ends_at'],
                    ]);
                    $secondPeriod = $secondPeriodStmt->fetch();
                    if (!is_array($secondPeriod)) {
                        throw new InvalidArgumentException('A double period must occupy two consecutive lessons without a break.');
                    }
                    $targetPeriods[] = $secondPeriod;
                    $entryIds[] = (int) $partnerId;
                }
                foreach ($entryIds as $offset => $movingEntryId) {
                    $targetPeriod = $targetPeriods[$offset];
                    $update->execute([
                        $classId,
                        $subjectId,
                        (int) $targetPeriod['id'],
                        $teacherId,
                        (string) $room['name'],
                        $roomId,
                        $weekday,
                        (string) $targetPeriod['starts_at'],
                        (string) $targetPeriod['ends_at'],
                        $movingEntryId,
                        $schoolId,
                        $versionId,
                    ]);
                }
                $after = school_timetable_entry_snapshot(db(), $schoolId, $versionId);
                $settingsStmt = db()->prepare('SELECT * FROM school_timetable_settings WHERE id = ? AND school_id = ?');
                $settingsStmt->execute([(int) $version['settings_id'], $schoolId]);
                $scheduleSettings = $settingsStmt->fetch();
                $unavailableStmt = db()->prepare(
                    'SELECT entity_type, entity_id, weekday, period_number FROM school_timetable_unavailable_slots
                     WHERE school_id = ? AND term_id = ?'
                );
                $unavailableStmt->execute([$schoolId, $termId]);
                $entriesForValidation = array_map(static function (array $candidate) use ($assertTeacherAssignment): array {
                    $candidate['teacher_is_assigned'] = $assertTeacherAssignment(
                        (int) $candidate['class_id'],
                        (int) $candidate['subject_id'],
                        (int) $candidate['teacher_user_id']
                    );
                    return $candidate;
                }, $after);
                $validationErrors = school_timetable_validate_entries($entriesForValidation, [
                    'max_periods_per_teacher_day' => (int) ($scheduleSettings['max_periods_per_teacher_day'] ?? 6),
                    'max_periods_per_teacher_week' => (int) ($scheduleSettings['max_periods_per_teacher_week'] ?? 30),
                    'max_periods_per_class_day' => (int) ($scheduleSettings['max_periods_per_class_day'] ?? 8),
                    'unavailable_slots' => $unavailableStmt->fetchAll(),
                ]);
                if ($validationErrors !== []) {
                    throw new DomainException((string) $validationErrors[0]['message']);
                }
                school_timetable_record_edit(db(), $schoolId, $versionId, $userId, 'edit_entry', $before, $after);
                audit($userId, 'school_timetable_draft_entry_edited', 'school_id=' . $schoolId . ';version_id=' . $versionId . ';entry_id=' . $entryId);
                db()->commit();
                flash('success', 'Draft lesson updated. Revision ' . ($currentRevision + 1) . ' saved.');
            } else {
                if ($action === 'undo_version') {
                    if ($currentRevision < 1) {
                        throw new InvalidArgumentException('There are no draft edits to undo.');
                    }
                    $historyRevision = $currentRevision;
                    $targetRevision = $currentRevision - 1;
                    $snapshotSide = 'before';
                } else {
                    $historyRevision = $currentRevision + 1;
                    $targetRevision = $historyRevision;
                    $snapshotSide = 'after';
                }
                $historyStmt = db()->prepare(
                    'SELECT snapshot_json FROM school_timetable_edit_history
                     WHERE school_id = ? AND version_id = ? AND revision_number = ?'
                );
                $historyStmt->execute([$schoolId, $versionId, $historyRevision]);
                $historyJson = $historyStmt->fetchColumn();
                if (!is_string($historyJson)) {
                    throw new InvalidArgumentException($action === 'undo_version'
                        ? 'There are no draft edits to undo.'
                        : 'There are no draft edits to redo.');
                }
                $history = json_decode($historyJson, true, 64, JSON_THROW_ON_ERROR);
                $snapshot = $history[$snapshotSide] ?? null;
                if (!is_array($snapshot)) {
                    throw new RuntimeException('Timetable edit history contains invalid data.');
                }
                school_timetable_restore_entry_snapshot(db(), $schoolId, $versionId, $snapshot);
                $setRevision = db()->prepare(
                    'UPDATE school_timetable_versions SET current_revision = ? WHERE id = ? AND school_id = ?'
                );
                $setRevision->execute([$targetRevision, $versionId, $schoolId]);
                audit($userId, 'school_timetable_draft_' . ($action === 'undo_version' ? 'undo' : 'redo'), 'school_id=' . $schoolId . ';version_id=' . $versionId . ';revision=' . $targetRevision);
                db()->commit();
                flash('success', $action === 'undo_version' ? 'Last timetable edit undone.' : 'Timetable edit redone.');
            }
            redirect($returnUrl . '&version_id=' . $versionId);
        } elseif ($action === 'publish_version') {
            $versionId = filter_var($_POST['version_id'] ?? null, FILTER_VALIDATE_INT);
            $effectiveFrom = trim((string) ($_POST['effective_from'] ?? ''));
            if ($versionId === false || $versionId < 1) {
                throw new InvalidArgumentException('Choose a valid draft timetable.');
            }
            $termDates = school_academic_year_dates((string) $selectedTerm['starts_on'], (string) $selectedTerm['ends_on']);
            $effectiveDate = DateTimeImmutable::createFromFormat('!Y-m-d', $effectiveFrom);
            if ($termDates === null || !$effectiveDate || $effectiveDate->format('Y-m-d') !== $effectiveFrom
                || $effectiveDate < $termDates[0] || $effectiveDate > $termDates[1]) {
                throw new InvalidArgumentException('Choose an effective date inside the selected academic term.');
            }
            db()->beginTransaction();
            $versionStmt = db()->prepare(
                'SELECT id, settings_id, status FROM school_timetable_versions
                 WHERE id = ? AND school_id = ? AND term_id = ? FOR UPDATE'
            );
            $versionStmt->execute([$versionId, $schoolId, $termId]);
            $version = $versionStmt->fetch();
            if (!is_array($version) || (string) $version['status'] !== 'draft') {
                throw new RuntimeException('Only a draft timetable in this school and term can be published.');
            }
            $entries = school_timetable_entry_snapshot(db(), $schoolId, $versionId);
            if ($entries === []) {
                throw new InvalidArgumentException('The draft contains no lessons to publish.');
            }
            $settingsStmt = db()->prepare('SELECT * FROM school_timetable_settings WHERE id = ? AND school_id = ?');
            $settingsStmt->execute([(int) $version['settings_id'], $schoolId]);
            $scheduleSettings = $settingsStmt->fetch();
            $unavailableStmt = db()->prepare(
                'SELECT entity_type, entity_id, weekday, period_number FROM school_timetable_unavailable_slots
                 WHERE school_id = ? AND term_id = ?'
            );
            $unavailableStmt->execute([$schoolId, $termId]);
            $unavailableSlots = $unavailableStmt->fetchAll();
            $capacityErrors = [];
            $roomCapacityStmt = db()->prepare(
                'SELECT                 room.id AS room_id, room.capacity, room.is_active,
                COUNT(student.id) AS class_size
                 FROM school_timetable_entries entry
                 LEFT JOIN school_timetable_rooms room
                   ON room.id = entry.room_id AND room.school_id = entry.school_id
                 LEFT JOIN school_students student
                   ON student.school_id = entry.school_id AND student.class_id = entry.class_id
                      AND student.school_status = "active"
                 WHERE entry.school_id = ? AND entry.version_id = ?
                 GROUP BY entry.id, room.id, room.capacity, room.is_active'
            );
            $roomCapacityStmt->execute([$schoolId, $versionId]);
            foreach ($roomCapacityStmt->fetchAll() as $roomCapacity) {
                if ($roomCapacity['room_id'] === null || !(int) $roomCapacity['is_active']
                    || (int) $roomCapacity['capacity'] < (int) $roomCapacity['class_size']) {
                    $capacityErrors[] = 'A scheduled room must be active and large enough for its class.';
                    break;
                }
            }
            $entriesForValidation = array_map(static function (array $candidate) use ($assertTeacherAssignment): array {
                $candidate['teacher_is_assigned'] = $assertTeacherAssignment(
                    (int) $candidate['class_id'],
                    (int) $candidate['subject_id'],
                    (int) $candidate['teacher_user_id']
                );
                return $candidate;
            }, $entries);
            $validationErrors = school_timetable_validate_entries($entriesForValidation, [
                'max_periods_per_teacher_day' => (int) ($scheduleSettings['max_periods_per_teacher_day'] ?? 6),
                'max_periods_per_teacher_week' => (int) ($scheduleSettings['max_periods_per_teacher_week'] ?? 30),
                'max_periods_per_class_day' => (int) ($scheduleSettings['max_periods_per_class_day'] ?? 8),
                'unavailable_slots' => $unavailableSlots,
            ]);
            if ($capacityErrors !== [] || $validationErrors !== []) {
                $messages = array_merge(
                    $capacityErrors,
                    array_map(static fn(array $error): string => (string) $error['message'], $validationErrors)
                );
                throw new DomainException(implode(' ', array_unique($messages)));
            }
            $archive = db()->prepare(
                'UPDATE school_timetable_versions
                 SET status = CASE
                         WHEN effective_from IS NULL OR effective_from < ? THEN "published"
                         ELSE "archived"
                     END,
                     superseded_at = NOW(),
                     effective_to = CASE
                         WHEN effective_from IS NULL OR effective_from < ? THEN DATE_SUB(?, INTERVAL 1 DAY)
                         ELSE effective_to
                     END
                 WHERE school_id = ? AND term_id = ? AND status = "published" AND id <> ?
                   AND (effective_to IS NULL OR effective_to >= ?)'
            );
            $archive->execute([$effectiveFrom, $effectiveFrom, $effectiveFrom, $schoolId, $termId, $versionId, $effectiveFrom]);
            $legacy = db()->prepare(
                'UPDATE school_timetable_versions
                 SET status = CASE
                         WHEN effective_from IS NULL OR effective_from < ? THEN "published"
                         ELSE "archived"
                     END,
                     superseded_at = NOW(),
                     effective_to = CASE
                         WHEN effective_from IS NULL OR effective_from < ? THEN DATE_SUB(?, INTERVAL 1 DAY)
                         ELSE effective_to
                     END
                 WHERE school_id = ? AND academic_year = ? AND is_legacy = 1 AND status = "published"
                   AND (effective_to IS NULL OR effective_to >= ?)'
            );
            $legacy->execute([
                $effectiveFrom,
                $effectiveFrom,
                $effectiveFrom,
                $schoolId,
                (string) $selectedTerm['academic_year'],
                $effectiveFrom,
            ]);
            $publishVersion = db()->prepare(
                'UPDATE school_timetable_versions
                 SET status = "published", effective_from = ?, effective_to = ?, published_by = ?, published_at = NOW()
                 WHERE id = ? AND school_id = ? AND term_id = ? AND status = "draft"'
            );
            $publishVersion->execute([
                $effectiveFrom,
                (string) $selectedTerm['ends_on'],
                $userId,
                $versionId,
                $schoolId,
                $termId,
            ]);
            $publishEntries = db()->prepare(
                'UPDATE school_timetable_entries SET published_at = NOW()
                 WHERE school_id = ? AND version_id = ?'
            );
            $publishEntries->execute([$schoolId, $versionId]);
            audit($userId, 'school_timetable_version_published', 'school_id=' . $schoolId . ';term_id=' . $termId . ';version_id=' . $versionId . ';effective_from=' . $effectiveFrom);
            school_notify_roles(
                $schoolId,
                ['class_teacher', 'subject_teacher'],
                $userId,
                'timetable-published-' . $versionId,
                'other',
                'New school timetable published',
                'A timetable for ' . (string) $selectedTerm['term_name'] . ' ' . (string) $selectedTerm['academic_year']
                    . ' is published effective from ' . $effectiveFrom . '.',
                $returnUrl,
                null,
                null,
                null,
                null,
                [],
                'normal',
                false
            );
            db()->commit();
            flash('success', 'Timetable published with its effective date. The earlier schedule remains in effect until then, and all versions are retained.');
            redirect($returnUrl . '&version_id=' . $versionId);
        } elseif ($action === 'archive_version') {
            $versionId = filter_var($_POST['version_id'] ?? null, FILTER_VALIDATE_INT);
            if ($versionId === false || $versionId < 1) {
                throw new InvalidArgumentException('Choose a valid timetable version.');
            }
            db()->beginTransaction();
            $archive = db()->prepare(
                'UPDATE school_timetable_versions SET status = "archived", superseded_at = NOW()
                 WHERE id = ? AND school_id = ? AND term_id = ? AND status = "draft"'
            );
            $archive->execute([$versionId, $schoolId, $termId]);
            if ($archive->rowCount() !== 1) {
                throw new RuntimeException('Only a draft timetable in this school and term can be archived.');
            }
            audit($userId, 'school_timetable_draft_archived', 'school_id=' . $schoolId . ';version_id=' . $versionId);
            db()->commit();
            flash('success', 'Draft archived. Its schedule and edit history were preserved.');
        } elseif ($action === 'propose_substitution') {
            $entryId = filter_var($_POST['entry_id'] ?? null, FILTER_VALIDATE_INT);
            $substituteId = filter_var($_POST['substitute_teacher_user_id'] ?? null, FILTER_VALIDATE_INT);
            $substitutionDate = trim((string) ($_POST['substitution_date'] ?? ''));
            if ($entryId === false || $entryId < 1 || $substituteId === false || $substituteId < 1) {
                throw new InvalidArgumentException('Choose a published lesson and an eligible substitute.');
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $substitutionDate);
            $termDates = school_academic_year_dates((string) $selectedTerm['starts_on'], (string) $selectedTerm['ends_on']);
            if (!$date || $date->format('Y-m-d') !== $substitutionDate || $termDates === null
                || $date < $termDates[0] || $date > $termDates[1] || $date < new DateTimeImmutable('today')) {
                throw new InvalidArgumentException('Choose today or a future substitution date inside the selected academic term.');
            }
            $lessonStmt = db()->prepare(
                'SELECT entry.id, entry.class_id, entry.subject_id, entry.teacher_user_id,
                        entry.weekday, entry.starts_at, entry.ends_at,
                        class.academic_year
                 FROM school_timetable_entries entry
                 JOIN school_timetable_versions version
                   ON version.id = entry.version_id AND version.school_id = entry.school_id
                 JOIN school_classes class ON class.id = entry.class_id AND class.school_id = entry.school_id
                 WHERE entry.id = ? AND entry.school_id = ? AND version.term_id = ?
                   AND version.status = "published" AND entry.published_at IS NOT NULL
                   AND (version.effective_from IS NULL OR version.effective_from <= ?)
                   AND (version.effective_to IS NULL OR version.effective_to >= ?)
                 LIMIT 1'
            );
            $lessonStmt->execute([$entryId, $schoolId, $substitutionDate, $substitutionDate, $termId]);
            $lesson = $lessonStmt->fetch();
            if (!is_array($lesson) || (int) $lesson['weekday'] !== (int) $date->format('N')) {
                throw new InvalidArgumentException('The selected lesson is not scheduled for that date.');
            }
            $absenceStmt = db()->prepare(
                'SELECT 1 FROM school_staff staff
                 JOIN school_staff_attendance attendance
                   ON attendance.school_id = staff.school_id AND attendance.staff_id = staff.id
                 WHERE staff.school_id = ? AND staff.user_id = ? AND staff.status = "active"
                   AND attendance.attendance_date = ? AND attendance.status IN ("absent", "excused")
                 LIMIT 1'
            );
            $absenceStmt->execute([$schoolId, (int) $lesson['teacher_user_id'], $substitutionDate]);
            if (!$absenceStmt->fetchColumn()) {
                throw new InvalidArgumentException('Record the assigned teacher as absent or excused for that date before proposing a substitute.');
            }
            if (!$assertTeacherAssignment((int) $lesson['class_id'], (int) $lesson['subject_id'], (int) $substituteId)) {
                throw new InvalidArgumentException('The proposed substitute must be an active, assigned teacher for this class or subject.');
            }
            $dayNumber = (int) $date->format('N');
            $conflictStmt = db()->prepare(
                'SELECT 1 FROM school_timetable_entries other
                 JOIN school_timetable_versions version ON version.id = other.version_id AND version.school_id = other.school_id
                 WHERE other.school_id = ? AND other.teacher_user_id = ? AND other.weekday = ?
                   AND other.published_at IS NOT NULL AND version.status = "published"
                   AND (version.effective_from IS NULL OR version.effective_from <= ?)
                   AND (version.effective_to IS NULL OR version.effective_to >= ?)
                   AND other.starts_at < ? AND other.ends_at > ?
                 LIMIT 1'
            );
            $conflictStmt->execute([
                $schoolId,
                $substituteId,
                $dayNumber,
                $substitutionDate,
                $substitutionDate,
                (string) $lesson['ends_at'],
                (string) $lesson['starts_at'],
            ]);
            if ($conflictStmt->fetchColumn()) {
                throw new DomainException('The proposed substitute is already teaching during this period.');
            }
            db()->beginTransaction();
            $insert = db()->prepare(
                'INSERT INTO school_timetable_substitutions
                 (school_id, entry_id, substitution_date, original_teacher_user_id,
                  substitute_teacher_user_id, status, reason, proposed_by)
                 VALUES (?, ?, ?, ?, ?, "proposed", ?, ?)
                 ON DUPLICATE KEY UPDATE original_teacher_user_id = VALUES(original_teacher_user_id),
                   substitute_teacher_user_id = VALUES(substitute_teacher_user_id),
                   status = "proposed", reason = VALUES(reason), proposed_by = VALUES(proposed_by),
                   approved_by = NULL'
            );
            $insert->execute([
                $schoolId,
                $entryId,
                $substitutionDate,
                (int) $lesson['teacher_user_id'],
                $substituteId,
                trim((string) ($_POST['reason'] ?? '')) === '' ? null : substr(trim((string) $_POST['reason']), 0, 500),
                $userId,
            ]);
            $substitutionLookup = db()->prepare(
                'SELECT id FROM school_timetable_substitutions
                 WHERE school_id = ? AND entry_id = ? AND substitution_date = ? FOR UPDATE'
            );
            $substitutionLookup->execute([$schoolId, $entryId, $substitutionDate]);
            $substitutionId = (int) $substitutionLookup->fetchColumn();
            audit($userId, 'school_timetable_substitution_proposed', 'school_id=' . $schoolId . ';substitution_id=' . $substitutionId . ';entry_id=' . $entryId);
            school_notification_create(
                $schoolId,
                $substituteId,
                $userId,
                'timetable-substitution-proposed-' . $entryId . '-' . $substitutionDate,
                'other',
                'Substitute teaching proposed',
                'A substitute assignment has been proposed for ' . $substitutionDate . '. A school leader must approve it.',
                $returnUrl,
                (int) $lesson['class_id'],
                (int) $lesson['subject_id'],
                null,
                null,
                'normal',
                false
            );
            db()->commit();
            flash('success', 'Substitution proposed for school-leader approval. The published timetable is unchanged until approval.');
        } elseif ($action === 'approve_substitution' || $action === 'cancel_substitution') {
            $substitutionId = filter_var($_POST['substitution_id'] ?? null, FILTER_VALIDATE_INT);
            if ($substitutionId === false || $substitutionId < 1) {
                throw new InvalidArgumentException('Choose a valid substitution proposal.');
            }
            db()->beginTransaction();
            $substitutionStmt = db()->prepare(
                'SELECT substitution.id, substitution.entry_id, substitution.substitution_date,
                        substitution.original_teacher_user_id, substitution.substitute_teacher_user_id,
                        entry.class_id, entry.subject_id, entry.weekday, entry.starts_at, entry.ends_at,
                        substitution.status, class.academic_year, version.term_id
                 FROM school_timetable_substitutions substitution
                 JOIN school_timetable_entries entry
                   ON entry.id = substitution.entry_id AND entry.school_id = substitution.school_id
                 JOIN school_timetable_versions version
                   ON version.id = entry.version_id AND version.school_id = entry.school_id
                      AND version.status = "published"
                 JOIN school_classes class ON class.id = entry.class_id AND class.school_id = entry.school_id
                 WHERE substitution.id = ? AND substitution.school_id = ?
                   AND entry.published_at IS NOT NULL
                   AND (version.effective_from IS NULL OR version.effective_from <= substitution.substitution_date)
                   AND (version.effective_to IS NULL OR version.effective_to >= substitution.substitution_date)
                   AND version.term_id = ? FOR UPDATE'
            );
            $substitutionStmt->execute([$substitutionId, $schoolId, $termId]);
            $substitution = $substitutionStmt->fetch();
            if (!is_array($substitution) || (string) $substitution['status'] !== 'proposed') {
                throw new RuntimeException('Only a pending substitution in this school can be changed.');
            }
            if ($action === 'approve_substitution') {
                $date = new DateTimeImmutable((string) $substitution['substitution_date']);
                $termDates = school_academic_year_dates((string) $selectedTerm['starts_on'], (string) $selectedTerm['ends_on']);
                if ($date < new DateTimeImmutable('today')
                    || (int) $substitution['term_id'] !== $termId
                    || (int) $substitution['weekday'] !== (int) $date->format('N')
                    || $termDates === null || $date < $termDates[0] || $date > $termDates[1]) {
                    throw new DomainException('The published lesson or substitution date is no longer current.');
                }
                $absenceStmt = db()->prepare(
                    'SELECT 1 FROM school_staff staff
                     JOIN school_staff_attendance attendance
                       ON attendance.school_id = staff.school_id AND attendance.staff_id = staff.id
                     WHERE staff.school_id = ? AND staff.user_id = ? AND staff.status = "active"
                       AND attendance.attendance_date = ? AND attendance.status IN ("absent", "excused")
                     LIMIT 1'
                );
                $absenceStmt->execute([$schoolId, (int) $substitution['original_teacher_user_id'], $date->format('Y-m-d')]);
                if (!$absenceStmt->fetchColumn()
                    || !$assertTeacherAssignment((int) $substitution['class_id'], (int) $substitution['subject_id'], (int) $substitution['substitute_teacher_user_id'])) {
                    throw new DomainException('The teacher absence or substitute assignment is no longer valid.');
                }
                $conflictStmt = db()->prepare(
                    'SELECT 1 FROM school_timetable_entries other
                     JOIN school_timetable_versions version ON version.id = other.version_id AND version.school_id = other.school_id
                     WHERE other.school_id = ? AND other.teacher_user_id = ? AND other.weekday = ?
                       AND other.published_at IS NOT NULL AND version.status = "published"
                       AND (version.effective_from IS NULL OR version.effective_from <= ?)
                       AND (version.effective_to IS NULL OR version.effective_to >= ?)
                       AND other.starts_at < ? AND other.ends_at > ?
                     LIMIT 1'
                );
                $conflictStmt->execute([
                    $schoolId,
                    (int) $substitution['substitute_teacher_user_id'],
                    (int) $date->format('N'),
                    $date->format('Y-m-d'),
                    $date->format('Y-m-d'),
                    (string) $substitution['ends_at'],
                    (string) $substitution['starts_at'],
                ]);
                if ($conflictStmt->fetchColumn()) {
                    throw new DomainException('The proposed substitute now has a timetable clash.');
                }
                $update = db()->prepare(
                    'UPDATE school_timetable_substitutions
                     SET status = "approved", approved_by = ?
                     WHERE id = ? AND school_id = ? AND status = "proposed"'
                );
                $update->execute([$userId, $substitutionId, $schoolId]);
            } else {
                $update = db()->prepare(
                    'UPDATE school_timetable_substitutions SET status = "cancelled"
                     WHERE id = ? AND school_id = ? AND status = "proposed"'
                );
                $update->execute([$substitutionId, $schoolId]);
            }
            audit($userId, 'school_timetable_substitution_' . ($action === 'approve_substitution' ? 'approved' : 'cancelled'), 'school_id=' . $schoolId . ';substitution_id=' . $substitutionId);
            if ($action === 'approve_substitution') {
                school_notification_create(
                    $schoolId,
                    (int) $substitution['substitute_teacher_user_id'],
                    $userId,
                    'timetable-substitution-approved-' . $substitutionId,
                    'other',
                    'Substitute assignment approved',
                    'Your substitute assignment for ' . (string) $substitution['substitution_date'] . ' has been approved.',
                    $returnUrl,
                    (int) $substitution['class_id'],
                    (int) $substitution['subject_id'],
                    null,
                    null,
                    'normal',
                    false
                );
                $classRecipients = db()->prepare(
                    'SELECT DISTINCT recipient_id
                     FROM (
                         SELECT account_link.user_id AS recipient_id
                         FROM school_student_account_links account_link
                         JOIN school_students student
                           ON student.id = account_link.student_id AND student.school_id = account_link.school_id
                         JOIN school_memberships membership
                           ON membership.school_id = account_link.school_id AND membership.user_id = account_link.user_id
                              AND membership.role_name = "school_student" AND membership.status = "active"
                         WHERE account_link.school_id = ? AND account_link.status = "active"
                           AND student.class_id = ? AND student.school_status = "active"
                         UNION
                         SELECT parent_link.user_id AS recipient_id
                         FROM school_parent_links parent_link
                         JOIN school_guardians guardian
                           ON guardian.id = parent_link.guardian_id AND guardian.school_id = parent_link.school_id
                         JOIN school_students student
                           ON student.id = parent_link.student_id AND student.school_id = parent_link.school_id
                         JOIN users parent_account
                           ON parent_account.id = parent_link.user_id AND parent_account.status = "active"
                              AND parent_account.email_verified_at IS NOT NULL
                              AND LOWER(TRIM(parent_account.email)) = LOWER(TRIM(guardian.email))
                         JOIN school_memberships membership
                           ON membership.user_id = parent_link.user_id AND membership.school_id = parent_link.school_id
                              AND membership.role_name = "school_parent" AND membership.status = "active"
                         WHERE parent_link.school_id = ? AND parent_link.status = "verified"
                           AND student.class_id = ? AND student.school_status = "active"
                     ) class_members'
                );
                $classRecipients->execute([
                    $schoolId,
                    (int) $substitution['class_id'],
                    $schoolId,
                    (int) $substitution['class_id'],
                ]);
                foreach ($classRecipients->fetchAll(PDO::FETCH_COLUMN) as $recipientId) {
                    school_notification_create(
                        $schoolId,
                        (int) $recipientId,
                        $userId,
                        'timetable-substitution-class-' . $substitutionId . '-user-' . (int) $recipientId,
                        'other',
                        'Teacher substitution confirmed',
                        'A substitute teacher is confirmed for ' . (string) $substitution['substitution_date'] . ' at '
                            . substr((string) $substitution['starts_at'], 0, 5) . ' for this class.',
                        $returnUrl,
                        (int) $substitution['class_id'],
                        (int) $substitution['subject_id'],
                        null,
                        (string) $substitution['substitution_date'],
                        'normal',
                        false
                    );
                }
            }
            db()->commit();
            flash('success', $action === 'approve_substitution' ? 'Substitution approved and recorded.' : 'Substitution proposal cancelled.');
        } else {
            throw new InvalidArgumentException('Choose a valid timetable action.');
        }
    } catch (DomainException | InvalidArgumentException $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        flash('error', $exception->getMessage());
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        error_log('School timetable action failed: ' . $exception->getMessage());
        flash('error', 'The timetable action could not be completed. No uncommitted changes were retained.');
    }
    redirect($returnUrl);
}

$settings = null;
$periods = [];
$rooms = [];
$classes = [];
$offerings = [];
$teachers = [];
$requirements = [];
$unavailable = [];
$versions = [];
$entries = [];
$substitutions = [];
$selectedVersion = null;
$canUndo = false;
$canRedo = false;
$versionId = filter_var($_GET['version_id'] ?? null, FILTER_VALIDATE_INT);
$selectedVersionId = $versionId === false ? null : $versionId;
$viewMode = (string) ($_GET['view'] ?? 'class');
if (!in_array($viewMode, ['class', 'teacher', 'room', 'school'], true)) {
    $viewMode = 'class';
}
$viewResourceId = filter_var($_GET['resource_id'] ?? null, FILTER_VALIDATE_INT);
$viewResourceId = $viewResourceId === false || $viewResourceId < 1 ? null : (int) $viewResourceId;

if ($selectedTerm !== null) {
    $settingsStmt = db()->prepare(
        'SELECT * FROM school_timetable_settings WHERE school_id = ? AND term_id = ?
         ORDER BY revision_number DESC LIMIT 1'
    );
    $settingsStmt->execute([$schoolId, $termId]);
    $settings = $settingsStmt->fetch() ?: null;
    if (is_array($settings)) {
        $periodStmt = db()->prepare(
            'SELECT * FROM school_timetable_periods WHERE school_id = ? AND settings_id = ?
             ORDER BY weekday, slot_number'
        );
        $periodStmt->execute([$schoolId, (int) $settings['id']]);
        $periods = $periodStmt->fetchAll();
    }
    $classParams = [$schoolId, (string) $selectedTerm['academic_year']];
    $classScope = '';
    if ($teacherRole) {
        $classScope = ' AND (
            class.class_teacher_id = ? OR EXISTS (
                SELECT 1 FROM teacher_class_assignments assignment
                WHERE assignment.school_id = class.school_id AND assignment.class_id = class.id
                  AND assignment.teacher_user_id = ? AND assignment.academic_year = class.academic_year
            ) OR EXISTS (
                SELECT 1 FROM class_subjects assigned
                WHERE assigned.school_id = class.school_id AND assigned.class_id = class.id
                  AND assigned.teacher_user_id = ?
            )
        )';
        array_push($classParams, $userId, $userId, $userId);
    }
    $classStmt = db()->prepare(
        'SELECT class.id, class.level, class.section, class.academic_year, class.class_teacher_id,
                COUNT(student.id) AS class_size
         FROM school_classes class
         LEFT JOIN school_students student
           ON student.school_id = class.school_id AND student.class_id = class.id
              AND student.school_status = "active"
         WHERE class.school_id = ? AND class.academic_year = ? AND class.status = "active"
           ' . $classScope . '
         GROUP BY class.id, class.level, class.section, class.academic_year, class.class_teacher_id
         ORDER BY class.level, class.section'
    );
    $classStmt->execute($classParams);
    $classes = $classStmt->fetchAll();
    $classIds = array_map(static fn(array $class): int => (int) $class['id'], $classes);
    $classFilter = $classIds === [] ? ' AND 1 = 0' : ' AND class.id IN (' . implode(',', array_fill(0, count($classIds), '?')) . ')';
    $offeringStmt = db()->prepare(
        'SELECT class.id AS class_id, class.level, class.section,
                subject.id AS subject_id, subject.name AS subject_name, subject.code,
                COALESCE(offering.teacher_user_id, class.class_teacher_id) AS assigned_teacher_id,
                assigned_teacher.full_name AS assigned_teacher_name
         FROM class_subjects offering
         JOIN school_classes class
           ON class.id = offering.class_id AND class.school_id = offering.school_id
         JOIN school_subjects subject
           ON subject.id = offering.subject_id AND subject.school_id = offering.school_id
         LEFT JOIN users assigned_teacher
           ON assigned_teacher.id = COALESCE(offering.teacher_user_id, class.class_teacher_id)
         WHERE offering.school_id = ? AND class.academic_year = ? AND class.status = "active"
           ' . $classFilter . '
         ORDER BY class.level, class.section, subject.name'
    );
    $offeringStmt->execute(array_merge([$schoolId, (string) $selectedTerm['academic_year']], $classIds));
    $offerings = $offeringStmt->fetchAll();
    $roomStmt = db()->prepare(
        'SELECT id, name, building, room_type, capacity, facilities, is_active
         FROM school_timetable_rooms WHERE school_id = ? ORDER BY is_active DESC, name'
    );
    $roomStmt->execute([$schoolId]);
    $rooms = $roomStmt->fetchAll();
    $teacherStmt = db()->prepare(
        'SELECT DISTINCT membership.user_id, user.full_name
         FROM school_memberships membership
         JOIN users user ON user.id = membership.user_id AND user.status = "active"
         WHERE membership.school_id = ? AND membership.status = "active"
           AND membership.role_name IN ("class_teacher", "subject_teacher")
         ORDER BY user.full_name'
    );
    $teacherStmt->execute([$schoolId]);
    $teachers = $teacherStmt->fetchAll();
    $requirementStmt = db()->prepare(
        'SELECT requirement.id, requirement.class_id, requirement.subject_id,
                requirement.teacher_user_id, requirement.periods_per_week,
                requirement.double_periods, requirement.preferred_room_id,
                class.level, class.section, subject.name AS subject_name, user.full_name AS teacher_name,
                room.name AS preferred_room
         FROM school_timetable_requirements requirement
         JOIN school_classes class ON class.id = requirement.class_id AND class.school_id = requirement.school_id
         JOIN school_subjects subject ON subject.id = requirement.subject_id AND subject.school_id = requirement.school_id
         JOIN users user ON user.id = requirement.teacher_user_id
         LEFT JOIN school_timetable_rooms room
           ON room.id = requirement.preferred_room_id AND room.school_id = requirement.school_id
         WHERE requirement.school_id = ? AND requirement.term_id = ?
           AND class.academic_year = ? AND class.status = "active"
         ORDER BY class.level, class.section, subject.name'
    );
    $requirementStmt->execute([$schoolId, $termId, (string) $selectedTerm['academic_year']]);
    $requirements = $requirementStmt->fetchAll();
    $unavailableStmt = db()->prepare(
        'SELECT slot.id, slot.entity_type, slot.entity_id, slot.weekday, slot.period_number, slot.reason,
                CASE
                    WHEN slot.entity_type = "class" THEN CONCAT(class.level, " ", COALESCE(class.section, ""))
                    WHEN slot.entity_type = "room" THEN room.name
                    ELSE teacher.full_name
                END AS entity_name
         FROM school_timetable_unavailable_slots slot
         LEFT JOIN school_classes class
           ON slot.entity_type = "class" AND class.id = slot.entity_id AND class.school_id = slot.school_id
         LEFT JOIN school_timetable_rooms room
           ON slot.entity_type = "room" AND room.id = slot.entity_id AND room.school_id = slot.school_id
         LEFT JOIN users teacher ON slot.entity_type = "teacher" AND teacher.id = slot.entity_id
         WHERE slot.school_id = ? AND slot.term_id = ?
         ORDER BY slot.weekday, slot.period_number, slot.entity_type'
    );
    $unavailableStmt->execute([$schoolId, $termId]);
    $unavailable = $unavailableStmt->fetchAll();
    $versionsStmt = db()->prepare(
        'SELECT id, version_number, title, status, is_legacy, effective_from, effective_to,
                current_revision, published_at, created_at
         FROM school_timetable_versions
         WHERE school_id = ? AND term_id = ?
         ORDER BY version_number DESC'
    );
    $versionsStmt->execute([$schoolId, $termId]);
    $versions = $versionsStmt->fetchAll();
    foreach ($versions as $version) {
        if ($selectedVersionId === (int) $version['id']) {
            $selectedVersion = $version;
            break;
        }
    }
    if ($selectedVersion === null) {
        foreach ($versions as $version) {
            if ((string) $version['status'] === 'draft') {
                $selectedVersion = $version;
                break;
            }
        }
    }
    if ($selectedVersion === null) {
        foreach ($versions as $version) {
            if ((string) $version['status'] === 'published') {
                $selectedVersion = $version;
                break;
            }
        }
    }
    if ($selectedVersion !== null) {
        $selectedVersionId = (int) $selectedVersion['id'];
        $entryStmt = db()->prepare(
            'SELECT entry.id, entry.class_id, entry.subject_id, entry.period_id, entry.teacher_user_id,
                    entry.room, entry.room_id, entry.weekday, entry.starts_at, entry.ends_at,
                    entry.double_period_group,
                    class.level, class.section, subject.name AS subject_name,
                    teacher.full_name AS teacher_name, room.name AS room_name, period.period_number,
                    version.status AS version_status
             FROM school_timetable_entries entry
             JOIN school_classes class ON class.id = entry.class_id AND class.school_id = entry.school_id
             LEFT JOIN school_subjects subject ON subject.id = entry.subject_id AND subject.school_id = entry.school_id
             LEFT JOIN users teacher ON teacher.id = entry.teacher_user_id
             LEFT JOIN school_timetable_rooms room ON room.id = entry.room_id AND room.school_id = entry.school_id
             LEFT JOIN school_timetable_periods period ON period.id = entry.period_id AND period.school_id = entry.school_id
             JOIN school_timetable_versions version ON version.id = entry.version_id AND version.school_id = entry.school_id
             WHERE entry.school_id = ? AND entry.version_id = ?' . $classFilter
                . ($schoolRole === 'subject_teacher' ? ' AND entry.teacher_user_id = ?' : '') . '
             ORDER BY class.level, class.section, entry.weekday, entry.starts_at, subject.name'
        );
        $entryStmt->execute(array_merge(
            [$schoolId, $selectedVersionId],
            $classIds,
            $schoolRole === 'subject_teacher' ? [$userId] : []
        ));
        $entries = $entryStmt->fetchAll();
    }
    if ($selectedVersion !== null && (string) $selectedVersion['status'] === 'published') {
        $substitutionStmt = db()->prepare(
            'SELECT substitution.id, substitution.entry_id, substitution.substitution_date,
                    substitution.substitute_teacher_user_id, substitution.status, substitution.reason,
                    substitute.full_name AS substitute_name, original.full_name AS original_name,
                    class.level, class.section, subject.name AS subject_name,
                    entry.starts_at, entry.ends_at
             FROM school_timetable_substitutions substitution
             JOIN school_timetable_entries entry
               ON entry.id = substitution.entry_id AND entry.school_id = substitution.school_id
             JOIN school_classes class ON class.id = entry.class_id AND class.school_id = entry.school_id
             LEFT JOIN school_subjects subject ON subject.id = entry.subject_id AND subject.school_id = entry.school_id
             JOIN users substitute ON substitute.id = substitution.substitute_teacher_user_id
             LEFT JOIN users original ON original.id = substitution.original_teacher_user_id
             WHERE substitution.school_id = ? AND entry.version_id = ?
               AND substitution.status IN ("proposed", "approved")
             ORDER BY substitution.substitution_date DESC, substitution.id DESC'
        );
        $substitutionStmt->execute([$schoolId, $selectedVersionId]);
        $substitutions = $substitutionStmt->fetchAll();
    }
}

$teacherResourceOptions = [];
if ($teacherRole) {
    foreach ($teachers as $teacher) {
        if ((int) $teacher['user_id'] === $userId) {
            $teacherResourceOptions[] = $teacher;
        }
    }
} else {
    $teacherResourceOptions = $teachers;
}

$export = (string) ($_GET['export'] ?? '');
if ($export === 'csv' && $selectedTerm !== null) {
    $exportVersionId = filter_var($_GET['version_id'] ?? null, FILTER_VALIDATE_INT);
    $exportVersionId = $exportVersionId === false ? 0 : $exportVersionId;
    if ($exportVersionId < 1) {
        http_response_code(404);
        exit('Timetable version not found.');
    }
    $exportQuery = db()->prepare(
        'SELECT class.level, class.section, entry.weekday, entry.starts_at, entry.ends_at,
                subject.name AS subject_name, teacher.full_name AS teacher_name, room.name AS room_name
         FROM school_timetable_entries entry
         JOIN school_timetable_versions version ON version.id = entry.version_id AND version.school_id = entry.school_id
         JOIN school_classes class ON class.id = entry.class_id AND class.school_id = entry.school_id
         LEFT JOIN school_subjects subject ON subject.id = entry.subject_id AND subject.school_id = entry.school_id
         LEFT JOIN users teacher ON teacher.id = entry.teacher_user_id
         LEFT JOIN school_timetable_rooms room ON room.id = entry.room_id AND room.school_id = entry.school_id
         WHERE entry.school_id = ? AND entry.version_id = ? AND version.term_id = ?' . $classFilter
            . ($schoolRole === 'subject_teacher' ? ' AND entry.teacher_user_id = ?' : '') . '
         ORDER BY class.level, class.section, entry.weekday, entry.starts_at'
    );
    $exportQuery->execute(array_merge(
        [$schoolId, $exportVersionId, $termId],
        $classIds,
        $schoolRole === 'subject_teacher' ? [$userId] : []
    ));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="connezer-timetable-' . $termId . '-v' . $exportVersionId . '.csv"');
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        throw new RuntimeException('The timetable export could not be opened.');
    }
    $safeCsvText = static function (string $value): string {
        return preg_match('/^\s*[=+\-@]/u', $value) === 1 ? "'" . $value : $value;
    };
    fputcsv($output, ['Class', 'Weekday', 'Starts', 'Ends', 'Subject', 'Teacher', 'Room']);
    foreach ($exportQuery->fetchAll() as $row) {
        fputcsv($output, [
            $safeCsvText(trim((string) $row['level'] . ' ' . (string) ($row['section'] ?? ''))),
            $weekdayNames[(int) $row['weekday']] ?? '',
            (string) $row['starts_at'],
            (string) $row['ends_at'],
            $safeCsvText((string) ($row['subject_name'] ?? '')),
            $safeCsvText((string) ($row['teacher_name'] ?? '')),
            $safeCsvText((string) ($row['room_name'] ?? '')),
        ]);
    }
    fclose($output);
    exit;
}

render_header('Smart timetable');
?>
<section class="panel timetable-heading">
    <p class="eyebrow">TEACHING AND LEARNING</p>
    <h1>Smart timetable</h1>
    <p class="muted">Generate a school schedule from assigned classes, subject workloads, teacher availability, and classroom capacity. Every draft is checked for clashes before it can be published.</p>
    <?php if ($selectedTerm !== null): ?>
        <form method="get" action="timetable.php" class="timetable-term-select">
            <label for="term_id">Academic term</label>
            <select id="term_id" name="term_id" required>
                <?php foreach ($terms as $term): ?>
                    <option value="<?= (int) $term['term_id'] ?>" <?= (int) $term['term_id'] === $termId ? 'selected' : '' ?>>
                        <?= e((string) $term['term_name'] . ' · ' . school_academic_year_label((string) $term['academic_year'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="button button-small" type="submit">Show term</button>
        </form>
    <?php endif; ?>
</section>

<?php if ($selectedTerm === null): ?>
    <section class="panel panel-top-gap"><h2>Academic term required</h2><p class="muted">Set up an academic year and term on the Academic calendar before configuring a timetable.</p><a class="button" href="term_setup.php">Open academic calendar</a></section>
<?php else: ?>
    <section class="panel panel-top-gap timetable-version-panel">
        <div class="timetable-panel-heading">
            <div><h2><?= e((string) $selectedTerm['term_name']) ?> timetable</h2><p class="muted"><?= e(school_academic_year_label((string) $selectedTerm['academic_year'])) ?> · <?= e((string) $selectedTerm['starts_on']) ?> to <?= e((string) $selectedTerm['ends_on']) ?></p></div>
            <?php if ($selectedVersion !== null): ?>
                <div class="timetable-actions no-print">
                    <a class="button button-small" href="timetable.php?term_id=<?= $termId ?>&amp;version_id=<?= (int) $selectedVersion['id'] ?>&amp;export=csv">Export CSV</a>
                    <button class="button button-small" type="button" data-timetable-print>Print timetable</button>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($versions !== []): ?>
            <form method="get" action="timetable.php" class="timetable-version-select no-print">
                <input type="hidden" name="term_id" value="<?= $termId ?>">
                <label for="version_id">Schedule version</label>
                <select id="version_id" name="version_id">
                    <?php foreach ($versions as $version): ?>
                        <option value="<?= (int) $version['id'] ?>" <?= (int) $version['id'] === $selectedVersionId ? 'selected' : '' ?>>
                            <?= e((string) $version['title'] . ' · v' . (int) $version['version_number'] . ' · ' . ucfirst((string) $version['status'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="button button-small" type="submit">Open version</button>
            </form>
        <?php endif; ?>
        <?php if ($selectedVersion === null): ?>
            <p class="muted">No timetable versions have been generated for this term.</p>
        <?php else: ?>
            <p><strong><?= e((string) $selectedVersion['title']) ?></strong> · <?= e(ucfirst((string) $selectedVersion['status'])) ?><?= $selectedVersion['effective_from'] ? ' · Effective ' . e((string) $selectedVersion['effective_from']) : '' ?></p>
            <?php if ($entries === []): ?><p class="muted">This schedule version has no visible lessons.</p>
            <?php else: ?>
                <?php if ($viewMode === 'class' && $viewResourceId !== null): ?>
                    <?php $entries = array_values(array_filter($entries, static fn(array $entry): bool => (int) $entry['class_id'] === $viewResourceId)); ?>
                <?php elseif ($viewMode === 'teacher' && $viewResourceId !== null): ?>
                    <?php $entries = array_values(array_filter($entries, static fn(array $entry): bool => (int) $entry['teacher_user_id'] === $viewResourceId)); ?>
                <?php elseif ($viewMode === 'room' && $viewResourceId !== null): ?>
                    <?php $entries = array_values(array_filter($entries, static fn(array $entry): bool => (int) $entry['room_id'] === $viewResourceId)); ?>
                <?php endif; ?>
                <form method="get" action="timetable.php" class="timetable-view-filter no-print">
                    <input type="hidden" name="term_id" value="<?= $termId ?>">
                    <input type="hidden" name="version_id" value="<?= (int) $selectedVersion['id'] ?>">
                    <label for="view_mode">View</label>
                    <select id="view_mode" name="view">
                        <option value="class" <?= $viewMode === 'class' ? 'selected' : '' ?>>By class</option>
                        <option value="teacher" <?= $viewMode === 'teacher' ? 'selected' : '' ?>>By teacher</option>
                        <option value="room" <?= $viewMode === 'room' ? 'selected' : '' ?>>By classroom</option>
                        <option value="school" <?= $viewMode === 'school' ? 'selected' : '' ?>>Whole school</option>
                    </select>
                    <?php if ($viewMode === 'class'): ?>
                        <label for="resource_id">Class</label><select id="resource_id" name="resource_id"><option value="">All authorized classes</option><?php foreach ($classes as $class): ?><option value="<?= (int) $class['id'] ?>" <?= $viewResourceId === (int) $class['id'] ? 'selected' : '' ?>><?= e(school_class_label($class)) ?></option><?php endforeach; ?></select>
                    <?php elseif ($viewMode === 'teacher'): ?>
                        <label for="resource_id">Teacher</label><select id="resource_id" name="resource_id"><option value="">All authorized teachers</option><?php foreach ($teacherResourceOptions as $teacher): ?><option value="<?= (int) $teacher['user_id'] ?>" <?= $viewResourceId === (int) $teacher['user_id'] ? 'selected' : '' ?>><?= e((string) $teacher['full_name']) ?></option><?php endforeach; ?></select>
                    <?php elseif ($viewMode === 'room'): ?>
                        <label for="resource_id">Classroom</label><select id="resource_id" name="resource_id"><option value="">All classrooms</option><?php foreach ($rooms as $room): ?><option value="<?= (int) $room['id'] ?>" <?= $viewResourceId === (int) $room['id'] ? 'selected' : '' ?>><?= e((string) $room['name']) ?></option><?php endforeach; ?></select>
                    <?php endif; ?>
                    <button class="button button-small" type="submit">Apply view</button>
                </form>
                <?php if ($entries === []): ?><p class="muted">No lessons match this view.</p>
                <?php else: ?>
                    <div class="table-scroll timetable-table-wrap"><table class="timetable-table"><thead><tr><th>Class</th><th>Day</th><th>Time</th><th>Period</th><th>Subject</th><th>Teacher</th><th>Room</th></tr></thead><tbody>
                        <?php foreach ($entries as $entry): ?>
                            <tr><td><?= e(school_class_label(['level' => $entry['level'], 'section' => $entry['section']])) ?></td><td><?= e($weekdayNames[(int) $entry['weekday']] ?? '—') ?></td><td><?= e(substr((string) $entry['starts_at'], 0, 5)) ?>–<?= e(substr((string) $entry['ends_at'], 0, 5)) ?></td><td><?= $entry['period_number'] === null ? '—' : (int) $entry['period_number'] ?></td><td><?= e((string) ($entry['subject_name'] ?? 'Class period')) ?></td><td><?= e((string) ($entry['teacher_name'] ?? '—')) ?></td><td><?= e((string) ($entry['room_name'] ?? $entry['room'] ?? '—')) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody></table></div>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($canManage && (string) $selectedVersion['status'] === 'draft'): ?>
                <div class="timetable-actions no-print">
                    <form method="post" action="<?= e($returnUrl) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="undo_version"><input type="hidden" name="version_id" value="<?= (int) $selectedVersion['id'] ?>"><button class="button button-small" type="submit" <?= !$canUndo ? 'disabled' : '' ?>>Undo</button></form>
                    <form method="post" action="<?= e($returnUrl) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="redo_version"><input type="hidden" name="version_id" value="<?= (int) $selectedVersion['id'] ?>"><button class="button button-small" type="submit" <?= !$canRedo ? 'disabled' : '' ?>>Redo</button></form>
                    <form method="post" action="<?= e($returnUrl) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="archive_version"><input type="hidden" name="version_id" value="<?= (int) $selectedVersion['id'] ?>"><button class="button button-small button-quiet" type="submit">Archive draft</button></form>
                </div>
                <p class="muted no-print">Draft lessons can be reassigned to another configured period, assigned teacher, or room below. Any hard clash rejects the edit.</p>
                <?php foreach ($entries as $entry): ?>
                    <details class="timetable-edit-item no-print">
                        <summary><?= e(school_class_label(['level' => $entry['level'], 'section' => $entry['section']])) ?> · <?= e($weekdayNames[(int) $entry['weekday']] ?? '') ?> · <?= e(substr((string) $entry['starts_at'], 0, 5)) ?> <?= e((string) ($entry['subject_name'] ?? 'Class period')) ?></summary>
                        <form method="post" action="<?= e($returnUrl) ?>" class="form-grid two-columns">
                            <?= csrf_field() ?><input type="hidden" name="action" value="edit_entry"><input type="hidden" name="version_id" value="<?= (int) $selectedVersion['id'] ?>"><input type="hidden" name="entry_id" value="<?= (int) $entry['id'] ?>"><input type="hidden" name="class_id" value="<?= (int) $entry['class_id'] ?>"><input type="hidden" name="subject_id" value="<?= (int) $entry['subject_id'] ?>">
                            <div class="span-all"><label>Day and period</label><select name="period_id" required><?php foreach ($periods as $period): if ((string) $period['kind'] !== 'lesson') { continue; } ?><option value="<?= (int) $period['id'] ?>" <?= (int) $entry['period_id'] === (int) $period['id'] ? 'selected' : '' ?>><?= e(($weekdayNames[(int) $period['weekday']] ?? '') . ' · P' . (int) $period['period_number'] . ' · ' . substr((string) $period['starts_at'], 0, 5)) ?></option><?php endforeach; ?></select></div>
                            <div><label>Assigned teacher</label><select name="teacher_user_id" required><?php foreach ($teachers as $teacher): if (!$assertTeacherAssignment((int) $entry['class_id'], (int) $entry['subject_id'], (int) $teacher['user_id'])) { continue; } ?><option value="<?= (int) $teacher['user_id'] ?>" <?= (int) $entry['teacher_user_id'] === (int) $teacher['user_id'] ? 'selected' : '' ?>><?= e((string) $teacher['full_name']) ?></option><?php endforeach; ?></select></div>
                            <div><label>Classroom</label><select name="room_id" required><?php foreach ($rooms as $room): if (!(int) $room['is_active']) { continue; } ?><option value="<?= (int) $room['id'] ?>" <?= (int) $entry['room_id'] === (int) $room['id'] ? 'selected' : '' ?>><?= e((string) $room['name']) ?> · <?= (int) $room['capacity'] ?> seats</option><?php endforeach; ?></select></div>
                            <div class="span-all"><button class="button button-small" type="submit">Save lesson change</button></div>
                        </form>
                    </details>
                <?php endforeach; ?>
                <form method="post" action="<?= e($returnUrl) ?>" class="timetable-publish-form no-print">
                    <?= csrf_field() ?><input type="hidden" name="action" value="publish_version"><input type="hidden" name="version_id" value="<?= (int) $selectedVersion['id'] ?>">
                    <label for="effective_from">Effective from (inside this term)</label><input id="effective_from" type="date" name="effective_from" min="<?= e((string) $selectedTerm['starts_on']) ?>" max="<?= e((string) $selectedTerm['ends_on']) ?>" value="<?= e((string) $selectedTerm['starts_on']) ?>" required>
                    <button class="button button-primary" type="submit">Validate and publish timetable</button>
                    <p class="muted">Publishing reruns hard checks and makes this version effective on the selected date. An earlier schedule remains active until then; archived and superseded versions are retained.</p>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <?php if ($canManage): ?>
        <section class="panel panel-top-gap timetable-setup no-print">
            <h2>School day and period settings</h2>
            <?php if ($settings !== null): ?><p class="muted">Latest settings revision <?= (int) $settings['revision_number'] ?>. Saving creates a new revision; existing drafts keep their original period grid.</p><?php endif; ?>
            <form method="post" action="<?= e($returnUrl) ?>" class="form-stack">
                <?= csrf_field() ?><input type="hidden" name="action" value="save_settings">
                <div class="form-grid two-columns">
                    <div class="span-all"><fieldset class="timetable-weekdays"><legend>School days</legend><?php foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat'] as $dayNo => $dayLabel): ?><label><input type="checkbox" name="weekdays[]" value="<?= $dayNo ?>" <?= $settings === null ? ($dayNo <= 5 ? 'checked' : '') : (in_array($dayNo, array_map('intval', json_decode((string) $settings['weekdays_json'], true) ?: []), true) ? 'checked' : '') ?>> <?= e($dayLabel) ?></label><?php endforeach; ?></fieldset></div>
                    <div><label for="starts_at">School day starts</label><input id="starts_at" name="starts_at" type="time" value="<?= $settings ? e(substr((string) $settings['starts_at'], 0, 5)) : '08:00' ?>" required></div>
                    <div><label for="lesson_minutes">Lesson length (minutes)</label><input id="lesson_minutes" name="lesson_minutes" type="number" min="15" max="180" value="<?= $settings ? (int) $settings['lesson_minutes'] : 40 ?>" required></div>
                    <div><label for="lesson_count">Lessons per day</label><input id="lesson_count" name="lesson_count" type="number" min="1" max="20" value="<?= $settings ? (int) $settings['lesson_count'] : 8 ?>" required></div>
                    <?php foreach (['assembly' => 'Assembly', 'break' => 'Break', 'lunch' => 'Lunch'] as $block => $label): ?>
                        <div><label for="<?= e($block) ?>_after_period"><?= e($label) ?> after period</label><select id="<?= e($block) ?>_after_period" name="<?= e($block) ?>_after_period"><option value="">None</option><?php for ($periodNo = 1; $periodNo < (int) ($settings['lesson_count'] ?? 8); $periodNo++): ?><option value="<?= $periodNo ?>" <?= (int) ($settings[$block . '_after_period'] ?? 0) === $periodNo ? 'selected' : '' ?>>After period <?= $periodNo ?></option><?php endfor; ?></select></div>
                        <div><label for="<?= e($block) ?>_minutes"><?= e($label) ?> duration (minutes)</label><input id="<?= e($block) ?>_minutes" name="<?= e($block) ?>_minutes" type="number" min="0" max="180" value="<?= (int) ($settings[$block . '_minutes'] ?? 0) ?>"></div>
                    <?php endforeach; ?>
                    <div><label for="max_periods_per_teacher_day">Maximum lessons per teacher / day</label><input id="max_periods_per_teacher_day" name="max_periods_per_teacher_day" type="number" min="1" max="20" value="<?= (int) ($settings['max_periods_per_teacher_day'] ?? 6) ?>" required></div>
                    <div><label for="max_periods_per_teacher_week">Maximum lessons per teacher / week</label><input id="max_periods_per_teacher_week" name="max_periods_per_teacher_week" type="number" min="1" max="100" value="<?= (int) ($settings['max_periods_per_teacher_week'] ?? 30) ?>" required></div>
                    <div><label for="max_periods_per_class_day">Maximum lessons per class / day</label><input id="max_periods_per_class_day" name="max_periods_per_class_day" type="number" min="1" max="20" value="<?= (int) ($settings['max_periods_per_class_day'] ?? 8) ?>" required></div>
                </div>
                <button class="button button-primary" type="submit">Save school day settings</button>
            </form>
        </section>

        <section class="panel panel-top-gap timetable-setup no-print">
            <h2>Classrooms and rooms</h2>
            <form method="post" action="<?= e($returnUrl) ?>" class="form-grid two-columns">
                <?= csrf_field() ?><input type="hidden" name="action" value="save_room">
                <div><label for="room_name">Room name</label><input id="room_name" name="name" maxlength="100" required></div>
                <div><label for="room_capacity">Seating capacity</label><input id="room_capacity" name="capacity" type="number" min="1" max="65535" required></div>
                <div><label for="room_type">Room type (optional)</label><input id="room_type" name="room_type" maxlength="60" placeholder="Classroom, laboratory"></div>
                <div><label for="room_building">Building (optional)</label><input id="room_building" name="building" maxlength="100"></div>
                <div class="span-all"><label for="room_facilities">Facilities (optional)</label><input id="room_facilities" name="facilities" maxlength="500"></div>
                <div class="span-all"><button class="button button-small" type="submit">Add classroom</button></div>
            </form>
            <?php if ($rooms !== []): ?>
                <?php foreach ($rooms as $room): ?>
                    <details class="timetable-edit-item">
                        <summary><?= e((string) $room['name']) ?> · <?= (int) $room['capacity'] ?> seats<?= !(int) $room['is_active'] ? ' · Inactive' : '' ?></summary>
                        <form method="post" action="<?= e($returnUrl) ?>" class="form-grid two-columns">
                            <?= csrf_field() ?><input type="hidden" name="action" value="save_room"><input type="hidden" name="room_id" value="<?= (int) $room['id'] ?>">
                            <div><label>Room name</label><input name="name" maxlength="100" value="<?= e((string) $room['name']) ?>" required></div>
                            <div><label>Capacity</label><input name="capacity" type="number" min="1" max="65535" value="<?= (int) $room['capacity'] ?>" required></div>
                            <div><label>Room type</label><input name="room_type" maxlength="60" value="<?= e((string) ($room['room_type'] ?? '')) ?>"></div>
                            <div><label>Building</label><input name="building" maxlength="100" value="<?= e((string) ($room['building'] ?? '')) ?>"></div>
                            <div class="span-all"><label>Facilities</label><input name="facilities" maxlength="500" value="<?= e((string) ($room['facilities'] ?? '')) ?>"></div>
                            <div class="span-all"><button class="button button-small" type="submit">Save classroom</button></div>
                        </form>
                        <?php if ((int) $room['is_active']): ?>
                            <form method="post" action="<?= e($returnUrl) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="deactivate_room"><input type="hidden" name="room_id" value="<?= (int) $room['id'] ?>"><button class="button button-small button-quiet" type="submit">Deactivate room</button></form>
                        <?php endif; ?>
                    </details>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <section class="panel panel-top-gap timetable-setup no-print">
            <h2>Weekly lesson requirements</h2>
            <p class="muted">Requirements use existing class-subject offerings and assigned school teachers. Double periods are placed consecutively and together.</p>
            <?php if ($offerings === [] || $teachers === []): ?><p class="muted">Add active classes, subject offerings, and assigned teacher memberships before adding weekly requirements.</p>
            <?php else: ?>
                <form method="post" action="<?= e($returnUrl) ?>" class="form-grid two-columns">
                    <?= csrf_field() ?><input type="hidden" name="action" value="save_requirement">
                    <div><label for="requirement_offering">Class and subject</label><select id="requirement_offering" name="offering" required><?php foreach ($offerings as $offering): ?><option value="<?= (int) $offering['class_id'] ?>:<?= (int) $offering['subject_id'] ?>" data-assigned-teacher="<?= (int) ($offering['assigned_teacher_id'] ?? 0) ?>" data-assigned-teacher-name="<?= e((string) ($offering['assigned_teacher_name'] ?? '')) ?>"><?= e(school_class_label(['level' => $offering['level'], 'section' => $offering['section']])) ?> · <?= e((string) $offering['subject_name']) ?><?= $offering['code'] ? ' (' . e((string) $offering['code']) . ')' : '' ?></option><?php endforeach; ?></select></div>
                    <div><label for="requirement_teacher_display">Assigned teacher</label><input id="requirement_teacher_display" type="text" value="" readonly data-assigned-teacher-name><input type="hidden" name="teacher_user_id" value="" data-assigned-teacher-id></div>
                    <div><label for="periods_per_week">Lessons per week</label><input id="periods_per_week" name="periods_per_week" type="number" min="1" max="50" value="5" required></div>
                    <div><label for="double_periods">Double periods per week</label><input id="double_periods" name="double_periods" type="number" min="0" max="25" value="0" required></div>
                    <div><label for="preferred_room_id">Preferred room (optional)</label><select id="preferred_room_id" name="preferred_room_id"><option value="0">Any room with enough capacity</option><?php foreach ($rooms as $room): if (!(int) $room['is_active']) { continue; } ?><option value="<?= (int) $room['id'] ?>"><?= e((string) $room['name']) ?> · <?= (int) $room['capacity'] ?> seats</option><?php endforeach; ?></select></div>
                    <div class="span-all"><button class="button button-small" type="submit" data-requirement-submit>Save weekly requirement</button></div>
                </form>
            <?php endif; ?>
            <?php if ($requirements !== []): ?><div class="table-scroll"><table><thead><tr><th>Class</th><th>Subject</th><th>Teacher</th><th>Periods</th><th>Doubles</th><th>Preferred room</th><th></th></tr></thead><tbody><?php foreach ($requirements as $requirement): ?><tr><td><?= e(school_class_label(['level' => $requirement['level'], 'section' => $requirement['section']])) ?></td><td><?= e((string) $requirement['subject_name']) ?></td><td><?= e((string) $requirement['teacher_name']) ?></td><td><?= (int) $requirement['periods_per_week'] ?></td><td><?= (int) $requirement['double_periods'] ?></td><td><?= e((string) ($requirement['preferred_room'] ?? 'Any suitable room')) ?></td><td><form method="post" action="<?= e($returnUrl) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="delete_requirement"><input type="hidden" name="requirement_id" value="<?= (int) $requirement['id'] ?>"><button class="button button-small button-quiet" type="submit">Remove</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
        </section>

        <?php if ($settings !== null): ?>
            <section class="panel panel-top-gap timetable-setup no-print">
                <h2>Availability and constraints</h2>
                <form method="post" action="<?= e($returnUrl) ?>" class="form-grid two-columns">
                    <?= csrf_field() ?><input type="hidden" name="action" value="save_unavailability">
                    <div><label for="unavailable_type">Resource</label><select id="unavailable_type" name="entity_type"><option value="class">Class</option><option value="teacher">Teacher</option><option value="room">Room</option></select></div>
                    <div><label for="unavailable_entity">Class / teacher / room</label><select id="unavailable_entity" name="entity_id" required><?php foreach ($classes as $class): ?><option data-resource-type="class" value="<?= (int) $class['id'] ?>"><?= e(school_class_label($class)) ?></option><?php endforeach; ?><?php foreach ($teachers as $teacher): ?><option data-resource-type="teacher" value="<?= (int) $teacher['user_id'] ?>" hidden><?= e((string) $teacher['full_name']) ?></option><?php endforeach; ?><?php foreach ($rooms as $room): if (!(int) $room['is_active']) { continue; } ?><option data-resource-type="room" value="<?= (int) $room['id'] ?>" hidden><?= e((string) $room['name']) ?></option><?php endforeach; ?></select></div>
                    <div><label for="unavailable_weekday">Day</label><select id="unavailable_weekday" name="weekday"><?php foreach ($weekdayNames as $dayNo => $dayName): ?><option value="<?= $dayNo ?>"><?= e($dayName) ?></option><?php endforeach; ?></select></div>
                    <div><label for="unavailable_period">Period number</label><select id="unavailable_period" name="period_number"><?php for ($periodNo = 1; $periodNo <= (int) $settings['lesson_count']; $periodNo++): ?><option value="<?= $periodNo ?>">Period <?= $periodNo ?></option><?php endfor; ?></select></div>
                    <div class="span-all"><label for="unavailable_reason">Reason (optional)</label><input id="unavailable_reason" name="reason" maxlength="180"></div>
                    <div class="span-all"><button class="button button-small" type="submit" data-unavailable-submit>Save unavailable slot</button></div>
                </form>
                <?php if ($unavailable !== []): ?><div class="table-scroll"><table><thead><tr><th>Resource</th><th>Day</th><th>Period</th><th>Reason</th><th></th></tr></thead><tbody><?php foreach ($unavailable as $slot): ?><tr><td><?= e(ucfirst((string) $slot['entity_type']) . ': ' . (string) ($slot['entity_name'] ?? 'Unknown resource')) ?></td><td><?= e($weekdayNames[(int) $slot['weekday']] ?? '—') ?></td><td><?= (int) $slot['period_number'] ?></td><td><?= e((string) ($slot['reason'] ?? '—')) ?></td><td><form method="post" action="<?= e($returnUrl) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="delete_unavailability"><input type="hidden" name="unavailable_id" value="<?= (int) $slot['id'] ?>"><button class="button button-small button-quiet" type="submit">Remove</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="panel panel-top-gap timetable-setup no-print">
            <h2>Generate a draft</h2>
            <p class="muted">The deterministic scheduler balances teacher and class loads, favors preferred rooms, and rejects class, teacher, room, capacity, availability, and workload conflicts. Publishing always runs the validator again.</p>
            <form method="post" action="<?= e($returnUrl) ?>" class="form-grid two-columns">
                <?= csrf_field() ?><input type="hidden" name="action" value="generate_draft">
                <div class="span-all"><label for="draft_title">Draft title (optional)</label><input id="draft_title" name="title" maxlength="120" placeholder="Generated timetable"></div>
                <div class="span-all"><button class="button button-primary" type="submit" <?= $settings === null || $requirements === [] || $rooms === [] ? 'disabled' : '' ?>>Generate timetable draft</button></div>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($selectedVersion !== null && (string) $selectedVersion['status'] === 'published' && $entries !== []): ?>
        <section class="panel panel-top-gap timetable-substitutions no-print">
            <h2>Teacher absences and substitutions</h2>
            <p class="muted">A substitution is advisory until a school leader confirms it. Proposals require an absence/excused attendance record and are rechecked for teacher clashes before approval. The published recurring schedule is not modified.</p>
            <?php if ($canManage): ?>
                <form method="post" action="<?= e($returnUrl) ?>" class="form-grid two-columns">
                    <?= csrf_field() ?><input type="hidden" name="action" value="propose_substitution">
                    <div><label for="substitution_entry">Published lesson</label><select id="substitution_entry" name="entry_id" required><?php foreach ($entries as $entry): if ((int) $entry['teacher_user_id'] < 1) { continue; } ?><option value="<?= (int) $entry['id'] ?>"><?= e(school_class_label(['level' => $entry['level'], 'section' => $entry['section']])) ?> · <?= e($weekdayNames[(int) $entry['weekday']] ?? '') ?> · <?= e(substr((string) $entry['starts_at'], 0, 5)) ?> · <?= e((string) $entry['subject_name']) ?></option><?php endforeach; ?></select></div>
                    <div><label for="substitution_date">Date</label><input id="substitution_date" name="substitution_date" type="date" min="<?= e(max((string) $selectedTerm['starts_on'], (new DateTimeImmutable('today'))->format('Y-m-d'))) ?>" max="<?= e((string) $selectedTerm['ends_on']) ?>" required></div>
                    <div><label for="substitute_teacher">Substitute teacher</label><select id="substitute_teacher" name="substitute_teacher_user_id" required><?php foreach ($teachers as $teacher): if ((int) $teacher['user_id'] === $userId) { continue; } ?><option value="<?= (int) $teacher['user_id'] ?>"><?= e((string) $teacher['full_name']) ?></option><?php endforeach; ?></select></div>
                    <div><label for="substitution_reason">Reason (optional)</label><input id="substitution_reason" name="reason" maxlength="500"></div>
                    <div class="span-all"><button class="button button-small" type="submit">Propose substitution</button></div>
                </form>
            <?php endif; ?>
            <?php if ($substitutions !== []): ?><div class="table-scroll"><table><thead><tr><th>Date</th><th>Class / subject</th><th>Original teacher</th><th>Substitute</th><th>Status</th><?php if ($canManage): ?><th>Action</th><?php endif; ?></tr></thead><tbody><?php foreach ($substitutions as $substitution): ?><tr><td><?= e((string) $substitution['substitution_date']) ?></td><td><?= e(school_class_label(['level' => $substitution['level'], 'section' => $substitution['section']])) ?> · <?= e((string) $substitution['subject_name']) ?> · <?= e(substr((string) $substitution['starts_at'], 0, 5)) ?></td><td><?= e((string) ($substitution['original_name'] ?? '—')) ?></td><td><?= e((string) $substitution['substitute_name']) ?></td><td><?= e(ucfirst((string) $substitution['status'])) ?></td><?php if ($canManage): ?><td><?php if ((string) $substitution['status'] === 'proposed'): ?><form method="post" action="<?= e($returnUrl) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="substitution_id" value="<?= (int) $substitution['id'] ?>"><button class="button button-small button-primary" type="submit" name="action" value="approve_substitution">Approve</button><button class="button button-small button-quiet" type="submit" name="action" value="cancel_substitution">Cancel</button></form><?php endif; ?></td><?php endif; ?></tr><?php endforeach; ?></tbody></table></div><?php else: ?><p class="muted">No substitution proposals have been recorded for this timetable.</p><?php endif; ?>
        </section>
    <?php endif; ?>
<?php endif; ?>
<script src="assets/js/timetable.js?v=1" defer></script>
<?php render_footer(); ?>
