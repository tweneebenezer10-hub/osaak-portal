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
$mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$matches[1]];
if (!serve_stored_image($relativePath, $mime, 'public, max-age=60')) {
    http_response_code(404);
    exit;
}
