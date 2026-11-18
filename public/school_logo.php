<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = current_user();
$requestedSchoolId = filter_var($_GET['school_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($user === null) {
    if ($requestedSchoolId === false || $requestedSchoolId === null) {
        http_response_code(404);
        exit;
    }
    $schoolId = (int) $requestedSchoolId;
    $publicSchoolStmt = db()->prepare(
        "SELECT logo_path FROM schools
         WHERE id = ? AND deleted_at IS NULL AND status = 'active'
           AND subscription_status IN ('trial', 'active')
           AND (subscription_expires_at IS NULL OR subscription_expires_at > UTC_TIMESTAMP())
         LIMIT 1"
    );
    $publicSchoolStmt->execute([$schoolId]);
    $relativePath = $publicSchoolStmt->fetchColumn();
    if (!is_string($relativePath)) {
        http_response_code(404);
        exit;
    }
} else {
    if (platform_super_admin_user($user) && $requestedSchoolId !== false && $requestedSchoolId !== null) {
        $schoolId = (int) $requestedSchoolId;
    } elseif ($requestedSchoolId !== false && $requestedSchoolId !== null) {
        $membershipStmt = db()->prepare(
            'SELECT 1 FROM school_memberships
             WHERE user_id = ? AND school_id = ? AND status IN ("active", "pending")
               AND EXISTS (SELECT 1 FROM schools WHERE schools.id = school_memberships.school_id AND schools.deleted_at IS NULL)
             LIMIT 1'
        );
        $membershipStmt->execute([(int) $user['id'], (int) $requestedSchoolId]);
        if (!$membershipStmt->fetchColumn()) {
            http_response_code(404);
            exit;
        }
        $schoolId = (int) $requestedSchoolId;
    } else {
        $school = active_school_context();
        $schoolId = $school === null ? 0 : (int) $school['id'];
        if ($schoolId < 1 || ($requestedSchoolId !== false && $requestedSchoolId !== null
            && (int) $requestedSchoolId !== $schoolId)) {
            http_response_code(404);
            exit;
        }
    }

    $stmt = db()->prepare('SELECT logo_path FROM schools WHERE id = ? LIMIT 1');
    $stmt->execute([$schoolId]);
    $relativePath = $stmt->fetchColumn();
}
if (!is_string($relativePath)
    || preg_match('#^school-logos/[a-f0-9]{48}\.(jpg|png|webp)$#', $relativePath, $matches) !== 1) {
    http_response_code(404);
    exit;
}

$imagePath = APP_ROOT . '/storage/' . $relativePath;
if (!is_file($imagePath) || !is_readable($imagePath)) {
    http_response_code(404);
    exit;
}

$mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$matches[1]];
$reportCardVariant = (string) ($_GET['variant'] ?? '') === 'report_card';
if ($reportCardVariant && $matches[1] === 'jpg') {
    $transparentRelativePath = substr($relativePath, 0, -4) . '.png';
    $transparentImagePath = APP_ROOT . '/storage/' . $transparentRelativePath;
    if (is_file($transparentImagePath) && is_readable($transparentImagePath)) {
        $imagePath = $transparentImagePath;
        $mime = 'image/png';
    }
}
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($imagePath));
header('Cache-Control: private, max-age=60');
header('X-Content-Type-Options: nosniff');
readfile($imagePath);
