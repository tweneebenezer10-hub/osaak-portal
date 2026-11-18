<?php
declare(strict_types=1);

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

    $relativePath = $directory . '/' . bin2hex(random_bytes(24)) . '.' . $extensions[$mime];
    $destination = APP_ROOT . '/storage/' . $relativePath;
    $destinationDirectory = dirname($destination);
    if (!is_dir($destinationDirectory) && !mkdir($destinationDirectory, 0750, true) && !is_dir($destinationDirectory)) {
        throw new RuntimeException('The private image storage directory could not be created.');
    }
    if (!move_uploaded_file($temporaryPath, $destination)) {
        throw new RuntimeException('The uploaded image could not be saved.');
    }
    return ['path' => $relativePath, 'mime' => $mime];
}

function remove_stored_image(?string $relativePath, string $directory): void
{
    if ($relativePath === null || preg_match('#^' . preg_quote($directory, '#') . '/[a-f0-9]{48}\.(?:jpg|png|webp)$#', $relativePath) !== 1) {
        return;
    }
    $path = APP_ROOT . '/storage/' . $relativePath;
    if (is_file($path) && !unlink($path)) {
        error_log('Secure Auth could not remove a replaced private image file.');
    }
}
