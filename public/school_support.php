<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/school_support_schema.php';

$user = require_school_context();
$supportSchema = school_support_schema();
$supportReadTrackingAvailable = school_support_has_column($supportSchema['tickets'], 'read_at')
    && school_support_has_column($supportSchema['tickets'], 'replied_at')
    && school_support_has_column($supportSchema['messages'], 'read_at')
    && school_support_has_column($supportSchema['messages'], 'read_by_user_id');
$supportReplyIdempotencyAvailable = school_support_has_column($supportSchema['messages'], 'request_key');
$school = active_school_context();
$schoolId = (int) $school['id'];
$canManageSupport = school_has_permission($user, $schoolId, 'support.manage');
$supportContact = platform_super_admin_contact_details();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_ticket') {
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));
        $category = (string) ($_POST['category'] ?? '');
        $priority = (string) ($_POST['priority'] ?? '');
        if ($subject === '' || strlen($subject) > 180 || strlen($body) < 10 || strlen($body) > 10000
            || !in_array($category, ['academic_calendar', 'fees', 'access', 'technical', 'other'], true)
            || !in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
            flash('error', 'Enter a subject (up to 180 characters), a description of 10–10,000 characters, and valid category and priority values.');
            redirect('school_support.php');
        }

        $stmt = db()->prepare(
            'INSERT INTO school_support_tickets (school_id, user_id, subject, body, category, status, priority)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$schoolId, (int) $user['id'], $subject, $body, $category, 'open', $priority]);
        $ticketId = (int) db()->lastInsertId();
        $managers = db()->query(
            "SELECT DISTINCT u.id
             FROM user_roles ur
             JOIN roles r ON r.id = ur.role_id AND r.name = 'platform_super_admin'
             JOIN users u ON u.id = ur.user_id AND u.status = 'active'
               AND u.email_verified_at IS NOT NULL"
        );
        foreach ($managers->fetchAll(PDO::FETCH_COLUMN) as $managerId) {
            try {
                create_user_notification(
                    (int) $managerId,
                    'school-support-ticket-' . $ticketId,
                    'support',
                    'New school IT support request',
                    (string) $school['name'] . ' submitted: ' . $subject,
                    'platform_admin.php?support_ticket_id=' . $ticketId . '#school-support-requests'
                );
            } catch (Throwable $exception) {
                error_log('School IT support notification failed for ticket ' . $ticketId . ': ' . $exception->getMessage());
            }
        }
        flash('success', 'Your support request was saved and routed to CONNEZER IT Support.');
        redirect('school_support.php');
    }

    if ($action === 'reply_ticket') {
        $ticketId = filter_var($_POST['ticket_id'] ?? null, FILTER_VALIDATE_INT);
        $message = trim((string) ($_POST['message'] ?? ''));
        $replyKey = strtolower(trim((string) ($_POST['reply_key'] ?? '')));
        if ($ticketId === false || $ticketId < 1 || $message === '' || strlen($message) > 10000
            || preg_match('/\A[a-f0-9]{32}\z/', $replyKey) !== 1) {
            flash('error', 'Choose a valid ticket and compose a reply.');
            redirect('school_support.php');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $ticketCheck = $pdo->prepare(
                'SELECT id FROM school_support_tickets
             WHERE id = ? AND school_id = ?' . ($canManageSupport ? '' : ' AND user_id = ?') . '
             LIMIT 1 FOR UPDATE'
            );
            $ticketCheck->execute($canManageSupport
                ? [$ticketId, $schoolId]
                : [$ticketId, $schoolId, (int) $user['id']]);
            if (!$ticketCheck->fetchColumn()) {
                throw new RuntimeException('The selected support ticket is not valid for this school.');
            }

            if ($supportReplyIdempotencyAvailable) {
                $replyStmt = $pdo->prepare(
                    'INSERT INTO school_support_messages (ticket_id, sender_user_id, message, request_key)
                     VALUES (?, ?, ?, ?)'
                );
                $replyStmt->execute([$ticketId, (int) $user['id'], $message, $replyKey]);
            } else {
                $replyStmt = $pdo->prepare(
                    'INSERT INTO school_support_messages (ticket_id, sender_user_id, message)
                     VALUES (?, ?, ?)'
                );
                $replyStmt->execute([$ticketId, (int) $user['id'], $message]);
            }

            if ($supportReadTrackingAvailable) {
                $updateStmt = $pdo->prepare(
                    'UPDATE school_support_tickets
                     SET status = ?, updated_at = NOW(), read_at = NULL, read_by_user_id = NULL,
                         replied_at = NULL
                     WHERE id = ? AND school_id = ?'
                );
            } else {
                $updateStmt = $pdo->prepare(
                    'UPDATE school_support_tickets
                     SET status = ?, updated_at = NOW()
                     WHERE id = ? AND school_id = ?'
                );
            }
            $updateStmt->execute(['open', $ticketId, $schoolId]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($supportReplyIdempotencyAvailable
                && $exception instanceof PDOException
                && ($exception->errorInfo[1] ?? null) === 1062) {
                $duplicateStmt = db()->prepare(
                    'SELECT id FROM school_support_messages
                     WHERE ticket_id = ? AND sender_user_id = ? AND request_key = ? LIMIT 1'
                );
                $duplicateStmt->execute([$ticketId, (int) $user['id'], $replyKey]);
                if ($duplicateStmt->fetchColumn()) {
                    flash('success', 'This support reply was already received.');
                    redirect('school_support.php?ticket_id=' . $ticketId);
                }
            }
            error_log('School support reply failed: ' . $exception->getMessage());
            flash('error', $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'The support reply could not be saved.');
            redirect('school_support.php?ticket_id=' . $ticketId);
        }

        $notificationFailed = false;
        try {
            $managers = db()->query(
                "SELECT DISTINCT u.id
                 FROM user_roles ur
                 JOIN roles r ON r.id = ur.role_id AND r.name = 'platform_super_admin'
                 JOIN users u ON u.id = ur.user_id AND u.status = 'active'
                   AND u.email_verified_at IS NOT NULL"
            );
            foreach ($managers->fetchAll(PDO::FETCH_COLUMN) as $managerId) {
                try {
                    create_user_notification(
                        (int) $managerId,
                        'school-support-reply-' . $ticketId . '-' . $replyKey,
                        'support',
                        'New school IT support reply',
                        (string) $school['name'] . ' replied to support request #' . $ticketId,
                        'platform_admin.php?support_ticket_id=' . $ticketId . '#school-support-requests'
                    );
                } catch (Throwable $exception) {
                    $notificationFailed = true;
                    error_log('School support reply notification failed for ticket ' . $ticketId . ': ' . $exception->getMessage());
                }
            }
        } catch (Throwable $exception) {
            $notificationFailed = true;
            error_log('School support reply recipients could not be loaded for ticket ' . $ticketId . ': ' . $exception->getMessage());
        }

        if ($notificationFailed) {
            flash('error', 'Your reply was saved, but the support team notification could not be delivered.');
        } else {
            flash('success', 'Support response saved.');
        }
        redirect('school_support.php?ticket_id=' . $ticketId);
    }
}

