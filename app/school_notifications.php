<?php
declare(strict_types=1);

function school_notification_create(
    int $schoolId,
    int $recipientUserId,
    ?int $senderUserId,
    string $key,
    string $category,
    string $title,
    string $body,
    ?string $link,
    ?int $classId = null,
    ?int $subjectId = null,
    ?int $assessmentId = null,
    ?string $attendanceDate = null,
    ?string $priority = null,
    ?bool $actionRequired = null
): void {
    $priority ??= $category === 'report_card' ? 'high' : 'normal';
    $actionRequired ??= $category === 'report_card' && $link !== null;
    if ($schoolId < 1 || $recipientUserId < 1
        || !in_array($category, ['attendance', 'assessment', 'report_card', 'other'], true)
        || !in_array($priority, ['critical', 'high', 'normal', 'informational'], true)
        || $key === '' || strlen($key) > 180 || $title === '' || strlen($title) > 180
        || strlen($body) > 1000 || ($link !== null && strlen($link) > 500)) {
        throw new InvalidArgumentException('The school notification payload is invalid.');
    }
    if ($recipientUserId === $senderUserId) {
        return;
    }
    $authorizedRecipient = db()->prepare(
        'SELECT 1 FROM school_memberships membership
         JOIN users recipient ON recipient.id = membership.user_id
         WHERE membership.school_id = ? AND membership.user_id = ?
           AND membership.status = "active" AND recipient.status = "active"
           AND recipient.email_verified_at IS NOT NULL LIMIT 1'
    );
    $authorizedRecipient->execute([$schoolId, $recipientUserId]);
    if (!$authorizedRecipient->fetchColumn()) {
        return;
    }
    $stmt = db()->prepare(
        'INSERT IGNORE INTO school_notifications
         (school_id, recipient_user_id, sender_user_id, notification_key, category,
          priority, title, body, link_url, action_required,
          class_id, subject_id, assessment_id, attendance_date)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $schoolId,
        $recipientUserId,
        $senderUserId,
        $key,
        $category,
        $priority,
        $title,
        $body,
        $link,
        $actionRequired ? 1 : 0,
        $classId,
        $subjectId,
        $assessmentId,
        $attendanceDate,
    ]);
}

function school_notify_roles(
    int $schoolId,
    array $roles,
    ?int $senderUserId,
    string $key,
    string $category,
    string $title,
    string $body,
    ?string $link,
    ?int $classId = null,
    ?int $subjectId = null,
    ?int $assessmentId = null,
    ?string $attendanceDate = null,
    array $excludeUserIds = [],
    ?string $priority = null,
    ?bool $actionRequired = null
): void {
    $roles = array_values(array_unique(array_filter(
        $roles,
        static fn(mixed $role): bool => is_string($role) && preg_match('/^school_[a-z_]+$|^(?:class|subject)_teacher$/', $role) === 1
    )));
    if ($roles === []) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($roles), '?'));
    $sql = 'SELECT DISTINCT membership.user_id
            FROM school_memberships membership
            JOIN users recipient ON recipient.id = membership.user_id
            WHERE membership.school_id = ? AND membership.status = "active"
              AND membership.role_name IN (' . $placeholders . ')
              AND recipient.status = "active" AND recipient.email_verified_at IS NOT NULL';
    $params = array_merge([$schoolId], $roles);
    if ($excludeUserIds !== []) {
        $excludeUserIds = array_values(array_unique(array_filter(array_map('intval', $excludeUserIds), static fn(int $id): bool => $id > 0)));
        if ($excludeUserIds !== []) {
            $sql .= ' AND membership.user_id NOT IN (' . implode(',', array_fill(0, count($excludeUserIds), '?')) . ')';
            $params = array_merge($params, $excludeUserIds);
        }
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $recipientId) {
        school_notification_create(
            $schoolId,
            (int) $recipientId,
            $senderUserId,
            $key,
            $category,
            $title,
            $body,
            $link,
            $classId,
            $subjectId,
            $assessmentId,
            $attendanceDate,
            $priority,
            $actionRequired
        );
    }
}

function school_notification_recent(
    int $schoolId,
    int $userId,
    int $limit = 5,
    int $offset = 0,
    string $filter = 'all'
): array
{
    if ($schoolId < 1 || $userId < 1
        || !in_array($filter, ['all', 'unread', 'read', 'action', 'priority', 'archived'], true)) {
        return [];
    }
    $limit = max(1, min(100, $limit));
    $offset = max(0, $offset);
    $whereFilter = match ($filter) {
        'unread' => ' AND notification.archived_at IS NULL AND notification.read_at IS NULL',
        'read' => ' AND notification.archived_at IS NULL AND notification.read_at IS NOT NULL',
        'action' => ' AND notification.archived_at IS NULL AND notification.action_required = 1 AND notification.acknowledged_at IS NULL',
        'priority' => ' AND notification.archived_at IS NULL AND notification.priority IN ("critical", "high")',
        'archived' => ' AND notification.archived_at IS NOT NULL',
        default => ' AND notification.archived_at IS NULL',
    };
    $stmt = db()->prepare(
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
         WHERE notification.school_id = ? AND notification.recipient_user_id = ?' . $whereFilter . '
         ORDER BY notification.created_at DESC, notification.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset
    );
    $stmt->execute([$schoolId, $userId]);
    return $stmt->fetchAll();
}

function school_notification_count(int $schoolId, int $userId, string $filter = 'all'): int
{
    if ($schoolId < 1 || $userId < 1
        || !in_array($filter, ['all', 'unread', 'read', 'action', 'priority', 'archived'], true)) {
        return 0;
    }
    $whereFilter = match ($filter) {
        'unread' => ' AND archived_at IS NULL AND read_at IS NULL',
        'read' => ' AND archived_at IS NULL AND read_at IS NOT NULL',
        'action' => ' AND archived_at IS NULL AND action_required = 1 AND acknowledged_at IS NULL',
        'priority' => ' AND archived_at IS NULL AND priority IN ("critical", "high")',
        'archived' => ' AND archived_at IS NOT NULL',
        default => ' AND archived_at IS NULL',
    };
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM school_notifications
         WHERE school_id = ? AND recipient_user_id = ?' . $whereFilter
    );
    $stmt->execute([$schoolId, $userId]);
    return (int) $stmt->fetchColumn();
}

function school_notification_unread_count(int $schoolId, int $userId): int
{
    if ($schoolId < 1 || $userId < 1) {
        return 0;
    }
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM school_notifications
         WHERE school_id = ? AND recipient_user_id = ? AND read_at IS NULL AND archived_at IS NULL'
    );
    $stmt->execute([$schoolId, $userId]);
    return (int) $stmt->fetchColumn();
}
