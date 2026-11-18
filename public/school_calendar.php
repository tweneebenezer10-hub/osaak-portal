<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = require_school_context();
$school = active_school_context();
$schoolId = (int) $school['id'];
$role = (string) ($user['active_school_role'] ?? school_user_role_for_school((int) $user['id'], $schoolId));
$supportContext = active_it_support_context($user);
$canManage = $supportContext === null && in_array($role, ['school_headmaster', 'school_proprietor'], true);
$dateInput = (string) ($_GET['date'] ?? date('Y-m-d'));
$selectedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $dateInput);
if (!$selectedDate || $selectedDate->format('Y-m-d') !== $dateInput) {
    $selectedDate = new DateTimeImmutable('today');
}
$view = (string) ($_GET['view'] ?? 'month');
if (!in_array($view, ['month', 'week', 'day', 'agenda'], true)) {
    $view = 'month';
}
$viewStart = match ($view) {
    'week' => $selectedDate->modify('monday this week'),
    'day' => $selectedDate,
    'agenda' => new DateTimeImmutable('today'),
    default => $selectedDate->modify('first day of this month'),
};
$viewEnd = match ($view) {
    'week' => $viewStart->modify('+6 days'),
    'day' => $selectedDate,
    'agenda' => (new DateTimeImmutable('today'))->modify('+30 days'),
    default => $viewStart->modify('last day of this month'),
};
$categories = [
    'examination' => 'Examination',
    'examination_week' => 'Examination Week',
    'pta_meeting' => 'PTA Meeting',
    'staff_meeting' => 'Staff Meeting',
    'assembly' => 'School Assembly',
    'sports_culture' => 'Sports and Cultural Activities',
    'holiday' => 'Holiday or Vacation',
    'public_holiday' => 'Public Holiday',
    'school_closure' => 'School Closure',
    'reopening' => 'School Reopening',
    'closing' => 'School Closing',
    'graduation' => 'Graduation or Speech Day',
    'other' => 'Other',
];
$audiences = [
    'school' => 'Entire school',
    'students' => 'Students',
    'parents' => 'Parents and guardians',
    'teaching_staff' => 'Teaching staff',
    'all_staff' => 'All staff',
    'selected_class' => 'Selected class',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (!$canManage) {
        http_response_code(403);
        exit('Only the Headmaster or Proprietor can manage school calendar events.');
    }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'set_event_status') {
        $eventId = filter_var($_POST['event_id'] ?? null, FILTER_VALIDATE_INT);
        $newStatus = (string) ($_POST['status'] ?? '');
        if ($eventId === false || $eventId < 1 || !in_array($newStatus, ['draft', 'published', 'cancelled'], true)) {
            flash('error', 'Choose a valid calendar event and status.');
            redirect('school_calendar.php');
        }
        $eventStmt = db()->prepare('SELECT title, status, category, starts_on, ends_on FROM school_calendar_events WHERE id = ? AND school_id = ? LIMIT 1');
        $eventStmt->execute([$eventId, $schoolId]);
        $existingEvent = $eventStmt->fetch();
        if (!$existingEvent) {
            flash('error', 'The calendar event was not found in this school.');
            redirect('school_calendar.php');
        }
        $attendanceConflictCount = 0;
        $attendanceConflictReason = trim((string) ($_POST['attendance_conflict_reason'] ?? ''));
        if ($existingEvent['status'] === 'published'
            && in_array($existingEvent['category'], ['holiday', 'public_holiday', 'school_closure'], true)
            && $newStatus !== 'published') {
            $conflictStmt = db()->prepare(
                'SELECT (SELECT COUNT(*) FROM student_attendance WHERE school_id = ? AND attendance_date BETWEEN ? AND ?)
                     + (SELECT COUNT(*) FROM school_staff_attendance WHERE school_id = ? AND attendance_date BETWEEN ? AND ?)'
            );
            $conflictStmt->execute([$schoolId, $existingEvent['starts_on'], $existingEvent['ends_on'], $schoolId, $existingEvent['starts_on'], $existingEvent['ends_on']]);
            $attendanceConflictCount = (int) $conflictStmt->fetchColumn();
            if ($attendanceConflictCount > 0
                && (($_POST['confirm_attendance_conflict'] ?? '') !== '1'
                    || $attendanceConflictReason === '' || strlen($attendanceConflictReason) > 500)) {
                flash('error', 'Attendance exists during this closure. Confirm the records will be preserved and provide a reason before unpublishing or cancelling it.');
                redirect('school_calendar.php?date=' . rawurlencode((string) $existingEvent['starts_on']) . '&view=month');
            }
        }
        $update = db()->prepare(
            'UPDATE school_calendar_events
             SET status = ?, updated_by = ?, cancelled_at = IF(? = "cancelled", NOW(), NULL)
             WHERE id = ? AND school_id = ?'
        );
        $update->execute([$newStatus, (int) $user['id'], $newStatus, $eventId, $schoolId]);
        audit((int) $user['id'], 'school_calendar_event_status_changed', 'school_id=' . $schoolId . ';event_id=' . $eventId . ';from=' . $existingEvent['status'] . ';to=' . $newStatus . ';attendance_conflicts=' . $attendanceConflictCount . ';reason=' . $attendanceConflictReason);
        if (($newStatus === 'published' && $existingEvent['status'] !== 'published')
            || ($existingEvent['status'] === 'published' && $newStatus !== 'published')) {
            $eventAudienceStmt = db()->prepare('SELECT audience, class_id, starts_on FROM school_calendar_events WHERE id = ? AND school_id = ?');
            $eventAudienceStmt->execute([$eventId, $schoolId]);
            $eventAudience = $eventAudienceStmt->fetch();
            $changeType = $newStatus === 'published' ? 'published' : ($newStatus === 'cancelled' ? 'cancelled' : 'unpublished');
            school_calendar_notify_audience($schoolId, $eventId, $existingEvent['title'], $eventAudience, (int) $user['id'], $changeType);
        }
        flash('success', $newStatus === 'cancelled' ? 'Calendar event cancelled.' : 'Calendar event status updated.');
        redirect('school_calendar.php?date=' . rawurlencode((string) ($_POST['return_date'] ?? date('Y-m-d'))) . '&view=' . rawurlencode((string) ($_POST['return_view'] ?? 'month')));
    }

    if ($action === 'save_event') {
        $eventId = filter_var($_POST['event_id'] ?? null, FILTER_VALIDATE_INT);
        $title = trim((string) ($_POST['title'] ?? ''));
        $category = (string) ($_POST['category'] ?? 'other');
        $description = trim((string) ($_POST['description'] ?? ''));
        $startsOn = trim((string) ($_POST['starts_on'] ?? ''));
        $endsOn = trim((string) ($_POST['ends_on'] ?? ''));
        $startsAt = trim((string) ($_POST['starts_at'] ?? ''));
        $endsAt = trim((string) ($_POST['ends_at'] ?? ''));
        $isAllDay = ($_POST['is_all_day'] ?? '') === '1';
        $venue = trim((string) ($_POST['venue'] ?? ''));
        $isPublic = ($_POST['is_public'] ?? '') === '1';
        $audience = (string) ($_POST['audience'] ?? 'school');
        $classId = filter_var($_POST['class_id'] ?? null, FILTER_VALIDATE_INT);
        $status = (string) ($_POST['status'] ?? 'draft');
        $reminder = ($_POST['reminder_minutes'] ?? '') === '' ? null : filter_var($_POST['reminder_minutes'], FILTER_VALIDATE_INT);
        $startDate = DateTimeImmutable::createFromFormat('!Y-m-d', $startsOn);
        $endDate = DateTimeImmutable::createFromFormat('!Y-m-d', $endsOn);
        $validStartTime = $startsAt === '' || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $startsAt) === 1;
        $validEndTime = $endsAt === '' || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $endsAt) === 1;
        if ($title === '' || strlen($title) > 180 || !isset($categories[$category])
            || strlen($description) > 10000 || strlen($venue) > 180
            || !$startDate || $startDate->format('Y-m-d') !== $startsOn
            || !$endDate || $endDate->format('Y-m-d') !== $endsOn || $endsOn < $startsOn
            || !$validStartTime || !$validEndTime
            || (!$isAllDay && ($startsAt === '' || $endsAt === ''))
            || (!$isAllDay && $endsOn === $startsOn && $endsAt < $startsAt)
            || !isset($audiences[$audience])
            || !in_array($status, ['draft', 'published'], true)
            || ($reminder !== null && ($reminder === false || $reminder < 0 || $reminder > 10080))) {
            flash('error', 'Check the event title, category, dates, times, audience, and reminder settings.');
            redirect('school_calendar.php');
        }
        if ($audience === 'selected_class') {
            if ($classId === false || $classId < 1) {
                flash('error', 'Choose a class for this event audience.');
                redirect('school_calendar.php');
            }
            $classCheck = db()->prepare('SELECT 1 FROM school_classes WHERE id = ? AND school_id = ? LIMIT 1');
            $classCheck->execute([$classId, $schoolId]);
            if (!$classCheck->fetchColumn()) {
                flash('error', 'The selected class does not belong to this school.');
                redirect('school_calendar.php');
            }
        } else {
            $classId = null;
        }
        if (in_array($category, ['holiday', 'public_holiday', 'school_closure'], true) && $audience !== 'school') {
            flash('error', 'Official holidays and school closures must apply to the entire school.');
            redirect('school_calendar.php');
        }
        $attendanceConflictCount = 0;
        $attendanceConflictReason = trim((string) ($_POST['attendance_conflict_reason'] ?? ''));
        $closureRangesToReview = [];
        if ($status === 'published' && in_array($category, ['holiday', 'public_holiday', 'school_closure'], true)) {
            $closureRangesToReview[] = [$startsOn, $endsOn];
        }
        if ($eventId !== false && $eventId > 0) {
            $oldClosureStmt = db()->prepare(
                'SELECT status, category, starts_on, ends_on FROM school_calendar_events
                 WHERE id = ? AND school_id = ? LIMIT 1'
            );
            $oldClosureStmt->execute([$eventId, $schoolId]);
            $oldClosure = $oldClosureStmt->fetch();
            if ($oldClosure && $oldClosure['status'] === 'published'
                && in_array($oldClosure['category'], ['holiday', 'public_holiday', 'school_closure'], true)
                && ($status !== 'published'
                    || !in_array($category, ['holiday', 'public_holiday', 'school_closure'], true)
                    || (string) $oldClosure['starts_on'] !== $startsOn
                    || (string) $oldClosure['ends_on'] !== $endsOn)) {
                $closureRangesToReview[] = [(string) $oldClosure['starts_on'], (string) $oldClosure['ends_on']];
            }
        }
        if ($closureRangesToReview !== []) {
            $conflictStmt = db()->prepare(
                'SELECT (SELECT COUNT(*) FROM student_attendance
                         WHERE school_id = ? AND attendance_date BETWEEN ? AND ?)
                     + (SELECT COUNT(*) FROM school_staff_attendance
                         WHERE school_id = ? AND attendance_date BETWEEN ? AND ?)'
            );
            foreach ($closureRangesToReview as [$conflictStart, $conflictEnd]) {
                $conflictStmt->execute([$schoolId, $conflictStart, $conflictEnd, $schoolId, $conflictStart, $conflictEnd]);
                $attendanceConflictCount += (int) $conflictStmt->fetchColumn();
            }
            if ($attendanceConflictCount > 0
                && (($_POST['confirm_attendance_conflict'] ?? '') !== '1'
                    || $attendanceConflictReason === '' || strlen($attendanceConflictReason) > 500)) {
                flash('error', 'Attendance already exists during this closure. Confirm that records will be preserved and provide a review reason before publishing.');
                redirect('school_calendar.php?date=' . rawurlencode($startsOn) . '&view=month');
            }
        }
        if ($eventId !== false && $eventId > 0) {
            $existingStmt = db()->prepare('SELECT * FROM school_calendar_events WHERE id = ? AND school_id = ? LIMIT 1');
            $existingStmt->execute([$eventId, $schoolId]);
            $existingEvent = $existingStmt->fetch();
            if (!$existingEvent || $existingEvent['status'] === 'cancelled') {
                flash('error', 'This calendar event cannot be edited.');
                redirect('school_calendar.php');
            }
            $newValues = [
                'title' => $title,
                'category' => $category,
                'description' => $description === '' ? null : $description,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'starts_at' => $isAllDay || $startsAt === '' ? null : $startsAt . ':00',
                'ends_at' => $isAllDay || $endsAt === '' ? null : $endsAt . ':00',
                'is_all_day' => $isAllDay ? 1 : 0,
                'venue' => $venue === '' ? null : $venue,
                'audience' => $audience,
                'is_public' => $isPublic ? 1 : 0,
                'class_id' => $classId,
                'status' => $status,
                'reminder_minutes' => $reminder,
            ];
            $changedFields = [];
            foreach ($newValues as $field => $value) {
                if ((string) ($existingEvent[$field] ?? '') !== (string) ($value ?? '')) {
                    $changedFields[] = $field;
                }
            }
            $save = db()->prepare(
                'UPDATE school_calendar_events SET title = ?, category = ?, description = ?,
                    starts_on = ?, ends_on = ?, starts_at = ?, ends_at = ?, is_all_day = ?,
                    venue = ?, audience = ?, is_public = ?, class_id = ?, status = ?, reminder_minutes = ?, updated_by = ?
                 WHERE id = ? AND school_id = ?'
            );
            $save->execute([
                $title, $category, $description === '' ? null : $description,
                $startsOn, $endsOn, $isAllDay || $startsAt === '' ? null : $startsAt . ':00',
                $isAllDay || $endsAt === '' ? null : $endsAt . ':00', $isAllDay ? 1 : 0,
                $venue === '' ? null : $venue, $audience, $isPublic ? 1 : 0, $classId, $status, $reminder,
                (int) $user['id'], $eventId, $schoolId,
            ]);
            $savedEventId = $eventId;
            $shouldNotify = $status === 'published'
                && ($existingEvent['status'] !== 'published' || $changedFields !== []);
            $notificationChange = $existingEvent['status'] === 'published' ? 'updated' : 'published';
            if ($changedFields !== []) {
                audit((int) $user['id'], 'school_calendar_event_updated', 'school_id=' . $schoolId . ';event_id=' . $eventId . ';changed_fields=' . implode(',', $changedFields) . ';attendance_conflicts=' . $attendanceConflictCount . ';reason=' . $attendanceConflictReason);
            }
        } else {
            $save = db()->prepare(
                'INSERT INTO school_calendar_events
                 (school_id, title, category, description, starts_on, ends_on, starts_at, ends_at,
                  is_all_day, venue, audience, is_public, class_id, status, reminder_minutes, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $save->execute([
                $schoolId, $title, $category, $description === '' ? null : $description,
                $startsOn, $endsOn, $isAllDay || $startsAt === '' ? null : $startsAt . ':00',
                $isAllDay || $endsAt === '' ? null : $endsAt . ':00', $isAllDay ? 1 : 0,
                $venue === '' ? null : $venue, $audience, $isPublic ? 1 : 0, $classId, $status, $reminder,
                (int) $user['id'], (int) $user['id'],
            ]);
            $savedEventId = (int) db()->lastInsertId();
            audit((int) $user['id'], 'school_calendar_event_created', 'school_id=' . $schoolId . ';event_id=' . $savedEventId . ';title=' . $title . ';status=' . $status . ';is_public=' . ($isPublic ? '1' : '0') . ';attendance_conflicts=' . $attendanceConflictCount . ';reason=' . $attendanceConflictReason);
            $shouldNotify = $status === 'published';
            $notificationChange = 'published';
        }
        if ($shouldNotify) {
            school_calendar_notify_audience($schoolId, $savedEventId, $title, ['audience' => $audience, 'class_id' => $classId, 'starts_on' => $startsOn], (int) $user['id'], $notificationChange);
        }
        flash('success', $status === 'published' ? 'School event published.' : 'School event saved as a draft.');
        redirect('school_calendar.php?date=' . rawurlencode($startsOn) . '&view=month');
    }
    flash('error', 'Choose a valid calendar action.');
    redirect('school_calendar.php');
}

