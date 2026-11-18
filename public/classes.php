<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = require_school_context();
$school = active_school_context();
$schoolId = (int) $school['id'];
require_school_permission($schoolId, 'classes.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if (in_array($action, ['add_level', 'update_level'], true)) {
        $levelId = filter_var($_POST['level_id'] ?? null, FILTER_VALIDATE_INT);
        $name = trim((string) ($_POST['name'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? ''));
        $academicStage = (string) ($_POST['academic_stage'] ?? '');
        $displayOrder = filter_var($_POST['display_order'] ?? null, FILTER_VALIDATE_INT);
        $nextLevelId = filter_var($_POST['next_level_id'] ?? null, FILTER_VALIDATE_INT);
        $graduationLevel = filter_var($_POST['is_graduation_level'] ?? '0', FILTER_VALIDATE_INT);
        $editingLevel = $action === 'update_level';
        $returnUrl = $editingLevel && $levelId !== false && $levelId > 0
            ? 'classes.php?edit_level=' . $levelId . '#class-levels'
            : 'classes.php?add_level=1#class-levels';

        if (!school_class_level_name_is_valid($name)
            || !school_class_category_is_valid($category)
            || !school_class_academic_stage_is_valid($academicStage)
            || $displayOrder === false || $displayOrder < 0 || $displayOrder > 100000
            || $graduationLevel === false || !in_array($graduationLevel, [0, 1], true)
            || ($editingLevel && ($levelId === false || $levelId < 1))
            || ($nextLevelId !== false && $nextLevelId < 1)
            || ($graduationLevel === 1 && $nextLevelId !== false)) {
            flash('error', 'Enter valid class details. A graduation level cannot also progress to another level.');
            redirect($returnUrl);
        }

        $existingLevel = null;
        if ($editingLevel) {
            $levelCheck = db()->prepare(
                'SELECT id, name, next_level_id FROM school_class_levels
                 WHERE id = ? AND school_id = ? LIMIT 1'
            );
            $levelCheck->execute([$levelId, $schoolId]);
            $existingLevel = $levelCheck->fetch() ?: null;
            if ($existingLevel === null) {
                flash('error', 'The selected class level does not belong to this school.');
                redirect('classes.php#class-levels');
            }
        }
        if ($nextLevelId !== false) {
            if ($editingLevel && (int) $nextLevelId === (int) $levelId) {
                flash('error', 'A class level cannot progress to itself.');
                redirect($returnUrl);
            }
            $nextLevelCheck = db()->prepare(
                'SELECT status FROM school_class_levels
                 WHERE id = ? AND school_id = ? LIMIT 1'
            );
            $nextLevelCheck->execute([$nextLevelId, $schoolId]);
            $nextLevelStatus = $nextLevelCheck->fetchColumn();
            if ($nextLevelStatus === false
                || ($nextLevelStatus !== 'active'
                    && (!$editingLevel || (int) ($existingLevel['next_level_id'] ?? 0) !== (int) $nextLevelId))) {
                flash('error', 'Choose an active progression level belonging to this school.');
                redirect($returnUrl);
            }
        }
        $duplicateCheck = db()->prepare(
            'SELECT 1 FROM school_class_levels
             WHERE school_id = ? AND name = ?' . ($editingLevel ? ' AND id <> ?' : '') . '
             LIMIT 1'
        );
        $duplicateParams = [$schoolId, $name];
        if ($editingLevel) {
            $duplicateParams[] = $levelId;
        }
        $duplicateCheck->execute($duplicateParams);
        if ($duplicateCheck->fetchColumn()) {
            flash('error', 'A class level with this name already exists at the school.');
            redirect($returnUrl);
        }

        db()->beginTransaction();
        try {
            $levelsForWriteStmt = db()->prepare(
                'SELECT id, next_level_id, status
                 FROM school_class_levels WHERE school_id = ? ORDER BY id FOR UPDATE'
            );
            $levelsForWriteStmt->execute([$schoolId]);
            $levelsForWrite = [];
            foreach ($levelsForWriteStmt->fetchAll() as $levelForWrite) {
                $levelsForWrite[(int) $levelForWrite['id']] = $levelForWrite;
            }
            if ($editingLevel && !isset($levelsForWrite[(int) $levelId])) {
                throw new InvalidArgumentException('The selected class level does not belong to this school.');
            }
            if ($nextLevelId !== false) {
                $targetLevel = $levelsForWrite[(int) $nextLevelId] ?? null;
                $currentNextLevelId = $editingLevel
                    ? (int) ($levelsForWrite[(int) $levelId]['next_level_id'] ?? 0)
                    : 0;
                if (!is_array($targetLevel)
                    || ($targetLevel['status'] !== 'active'
                        && (!$editingLevel || $currentNextLevelId !== (int) $nextLevelId))) {
                    throw new InvalidArgumentException('Choose an active progression level belonging to this school.');
                }
                if ($editingLevel) {
                    $nextLevelById = [];
                    foreach ($levelsForWrite as $progressionLevelId => $levelForWrite) {
                        $nextLevelById[$progressionLevelId] = $levelForWrite['next_level_id'] === null
                            ? 0
                            : (int) $levelForWrite['next_level_id'];
                    }
                    if (school_class_progression_would_cycle(
                        $nextLevelById,
                        (int) $levelId,
                        (int) $nextLevelId
                    )) {
                        throw new InvalidArgumentException('Class progression cannot form a cycle.');
                    }
                }
            }
            if ($editingLevel) {
                db()->prepare(
                    'UPDATE school_class_levels
                     SET name = ?, category = ?, academic_stage = ?, is_graduation_level = ?,
                         display_order = ?, next_level_id = ?
                     WHERE id = ? AND school_id = ?'
                )->execute([
                    $name,
                    $category,
                    $academicStage,
                    $graduationLevel,
                    $displayOrder,
                    $nextLevelId === false ? null : $nextLevelId,
                    $levelId,
                    $schoolId,
                ]);
                $savedLevelId = (int) $levelId;
            } else {
                db()->prepare(
                    'INSERT INTO school_class_levels
                     (school_id, name, category, academic_stage, is_graduation_level,
                      display_order, next_level_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $schoolId,
                    $name,
                    $category,
                    $academicStage,
                    $graduationLevel,
                    $displayOrder,
                    $nextLevelId === false ? null : $nextLevelId,
                ]);
                $savedLevelId = (int) db()->lastInsertId();
            }
            db()->commit();
        } catch (PDOException $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            error_log('School class-level save failed: ' . $exception->getMessage());
            flash('error', 'The class level could not be saved because it conflicts with an existing class record.');
            redirect($returnUrl);
        } catch (InvalidArgumentException $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            flash('error', $exception->getMessage());
            redirect($returnUrl);
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            throw $exception;
        }
        audit(
            (int) $user['id'],
            $editingLevel ? 'school_class_level_updated' : 'school_class_level_created',
            'school_id=' . $schoolId . ';class_level_id=' . $savedLevelId
        );
        flash('success', $editingLevel ? 'Class level updated.' : 'Class level created.');
        redirect('classes.php#class-levels');
    }

    if (in_array($action, ['archive_level', 'activate_level', 'delete_level'], true)) {
        $levelId = filter_var($_POST['level_id'] ?? null, FILTER_VALIDATE_INT);
        if ($levelId === false || $levelId < 1) {
            flash('error', 'Choose a valid school class level.');
            redirect('classes.php#class-levels');
        }
        $levelCheck = db()->prepare(
            'SELECT id FROM school_class_levels WHERE id = ? AND school_id = ? LIMIT 1'
        );
        $levelCheck->execute([$levelId, $schoolId]);
        if (!$levelCheck->fetchColumn()) {
            flash('error', 'The selected class level does not belong to this school.');
            redirect('classes.php#class-levels');
        }
        if ($action === 'delete_level') {
            db()->beginTransaction();
            try {
                $levelLock = db()->prepare(
                    'SELECT id, next_level_id FROM school_class_levels
                     WHERE id = ? AND school_id = ? FOR UPDATE'
                );
                $levelLock->execute([$levelId, $schoolId]);
                $lockedLevel = $levelLock->fetch();
                if (!is_array($lockedLevel)) {
                    throw new InvalidArgumentException('The selected class level does not belong to this school.');
                }
                $classCountStmt = db()->prepare(
                    'SELECT COUNT(*) FROM school_classes
                     WHERE school_id = ? AND class_level_id = ?'
                );
                $classCountStmt->execute([$schoolId, $levelId]);
                $nextLevelCountStmt = db()->prepare(
                    'SELECT COUNT(*) FROM school_class_levels
                     WHERE school_id = ? AND next_level_id = ?'
                );
                $nextLevelCountStmt->execute([$schoolId, $levelId]);
                if ((int) $classCountStmt->fetchColumn() > 0
                    || (int) $nextLevelCountStmt->fetchColumn() > 0
                    || $lockedLevel['next_level_id'] !== null) {
                    throw new RuntimeException('This class level is used by class records or progression rules. Archive it instead of deleting it.');
                }
                db()->prepare(
                    'DELETE FROM school_class_levels WHERE id = ? AND school_id = ?'
                )->execute([$levelId, $schoolId]);
                db()->commit();
            } catch (InvalidArgumentException | RuntimeException $exception) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                flash('error', $exception->getMessage());
                redirect('classes.php#class-levels');
            } catch (Throwable $exception) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                throw $exception;
            }
            audit((int) $user['id'], 'school_class_level_deleted', 'school_id=' . $schoolId . ';class_level_id=' . $levelId);
            flash('success', 'Unused class level deleted.');
            redirect('classes.php#class-levels');
        }

        $newStatus = $action === 'archive_level' ? 'archived' : 'active';
        db()->prepare(
            'UPDATE school_class_levels SET status = ? WHERE id = ? AND school_id = ?'
        )->execute([$newStatus, $levelId, $schoolId]);
        audit(
            (int) $user['id'],
            $action === 'archive_level' ? 'school_class_level_archived' : 'school_class_level_activated',
            'school_id=' . $schoolId . ';class_level_id=' . $levelId
        );
        flash('success', $action === 'archive_level' ? 'Class level deactivated. Existing class records were retained.' : 'Class level activated.');
        redirect('classes.php#class-levels');
    }

    if (in_array($action, ['archive_class', 'activate_class', 'delete_class'], true)) {
        $classId = filter_var($_POST['class_id'] ?? null, FILTER_VALIDATE_INT);
        if ($classId === false || $classId < 1) {
            flash('error', 'Choose a valid school class.');
            redirect('classes.php#class-list');
        }
        db()->beginTransaction();
        try {
            $classLock = db()->prepare(
                'SELECT id, status FROM school_classes
                 WHERE id = ? AND school_id = ? FOR UPDATE'
            );
            $classLock->execute([$classId, $schoolId]);
            $class = $classLock->fetch();
            if (!is_array($class)) {
                throw new InvalidArgumentException('The selected class does not belong to this school.');
            }

            if ($action === 'archive_class') {
                $activeStudentCount = db()->prepare(
                    'SELECT COUNT(*) FROM school_students
                     WHERE school_id = ? AND class_id = ? AND school_status = "active"'
                );
                $activeStudentCount->execute([$schoolId, $classId]);
                if ((int) $activeStudentCount->fetchColumn() > 0) {
                    throw new RuntimeException('Move current students to an active class before deactivating this class.');
                }
                db()->prepare(
                    'UPDATE school_classes SET status = "archived"
                     WHERE id = ? AND school_id = ?'
                )->execute([$classId, $schoolId]);
            } elseif ($action === 'activate_class') {
                $classLevelCheck = db()->prepare(
                    'SELECT class_level.status
                     FROM school_classes class
                     JOIN school_class_levels class_level
                       ON class_level.id = class.class_level_id AND class_level.school_id = class.school_id
                     WHERE class.id = ? AND class.school_id = ?'
                );
                $classLevelCheck->execute([$classId, $schoolId]);
                if ($classLevelCheck->fetchColumn() !== 'active') {
                    throw new RuntimeException('Activate the class level before reactivating this class.');
                }
                db()->prepare(
                    'UPDATE school_classes SET status = "active"
                     WHERE id = ? AND school_id = ?'
                )->execute([$classId, $schoolId]);
            } else {
                if (school_class_has_references(db(), (int) $classId)) {
                    throw new RuntimeException('This class has student, academic, attendance, fee, timetable, or history records. Archive it instead of deleting it.');
                }
                db()->prepare(
                    'DELETE FROM school_classes WHERE id = ? AND school_id = ?'
                )->execute([$classId, $schoolId]);
            }
            db()->commit();
        } catch (InvalidArgumentException | RuntimeException $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            flash('error', $exception->getMessage());
            redirect('classes.php#class-list');
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            throw $exception;
        }
        audit(
            (int) $user['id'],
            $action === 'archive_class' ? 'school_class_archived' : ($action === 'activate_class' ? 'school_class_activated' : 'school_class_deleted'),
            'school_id=' . $schoolId . ';class_id=' . $classId
        );
        flash('success', $action === 'archive_class'
            ? 'Class deactivated. Its history and records were retained.'
            : ($action === 'activate_class' ? 'Class activated.' : 'Unused class deleted.'));
        redirect('classes.php#class-list');
    }

    if (in_array($action, ['add_class', 'update_class'], true)) {
        $levelId = filter_var($_POST['class_level_id'] ?? null, FILTER_VALIDATE_INT);
        $section = trim((string) ($_POST['section'] ?? ''));
        $year = trim((string) ($_POST['academic_year'] ?? ''));
        $teacherId = filter_var($_POST['class_teacher_id'] ?? null, FILTER_VALIDATE_INT);
        $classId = filter_var($_POST['class_id'] ?? null, FILTER_VALIDATE_INT);
        $capacityInput = trim((string) ($_POST['capacity'] ?? ''));
        $capacity = $capacityInput === '' ? null : filter_var($capacityInput, FILTER_VALIDATE_INT);
        $editingClass = $action === 'update_class';
        $formReturn = $editingClass && $classId !== false && $classId > 0
            ? 'classes.php?edit=' . $classId
            : 'classes.php?add=1';

        if ($levelId === false || $levelId < 1
            || preg_match('/^.{0,40}$/us', $section) !== 1
            || preg_match('/[\x00-\x1F\x7F]/u', $section) === 1
            || ($capacityInput !== '' && ($capacity === false || $capacity < 1 || $capacity > 100000))
            || (is_string($_POST['class_teacher_id'] ?? null) && $_POST['class_teacher_id'] !== ''
                && ($teacherId === false || $teacherId < 1))
            || ($editingClass && ($classId === false || $classId < 1))) {
            flash('error', 'Choose a class level, enter a valid academic year and stream, and check the class capacity and teacher details.');
            redirect($formReturn);
        }

        $levelStmt = db()->prepare(
            'SELECT id, name, category, academic_stage, status
             FROM school_class_levels WHERE id = ? AND school_id = ? LIMIT 1'
        );
        $levelStmt->execute([$levelId, $schoolId]);
        $classLevel = $levelStmt->fetch();
        if (!is_array($classLevel)) {
            flash('error', 'Choose an active class level belonging to this school.');
            redirect($formReturn);
        }

        $existingClass = null;
        if ($editingClass) {
            $classCheck = db()->prepare(
                'SELECT class_level_id, academic_year
                 FROM school_classes WHERE id = ? AND school_id = ? LIMIT 1'
            );
            $classCheck->execute([$classId, $schoolId]);
            $existingClass = $classCheck->fetch() ?: null;
            if ($existingClass === null) {
                flash('error', 'The selected class does not belong to this school.');
                redirect($formReturn);
            }
        }
        if ((string) $classLevel['status'] !== 'active'
            && (!$editingClass || (int) $existingClass['class_level_id'] !== (int) $levelId)) {
            flash('error', 'Choose an active class level belonging to this school.');
            redirect($formReturn);
        }
        $existingClassYear = $existingClass['academic_year'] ?? null;
        $academicYearStmt = db()->prepare(
            'SELECT status, starts_on, ends_on FROM school_academic_years
             WHERE school_id = ? AND academic_year = ? LIMIT 1'
        );
        $academicYearStmt->execute([$schoolId, $year]);
        $academicYearRecord = $academicYearStmt->fetch();
        if (!$academicYearRecord
            || (!school_academic_year_name_is_valid($year)
                && (!$editingClass || $year !== $existingClassYear))
            || (($academicYearRecord['status'] !== 'open'
                    || !$academicYearRecord['starts_on'] || !$academicYearRecord['ends_on'])
                && (!$editingClass || $year !== $existingClassYear))) {
            flash('error', 'Choose an academic year with configured dates. New classes can only be added to an open year; an existing class may keep its current year label.');
            redirect($formReturn);
        }
        if ($teacherId !== false && $teacherId > 0) {
            $teacherCheck = db()->prepare(
                "SELECT 1 FROM school_memberships
                 WHERE school_id = ? AND user_id = ?
                   AND role_name IN ('class_teacher','subject_teacher') AND status = 'active'
                 LIMIT 1"
            );
            $teacherCheck->execute([$schoolId, $teacherId]);
            if (!$teacherCheck->fetchColumn()) {
                flash('error', 'Select an active class teacher assigned to this school.');
                redirect($formReturn);
            }
        }

        $sectionValue = $section === '' ? null : $section;
        $teacherValue = $teacherId !== false && $teacherId > 0 ? $teacherId : null;
        $name = (string) $classLevel['name'] . ($section === '' ? '' : ' ' . $section);
        $duplicateSql = 'SELECT 1 FROM school_classes
                         WHERE school_id = ? AND class_level_id = ?
                           AND section <=> ? AND academic_year = ?';
        $duplicateParams = [$schoolId, $levelId, $sectionValue, $year];
        if ($editingClass) {
            $duplicateSql .= ' AND id <> ?';
            $duplicateParams[] = $classId;
        }
        $duplicateSql .= ' LIMIT 1';
        $duplicateCheck = db()->prepare($duplicateSql);
        $duplicateCheck->execute($duplicateParams);
        if ($duplicateCheck->fetchColumn()) {
            flash('error', 'A class with this level, stream, and academic year already exists.');
            redirect($formReturn);
        }

        db()->beginTransaction();
        try {
            $levelForWriteStmt = db()->prepare(
                'SELECT id, name, category, academic_stage, status
                 FROM school_class_levels
                 WHERE id = ? AND school_id = ? FOR UPDATE'
            );
            $levelForWriteStmt->execute([$levelId, $schoolId]);
            $levelForWrite = $levelForWriteStmt->fetch();
            if (!is_array($levelForWrite)
                || ((string) $levelForWrite['status'] !== 'active'
                    && (!$editingClass || (int) $existingClass['class_level_id'] !== (int) $levelId))) {
                throw new InvalidArgumentException('Choose an active class level belonging to this school.');
            }
            $classLevel = $levelForWrite;
            $name = (string) $classLevel['name'] . ($section === '' ? '' : ' ' . $section);
            $duplicateCheck->execute($duplicateParams);
            if ($duplicateCheck->fetchColumn()) {
                throw new RuntimeException('A class with this level, stream, and academic year already exists.');
            }

            if ($editingClass) {
                $classLock = db()->prepare(
                    'SELECT class_level_id, academic_year FROM school_classes
                     WHERE id = ? AND school_id = ? FOR UPDATE'
                );
                $classLock->execute([$classId, $schoolId]);
                $lockedClass = $classLock->fetch();
                if (!is_array($lockedClass)) {
                    throw new InvalidArgumentException('The selected class does not belong to this school.');
                }
                if (((int) $lockedClass['class_level_id'] !== (int) $levelId
                        || (string) $lockedClass['academic_year'] !== $year)
                    && school_class_has_references(db(), (int) $classId)) {
                    throw new RuntimeException('A class with linked student or academic history cannot be moved to another level or year. Archive it and create the corrected class instead.');
                }
                $activeStudentStmt = db()->prepare(
                    'SELECT COUNT(*) FROM school_students
                     WHERE school_id = ? AND class_id = ? AND school_status = "active"'
                );
                $activeStudentStmt->execute([$schoolId, $classId]);
                if ($capacity !== null && (int) $activeStudentStmt->fetchColumn() > $capacity) {
                    throw new RuntimeException('Capacity cannot be lower than the number of students currently assigned to this class.');
                }
                db()->prepare(
                    'UPDATE school_classes
                     SET class_level_id = ?, name = ?, level = ?, category = ?, academic_stage = ?,
                         section = ?, class_teacher_id = ?, academic_year = ?, capacity = ?,
                         level_review_required = 0
                     WHERE id = ? AND school_id = ?'
                )->execute([
                    $levelId,
                    $name,
                    (string) $classLevel['name'],
                    (string) $classLevel['category'],
                    (string) $classLevel['academic_stage'],
                    $sectionValue,
                    $teacherValue,
                    $year,
                    $capacity,
                    $classId,
                    $schoolId,
                ]);
            } else {
                db()->prepare(
                    'INSERT INTO school_classes
                     (school_id, class_level_id, name, level, category, academic_stage,
                      section, class_teacher_id, academic_year, capacity, level_review_required)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)'
                )->execute([
                    $schoolId,
                    $levelId,
                    $name,
                    (string) $classLevel['name'],
                    (string) $classLevel['category'],
                    (string) $classLevel['academic_stage'],
                    $sectionValue,
                    $teacherValue,
                    $year,
                    $capacity,
                ]);
                $classId = (int) db()->lastInsertId();
            }

            if ($teacherValue === null) {
                db()->prepare(
                    'DELETE FROM teacher_class_assignments WHERE school_id = ? AND class_id = ?'
                )->execute([$schoolId, $classId]);
            } else {
                db()->prepare(
                    'INSERT INTO teacher_class_assignments
                     (school_id, class_id, teacher_user_id, academic_year, assigned_by)
                     VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE teacher_user_id = VALUES(teacher_user_id),
                         academic_year = VALUES(academic_year), assigned_by = VALUES(assigned_by),
                         assigned_at = CURRENT_TIMESTAMP'
                )->execute([$schoolId, $classId, $teacherValue, $year, (int) $user['id']]);
            }
            db()->commit();
        } catch (InvalidArgumentException | RuntimeException $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            flash('error', $exception->getMessage());
            redirect($formReturn);
        } catch (PDOException $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            error_log('School class save failed: ' . $exception->getMessage());
            flash('error', 'The class could not be saved because its name or academic-year assignment conflicts with an existing class.');
            redirect($formReturn);
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            throw $exception;
        }
        audit(
            (int) $user['id'],
            'school_class_teacher_assignment_saved',
            'school_id=' . $schoolId . ';class_id=' . $classId . ';teacher_id='
                . ($teacherValue ?? 'none') . ';academic_year=' . $year
        );
        flash('success', $editingClass ? 'Class details updated successfully.' : 'Class created successfully.');
        redirect('classes.php');
    }
}

