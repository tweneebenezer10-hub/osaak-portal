<?php
declare(strict_types=1);

function school_staff_employment_transition(string $newStatus, string $effectiveOn, string $today): array
{
    if (!in_array($newStatus, ['suspended', 'terminated', 'resigned', 'retired', 'transferred'], true)) {
        throw new InvalidArgumentException('Choose a valid employment status.');
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $effectiveOn);
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false || $date->format('Y-m-d') !== $effectiveOn
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $effectiveOn < $today) {
        throw new InvalidArgumentException('Choose a valid effective date that is today or later.');
    }
    $future = $effectiveOn > $today;
    if ($newStatus === 'suspended' && $future) {
        throw new InvalidArgumentException('Suspension must take effect today. Future-dated employment endings are supported.');
    }
    return [
        'event_type' => $future ? 'termination_scheduled' : $newStatus,
        'new_status' => $newStatus,
        'current_status' => $future ? 'termination_scheduled' : $newStatus,
        'effective_on' => $effectiveOn,
        'event_state' => 'scheduled',
    ];
}

function school_staff_employment_schema_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['_schema_emp_ready'])) {
        $ready = true;
        return true;
    }
    $pdo = db();
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
        $ready = in_array('school_staff_employment_events', $tables, true)
            && in_array('school_staff_responsibility_history', $tables, true);
        return $ready;
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name IN ("school_staff_employment_events", "school_staff_responsibility_history")'
    );
    $stmt->execute();
    $tablesReady = (int) $stmt->fetchColumn() === 2;
    if (!$tablesReady) {
        $ready = false;
        return $ready;
    }
    $columns = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = "school_staff"
           AND column_name IN ("position_title", "employment_status", "employment_effective_on")'
    );
    $columns->execute();
    $ready = (int) $columns->fetchColumn() === 3;
    if ($ready && session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['_schema_emp_ready'] = true;
    }
    return $ready;
}

function school_staff_employment_record_responsibility(
    PDO $pdo,
    int $schoolId,
    int $eventId,
    int $formerStaffId,
    ?int $replacementStaffId,
    string $type,
    string $key,
    array $details,
    string $effectiveOn,
    int $actorUserId,
    bool $attentionRequired = false
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO school_staff_responsibility_history
         (school_id, employment_event_id, former_staff_id, replacement_staff_id,
          responsibility_type, responsibility_key, responsibility_json, effective_on,
          attention_required, recorded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $schoolId,
        $eventId,
        $formerStaffId,
        $replacementStaffId,
        $type,
        $key,
        json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        $effectiveOn,
        $attentionRequired ? 1 : 0,
        $actorUserId,
    ]);
}

function school_staff_employment_replacement(
    PDO $pdo,
    int $schoolId,
    mixed $replacementStaffId,
    string $requiredRole
): ?array {
    $id = filter_var($replacementStaffId, FILTER_VALIDATE_INT);
    if ($id === false || $id < 1) {
        return null;
    }
    $roles = match ($requiredRole) {
        'class_teacher' => ['class_teacher'],
        'administrative' => [
            'school_proprietor',
            'school_headmaster',
            'school_assistant_headmaster',
            'school_secretary',
            'school_accountant',
            'class_teacher',
            'subject_teacher',
        ],
        default => ['class_teacher', 'subject_teacher'],
    };
    $rolePlaceholders = implode(',', array_fill(0, count($roles), '?'));
    $stmt = $pdo->prepare(
        'SELECT staff.id, staff.user_id, staff.position_title, account.full_name
         FROM school_staff staff
         JOIN users account ON account.id = staff.user_id
           AND account.status = "active" AND account.email_verified_at IS NOT NULL
         WHERE staff.id = ? AND staff.school_id = ? AND staff.status = "active"
           AND staff.employment_status = "active"
           AND EXISTS (
               SELECT 1 FROM school_memberships membership
               WHERE membership.school_id = staff.school_id AND membership.user_id = staff.user_id
                 AND membership.status = "active" AND membership.role_name IN (' . $rolePlaceholders . ')
           )
         LIMIT 1'
    );
    $stmt->execute(array_merge([(int) $id, $schoolId], $roles));
    $replacement = $stmt->fetch();
    return is_array($replacement) ? $replacement : null;
}

