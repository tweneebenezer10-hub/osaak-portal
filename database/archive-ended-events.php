<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

db()->beginTransaction();
try {
    $stmt = db()->query(
        "SELECT id,title
         FROM association_events
         WHERE status='published'
           AND COALESCE(ends_at,starts_at) <= UTC_TIMESTAMP() - INTERVAL 1 DAY
         FOR UPDATE"
    );
    $events = $stmt->fetchAll();
    if ($events) {
        $eventIds = array_map(static fn(array $event): int => (int) $event['id'], $events);
        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
        $stmt = db()->prepare(
            "UPDATE association_events SET status='completed'
             WHERE id IN ($placeholders) AND status='published'"
        );
        $stmt->execute($eventIds);
        $stmt = db()->prepare(
            "UPDATE association_announcements SET status='archived'
             WHERE linked_event_id IN ($placeholders) AND status='published'"
        );
        $stmt->execute($eventIds);
    }
    db()->commit();
} catch (Throwable $exception) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    throw $exception;
}

$message = sprintf(
    "Archived %d event(s) and linked announcement(s) at %s UTC.",
    count($events),
    gmdate('Y-m-d H:i:s')
);
fwrite(STDOUT, $message . PHP_EOL);
