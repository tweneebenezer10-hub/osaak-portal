<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = require_login();
$jobId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$jobId || !(user_has_permission($user, 'jobs.view') || user_has_permission($user, 'jobs.manage') || user_has_permission($user, 'jobs.submit'))) {
    http_response_code(404);
    exit;
}
$stmt = db()->prepare(
    'SELECT id,created_by,status,review_status,closes_at,attachment_path
     FROM association_jobs WHERE id=? LIMIT 1'
);
$stmt->execute([(int) $jobId]);
$job = $stmt->fetch();
$isReviewer = user_has_permission($user, 'jobs.review') || user_has_permission($user, 'jobs.manage');
$isOwner = $job && (int) $job['created_by'] === (int) $user['id'];
$isActive = $job && $job['status'] === 'published'
    && $job['review_status'] === 'approved'
    && ($job['closes_at'] === null || strtotime($job['closes_at'] . ' UTC') > time());
if (!$job || !$job['attachment_path'] || (!$isReviewer && !$isOwner && !$isActive)) {
    http_response_code(404);
    exit;
}
$relativePath = (string) $job['attachment_path'];
if (preg_match('#^job-advertisements/[a-f0-9]{48}\.(pdf|jpg|png|webp)$#', $relativePath, $matches) !== 1) {
    http_response_code(404);
    exit;
}
$filePath = APP_ROOT . '/storage/' . $relativePath;
if (!is_file($filePath) || !is_readable($filePath)) {
    http_response_code(404);
    exit;
}
$mimeTypes = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
header('Content-Type: ' . $mimeTypes[$matches[1]]);
header('Content-Length: ' . (string) filesize($filePath));
header('Content-Disposition: attachment; filename="job-advertisement-' . (int) $job['id'] . '.' . $matches[1] . '"');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($filePath);
