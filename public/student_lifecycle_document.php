<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = require_school_context();
$school = active_school_context();
$schoolId = (int) $school['id'];
require_school_permission($schoolId, 'students.lifecycle.manage');

$documentId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($documentId === false || $documentId < 1) {
    http_response_code(404);
    exit('Document not found.');
}
$stmt = db()->prepare(
    'SELECT stored_name, original_name, mime_type, file_size
     FROM student_lifecycle_documents
     WHERE id = ? AND school_id = ? LIMIT 1'
);
$stmt->execute([$documentId, $schoolId]);
$document = $stmt->fetch();
$validStoredName = is_array($document)
    && preg_match('/^[a-f0-9]{48}\.(?:pdf|jpg|jpeg|png|webp)$/', (string) $document['stored_name']) === 1;
$path = $validStoredName
    ? APP_ROOT . '/storage/student-lifecycle/' . $document['stored_name']
    : '';
if (!$document || !$validStoredName || !is_file($path)) {
    http_response_code(404);
    exit('Document not found.');
}

$safeName = str_replace(["\r", "\n", '"'], ['_', '_', "'"], (string) $document['original_name']);
header('Content-Type: ' . $document['mime_type']);
header('Content-Length: ' . (int) $document['file_size']);
header('Content-Disposition: attachment; filename="' . $safeName . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
exit;