$ticketId = filter_var($_GET['ticket_id'] ?? null, FILTER_VALIDATE_INT);
$selectedTicket = null;
$messages = [];

$ticketTrackingSelect = $supportReadTrackingAvailable
    ? 'st.read_at, st.replied_at'
    : 'NULL AS read_at, NULL AS replied_at';
$ticketStmt = db()->prepare(
    'SELECT st.id, st.subject, st.body, st.category, st.status, st.priority, st.created_at,
            ' . $ticketTrackingSelect . ', u.full_name AS reporter_name
     FROM school_support_tickets st
     JOIN users u ON u.id = st.user_id
     WHERE st.school_id = ?' . ($canManageSupport ? '' : ' AND st.user_id = ?') . '
     ORDER BY st.created_at DESC'
);
$ticketStmt->execute($canManageSupport
    ? [$schoolId]
    : [$schoolId, (int) $user['id']]);
$tickets = $ticketStmt->fetchAll();

if ($ticketId !== false && $ticketId > 0) {
    $selectedStmt = db()->prepare(
        'SELECT st.id, st.subject, st.body, st.category, st.status, st.priority, st.created_at,
                ' . $ticketTrackingSelect . ', u.full_name AS reporter_name
         FROM school_support_tickets st
         JOIN users u ON u.id = st.user_id
         WHERE st.id = ? AND st.school_id = ?' . ($canManageSupport ? '' : ' AND st.user_id = ?') . '
         LIMIT 1'
    );
    $selectedStmt->execute($canManageSupport
        ? [$ticketId, $schoolId]
        : [$ticketId, $schoolId, (int) $user['id']]);
    $selectedTicket = $selectedStmt->fetch();

    if ($selectedTicket) {
        if ($supportReadTrackingAvailable) {
            $markRepliesReadStmt = db()->prepare(
                'UPDATE school_support_messages
                 SET read_by_user_id = CASE WHEN read_at IS NULL THEN ? ELSE read_by_user_id END,
                     read_at = COALESCE(read_at, UTC_TIMESTAMP())
                 WHERE ticket_id = ? AND read_at IS NULL
                   AND EXISTS (
                       SELECT 1 FROM user_roles ur
                       JOIN roles r ON r.id = ur.role_id AND r.name = "platform_super_admin"
                       WHERE ur.user_id = school_support_messages.sender_user_id
                   )'
            );
            $markRepliesReadStmt->execute([
                (int) $user['id'],
                (int) $ticketId,
            ]);
        }
        $messageReadSelect = $supportReadTrackingAvailable ? 'sm.read_at' : 'NULL AS read_at';
        $messageStmt = db()->prepare(
            'SELECT sm.id, sm.sender_user_id, sm.message, sm.created_at, ' . $messageReadSelect . ',
                    EXISTS (
                        SELECT 1 FROM user_roles ur
                        JOIN roles r ON r.id = ur.role_id AND r.name = "platform_super_admin"
                        WHERE ur.user_id = sm.sender_user_id
                    ) AS sender_is_support,
                    u.full_name AS sender_name
             FROM school_support_messages sm
             JOIN users u ON u.id = sm.sender_user_id
             WHERE sm.ticket_id = ? ORDER BY sm.created_at ASC'
        );
        $messageStmt->execute([$ticketId]);
        $messages = $messageStmt->fetchAll();
    }
}

