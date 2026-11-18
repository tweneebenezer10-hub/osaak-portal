<?php
declare(strict_types=1);

function create_storage_backup_archive(string $storageRoot, string $temporaryTarPath): array
{
    $realStorageRoot = realpath($storageRoot);
    $realArchiveDirectory = realpath(dirname($temporaryTarPath));
    if ($realStorageRoot === false || !is_dir($realStorageRoot)
        || $realArchiveDirectory === false
        || strtolower(pathinfo($temporaryTarPath, PATHINFO_EXTENSION)) !== 'tar'
        || file_exists($temporaryTarPath)) {
        throw new InvalidArgumentException('A storage directory and unused TAR archive path are required.');
    }

    $excludedDirectories = ['backups', 'logs', 'sessions'];
    $files = [];
    $totalBytes = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($realStorageRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $entry) {
        $relativePath = substr($entry->getPathname(), strlen($realStorageRoot) + 1);
        $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
        $segments = explode('/', $relativePath);
        if (in_array(strtolower($segments[0]), $excludedDirectories, true)) {
            continue;
        }
        if (count($segments) === 1 && $entry->isFile()) {
            continue;
        }
        if ($entry->isLink()) {
            throw new RuntimeException('Storage backup stopped because it encountered a symbolic link.');
        }
        if (!$entry->isFile()) {
            continue;
        }

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..'
                || preg_match('/[\x00-\x1F\x7F]/', $segment) === 1) {
                throw new RuntimeException('Storage backup stopped because it encountered an unsafe file path.');
            }
        }
        $realFilePath = realpath($entry->getPathname());
        if ($realFilePath === false
            || !str_starts_with(
                strtolower($realFilePath),
                strtolower($realStorageRoot . DIRECTORY_SEPARATOR)
            )) {
            throw new RuntimeException('Storage backup stopped because a file resolves outside private storage.');
        }

        $size = filesize($realFilePath);
        $modifiedAt = filemtime($realFilePath);
        if ($size === false || $modifiedAt === false) {
            throw new RuntimeException('Storage backup could not inspect an uploaded file.');
        }
        $files[] = [
            'path' => $realFilePath,
            'relative_path' => $relativePath,
            'bytes' => $size,
            'modified_at' => $modifiedAt,
        ];
        $totalBytes += $size;
    }

    if ($files === []) {
        throw new RuntimeException('No uploaded files were found in private storage to back up.');
    }
    usort($files, static fn(array $left, array $right): int => strcmp($left['relative_path'], $right['relative_path']));

    $archive = null;
    try {
        $archive = new PharData($temporaryTarPath, 0, null, Phar::TAR);
        foreach ($files as $file) {
            $archive->addFile($file['path'], $file['relative_path']);
            clearstatcache(true, $file['path']);
            if (filesize($file['path']) !== $file['bytes'] || filemtime($file['path']) !== $file['modified_at']) {
                throw new RuntimeException('A stored file changed during backup; retry after uploads finish.');
            }
        }
        chmod($temporaryTarPath, 0600);
        unset($archive);
        $archive = null;

        $compressedArchive = (new PharData($temporaryTarPath))->compress(Phar::GZ);
        unset($compressedArchive);
        $compressedPath = $temporaryTarPath . '.gz';
        if (!is_file($compressedPath)) {
            throw new RuntimeException('PHP could not create the compressed uploaded-file archive.');
        }
        chmod($compressedPath, 0600);
        if (!unlink($temporaryTarPath)) {
            throw new RuntimeException('Could not remove the temporary uncompressed uploaded-file archive.');
        }

        $compressedBytes = filesize($compressedPath);
        $checksum = hash_file('sha256', $compressedPath);
        if ($compressedBytes === false || $compressedBytes < 1 || $checksum === false) {
            throw new RuntimeException('The uploaded-file archive could not be verified.');
        }

        $reader = gzopen($compressedPath, 'rb');
        if ($reader === false) {
            throw new RuntimeException('The uploaded-file archive could not be reopened for verification.');
        }
        $uncompressedBytes = 0;
        try {
            while (!gzeof($reader)) {
                $chunk = gzread($reader, 65536);
                if ($chunk === false) {
                    throw new RuntimeException('The uploaded-file archive failed its read check.');
                }
                $uncompressedBytes += strlen($chunk);
            }
            if (!gzclose($reader)) {
                $reader = false;
                throw new RuntimeException('The uploaded-file archive failed its integrity check.');
            }
            $reader = false;
        } finally {
            if (is_resource($reader)) {
                gzclose($reader);
            }
        }

        return [
            'path' => $compressedPath,
            'file_count' => count($files),
            'source_bytes' => $totalBytes,
            'archive_bytes' => $uncompressedBytes,
            'compressed_bytes' => $compressedBytes,
            'sha256' => $checksum,
        ];
    } catch (Throwable $exception) {
        unset($archive);
        foreach ([$temporaryTarPath, $temporaryTarPath . '.gz'] as $partialPath) {
            if (is_file($partialPath)) {
                unlink($partialPath);
            }
        }
        throw $exception;
    }
}
