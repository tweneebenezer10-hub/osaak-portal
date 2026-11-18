<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$applicationRoot = dirname(__DIR__);
require_once $applicationRoot . '/app/storage_backup.php';
$environment = strtolower((string) getenv('APP_ENV')) === 'production';
$configPath = $applicationRoot . '/config/' . ($environment ? 'render.php' : 'local.php');
if (!is_file($configPath)) {
    fwrite(STDERR, 'Database backup configuration is missing: ' . $configPath . PHP_EOL);
    exit(1);
}

$config = require $configPath;
$database = is_array($config) ? ($config['db'] ?? null) : null;
if (!is_array($database)
    || trim((string) ($database['host'] ?? '')) === ''
    || trim((string) ($database['name'] ?? '')) === ''
    || trim((string) ($database['user'] ?? '')) === ''
    || !array_key_exists('password', $database)
    || preg_match('/[\r\n\x00]/', (string) $database['host']) === 1
    || preg_match('/[\r\n\x00]/', (string) $database['name']) === 1
    || preg_match('/[\r\n\x00]/', (string) $database['user']) === 1
    || preg_match('/[\r\n\x00]/', (string) $database['password']) === 1
    || preg_match('/[\r\n\x00]/', (string) ($database['charset'] ?? 'utf8mb4')) === 1
    || preg_match('/[\r\n\x00]/', (string) ($database['ssl_ca'] ?? '')) === 1) {
    fwrite(STDERR, 'Database backup settings are incomplete or contain invalid line breaks.' . PHP_EOL);
    exit(1);
}

$port = trim((string) ($database['port'] ?? ''));
if ($port !== '' && filter_var($port, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 65535],
]) === false) {
    fwrite(STDERR, 'The configured database port is invalid.' . PHP_EOL);
    exit(1);
}

$directory = $applicationRoot . '/storage/backups';
if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
    fwrite(STDERR, 'Could not create the private database backup directory.' . PHP_EOL);
    exit(1);
}
$realDirectory = realpath($directory);
$publicDirectory = realpath($applicationRoot . '/public');
if ($realDirectory === false || $publicDirectory === false
    || str_starts_with(strtolower($realDirectory . DIRECTORY_SEPARATOR), strtolower($publicDirectory . DIRECTORY_SEPARATOR))) {
    fwrite(STDERR, 'Database backups must be stored outside the public web directory.' . PHP_EOL);
    exit(1);
}

$lock = fopen($directory . '/.database-backup.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    if (is_resource($lock)) {
        fclose($lock);
    }
    fwrite(STDERR, 'Another database backup is already running.' . PHP_EOL);
    exit(1);
}

$optionFile = null;
$stderrFile = null;
$temporaryGzip = null;
$temporaryManifest = null;
$temporaryUploadTar = null;
$temporaryUploadGzip = null;
$temporaryUploadManifest = null;
$publishedPaths = [];
$process = null;
$pipes = [];
$gzip = null;
$gzipClosed = true;
$status = 1;
$errorMessage = null;

