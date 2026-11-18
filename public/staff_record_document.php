<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = require_school_context();
$school = active_school_context();
if (!$school) {
    http_response_code(404);
    exit('Document not found.');
}
$schoolId = (int) $school['id'];
$userId = (int) $user['id'];
$supportContext = active_it_support_context($user);
$role = school_user_role_for_school($userId, $schoolId);
$isManager = in_array($role, ['school_proprietor', 'school_headmaster'], true);
if ($supportContext !== null) {
    http_response_code(403);
    exit('Staff documents are unavailable during IT support access.');
}
$tableCheck = db()->prepare(
    'SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = ?'
);
$tableCheck->execute(['school_staff_documents']);
if ((int) $tableCheck->fetchColumn() !== 1) {
    http_response_code(503);
    exit('Staff records migration 087 is required before documents can be downloaded.');
}

$documentId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($documentId === false || $documentId < 1) {
    http_response_code(404);
    exit('Document not found.');
}
$documentStmt = db()->prepare(
    'SELECT document.stored_name, document.original_name, document.mime_type, document.file_size
     FROM school_staff_documents document
     JOIN school_staff staff ON staff.id = document.staff_id AND staff.school_id = document.school_id
     WHERE document.id = ? AND document.school_id = ?
       AND (? = 1 OR (
           staff.user_id = ? AND staff.status = "active"
           AND EXISTS (
               SELECT 1 FROM school_memberships membership
               WHERE membership.school_id = staff.school_id
                 AND membership.user_id = staff.user_id AND membership.status = "active"
                 AND membership.role_name NOT IN ("school_parent", "school_student")
           )
       ))
     LIMIT 1'
);
$documentStmt->execute([$documentId, $schoolId, $isManager ? 1 : 0, $userId]);
$document = $documentStmt->fetch();
$validStoredName = is_array($document)
    && preg_match('/^[a-f0-9]{48}\.(?:pdf|jpg|jpeg|png|webp)$/', (string) $document['stored_name']) === 1;
$path = $validStoredName
    ? APP_ROOT . '/storage/staff-records/' . $document['stored_name']
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
