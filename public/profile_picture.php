<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$viewer = require_login();
$targetId = filter_var($_GET['user_id'] ?? (string) $viewer['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$targetId) {
    http_response_code(404);
    exit;
}

$stmt = db()->prepare(
    "SELECT COALESCE(u.profile_picture_path,mp.profile_picture_path) AS profile_picture_path,
            mp.directory_visible,mp.membership_status,
            COALESCE(mt.visible,0) AS mentorship_visible,
            COALESCE(mt.offering_mentorship,0) AS offering_mentorship,
            COALESCE(mt.seeking_mentorship,0) AS seeking_mentorship,
            u.status,u.email_verified_at
     FROM users u LEFT JOIN member_profiles mp ON mp.user_id=u.id
     LEFT JOIN mentorship_profiles mt ON mt.user_id=mp.user_id
     WHERE u.id=?"
);
$stmt->execute([(int) $targetId]);
$profile = $stmt->fetch();
$canView = $profile && (
    (int) $targetId === (int) $viewer['id']
    || (user_has_permission($viewer, 'directory.view') && $profile['directory_visible']
        && $profile['membership_status'] === 'active' && $profile['status'] === 'active'
        && $profile['email_verified_at'] !== null)
    || (user_has_permission($viewer, 'mentorship.view') && $profile['mentorship_visible']
        && ($profile['offering_mentorship'] || $profile['seeking_mentorship'])
        && $profile['membership_status'] === 'active' && $profile['status'] === 'active'
        && $profile['email_verified_at'] !== null)
);
if (!$canView || !$profile['profile_picture_path']) {
    http_response_code(404);
    exit;
}

$relativePath = (string) $profile['profile_picture_path'];
if (preg_match('#^profile-pictures/[a-f0-9]{48}\.(jpg|png|webp)$#', $relativePath, $matches) !== 1) {
    http_response_code(404);
    exit;
}
$imagePath = APP_ROOT . '/storage/' . $relativePath;
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