$teacherStmt = db()->prepare(
    "SELECT DISTINCT user.id, user.full_name
     FROM school_memberships membership
     JOIN users user ON user.id = membership.user_id
     WHERE membership.school_id = ?
       AND membership.role_name IN ('class_teacher','subject_teacher')
       AND membership.status = 'active'
     ORDER BY user.full_name"
);
$teacherStmt->execute([$schoolId]);
$teachers = $teacherStmt->fetchAll();

$levelStmt = db()->prepare(
    'SELECT class_level.id, class_level.name, class_level.category, class_level.academic_stage,
            class_level.is_graduation_level,
            class_level.display_order, class_level.next_level_id, class_level.status,
            next_level.name AS next_level_name,
            (SELECT COUNT(*) FROM school_classes class
             WHERE class.school_id = class_level.school_id
               AND class.class_level_id = class_level.id) AS class_count,
            (SELECT COUNT(*) FROM school_class_levels incoming
             WHERE incoming.school_id = class_level.school_id
               AND incoming.next_level_id = class_level.id) AS incoming_progression_count
     FROM school_class_levels class_level
     LEFT JOIN school_class_levels next_level
       ON next_level.id = class_level.next_level_id AND next_level.school_id = class_level.school_id
     WHERE class_level.school_id = ?
     ORDER BY class_level.display_order, class_level.name'
);
$levelStmt->execute([$schoolId]);
$classLevels = $levelStmt->fetchAll();
$activeClassLevels = array_values(array_filter(
    $classLevels,
    static fn(array $level): bool => (string) $level['status'] === 'active'
));
$editingLevel = null;
$editingLevelId = filter_var($_GET['edit_level'] ?? null, FILTER_VALIDATE_INT);
if ($editingLevelId !== false && $editingLevelId > 0) {
    foreach ($classLevels as $level) {
        if ((int) $level['id'] === (int) $editingLevelId) {
            $editingLevel = $level;
            break;
        }
    }
    if ($editingLevel === null) {
        flash('error', 'That class level could not be found in this school.');
        redirect('classes.php#class-levels');
    }
}
$showLevelForm = $editingLevel !== null || (string) ($_GET['add_level'] ?? '') === '1';