render_header('School support');
?>
<?php render_it_support_contact_section($supportContact, true); ?>
<section class="panel school-support-panel">
    <p class="eyebrow">CONNEZER EDUTECH SYSTEMS — IT SUPPORT</p>
    <p class="muted">Contact support directly or submit a request. Do not send student records, passwords, or verification codes through WhatsApp or email.</p>
    <?php if (!$supportReadTrackingAvailable): ?>
        <p class="notice notice-warning">Conversation read tracking requires database migration 080. Existing support requests and replies remain available.</p>
    <?php endif; ?>
    <?php if (!$supportReplyIdempotencyAvailable): ?>
        <p class="notice notice-warning">Duplicate-reply protection requires database migration 080.</p>
    <?php endif; ?>
</section>

<section class="panel school-support-panel">
    <h2>Submit a Support Request</h2>
    <form method="post" action="school_support.php" class="form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_ticket">
        <div class="form-grid two-columns">
            <div class="school-support-form-wide"><label for="subject">Subject</label><input id="subject" name="subject" type="text" maxlength="180" required></div>
            <div><label for="category">Issue category</label><select id="category" name="category" required><option value="technical">Technical issue</option><option value="academic_calendar">Academic calendar</option><option value="fees">Fees and payments</option><option value="access">Account access</option><option value="other">Other</option></select></div>
            <div><label for="priority">Priority</label><select id="priority" name="priority" required><option value="normal">Normal</option><option value="low">Low</option><option value="high">High</option><option value="urgent">Urgent</option></select></div>
            <div class="school-support-form-wide"><label for="body">Description</label><textarea id="body" name="body" rows="5" minlength="10" maxlength="10000" required></textarea></div>
        </div>
        <button class="button button-primary" type="submit">Submit Support Request</button>
    </form>
