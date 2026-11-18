<?php
declare(strict_types=1);

function store_support_attachment(array $upload): ?array
{
    $uploadError = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
        throw new InvalidArgumentException('The attachment exceeds the server upload size limit.');
    }
    if ($uploadError !== UPLOAD_ERR_OK) {
        error_log('Secure Auth support attachment upload failed with PHP upload error ' . $uploadError . '.');
        throw new RuntimeException('The attachment upload failed. Check the server upload settings and try again.');
    }

    $temporaryPath = (string) ($upload['tmp_name'] ?? '');
    if (!is_uploaded_file($temporaryPath)) {
        throw new InvalidArgumentException('Choose a valid attachment to upload.');
    }
    $fileSize = filesize($temporaryPath);
    if ($fileSize === false || $fileSize < 1 || $fileSize > 5 * 1024 * 1024) {
        throw new InvalidArgumentException('Attachments must be between 1 byte and 5 MB.');
    }

    $extension = strtolower(pathinfo((string) ($upload['name'] ?? ''), PATHINFO_EXTENSION));
    $allowedMimeTypes = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
        'txt' => ['text/plain'],
        'csv' => ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'],
    ];
    if (!isset($allowedMimeTypes[$extension])) {
        throw new InvalidArgumentException('Attachments may be PDF, JPG, PNG, WebP, TXT, or CSV files.');
    }
    if (!class_exists(finfo::class)) {
        throw new RuntimeException('The PHP Fileinfo extension is required to validate support attachments.');
    }
    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $fileInfo->file($temporaryPath);
    if (!is_string($mimeType) || !in_array($mimeType, $allowedMimeTypes[$extension], true)) {
        throw new InvalidArgumentException('The attachment contents do not match an allowed file type.');
    }

    $originalName = basename(str_replace('\\', '/', (string) ($upload['name'] ?? '')));
    $originalName = trim((string) preg_replace('/[\x00-\x1F\x7F]/', '_', $originalName));
    if ($originalName === '') {
        $originalName = 'attachment.' . $extension;
    }
    if (strlen($originalName) > 255) {
        throw new InvalidArgumentException('The attachment file name must be 255 characters or fewer.');
    }

    $directory = APP_ROOT . '/storage/support';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('The private support attachment directory could not be created.');
    }
    $storedName = bin2hex(random_bytes(24)) . '.' . $extension;
    $path = $directory . DIRECTORY_SEPARATOR . $storedName;
    if (!move_uploaded_file($temporaryPath, $path)) {
        throw new RuntimeException('The support attachment could not be stored.');
    }

    return [
        'stored_name' => $storedName,
        'original_name' => $originalName,
        'mime_type' => $mimeType,
        'file_size' => $fileSize,
        'path' => $path,
    ];
}

function remove_support_attachment_file(?array $attachment): void
{
    if (!$attachment || !isset($attachment['stored_name'], $attachment['path'])
        || preg_match('/^[a-f0-9]{48}\.(?:pdf|jpg|jpeg|png|webp|txt|csv)$/', (string) $attachment['stored_name']) !== 1) {
        return;
    }
    if (is_file($attachment['path']) && !unlink($attachment['path'])) {
        error_log('Secure Auth could not remove an uncommitted support attachment.');
    }
}

function save_support_attachment(int $replyId, int $userId, array $attachment): void
{
    $stmt = db()->prepare(
        'INSERT INTO support_ticket_attachments
         (reply_id, uploaded_by, stored_name, original_name, mime_type, file_size)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $replyId,
        $userId,
        $attachment['stored_name'],
        $attachment['original_name'],
        $attachment['mime_type'],
        $attachment['file_size'],
    ]);
}