$rangeStart = $viewStart->format('Y-m-d');
$rangeEnd = $viewEnd->format('Y-m-d');
$events = school_calendar_events_for_user($schoolId, $user, $rangeStart, $rangeEnd);
$upcomingEvents = school_calendar_events_for_user(
    $schoolId,
    $user,
    (new DateTimeImmutable('today'))->format('Y-m-d'),
    (new DateTimeImmutable('today'))->modify('+30 days')->format('Y-m-d')
);
$upcomingEvents = array_slice(array_values(array_filter(
    $upcomingEvents,
    static fn (array $event): bool => (string) $event['ends_on'] >= date('Y-m-d')
)), 0, 5);
$editEvent = null;
$editId = filter_var($_GET['edit_id'] ?? null, FILTER_VALIDATE_INT);
if ($canManage && $editId !== false && $editId > 0) {
    $editStmt = db()->prepare('SELECT * FROM school_calendar_events WHERE id = ? AND school_id = ? AND status <> "cancelled" LIMIT 1');
    $editStmt->execute([$editId, $schoolId]);
    $editEvent = $editStmt->fetch() ?: null;
}
$classesStmt = db()->prepare('SELECT id, level, section, academic_year FROM school_classes WHERE school_id = ? AND status = "active" ORDER BY academic_year DESC, level, section');
$classesStmt->execute([$schoolId]);
$calendarClasses = $classesStmt->fetchAll();
$selectedEventId = filter_var($_GET['event_id'] ?? null, FILTER_VALIDATE_INT);
$selectedEvent = null;
if ($selectedEventId !== false && $selectedEventId > 0) {
    foreach ($events as $event) {
        if ((int) $event['id'] === $selectedEventId) {
            $selectedEvent = $event;
            break;
        }
    }
}

