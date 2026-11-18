<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
$user = require_login();
$newsId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$newsId) {
    http_response_code(404);
    exit;
}
$stmt = db()->prepare(
    "SELECT photograph_path,status,publish_at FROM association_news WHERE id=?"
);
$stmt->execute([(int) $newsId]);
$article = $stmt->fetch();
$canManage = user_has_permission($user, 'news.manage');
$isPublished = $article && $article['status'] === 'published'
    && ($article['publish_at'] === null || $article['publish_at'] <= gmdate('Y-m-d H:i:s'));
if (!$article || (!$canManage && (!$isPublished || !user_has_permission($user, 'news.view')))) {
    http_response_code(404);
    exit;
}
$relativePath = (string) ($article['photograph_path'] ?? '');
if (preg_match('#^association-news/[a-f0-9]{48}\.(jpg|png|webp)$#', $relativePath, $matches) !== 1) {
    http_response_code(404);
    exit;
}
$imagePath = APP_ROOT . '/storage/' . $relativePath;
$mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$matches[1]];
if (!is_file($imagePath) || !is_readable($imagePath)) {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($imagePath));
header('Cache-Control: private, max-age=60');
header('X-Content-Type-Options: nosniff');
readfile($imagePath);
