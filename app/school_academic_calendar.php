<?php
declare(strict_types=1);

function school_standard_term_names(): array
{
    return ['First Term', 'Second Term', 'Third Term'];
}

function school_page_academic_period(int $schoolId): ?array
{
    $user = !empty($_SESSION['user_id']) ? current_user() : null;
    if ($user === null
        || active_it_support_context($user) !== null
        || !in_array(
            school_user_role_for_school((int) $user['id'], $schoolId),
            ['school_headmaster', 'school_proprietor'],
            true
        )) {
        return school_academic_period_for_date($schoolId);
    }

    $selection = $_SESSION['school_page_academic_periods'][(string) $schoolId] ?? null;
    if (is_array($selection)) {
        $yearId = filter_var($selection['academic_year_id'] ?? null, FILTER_VALIDATE_INT);
        $termName = $selection['term_name'] ?? null;
        if ($yearId !== false && $yearId > 0 && is_string($termName)
            && in_array($termName, school_standard_term_names(), true)) {
            $stmt = db()->prepare(
                'SELECT y.id AS academic_year_id, y.academic_year,
                        t.id AS term_id, t.term_name, t.starts_on, t.ends_on
                 FROM school_academic_years y
                 JOIN school_terms t
                   ON t.academic_year_id = y.id AND t.school_id = y.school_id
                 WHERE y.id = ? AND y.school_id = ? AND t.term_name = ? LIMIT 1'
            );
            $stmt->execute([$yearId, $schoolId, $termName]);
            $period = $stmt->fetch();
            if ($period) {
                return [
                    'academic_year_id' => (int) $period['academic_year_id'],
                    'academic_year' => (string) $period['academic_year'],
                    'term_name' => (string) $period['term_name'],
                    'term_id' => (int) $period['term_id'],
                    'starts_on' => (string) $period['starts_on'],
                    'ends_on' => (string) $period['ends_on'],
                ];
            }
        }
        unset($_SESSION['school_page_academic_periods'][(string) $schoolId]);
    }

    return school_academic_period_for_date($schoolId);
}