try {
    $dumpBinary = trim((string) getenv('MYSQLDUMP_BINARY'));
    if ($dumpBinary === '') {
        $dumpBinary = 'mysqldump';
    }

    $optionFile = tempnam($directory, '.database-client-');
    $stderrFile = tempnam($directory, '.database-backup-errors-');
    $temporaryGzip = tempnam($directory, '.database-backup-');
    if ($optionFile === false || $stderrFile === false || $temporaryGzip === false) {
        throw new RuntimeException('Could not create private temporary backup files.');
    }
    chmod($optionFile, 0600);
    chmod($stderrFile, 0600);
    chmod($temporaryGzip, 0600);

    $quoteOption = static function (string $value): string {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    };
    $clientOptions = [
        'host' => (string) $database['host'],
        'user' => (string) $database['user'],
        'password' => (string) $database['password'],
        'default-character-set' => (string) ($database['charset'] ?? 'utf8mb4'),
    ];
    if ($port !== '') {
        $clientOptions['port'] = $port;
    }
    if (trim((string) ($database['ssl_ca'] ?? '')) !== '') {
        $sslCa = (string) $database['ssl_ca'];
        if (!is_file($sslCa) || !is_readable($sslCa)) {
            throw new RuntimeException('The configured database TLS CA file is not readable.');
        }
        $clientOptions['ssl-ca'] = $sslCa;
        $clientOptions['ssl-verify-server-cert'] = 'true';
    }
    $optionLines = ["[client]"];
    foreach ($clientOptions as $key => $value) {
        $optionLines[] = $key . '=' . $quoteOption($value);
    }
    if (file_put_contents($optionFile, implode(PHP_EOL, $optionLines) . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Could not write private database client settings.');
    }

    $timestamp = gmdate('Ymd\THis\Z');
    $suffix = bin2hex(random_bytes(8));
    $baseName = preg_replace('/[^A-Za-z0-9_.-]/', '_', (string) $database['name']);
    $backupPath = $directory . DIRECTORY_SEPARATOR . $baseName . '-' . $timestamp . '-' . $suffix . '.sql.gz';
    $manifestPath = $backupPath . '.json';
    $temporaryManifest = $directory . DIRECTORY_SEPARATOR . '.database-manifest-' . $suffix . '.tmp';
    $uploadBackupPath = $directory . DIRECTORY_SEPARATOR . $baseName . '-uploads-' . $timestamp . '-' . $suffix . '.tar.gz';
    $uploadManifestPath = $uploadBackupPath . '.json';
    $temporaryUploadTar = $directory . DIRECTORY_SEPARATOR . '.uploads-' . $suffix . '.tar';
    $temporaryUploadGzip = $temporaryUploadTar . '.gz';
    $temporaryUploadManifest = $directory . DIRECTORY_SEPARATOR . '.uploads-manifest-' . $suffix . '.tmp';

    $gzip = gzopen($temporaryGzip, 'wb6');
    if ($gzip === false) {
        throw new RuntimeException('Could not create the compressed database backup.');
    }
    $gzipClosed = false;

    $command = [
        $dumpBinary,
        '--defaults-file=' . $optionFile,
        '--single-transaction',
        '--quick',
        '--routines',
        '--events',
        '--triggers',
        '--hex-blob',
        '--default-character-set=' . (string) ($database['charset'] ?? 'utf8mb4'),
        '--databases',
        (string) $database['name'],
    ];
    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', $stderrFile, 'wb'],
        ],
        $pipes,
        $applicationRoot,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start mysqldump. Confirm it is installed and available on PATH or set MYSQLDUMP_BINARY.');
    }
    fclose($pipes[0]);
    while (!feof($pipes[1])) {
        $chunk = fread($pipes[1], 65536);
        if ($chunk === false) {
            throw new RuntimeException('Could not read the database dump output.');
        }
        if ($chunk === '') {
            continue;
        }
        $offset = 0;
        while ($offset < strlen($chunk)) {
            $written = gzwrite($gzip, substr($chunk, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Could not compress the database dump.');
            }
            $offset += $written;
        }
    }
    fclose($pipes[1]);
    unset($pipes[1]);
    $status = proc_close($process);
    $process = null;

    if (!gzclose($gzip)) {
        $gzipClosed = true;
        throw new RuntimeException('Could not finalize the compressed database dump.');
    }
    $gzipClosed = true;
    $gzip = null;
    if ($status !== 0) {
        $diagnostics = is_file($stderrFile) ? trim((string) file_get_contents($stderrFile)) : '';
        throw new RuntimeException('mysqldump failed with exit code ' . $status
            . ($diagnostics !== '' ? ': ' . substr($diagnostics, 0, 1000) : '.'));
    }

    $compressedSize = filesize($temporaryGzip);
    $checksum = hash_file('sha256', $temporaryGzip);
    if ($compressedSize === false || $compressedSize < 1 || $checksum === false) {
        throw new RuntimeException('The generated backup file is empty or could not be verified.');
    }

    $reader = gzopen($temporaryGzip, 'rb');
    if ($reader === false) {
        throw new RuntimeException('The generated backup could not be reopened for verification.');
    }
    $uncompressedSize = 0;
    try {
        while (!gzeof($reader)) {
            $chunk = gzread($reader, 65536);
            if ($chunk === false) {
                throw new RuntimeException('The compressed database backup failed its read check.');
            }
            $uncompressedSize += strlen($chunk);
        }
        if (!gzclose($reader)) {
            $reader = false;
            throw new RuntimeException('The compressed database backup failed its integrity check.');
        }
        $reader = false;
    } finally {
        if (is_resource($reader)) {
            gzclose($reader);
        }
    }
    if ($uncompressedSize < 1) {
        throw new RuntimeException('The database dump contains no data.');
    }

    $uploadBackup = create_storage_backup_archive($applicationRoot . '/storage', $temporaryUploadTar);
    $manifest = [
        'database' => (string) $database['name'],
        'created_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'backup_file' => basename($backupPath),
        'format' => 'mysqldump SQL compressed with gzip',
        'compressed_bytes' => $compressedSize,
        'uncompressed_bytes' => $uncompressedSize,
        'sha256' => $checksum,
    ];
    $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($temporaryManifest, $manifestJson . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Could not write the backup manifest.');
    }
    chmod($temporaryManifest, 0600);

    $uploadManifest = [
        'created_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'archive_file' => basename($uploadBackupPath),
        'format' => 'private storage TAR compressed with gzip',
        'files' => $uploadBackup['file_count'],
        'source_bytes' => $uploadBackup['source_bytes'],
        'archive_bytes' => $uploadBackup['archive_bytes'],
        'compressed_bytes' => $uploadBackup['compressed_bytes'],
        'sha256' => $uploadBackup['sha256'],
        'excluded_directories' => ['backups', 'logs', 'sessions'],
    ];
    $uploadManifestJson = json_encode($uploadManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($temporaryUploadManifest, $uploadManifestJson . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Could not write the uploaded-file backup manifest.');
    }
    chmod($temporaryUploadManifest, 0600);

    if (!rename($temporaryGzip, $backupPath)) {
        throw new RuntimeException('Could not move the verified backup into its final location.');
    }
    $temporaryGzip = null;
    $publishedPaths[] = $backupPath;
    if (!rename($uploadBackup['path'], $uploadBackupPath)) {
        throw new RuntimeException('Could not publish the uploaded-file archive.');
    }
    $temporaryUploadGzip = null;
    $publishedPaths[] = $uploadBackupPath;
    if (!rename($temporaryManifest, $manifestPath)) {
        throw new RuntimeException('Could not publish the database backup manifest.');
    }
    $temporaryManifest = null;
    $publishedPaths[] = $manifestPath;
    if (!rename($temporaryUploadManifest, $uploadManifestPath)) {
        throw new RuntimeException('Could not publish the uploaded-file backup manifest.');
    }
    $temporaryUploadManifest = null;
    $publishedPaths[] = $uploadManifestPath;

    fwrite(STDOUT, 'Database backup created and gzip/checksum verified: ' . $backupPath . PHP_EOL);
    fwrite(STDOUT, 'Compressed: ' . $compressedSize . ' bytes; uncompressed: ' . $uncompressedSize . ' bytes; SHA-256: ' . $checksum . PHP_EOL);
    fwrite(STDOUT, 'Uploaded-file backup created and verified: ' . $uploadBackupPath . PHP_EOL);
    fwrite(STDOUT, 'Archived files: ' . $uploadBackup['file_count'] . '; source bytes: ' . $uploadBackup['source_bytes'] . '; SHA-256: ' . $uploadBackup['sha256'] . PHP_EOL);
    fwrite(STDOUT, 'Integrity checks are not restore tests.' . PHP_EOL);
    $status = 0;
} catch (Throwable $exception) {
    $errorMessage = $exception->getMessage();
} finally {
    if (is_resource($pipes[1] ?? null)) {
        fclose($pipes[1]);
    }
    if (is_resource($pipes[0] ?? null)) {
        fclose($pipes[0]);
    }
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    if (is_resource($gzip) && !$gzipClosed) {
        gzclose($gzip);
    }
    foreach ([$optionFile, $stderrFile, $temporaryGzip, $temporaryManifest, $temporaryUploadTar, $temporaryUploadGzip, $temporaryUploadManifest] as $temporaryPath) {
        if (is_string($temporaryPath) && is_file($temporaryPath)) {
            unlink($temporaryPath);
        }
    }
    if ($errorMessage !== null) {
        foreach ($publishedPaths as $publishedPath) {
            if (is_file($publishedPath)) {
                unlink($publishedPath);
            }
        }
    }
    flock($lock, LOCK_UN);
    fclose($lock);
}

if ($errorMessage !== null) {
    fwrite(STDERR, 'Database backup failed: ' . $errorMessage . PHP_EOL);
    exit(1);
}

exit($status);
