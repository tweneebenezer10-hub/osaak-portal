<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = require_login();
$schoolContext = active_school_context();
$schoolId = $schoolContext === null ? 0 : (int) $schoolContext['id'];
$canViewNotifications = user_has_permission($user, 'notifications.view_self') || $schoolId > 0;
if (!$canViewNotifications) {
    http_response_code(403);
    render_header('Access denied');
    echo '<section class="panel"><h1>Access denied</h1><p>Your account cannot access notifications.</p><a class="button" href="dashboard.php">Return to dashboard</a></section>';
    render_footer();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if (in_array($action, [
        'open_school_notification',
        'mark_school_read',
        'mark_school_unread',
        'acknowledge_school_notification',
        'archive_school_notification',
    ], true)) {
        if ($schoolId < 1) {
            http_response_code(403);
            exit('School notifications are not available in this workspace.');
        }
        $notificationId = filter_var($_POST['notification_id'] ?? null, FILTER_VALIDATE_INT);
        if ($notificationId === false || $notificationId < 1) {
            flash('error', 'Choose a valid school notification.');
            redirect('notifications.php');
        }
        $stmt = db()->prepare(
            'SELECT link_url, action_required, acknowledged_at, archived_at
             FROM school_notifications
             WHERE id = ? AND school_id = ? AND recipient_user_id = ? LIMIT 1'
        );
        $stmt->execute([$notificationId, $schoolId, (int) $user['id']]);
        $schoolNotification = $stmt->fetch();
        if (!$schoolNotification) {
            flash('error', 'That school notification was not found.');
            redirect('notifications.php');
        }
        if ($action === 'archive_school_notification') {
            $archiveStmt = db()->prepare(
                'UPDATE school_notifications
                 SET archived_at = COALESCE(archived_at, UTC_TIMESTAMP()),
                     read_at = COALESCE(read_at, UTC_TIMESTAMP())
                 WHERE id = ? AND school_id = ? AND recipient_user_id = ?
                   AND (action_required = 0 OR acknowledged_at IS NOT NULL)'
            );
            $archiveStmt->execute([$notificationId, $schoolId, (int) $user['id']]);
            if ($archiveStmt->rowCount() !== 1) {
                flash('error', 'A pending action must be acknowledged or completed before its notification can be archived.');
                redirect('notifications.php');
            }
            redirect('notifications.php');
        }
        if ($action === 'acknowledge_school_notification') {
            $acknowledgeStmt = db()->prepare(
                'UPDATE school_notifications
                 SET acknowledged_at = COALESCE(acknowledged_at, UTC_TIMESTAMP()),
                     read_at = COALESCE(read_at, UTC_TIMESTAMP())
                 WHERE id = ? AND school_id = ? AND recipient_user_id = ?
                   AND action_required = 1 AND acknowledged_at IS NULL
                   AND archived_at IS NULL'
            );
            $acknowledgeStmt->execute([$notificationId, $schoolId, (int) $user['id']]);
            if ($acknowledgeStmt->rowCount() !== 1) {
                flash('error', 'This notification is not an outstanding action or has already been acknowledged.');
                redirect('notifications.php');
            }
            flash('success', 'Notification acknowledged. The underlying task remains unchanged.');
            redirect('notifications.php');
        }
        if ($action === 'mark_school_unread') {
            $unreadStmt = db()->prepare(
                'UPDATE school_notifications
                 SET read_at = NULL
                 WHERE id = ? AND school_id = ? AND recipient_user_id = ?
                   AND archived_at IS NULL'
            );
            $unreadStmt->execute([$notificationId, $schoolId, (int) $user['id']]);
            if ($unreadStmt->rowCount() !== 1) {
                flash('error', 'That notification could not be marked as unread.');
                redirect('notifications.php');
            }
            flash('success', 'Notification marked as unread.');
            redirect('notifications.php?school_filter=unread');
        }
        $markStmt = db()->prepare(
            'UPDATE school_notifications SET read_at = COALESCE(read_at, UTC_TIMESTAMP())
             WHERE id = ? AND school_id = ? AND recipient_user_id = ? AND archived_at IS NULL'
        );
        $markStmt->execute([$notificationId, $schoolId, (int) $user['id']]);
        redirect('notifications.php?school_notification=' . (int) $notificationId);
    }
    if ($action === 'mark_all_school_read') {
        if ($schoolId < 1) {
            http_response_code(403);
            exit('School notifications are not available in this workspace.');
        }
        db()->prepare(
            'UPDATE school_notifications
             SET read_at = COALESCE(read_at, UTC_TIMESTAMP())
             WHERE school_id = ? AND recipient_user_id = ?
               AND archived_at IS NULL AND read_at IS NULL'
        )->execute([$schoolId, (int) $user['id']]);
        flash('success', 'School notifications marked as read. Outstanding tasks are unchanged.');
        redirect('notifications.php');
    }
    if ($action === 'preferences') {
        $stmt = db()->prepare(
            'INSERT INTO user_notification_preferences
             (user_id, announcements_in_app, events_in_app, dues_in_app)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 announcements_in_app = VALUES(announcements_in_app),
                 events_in_app = VALUES(events_in_app),
                 dues_in_app = VALUES(dues_in_app),
                 updated_at = UTC_TIMESTAMP()'
        );
        $stmt->execute([
            (int) $user['id'],
            isset($_POST['announcements_in_app']) ? 1 : 0,
            isset($_POST['events_in_app']) ? 1 : 0,
            isset($_POST['dues_in_app']) ? 1 : 0,
        ]);
        audit((int) $user['id'], 'notification_preferences_updated');
        flash('success', 'In-app notification preferences saved.');
        redirect('notifications.php');
    }
    if ($action === 'open_association_notification') {
        $notificationId = filter_var($_POST['notification_id'] ?? null, FILTER_VALIDATE_INT);
        if ($notificationId === false || $notificationId < 1) {
            flash('error', 'Choose a valid notification.');
            redirect('notifications.php');
        }
        $stmt = db()->prepare(
            'SELECT id FROM association_notifications
             WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([(int) $notificationId, (int) $user['id']]);
        if (!$stmt->fetchColumn()) {
            flash('error', 'That notification was not found.');
            redirect('notifications.php');
        }
        $markStmt = db()->prepare(
            'UPDATE association_notifications
             SET read_at = COALESCE(read_at, UTC_TIMESTAMP())
             WHERE id = ? AND user_id = ?'
        );
        $markStmt->execute([(int) $notificationId, (int) $user['id']]);
        redirect('notifications.php?association_notification=' . (int) $notificationId);
    }

    if ($action === 'mark_read') {
        $notificationId = filter_var($_POST['notification_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$notificationId) {
            flash('error', 'Choose a valid notification.');
            redirect('notifications.php');
        }
        $stmt = db()->prepare(
            'UPDATE association_notifications
             SET read_at = UTC_TIMESTAMP()
             WHERE id = ? AND user_id = ? AND read_at IS NULL'
        );
        $stmt->execute([(int) $notificationId, (int) $user['id']]);
        if (!$stmt->rowCount()) {
            flash('error', 'That unread notification was not found.');
            redirect('notifications.php');
        }
        redirect('notifications.php');
    }
    if ($action === 'mark_all_read') {
        $stmt = db()->prepare(
            'UPDATE association_notifications
             SET read_at = UTC_TIMESTAMP()
             WHERE user_id = ? AND read_at IS NULL'
        );
        $stmt->execute([(int) $user['id']]);
        flash('success', 'All notifications marked as read.');
        redirect('notifications.php');
    }
    if ($action === 'mark_unread') {
        $notificationId = filter_var($_POST['notification_id'] ?? null, FILTER_VALIDATE_INT);
        if ($notificationId === false || $notificationId < 1) {
            flash('error', 'Choose a valid notification.');
            redirect('notifications.php');
        }
        $stmt = db()->prepare(
            'UPDATE association_notifications
             SET read_at = NULL
             WHERE id = ? AND user_id = ? AND read_at IS NOT NULL'
        );
        $stmt->execute([(int) $notificationId, (int) $user['id']]);
        if ($stmt->rowCount() !== 1) {
            flash('error', 'That notification could not be marked as unread.');
            redirect('notifications.php');
        }
        flash('success', 'Notification marked as unread.');
        redirect('notifications.php?association_filter=unread');
    }
    flash('error', 'Unknown notification action.');
    redirect('notifications.php');
}

$stmt = db()->prepare(
    'SELECT announcements_in_app, events_in_app, dues_in_app
     FROM user_notification_preferences WHERE user_id = ?'
);
$stmt->execute([(int) $user['id']]);
$preferences = $stmt->fetch() ?: [
    'announcements_in_app' => 1,
    'events_in_app' => 1,
    'dues_in_app' => 1,
];
$associationFilter = (string) ($_GET['association_filter'] ?? 'all');
if (!in_array($associationFilter, ['all', 'unread', 'read'], true)) {
    $associationFilter = 'all';
}
$associationWhere = match ($associationFilter) {
    'unread' => ' AND read_at IS NULL',
    'read' => ' AND read_at IS NOT NULL',
    default => '',
};
$stmt = db()->prepare(
    'SELECT id, notification_key, category, title, body, link_url, read_at, created_at
     FROM association_notifications
     WHERE user_id = ?' . $associationWhere . '
     ORDER BY created_at DESC, id DESC
     LIMIT 100'
);
$stmt->execute([(int) $user['id']]);
$notifications = $stmt->fetchAll();
$unreadStmt = db()->prepare(
    'SELECT COUNT(*) FROM association_notifications WHERE user_id = ? AND read_at IS NULL'
);
$unreadStmt->execute([(int) $user['id']]);
$unreadCount = (int) $unreadStmt->fetchColumn();
$associationTotalStmt = db()->prepare(
    'SELECT COUNT(*) FROM association_notifications WHERE user_id = ?' . $associationWhere
);
$associationTotalStmt->execute([(int) $user['id']]);
$associationTotal = (int) $associationTotalStmt->fetchColumn();
$associationDetail = null;
$associationDetailLink = null;
if (isset($_GET['association_notification'])) {
    $detailId = filter_var($_GET['association_notification'], FILTER_VALIDATE_INT);
    if ($detailId === false || $detailId < 1) {
        http_response_code(404);
        render_header('Notification not found');
        echo '<section class="panel"><h1>Notification not found</h1><p>This notification is not available for your account.</p><a class="button" href="notifications.php">Back to Inbox</a></section>';
        render_footer();
        exit;
    }
    $associationDetailStmt = db()->prepare(
        'SELECT id, category, title, body, link_url, read_at, created_at
         FROM association_notifications
         WHERE id = ? AND user_id = ? LIMIT 1'
    );
    $associationDetailStmt->execute([(int) $detailId, (int) $user['id']]);
    $associationDetail = $associationDetailStmt->fetch();
    if (!$associationDetail) {
        http_response_code(404);
        render_header('Notification not found');
        echo '<section class="panel"><h1>Notification not found</h1><p>This notification is not available for your account.</p><a class="button" href="notifications.php">Back to Inbox</a></section>';
        render_footer();
        exit;
    }
    if ($associationDetail['read_at'] === null) {
        db()->prepare(
            'UPDATE association_notifications
             SET read_at = UTC_TIMESTAMP()
             WHERE id = ? AND user_id = ? AND read_at IS NULL'
        )->execute([(int) $detailId, (int) $user['id']]);
        $associationDetail['read_at'] = gmdate('Y-m-d H:i:s');
    }
    $associationLink = (string) ($associationDetail['link_url'] ?? '');
    $associationLinkParts = parse_url($associationLink);
    $associationFragment = is_array($associationLinkParts)
        ? (string) ($associationLinkParts['fragment'] ?? '')
        : '';
    if (is_array($associationLinkParts)
        && !isset($associationLinkParts['scheme'])
        && !isset($associationLinkParts['host'])
        && !isset($associationLinkParts['user'])
        && !isset($associationLinkParts['pass'])
        && preg_match('/\A[A-Za-z0-9_-]+\.php\z/', (string) ($associationLinkParts['path'] ?? '')) === 1
        && preg_match('/\A[A-Za-z0-9_.~%=&+-]*\z/', (string) ($associationLinkParts['query'] ?? '')) === 1
        && ($associationFragment === '' || preg_match('/\A[A-Za-z0-9_-]+\z/', $associationFragment) === 1)) {
        $associationDetailLink = $associationLink;
    }
    render_header('Notification message');
    page_title('MEMBER SERVICES', 'Notification message', 'Read the complete update and choose a safe next step.');
    ?>
    <section class="panel">
        <p><a class="button button-quiet button-small" href="notifications.php?association_filter=<?= e($associationFilter) ?>">← Back to Inbox</a></p>
        <p class="eyebrow"><?= e(ucfirst((string) $associationDetail['category'])) ?> · Association update</p>
        <h2><?= e((string) $associationDetail['title']) ?></h2>
        <p class="muted">From Association · <?= e((new DateTimeImmutable((string) $associationDetail['created_at']))->format('j M Y, g:i a')) ?> UTC</p>
        <div class="notification-message-body"><?= nl2br(e((string) $associationDetail['body'])) ?></div>
        <div class="inline-form top-gap">
            <?php if ($associationDetailLink !== null): ?><a class="button button-primary" href="<?= e($associationDetailLink) ?>">View Details</a><?php endif; ?>
            <?php if ($associationDetail['read_at'] !== null): ?>
                <form method="post">
                    <?= csrf_field() ?><input type="hidden" name="action" value="mark_unread">
                    <input type="hidden" name="notification_id" value="<?= (int) $associationDetail['id'] ?>">
                    <button class="button button-quiet" type="submit">Mark as Unread</button>
                </form>
            <?php endif; ?>
        </div>
    </section>
    <?php
    render_footer();
    exit;
}
$schoolPage = filter_var($_GET['school_page'] ?? 1, FILTER_VALIDATE_INT);
$schoolPage = $schoolPage === false || $schoolPage < 1 ? 1 : $schoolPage;
$schoolNotificationFilter = (string) ($_GET['school_filter'] ?? 'all');
if (!in_array($schoolNotificationFilter, ['all', 'unread', 'read', 'action', 'priority', 'archived'], true)) {
    $schoolNotificationFilter = 'all';
}
$schoolNotificationTotal = $schoolId > 0
    ? school_notification_count($schoolId, (int) $user['id'], $schoolNotificationFilter)
    : 0;
$schoolPageCount = max(1, (int) ceil($schoolNotificationTotal / 50));
$schoolPage = min($schoolPage, $schoolPageCount);
$schoolNotifications = $schoolId > 0
    ? school_notification_recent(
        $schoolId,
        (int) $user['id'],
        50,
        ($schoolPage - 1) * 50,
        $schoolNotificationFilter
    )
    : [];
$schoolUnreadCount = $schoolId > 0
    ? school_notification_unread_count($schoolId, (int) $user['id'])
    : 0;
$schoolNotificationDetail = null;
$schoolNotificationDetailLink = null;
if (isset($_GET['school_notification'])) {
    $detailId = filter_var($_GET['school_notification'], FILTER_VALIDATE_INT);
    if ($schoolId < 1 || $detailId === false || $detailId < 1) {
        http_response_code(404);
        render_header('Notification not found');
        echo '<section class="panel"><h1>Notification not found</h1><p>This notification is not available in the selected school.</p><a class="button" href="notifications.php">Back to Inbox</a></section>';
        render_footer();
        exit;
    }
    $detailStmt = db()->prepare(
        'SELECT notification.id, notification.title, notification.body, notification.link_url,
                notification.category, notification.priority, notification.action_required,
                notification.acknowledged_at, notification.archived_at,
                notification.read_at, notification.created_at,
                sender.full_name AS sender_name, class.level AS class_level,
                class.section AS class_section, subject.name AS subject_name
         FROM school_notifications notification
         LEFT JOIN users sender ON sender.id = notification.sender_user_id
         LEFT JOIN school_classes class ON class.id = notification.class_id AND class.school_id = notification.school_id
         LEFT JOIN school_subjects subject ON subject.id = notification.subject_id AND subject.school_id = notification.school_id
         WHERE notification.id = ? AND notification.school_id = ?
           AND notification.recipient_user_id = ?
         LIMIT 1'
    );
    $detailStmt->execute([(int) $detailId, $schoolId, (int) $user['id']]);
    $schoolNotificationDetail = $detailStmt->fetch();
    if (!$schoolNotificationDetail) {
        http_response_code(404);
        render_header('Notification not found');
        echo '<section class="panel"><h1>Notification not found</h1><p>This notification is not available in the selected school.</p><a class="button" href="notifications.php">Back to Inbox</a></section>';
        render_footer();
        exit;
    }
    if ($schoolNotificationDetail['read_at'] === null && $schoolNotificationDetail['archived_at'] === null) {
        db()->prepare(
            'UPDATE school_notifications
             SET read_at = UTC_TIMESTAMP()
             WHERE id = ? AND school_id = ? AND recipient_user_id = ?
               AND read_at IS NULL AND archived_at IS NULL'
        )->execute([(int) $detailId, $schoolId, (int) $user['id']]);
        $schoolNotificationDetail['read_at'] = gmdate('Y-m-d H:i:s');
    }
    $notificationLink = (string) ($schoolNotificationDetail['link_url'] ?? '');
    $linkParts = parse_url($notificationLink);
    $allowedNotificationRoutes = [
        'attendance.php',
        'assessments.php',
        'report_cards.php',
        'staff_payroll.php',
        'staff_self_service.php',
        'staff_records.php',
        'school_dashboard.php',
        'term_setup.php',
        'students.php',
        'admissions.php',
        'notifications.php',
    ];
    if (is_array($linkParts)
        && !isset($linkParts['scheme'])
        && !isset($linkParts['host'])
        && !isset($linkParts['user'])
        && !isset($linkParts['pass'])
        && in_array((string) ($linkParts['path'] ?? ''), $allowedNotificationRoutes, true)
        && preg_match('/\A[A-Za-z0-9_.~%=&+-]*\z/', (string) ($linkParts['query'] ?? '')) === 1
        && (($linkParts['fragment'] ?? '') === ''
            || preg_match('/\A[A-Za-z0-9_-]+\z/', (string) $linkParts['fragment']) === 1)) {
        $schoolNotificationDetailLink = $notificationLink;
    }
    render_header('Notification message');
    page_title('SCHOOL INBOX', 'Notification message', 'Read the full message and choose a safe next step.');
    ?>
    <section class="panel">
        <p><a class="button button-quiet button-small" href="notifications.php?school_filter=<?= e((string) ($_GET['school_filter'] ?? 'all')) ?>">← Back to Inbox</a></p>
        <p class="eyebrow"><?= e(ucfirst((string) $schoolNotificationDetail['category'])) ?> · <?= e(ucfirst((string) $schoolNotificationDetail['priority'])) ?></p>
        <h2><?= e((string) $schoolNotificationDetail['title']) ?></h2>
        <p class="muted">From <?= e((string) ($schoolNotificationDetail['sender_name'] ?? 'School system')) ?>
            · <?= e((new DateTimeImmutable((string) $schoolNotificationDetail['created_at']))->format('j M Y, g:i a')) ?> UTC</p>
        <?php if ($schoolNotificationDetail['class_level']): ?>
            <p class="muted">Class: <?= e(school_class_label(['level' => $schoolNotificationDetail['class_level'], 'section' => $schoolNotificationDetail['class_section']])) ?></p>
        <?php endif; ?>
        <?php if ($schoolNotificationDetail['subject_name']): ?><p class="muted">Subject: <?= e((string) $schoolNotificationDetail['subject_name']) ?></p><?php endif; ?>
        <div class="notification-message-body"><?= nl2br(e((string) $schoolNotificationDetail['body'])) ?></div>
        <div class="inline-form top-gap">
            <?php if ($schoolNotificationDetailLink !== null): ?><a class="button button-primary" href="<?= e($schoolNotificationDetailLink) ?>">View Details</a><?php endif; ?>
            <?php if ($schoolNotificationDetail['action_required'] && $schoolNotificationDetail['acknowledged_at'] === null): ?>
                <form method="post">
                    <?= csrf_field() ?><input type="hidden" name="action" value="acknowledge_school_notification">
                    <input type="hidden" name="notification_id" value="<?= (int) $schoolNotificationDetail['id'] ?>">
                    <button class="button" type="submit">Acknowledge</button>
                </form>
            <?php endif; ?>
            <?php if ($schoolNotificationDetail['read_at'] !== null): ?>
                <form method="post">
                    <?= csrf_field() ?><input type="hidden" name="action" value="mark_school_unread">
                    <input type="hidden" name="notification_id" value="<?= (int) $schoolNotificationDetail['id'] ?>">
                    <button class="button button-quiet" type="submit">Mark as Unread</button>
                </form>
            <?php endif; ?>
            <?php if ($schoolNotificationDetail['archived_at'] === null
                && (!$schoolNotificationDetail['action_required'] || $schoolNotificationDetail['acknowledged_at'] !== null)): ?>
                <form method="post">
                    <?= csrf_field() ?><input type="hidden" name="action" value="archive_school_notification">
                    <input type="hidden" name="notification_id" value="<?= (int) $schoolNotificationDetail['id'] ?>">
                    <button class="button button-quiet" type="submit" data-confirm="Archive this notification? The school record will not be deleted or changed.">Archive message</button>
                </form>
            <?php endif; ?>
        </div>
    </section>
    <?php
    render_footer();
    exit;
}

render_header('Notifications');
page_title('MEMBER SERVICES', 'Notifications', 'Review association updates sent to your account.');
?>
<section class="panel">
    <div class="panel-heading"><div><h2>Your notifications</h2><p class="muted"><?= $unreadCount ?> unread</p></div>
        <?php if ($unreadCount > 0): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="mark_all_read"><button class="button button-quiet button-small" type="submit">Mark all read</button></form><?php endif; ?>
    </div>
    <nav class="notification-filters" aria-label="Filter association notifications">
        <?php foreach (['all' => 'All', 'unread' => 'Unread', 'read' => 'Read'] as $filterKey => $filterLabel): ?>
            <a class="button <?= $associationFilter === $filterKey ? 'button-primary' : 'button-quiet' ?>"
               href="notifications.php?association_filter=<?= e($filterKey) ?>"
               <?= $associationFilter === $filterKey ? 'aria-current="page"' : '' ?>><?= e($filterLabel) ?></a>
        <?php endforeach; ?>
        <span class="muted"><?= $associationTotal ?> message<?= $associationTotal === 1 ? '' : 's' ?></span>
    </nav>
    <?php if (!$notifications): ?><p class="muted">You have no notifications yet.</p><?php else: ?>
    <ul class="activity-list"><?php foreach ($notifications as $notification): ?>
        <li>
            <strong><?= e($notification['title']) ?><?php if ($notification['read_at'] === null): ?> <span class="status-pill">New</span><?php endif; ?></strong>
            <span><?= e(mb_strlen((string) $notification['body']) > 220
                ? mb_substr((string) $notification['body'], 0, 220) . '…'
                : (string) $notification['body']) ?></span>
            <span><?= e(ucfirst((string) $notification['category'])) ?> · <?= e((string) $notification['created_at']) ?> UTC</span>
            <div class="inline-form">
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="open_association_notification"><input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>"><button class="button button-quiet button-small" type="submit">Read message →</button></form>
                <?php if ($notification['read_at'] === null): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="mark_read"><input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>"><button class="button button-quiet button-small" type="submit">Mark read</button></form><?php endif; ?>
                <?php if ($notification['read_at'] !== null): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="mark_unread"><input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>"><button class="button button-quiet button-small" type="submit">Mark unread</button></form><?php endif; ?>
            </div>
        </li>
    <?php endforeach; ?></ul>
    <?php endif; ?>
</section>
<?php if ($schoolId > 0): ?>
<section class="panel top-gap">
    <div class="panel-heading">
        <div><h2>School notifications</h2><p class="muted"><?= $schoolUnreadCount ?> unread</p></div>
        <?php if ($schoolUnreadCount > 0): ?>
            <form method="post">
                <?= csrf_field() ?><input type="hidden" name="action" value="mark_all_school_read">
                <button class="button button-quiet button-small" type="submit">Mark all read</button>
            </form>
        <?php endif; ?>
    </div>
    <nav class="notification-filters" aria-label="Filter school notifications">
        <?php foreach ([
            'all' => 'All',
            'unread' => 'Unread',
            'read' => 'Read',
            'action' => 'Action required',
            'priority' => 'Critical / High',
            'archived' => 'Archived',
        ] as $filterKey => $filterLabel): ?>
            <a class="button <?= $schoolNotificationFilter === $filterKey ? 'button-primary' : 'button-quiet' ?>"
               href="notifications.php?school_filter=<?= e($filterKey) ?>"
               <?= $schoolNotificationFilter === $filterKey ? 'aria-current="page"' : '' ?>><?= e($filterLabel) ?></a>
        <?php endforeach; ?>
    </nav>
    <?php if ($schoolNotifications === []): ?><p class="muted">No school notifications yet.</p><?php else: ?>
    <ul class="activity-list"><?php foreach ($schoolNotifications as $notification): ?>
        <li class="school-notification school-notification-<?= e((string) $notification['priority']) ?>">
            <strong>
                <?= e((string) $notification['title']) ?>
                <span class="notification-priority notification-priority-<?= e((string) $notification['priority']) ?>"><?= e(ucfirst((string) $notification['priority'])) ?></span>
                <?php if ($notification['action_required'] && $notification['acknowledged_at'] === null): ?><span class="status-pill">Action required</span><?php endif; ?>
                <?php if ($notification['read_at'] === null): ?> <span class="status-pill">Unread</span><?php endif; ?>
            </strong>
            <span><?= e(mb_strlen((string) $notification['body']) > 220
                ? mb_substr((string) $notification['body'], 0, 220) . '…'
                : (string) $notification['body']) ?></span>
            <span><?= e((string) ($notification['sender_name'] ?? 'System')) ?><?php if ($notification['class_level']): ?> · <?= e(school_class_label(['level' => $notification['class_level'], 'section' => $notification['class_section']])) ?><?php endif; ?><?php if ($notification['subject_name']): ?> · <?= e((string) $notification['subject_name']) ?><?php endif; ?> · <?= e((string) $notification['created_at']) ?> UTC</span>
            <div class="inline-form">
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="open_school_notification"><input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>"><button class="button button-quiet button-small" type="submit">Read message →</button></form>
                <?php if ($notification['read_at'] === null): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="mark_school_read"><input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>"><button class="button button-quiet button-small" type="submit">Mark read</button></form><?php endif; ?>
                <?php if ($notification['read_at'] !== null && $notification['archived_at'] === null): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="mark_school_unread"><input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>"><button class="button button-quiet button-small" type="submit">Mark unread</button></form><?php endif; ?>
                <?php if ($notification['action_required'] && $notification['acknowledged_at'] === null): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="acknowledge_school_notification"><input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>"><button class="button button-quiet button-small" type="submit">Acknowledge</button></form><?php endif; ?>
                <?php if ($notification['archived_at'] === null && (!$notification['action_required'] || $notification['acknowledged_at'] !== null)): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="archive_school_notification"><input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>"><button class="button button-quiet button-small" type="submit">Archive</button></form><?php endif; ?>
            </div>
        </li>
    <?php endforeach; ?></ul>
    <?php endif; ?>
    <?php if ($schoolPageCount > 1): ?><nav class="inline-form" aria-label="School notification pages">
        <?php if ($schoolPage > 1): ?><a class="button button-quiet button-small" href="notifications.php?school_filter=<?= e($schoolNotificationFilter) ?>&amp;school_page=<?= $schoolPage - 1 ?>">Newer notifications</a><?php endif; ?>
        <span>Page <?= $schoolPage ?> of <?= $schoolPageCount ?></span>
        <?php if ($schoolPage < $schoolPageCount): ?><a class="button button-quiet button-small" href="notifications.php?school_filter=<?= e($schoolNotificationFilter) ?>&amp;school_page=<?= $schoolPage + 1 ?>">Older notifications</a><?php endif; ?>
    </nav><?php endif; ?>
</section>
<?php endif; ?>
<section class="panel top-gap">
    <h2>In-app preferences</h2>
    <form method="post" class="form-stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="preferences">
        <label class="check-label"><input type="checkbox" name="announcements_in_app" value="1"<?= (bool) $preferences['announcements_in_app'] ? ' checked' : '' ?>> Association announcement notifications</label>
        <label class="check-label"><input type="checkbox" name="events_in_app" value="1"<?= (bool) $preferences['events_in_app'] ? ' checked' : '' ?>> Event notifications</label>
        <label class="check-label"><input type="checkbox" name="dues_in_app" value="1"<?= (bool) $preferences['dues_in_app'] ? ' checked' : '' ?>> Dues notifications</label>
        <p class="muted">These controls affect in-app notices only. Email notifications are sent only by workflows that report their actual mail-delivery result.</p>
        <button class="button button-primary" type="submit">Save preferences</button>
    </form>
</section>
<?php render_footer(); ?>
