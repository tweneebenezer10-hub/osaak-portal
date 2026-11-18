<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = require_login();
$memberId = (int) $user['id'];
$canMessage = user_has_permission($user, 'messages.use');
$canViewChatPictures = user_has_permission($user, 'directory.view');
$stmt = db()->prepare(
    "SELECT 1 FROM users u JOIN member_profiles mp ON mp.user_id=u.id
     WHERE u.id=? AND u.status='active' AND u.email_verified_at IS NOT NULL
       AND mp.membership_status='active'"
);
$stmt->execute([$memberId]);
if (!$canMessage || !$stmt->fetchColumn()) {
    http_response_code(403);
    render_header('Access denied');
    echo '<section class="panel"><h1>Access denied</h1><p>Private member chat is available to active, verified association members.</p><a class="button" href="dashboard.php">Return to dashboard</a></section>';
    render_footer();
    exit;
}

$stmt = db()->prepare(
    "SELECT c.id, partner.id AS partner_id, partner.full_name AS partner_name,
            CASE WHEN partner_profile.directory_visible=1 THEN partner_profile.profile_picture_path ELSE NULL END AS profile_picture_path,
            (SELECT m.body FROM member_messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) AS last_body,
            (SELECT m.created_at FROM member_messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) AS last_at,
            (SELECT COUNT(*) FROM member_messages m
             WHERE m.conversation_id=c.id AND m.sender_id<>? AND m.read_at IS NULL) AS unread_count
     FROM member_conversations c
     JOIN users partner ON partner.id=CASE WHEN c.member_a=? THEN c.member_b ELSE c.member_a END
     JOIN member_profiles partner_profile ON partner_profile.user_id=partner.id
     WHERE c.member_a=? OR c.member_b=?
     ORDER BY COALESCE(last_at,c.created_at) DESC,c.id DESC
     LIMIT 100"
);
$stmt->execute([$memberId, $memberId, $memberId, $memberId]);
$conversations = $stmt->fetchAll();
$conversationId = filter_var($_GET['conversation'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$selected = null;
$messages = [];
if ($conversationId !== null) {
    $stmt = db()->prepare(
        "SELECT c.id,partner.id AS partner_id,partner.full_name AS partner_name,
                CASE WHEN partner_profile.directory_visible=1 THEN partner_profile.profile_picture_path ELSE NULL END AS profile_picture_path
         FROM member_conversations c
         JOIN users partner ON partner.id=CASE WHEN c.member_a=? THEN c.member_b ELSE c.member_a END
         JOIN member_profiles partner_profile ON partner_profile.user_id=partner.id
         WHERE c.id=? AND (c.member_a=? OR c.member_b=?)"
    );
    $stmt->execute([$memberId, $conversationId, $memberId, $memberId]);
    $selected = $stmt->fetch();
    if (!$selected) {
        http_response_code(404);
        render_header('Conversation unavailable');
        echo '<section class="panel"><h1>Conversation unavailable</h1><p>This private conversation is not available to your account.</p><a class="button" href="messages.php">Return to messages</a></section>';
        render_footer();
        exit;
    }
    $stmt = db()->prepare(
        'SELECT m.id,m.sender_id,m.body,m.created_at,m.delivered_at,m.read_at,u.full_name AS sender_name
         FROM member_messages m JOIN users u ON u.id=m.sender_id
         WHERE m.conversation_id=? ORDER BY m.id DESC LIMIT 50'
    );
    $stmt->execute([$conversationId]);
    $messages = array_reverse($stmt->fetchAll());
    $pendingDeliveryIds = [];
    foreach ($messages as $message) {
        if ((int) $message['sender_id'] !== $memberId && $message['delivered_at'] === null) {
            $pendingDeliveryIds[] = (int) $message['id'];
        }
    }
    if ($pendingDeliveryIds) {
        $placeholders = implode(',', array_fill(0, count($pendingDeliveryIds), '?'));
        $stmt = db()->prepare(
            "UPDATE member_messages SET delivered_at=UTC_TIMESTAMP()
             WHERE conversation_id=? AND sender_id<>? AND delivered_at IS NULL
               AND id IN ({$placeholders})"
        );
        $stmt->execute([$conversationId, $memberId, ...$pendingDeliveryIds]);
        $stmt = db()->prepare(
            'SELECT m.id,m.sender_id,m.body,m.created_at,m.delivered_at,m.read_at,u.full_name AS sender_name
             FROM member_messages m JOIN users u ON u.id=m.sender_id
             WHERE m.conversation_id=? ORDER BY m.id DESC LIMIT 50'
        );
        $stmt->execute([$conversationId]);
        $messages = array_reverse($stmt->fetchAll());
    }
}
$csrf = csrf_token();

render_header('Member messages');
page_title('MEMBER SERVICES', 'Member messages', 'Private conversations between active, verified OSAAK members.');
?>
<section class="member-chat" data-member-chat data-current-user="<?= $memberId ?>" data-csrf="<?= e($csrf) ?>" data-conversation="<?= $conversationId ?? '' ?>" data-last-id="<?= $messages ? (int) $messages[array_key_last($messages)]['id'] : 0 ?>">
    <aside class="chat-sidebar">
        <div class="chat-sidebar-heading"><h2>Messages</h2><span class="chat-unread-total" data-chat-unread aria-live="polite"></span></div>
        <form class="chat-search" data-member-search>
            <label for="member-search">Start a conversation</label>
            <input id="member-search" name="q" type="search" minlength="2" maxlength="80" placeholder="Search members by name" autocomplete="off">
            <div class="chat-search-results" data-search-results aria-live="polite"></div>
            <small class="muted">Never share passwords, one-time codes, or other sign-in secrets.</small>
        </form>
        <nav class="conversation-list" aria-label="Conversations">
            <?php foreach ($conversations as $conversation): ?>
            <a class="conversation-link<?= $conversationId === (int) $conversation['id'] ? ' active' : '' ?>" href="messages.php?conversation=<?= (int) $conversation['id'] ?>">
                <?php if ($canViewChatPictures && $conversation['profile_picture_path']): ?><img class="chat-avatar" src="profile_picture.php?user_id=<?= (int) $conversation['partner_id'] ?>" alt=""><?php else: ?><span class="chat-avatar" aria-hidden="true"><?= e(strtoupper(substr((string) $conversation['partner_name'], 0, 1))) ?></span><?php endif; ?>
                <span class="conversation-summary"><strong><?= e($conversation['partner_name']) ?></strong><span><?= e(substr((string) ($conversation['last_body'] ?? 'Start a conversation'), 0, 72)) ?></span></span>
                <span class="conversation-meta"><?php if ($conversation['last_at']): ?><time><?= e((string) $conversation['last_at']) ?> UTC</time><?php endif; ?><?php if ((int) $conversation['unread_count'] > 0): ?><span class="notification-badge"><?= (int) $conversation['unread_count'] ?></span><?php endif; ?></span>
            </a>
            <?php endforeach; ?>
            <?php if (!$conversations): ?><p class="muted chat-empty-list">No conversations yet. Search for a member to start one.</p><?php endif; ?>
        </nav>
    </aside>
    <div class="chat-panel" data-chat-panel>
        <?php if (!$selected): ?>
            <div class="chat-placeholder"><span class="chat-placeholder-mark" aria-hidden="true">OSAAK</span><h2>Your member conversations</h2><p class="muted">Choose a conversation or search for a member to begin.</p></div>
        <?php else: ?>
            <header class="chat-header"><a class="chat-back" href="messages.php">Back</a><?php if ($canViewChatPictures && $selected['profile_picture_path']): ?><img class="chat-avatar" src="profile_picture.php?user_id=<?= (int) $selected['partner_id'] ?>" alt=""><?php else: ?><span class="chat-avatar" aria-hidden="true"><?= e(strtoupper(substr((string) $selected['partner_name'], 0, 1))) ?></span><?php endif; ?><div><h2><?= e($selected['partner_name']) ?></h2><span class="muted">Private member conversation</span></div></header>
            <div class="chat-history" data-chat-history aria-live="polite">
                <p class="chat-loading" data-chat-feedback role="status">Checking for new messages…</p>
                <?php if (count($messages) >= 50): ?><button class="button button-quiet button-small chat-older" type="button" data-load-older>Load older messages</button><?php endif; ?>
                <?php foreach ($messages as $message): $mine = (int) $message['sender_id'] === $memberId; ?>
                <article class="chat-message<?= $mine ? ' mine' : '' ?>" data-message-id="<?= (int) $message['id'] ?>" data-sender-id="<?= (int) $message['sender_id'] ?>">
                    <strong><?= $mine ? 'You' : e($message['sender_name']) ?></strong>
                    <p><?= nl2br(e($message['body'])) ?></p>
                    <time datetime="<?= e(str_replace(' ', 'T', (string) $message['created_at'])) ?>Z"><?= e((new DateTimeImmutable($message['created_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone((string) ($config['timezone'] ?? 'UTC')))->format('j M Y, H:i')) ?></time>
                    <?php if ($mine):
                        $messageStatus = $message['read_at'] !== null ? 'read' : ($message['delivered_at'] !== null ? 'delivered' : 'sent');
                        $statusLabel = $messageStatus === 'read' ? 'Read' : ($messageStatus === 'delivered' ? 'Delivered' : 'Sent');
                    ?>
                    <span class="message-status message-status-<?= e($messageStatus) ?>" data-message-status="<?= e($messageStatus) ?>" aria-label="<?= e($statusLabel) ?>">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <?php if ($messageStatus === 'sent'): ?><path d="M5 12.5l4 4L19 7" /><?php else: ?><path d="M2 12.5l4 4L16 6.5M9 12.5l4 4L23 6.5" /><?php endif; ?>
                        </svg><span class="visually-hidden"><?= e($statusLabel) ?></span>
                    </span>
                    <?php endif; ?>
                </article>
                <?php endforeach; ?>
            </div>
            <form class="chat-compose" data-message-form>
                <label class="visually-hidden" for="message-body">Message</label>
                <textarea id="message-body" name="body" rows="2" maxlength="4000" placeholder="Write a message" required></textarea>
                <span class="visually-hidden" data-send-feedback role="status" aria-live="polite"></span>
                <button class="button button-primary" type="submit">Send</button>
            </form>
        <?php endif; ?>
    </div>
</section>
<script src="assets/js/messages.js?v=3" defer></script>
<?php render_footer(); ?>
