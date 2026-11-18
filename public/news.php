<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/image_uploads.php';
$user = require_login();
$canManage = user_has_permission($user, 'news.manage');
if (!$canManage && !user_has_permission($user, 'news.view')) {
    http_response_code(403);
    render_header('Access denied');
    echo '<section class="panel"><h1>Access denied</h1><p>Your account cannot view association news.</p><a class="button" href="dashboard.php">Return to dashboard</a></section>';
    render_footer();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (!$canManage) {
        http_response_code(403);
        exit('Only an authorized association editor can change news articles.');
    }
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'save_news') {
            $newsId = filter_var($_POST['news_id'] ?? null, FILTER_VALIDATE_INT);
            $headline = trim((string) ($_POST['headline'] ?? ''));
            $body = trim((string) ($_POST['body'] ?? ''));
            $status = (string) ($_POST['status'] ?? 'draft');
            $eventInput = trim((string) ($_POST['related_event_id'] ?? ''));
            $eventId = $eventInput === '' ? null : filter_var($eventInput, FILTER_VALIDATE_INT);
            $publishInput = trim((string) ($_POST['publish_at'] ?? ''));
            $publishAt = null;
            if ($publishInput !== '') {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $publishInput, new DateTimeZone('UTC'));
                $dateErrors = DateTimeImmutable::getLastErrors();
                if (!$date || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
                    throw new InvalidArgumentException('Enter a valid publication date and time in UTC.');
                }
                $publishAt = $date->format('Y-m-d H:i:s');
            }
            if (preg_match('/^.{2,180}$/us', $headline) !== 1 || strlen($body) < 10 || strlen($body) > 50000
                || !in_array($status, ['draft', 'published', 'archived'], true)
                || ($eventInput !== '' && (!$eventId || $eventId < 1))) {
                throw new InvalidArgumentException('Check the headline, article text, optional event, and publication status.');
            }
            if ($status === 'published' && $publishAt === null) {
                $publishAt = gmdate('Y-m-d H:i:s');
            }
            if ($eventId) {
                $stmt = db()->prepare('SELECT id FROM association_events WHERE id=?');
                $stmt->execute([(int) $eventId]);
                if (!$stmt->fetchColumn()) {
                    throw new InvalidArgumentException('Choose an existing association event or leave the event link empty.');
                }
            }

            $oldPath = null;
            if ($newsId) {
                $stmt = db()->prepare('SELECT photograph_path FROM association_news WHERE id=?');
                $stmt->execute([(int) $newsId]);
                $oldPath = $stmt->fetchColumn();
                if ($oldPath === false) {
                    throw new InvalidArgumentException('That news article no longer exists.');
                }
            }
            $newPhoto = null;
            $upload = $_FILES['photograph'] ?? ['error' => UPLOAD_ERR_NO_FILE];
            if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $newPhoto = store_uploaded_image($upload, 'association-news', 4194304);
            }
            $photoPath = $newPhoto['path'] ?? $oldPath;
            try {
                db()->beginTransaction();
                if ($newsId) {
                    $stmt = db()->prepare(
                        'UPDATE association_news
                         SET headline=?,body=?,photograph_path=?,related_event_id=?,status=?,publish_at=?,updated_by=?
                         WHERE id=?'
                    );
                    $stmt->execute([
                        $headline,
                        $body,
                        $photoPath,
                        $eventId ?: null,
                        $status,
                        $publishAt,
                        (int) $user['id'],
                        (int) $newsId,
                    ]);
                    $savedId = (int) $newsId;
                } else {
                    $stmt = db()->prepare(
                        'INSERT INTO association_news
                         (headline,body,photograph_path,related_event_id,status,publish_at,created_by,updated_by)
                         VALUES (?,?,?,?,?,?,?,?)'
                    );
                    $stmt->execute([
                        $headline,
                        $body,
                        $photoPath,
                        $eventId ?: null,
                        $status,
                        $publishAt,
                        (int) $user['id'],
                        (int) $user['id'],
                    ]);
                    $savedId = (int) db()->lastInsertId();
                }
                audit((int) $user['id'], $newsId ? 'association_news_updated' : 'association_news_created', 'news_id=' . $savedId . '; status=' . $status);
                db()->commit();
            } catch (Throwable $exception) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                if ($newPhoto !== null) {
                    remove_stored_image($newPhoto['path'], 'association-news');
                }
                throw $exception;
            }
            if ($newPhoto !== null && is_string($oldPath)) {
                remove_stored_image($oldPath, 'association-news');
            }
            flash('success', 'Association news article saved.');
        } elseif ($action === 'remove_news_photo') {
            $newsId = filter_var($_POST['news_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$newsId) {
                throw new InvalidArgumentException('Choose a valid article.');
            }
            db()->beginTransaction();
            $stmt = db()->prepare('SELECT photograph_path FROM association_news WHERE id=? FOR UPDATE');
            $stmt->execute([(int) $newsId]);
            $oldPath = $stmt->fetchColumn();
            if ($oldPath === false) {
                throw new InvalidArgumentException('That news article no longer exists.');
            }
            $stmt = db()->prepare('UPDATE association_news SET photograph_path=NULL,updated_by=? WHERE id=?');
            $stmt->execute([(int) $user['id'], (int) $newsId]);
            audit((int) $user['id'], 'association_news_photo_removed', 'news_id=' . (int) $newsId);
            db()->commit();
            remove_stored_image(is_string($oldPath) ? $oldPath : null, 'association-news');
            flash('success', 'Article photograph removed.');
        } else {
            throw new InvalidArgumentException('Choose a valid news action.');
        }
    } catch (InvalidArgumentException $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        flash('error', $exception->getMessage());
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $exception;
    }
    redirect('news.php');
}