$monthGridDates = [];
$calendarEventsByDate = [];
if ($view === 'month') {
    $monthFirst = $selectedDate->modify('first day of this month');
    $gridStart = $monthFirst->modify('monday this week');
    for ($offset = 0; $offset < 42; $offset++) {
        $gridDate = $gridStart->modify('+' . $offset . ' days');
        $dateKey = $gridDate->format('Y-m-d');
        $monthGridDates[] = ['date' => $dateKey, 'day' => $gridDate->format('j'), 'in_month' => $gridDate->format('Y-m') === $selectedDate->format('Y-m')];
    }
    foreach ($events as $event) {
        $eventFirst = new DateTimeImmutable((string) $event['starts_on']);
        $eventLast = new DateTimeImmutable((string) $event['ends_on']);
        foreach ($monthGridDates as $gridDate) {
            if ($gridDate['date'] >= $eventFirst->format('Y-m-d') && $gridDate['date'] <= $eventLast->format('Y-m-d')) {
                $calendarEventsByDate[$gridDate['date']][] = $event;
            }
        }
    }
}

$previousDate = match ($view) {
    'week' => $selectedDate->modify('-1 week'),
    'day' => $selectedDate->modify('-1 day'),
    'agenda' => $selectedDate->modify('-1 month'),
    default => $selectedDate->modify('-1 month'),
};
$nextDate = match ($view) {
    'week' => $selectedDate->modify('+1 week'),
    'day' => $selectedDate->modify('+1 day'),
    'agenda' => $selectedDate->modify('+1 month'),
    default => $selectedDate->modify('+1 month'),
};

