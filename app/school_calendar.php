<?php
declare(strict_types=1);

function school_calendar_events_for_user(
    int $schoolId,
    array $user,
    string $rangeStart,
    string $rangeEnd
): array {
    $userId = (int) ($user['id'] ?? 0);
    $role = school_user_role_for_school($userId, $schoolId);
    if ($schoolId < 1 || $userId < 1 || $role === null) {
        return [];
    }
    $supportContext = active_it_support_context($user);
    $canManage = $supportContext === null && in_array($role, ['school_headmaster', 'school_proprietor'], true);
    $classIds = [];
    if ($role === 'class_teacher') {
        $classStmt = db()->prepare(
            'SELECT tca.class_id FROM teacher_class_assignments tca
             JOIN school_classes sc ON sc.id = tca.class_id AND sc.school_id = tca.school_id
             WHERE tca.school_id = ? AND tca.teacher_user_id = ? AND tca.academic_year = sc.academic_year'
        );
        $classStmt->execute([$schoolId, $userId]);
        $classIds = array_map('intval', $classStmt->fetchAll(PDO::FETCH_COLUMN));
    } elseif ($role === 'subject_teacher') {
        $classStmt = db()->prepare('SELECT DISTINCT class_id FROM class_subjects WHERE school_id = ? AND teacher_user_id = ?');
        $classStmt->execute([$schoolId, $userId]);
        $classIds = array_map('intval', $classStmt->fetchAll(PDO::FETCH_COLUMN));
    } elseif ($role === 'school_student') {
        $classStmt = db()->prepare(
            'SELECT ss.class_id FROM school_student_account_links link
             JOIN school_students ss ON ss.id = link.student_id AND ss.school_id = link.school_id
             WHERE link.school_id = ? AND link.user_id = ? AND link.status = "active"
               AND ss.school_status = "active" AND ss.class_id IS NOT NULL'
        );
        $classStmt->execute([$schoolId, $userId]);
        $classIds = array_map('intval', $classStmt->fetchAll(PDO::FETCH_COLUMN));
    } elseif ($role === 'school_parent') {
        $emailStmt = db()->prepare(
            'SELECT DISTINCT student.class_id
             FROM school_parent_links link
             JOIN school_guardians guardian
               ON guardian.id = link.guardian_id AND guardian.school_id = link.school_id
             JOIN school_students student
               ON student.id = link.student_id AND student.school_id = link.school_id
             JOIN users parent_account
               ON parent_account.id = link.user_id AND parent_account.status = "active"
                  AND parent_account.email_verified_at IS NOT NULL
             JOIN school_memberships membership
               ON membership.user_id = link.user_id AND membership.school_id = link.school_id
                  AND membership.role_name = "school_parent" AND membership.status = "active"
             WHERE link.school_id = ? AND link.user_id = ? AND link.status = "verified"
               AND LOWER(TRIM(parent_account.email)) = LOWER(TRIM(guardian.email))
               AND student.school_status = "active" AND student.class_id IS NOT NULL'
        );
        $emailStmt->execute([$schoolId, $userId]);
        $classIds = array_map('intval', $emailStmt->fetchAll(PDO::FETCH_COLUMN));
    }
    $staffCheck = db()->prepare(
        'SELECT 1 FROM school_staff
         WHERE school_id = ? AND user_id = ? AND status = "active" LIMIT 1'
    );
    $staffCheck->execute([$schoolId, $userId]);
    $isStaff = (bool) $staffCheck->fetchColumn();

    $stmt = db()->prepare(
        'SELECT id, school_id, title, category, description, starts_on, ends_on,
                starts_at, ends_at, is_all_day, venue, audience, class_id, status
         FROM school_calendar_events
         WHERE school_id = ? AND starts_on <= ? AND ends_on >= ?'
            . ($canManage ? '' : ' AND status = "published"') . '
         ORDER BY starts_on, starts_at, id'
    );
    $stmt->execute([$schoolId, $rangeEnd, $rangeStart]);
    $events = [];
    foreach ($stmt->fetchAll() as $event) {
        $visible = match ((string) $event['audience']) {
            'school' => true,
            'students' => $role === 'school_student',
            'parents' => $role === 'school_parent',
            'teaching_staff' => in_array($role, ['class_teacher', 'subject_teacher'], true),
            'all_staff' => $isStaff && !in_array($role, ['school_student', 'school_parent'], true),
            'selected_class' => in_array((int) $event['class_id'], $classIds, true),
            default => false,
        };
        if ($canManage || $visible) {
            $events[] = $event;
        }
    }
    return $events;
}

function render_school_calendar_preview(int $schoolId, array $user): void
{
    $today = new DateTimeImmutable('today');
    $events = school_calendar_events_for_user(
        $schoolId,
        $user,
        $today->format('Y-m-d'),
        $today->modify('+30 days')->format('Y-m-d')
    );
    ?>
    <section class="panel panel-top-gap-sm">
        <div class="attendance-header-actions">
            <h2>Upcoming school events</h2>
            <a href="school_calendar.php">Open school calendar</a>
        </div>
        <?php if ($events === []): ?>
            <p class="muted">No upcoming school events.</p>
        <?php else: ?>
            <ul>
                <?php foreach (array_slice($events, 0, 5) as $event): ?>
                    <li>
                        <a href="school_calendar.php?date=<?= e((string) $event['starts_on']) ?>&amp;view=day&amp;event_id=<?= (int) $event['id'] ?>"><?= e((string) $event['title']) ?></a>
                        · <?= e((string) $event['starts_on']) ?>
                        · <?= e(ucfirst(str_replace('_', ' ', (string) $event['category']))) ?>
                        <?= empty($event['is_all_day']) ? ' · ' . e(substr((string) $event['starts_at'], 0, 5)) : ' · All day' ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <?php
}