<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = require_school_context();
$school = active_school_context();
$schoolId = (int) $school['id'];
$studentId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$studentId) {
    http_response_code(404);
    exit;
}

$canManageStudents = school_has_permission($user, $schoolId, 'students.manage');
$role = (string) ($user['active_school_role'] ?? school_user_role_for_school((int) $user['id'], $schoolId));
$scopeSql = '';
$params = [$studentId, $schoolId];
if (!$canManageStudents && $role === 'class_teacher') {
    $scopeSql = ' AND EXISTS (
        SELECT 1 FROM teacher_class_assignments tca
        JOIN school_classes sc ON sc.id = tca.class_id AND sc.school_id = tca.school_id
        WHERE tca.school_id = ss.school_id AND tca.class_id = ss.class_id
          AND tca.teacher_user_id = ? AND tca.academic_year = sc.academic_year
    )';
    $params[] = (int) $user['id'];
} elseif (!$canManageStudents && $role === 'subject_teacher') {
    $scopeSql = ' AND EXISTS (
        SELECT 1 FROM class_subjects cs
        WHERE cs.school_id = ss.school_id AND cs.class_id = ss.class_id AND cs.teacher_user_id = ?
    )';
    $params[] = (int) $user['id'];
} elseif (!$canManageStudents) {
    http_response_code(403);
    exit;
}

$stmt = db()->prepare(
    'SELECT photo_path FROM school_students ss
     WHERE ss.id = ? AND ss.school_id = ?
       AND (ss.school_status = "active" OR ? = 1)' . $scopeSql . ' LIMIT 1'
);
$stmt->execute(array_merge([$studentId, $schoolId, $canManageStudents ? 1 : 0], array_slice($params, 2)));
$photoPath = $stmt->fetchColumn();
if (!is_string($photoPath)
    || preg_match('#^student-photos/[a-f0-9]{48}\.(jpg|png|webp)$#', $photoPath, $matches) !== 1) {
    http_response_code(404);
    exit;
}
$imagePath = APP_ROOT . '/storage/' . $photoPath;
$mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$matches[1]];
if (!is_file($imagePath) || !is_readable($imagePath)) {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($imagePath));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($imagePath);
