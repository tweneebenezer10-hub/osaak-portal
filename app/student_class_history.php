<?php
declare(strict_types=1);

function student_class_history_effective_date(string $effectiveOn, string $today): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $effectiveOn);
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false || $date->format('Y-m-d') !== $effectiveOn
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $effectiveOn > $today) {
        throw new InvalidArgumentException('Choose a valid effective date that is no later than today.');
    }
    return $effectiveOn;
}

function student_class_history_schema_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    $pdo = db();
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
        $ready = in_array('school_student_class_history', $tables, true);
        return $ready;
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = "school_student_class_history"'
    );
    $stmt->execute();
    $ready = (int) $stmt->fetchColumn() === 1;
    return $ready;
}

function student_class_history_record(
    PDO $pdo,
    int $schoolId,
    int $studentId,
    string $eventType,
    ?int $previousClassId,
    ?int $newClassId,
    string $effectiveOn,
    string $reason,
    ?int $actorUserId
): void {
    if (!student_class_history_schema_ready()) {
        return;
    }
    if (!in_array($eventType, ['class_change', 'reinstated', 'graduation_reversed', 'transfer_received'], true)) {
        throw new InvalidArgumentException('Choose a valid student class-history event.');
    }
    $classIds = array_values(array_unique(array_filter(
        [$previousClassId, $newClassId],
        static fn(?int $id): bool => $id !== null && $id > 0
    )));
    $classes = [];
    if ($classIds !== []) {
        $placeholders = implode(',', array_fill(0, count($classIds), '?'));
        $classStmt = $pdo->prepare(
            'SELECT id, level, section, academic_year
             FROM school_classes WHERE school_id = ? AND id IN (' . $placeholders . ')'
        );
        $classStmt->execute(array_merge([$schoolId], $classIds));
        foreach ($classStmt->fetchAll() as $class) {
            $classes[(int) $class['id']] = $class;
        }
    }
    $snapshot = static function (?int $classId) use ($classes): array {
        if ($classId === null || !isset($classes[$classId])) {
            return [null, null];
        }
        $class = $classes[$classId];
        $label = (string) $class['level'];
        if (trim((string) ($class['section'] ?? '')) !== '') {
            $label .= ' ' . (string) $class['section'];
        }
        return [$label, (string) $class['academic_year']];
    };
    [$previousLabel, $previousYear] = $snapshot($previousClassId);
    [$newLabel, $newYear] = $snapshot($newClassId);
    $stmt = $pdo->prepare(
        'INSERT INTO school_student_class_history
         (school_id, student_id, event_type, previous_class_id, new_class_id,
          previous_class_snapshot, new_class_snapshot, previous_academic_year,
          new_academic_year, effective_on, reason, actor_user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $schoolId,
        $studentId,
        $eventType,
        $previousClassId,
        $newClassId,
        $previousLabel,
        $newLabel,
        $previousYear,
        $newYear,
        $effectiveOn,
        $reason,
        $actorUserId,
    ]);
}
