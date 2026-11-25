<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = require_school_context();
$school = active_school_context();
$schoolId = (int) ($school['id'] ?? 0);
if ($schoolId < 1 || !school_has_permission($user, $schoolId, 'students.lifecycle.manage')) {
    http_response_code(404);
    exit;
}
$certificateId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($certificateId === false || $certificateId < 1) {
    http_response_code(404);
    exit;
}
$stmt = db()->prepare(
    'SELECT school_logo_path FROM school_completion_certificates
     WHERE id = ? AND school_id = ? AND status = "issued" LIMIT 1'
);
$stmt->execute([$certificateId, $schoolId]);
$relativePath = $stmt->fetchColumn();
if (!is_string($relativePath)
    || preg_match('#^school-logos/[a-f0-9]{48}\.(jpg|png|webp)$#', $relativePath, $matches) !== 1) {
    http_response_code(404);
    exit;
}
$mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$matches[1]];
if (!serve_stored_image($relativePath, $mime, 'private, no-store')) {
    http_response_code(404);
    exit;
}