render_header('School calendar');
?>
<header class="panel attendance-page-heading"><div><p class="eyebrow">SCHOOL CALENDAR</p><h1>Calendar</h1></div>
    <nav class="attendance-header-actions" aria-label="Calendar view and navigation">
        <a class="button" href="school_calendar.php?date=<?= e($previousDate->format('Y-m-d')) ?>&amp;view=<?= e($view) ?>" aria-label="Previous period">←</a>
        <a class="button" href="school_calendar.php?date=<?= e(date('Y-m-d')) ?>&amp;view=<?= e($view) ?>">Today</a>
        <a class="button" href="school_calendar.php?date=<?= e($nextDate->format('Y-m-d')) ?>&amp;view=<?= e($view) ?>" aria-label="Next period">→</a>
        <form method="get" class="inline-form"><input type="hidden" name="view" value="<?= e($view) ?>"><label for="calendar-date">Date</label><input id="calendar-date" name="date" type="date" value="<?= e($selectedDate->format('Y-m-d')) ?>"><button class="button" type="submit">Go</button></form>
    </nav>
</header>
<nav class="attendance-period-tabs" aria-label="Calendar view">
    <?php foreach (['month' => 'Month', 'week' => 'Week', 'day' => 'Day', 'agenda' => 'Agenda'] as $viewKey => $viewLabel): ?><a class="attendance-period-tab<?= $view === $viewKey ? ' is-active' : '' ?>" href="school_calendar.php?date=<?= e($selectedDate->format('Y-m-d')) ?>&amp;view=<?= e($viewKey) ?>" <?= $view === $viewKey ? 'aria-current="page"' : '' ?>><?= e($viewLabel) ?></a><?php endforeach; ?>
