<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$schoolsStmt = db()->query(
    'SELECT id, name, short_name
     FROM schools
     WHERE status = "active"
       AND subscription_status IN ("trial", "active")
       AND (subscription_expires_at IS NULL OR subscription_expires_at > NOW())
       AND deleted_at IS NULL
     ORDER BY name, id'
);
$schools = $schoolsStmt->fetchAll();
$schoolId = filter_var($_GET['school_id'] ?? null, FILTER_VALIDATE_INT);
$schoolId = $schoolId === false || $schoolId === null ? 0 : (int) $schoolId;
$school = null;
foreach ($schools as $candidate) {
    if ((int) $candidate['id'] === $schoolId) {
        $school = $candidate;
        break;
    }
}

$dateInput = (string) ($_GET['date'] ?? date('Y-m-d'));
if (isset($_GET['month']) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $_GET['month']) === 1) {
    $dateInput = (string) $_GET['month'] . '-01';
}
$selectedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $dateInput);
if (!$selectedDate || $selectedDate->format('Y-m-d') !== $dateInput) {
    $selectedDate = new DateTimeImmutable('today');
}
$view = (string) ($_GET['view'] ?? 'month');
if (!in_array($view, ['month', 'list'], true)) {
    $view = 'month';
}
$monthStart = $selectedDate->modify('first day of this month');
$monthEnd = $selectedDate->modify('last day of this month');
$events = [];
$selectedEvent = null;
if ($school !== null) {
    $eventsStmt = db()->prepare(
        'SELECT e.id, e.title, e.category, e.description, e.starts_on, e.ends_on,
                e.starts_at, e.ends_at, e.is_all_day, e.venue,
                (SELECT GROUP_CONCAT(DISTINCT y.academic_year ORDER BY y.starts_on SEPARATOR ", ")
                 FROM school_academic_years y
                 WHERE y.school_id = e.school_id AND y.starts_on <= e.ends_on AND y.ends_on >= e.starts_on) AS academic_years,
                (SELECT GROUP_CONCAT(DISTINCT t.term_name ORDER BY t.starts_on SEPARATOR ", ")
                 FROM school_terms t
                 WHERE t.school_id = e.school_id AND t.starts_on <= e.ends_on AND t.ends_on >= e.starts_on) AS terms
         FROM school_calendar_events e
         WHERE e.school_id = ? AND e.is_public = 1 AND e.status = "published"
           AND e.starts_on <= ? AND e.ends_on >= ?
         ORDER BY e.starts_on, e.starts_at, e.id'
    );
    $eventsStmt->execute([$schoolId, $monthEnd->format('Y-m-d'), $monthStart->format('Y-m-d')]);
    $events = $eventsStmt->fetchAll();

    $eventId = filter_var($_GET['event_id'] ?? null, FILTER_VALIDATE_INT);
    if ($eventId !== false && $eventId !== null && $eventId > 0) {
        $eventStmt = db()->prepare(
            'SELECT e.id, e.title, e.category, e.description, e.starts_on, e.ends_on,
                    e.starts_at, e.ends_at, e.is_all_day, e.venue,
                    (SELECT GROUP_CONCAT(DISTINCT y.academic_year ORDER BY y.starts_on SEPARATOR ", ")
                     FROM school_academic_years y
                     WHERE y.school_id = e.school_id AND y.starts_on <= e.ends_on AND y.ends_on >= e.starts_on) AS academic_years,
                    (SELECT GROUP_CONCAT(DISTINCT t.term_name ORDER BY t.starts_on SEPARATOR ", ")
                     FROM school_terms t
                     WHERE t.school_id = e.school_id AND t.starts_on <= e.ends_on AND t.ends_on >= e.starts_on) AS terms
             FROM school_calendar_events e
             WHERE e.id = ? AND e.school_id = ? AND e.is_public = 1 AND e.status = "published"
             LIMIT 1'
        );
        $eventStmt->execute([$eventId, $schoolId]);
        $selectedEvent = $eventStmt->fetch() ?: null;
    }
}

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