$classStmt = db()->prepare(
    'SELECT class.id, class.class_level_id, class.level, class.category, class.academic_stage,
            class.section, class.academic_year, class.capacity, class.status,
            class.level_review_required, user.full_name AS teacher_name,
            COUNT(DISTINCT CASE WHEN student.school_status = "active" THEN student.id END) AS active_student_count
     FROM school_classes class
     JOIN school_class_levels class_level
       ON class_level.id = class.class_level_id AND class_level.school_id = class.school_id
     LEFT JOIN users user ON user.id = class.class_teacher_id
     LEFT JOIN school_students student
       ON student.school_id = class.school_id AND student.class_id = class.id
     WHERE class.school_id = ?
     GROUP BY class.id, class.class_level_id, class.level, class.category, class.academic_stage,
              class.section, class.academic_year, class.capacity, class.status,
              class.level_review_required, user.full_name, class_level.display_order
     ORDER BY class_level.display_order, class.level, class.academic_year DESC, class.section'
);
$classStmt->execute([$schoolId]);
$classes = $classStmt->fetchAll();
$academicYears = school_academic_years_for_school($schoolId);
$hasConfiguredOpenYear = array_filter(
    $academicYears,
    static fn(array $year): bool => $year['status'] === 'open'
        && $year['starts_on'] !== null && $year['ends_on'] !== null
) !== [];
$activeAcademicYear = school_active_academic_year($schoolId);

