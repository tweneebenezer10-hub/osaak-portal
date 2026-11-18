<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = require_login();
$memberId = (int) $user['id'];
$stmt = db()->prepare(
    "SELECT 1 FROM users u JOIN member_profiles mp ON mp.user_id=u.id
     WHERE u.id=? AND u.status='active' AND u.email_verified_at IS NOT NULL
       AND mp.membership_status='active'"
);
$stmt->execute([$memberId]);
$activeMember = (bool) $stmt->fetchColumn();
$canMessage = user_has_permission($user, 'messages.use');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

function message_json(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function message_conversation(int $conversationId, int $memberId): array
{
    $stmt = db()->prepare(
        'SELECT id,member_a,member_b FROM member_conversations
         WHERE id=? AND (member_a=? OR member_b=?)'
    );
    $stmt->execute([$conversationId, $memberId, $memberId]);
    $conversation = $stmt->fetch();
    if (!$conversation) {
        message_json(['error' => 'That private conversation is unavailable.'], 404);
    }
    return $conversation;
}

function message_unread_count(int $memberId): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM member_messages m
         JOIN member_conversations c ON c.id=m.conversation_id
         WHERE (c.member_a=? OR c.member_b=?) AND m.sender_id<>? AND m.read_at IS NULL'
    );
    $stmt->execute([$memberId, $memberId, $memberId]);
    return (int) $stmt->fetchColumn();
}

function message_unread_by_conversation(int $memberId): array
{
    $stmt = db()->prepare(
        'SELECT c.id AS conversation_id,COUNT(*) AS unread_count
         FROM member_messages m
         JOIN member_conversations c ON c.id=m.conversation_id
         WHERE (c.member_a=? OR c.member_b=?) AND m.sender_id<>? AND m.read_at IS NULL
         GROUP BY c.id'
    );
    $stmt->execute([$memberId, $memberId, $memberId]);
    return $stmt->fetchAll();
}

function message_mark_delivered(int $conversationId, int $memberId, array $messages): void
{
    $ids = [];
    foreach ($messages as $message) {
        if ((int) $message['sender_id'] !== $memberId && $message['delivered_at'] === null) {
            $ids[] = (int) $message['id'];
        }
    }
    if (!$ids) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare(
        "UPDATE member_messages SET delivered_at=UTC_TIMESTAMP()
         WHERE conversation_id=? AND sender_id<>? AND delivered_at IS NULL
           AND id IN ({$placeholders})"
    );
    $stmt->execute([$conversationId, $memberId, ...$ids]);
}

