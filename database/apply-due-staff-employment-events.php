<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (!school_staff_employment_schema_ready()) {
    throw new RuntimeException('Apply migration 107_staff_employment_history.sql before processing staff employment events.');
}

$schools = db()->query(
    'SELECT id FROM schools WHERE deleted_at IS NULL ORDER BY id'
)->fetchAll(PDO::FETCH_COLUMN);
$processed = 0;
foreach ($schools as $schoolId) {
    $processed += school_staff_employment_process_due((int) $schoolId, true);
}

fwrite(STDOUT, 'Applied ' . $processed . " due staff employment event(s).\n");
