<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/support_uploads.php';

function support_categories(): array
{
    return [
        'login_account' => 'Login and Account',
        'membership' => 'Membership',
        'payments_dues' => 'Payments and Dues',
        'website_problem' => 'Website Problem',
        'technical_support' => 'Technical Support',
        'other' => 'Other',
        'account_access' => 'Account Access',
        'payments' => 'Payments',
        'events' => 'Events',
        'technical' => 'Technical issue',
    ];
}

function support_priorities(): array
{
    return ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'];
}

function support_statuses(): array
{
    return [
        'open' => 'Open',
        'in_progress' => 'In Progress',
        'awaiting_member' => 'Awaiting Member',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
    ];
}

function support_input_string(array $input, string $key): string
{
    $value = $input[$key] ?? '';
    return is_string($value) ? $value : '';
}

function support_uploaded_attachment(): ?array
{
    $upload = $_FILES['attachment'] ?? [];
    if (!is_array($upload)) {
        throw new InvalidArgumentException('Choose a valid attachment to upload.');
    }
    foreach (['name', 'tmp_name'] as $field) {
        if (isset($upload[$field]) && !is_string($upload[$field])) {
            throw new InvalidArgumentException('Choose a valid attachment to upload.');
        }
    }
    if (isset($upload['error']) && !is_int($upload['error'])
        && !(is_string($upload['error']) && ctype_digit($upload['error']))) {
        throw new InvalidArgumentException('Choose a valid attachment to upload.');
    }
    return store_support_attachment($upload);
}

function record_support_history(
    int $ticketId,
    int $actorId,
    string $eventType,
    ?string $oldStatus,
    ?string $newStatus,
    ?string $oldPriority,
    ?string $newPriority
): int {
    $stmt = db()->prepare(
        'INSERT INTO support_ticket_history
         (ticket_id, changed_by, event_type, old_status, new_status, old_priority, new_priority)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$ticketId, $actorId, $eventType, $oldStatus, $newStatus, $oldPriority, $newPriority]);
    return (int) db()->lastInsertId();
}

function notify_support_managers(int $ticketId, string $reference, string $subject, string $key, string $body): void
{
    $stmt = db()->query(
        "SELECT DISTINCT ur.user_id
         FROM user_roles ur
         JOIN roles r ON r.id = ur.role_id
         JOIN role_permissions rp ON rp.role_id = ur.role_id AND rp.permission_name = 'support.manage'
         JOIN users u ON u.id = ur.user_id AND u.status = 'active'"
    );
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $userId) {
        create_user_notification(
            (int) $userId,
            $key,
            'support',
            'Support request ' . $reference,
            $body . ' — ' . $subject,
            'support.php?ticket=' . $ticketId
        );
    }
}

function support_valid_date(string $date): bool
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        return false;
    }
    [$year, $month, $day] = array_map('intval', explode('-', $date));
    return checkdate($month, $day, $year);
}

$user = require_login();
$canManage = user_has_permission($user, 'support.manage');
$canUseSupport = user_has_permission($user, 'support.view_self') || $canManage;
if (!$canUseSupport) {
    http_response_code(403);
    render_header('Access denied');
    echo '<section class="panel"><h1>Access denied</h1><p>Your account cannot access support tickets.</p><a class="button" href="dashboard.php">Return to dashboard</a></section>';
    render_footer();
    exit;
}

