<?php
/**
 * Vercel PHP Router for CONNEZER EDUTECH SYSTEMS
 *
 * This file is the single serverless entry point for all PHP requests on Vercel.
 * It routes requests to the corresponding script in public/*.php.
 */
declare(strict_types=1);

$uri = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$uri = '/' . ltrim((string) $uri, '/');

$publicDir = dirname(__DIR__) . '/public';

// 1. Direct match: /<script>.php -> public/<script>.php
if (preg_match('#^/([a-zA-Z0-9_\-]+\.php)$#', $uri, $matches)) {
    $target = $publicDir . '/' . $matches[1];
    if (is_file($target)) {
        $_SERVER['SCRIPT_NAME'] = '/' . $matches[1];
        $_SERVER['PHP_SELF'] = '/' . $matches[1];
        require $target;
        exit;
    }
}

// 2. Extensionless match: /<script> -> public/<script>.php
$cleanName = trim($uri, '/');
if ($cleanName !== '' && preg_match('#^[a-zA-Z0-9_\-]+$#', $cleanName)) {
    $target = $publicDir . '/' . $cleanName . '.php';
    if (is_file($target)) {
        $_SERVER['SCRIPT_NAME'] = '/' . $cleanName . '.php';
        $_SERVER['PHP_SELF'] = '/' . $cleanName . '.php';
        require $target;
        exit;
    }
}

// 3. Root request: / -> public/index.php
if ($uri === '/' || $uri === '') {
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['PHP_SELF'] = '/index.php';
    require $publicDir . '/index.php';
    exit;
}

// 4. If request is asking for an asset that fell through to router
$assetPath = $publicDir . $uri;
if (is_file($assetPath)) {
    $ext = pathinfo($assetPath, PATHINFO_EXTENSION);
    $mimeTypes = [
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'webmanifest' => 'application/manifest+json',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'webp' => 'image/webp',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
    ];
    $contentType = $mimeTypes[strtolower($ext)] ?? 'application/octet-stream';
    header('Content-Type: ' . $contentType);
    readfile($assetPath);
    exit;
}

// 5. Route not found
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
exit('Not found.');
