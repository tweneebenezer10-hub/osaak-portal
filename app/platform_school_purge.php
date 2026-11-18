<?php
declare(strict_types=1);

function platform_school_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
    );
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function platform_school_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
    );
    $stmt->execute([$table, $column]);
    return (bool) $stmt->fetchColumn();
}

function platform_school_purge_files(PDO $pdo, int $schoolId): array
{
    $files = [];
    $add = static function (?string $name, string $directory, string $pattern) use (&$files): void {
        if ($name !== null && preg_match($pattern, $name) === 1) {
            $files[$directory . '/' . $name] = true;
        }
    };

    $schoolStmt = $pdo->prepare('SELECT logo_path FROM schools WHERE id = ?');
    $schoolStmt->execute([$schoolId]);
    $add($schoolStmt->fetchColumn() ?: null, 'school-logos', '/^[a-f0-9]{48}\.(?:jpg|png|webp)$/');

    if (platform_school_table_exists($pdo, 'school_students')
        && platform_school_column_exists($pdo, 'school_students', 'photo_path')) {
        $photoStmt = $pdo->prepare('SELECT photo_path FROM school_students WHERE school_id = ?');
        $photoStmt->execute([$schoolId]);
        foreach ($photoStmt->fetchAll(PDO::FETCH_COLUMN) as $path) {
            $add(is_string($path) ? basename(str_replace('\\', '/', $path)) : null,
                'student-photos', '/^[a-f0-9]{48}\.(?:jpg|png|webp)$/');
        }
    }

    foreach ([
        ['school_staff_documents', 'staff-records', '/^[a-f0-9]{48}\.(?:pdf|jpg|jpeg|png|webp)$/'],
        ['student_lifecycle_documents', 'student-lifecycle', '/^[a-f0-9]{48}\.(?:pdf|jpg|jpeg|png|webp)$/'],
    ] as [$table, $directory, $pattern]) {
        if (!platform_school_table_exists($pdo, $table)
            || !platform_school_column_exists($pdo, $table, 'school_id')
            || !platform_school_column_exists($pdo, $table, 'stored_name')) {
            continue;
        }
        $documentStmt = $pdo->prepare('SELECT stored_name FROM `' . $table . '` WHERE school_id = ?');
        $documentStmt->execute([$schoolId]);
        foreach ($documentStmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $add(is_string($name) ? $name : null, $directory, $pattern);
        }
    }

    if (platform_school_table_exists($pdo, 'school_staff_attendance_photos')) {
        $photoStmt = $pdo->prepare(
            'SELECT stored_name, mime_type FROM school_staff_attendance_photos WHERE school_id = ?'
        );
        $photoStmt->execute([$schoolId]);
        $photoExtensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        foreach ($photoStmt->fetchAll() as $photo) {
            $extension = $photoExtensions[(string) $photo['mime_type']] ?? null;
            $name = (string) $photo['stored_name'];
            if ($extension !== null && preg_match('/^[a-f0-9]{48}$/', $name) === 1) {
                $add($name . '.' . $extension, 'staff-attendance', '/^[a-f0-9]{48}\.(?:jpg|png|webp)$/');
            }
        }
    }

    return array_keys($files);
}