$editingClass = null;
$editingClassId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT);
if ($editingClassId !== false && $editingClassId > 0) {
    $editingStmt = db()->prepare(
        'SELECT id, class_level_id, level, category, academic_stage, section,
                academic_year, class_teacher_id, capacity, level_review_required
         FROM school_classes WHERE id = ? AND school_id = ? LIMIT 1'
    );
    $editingStmt->execute([$editingClassId, $schoolId]);
    $editingClass = $editingStmt->fetch() ?: null;
    if ($editingClass === null) {
        flash('error', 'That class could not be found in this school.');
        redirect('classes.php');
    }
}
$openClassForm = $editingClass !== null || (string) ($_GET['add'] ?? '') === '1';

render_header('Classes & Levels');
?>
<section class="panel">
    <p class="eyebrow">ACADEMIC CLASSES</p>
    <h1>Classes &amp; Levels</h1>
    <p class="muted">Define your school’s class levels, categories, progression, yearly class groups, teachers, and optional capacity.</p>
    <?php if (!$hasConfiguredOpenYear): ?><p class="notice notice-error">Configure an open academic year and its dates in <a href="term_setup.php">Academic Years &amp; Terms</a> before creating a year-specific class.</p><?php endif; ?>
</section>

<section class="report-action-grid" aria-label="Class management actions">
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('students') ?></span>
        <div><h2>Class structure</h2><p>Create custom levels, set categories and order, and choose each level’s progression target.</p></div>
        <a class="button button-primary" href="classes.php?add_level=1#class-levels">Add New Class</a>
    </article>
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('calendar') ?></span>
        <div><h2>Year-specific classes</h2><p>Open a class for an academic year, assign a teacher, and set capacity if needed.</p></div>
        <a class="button" href="classes.php?add=1">Add Class Group</a>
    </article>
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('calendar') ?></span>
        <div><h2>Academic Years &amp; Terms</h2><p>Configure school years and dates for class groups.</p></div>
        <a class="button" href="term_setup.php">Manage Calendar</a>
    </article>