</section>

<section class="panel school-support-panel">
    <h2>Tickets</h2>
    <?php if ($tickets === []): ?>
        <p class="muted">No school support tickets have been created yet.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr><th>Subject</th><th>Reporter</th><th>Category</th><th>Status</th><th>Priority</th><th>Submitted</th></tr>
            </thead>
            <tbody>
                <?php foreach ($tickets as $ticket): ?>
                    <tr>
                        <td><a href="school_support.php?ticket_id=<?= (int) $ticket['id'] ?>"><?= e((string) $ticket['subject']) ?></a></td>
                        <td><?= e((string) $ticket['reporter_name']) ?></td>
                        <td><?= e(ucwords(str_replace('_', ' ', (string) $ticket['category']))) ?></td>
                        <td><?= e((string) $ticket['status']) ?></td>
                        <td><?= e((string) $ticket['priority']) ?></td>
                        <td><?= e((string) $ticket['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<?php if ($selectedTicket): ?>
    <section class="panel school-support-panel">
        <h2><?= e((string) $selectedTicket['subject']) ?></h2>
        <p class="muted">Reporter: <?= e((string) $selectedTicket['reporter_name']) ?> · <?= e(ucwords(str_replace('_', ' ', (string) $selectedTicket['category']))) ?> · <?= e(ucfirst((string) $selectedTicket['priority'])) ?> priority · Status: <?= e((string) $selectedTicket['status']) ?> · <?= $supportReadTrackingAvailable ? ($selectedTicket['read_at'] === null ? 'Unread by support' : 'Read by support') : 'Read tracking pending migration 080' ?><?= $supportReadTrackingAvailable ? ($selectedTicket['replied_at'] === null ? ' · Awaiting reply' : ' · Replied') : '' ?></p>
        <div class="panel panel-light">
            <p><?= nl2br(e((string) $selectedTicket['body'])) ?></p>
        </div>

        <?php if ($messages === []): ?>
            <p class="muted">No replies have been added to this ticket yet.</p>
        <?php else: ?>
            <h3>Conversation</h3>
            <?php foreach ($messages as $message): ?>
                <div class="panel panel-light school-support-message">
                    <p class="muted"><?= e((string) $message['sender_name']) ?> · <?= e((string) $message['created_at']) ?> · <?= !$supportReadTrackingAvailable ? 'Read tracking pending migration 080' : ((int) $message['sender_is_support'] === 1 ? 'Read' : ($message['read_at'] !== null ? 'Read by support' : ((int) $message['sender_user_id'] === (int) $user['id'] ? 'Sent' : 'School team message'))) ?></p>
                    <p><?= nl2br(e((string) $message['message'])) ?></p>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <form method="post" action="school_support.php?ticket_id=<?= (int) $selectedTicket['id'] ?>" class="form-stack school-support-reply">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reply_ticket">
            <input type="hidden" name="ticket_id" value="<?= (int) $selectedTicket['id'] ?>">
            <input type="hidden" name="reply_key" value="<?= e(bin2hex(random_bytes(16))) ?>">
            <div>
                <label for="message">Reply</label>
                <textarea id="message" name="message" rows="4" required></textarea>
            </div>
            <button class="button button-primary" type="submit">Send reply</button>
        </form>
    </section>
<?php endif; ?>
<?php render_footer(); ?>