</nav>
<?php if ($selectedEvent): ?><section class="panel panel-top-gap-sm"><h2><?= e((string) $selectedEvent['title']) ?></h2><p><?= e($categories[$selectedEvent['category']] ?? ucfirst((string) $selectedEvent['category'])) ?> · <?= e((string) $selectedEvent['starts_on']) ?><?= $selectedEvent['ends_on'] !== $selectedEvent['starts_on'] ? ' to ' . e((string) $selectedEvent['ends_on']) : '' ?><?= !$selectedEvent['is_all_day'] ? ' · ' . e(substr((string) $selectedEvent['starts_at'], 0, 5)) . '–' . e(substr((string) $selectedEvent['ends_at'], 0, 5)) : ' · All day' ?></p><?php if ($selectedEvent['venue']): ?><p>Venue: <?= e((string) $selectedEvent['venue']) ?></p><?php endif; ?><?php if ($selectedEvent['description']): ?><p><?= nl2br(e((string) $selectedEvent['description'])) ?></p><?php endif; ?><?php if ($canManage && $selectedEvent['status'] !== 'cancelled'): ?><nav class="attendance-header-actions"><a class="button" href="school_calendar.php?date=<?= e($selectedDate->format('Y-m-d')) ?>&amp;view=<?= e($view) ?>&amp;edit_id=<?= (int) $selectedEvent['id'] ?>">Edit</a><?php foreach (['draft' => 'Unpublish', 'published' => 'Publish'] as $statusValue => $statusLabel): ?><?php if ($selectedEvent['status'] !== $statusValue): ?><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="set_event_status"><input type="hidden" name="event_id" value="<?= (int) $selectedEvent['id'] ?>"><input type="hidden" name="status" value="<?= e($statusValue) ?>"><input type="hidden" name="return_date" value="<?= e($selectedDate->format('Y-m-d')) ?>"><input type="hidden" name="return_view" value="<?= e($view) ?>"><label><input type="checkbox" name="confirm_attendance_conflict" value="1"> Preserve attendance if present</label><input name="attendance_conflict_reason" maxlength="500" placeholder="Reason if attendance exists"><button class="button" type="submit"><?= e($statusLabel) ?></button></form><?php endif; ?><?php endforeach; ?></nav><?php endif; ?></section><?php endif; ?>

