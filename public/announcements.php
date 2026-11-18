<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = require_school_context();
$school = active_school_context();
$schoolId = (int) $school['id'];
require_school_permission($schoolId, 'announcements.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'publish_announcement') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));
        $audience = in_array((string) ($_POST['audience'] ?? ''), ['all', 'staff', 'parents', 'students', 'teachers'], true)
            ? (string) $_POST['audience']
            : 'all';
        if ($title === '' || $body === '') {
            flash('error', 'Both title and body are required for an announcement.');
            redirect('announcements.php');
        }
        $stmt = db()->prepare(
            'INSERT INTO school_announcements (school_id, author_user_id, title, body, audience, published_at) VALUES (?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$schoolId, (int) $user['id'], $title, $body, $audience]);
        $announcementId = (int) db()->lastInsertId();
        if (in_array($audience, ['all', 'students'], true)) {
            $studentUsersStmt = db()->prepare(
                'SELECT link.user_id
                 FROM school_student_account_links link
                 JOIN school_memberships membership
                   ON membership.user_id = link.user_id
                  AND membership.school_id = link.school_id
                  AND membership.status = "active"
                 WHERE link.school_id = ? AND link.status = "active"'
            );
            $studentUsersStmt->execute([$schoolId]);
            foreach ($studentUsersStmt->fetchAll(PDO::FETCH_COLUMN) as $studentUserId) {
                create_user_notification(
                    (int) $studentUserId,
                    'school-announcement-' . $announcementId,
                    'announcement',
                    'New school announcement',
                    $title,
                    'student_dashboard.php'
                );
            }
        }
        flash('success', 'Announcement published successfully.');
        redirect('announcements.php');
    }
}

$announcementStmt = db()->prepare(
    'SELECT sa.id, sa.title, sa.body, sa.audience, sa.published_at, u.full_name AS author_name
     FROM school_announcements sa
     JOIN users u ON u.id = sa.author_user_id
     WHERE sa.school_id = ? ORDER BY sa.published_at DESC, sa.id DESC'
);
$announcementStmt->execute([$schoolId]);
$announcements = $announcementStmt->fetchAll();

render_header('Announcements');
?>
<section class="panel">
    <p class="eyebrow">COMMUNICATION</p>
    <h1>Announcements</h1>
</section>

<section class="report-action-grid" aria-label="Announcement actions">
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('announcement') ?></span>
        <div><h2>Publish an Announcement</h2><p>Write and publish an announcement to an authorized school audience.</p></div>
        <a class="button button-primary" href="#publish-announcement" data-action-open="publish-announcement">Create Announcement</a>
    </article>
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('announcements') ?></span>
        <div><h2>Recent Announcements</h2><p>Review announcements already published for this school.</p></div>
        <a class="button" href="#recent-announcements" data-action-open="recent-announcements">View Announcements</a>
    </article>
</section>

<section class="panel panel-top-gap" id="publish-announcement" data-action-view="publish-announcement">
    <h2>Publish announcement</h2>
    <form method="post" action="announcements.php" class="form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="publish_announcement">
        <div class="form-grid two-columns">
            <div class="span-full"><label for="title">Title</label><input id="title" name="title" type="text" maxlength="180" required></div>
            <div class="span-full"><label for="body">Message</label><textarea id="body" name="body" rows="6" required></textarea></div>
            <div class="span-full">
                <label for="audience">Audience</label>
                <select id="audience" name="audience">
                    <option value="all">All</option>
                    <option value="staff">Staff</option>
                    <option value="parents">Parents</option>
                    <option value="students">Students</option>
                    <option value="teachers">Teachers</option>
                </select>
            </div>
        </div>
        <button class="button button-primary" type="submit">Publish</button>
    </form>
</section>

<section class="panel panel-top-gap" id="recent-announcements" data-action-view="recent-announcements">
    <h2>Recent announcements</h2>
    <?php if ($announcements === []): ?>
        <p class="muted">No announcements have been published for this school yet.</p>
    <?php else: ?>
        <div class="form-stack">
            <?php foreach ($announcements as $announcement): ?>
                <article class="panel panel-light">
                    <p class="eyebrow"><?= e((string) $announcement['audience']) ?> · <?= e((string) $announcement['published_at']) ?></p>
                    <h3><?= e((string) $announcement['title']) ?></h3>
                    <p class="muted">By <?= e((string) $announcement['author_name']) ?></p>
                    <?php render_dashboard_read_more((string) $announcement['body']); ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