$ticketIdValue = $_GET['ticket'] ?? $_POST['ticket_id'] ?? null;
$ticketId = $ticketIdValue === null ? null : filter_var($ticketIdValue, FILTER_VALIDATE_INT);
if ($ticketIdValue !== null && (!$ticketId || (int) $ticketId < 1)) {
    http_response_code(404);
    exit('Ticket not found.');
}
$ticketId = $ticketId === null ? null : (int) $ticketId;
$ticket = null;
$replies = [];
$attachmentsByReply = [];
$history = [];
if ($ticketId !== null) {
    $stmt = db()->prepare(
        'SELECT t.*, u.full_name AS member_name, u.email AS member_email, a.full_name AS assignee_name
         FROM support_tickets t
         JOIN users u ON u.id = t.user_id
         LEFT JOIN users a ON a.id = t.assigned_to
         WHERE t.id = ? AND (t.user_id = ? OR ? = 1)'
    );
    $stmt->execute([$ticketId, (int) $user['id'], $canManage ? 1 : 0]);
    $ticket = $stmt->fetch();
    if (!$ticket) {
        http_response_code(404);
        render_header('Ticket not found');
        echo '<section class="panel"><h1>Ticket not found</h1><p class="muted">This ticket is unavailable.</p><a class="button" href="support.php">Return to support</a></section>';
        render_footer();
        exit;
    }
    $stmt = db()->prepare(
        'SELECT r.id, r.body, r.created_at, u.full_name, r.user_id
         FROM support_ticket_replies r
         JOIN users u ON u.id = r.user_id
         WHERE r.ticket_id = ?
         ORDER BY r.created_at, r.id'
    );
    $stmt->execute([$ticketId]);
    $replies = $stmt->fetchAll();
    $stmt = db()->prepare(
        'SELECT a.id, a.reply_id, a.original_name, a.file_size
         FROM support_ticket_attachments a
         JOIN support_ticket_replies r ON r.id = a.reply_id
         WHERE r.ticket_id = ?
         ORDER BY a.created_at, a.id'
    );
    $stmt->execute([$ticketId]);
    foreach ($stmt->fetchAll() as $attachment) {
        $attachmentsByReply[(int) $attachment['reply_id']][] = $attachment;
    }
    $stmt = db()->prepare(
        'SELECT h.event_type, h.old_status, h.new_status, h.old_priority, h.new_priority,
                h.created_at, u.full_name
         FROM support_ticket_history h
         JOIN users u ON u.id = h.changed_by
         WHERE h.ticket_id = ?
         ORDER BY h.created_at DESC, h.id DESC'
    );
    $stmt->execute([$ticketId]);
    $history = $stmt->fetchAll();
}

