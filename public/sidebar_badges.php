<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
$user = current_user();
if ($user === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication is required.'], JSON_THROW_ON_ERROR);
    exit;
}

$counts = sidebar_notification_counts($user);
echo json_encode(
    ['counts' => $counts],
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
);
