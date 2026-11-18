<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = require_school_context();
$school = active_school_context();
$schoolId = (int) $school['id'];
require_school_permission($schoolId, 'students.manage');

header('Content-Type: application/json; charset=utf-8');
$admissionDate = trim((string) ($_GET['admission_date'] ?? ''));
$date = DateTimeImmutable::createFromFormat('!Y-m-d', $admissionDate);
if (!$date || $date->format('Y-m-d') !== $admissionDate) {
    http_response_code(422);
    echo json_encode(['error' => 'Enter a valid admission date to preview the number.']);
    exit;
}

try {
    echo json_encode([
        'admission_number' => school_preview_admission_number($schoolId, (int) $date->format('Y')),
    ], JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException | RuntimeException $exception) {
    http_response_code(422);
    echo json_encode(['error' => $exception->getMessage()]);
} catch (Throwable $exception) {
    error_log('Admission-number preview failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'The admission number preview is temporarily unavailable.']);
}