if (isset($_GET['attachment'])) {
    $attachmentId = filter_var($_GET['attachment'], FILTER_VALIDATE_INT);
    if (!$attachmentId || $ticketId === null) {
        http_response_code(404);
        exit('Attachment not found.');
    }
    $stmt = db()->prepare(
        'SELECT a.stored_name, a.original_name, a.mime_type
         FROM support_ticket_attachments a
         JOIN support_ticket_replies r ON r.id = a.reply_id
         JOIN support_tickets t ON t.id = r.ticket_id
         WHERE a.id = ? AND t.id = ? AND (t.user_id = ? OR ? = 1)'
    );
    $stmt->execute([(int) $attachmentId, $ticketId, (int) $user['id'], $canManage ? 1 : 0]);
    $attachment = $stmt->fetch();
    $allowedDownloadMimeTypes = [
        'application/pdf', 'image/jpeg', 'image/png', 'image/webp',
        'text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel',
    ];
    if (!$attachment
        || preg_match('/^[a-f0-9]{48}\.(?:pdf|jpg|jpeg|png|webp|txt|csv)$/', (string) $attachment['stored_name']) !== 1
        || !in_array($attachment['mime_type'], $allowedDownloadMimeTypes, true)) {
        http_response_code(404);
        exit('Attachment not found.');
    }
    $storage = realpath(APP_ROOT . '/storage/support');
    $path = $storage ? realpath($storage . DIRECTORY_SEPARATOR . $attachment['stored_name']) : false;
    if (!$storage || !$path || !str_starts_with($path, $storage . DIRECTORY_SEPARATOR) || !is_file($path) || !is_readable($path)) {
        throw new RuntimeException('The requested support attachment is missing from private storage.');
    }
    header('Content-Type: ' . $attachment['mime_type']);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . rawurlencode($attachment['original_name']) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = support_input_string($_POST, 'action');
    if ($action === 'create') {
        if (!user_has_permission($user, 'support.view_self')) {
            http_response_code(403);
            exit('Your account cannot create support tickets.');
        }
        $subject = trim(support_input_string($_POST, 'subject'));
        $category = support_input_string($_POST, 'category');
        $priority = support_input_string($_POST, 'priority') ?: 'normal';
        $body = trim(support_input_string($_POST, 'body'));
        if (preg_match('/^.{4,180}$/us', $subject) !== 1
            || !isset(support_categories()[$category])
            || !isset(support_priorities()[$priority])
            || preg_match('/^.{10,10000}$/us', $body) !== 1) {
            flash('error', 'Provide a subject, category, priority, and message between 10 and 10,000 characters.');
            redirect('support.php');
        }
        $reference = 'OSA-' . gmdate('ymd') . '-' . strtoupper(bin2hex(random_bytes(5)));
        try {
            $attachment = support_uploaded_attachment();
        } catch (InvalidArgumentException $exception) {
            flash('error', $exception->getMessage());
            redirect('support.php');
        }
        try {
            db()->beginTransaction();
            $stmt = db()->prepare(
                'INSERT INTO support_tickets (reference, user_id, subject, category, priority)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$reference, (int) $user['id'], $subject, $category, $priority]);
            $newTicketId = (int) db()->lastInsertId();
            $stmt = db()->prepare(
                'INSERT INTO support_ticket_replies (ticket_id, user_id, body) VALUES (?, ?, ?)'
            );
            $stmt->execute([$newTicketId, (int) $user['id'], $body]);
            $newReplyId = (int) db()->lastInsertId();
            if ($attachment) {
                save_support_attachment($newReplyId, (int) $user['id'], $attachment);
            }
            record_support_history(
                $newTicketId,
                (int) $user['id'],
                'created',
                null,
                'open',
                null,
                $priority
            );
            notify_support_managers(
                $newTicketId,
                $reference,
                $subject,
                'support:ticket:' . $newTicketId . ':reply:' . $newReplyId,
                'A new member support request needs review'
            );
            db()->commit();
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            remove_support_attachment_file($attachment);
            throw $exception;
        }
        audit((int) $user['id'], 'support_ticket_created', 'ticket_id=' . $newTicketId);
        flash('success', 'Your request was submitted. Ticket number: ' . $reference);
        redirect('support.php?ticket=' . $newTicketId);
    }

    $postedTicketId = filter_var($_POST['ticket_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$postedTicketId || (int) $postedTicketId < 1) {
        flash('error', 'Choose a valid ticket.');
        redirect('support.php');
    }
    $stmt = db()->prepare(
        'SELECT id, user_id, reference, subject, status, priority
         FROM support_tickets WHERE id = ?'
    );
    $stmt->execute([(int) $postedTicketId]);
    $postedTicket = $stmt->fetch();
    if (!$postedTicket || (!$canManage && (int) $postedTicket['user_id'] !== (int) $user['id'])) {
        http_response_code(404);
        exit('Ticket not found.');
    }

    if ($action === 'reply') {
        $body = trim(support_input_string($_POST, 'body'));
        if ($postedTicket['status'] === 'closed' || preg_match('/^.{2,10000}$/us', $body) !== 1) {
            flash('error', 'This ticket is closed or your reply is not valid.');
            redirect('support.php?ticket=' . (int) $postedTicketId);
        }
        try {
            $attachment = support_uploaded_attachment();
        } catch (InvalidArgumentException $exception) {
            flash('error', $exception->getMessage());
            redirect('support.php?ticket=' . (int) $postedTicketId);
        }
        try {
            db()->beginTransaction();
            $stmt = db()->prepare(
                'SELECT status, priority, user_id, reference, subject
                 FROM support_tickets WHERE id = ? FOR UPDATE'
            );
            $stmt->execute([(int) $postedTicketId]);
            $lockedTicket = $stmt->fetch();
            if (!$lockedTicket || $lockedTicket['status'] === 'closed') {
                db()->rollBack();
                remove_support_attachment_file($attachment);
                flash('error', 'This ticket was closed before your reply could be saved.');
                redirect('support.php?ticket=' . (int) $postedTicketId);
            }
            $stmt = db()->prepare(
                'INSERT INTO support_ticket_replies (ticket_id, user_id, body) VALUES (?, ?, ?)'
            );
            $stmt->execute([(int) $postedTicketId, (int) $user['id'], $body]);
            $replyId = (int) db()->lastInsertId();
            if ($attachment) {
                save_support_attachment($replyId, (int) $user['id'], $attachment);
            }

            if ($canManage) {
                $nextStatus = 'awaiting_member';
                $stmt = db()->prepare(
                    'UPDATE support_tickets
                     SET status = ?, assigned_to = ?, acknowledged_at = COALESCE(acknowledged_at, UTC_TIMESTAMP()),
                         updated_at = UTC_TIMESTAMP()
                     WHERE id = ?'
                );
                $stmt->execute([$nextStatus, (int) $user['id'], (int) $postedTicketId]);
                record_support_history(
                    (int) $postedTicketId,
                    (int) $user['id'],
                    'updated',
                    (string) $lockedTicket['status'],
                    $nextStatus,
                    (string) $lockedTicket['priority'],
                    (string) $lockedTicket['priority']
                );
                create_user_notification(
                    (int) $lockedTicket['user_id'],
                    'support:ticket:' . (int) $postedTicketId . ':reply:' . $replyId,
                    'support',
                    'Support replied to ' . $lockedTicket['reference'],
                    'The support team replied to your request: ' . $lockedTicket['subject'],
                    'support.php?ticket=' . (int) $postedTicketId
                );
            } else {
                $nextStatus = 'open';
                $stmt = db()->prepare(
                    'UPDATE support_tickets
                     SET status = ?, acknowledged_at = NULL, updated_at = UTC_TIMESTAMP()
                     WHERE id = ?'
                );
                $stmt->execute([$nextStatus, (int) $postedTicketId]);
                record_support_history(
                    (int) $postedTicketId,
                    (int) $user['id'],
                    'updated',
                    (string) $lockedTicket['status'],
                    $nextStatus,
                    (string) $lockedTicket['priority'],
                    (string) $lockedTicket['priority']
                );
                notify_support_managers(
                    (int) $postedTicketId,
                    (string) $lockedTicket['reference'],
                    (string) $lockedTicket['subject'],
                    'support:ticket:' . (int) $postedTicketId . ':reply:' . $replyId,
                    'A member replied and the ticket requires attention again'
                );
            }
            db()->commit();
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            remove_support_attachment_file($attachment);
            throw $exception;
        }
        audit((int) $user['id'], 'support_ticket_replied', 'ticket_id=' . (int) $postedTicketId);
        flash('success', 'Your reply was added.');
        redirect('support.php?ticket=' . (int) $postedTicketId);
    }

    if ($action === 'acknowledge' && $canManage) {
        db()->beginTransaction();
        try {
            $stmt = db()->prepare(
                'SELECT status, priority, acknowledged_at
                 FROM support_tickets WHERE id = ? FOR UPDATE'
            );
            $stmt->execute([(int) $postedTicketId]);
            $lockedTicket = $stmt->fetch();
            if (!$lockedTicket) {
                throw new RuntimeException('The support ticket could not be found while acknowledging it.');
            }
            if ($lockedTicket['acknowledged_at'] === null) {
                $stmt = db()->prepare(
                    'UPDATE support_tickets
                     SET acknowledged_at = UTC_TIMESTAMP(), assigned_to = ?, updated_at = UTC_TIMESTAMP()
                     WHERE id = ?'
                );
                $stmt->execute([(int) $user['id'], (int) $postedTicketId]);
                record_support_history(
                    (int) $postedTicketId,
                    (int) $user['id'],
                    'acknowledged',
                    (string) $lockedTicket['status'],
                    (string) $lockedTicket['status'],
                    (string) $lockedTicket['priority'],
                    (string) $lockedTicket['priority']
                );
                $stmt = db()->prepare(
                    "UPDATE association_notifications
                     SET read_at = COALESCE(read_at, UTC_TIMESTAMP())
                     WHERE user_id = ? AND category = 'support' AND link_url = ?"
                );
                $stmt->execute([
                    (int) $user['id'],
                    'support.php?ticket=' . (int) $postedTicketId,
                ]);
            }
            db()->commit();
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            throw $exception;
        }
        audit((int) $user['id'], 'support_ticket_acknowledged', 'ticket_id=' . (int) $postedTicketId);
        flash('success', 'Ticket ' . $postedTicket['reference'] . ' marked as acknowledged.');
        redirect('support.php?ticket=' . (int) $postedTicketId);
    }

    if ($action === 'update' && $canManage) {
        $status = support_input_string($_POST, 'status');
        $priority = support_input_string($_POST, 'priority');
        if (!isset(support_statuses()[$status]) || !isset(support_priorities()[$priority])) {
            flash('error', 'Choose a valid ticket status and priority.');
            redirect('support.php?ticket=' . (int) $postedTicketId);
        }
        db()->beginTransaction();
        try {
            $stmt = db()->prepare(
                'SELECT status, priority, user_id, reference, subject
                 FROM support_tickets WHERE id = ? FOR UPDATE'
            );
            $stmt->execute([(int) $postedTicketId]);
            $lockedTicket = $stmt->fetch();
            if (!$lockedTicket) {
                throw new RuntimeException('The support ticket could not be found while updating it.');
            }
            $oldStatus = (string) $lockedTicket['status'];
            $oldPriority = (string) $lockedTicket['priority'];
            $changed = $oldStatus !== $status || $oldPriority !== $priority;
            if ($changed) {
                $stmt = db()->prepare(
                    'UPDATE support_tickets
                     SET status = ?, priority = ?, assigned_to = ?,
                         acknowledged_at = COALESCE(acknowledged_at, UTC_TIMESTAMP()),
                         updated_at = UTC_TIMESTAMP()
                     WHERE id = ?'
                );
                $stmt->execute([$status, $priority, (int) $user['id'], (int) $postedTicketId]);
                $historyId = record_support_history(
                    (int) $postedTicketId,
                    (int) $user['id'],
                    'updated',
                    $oldStatus,
                    $status,
                    $oldPriority,
                    $priority
                );
                create_user_notification(
                    (int) $lockedTicket['user_id'],
                    'support:ticket:' . (int) $postedTicketId . ':status:' . $historyId,
                    'support',
                    'Support ticket ' . $lockedTicket['reference'] . ' updated',
                    'Your request is now ' . support_statuses()[$status]
                        . ' with ' . support_priorities()[$priority] . ' priority.',
                    'support.php?ticket=' . (int) $postedTicketId
                );
            }
            db()->commit();
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            throw $exception;
        }
        if ($changed) {
            audit(
                (int) $user['id'],
                'support_ticket_updated',
                'ticket_id=' . (int) $postedTicketId . '; status=' . $status . '; priority=' . $priority
            );
            flash('success', 'Ticket status and priority updated.');
        } else {
            flash('success', 'No ticket changes were needed.');
        }
        redirect('support.php?ticket=' . (int) $postedTicketId);
    }

    http_response_code(403);
    exit('Your account cannot perform that support action.');
}

$categories = support_categories();
$priorities = support_priorities();
$statuses = support_statuses();
$filters = [
    'q' => trim(support_input_string($_GET, 'q')),
    'category' => support_input_string($_GET, 'category'),
    'priority' => support_input_string($_GET, 'priority'),
    'status' => support_input_string($_GET, 'status'),
    'from' => support_input_string($_GET, 'from'),
    'to' => support_input_string($_GET, 'to'),
];
if (strlen($filters['q']) > 100) {
    $filters['q'] = substr($filters['q'], 0, 100);
}
if (!isset($categories[$filters['category']])) {
    $filters['category'] = '';
}
if (!isset($priorities[$filters['priority']])) {
    $filters['priority'] = '';
}
if (!isset($statuses[$filters['status']])) {
    $filters['status'] = '';
}
if (!support_valid_date($filters['from'])) {
    $filters['from'] = '';
}
if (!support_valid_date($filters['to'])) {
    $filters['to'] = '';
}

if ($canManage) {
    $summary = db()->query(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(acknowledged_at IS NULL AND status IN ('open','in_progress')), 0) AS new_count,
                COALESCE(SUM(status = 'open'), 0) AS open_count,
                COALESCE(SUM(status IN ('in_progress','awaiting_member')), 0) AS handling_count,
                COALESCE(SUM(status = 'resolved'), 0) AS resolved_count,
                COALESCE(SUM(status = 'closed'), 0) AS closed_count
         FROM support_tickets"
    )->fetch();
} else {
    $summary = [];
}

$conditions = [];
$parameters = [];
if (!$canManage) {
    $conditions[] = 't.user_id = ?';
    $parameters[] = (int) $user['id'];
}
if ($filters['q'] !== '') {
    $conditions[] = '(t.reference LIKE ? OR t.subject LIKE ? OR u.full_name LIKE ?)';
    $search = '%' . $filters['q'] . '%';
    array_push($parameters, $search, $search, $search);
}
if ($filters['category'] !== '') {
    $conditions[] = 't.category = ?';
    $parameters[] = $filters['category'];
}
if ($filters['priority'] !== '') {
    $conditions[] = 't.priority = ?';
    $parameters[] = $filters['priority'];
}
if ($filters['status'] !== '') {
    $conditions[] = 't.status = ?';
    $parameters[] = $filters['status'];
}
if ($filters['from'] !== '') {
    $conditions[] = 't.created_at >= ?';
    $parameters[] = $filters['from'] . ' 00:00:00';
}
if ($filters['to'] !== '') {
    $conditions[] = 't.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
    $parameters[] = $filters['to'] . ' 00:00:00';
}
$ticketSql = 'SELECT t.id, t.reference, t.subject, t.category, t.priority, t.status,
                     t.acknowledged_at, t.created_at, t.updated_at, u.full_name AS member_name
              FROM support_tickets t JOIN users u ON u.id = t.user_id';
if ($conditions) {
    $ticketSql .= ' WHERE ' . implode(' AND ', $conditions);
}
$ticketSql .= ' ORDER BY t.updated_at DESC, t.id DESC LIMIT 100';
$stmt = db()->prepare($ticketSql);
$stmt->execute($parameters);
$tickets = $stmt->fetchAll();

render_header('Help and support');
page_title(
    'MEMBER SERVICES',
    $canManage ? 'Support queue' : 'Help and support',
    $canManage
        ? 'Review, acknowledge, and respond to private member support requests.'
        : 'Create a private request and follow replies from the association support team.'
);
?>
<?php if ($canManage): ?>
<section class="support-summary-grid" aria-label="Support ticket summary">
    <article class="support-summary-card"><span>Total requests</span><strong><?= (int) ($summary['total'] ?? 0) ?></strong></article>
    <article class="support-summary-card support-summary-new"><span>New</span><strong><?= (int) ($summary['new_count'] ?? 0) ?></strong></article>
    <article class="support-summary-card"><span>Open</span><strong><?= (int) ($summary['open_count'] ?? 0) ?></strong></article>
    <article class="support-summary-card"><span>Being handled</span><strong><?= (int) ($summary['handling_count'] ?? 0) ?></strong></article>
    <article class="support-summary-card"><span>Resolved</span><strong><?= (int) ($summary['resolved_count'] ?? 0) ?></strong></article>
    <article class="support-summary-card"><span>Closed</span><strong><?= (int) ($summary['closed_count'] ?? 0) ?></strong></article>
</section>
<?php endif; ?>

<?php if ($ticket): ?>
<section class="panel">
    <div class="panel-heading">
        <div>
            <span class="eyebrow"><?= e($ticket['reference']) ?></span>
            <h2><?= e($ticket['subject']) ?></h2>
            <p class="muted">
                <?= e($categories[$ticket['category']] ?? ucwords(str_replace('_', ' ', $ticket['category']))) ?>
                · <?= e($priorities[$ticket['priority']] ?? 'Normal') ?> priority
                · <?= e($statuses[$ticket['status']] ?? ucwords(str_replace('_', ' ', $ticket['status']))) ?>
            </p>
        </div>
        <a class="button button-quiet button-small" href="support.php">Back to tickets</a>
    </div>
    <?php if ($canManage): ?>
    <dl class="support-member-details">
        <div><dt>Member</dt><dd><?= e($ticket['member_name']) ?></dd></div>
        <div><dt>Email</dt><dd><a href="mailto:<?= e($ticket['member_email']) ?>"><?= e($ticket['member_email']) ?></a></dd></div>
        <div><dt>Submitted</dt><dd><?= e((string) $ticket['created_at']) ?> UTC</dd></div>
        <div><dt>Assigned to</dt><dd><?= e($ticket['assignee_name'] ?: 'Unassigned') ?></dd></div>
    </dl>
    <div class="support-ticket-actions">
        <?php if ($ticket['acknowledged_at'] === null): ?>
        <form method="post" action="support.php?ticket=<?= (int) $ticket['id'] ?>">
            <?= csrf_field() ?><input type="hidden" name="action" value="acknowledge"><input type="hidden" name="ticket_id" value="<?= (int) $ticket['id'] ?>">
            <button class="button button-small" type="submit">Mark as acknowledged</button>
        </form>
        <?php else: ?>
        <p class="muted">Acknowledged <?= e((string) $ticket['acknowledged_at']) ?> UTC</p>
        <?php endif; ?>
    </div>
    <form method="post" action="support.php?ticket=<?= (int) $ticket['id'] ?>" class="support-update-form">
        <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="ticket_id" value="<?= (int) $ticket['id'] ?>">
        <label for="ticket-status">Status</label>
        <select id="ticket-status" name="status"><?php foreach ($statuses as $statusValue => $statusLabel): ?><option value="<?= e($statusValue) ?>"<?= $ticket['status'] === $statusValue ? ' selected' : '' ?>><?= e($statusLabel) ?></option><?php endforeach; ?></select>
        <label for="ticket-priority">Priority</label>
        <select id="ticket-priority" name="priority"><?php foreach ($priorities as $priorityValue => $priorityLabel): ?><option value="<?= e($priorityValue) ?>"<?= $ticket['priority'] === $priorityValue ? ' selected' : '' ?>><?= e($priorityLabel) ?></option><?php endforeach; ?></select>
        <button class="button button-small" type="submit">Save ticket</button>
    </form>
    <?php else: ?>
    <p class="muted">Submitted <?= e((string) $ticket['created_at']) ?> UTC.</p>
    <?php endif; ?>
</section>

<section class="panel top-gap">
    <h2>Conversation</h2>
    <div class="support-thread">
        <?php foreach ($replies as $reply): ?>
        <article class="support-reply">
            <div class="support-reply-meta">
                <strong><?= e($reply['full_name']) ?><?= (int) $reply['user_id'] === (int) $ticket['user_id'] ? ' · Member' : ' · Support' ?></strong>
                <time datetime="<?= e(gmdate('c', strtotime((string) $reply['created_at'] . ' UTC'))) ?>"><?= e((string) $reply['created_at']) ?> UTC</time>
            </div>
            <p><?= nl2br(e($reply['body'])) ?></p>
            <?php foreach ($attachmentsByReply[(int) $reply['id']] ?? [] as $attachment): ?>
            <p class="muted"><a href="support.php?ticket=<?= (int) $ticket['id'] ?>&amp;attachment=<?= (int) $attachment['id'] ?>"><?= e($attachment['original_name']) ?></a> · <?= number_format((int) $attachment['file_size'] / 1024, 1) ?> KB</p>
            <?php endforeach; ?>
        </article>
        <?php endforeach; ?>
    </div>
    <?php if ($ticket['status'] !== 'closed'): ?>
    <form method="post" action="support.php?ticket=<?= (int) $ticket['id'] ?>" class="form-stack top-gap" enctype="multipart/form-data">
        <?= csrf_field() ?><input type="hidden" name="action" value="reply"><input type="hidden" name="ticket_id" value="<?= (int) $ticket['id'] ?>">
        <label for="reply-body">Reply</label><textarea id="reply-body" name="body" rows="5" maxlength="10000" required></textarea>
        <label for="reply-attachment">Attachment (optional)</label><input id="reply-attachment" name="attachment" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.txt,.csv"><small class="muted">PDF, JPG, PNG, WebP, TXT, or CSV; maximum 5 MB.</small>
        <button class="button button-primary" type="submit">Send reply</button>
    </form>
    <?php elseif ($canManage): ?>
    <p class="muted">This ticket is closed. Change its status above to reopen it before replying.</p>
    <?php else: ?>
    <p class="muted">This ticket is closed. You can view the conversation and contact support to reopen it.</p>
    <?php endif; ?>
</section>

<?php if ($canManage): ?>
<section class="panel top-gap">
    <h2>Ticket history</h2>
    <?php if (!$history): ?><p class="muted">No workflow history was recorded for this ticket before the history feature was enabled.</p><?php else: ?>
    <ol class="support-history">
        <?php foreach ($history as $event): ?>
        <li>
            <strong><?= e(match ($event['event_type']) { 'created' => 'Ticket submitted', 'acknowledged' => 'Ticket acknowledged', default => 'Ticket updated' }) ?></strong>
            <span><?= e($event['full_name']) ?> · <?= e((string) $event['created_at']) ?> UTC</span>
            <?php if ($event['old_status'] !== $event['new_status'] || $event['old_priority'] !== $event['new_priority']): ?>
            <small>
                <?php if ($event['old_status'] !== $event['new_status']): ?>Status: <?= e($statuses[$event['old_status']] ?? (string) $event['old_status'] ?: '—') ?> → <?= e($statuses[$event['new_status']] ?? (string) $event['new_status'] ?: '—') ?><?php endif; ?>
                <?php if ($event['old_priority'] !== $event['new_priority']): ?><?= $event['old_status'] !== $event['new_status'] ? ' · ' : '' ?>Priority: <?= e($priorities[$event['old_priority']] ?? (string) $event['old_priority'] ?: '—') ?> → <?= e($priorities[$event['new_priority']] ?? (string) $event['new_priority'] ?: '—') ?><?php endif; ?>
            </small>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ol>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php elseif (!$canManage && user_has_permission($user, 'support.view_self')): ?>
<section class="panel">
    <h2>Open a support request</h2>
    <form method="post" action="support.php" class="form-stack" enctype="multipart/form-data">
        <?= csrf_field() ?><input type="hidden" name="action" value="create">
        <label for="subject">Subject</label><input id="subject" name="subject" maxlength="180" required>
        <label for="category">Category</label>
        <select id="category" name="category" required>
            <?php foreach (array_slice($categories, 0, 6, true) as $categoryValue => $categoryLabel): ?><option value="<?= e($categoryValue) ?>"><?= e($categoryLabel) ?></option><?php endforeach; ?>
        </select>
        <label for="priority">Priority</label>
        <select id="priority" name="priority" required>
            <?php foreach ($priorities as $priorityValue => $priorityLabel): ?><option value="<?= e($priorityValue) ?>"<?= $priorityValue === 'normal' ? ' selected' : '' ?>><?= e($priorityLabel) ?></option><?php endforeach; ?>
        </select>
        <label for="ticket-body">Description</label><textarea id="ticket-body" name="body" rows="6" minlength="10" maxlength="10000" required></textarea>
        <label for="ticket-attachment">Attachment (optional)</label><input id="ticket-attachment" name="attachment" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.txt,.csv"><small class="muted">PDF, JPG, PNG, WebP, TXT, or CSV; maximum 5 MB.</small>
        <button class="button button-primary" type="submit">Submit request</button>
    </form>
</section>
<?php endif; ?>

<?php if ($canManage): ?>
<section class="panel top-gap">
    <h2>Find support requests</h2>
    <form method="get" action="support.php" class="support-filter-form">
        <div class="support-filter-field"><label for="support-search">Ticket number, member, or subject</label><input id="support-search" name="q" value="<?= e($filters['q']) ?>" maxlength="100"></div>
        <div class="support-filter-field"><label for="support-category">Category</label><select id="support-category" name="category"><option value="">All categories</option><?php foreach ($categories as $categoryValue => $categoryLabel): ?><option value="<?= e($categoryValue) ?>"<?= $filters['category'] === $categoryValue ? ' selected' : '' ?>><?= e($categoryLabel) ?></option><?php endforeach; ?></select></div>
        <div class="support-filter-field"><label for="support-priority">Priority</label><select id="support-priority" name="priority"><option value="">All priorities</option><?php foreach ($priorities as $priorityValue => $priorityLabel): ?><option value="<?= e($priorityValue) ?>"<?= $filters['priority'] === $priorityValue ? ' selected' : '' ?>><?= e($priorityLabel) ?></option><?php endforeach; ?></select></div>
        <div class="support-filter-field"><label for="support-status">Status</label><select id="support-status" name="status"><option value="">All statuses</option><?php foreach ($statuses as $statusValue => $statusLabel): ?><option value="<?= e($statusValue) ?>"<?= $filters['status'] === $statusValue ? ' selected' : '' ?>><?= e($statusLabel) ?></option><?php endforeach; ?></select></div>
        <div class="support-filter-field"><label for="support-from">Submitted from</label><input id="support-from" type="date" name="from" value="<?= e($filters['from']) ?>"></div>
        <div class="support-filter-field"><label for="support-to">Submitted to</label><input id="support-to" type="date" name="to" value="<?= e($filters['to']) ?>"></div>
        <button class="button button-small" type="submit">Filter requests</button><a class="button button-quiet button-small" href="support.php">Clear filters</a>
    </form>
</section>
<?php endif; ?>

<section class="panel table-panel top-gap">
    <h2><?= $canManage ? 'Support requests' : 'Your requests' ?></h2>
    <?php if (!$tickets): ?><p class="muted">No support tickets match this view.</p><?php else: ?>
    <div class="table-scroll">
        <table>
            <thead><tr><th>Ticket number</th><?php if ($canManage): ?><th>Member</th><?php endif; ?><th>Subject</th><th>Category</th><th>Priority</th><th>Submitted (UTC)</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($tickets as $item): ?>
            <tr>
                <td><a href="support.php?ticket=<?= (int) $item['id'] ?>"><?= e($item['reference']) ?></a><?php if ($canManage && $item['acknowledged_at'] === null): ?><span class="support-new-label">New</span><?php endif; ?></td>
                <?php if ($canManage): ?><td><?= e($item['member_name']) ?></td><?php endif; ?>
                <td><?= e($item['subject']) ?></td>
                <td><?= e($categories[$item['category']] ?? ucwords(str_replace('_', ' ', $item['category']))) ?></td>
                <td><span class="support-priority support-priority-<?= e($item['priority']) ?>"><?= e($priorities[$item['priority']] ?? 'Normal') ?></span></td>
                <td><?= e((string) $item['created_at']) ?></td>
                <td><?= e($statuses[$item['status']] ?? ucwords(str_replace('_', ' ', $item['status']))) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (count($tickets) === 100): ?><p class="muted">Showing the latest 100 matching requests. Use the filters to narrow the results.</p><?php endif; ?>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
