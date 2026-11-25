<?php
declare(strict_types=1);

function ensure_media_table(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    db()->exec(
        'CREATE TABLE IF NOT EXISTS app_media_files (
            relative_path VARCHAR(255) NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            file_size INT UNSIGNED NOT NULL,
            content MEDIUMBLOB NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (relative_path)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $ready = true;
}

function save_stored_media(string $relativePath, string $mime, string $bytes): void
{
    ensure_media_table();
    $stmt = db()->prepare(
        'INSERT INTO app_media_files (relative_path, mime_type, file_size, content)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            mime_type = VALUES(mime_type),
            file_size = VALUES(file_size),
            content = VALUES(content),
            updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([$relativePath, $mime, strlen($bytes), $bytes]);

    // Also mirror to local disk when writable (e.g. local XAMPP)
    $destination = APP_ROOT . '/storage/' . $relativePath;
    $destinationDirectory = dirname($destination);
    if ((is_dir($destinationDirectory) || @mkdir($destinationDirectory, 0750, true))
        && is_writable($destinationDirectory)) {
        @file_put_contents($destination, $bytes);
    }
}

function find_stored_media(string $relativePath): ?array
{
    $imagePath = APP_ROOT . '/storage/' . $relativePath;
    if (is_file($imagePath) && is_readable($imagePath)) {
        $bytes = file_get_contents($imagePath);
        if (is_string($bytes)) {
            return ['bytes' => $bytes, 'mime' => null];
        }
    }
    try {
        $stmt = db()->prepare('SELECT mime_type, content FROM app_media_files WHERE relative_path = ? LIMIT 1');
        $stmt->execute([$relativePath]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row) && isset($row['content']) && is_string($row['content'])) {
            return ['bytes' => $row['content'], 'mime' => (string) ($row['mime_type'] ?? '')];
        }
    } catch (Throwable $ignore) {}
    return null;
}

function serve_stored_image(string $relativePath, string $fallbackMime, string $cacheControl = 'private, no-store'): bool
{
    $media = find_stored_media($relativePath);
    if ($media === null) {
        return false;
    }
    $mime = $media['mime'] !== null && $media['mime'] !== '' ? $media['mime'] : $fallbackMime;
    $bytes = $media['bytes'];
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) strlen($bytes));
    header('Cache-Control: ' . $cacheControl);
    header('X-Content-Type-Options: nosniff');
    echo $bytes;
    return true;
}

function store_uploaded_image(array $upload, string $directory, int $maxBytes): array
{
    $uploadError = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError === UPLOAD_ERR_NO_FILE) {
        throw new InvalidArgumentException('Choose an image file to upload.');
    }
    if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
        throw new InvalidArgumentException('The image exceeds the server upload size limit.');
    }
    if ($uploadError !== UPLOAD_ERR_OK) {
        error_log('Secure Auth image upload failed with PHP upload error ' . $uploadError . '.');
        throw new RuntimeException('The image upload failed. Check the server upload settings and try again.');
    }
    $temporaryPath = (string) ($upload['tmp_name'] ?? '');
    $fileSize = (int) ($upload['size'] ?? 0);
    if (!is_uploaded_file($temporaryPath) || $fileSize < 1 || $fileSize > $maxBytes) {
        throw new InvalidArgumentException('The image must be smaller than ' . (int) floor($maxBytes / 1048576) . ' MB.');
    }
    $image = getimagesize($temporaryPath);
    $mime = is_array($image) ? (string) ($image['mime'] ?? '') : '';
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime]) || (int) $image[0] < 1 || (int) $image[1] < 1
        || (int) $image[0] > 5000 || (int) $image[1] > 5000
        || (int) $image[0] * (int) $image[1] > 20000000) {
        throw new InvalidArgumentException('Upload a valid JPEG, PNG, or WebP image no larger than 5000 by 5000 pixels.');
    }

    $bytes = file_get_contents($temporaryPath);
    if (!is_string($bytes) || $bytes === '') {
        throw new RuntimeException('The uploaded image could not be read.');
    }
    $relativePath = $directory . '/' . bin2hex(random_bytes(24)) . '.' . $extensions[$mime];
    save_stored_media($relativePath, $mime, $bytes);
    return ['path' => $relativePath, 'mime' => $mime];
}

function remove_stored_image(?string $relativePath, string $directory): void
{
    if ($relativePath === null || preg_match('#^' . preg_quote($directory, '#') . '/[a-f0-9]{48}\.(?:jpg|png|webp)$#', $relativePath) !== 1) {
        return;
    }
    try {
        $stmt = db()->prepare('DELETE FROM app_media_files WHERE relative_path = ?');
        $stmt->execute([$relativePath]);
    } catch (Throwable $ignore) {}
    $path = APP_ROOT . '/storage/' . $relativePath;
    if (is_file($path) && !@unlink($path)) {
        error_log('Secure Auth could not remove a replaced private image file.');
    }
}