</section>

<section class="panel panel-top-gap" id="class-levels">
    <h2>School class structure</h2>
    <p class="muted">Class names and categories are school-defined. Display order controls lists; progression targets guide promotion without moving records automatically. Mark terminal levels that require the school’s graduation review. Editing a level keeps existing year-group labels and academic records unchanged; new groups use the updated definition.</p>
    <?php if ($showLevelForm): ?>
        <form method="post" action="classes.php#class-levels" class="form-stack">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $editingLevel === null ? 'add_level' : 'update_level' ?>">
            <?php if ($editingLevel !== null): ?><input type="hidden" name="level_id" value="<?= (int) $editingLevel['id'] ?>"><?php endif; ?>
            <div class="form-grid two-columns">
                <label for="level-name">Class name
                    <input id="level-name" name="name" maxlength="80" required value="<?= e((string) ($editingLevel['name'] ?? '')) ?>" placeholder="Creche, Nursery 1, KG1, Primary 1…">
                </label>
                <label for="level-category">Category
                    <input id="level-category" name="category" maxlength="60" required value="<?= e((string) ($editingLevel['category'] ?? '')) ?>" placeholder="Nursery, Primary, Vocational, or your own category">
                </label>
                <label for="academic-stage">Academic stage
                    <select id="academic-stage" name="academic_stage" required>
                        <?php foreach (['primary' => 'Early / Primary', 'secondary' => 'Secondary', 'other' => 'Other / not categorized'] as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= ($editingLevel['academic_stage'] ?? 'other') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="check-label">
                    <input type="checkbox" name="is_graduation_level" value="1" <?= (int) ($editingLevel['is_graduation_level'] ?? 0) === 1 ? 'checked' : '' ?>>
                    Graduation level (must not progress to another level)
                </label>
                <label for="level-order">Display order
                    <input id="level-order" name="display_order" type="number" min="0" max="100000" required value="<?= e((string) ($editingLevel['display_order'] ?? count($classLevels) * 10)) ?>">
                </label>
                <label for="next-level-id">Progresses to
                    <select id="next-level-id" name="next_level_id">
                        <option value="">No automatic progression target</option>
                        <?php foreach ($classLevels as $candidateLevel): ?>
                            <?php if ($editingLevel !== null && (int) $candidateLevel['id'] === (int) $editingLevel['id']) { continue; } ?>
                            <?php if ($candidateLevel['status'] !== 'active'
                                && (int) ($editingLevel['next_level_id'] ?? 0) !== (int) $candidateLevel['id']) { continue; } ?>
                            <option value="<?= (int) $candidateLevel['id'] ?>" <?= (int) ($editingLevel['next_level_id'] ?? 0) === (int) $candidateLevel['id'] ? 'selected' : '' ?>>
                                <?= e((string) $candidateLevel['name']) ?><?= $candidateLevel['status'] === 'archived' ? ' · Deactivated' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <div class="class-editor-actions">
                <button class="button button-primary" type="submit"><?= $editingLevel === null ? 'Save Class Level' : 'Update Class Level' ?></button>
                <a class="button button-quiet" href="classes.php#class-levels">Cancel</a>
            </div>
        </form>
    <?php endif; ?>
    <?php if ($classLevels === []): ?>
        <p class="muted">No class levels have been created yet. Add a class level to start defining your school’s structure.</p>
    <?php else: ?>
        <div class="table-scroll"><table>
            <thead><tr><th>Class level</th><th>Category / stage</th><th>Order</th><th>Progression</th><th>Graduation</th><th>Year groups</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($classLevels as $level): ?>
                <tr>
                    <td><?= e((string) $level['name']) ?></td>
                    <td><?= e((string) $level['category']) ?> · <?= e(ucfirst((string) $level['academic_stage'])) ?></td>
                    <td><?= (int) $level['display_order'] ?></td>
                    <td><?= e((string) ($level['next_level_name'] ?: 'Not set')) ?></td>
                    <td><?= (int) $level['is_graduation_level'] === 1 ? 'Eligible after review' : 'Not designated' ?></td>
                    <td><?= (int) $level['class_count'] ?></td>
                    <td><?= e(ucfirst((string) $level['status'])) ?></td>
                    <td>
                        <a href="classes.php?edit_level=<?= (int) $level['id'] ?>#class-levels">Edit</a>
                        <form method="post" action="classes.php#class-levels" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="level_id" value="<?= (int) $level['id'] ?>">
                            <?php if ($level['status'] === 'active'): ?>
                                <button class="button button-quiet button-small" type="submit" name="action" value="archive_level" data-confirm="Deactivate this class level? Existing year-specific classes and student records will be retained.">Deactivate</button>
                            <?php else: ?>
                                <button class="button button-quiet button-small" type="submit" name="action" value="activate_level">Activate</button>
                            <?php endif; ?>
                            <?php if ((int) $level['class_count'] === 0
                                && (int) $level['incoming_progression_count'] === 0
                                && $level['next_level_id'] === null): ?>
                                <button class="button button-quiet button-small" type="submit" name="action" value="delete_level" data-confirm="Delete this unused class level?">Delete</button>
                            <?php else: ?>
                                <span class="muted">In use</span>
                            <?php endif; ?>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>

<dialog class="class-editor-dialog" data-class-editor-dialog<?= $openClassForm ? ' data-auto-open="true"' : '' ?>>
    <div class="class-editor-content">
        <h2><?= $editingClass === null ? 'Add Class Group' : 'Edit Class Group' ?></h2>
        <?php if ($editingClass !== null && (bool) $editingClass['level_review_required']): ?>
            <p class="notice notice-error">This legacy class level needs review. Update its school class-level definition before using it for new class groups.</p>
        <?php endif; ?>
        <?php if ($activeClassLevels === [] && $editingClass === null): ?>
            <p class="notice notice-error">Create and activate a class level in the school class structure before adding a year-specific class.</p>
        <?php else: ?>
        <form method="post" action="classes.php" class="form-stack">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $editingClass === null ? 'add_class' : 'update_class' ?>">
            <?php if ($editingClass !== null): ?><input type="hidden" name="class_id" value="<?= (int) $editingClass['id'] ?>"><?php endif; ?>
            <div class="form-grid two-columns">
                <label for="class-level-id">Class level
                    <select id="class-level-id" name="class_level_id" required>
                        <option value="">Select a class level</option>
                        <?php foreach ($classLevels as $level): ?>
                            <?php if ($level['status'] !== 'active' && (int) ($editingClass['class_level_id'] ?? 0) !== (int) $level['id']) { continue; } ?>
                            <option value="<?= (int) $level['id'] ?>" <?= (int) ($editingClass['class_level_id'] ?? 0) === (int) $level['id'] ? 'selected' : '' ?>>
                                <?= e((string) $level['name']) ?> · <?= e((string) $level['category']) ?><?= $level['status'] === 'archived' ? ' · Deactivated' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label for="section">Stream <span class="label-hint">(optional)</span>
                    <input id="section" name="section" type="text" maxlength="40" placeholder="A, B, Blue" value="<?= e((string) ($editingClass['section'] ?? '')) ?>">
                </label>
                <label for="academic_year">Academic year
                    <select id="academic_year" name="academic_year" required>
                        <option value="">Select academic year</option>
                        <?php foreach ($academicYears as $academicYear): ?>
                            <option value="<?= e((string) $academicYear['academic_year']) ?>" <?= ($editingClass['academic_year'] ?? $activeAcademicYear['academic_year'] ?? '') === $academicYear['academic_year'] ? 'selected' : '' ?>>
                                <?= e(school_academic_year_label((string) $academicYear['academic_year'])) ?><?= $academicYear['status'] === 'closed' ? ' · Closed (existing classes only)' : ((int) $academicYear['is_active'] === 1 ? ' · Active' : '') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label for="class-capacity">Capacity <span class="label-hint">(optional)</span>
                    <input id="class-capacity" name="capacity" type="number" min="1" max="100000" value="<?= e((string) ($editingClass['capacity'] ?? '')) ?>" placeholder="No limit">
                </label>
                <label for="class_teacher_id" class="span-full">Class teacher
                    <select id="class_teacher_id" name="class_teacher_id">
                        <option value="">Unassigned</option>
                        <?php foreach ($teachers as $teacher): ?>
                            <option value="<?= (int) $teacher['id'] ?>" <?= $editingClass !== null && (int) $editingClass['class_teacher_id'] === (int) $teacher['id'] ? 'selected' : '' ?>><?= e((string) $teacher['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <div class="class-editor-actions">
                <button class="button button-primary" type="submit"><?= $editingClass === null ? 'Save Class' : 'Update Class' ?></button>
                <?php if ($editingClass === null): ?>
                    <button class="button" type="button" data-close-class-dialog>Cancel</button>
                <?php else: ?>
                    <a class="button" href="classes.php">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
        <?php endif; ?>
    </div>
</dialog>

<section class="panel panel-top-gap" id="class-list">
    <h2>Year-specific classes</h2>
    <?php if ($classes === []): ?>
        <p class="muted">No classes have been created yet for this school.</p>
    <?php else: ?>
        <div class="table-scroll"><table>
            <thead><tr><th>Class</th><th>Category</th><th>Academic year</th><th>Students / capacity</th><th>Class teacher</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($classes as $class): ?>
                    <tr>
                        <td><?= e(school_class_label($class)) ?><?= (bool) $class['level_review_required'] ? ' · Needs review' : '' ?></td>
                        <td><?= e((string) $class['category']) ?></td>
                        <td><?= e(school_academic_year_label((string) $class['academic_year'])) ?></td>
                        <td><?= (int) $class['active_student_count'] ?><?= $class['capacity'] !== null ? ' / ' . (int) $class['capacity'] : '' ?></td>
                        <td><?= e((string) ($class['teacher_name'] ?: 'Unassigned')) ?></td>
                        <td><?= e(ucfirst((string) $class['status'])) ?></td>
                        <td>
                            <a href="students.php?action=directory&amp;class_id=<?= (int) $class['id'] ?>">View students</a>
                            <a href="classes.php?edit=<?= (int) $class['id'] ?>">Edit</a>
                            <form method="post" action="classes.php#class-list" class="inline-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="class_id" value="<?= (int) $class['id'] ?>">
                                <?php if ($class['status'] === 'active'): ?>
                                    <button class="button button-quiet button-small" type="submit" name="action" value="archive_class" data-confirm="Deactivate this class? Current students must be moved first; existing records will be retained.">Deactivate</button>
                                <?php else: ?>
                                    <button class="button button-quiet button-small" type="submit" name="action" value="activate_class">Activate</button>
                                <?php endif; ?>
                                <button class="button button-quiet button-small" type="submit" name="action" value="delete_class" data-confirm="Delete this class only if it has no student or historical records.">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