function school_staff_employment_apply_event(PDO $pdo, int $eventId): bool
{
    $eventStmt = $pdo->prepare(
        'SELECT employment_event.*, staff.user_id, staff.status AS legacy_status,
                staff.employment_status AS current_employment_status,
                staff.employee_number, staff.position_title, account.full_name
         FROM school_staff_employment_events employment_event
         JOIN school_staff staff ON staff.id = employment_event.staff_id AND staff.school_id = employment_event.school_id
         JOIN users account ON account.id = staff.user_id
         WHERE employment_event.id = ? FOR UPDATE'
    );
    $eventStmt->execute([$eventId]);
    $event = $eventStmt->fetch();
    if (!is_array($event) || (string) $event['event_state'] !== 'scheduled') {
        return false;
    }

    $schoolId = (int) $event['school_id'];
    $staffId = (int) $event['staff_id'];
    $userId = (int) $event['user_id'];
    $actorId = (int) $event['authorized_by'];
    $effectiveOn = (string) $event['effective_on'];
    $newStatus = (string) $event['new_status'];
    $plan = json_decode((string) ($event['replacement_plan_json'] ?? '{}'), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($plan)) {
        throw new RuntimeException('The saved staff replacement plan is invalid.');
    }
    $replacementValue = static function (string $type, string $key) use ($plan): mixed {
        $group = $plan[$type] ?? null;
        return is_array($group) ? ($group[$key] ?? null) : null;
    };

    $classesStmt = $pdo->prepare(
        'SELECT class.id, class.name, class.level, class.section, class.academic_year,
                class.class_teacher_id, assignment.teacher_user_id AS assignment_teacher_id
         FROM school_classes class
         LEFT JOIN teacher_class_assignments assignment
           ON assignment.school_id = class.school_id AND assignment.class_id = class.id
         WHERE class.school_id = ? AND (class.class_teacher_id = ? OR assignment.teacher_user_id = ?)
         FOR UPDATE'
    );
    $classesStmt->execute([$schoolId, $userId, $userId]);
    foreach ($classesStmt->fetchAll() as $class) {
        $classId = (int) $class['id'];
        $replacement = school_staff_employment_replacement(
            $pdo,
            $schoolId,
            $replacementValue('class_teacher', (string) $classId),
            'class_teacher'
        );
        $replacementStaffId = $replacement ? (int) $replacement['id'] : null;
        school_staff_employment_record_responsibility(
            $pdo,
            $schoolId,
            $eventId,
            $staffId,
            $replacementStaffId,
            'class_teacher',
            (string) $classId,
            [
                'class_id' => $classId,
                'class_name' => (string) $class['name'],
                'level' => (string) $class['level'],
                'section' => $class['section'],
                'academic_year' => (string) $class['academic_year'],
                'former_teacher_user_id' => $userId,
                'replacement_user_id' => $replacement ? (int) $replacement['user_id'] : null,
            ],
            $effectiveOn,
            $actorId,
            $replacement === null
        );

        if ((int) ($class['class_teacher_id'] ?? 0) === $userId) {
            $updateClass = $pdo->prepare(
                'UPDATE school_classes SET class_teacher_id = ? WHERE id = ? AND school_id = ?'
            );
            $updateClass->execute([$replacement ? (int) $replacement['user_id'] : null, $classId, $schoolId]);
        }
        if ($replacement) {
            $assignment = $pdo->prepare(
                'INSERT INTO teacher_class_assignments
                 (school_id, class_id, teacher_user_id, academic_year, assigned_by)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE teacher_user_id = VALUES(teacher_user_id),
                   academic_year = VALUES(academic_year), assigned_by = VALUES(assigned_by), assigned_at = NOW()'
            );
            $assignment->execute([$schoolId, $classId, (int) $replacement['user_id'], (string) $class['academic_year'], $actorId]);
        } else {
            $deleteAssignment = $pdo->prepare(
                'DELETE FROM teacher_class_assignments WHERE school_id = ? AND class_id = ? AND teacher_user_id = ?'
            );
            $deleteAssignment->execute([$schoolId, $classId, $userId]);
        }
    }

    $subjectsStmt = $pdo->prepare(
        'SELECT offering.class_id, offering.subject_id, class.name AS class_name,
                class.level, class.section, class.academic_year, subject.name AS subject_name
         FROM class_subjects offering
         JOIN school_classes class ON class.id = offering.class_id AND class.school_id = offering.school_id
         JOIN school_subjects subject ON subject.id = offering.subject_id AND subject.school_id = offering.school_id
         WHERE offering.school_id = ? AND offering.teacher_user_id = ?
         FOR UPDATE'
    );
    $subjectsStmt->execute([$schoolId, $userId]);
    foreach ($subjectsStmt->fetchAll() as $offering) {
        $classId = (int) $offering['class_id'];
        $subjectId = (int) $offering['subject_id'];
        $key = $classId . ':' . $subjectId;
        $replacement = school_staff_employment_replacement(
            $pdo,
            $schoolId,
            $replacementValue('subject_teacher', $key),
            'subject_teacher'
        );
        school_staff_employment_record_responsibility(
            $pdo,
            $schoolId,
            $eventId,
            $staffId,
            $replacement ? (int) $replacement['id'] : null,
            'subject_teacher',
            $key,
            [
                'class_id' => $classId,
                'class_name' => (string) $offering['class_name'],
                'level' => (string) $offering['level'],
                'section' => $offering['section'],
                'academic_year' => (string) $offering['academic_year'],
                'subject_id' => $subjectId,
                'subject_name' => (string) $offering['subject_name'],
                'former_teacher_user_id' => $userId,
                'replacement_user_id' => $replacement ? (int) $replacement['user_id'] : null,
            ],
            $effectiveOn,
            $actorId,
            $replacement === null
        );
        $updateOffering = $pdo->prepare(
            'UPDATE class_subjects SET teacher_user_id = ?
             WHERE school_id = ? AND class_id = ? AND subject_id = ? AND teacher_user_id = ?'
        );
        $updateOffering->execute([
            $replacement ? (int) $replacement['user_id'] : null,
            $schoolId,
            $classId,
            $subjectId,
            $userId,
        ]);
    }

    $adminTable = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = "school_staff_administrative_assignments"'
    );
    $adminTable->execute();
    if ((int) $adminTable->fetchColumn() === 1) {
        $adminStmt = $pdo->prepare(
            'SELECT id, staff_name, job_title, responsibilities
             FROM school_staff_administrative_assignments
             WHERE school_id = ? AND staff_id = ? AND status = "active" FOR UPDATE'
        );
        $adminStmt->execute([$schoolId, $staffId]);
        foreach ($adminStmt->fetchAll() as $assignment) {
            $replacement = school_staff_employment_replacement(
                $pdo,
                $schoolId,
                $replacementValue('administrative', (string) $assignment['id']),
                'administrative'
            );
            school_staff_employment_record_responsibility(
                $pdo,
                $schoolId,
                $eventId,
                $staffId,
                $replacement ? (int) $replacement['id'] : null,
                'administrative',
                (string) $assignment['id'],
                [
                    'staff_name' => (string) $assignment['staff_name'],
                    'job_title' => (string) $assignment['job_title'],
                    'responsibilities' => (string) $assignment['responsibilities'],
                    'replacement_name' => $replacement ? (string) $replacement['full_name'] : null,
                ],
                $effectiveOn,
                $actorId,
                $replacement === null
            );
            if ($replacement) {
                $updateAdmin = $pdo->prepare(
                    'UPDATE school_staff_administrative_assignments
                     SET staff_id = ?, staff_name = ?, updated_by = ?
                     WHERE id = ? AND school_id = ? AND status = "active"'
                );
                $updateAdmin->execute([
                    (int) $replacement['id'],
                    (string) $replacement['full_name'],
                    $actorId,
                    (int) $assignment['id'],
                    $schoolId,
                ]);
            } else {
                $removeAdmin = $pdo->prepare(
                    'UPDATE school_staff_administrative_assignments
                     SET status = "removed", removed_at = UTC_TIMESTAMP(), updated_by = ?
                     WHERE id = ? AND school_id = ? AND status = "active"'
                );
                $removeAdmin->execute([$actorId, (int) $assignment['id'], $schoolId]);
            }
        }
    }

    $timetableTable = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name IN ("school_timetable_entries", "school_timetable_versions")'
    );
    $timetableTable->execute();
    if ((int) $timetableTable->fetchColumn() === 2) {
        $timetableStmt = $pdo->prepare(
            'SELECT entry.id, entry.version_id, entry.class_id, entry.subject_id,
                    entry.weekday, entry.starts_at, entry.ends_at,
                    class.name AS class_name, subject.name AS subject_name
             FROM school_timetable_entries entry
             JOIN school_timetable_versions version
               ON version.id = entry.version_id AND version.school_id = entry.school_id
             JOIN school_classes class ON class.id = entry.class_id AND class.school_id = entry.school_id
             LEFT JOIN school_subjects subject
               ON subject.id = entry.subject_id AND subject.school_id = entry.school_id
             WHERE entry.school_id = ? AND entry.teacher_user_id = ?
               AND version.status = "published"
               AND (version.effective_to IS NULL OR version.effective_to >= ?)
             FOR UPDATE'
        );
        $timetableStmt->execute([$schoolId, $userId, $effectiveOn]);
        foreach ($timetableStmt->fetchAll() as $entry) {
            school_staff_employment_record_responsibility(
                $pdo,
                $schoolId,
                $eventId,
                $staffId,
                null,
                'timetable',
                (string) $entry['id'],
                [
                    'version_id' => (int) $entry['version_id'],
                    'entry_id' => (int) $entry['id'],
                    'class_id' => (int) $entry['class_id'],
                    'class_name' => (string) $entry['class_name'],
                    'subject_id' => (int) $entry['subject_id'],
                    'subject_name' => (string) ($entry['subject_name'] ?? ''),
                    'weekday' => (int) $entry['weekday'],
                    'starts_at' => (string) $entry['starts_at'],
                    'ends_at' => (string) $entry['ends_at'],
                    'action_required' => 'Publish an effective-dated timetable revision; published history was not modified.',
                ],
                $effectiveOn,
                $actorId,
                true
            );
        }
    }

    $legacyStatus = $newStatus === 'suspended' ? 'suspended' : 'inactive';
    $updateStaff = $pdo->prepare(
        'UPDATE school_staff
         SET status = ?, employment_status = ?, employment_effective_on = ?
         WHERE id = ? AND school_id = ?'
    );
    $updateStaff->execute([$legacyStatus, $newStatus, $effectiveOn, $staffId, $schoolId]);
    $disableRoles = $pdo->prepare(
        'UPDATE school_memberships SET status = "disabled"
         WHERE school_id = ? AND user_id = ? AND status = "active"
           AND role_name NOT IN ("school_parent", "school_student")'
    );
    $disableRoles->execute([$schoolId, $userId]);

    $complete = $pdo->prepare(
        'UPDATE school_staff_employment_events SET event_state = "applied", processed_at = UTC_TIMESTAMP()
         WHERE id = ? AND event_state = "scheduled"'
    );
    $complete->execute([$eventId]);
    audit(
        $actorId,
        'school_staff_employment_status_applied',
        'school_id=' . $schoolId . ';staff_id=' . $staffId . ';event_id=' . $eventId
            . ';status=' . $newStatus . ';effective_on=' . $effectiveOn
    );
    return true;
}

