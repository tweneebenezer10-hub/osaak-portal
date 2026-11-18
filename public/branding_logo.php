<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$stmt = db()->prepare("SELECT setting_value FROM association_settings WHERE setting_key='site_logo_path' LIMIT 1");
$stmt->execute();
$relativePath = (string) $stmt->fetchColumn();
if (preg_match('#^branding/[a-f0-9]{48}\.(jpg|png|webp)$#', $relativePath, $matches) !== 1) {
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
header('Cache-Control: public, max-age=60');
header('X-Content-Type-Options: nosniff');
readfile($imagePath);
