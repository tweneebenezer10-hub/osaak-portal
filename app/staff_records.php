<?php
declare(strict_types=1);

function store_school_staff_document(array $upload): ?array
{
    $uploadError = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
        throw new InvalidArgumentException('The staff document exceeds the server upload size limit.');
    }
    if ($uploadError !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The staff document upload failed. Check the server upload settings and try again.');
    }

    $temporaryPath = (string) ($upload['tmp_name'] ?? '');
    if (!is_uploaded_file($temporaryPath)) {
        throw new InvalidArgumentException('Choose a valid staff document to upload.');
    }
    $fileSize = filesize($temporaryPath);
    if ($fileSize === false || $fileSize < 1 || $fileSize > 10 * 1024 * 1024) {
        throw new InvalidArgumentException('Staff documents must be between 1 byte and 10 MB.');
    }

    $extension = strtolower(pathinfo((string) ($upload['name'] ?? ''), PATHINFO_EXTENSION));
    $allowedMimeTypes = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
    ];
    if (!isset($allowedMimeTypes[$extension])) {
        throw new InvalidArgumentException('Staff documents must be PDF, JPG, PNG, or WebP files.');
    }
    if (!class_exists(finfo::class)) {
        throw new RuntimeException('The PHP Fileinfo extension is required to validate staff documents.');
    }
    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $fileInfo->file($temporaryPath);
    if (!is_string($mimeType) || !in_array($mimeType, $allowedMimeTypes[$extension], true)) {
        throw new InvalidArgumentException('The document contents do not match an allowed file type.');
    }

    $originalName = basename(str_replace('\\', '/', (string) ($upload['name'] ?? '')));
    $originalName = trim((string) preg_replace('/[\x00-\x1F\x7F]/', '_', $originalName));
    if ($originalName === '' || strlen($originalName) > 255) {
        throw new InvalidArgumentException('The document file name must be between 1 and 255 characters.');
    }

    $directory = APP_ROOT . '/storage/staff-records';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('The private staff document directory could not be created.');
    }
    $storedName = bin2hex(random_bytes(24)) . '.' . $extension;
    $path = $directory . DIRECTORY_SEPARATOR . $storedName;
    if (!move_uploaded_file($temporaryPath, $path)) {
        throw new RuntimeException('The staff document could not be stored.');
    }

    return [
        'stored_name' => $storedName,
        'original_name' => $originalName,
        'mime_type' => $mimeType,
        'file_size' => $fileSize,
        'path' => $path,
    ];
}

function remove_uncommitted_school_staff_document(?array $document): void
{
    if (!$document || !isset($document['stored_name'], $document['path'])
        || preg_match('/^[a-f0-9]{48}\.(?:pdf|jpg|jpeg|png|webp)$/', (string) $document['stored_name']) !== 1) {
        return;
    }
    if (is_file($document['path']) && !unlink($document['path'])) {
        error_log('The application could not remove an uncommitted staff document.');
    }
}
