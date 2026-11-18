<?php
declare(strict_types=1);

function school_suggested_admission_abbreviation(string $schoolName): string
{
    $words = preg_split('/[^A-Za-z0-9]+/', trim($schoolName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($words) < 2) {
        return strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $schoolName) ?? '', 0, 10));
    }
    return strtoupper(implode('', array_map(static fn(string $word): string => $word[0], array_slice($words, 0, 10))));
}

function school_admission_number_prefix(int $schoolId): string
{
    $stmt = db()->prepare('SELECT name, short_name, admission_abbreviation FROM schools WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([$schoolId]);
    $school = $stmt->fetch();
    if (!$school) {
        throw new RuntimeException('The selected school is not available for admissions.');
    }
    $configured = strtoupper(trim((string) ($school['admission_abbreviation'] ?? '')));
    if ($configured !== '') {
        if (preg_match('/^[A-Z0-9]{1,20}$/', $configured) !== 1) {
            throw new RuntimeException('The school admission abbreviation is invalid. Update it in School settings.');
        }
        return $configured;
    }
    $shortName = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $school['short_name']) ?? '');
    if ($shortName !== '') {
        return substr($shortName, 0, 20);
    }
    $suggested = school_suggested_admission_abbreviation((string) $school['name']);
    if ($suggested === '') {
        throw new RuntimeException('Set an admission abbreviation in School settings before registering students.');
    }
    return $suggested;
}

function school_existing_admission_sequence_max(int $schoolId, int $admissionYear): int
{
    $yearSuffix = substr((string) $admissionYear, -2);
    $stmt = db()->prepare('SELECT admission_number FROM school_students WHERE school_id = ? AND admission_number IS NOT NULL');
    $stmt->execute([$schoolId]);
    $maxSequence = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $number) {
        if (preg_match('~(?:^|/)(\d{2})/?(\d{4,})$~', (string) $number, $parts) === 1
            && $parts[1] === $yearSuffix) {
            $maxSequence = max($maxSequence, (int) $parts[2]);
        }
    }
    return $maxSequence;
}

function school_preview_admission_number(int $schoolId, int $admissionYear): string
{
    if ($admissionYear < 2000 || $admissionYear > 2099) {
        throw new InvalidArgumentException('Admission dates must be in the years 2000 through 2099 for the two-digit number format.');
    }
    $prefix = school_admission_number_prefix($schoolId);
    $stmt = db()->prepare(
        'SELECT next_sequence FROM school_admission_sequences WHERE school_id = ? AND admission_year = ? LIMIT 1'
    );
    $stmt->execute([$schoolId, $admissionYear]);
    $nextSequence = $stmt->fetchColumn();
    $nextSequence = max((int) ($nextSequence ?: 1), school_existing_admission_sequence_max($schoolId, $admissionYear) + 1);
    return $prefix . '/' . substr((string) $admissionYear, -2) . sprintf('%04d', $nextSequence);
}

function school_allocate_admission_number(int $schoolId, int $admissionYear): string
{
    if (!db()->inTransaction()) {
        throw new LogicException('Admission numbers must be allocated inside the student-registration transaction.');
    }
    if ($admissionYear < 2000 || $admissionYear > 2099) {
        throw new InvalidArgumentException('Admission dates must be in the years 2000 through 2099 for the two-digit number format.');
    }
    $prefix = school_admission_number_prefix($schoolId);
    db()->prepare(
        'INSERT INTO school_admission_sequences (school_id, admission_year, next_sequence)
         VALUES (?, ?, 1)
         ON DUPLICATE KEY UPDATE school_id = VALUES(school_id)'
    )->execute([$schoolId, $admissionYear]);
    $sequenceStmt = db()->prepare(
        'SELECT next_sequence FROM school_admission_sequences
         WHERE school_id = ? AND admission_year = ? FOR UPDATE'
    );
    $sequenceStmt->execute([$schoolId, $admissionYear]);
    $nextSequence = max((int) $sequenceStmt->fetchColumn(), school_existing_admission_sequence_max($schoolId, $admissionYear) + 1);
    while (true) {
        $number = $prefix . '/' . substr((string) $admissionYear, -2) . sprintf('%04d', $nextSequence);
        $existingStmt = db()->prepare('SELECT 1 FROM school_students WHERE school_id = ? AND admission_number = ? LIMIT 1');
        $existingStmt->execute([$schoolId, $number]);
        $nextSequence++;
        db()->prepare(
            'UPDATE school_admission_sequences SET next_sequence = ? WHERE school_id = ? AND admission_year = ?'
        )->execute([$nextSequence, $schoolId, $admissionYear]);
        if (!$existingStmt->fetchColumn()) {
            return $number;
        }
    }
}