function school_academic_period_for_date(int $schoolId, ?DateTimeImmutable $date = null): ?array
{
    $activeYear = school_active_academic_year($schoolId);
    if ($activeYear === null) {
        return null;
    }
    $date ??= new DateTimeImmutable('today');
    $today = $date->format('Y-m-d');
    $isReadOnly = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST';
    static $cache = [];
    $cacheKey = $schoolId . ':' . (int) $activeYear['id'] . ':' . $today;
    if ($isReadOnly && array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $currentTermStmt = db()->prepare(
        'SELECT t.id, t.school_id, t.academic_year_id, y.academic_year, t.term_name, t.starts_on, t.ends_on
         FROM school_terms t
         JOIN school_academic_years y
           ON y.id = t.academic_year_id AND y.school_id = t.school_id
         WHERE t.school_id = ? AND t.academic_year_id = ?
           AND t.starts_on >= y.starts_on AND t.ends_on <= y.ends_on
           AND t.starts_on <= ? AND t.ends_on >= ?
           AND t.term_name IN ("First Term", "Second Term", "Third Term")
         ORDER BY t.starts_on, t.id LIMIT 1'
    );
    $currentTermStmt->execute([$schoolId, (int) $activeYear['id'], $today, $today]);
    $currentTerm = $currentTermStmt->fetch();
    if ($currentTerm !== false
        && is_array($currentTerm)
        && school_academic_year_dates((string) $currentTerm['starts_on'], (string) $currentTerm['ends_on']) !== null) {
        $result = [
            'school_id' => (int) $currentTerm['school_id'],
            'academic_year_id' => (int) $currentTerm['academic_year_id'],
            'academic_year' => (string) $currentTerm['academic_year'],
            'term_name' => (string) $currentTerm['term_name'],
            'term_id' => (int) $currentTerm['id'],
            'starts_on' => (string) $currentTerm['starts_on'],
            'ends_on' => (string) $currentTerm['ends_on'],
        ];
        if ($isReadOnly) {
            $cache[$cacheKey] = $result;
        }
        return $result;
    }

    $fallbackTermStmt = db()->prepare(
        'SELECT t.id, t.school_id, t.academic_year_id, y.academic_year, t.term_name, t.starts_on, t.ends_on
         FROM school_terms t
         JOIN school_academic_years y
           ON y.id = t.academic_year_id AND y.school_id = t.school_id
         WHERE t.school_id = ? AND t.academic_year_id = ?
           AND t.term_name IN ("First Term", "Second Term", "Third Term")
         ORDER BY t.starts_on, t.id LIMIT 1'
    );
    $fallbackTermStmt->execute([$schoolId, (int) $activeYear['id']]);
    $fallbackTerm = $fallbackTermStmt->fetch();
    if ($fallbackTerm === false || !is_array($fallbackTerm)) {
        if ($isReadOnly) {
            $cache[$cacheKey] = null;
        }
        return null;
    }
    if (school_academic_year_dates((string) $fallbackTerm['starts_on'], (string) $fallbackTerm['ends_on']) === null) {
        if ($isReadOnly) {
            $cache[$cacheKey] = null;
        }
        return null;
    }
    $result = [
        'school_id' => (int) $fallbackTerm['school_id'],
        'academic_year_id' => (int) $fallbackTerm['academic_year_id'],
        'academic_year' => (string) $fallbackTerm['academic_year'],
        'term_name' => (string) $fallbackTerm['term_name'],
        'term_id' => (int) $fallbackTerm['id'],
        'starts_on' => (string) $fallbackTerm['starts_on'],
        'ends_on' => (string) $fallbackTerm['ends_on'],
    ];
    if ($isReadOnly) {
        $cache[$cacheKey] = $result;
    }
    return $result;
}

function school_page_academic_term(int $schoolId): ?array
{
    $period = school_page_academic_period($schoolId);
    if ($period === null) {
        return null;
    }
    return [
        'id' => (int) $period['term_id'],
        'school_id' => $schoolId,
        'academic_year_id' => (int) $period['academic_year_id'],
        'academic_year' => (string) $period['academic_year'],
        'term_name' => (string) $period['term_name'],
        'starts_on' => (string) $period['starts_on'],
        'ends_on' => (string) $period['ends_on'],
    ];
}

function school_academic_years_for_school(int $schoolId, bool $openOnly = false): array
{
    $isReadOnly = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST';
    static $cache = [];
    $cacheKey = $schoolId . ':' . ($openOnly ? '1' : '0');
    if ($isReadOnly && array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }
    $stmt = db()->prepare(
        'SELECT id, school_id, academic_year, starts_on, ends_on, status, is_active
         FROM school_academic_years
         WHERE school_id = ?' . ($openOnly ? ' AND status = "open"' : '') . '
         ORDER BY is_active DESC, academic_year DESC'
    );
    $stmt->execute([$schoolId]);
    $rows = $stmt->fetchAll();
    if ($isReadOnly) {
        $cache[$cacheKey] = $rows;
    }
    return $rows;
}

function school_academic_terms_for_school(int $schoolId): array
{
    $isReadOnly = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST';
    static $cache = [];
    if ($isReadOnly && array_key_exists($schoolId, $cache)) {
        return $cache[$schoolId];
    }
    $stmt = db()->prepare(
        'SELECT t.id AS term_id, t.school_id, t.academic_year_id,
                y.academic_year, t.term_name, t.starts_on, t.ends_on
         FROM school_terms t
         JOIN school_academic_years y
           ON y.id = t.academic_year_id AND y.school_id = t.school_id
         WHERE t.school_id = ?
           AND t.term_name IN ("First Term", "Second Term", "Third Term")
         ORDER BY y.is_active DESC, y.academic_year DESC, t.starts_on, t.id'
    );
    $stmt->execute([$schoolId]);
    $rows = $stmt->fetchAll();
    if ($isReadOnly) {
        $cache[$schoolId] = $rows;
    }
    return $rows;
}

function school_active_academic_year(int $schoolId): ?array
{
    $isReadOnly = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST';
    static $cache = [];
    if ($isReadOnly && array_key_exists($schoolId, $cache)) {
        return $cache[$schoolId];
    }
    $stmt = db()->prepare(
        'SELECT id, school_id, academic_year, starts_on, ends_on, status, is_active
         FROM school_academic_years
         WHERE school_id = ? AND status = "open"
         ORDER BY is_active DESC, ends_on DESC, starts_on DESC, id DESC LIMIT 1'
    );
    $stmt->execute([$schoolId]);
    $year = $stmt->fetch();
    $result = is_array($year) ? $year : null;
    if ($isReadOnly) {
        $cache[$schoolId] = $result;
    }
    return $result;
}

function school_academic_year_dates(string $startsOn, string $endsOn): ?array
{
    $starts = DateTimeImmutable::createFromFormat('!Y-m-d', $startsOn);
    $ends = DateTimeImmutable::createFromFormat('!Y-m-d', $endsOn);
    if (!$starts || !$ends || $starts->format('Y-m-d') !== $startsOn
        || $ends->format('Y-m-d') !== $endsOn || $ends < $starts) {
        return null;
    }
    return [$starts, $ends];
}

function school_academic_year_name_is_valid(string $academicYear): bool
{
    if (preg_match('/^(\d{4})\/(\d{4})$/', $academicYear, $parts) !== 1) {
        return false;
    }
    return (int) $parts[2] === (int) $parts[1] + 1;
}

function school_academic_year_display_label(string $academicYear): string
{
    return school_academic_year_label($academicYear) . ' Academic Year';
}

function school_academic_year_dates_match_label(string $academicYear, string $startsOn, string $endsOn): bool
{
    if (preg_match('/^(\d{4})\/(\d{4})$/', $academicYear, $parts) !== 1) {
        return false;
    }
    $dates = school_academic_year_dates($startsOn, $endsOn);
    return $dates !== null
        && $dates[0]->format('Y') === $parts[1]
        && $dates[1]->format('Y') === $parts[2];
}