<section class="panel panel-top-gap-sm"><h2><?= e($viewStart->format('M j, Y')) ?><?= $rangeStart !== $rangeEnd ? ' – ' . e($viewEnd->format('M j, Y')) : '' ?></h2>
    <?php if ($view === 'month'): ?>
        <div class="school-calendar-grid" role="grid" aria-label="<?= e($selectedDate->format('F Y')) ?> calendar">
            <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $weekday): ?><div class="school-calendar-weekday" role="columnheader"><?= e($weekday) ?></div><?php endforeach; ?>
            <?php foreach ($monthGridDates as $gridDate): ?><div class="school-calendar-day<?= $gridDate['in_month'] ? '' : ' is-outside-month' ?><?= $gridDate['date'] === date('Y-m-d') ? ' is-today' : '' ?>" role="gridcell">
                <a class="school-calendar-day-number" href="school_calendar.php?date=<?= e($gridDate['date']) ?>&amp;view=day"><?= e($gridDate['day']) ?></a>
                <?php foreach (array_slice($calendarEventsByDate[$gridDate['date']] ?? [], 0, 3) as $calendarEvent): ?><a class="school-calendar-event" href="school_calendar.php?date=<?= e($gridDate['date']) ?>&amp;view=day&amp;event_id=<?= (int) $calendarEvent['id'] ?>"><?= e((string) $calendarEvent['title']) ?></a><?php endforeach; ?>
                <?php if (count($calendarEventsByDate[$gridDate['date']] ?? []) > 3): ?><span class="muted">+<?= count($calendarEventsByDate[$gridDate['date']]) - 3 ?> more</span><?php endif; ?>
            </div><?php endforeach; ?>
        </div>
        <?php if ($events !== []): ?><div class="school-calendar-agenda-mobile"><h3>Events this month</h3><ul><?php foreach ($events as $event): ?><li><a href="school_calendar.php?date=<?= e((string) $event['starts_on']) ?>&amp;view=day&amp;event_id=<?= (int) $event['id'] ?>"><?= e((string) $event['title']) ?></a> · <?= e((string) $event['starts_on']) ?><?= $event['ends_on'] !== $event['starts_on'] ? ' – ' . e((string) $event['ends_on']) : '' ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <?php endif; ?>
    <?php if ($events === []): ?><p class="muted">No published school events in this period.</p><?php elseif ($view !== 'month'): ?><div class="table-scroll"><table><thead><tr><th>Event</th><th>Category</th><th>Date</th><th>Time</th><th>Venue</th><?php if ($canManage): ?><th>Status</th><th>Manage</th><?php endif; ?></tr></thead><tbody>
        <?php foreach ($events as $event): ?><tr><th><a href="school_calendar.php?date=<?= e($selectedDate->format('Y-m-d')) ?>&amp;view=<?= e($view) ?>&amp;event_id=<?= (int) $event['id'] ?>"><?= e((string) $event['title']) ?></a></th><td><?= e($categories[$event['category']] ?? ucfirst((string) $event['category'])) ?></td><td><?= e((string) $event['starts_on']) ?><?= $event['ends_on'] !== $event['starts_on'] ? ' – ' . e((string) $event['ends_on']) : '' ?></td><td><?= $event['is_all_day'] ? 'All day' : e(substr((string) $event['starts_at'], 0, 5) . '–' . substr((string) $event['ends_at'], 0, 5)) ?></td><td><?= e((string) ($event['venue'] ?: '—')) ?></td><?php if ($canManage): ?><td><?= e(ucfirst((string) $event['status'])) ?></td><td><?php if ($event['status'] !== 'cancelled'): ?><a href="school_calendar.php?date=<?= e($selectedDate->format('Y-m-d')) ?>&amp;view=<?= e($view) ?>&amp;edit_id=<?= (int) $event['id'] ?>">Edit</a><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="set_event_status"><input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>"><input type="hidden" name="return_date" value="<?= e($selectedDate->format('Y-m-d')) ?>"><input type="hidden" name="return_view" value="<?= e($view) ?>"><input type="hidden" name="status" value="cancelled"><input type="hidden" name="confirm_attendance_conflict" value="1"><input name="attendance_conflict_reason" maxlength="500" placeholder="Reason if attendance exists" aria-label="Reason if attendance exists"><button class="button button-small" type="submit" data-confirm="Cancel this school event? Existing attendance will be preserved.">Cancel</button></form><?php endif; ?></td><?php endif; ?></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</section>