$monthGridDates = [];
$calendarEventsByDate = [];
$gridStart = $monthStart->modify('monday this week');
for ($offset = 0; $offset < 42; $offset++) {
    $gridDate = $gridStart->modify('+' . $offset . ' days');
    $dateKey = $gridDate->format('Y-m-d');
    $monthGridDates[] = [
        'date' => $dateKey,
        'day' => $gridDate->format('j'),
        'in_month' => $gridDate->format('Y-m') === $selectedDate->format('Y-m'),
    ];
}
foreach ($events as $event) {
    $eventFirst = new DateTimeImmutable((string) $event['starts_on']);
    $eventLast = new DateTimeImmutable((string) $event['ends_on']);
    foreach ($monthGridDates as $gridDate) {
        if ($gridDate['date'] >= $eventFirst->format('Y-m-d')
            && $gridDate['date'] <= $eventLast->format('Y-m-d')) {
            $calendarEventsByDate[$gridDate['date']][] = $event;
        }
    }
}
$previousMonth = $monthStart->modify('-1 month');
$nextMonth = $monthStart->modify('+1 month');

render_header('Public school calendar', false);
?>
<section class="panel public-calendar-page">
    <div class="page-heading">
        <p class="eyebrow">PUBLIC SCHOOL CALENDAR</p>
        <h1>School events</h1>
        <p class="muted">Choose an active school to view its publicly published events.</p>
    </div>
    <form method="get" class="inline-form public-calendar-school-picker">
        <label for="public-calendar-school">School</label>
        <select id="public-calendar-school" name="school_id" required>
            <option value="">Choose an active school</option>
            <?php foreach ($schools as $candidate): ?>
                <option value="<?= (int) $candidate['id'] ?>" <?= (int) $candidate['id'] === $schoolId ? 'selected' : '' ?>>
                    <?= e((string) $candidate['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <input type="hidden" name="date" value="<?= e($selectedDate->format('Y-m-d')) ?>">
        <input type="hidden" name="view" value="<?= e($view) ?>">
        <button class="button button-primary" type="submit">View calendar</button>
    </form>
    <?php if ($schools === []): ?>
        <p class="muted">No active school calendars are available right now.</p>
    <?php elseif ($school === null): ?>
        <p class="muted">Select a school to see its public calendar.</p>
    <?php else: ?>
        <h2><?= e((string) $school['name']) ?><?= !empty($school['short_name']) ? ' (' . e((string) $school['short_name']) . ')' : '' ?></h2>
        <nav class="public-calendar-navigation" aria-label="Calendar navigation">
            <a class="button" href="?school_id=<?= $schoolId ?>&amp;date=<?= e($previousMonth->format('Y-m-d')) ?>&amp;view=<?= e($view) ?>" aria-label="Previous month">←</a>
            <strong><?= e($selectedDate->format('F Y')) ?></strong>
            <a class="button" href="?school_id=<?= $schoolId ?>&amp;date=<?= e($nextMonth->format('Y-m-d')) ?>&amp;view=<?= e($view) ?>" aria-label="Next month">→</a>
            <form method="get" class="inline-form">
                <input type="hidden" name="school_id" value="<?= $schoolId ?>">
                <input type="hidden" name="view" value="<?= e($view) ?>">
                <label for="public-calendar-date">Month</label>
                <input id="public-calendar-date" type="month" name="month" value="<?= e($selectedDate->format('Y-m')) ?>">
                <button class="button" type="submit">Go</button>
            </form>
        </nav>
        <nav class="attendance-period-tabs" aria-label="Public calendar view">
            <?php foreach (['month' => 'Month', 'list' => 'List'] as $viewKey => $label): ?>
                <a class="attendance-period-tab<?= $view === $viewKey ? ' is-active' : '' ?>"
                   href="?school_id=<?= $schoolId ?>&amp;date=<?= e($selectedDate->format('Y-m-d')) ?>&amp;view=<?= e($viewKey) ?>"
                   <?= $view === $viewKey ? 'aria-current="page"' : '' ?>><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <?php if ($selectedEvent): ?>
            <article class="panel public-calendar-event-detail" aria-labelledby="public-calendar-event-title">
                <h2 id="public-calendar-event-title"><?= e((string) $selectedEvent['title']) ?></h2>
                <p><?= e($categories[(string) $selectedEvent['category']] ?? 'School event') ?> ·
                    <?= e((string) $selectedEvent['starts_on']) ?><?= $selectedEvent['ends_on'] !== $selectedEvent['starts_on'] ? ' – ' . e((string) $selectedEvent['ends_on']) : '' ?>
                    · <?= empty($selectedEvent['is_all_day']) ? e(substr((string) $selectedEvent['starts_at'], 0, 5) . '–' . substr((string) $selectedEvent['ends_at'], 0, 5)) : 'All day' ?>
                </p>
                <p>Academic year: <?= e((string) ($selectedEvent['academic_years'] ?: 'Not configured')) ?> · Term: <?= e((string) ($selectedEvent['terms'] ?: 'Not configured')) ?></p>
                <?php if (!empty($selectedEvent['venue'])): ?><p>Location: <?= e((string) $selectedEvent['venue']) ?></p><?php endif; ?>
                <?php if (!empty($selectedEvent['description'])): ?><p><?= nl2br(e((string) $selectedEvent['description'])) ?></p><?php endif; ?>
            </article>
        <?php elseif (isset($_GET['event_id'])): ?>
            <p class="muted" role="status">That event is not available on this public calendar.</p>
        <?php endif; ?>
        <?php if ($view === 'month'): ?>
            <div class="public-calendar-month" role="grid" aria-label="<?= e($selectedDate->format('F Y')) ?> calendar">
                <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $weekday): ?>
                    <div class="public-calendar-weekday" role="columnheader"><?= e($weekday) ?></div>
                <?php endforeach; ?>
                <?php foreach ($monthGridDates as $gridDate): ?>
                    <div class="public-calendar-day<?= $gridDate['in_month'] ? '' : ' is-outside-month' ?><?= $gridDate['date'] === date('Y-m-d') ? ' is-today' : '' ?>" role="gridcell">
                        <strong><?= e($gridDate['day']) ?></strong>
                        <?php foreach (array_slice($calendarEventsByDate[$gridDate['date']] ?? [], 0, 3) as $calendarEvent): ?>
                            <a href="?school_id=<?= $schoolId ?>&amp;date=<?= e($selectedDate->format('Y-m-d')) ?>&amp;view=month&amp;event_id=<?= (int) $calendarEvent['id'] ?>"><?= e((string) $calendarEvent['title']) ?></a>
                        <?php endforeach; ?>
                        <?php if (count($calendarEventsByDate[$gridDate['date']] ?? []) > 3): ?><span class="muted">+<?= count($calendarEventsByDate[$gridDate['date']]) - 3 ?> more</span><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <section class="public-calendar-list<?= $view === 'month' ? ' public-calendar-mobile-list' : '' ?>" aria-label="Events in <?= e($selectedDate->format('F Y')) ?>">
            <h2><?= $view === 'month' ? 'Events this month' : 'Event list' ?></h2>
            <?php if ($events === []): ?>
                <p class="muted">No public events are scheduled for this month.</p>
            <?php else: ?>
                <ul>
                    <?php foreach ($events as $event): ?>
                        <li>
                            <a href="?school_id=<?= $schoolId ?>&amp;date=<?= e($selectedDate->format('Y-m-d')) ?>&amp;view=<?= e($view) ?>&amp;event_id=<?= (int) $event['id'] ?>"><strong><?= e((string) $event['title']) ?></strong></a>
                            <span><?= e($categories[(string) $event['category']] ?? 'School event') ?> · <?= e((string) $event['starts_on']) ?><?= $event['ends_on'] !== $event['starts_on'] ? ' – ' . e((string) $event['ends_on']) : '' ?></span>
                            <span>Academic year: <?= e((string) ($event['academic_years'] ?: 'Not configured')) ?> · Term: <?= e((string) ($event['terms'] ?: 'Not configured')) ?></span>
                            <span><?= empty($event['is_all_day']) ? e(substr((string) $event['starts_at'], 0, 5) . '–' . substr((string) $event['ends_at'], 0, 5)) : 'All day' ?><?= !empty($event['venue']) ? ' · ' . e((string) $event['venue']) : '' ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    <?php endif; ?>
    <p class="auth-switch"><a href="index.php">Return to sign in</a></p>
</section>
<?php render_footer(); ?>