function platform_purge_school(PDO $pdo, int $schoolId, string $confirmedName): array
{
    $schoolStmt = $pdo->prepare(
        'SELECT name, deleted_at FROM schools WHERE id = ? LIMIT 1 FOR UPDATE'
    );
    $schoolStmt->execute([$schoolId]);
    $school = $schoolStmt->fetch();
    if (!$school || $school['deleted_at'] === null) {
        throw new DomainException('Only a school already marked deleted can be permanently removed.');
    }
    if (!hash_equals((string) $school['name'], trim($confirmedName))) {
        throw new DomainException('The school name did not match. No data was deleted.');
    }

    $schoolTableStmt = $pdo->query(
        'SELECT DISTINCT c.TABLE_NAME
         FROM information_schema.columns c
         JOIN information_schema.tables t
           ON t.table_schema = c.table_schema AND t.table_name = c.table_name
         WHERE c.table_schema = DATABASE()
           AND c.column_name = "school_id"
           AND t.table_type = "BASE TABLE"'
    );
    $schoolTables = array_map('strval', $schoolTableStmt->fetchAll(PDO::FETCH_COLUMN));
    if (!in_array('schools', $schoolTables, true)) {
        $schoolTables[] = 'schools';
    }
    $schoolTableSet = array_fill_keys($schoolTables, true);

    $constraintsStmt = $pdo->query(
        'SELECT k.TABLE_NAME, k.REFERENCED_TABLE_NAME, r.DELETE_RULE
         FROM information_schema.key_column_usage k
         JOIN information_schema.referential_constraints r
           ON r.constraint_schema = k.constraint_schema
          AND r.constraint_name = k.constraint_name
          AND r.table_name = k.table_name
         WHERE k.table_schema = DATABASE()
           AND k.referenced_table_schema = DATABASE()
           AND k.referenced_table_name IS NOT NULL'
    );
    foreach ($constraintsStmt->fetchAll() as $constraint) {
        $childTable = (string) $constraint['TABLE_NAME'];
        $parentTable = (string) $constraint['REFERENCED_TABLE_NAME'];
        if (isset($schoolTableSet[$parentTable])
            && !isset($schoolTableSet[$childTable])
            && $constraint['DELETE_RULE'] === 'RESTRICT'
            && !in_array($childTable, ['student_record_transfer_requests'], true)) {
            throw new DomainException(
                'Permanent deletion is blocked by related records in ' . $childTable
                . '. No school data was deleted.'
            );
        }
    }

    $foreignKeysStmt = $pdo->query(
        'SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
         FROM information_schema.key_column_usage
         WHERE table_schema = DATABASE() AND referenced_table_schema = DATABASE()
           AND referenced_table_name IS NOT NULL'
    );
    $foreignKeys = $foreignKeysStmt->fetchAll();
    $children = [];
    foreach ($foreignKeys as $foreignKey) {
        $child = (string) $foreignKey['TABLE_NAME'];
        $parent = (string) $foreignKey['REFERENCED_TABLE_NAME'];
        if (isset($schoolTableSet[$child], $schoolTableSet[$parent]) && $child !== $parent) {
            $children[$parent][$child] = true;
        }
    }

    $orderedTables = [];
    $states = [];
    $visit = static function (string $table) use (&$visit, &$states, &$orderedTables, $children): void {
        $state = $states[$table] ?? 0;
        if ($state === 2) {
            return;
        }
        if ($state === 1) {
            throw new DomainException('School data relationships contain a cycle; permanent deletion was stopped safely.');
        }
        $states[$table] = 1;
        foreach (array_keys($children[$table] ?? []) as $child) {
            $visit($child);
        }
        $states[$table] = 2;
        $orderedTables[] = $table;
    };
    foreach ($schoolTables as $table) {
        $visit($table);
    }

    $filePaths = platform_school_purge_files($pdo, $schoolId);
    if (platform_school_table_exists($pdo, 'student_record_transfer_requests')) {
        $pdo->prepare(
            'DELETE FROM student_record_transfer_requests
             WHERE source_school_id = ? OR target_school_id = ?'
        )->execute([$schoolId, $schoolId]);
    }
    if (platform_school_table_exists($pdo, 'student_lifecycle_events')
        && platform_school_column_exists($pdo, 'student_lifecycle_events', 'destination_school_id')) {
        $pdo->prepare(
            'UPDATE student_lifecycle_events SET destination_school_id = NULL
             WHERE destination_school_id = ? AND school_id <> ?'
        )->execute([$schoolId, $schoolId]);
    }
    if (platform_school_table_exists($pdo, 'school_completion_certificates')
        && platform_school_column_exists($pdo, 'school_completion_certificates', 'replaces_certificate_id')) {
        $pdo->prepare(
            'UPDATE school_completion_certificates SET replaces_certificate_id = NULL
             WHERE school_id = ?'
        )->execute([$schoolId]);
    }

    $deletedRows = 0;
    foreach ($orderedTables as $table) {
        if ($table === 'schools' || $table === 'school_students') {
            continue;
        }
        if (!isset($schoolTableSet[$table]) || preg_match('/\A[A-Za-z0-9_]+\z/', $table) !== 1) {
            throw new DomainException('An unexpected table was found during the school data purge.');
        }
        $deleteStmt = $pdo->prepare('DELETE FROM `' . $table . '` WHERE school_id = ?');
        $deleteStmt->execute([$schoolId]);
        $deletedRows += $deleteStmt->rowCount();
    }

    $deleteSchool = $pdo->prepare(
        'DELETE FROM schools WHERE id = ? AND deleted_at IS NOT NULL'
    );
    $deleteSchool->execute([$schoolId]);
    if ($deleteSchool->rowCount() !== 1) {
        throw new DomainException('The school could not be permanently removed.');
    }

    return [
        'name' => (string) $school['name'],
        'deleted_rows' => $deletedRows + 1,
        'files' => $filePaths,
    ];
}

function remove_purged_school_files(array $relativePaths): array
{
    $storageRoot = realpath(APP_ROOT . '/storage');
    if ($storageRoot === false) {
        return $relativePaths === [] ? [] : ['storage directory is unavailable'];
    }
    $failedPaths = [];
    foreach ($relativePaths as $relativePath) {
        if (!is_string($relativePath)
            || preg_match('#\A(?:school-logos|student-photos|staff-records|student-lifecycle|staff-attendance)/[a-f0-9]{48}\.(?:jpg|png|webp|pdf|jpeg)\z#', $relativePath) !== 1) {
            $failedPaths[] = 'invalid stored file reference';
            continue;
        }
        $candidate = $storageRoot . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $resolved = realpath($candidate);
        if ($resolved === false) {
            continue;
        }
        if (!str_starts_with($resolved, $storageRoot . DIRECTORY_SEPARATOR) || !is_file($resolved)) {
            $failedPaths[] = $relativePath;
            continue;
        }
        if (!unlink($resolved)) {
            $failedPaths[] = $relativePath;
        }
    }
    return $failedPaths;
}