<section class="panel panel-top-gap-sm"><h2>Upcoming events</h2><?php if ($upcomingEvents === []): ?><p class="muted">No upcoming events.</p><?php else: ?><ul><?php foreach ($upcomingEvents as $event): ?><li><a href="school_calendar.php?date=<?= e((string) $event['starts_on']) ?>&amp;view=day&amp;event_id=<?= (int) $event['id'] ?>"><?= e((string) $event['title']) ?></a> · <?= e((string) $event['starts_on']) ?> · <?= e($categories[$event['category']] ?? ucfirst((string) $event['category'])) ?></li><?php endforeach; ?></ul><?php endif; ?></section>

<?php if ($canManage): ?>
<section class="panel panel-top-gap-sm"><h2><?= $editEvent ? 'Edit event' : 'Add event' ?></h2>
    <form method="post" class="form-stack" action="school_calendar.php?date=<?= e($selectedDate->format('Y-m-d')) ?>&amp;view=<?= e($view) ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_event"><input type="hidden" name="event_id" value="<?= (int) ($editEvent['id'] ?? 0) ?>">
        <div class="form-grid two-columns">
            <div><label for="event-title">Title</label><input id="event-title" name="title" maxlength="180" required value="<?= e((string) ($editEvent['title'] ?? '')) ?>"></div>
            <div><label for="event-category">Category</label><select id="event-category" name="category"><?php foreach ($categories as $key => $label): ?><option value="<?= e($key) ?>" <?= ($editEvent['category'] ?? 'other') === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            <div><label for="event-start">Start date</label><input id="event-start" name="starts_on" type="date" required value="<?= e((string) ($editEvent['starts_on'] ?? $selectedDate->format('Y-m-d'))) ?>"></div>
            <div><label for="event-end">End date</label><input id="event-end" name="ends_on" type="date" required value="<?= e((string) ($editEvent['ends_on'] ?? $selectedDate->format('Y-m-d'))) ?>"></div>
            <fieldset class="form-grid two-columns" data-event-time-fields <?= !empty($editEvent['is_all_day']) || !$editEvent ? 'hidden' : '' ?>><legend>Event time</legend>
                <div><label for="event-start-time">Start time</label><input id="event-start-time" name="starts_at" type="time" value="<?= e(substr((string) ($editEvent['starts_at'] ?? ''), 0, 5)) ?>" <?= empty($editEvent['is_all_day']) && $editEvent ? 'required' : '' ?>></div>
                <div><label for="event-end-time">End time</label><input id="event-end-time" name="ends_at" type="time" value="<?= e(substr((string) ($editEvent['ends_at'] ?? ''), 0, 5)) ?>" <?= empty($editEvent['is_all_day']) && $editEvent ? 'required' : '' ?>></div>
            </fieldset>
            <div><label for="event-venue">Venue</label><input id="event-venue" name="venue" maxlength="180" value="<?= e((string) ($editEvent['venue'] ?? '')) ?>"></div>
            <div><label for="event-audience">Audience</label><select id="event-audience" name="audience"><?php foreach ($audiences as $key => $label): ?><option value="<?= e($key) ?>" <?= ($editEvent['audience'] ?? 'school') === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            <div><label><input type="checkbox" name="is_public" value="1" <?= !empty($editEvent['is_public']) ? 'checked' : '' ?>> Show on the anonymous public school calendar</label><p class="muted">Only events explicitly checked here and published will appear to visitors. The audience setting does not make an event public.</p></div>
            <div><label for="event-class">Selected class</label><select id="event-class" name="class_id"><option value="">Choose class when needed</option><?php foreach ($calendarClasses as $calendarClass): ?><option value="<?= (int) $calendarClass['id'] ?>" <?= (int) ($editEvent['class_id'] ?? 0) === (int) $calendarClass['id'] ? 'selected' : '' ?>><?= e(school_class_label($calendarClass)) ?> · <?= e((string) $calendarClass['academic_year']) ?></option><?php endforeach; ?></select></div>
            <div><label for="event-reminder">Reminder (minutes before)</label><input id="event-reminder" name="reminder_minutes" type="number" min="0" max="10080" value="<?= e((string) ($editEvent['reminder_minutes'] ?? '')) ?>"></div>
            <div><label><input type="checkbox" name="is_all_day" value="1" data-all-day-toggle <?= !empty($editEvent['is_all_day']) || !$editEvent ? 'checked' : '' ?>> All-day event</label></div>
            <div><label for="event-status">Save as</label><select id="event-status" name="status"><option value="draft" <?= ($editEvent['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Draft</option><option value="published" <?= ($editEvent['status'] ?? '') === 'published' ? 'selected' : '' ?>>Published</option></select></div>
            <div class="span-full"><label for="event-description">Description</label><textarea id="event-description" name="description" rows="4" maxlength="10000"><?= e((string) ($editEvent['description'] ?? '')) ?></textarea></div>
            <div class="span-full"><label class="check-label"><input type="checkbox" name="confirm_attendance_conflict" value="1"> I have reviewed attendance already recorded during this holiday or closure; records will be preserved.</label><label for="event-conflict-reason">Review reason if attendance exists</label><input id="event-conflict-reason" name="attendance_conflict_reason" maxlength="500"></div>
        </div>
        <button class="button button-primary" type="submit">Save event</button>
    </form>
</section>
<?php endif; ?>
<?php render_footer(); ?>

<?php
function school_calendar_notify_audience(int $schoolId, int $eventId, string $title, array $event, int $senderId, string $change = 'published'): void
{
    $audience = (string) ($event['audience'] ?? 'school');
    $roles = match ($audience) {
        'students' => ['school_student'],
        'parents' => ['school_parent'],
        'teaching_staff' => ['class_teacher', 'subject_teacher'],
        'all_staff' => ['school_proprietor', 'school_headmaster', 'school_assistant_headmaster', 'school_secretary', 'class_teacher', 'subject_teacher', 'school_accountant'],
        'school' => ['school_proprietor', 'school_headmaster', 'school_assistant_headmaster', 'school_secretary', 'class_teacher', 'subject_teacher', 'school_accountant', 'school_parent', 'school_student'],
        default => [],
    };
    $key = 'school-calendar-event:' . $eventId . ':' . $change . ':' . gmdate('YmdHis');
    $noticeTitle = match ($change) {
        'updated' => 'School calendar event updated',
        'cancelled' => 'School calendar event cancelled',
        'unpublished' => 'School calendar event unpublished',
        default => 'New school calendar event',
    };
    $body = $title . ' was ' . $change . ' for ' . (string) ($event['starts_on'] ?? '') . '. Open the school calendar for details.';
    if ($audience === 'selected_class' && !empty($event['class_id'])) {
        $teacherStmt = db()->prepare(
            'SELECT teacher_user_id FROM teacher_class_assignments
             WHERE school_id = ? AND class_id = ? UNION
             SELECT teacher_user_id FROM class_subjects WHERE school_id = ? AND class_id = ?'
        );
        $teacherStmt->execute([$schoolId, (int) $event['class_id'], $schoolId, (int) $event['class_id']]);
        foreach ($teacherStmt->fetchAll(PDO::FETCH_COLUMN) as $recipientId) {
            school_notification_create($schoolId, (int) $recipientId, $senderId, $key, 'other', $noticeTitle, $body, 'school_calendar.php?date=' . rawurlencode((string) $event['starts_on']) . '&view=day&event_id=' . $eventId, (int) $event['class_id']);
        }
        $studentStmt = db()->prepare(
            'SELECT link.user_id FROM school_student_account_links link
             JOIN school_students ss ON ss.id = link.student_id AND ss.school_id = link.school_id
             WHERE link.school_id = ? AND link.status = "active" AND ss.class_id = ?'
        );
        $studentStmt->execute([$schoolId, (int) $event['class_id']]);
        foreach ($studentStmt->fetchAll(PDO::FETCH_COLUMN) as $recipientId) {
            school_notification_create($schoolId, (int) $recipientId, $senderId, $key, 'other', $noticeTitle, $body, 'school_calendar.php?date=' . rawurlencode((string) $event['starts_on']) . '&view=day&event_id=' . $eventId, (int) $event['class_id']);
        }
        $parentStmt = db()->prepare(
            'SELECT DISTINCT membership.user_id
             FROM school_student_account_links link
             JOIN school_students ss ON ss.id = link.student_id AND ss.school_id = link.school_id
             JOIN school_guardians guardian ON guardian.id = ss.guardian_id AND guardian.school_id = ss.school_id
             JOIN users parent_user ON LOWER(TRIM(parent_user.email)) = LOWER(TRIM(guardian.email))
             JOIN school_memberships membership ON membership.school_id = link.school_id
               AND membership.user_id = parent_user.id AND membership.role_name = "school_parent"
               AND membership.status = "active"
             WHERE link.school_id = ? AND link.status = "active" AND ss.class_id = ?'
        );
        $parentStmt->execute([$schoolId, (int) $event['class_id']]);
        foreach ($parentStmt->fetchAll(PDO::FETCH_COLUMN) as $recipientId) {
            school_notification_create($schoolId, (int) $recipientId, $senderId, $key, 'other', 'School calendar event', $body, 'school_calendar.php?date=' . rawurlencode((string) $event['starts_on']) . '&view=day&event_id=' . $eventId, (int) $event['class_id']);
        }
        return;
    }
    school_notify_roles(
        $schoolId,
        $roles,
        $senderId,
        $key,
        'other',
        $noticeTitle,
        $body,
        'school_calendar.php?date=' . rawurlencode((string) ($event['starts_on'] ?? date('Y-m-d'))) . '&view=day&event_id=' . $eventId
    );
}