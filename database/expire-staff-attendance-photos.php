<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/app/bootstrap.php';

$storageRoot = realpath(APP_ROOT . '/storage/staff-attendance');
$expiredStmt = db()->query(
    'SELECT id, stored_name, mime_type FROM school_staff_attendance_photos
     WHERE expires_at <= NOW() ORDER BY expires_at'
);
$extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
$removePhoto = db()->prepare(
    'DELETE FROM school_staff_attendance_photos WHERE id = ? AND expires_at <= NOW()'
);
$removed = 0;
$failed = 0;
foreach ($expiredStmt->fetchAll() as $photo) {
    $name = (string) $photo['stored_name'];
    $extension = $extensions[(string) $photo['mime_type']] ?? null;
    if ($extension === null || preg_match('/^[a-f0-9]{48}$/', $name) !== 1) {
        error_log('Expired staff attendance photo has an invalid stored reference; record retained.');
        $failed++;
        continue;
    }
    if ($storageRoot === false) {
        error_log('Expired staff attendance photos remain queued because private photo storage is unavailable.');
        $failed++;
        continue;
    }
    $path = realpath($storageRoot . DIRECTORY_SEPARATOR . $name . '.' . $extension);
    if ($path !== false && !str_starts_with($path, $storageRoot . DIRECTORY_SEPARATOR)) {
        error_log('Expired staff attendance photo path escaped private storage; record retained.');
        $failed++;
        continue;
    }
    if ($path !== false && (!is_file($path) || !unlink($path))) {
        error_log('Unable to remove an expired staff attendance photograph; record retained.');
        $failed++;
        continue;
    }
    $removePhoto->execute([(int) $photo['id']]);
    $removed += $removePhoto->rowCount();
}
fwrite(STDOUT, 'Expired staff attendance photos removed: ' . $removed . '; failures: ' . $failed . PHP_EOL);