function school_staff_employment_responsibilities(int $schoolId, array $staff): array
{
    $userId = (int) ($staff['user_id'] ?? 0);
    $staffId = (int) ($staff['id'] ?? 0);
    if ($schoolId < 1 || $userId < 1 || $staffId < 1) {
        return [];
    }
    $responsibilities = [];
    $classes = db()->prepare(
        'SELECT class.id, class.name, class.level, class.section, class.academic_year
         FROM school_classes class
         LEFT JOIN teacher_class_assignments assignment
           ON assignment.school_id = class.school_id AND assignment.class_id = class.id
         WHERE class.school_id = ? AND (class.class_teacher_id = ? OR assignment.teacher_user_id = ?)
         ORDER BY class.academic_year DESC, class.level, class.section'
    );
    $classes->execute([$schoolId, $userId, $userId]);
    foreach ($classes->fetchAll() as $class) {
        $responsibilities[] = [
            'type' => 'class_teacher',
            'key' => (string) (int) $class['id'],
            'label' => 'Class teacher — ' . (string) $class['name'] . ' (' . (string) $class['academic_year'] . ')',
        ];
    }

    $subjects = db()->prepare(
        'SELECT offering.class_id, offering.subject_id, class.name AS class_name,
                class.academic_year, subject.name AS subject_name
         FROM class_subjects offering
         JOIN school_classes class ON class.id = offering.class_id AND class.school_id = offering.school_id
         JOIN school_subjects subject ON subject.id = offering.subject_id AND subject.school_id = offering.school_id
         WHERE offering.school_id = ? AND offering.teacher_user_id = ?
         ORDER BY class.academic_year DESC, class.name, subject.name'
    );
    $subjects->execute([$schoolId, $userId]);
    foreach ($subjects->fetchAll() as $offering) {
        $key = (int) $offering['class_id'] . ':' . (int) $offering['subject_id'];
        $responsibilities[] = [
            'type' => 'subject_teacher',
            'key' => $key,
            'label' => 'Subject teacher — ' . (string) $offering['subject_name'] . ', '
                . (string) $offering['class_name'] . ' (' . (string) $offering['academic_year'] . ')',
        ];
    }

    $timetableTables = db()->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name IN ("school_timetable_entries", "school_timetable_versions")'
    );
    $timetableTables->execute();
    if ((int) $timetableTables->fetchColumn() === 2) {
        $timetable = db()->prepare(
            'SELECT entry.id, entry.class_id, entry.subject_id, entry.weekday,
                    entry.starts_at, entry.ends_at, class.name AS class_name,
                    subject.name AS subject_name
             FROM school_timetable_entries entry
             JOIN school_timetable_versions version
               ON version.id = entry.version_id AND version.school_id = entry.school_id
             JOIN school_classes class ON class.id = entry.class_id AND class.school_id = entry.school_id
             LEFT JOIN school_subjects subject
               ON subject.id = entry.subject_id AND subject.school_id = entry.school_id
             WHERE entry.school_id = ? AND entry.teacher_user_id = ?
               AND version.status = "published"
               AND (version.effective_to IS NULL OR version.effective_to >= ?)
             ORDER BY entry.weekday, entry.starts_at'
        );
        $timetable->execute([$schoolId, $userId, date('Y-m-d')]);
        foreach ($timetable->fetchAll() as $entry) {
            $responsibilities[] = [
                'type' => 'timetable',
                'key' => (string) (int) $entry['id'],
                'label' => 'Published timetable — ' . (string) $entry['class_name'] . ', '
                    . (string) ($entry['subject_name'] ?? 'subject') . ' (weekday '
                    . (int) $entry['weekday'] . ', ' . substr((string) $entry['starts_at'], 0, 5) . ')',
            ];
        }
    }

    $adminTable = db()->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = "school_staff_administrative_assignments"'
    );
    $adminTable->execute();
    if ((int) $adminTable->fetchColumn() === 1) {
        $administrative = db()->prepare(
            'SELECT id, job_title, responsibilities
             FROM school_staff_administrative_assignments
             WHERE school_id = ? AND staff_id = ? AND status = "active"
             ORDER BY job_title, id'
        );
        $administrative->execute([$schoolId, $staffId]);
        foreach ($administrative->fetchAll() as $assignment) {
            $responsibilities[] = [
                'type' => 'administrative',
                'key' => (string) (int) $assignment['id'],
                'label' => 'Administrative — ' . (string) $assignment['job_title'],
            ];
        }
    }

    return $responsibilities;
}

function school_staff_employment_process_due(int $schoolId, bool $throwOnFailure = false): int
{
    if ($schoolId < 1 || !school_staff_employment_schema_ready()) {
        return 0;
    }
    $due = db()->prepare(
        'SELECT id FROM school_staff_employment_events
         WHERE school_id = ? AND event_state = "scheduled" AND effective_on <= ?
         ORDER BY effective_on, id LIMIT 25'
    );
    $due->execute([$schoolId, date('Y-m-d')]);
    $eventIds = array_map('intval', $due->fetchAll(PDO::FETCH_COLUMN));
    $processed = 0;
    foreach ($eventIds as $eventId) {
        db()->beginTransaction();
        try {
            if (school_staff_employment_apply_event(db(), $eventId)) {
                db()->commit();
                $processed++;
            } else {
                db()->rollBack();
            }
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            error_log('Scheduled staff employment event ' . $eventId . ' could not be applied: ' . $exception->getMessage());
            if ($throwOnFailure) {
                throw $exception;
            }
        }
    }
    return $processed;
}