$events = $canManage ? db()->query(
    "SELECT id,title FROM association_events WHERE status IN ('published','draft') ORDER BY starts_at DESC LIMIT 200"
)->fetchAll() : [];
$where = $canManage
    ? '1=1'
    : "n.status='published' AND (n.publish_at IS NULL OR n.publish_at<=UTC_TIMESTAMP())";
$newsRows = db()->query(
    'SELECT n.*,e.title AS event_title,u.full_name AS author
     FROM association_news n
     LEFT JOIN association_events e ON e.id=n.related_event_id
     JOIN users u ON u.id=n.created_by
     WHERE ' . $where . '
     ORDER BY COALESCE(n.publish_at,n.created_at) DESC,n.id DESC LIMIT 200'
)->fetchAll();
mark_sidebar_content_viewed(
    (int) $user['id'],
    'association_news',
    array_map(
        static fn(array $article): array => [
            'id' => (int) $article['id'],
            'updated_at' => (string) $article['updated_at'],
        ],
        array_values(array_filter(
            $newsRows,
            static fn(array $article): bool => $article['status'] === 'published'
                && ($article['publish_at'] === null
                    || strtotime((string) $article['publish_at'] . ' UTC') <= time())
        ))
    )
);

render_header('Association news');
page_title('ASSOCIATION NEWS', 'Association news', 'Updates, achievements, reunions, and developments from the alumni association.');
?>
<?php if ($canManage): ?>
<section class="panel module-form-panel"><h2>Write an article</h2><form method="post" enctype="multipart/form-data" class="module-form"><?= csrf_field() ?><input type="hidden" name="action" value="save_news">
    <label>Headline<input name="headline" maxlength="180" required></label>
    <label>Related event<select name="related_event_id"><option value="">Not linked to an event</option><?php foreach ($events as $event): ?><option value="<?= (int) $event['id'] ?>"><?= e($event['title']) ?></option><?php endforeach; ?></select></label>
    <label>Publication date and time (UTC)<input name="publish_at" type="datetime-local"></label>
    <label>Publication status<select name="status"><option value="draft">Draft</option><option value="published">Publish now or at scheduled time</option></select></label>
    <label class="span-all">Article<textarea name="body" rows="8" maxlength="50000" required></textarea></label>
    <label class="span-all">Photograph<input name="photograph" type="file" accept="image/jpeg,image/png,image/webp"><small class="muted">Optional JPEG, PNG, or WebP image; maximum 4 MB.</small></label>
    <button class="button button-primary" type="submit">Save article</button>
</form></section>
<?php endif; ?>
<section class="association-card-grid">
<?php foreach ($newsRows as $article): ?>
<article class="panel association-card">
    <div class="association-card-heading"><h2><?= e($article['headline']) ?></h2><span class="status-pill"><?= e(ucfirst($article['status'])) ?></span></div>
    <?php if ($article['photograph_path']): ?><img class="news-photograph" src="news-photo.php?id=<?= (int) $article['id'] ?>" alt="<?= e($article['headline']) ?>"><?php endif; ?>
    <p class="muted">By <?= e($article['author']) ?> · <?= e($article['publish_at'] ?: $article['created_at']) ?> UTC<?= $article['event_title'] ? ' · Related event: ' . e($article['event_title']) : '' ?></p>
    <div class="module-body"><?= nl2br(e($article['body'])) ?></div>
    <?php if ($canManage): ?>
    <details class="top-gap"><summary>Edit article</summary><form method="post" enctype="multipart/form-data" class="module-form top-gap"><?= csrf_field() ?><input type="hidden" name="action" value="save_news"><input type="hidden" name="news_id" value="<?= (int) $article['id'] ?>">
        <label>Headline<input name="headline" maxlength="180" value="<?= e($article['headline']) ?>" required></label>
        <label>Related event<select name="related_event_id"><option value="">Not linked to an event</option><?php foreach ($events as $event): ?><option value="<?= (int) $event['id'] ?>" <?= (int) $article['related_event_id'] === (int) $event['id'] ? 'selected' : '' ?>><?= e($event['title']) ?></option><?php endforeach; ?></select></label>
        <label>Publication date and time (UTC)<input name="publish_at" type="datetime-local" value="<?= e($article['publish_at'] ? gmdate('Y-m-d\TH:i', strtotime($article['publish_at'])) : '') ?>"></label>
        <label>Publication status<select name="status"><?php foreach (['draft'=>'Draft','published'=>'Published','archived'=>'Archived'] as $value=>$label): ?><option value="<?= e($value) ?>" <?= $article['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
        <label class="span-all">Article<textarea name="body" rows="6" maxlength="50000" required><?= e($article['body']) ?></textarea></label>
        <label class="span-all">Replace photograph<input name="photograph" type="file" accept="image/jpeg,image/png,image/webp"></label>
        <button class="button button-small" type="submit">Save changes</button>
    </form>
    <?php if ($article['photograph_path']): ?><form method="post" class="top-gap"><?= csrf_field() ?><input type="hidden" name="action" value="remove_news_photo"><input type="hidden" name="news_id" value="<?= (int) $article['id'] ?>"><button class="button button-quiet button-small" type="submit">Remove photograph</button></form><?php endif; ?></details>
    <?php endif; ?>
</article>
<?php endforeach; ?>
<?php if (!$newsRows): ?><article class="panel"><p class="muted">No association news has been published.</p></article><?php endif; ?>
</section>
<?php render_footer(); ?>