function message_rows(int $conversationId, string $condition, int $cursor): array
{
    if (!in_array($condition, ['after', 'before'], true)) {
        throw new LogicException('Invalid message cursor direction.');
    }
    $operator = $condition === 'after' ? '>' : '<';
    $direction = $condition === 'after' ? 'ASC' : 'DESC';
    $stmt = db()->prepare(
        "SELECT m.id,m.conversation_id,m.sender_id,m.body,m.created_at,m.delivered_at,m.read_at,u.full_name AS sender_name
         FROM member_messages m JOIN users u ON u.id=m.sender_id
         WHERE m.conversation_id=? AND m.id {$operator} ?
         ORDER BY m.id {$direction} LIMIT 50"
    );
    $stmt->execute([$conversationId, $cursor]);
    $messages = $stmt->fetchAll();
    return $condition === 'before' ? array_reverse($messages) : $messages;
}

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');
if ($action === 'badge' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $messageCount = $canMessage && $activeMember ? message_unread_count($memberId) : 0;
    $jobCount = 0;
    if (user_has_permission($user, 'jobs.review') || user_has_permission($user, 'jobs.manage')) {
        $jobCount = (int) db()->query(
            "SELECT COUNT(*) FROM association_jobs
             WHERE submission_kind='member' AND review_status='pending'"
        )->fetchColumn();
    } elseif ($activeMember && user_has_permission($user, 'jobs.view')) {
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM association_jobs j
             WHERE j.status='published' AND j.review_status='approved'
               AND (j.closes_at IS NULL OR j.closes_at>UTC_TIMESTAMP())
               AND NOT EXISTS (
                   SELECT 1 FROM job_opportunity_reads r
                   WHERE r.user_id=? AND r.job_id=j.id
               )"
        );
        $stmt->execute([$memberId]);
        $jobCount = (int) $stmt->fetchColumn();
    }
    message_json([
        'unread_count' => $messageCount,
        'unread_conversations' => $canMessage && $activeMember ? message_unread_by_conversation($memberId) : [],
        'job_count' => $jobCount,
    ]);
}
if (!$canMessage || !$activeMember) {
    message_json(['error' => 'Member messaging is unavailable to this account.'], 403);
}
if ($action === 'search' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $query = trim((string) ($_GET['q'] ?? ''));
    if (strlen($query) < 2 || strlen($query) > 80) {
        message_json(['members' => []]);
    }
    $stmt = db()->prepare(
        "SELECT u.id,u.full_name
         FROM users u JOIN member_profiles mp ON mp.user_id=u.id
         WHERE u.id<>? AND u.status='active' AND u.email_verified_at IS NOT NULL
           AND mp.membership_status='active' AND u.full_name LIKE ?
         ORDER BY u.full_name LIMIT 20"
    );
    $stmt->execute([$memberId, '%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%']);
    message_json(['members' => $stmt->fetchAll()]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    message_json(['error' => 'Unsupported messaging request.'], 405);
}
$providedToken = $_POST['csrf_token'] ?? '';
if (!is_string($providedToken) || !hash_equals(csrf_token(), $providedToken)) {
    message_json(['error' => 'This request could not be verified. Reload the page and try again.'], 419);
}

try {
    if ($action === 'sync') {
        $stmt = db()->prepare(
            'SELECT m.id,m.conversation_id,m.sender_id,m.body,m.created_at,m.delivered_at,m.read_at,
                    u.full_name AS sender_name
             FROM member_messages m
             JOIN member_conversations c ON c.id=m.conversation_id
             JOIN users u ON u.id=m.sender_id
             WHERE (c.member_a=? OR c.member_b=?)
               AND m.sender_id<>? AND m.delivered_at IS NULL
             ORDER BY m.id ASC LIMIT 100'
        );
        $stmt->execute([$memberId, $memberId, $memberId]);
        $pending = $stmt->fetchAll();
        $byConversation = [];
        foreach ($pending as $message) {
            $byConversation[(int) $message['conversation_id']][] = $message;
        }
        foreach ($byConversation as $pendingConversationId => $conversationMessages) {
            message_mark_delivered($pendingConversationId, $memberId, $conversationMessages);
        }
        if ($pending) {
            $ids = array_map(static fn(array $message): int => (int) $message['id'], $pending);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = db()->prepare(
                "SELECT m.id,m.conversation_id,m.sender_id,m.body,m.created_at,m.delivered_at,m.read_at,
                        u.full_name AS sender_name
                 FROM member_messages m
                 JOIN member_conversations c ON c.id=m.conversation_id
                 JOIN users u ON u.id=m.sender_id
                 WHERE m.id IN ({$placeholders}) AND (c.member_a=? OR c.member_b=?)
                 ORDER BY m.id ASC"
            );
            $stmt->execute([...$ids, $memberId, $memberId]);
            $pending = $stmt->fetchAll();
        }
        message_json([
            'messages' => $pending,
            'unread_count' => message_unread_count($memberId),
            'unread_conversations' => message_unread_by_conversation($memberId),
        ]);
    }

    if ($action === 'start') {
        $partnerId = filter_var($_POST['member_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$partnerId || (int) $partnerId === $memberId) {
            throw new InvalidArgumentException('Choose another active member.');
        }
        $stmt = db()->prepare(
            "SELECT id FROM users u JOIN member_profiles mp ON mp.user_id=u.id
             WHERE u.id=? AND u.status='active' AND u.email_verified_at IS NOT NULL
               AND mp.membership_status='active'"
        );
        $stmt->execute([(int) $partnerId]);
        if (!$stmt->fetchColumn()) {
            throw new InvalidArgumentException('That member is not available for messaging.');
        }
        $memberA = min($memberId, (int) $partnerId);
        $memberB = max($memberId, (int) $partnerId);
        $stmt = db()->prepare(
            'INSERT INTO member_conversations (member_a,member_b,created_at) VALUES (?,?,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)'
        );
        $stmt->execute([$memberA, $memberB]);
        $conversationId = (int) db()->lastInsertId();
        message_json(['conversation_id' => $conversationId]);
    }

    $conversationId = filter_var($_POST['conversation_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$conversationId) {
        throw new InvalidArgumentException('Choose a valid conversation.');
    }
    $conversation = message_conversation((int) $conversationId, $memberId);

    if ($action === 'send') {
        $body = trim((string) ($_POST['body'] ?? ''));
        $characterCount = strlen($body) <= 16000 ? preg_match_all('/./us', $body) : false;
        if ($body === '' || $characterCount === false || $characterCount > 4000) {
            throw new InvalidArgumentException('Messages must contain 1 to 4000 valid UTF-8 characters.');
        }
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM member_messages
             WHERE sender_id=? AND created_at>=UTC_TIMESTAMP()-INTERVAL 1 MINUTE'
        );
        $stmt->execute([$memberId]);
        if ((int) $stmt->fetchColumn() >= 30) {
            message_json(['error' => 'Message limit reached. Please wait a minute before sending more.'], 429);
        }
        $stmt = db()->prepare(
            'INSERT INTO member_messages (conversation_id,sender_id,body,created_at)
             VALUES (?,?,?,UTC_TIMESTAMP())'
        );
        $stmt->execute([(int) $conversationId, $memberId, $body]);
        $messageId = (int) db()->lastInsertId();
        $stmt = db()->prepare(
            'SELECT m.id,m.conversation_id,m.sender_id,m.body,m.created_at,m.delivered_at,m.read_at,u.full_name AS sender_name
             FROM member_messages m JOIN users u ON u.id=m.sender_id WHERE m.id=?'
        );
        $stmt->execute([$messageId]);
        message_json([
            'message' => $stmt->fetch(),
            'unread_count' => message_unread_count($memberId),
            'unread_conversations' => message_unread_by_conversation($memberId),
        ], 201);
    }

    if ($action === 'poll') {
        $afterId = filter_var($_POST['after_id'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($afterId === false) {
            throw new InvalidArgumentException('The message cursor is invalid.');
        }
        $messages = message_rows((int) $conversationId, 'after', (int) $afterId);
        message_mark_delivered((int) $conversationId, $memberId, $messages);
        if ($messages) {
            $messages = message_rows((int) $conversationId, 'after', (int) $afterId);
        }
        $oldestId = filter_var($_POST['oldest_id'] ?? (string) $afterId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($oldestId === false) {
            throw new InvalidArgumentException('The visible message range is invalid.');
        }
        $stmt = db()->prepare(
            'SELECT id,delivered_at,read_at FROM member_messages
             WHERE conversation_id=? AND sender_id=? AND id>=? AND id<=?
             ORDER BY id ASC'
        );
        $latestId = max((int) $afterId, ...array_map(static fn(array $message): int => (int) $message['id'], $messages));
        $stmt->execute([(int) $conversationId, $memberId, (int) $oldestId, $latestId]);
        message_json([
            'messages' => $messages,
            'status_updates' => $stmt->fetchAll(),
            'unread_count' => message_unread_count($memberId),
            'unread_conversations' => message_unread_by_conversation($memberId),
        ]);
    }

    if ($action === 'read') {
        $rawIds = (string) ($_POST['message_ids'] ?? '');
        $parts = $rawIds === '' ? [] : explode(',', $rawIds);
        if (!$parts || count($parts) > 100) {
            throw new InvalidArgumentException('Choose visible messages to mark as read.');
        }
        $ids = [];
        foreach ($parts as $part) {
            if ($part === '' || !ctype_digit($part) || (int) $part < 1) {
                throw new InvalidArgumentException('The message read receipt is invalid.');
            }
            $ids[] = (int) $part;
        }
        $ids = array_values(array_unique($ids));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare(
            "UPDATE member_messages SET read_at=UTC_TIMESTAMP()
             WHERE conversation_id=? AND sender_id<>? AND read_at IS NULL
               AND id IN ({$placeholders})"
        );
        $stmt->execute([(int) $conversationId, $memberId, ...$ids]);
        $stmt = db()->prepare(
            "SELECT id,delivered_at,read_at FROM member_messages
             WHERE conversation_id=? AND sender_id<>? AND id IN ({$placeholders})"
        );
        $stmt->execute([(int) $conversationId, $memberId, ...$ids]);
        message_json([
            'status_updates' => $stmt->fetchAll(),
            'unread_count' => message_unread_count($memberId),
            'unread_conversations' => message_unread_by_conversation($memberId),
        ]);
    }

    if ($action === 'older') {
        $beforeId = filter_var($_POST['before_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$beforeId) {
            throw new InvalidArgumentException('The older-message cursor is invalid.');
        }
        $messages = message_rows((int) $conversationId, 'before', (int) $beforeId);
        message_mark_delivered((int) $conversationId, $memberId, $messages);
        if ($messages) {
            $messages = message_rows((int) $conversationId, 'before', (int) $beforeId);
        }
        message_json([
            'messages' => $messages,
            'unread_count' => message_unread_count($memberId),
            'unread_conversations' => message_unread_by_conversation($memberId),
        ]);
    }

    throw new InvalidArgumentException('Unknown messaging action.');
} catch (InvalidArgumentException $exception) {
    message_json(['error' => $exception->getMessage()], 422);
}
