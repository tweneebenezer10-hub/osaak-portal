<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
$user = require_login();

$modules = [
    'events' => ['title' => 'Events', 'view' => 'events.view', 'manage' => 'events.manage', 'description' => 'Association events, registrations, and attendance.'],
    'announcements' => ['title' => 'Announcements', 'view' => 'announcements.view', 'manage' => 'announcements.manage', 'description' => 'Association news and member communications.'],
    'finance' => ['title' => 'Dues and payments', 'view' => 'dues.view_self', 'manage' => 'finance.manage', 'description' => 'Membership dues, payment records, and review history.'],
    'meetings' => ['title' => 'Meetings', 'view' => 'meetings.view', 'manage' => 'meetings.manage', 'description' => 'Meeting schedules, agendas, minutes, and attendance.'],
    'documents' => ['title' => 'Documents', 'view' => 'documents.view', 'manage' => 'documents.manage', 'description' => 'Association records shared securely with members.'],
    'reports' => ['title' => 'Reports', 'view' => 'reports.view', 'manage' => null, 'description' => 'Membership, event, and finance summaries.'],
    'settings' => ['title' => 'Association settings', 'view' => 'association.settings.manage', 'manage' => 'association.settings.manage', 'description' => 'Basic association contact and portal information.'],
];
$module = (string) ($_GET['module'] ?? $_POST['module'] ?? 'events');
if (!isset($modules[$module])) {
    http_response_code(404);
    exit('Association module not found.');
}
$definition = $modules[$module];
$canView = user_has_permission($user, $definition['view'])
    || ($module === 'finance' && user_has_permission($user, 'finance.view'));
if (!$canView) {
    http_response_code(403);
    render_header('Access denied');
    echo '<section class="panel"><h1>Access denied</h1><p>Your account does not have permission to view this module.</p><a class="button" href="dashboard.php">Return to dashboard</a></section>';
    render_footer();
    exit;
}
$canManage = $definition['manage'] !== null && user_has_permission($user, $definition['manage']);
$isOfficer = in_array($user['active_role'], [
    'administrator', 'it_manager', 'association_super_admin', 'president', 'vice_president',
    'secretary', 'assistant_secretary', 'treasurer', 'assistant_treasurer',
    'organising_secretary', 'public_relations_officer', 'executive_member',
    'electoral_officer', 'auditor', 'welfare_officer', 'committee_chairperson',
], true);
$canManagePayments = user_has_permission($user, 'payments.manage')
    || user_has_permission($user, 'finance.manage');

function association_datetime(string $value): ?string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        return null;
    }
    return $date->format('Y-m-d H:i:s');
}

function association_sync_event_announcement(int $eventId, int $actorId): array
{
    $stmt = db()->prepare('SELECT * FROM association_events WHERE id = ?');
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();
    if (!$event) {
        throw new RuntimeException('The event announcement could not be synchronized because the event is missing.');
    }
    $status = $event['status'] === 'published' ? 'published' : 'archived';
    $body = 'Event date: ' . $event['starts_at'] . ' UTC';
    if ($event['ends_at'] !== null) {
        $body .= "\nEnds: " . $event['ends_at'] . ' UTC';
    }
    if ($event['location'] !== null && $event['location'] !== '') {
        $body .= "\nLocation: " . $event['location'];
    }
    if ($event['description'] !== null && $event['description'] !== '') {
        $body .= "\n\n" . $event['description'];
    }
    $title = 'Event: ' . $event['title'];
    $stmt = db()->prepare('SELECT id FROM association_announcements WHERE linked_event_id = ?');
    $stmt->execute([$eventId]);
    $announcementId = (int) $stmt->fetchColumn();
    if ($announcementId) {
        $stmt = db()->prepare(
            'UPDATE association_announcements
             SET title=?,body=?,audience=\'members\',status=?,
                 publish_at=CASE WHEN ?=\'published\' THEN COALESCE(publish_at,UTC_TIMESTAMP()) ELSE publish_at END
             WHERE id=? AND linked_event_id=?'
        );
        $stmt->execute([$title, $body, $status, $status, $announcementId, $eventId]);
    } else {
        $stmt = db()->prepare(
            'INSERT INTO association_announcements
             (title,body,audience,status,publish_at,created_by,linked_event_id)
             VALUES (?, ?, \'members\', ?, CASE WHEN ?=\'published\' THEN UTC_TIMESTAMP() ELSE NULL END, ?, ?)'
        );
        $stmt->execute([$title, $body, $status, $status, $actorId, $eventId]);
        $announcementId = (int) db()->lastInsertId();
    }
    return ['id' => $announcementId, 'title' => $title, 'body' => $body, 'audience' => 'members', 'status' => $status];
}

function association_uploaded_document(int $actorId): void
{
    $title = trim((string) ($_POST['title'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $visibility = (string) ($_POST['visibility'] ?? 'members');
    $file = $_FILES['document'] ?? null;
    if (preg_match('/^.{2,160}$/us', $title) !== 1 || strlen($description) > 500
        || !in_array($visibility, ['members', 'officers'], true)) {
        throw new InvalidArgumentException('Enter a title, a short description, and a valid document visibility.');
    }
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || (int) ($file['size'] ?? 0) < 1 || (int) $file['size'] > 10 * 1024 * 1024
        || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        throw new InvalidArgumentException('Choose a document up to 10 MB and try again.');
    }
    $originalName = basename((string) ($file['name'] ?? 'document'));
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
    $allowed = [
        'pdf' => 'application/pdf',
        'txt' => 'text/plain',
        'csv' => 'text/plain',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];
    $zipMime = 'application/zip';
    if (!isset($allowed[$extension]) || !in_array($mime, [$allowed[$extension], 'application/octet-stream'], true)
        || (in_array($extension, ['docx', 'xlsx'], true) && !in_array($mime, [$allowed[$extension], $zipMime, 'application/octet-stream'], true))) {
        throw new InvalidArgumentException('Supported file types are PDF, DOCX, XLSX, CSV, TXT, JPG, and PNG.');
    }
    if (strlen($originalName) > 255) {
        throw new InvalidArgumentException('The uploaded file name is too long.');
    }
    $directory = APP_ROOT . '/storage/documents';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('The private document storage directory could not be created.');
    }
    $storedName = bin2hex(random_bytes(24)) . '.' . $extension;
    $destination = $directory . '/' . $storedName;
    if (!move_uploaded_file((string) $file['tmp_name'], $destination)) {
        throw new RuntimeException('The uploaded document could not be stored.');
    }
    try {
        $stmt = db()->prepare(
            'INSERT INTO association_documents
             (title, description, stored_name, original_name, mime_type, file_size, visibility, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $title, $description !== '' ? $description : null, $storedName,
            $originalName, $mime ?: $allowed[$extension], (int) $file['size'], $visibility, $actorId,
        ]);
        audit($actorId, 'association_document_uploaded', 'document_id=' . (int) db()->lastInsertId());
    } catch (Throwable $exception) {
        if (is_file($destination)) {
            unlink($destination);
        }
        throw $exception;
    }
}

if ($module === 'documents' && isset($_GET['download'])) {
    $documentId = filter_var($_GET['download'], FILTER_VALIDATE_INT);
    if (!$documentId) {
        http_response_code(404);
        exit('Document not found.');
    }
    $stmt = db()->prepare('SELECT * FROM association_documents WHERE id = ?');
    $stmt->execute([(int) $documentId]);
    $document = $stmt->fetch();
    if (!$document || ($document['visibility'] === 'officers' && !$canManage)) {
        http_response_code(404);
        exit('Document not found.');
    }
    $storage = realpath(APP_ROOT . '/storage/documents');
    $path = $storage ? realpath($storage . DIRECTORY_SEPARATOR . $document['stored_name']) : false;
    if (!$storage || !$path || !str_starts_with($path, $storage . DIRECTORY_SEPARATOR) || !is_file($path)) {
        throw new RuntimeException('The requested document is missing from private storage.');
    }
    header('Content-Type: ' . $document['mime_type']);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . rawurlencode($document['original_name']) . '"');
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $fail = static function (string $message): never {
        flash('error', $message);
        redirect('school_services.php?module=' . rawurlencode((string) ($_POST['module'] ?? 'events')));
    };
    try {
        if ($module === 'events' && $action === 'create_event' && $canManage) {
            $title = trim((string) ($_POST['title'] ?? ''));
            $location = trim((string) ($_POST['location'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $startsAt = association_datetime((string) ($_POST['starts_at'] ?? ''));
            $endsAtInput = trim((string) ($_POST['ends_at'] ?? ''));
            $endsAt = $endsAtInput !== '' ? association_datetime($endsAtInput) : null;
            $deadlineInput = trim((string) ($_POST['registration_deadline'] ?? ''));
            $registrationDeadline = $deadlineInput !== '' ? association_datetime($deadlineInput) : null;
            $capacityInput = trim((string) ($_POST['capacity'] ?? ''));
            $capacity = $capacityInput !== '' ? filter_var($capacityInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]) : null;
            $status = (string) ($_POST['status'] ?? 'draft');
            if (preg_match('/^.{2,160}$/us', $title) !== 1 || strlen($location) > 200 || strlen($description) > 10000
                || !$startsAt || ($endsAtInput !== '' && !$endsAt) || ($endsAt && $endsAt < $startsAt)
                || ($deadlineInput !== '' && !$registrationDeadline)
                || ($registrationDeadline && ($registrationDeadline > $startsAt || $registrationDeadline < gmdate('Y-m-d H:i:s')))
                || ($capacityInput !== '' && $capacity === false) || !in_array($status, ['draft', 'published'], true)) {
                throw new InvalidArgumentException('Check the event title, schedule, registration deadline, capacity, and publication status.');
            }
            db()->beginTransaction();
            try {
                $stmt = db()->prepare('INSERT INTO association_events (title, description, location, starts_at, ends_at, registration_deadline, capacity, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$title, $description !== '' ? $description : null, $location !== '' ? $location : null, $startsAt, $endsAt, $registrationDeadline, $capacity ?: null, $status, (int) $user['id']]);
                $eventId = (int) db()->lastInsertId();
                $announcement = association_sync_event_announcement($eventId, (int) $user['id']);
                db()->commit();
            } catch (Throwable $exception) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                throw $exception;
            }
            if ($announcement['status'] === 'published') {
                notify_announcement_recipients($announcement['id'], $announcement['title'], $announcement['body'], $announcement['audience']);
            }
            audit((int) $user['id'], 'association_event_created', 'event_id=' . $eventId . '; status=' . $status);
            flash('success', 'Event saved.');
        } elseif ($module === 'events' && $action === 'update_event' && $canManage) {
            $eventId = filter_var($_POST['event_id'] ?? null, FILTER_VALIDATE_INT);
            $title = trim((string) ($_POST['title'] ?? ''));
            $location = trim((string) ($_POST['location'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $startsAt = association_datetime((string) ($_POST['starts_at'] ?? ''));
            $endsAtInput = trim((string) ($_POST['ends_at'] ?? ''));
            $endsAt = $endsAtInput !== '' ? association_datetime($endsAtInput) : null;
            $deadlineInput = trim((string) ($_POST['registration_deadline'] ?? ''));
            $registrationDeadline = $deadlineInput !== '' ? association_datetime($deadlineInput) : null;
            $capacityInput = trim((string) ($_POST['capacity'] ?? ''));
            $capacity = $capacityInput !== '' ? filter_var($capacityInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]) : null;
            $status = (string) ($_POST['status'] ?? '');
            if (!$eventId || preg_match('/^.{2,160}$/us', $title) !== 1
                || strlen($location) > 200 || strlen($description) > 10000
                || !$startsAt || ($endsAtInput !== '' && !$endsAt) || ($endsAt && $endsAt < $startsAt)
                || ($deadlineInput !== '' && !$registrationDeadline)
                || ($registrationDeadline && $registrationDeadline > $startsAt)
                || ($capacityInput !== '' && $capacity === false)
                || !in_array($status, ['draft', 'published', 'cancelled', 'completed'], true)) {
                throw new InvalidArgumentException('Check the event, schedule, registration deadline, capacity, and status.');
            }
            db()->beginTransaction();
            try {
                $stmt = db()->prepare(
                    'UPDATE association_events
                     SET title=?,description=?,location=?,starts_at=?,ends_at=?,
                         registration_deadline=?,capacity=?,status=?
                     WHERE id=?'
                );
                $stmt->execute([
                    $title,
                    $description !== '' ? $description : null,
                    $location !== '' ? $location : null,
                    $startsAt,
                    $endsAt,
                    $registrationDeadline,
                    $capacity ?: null,
                    $status,
                    (int) $eventId,
                ]);
                $check = db()->prepare('SELECT id FROM association_events WHERE id=?');
                $check->execute([(int) $eventId]);
                if (!$check->fetchColumn()) {
                    throw new InvalidArgumentException('That event no longer exists.');
                }
                $announcement = association_sync_event_announcement((int) $eventId, (int) $user['id']);
                db()->commit();
            } catch (Throwable $exception) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                throw $exception;
            }
            if ($announcement['status'] === 'published') {
                notify_announcement_recipients($announcement['id'], $announcement['title'], $announcement['body'], $announcement['audience']);
            }
            audit((int) $user['id'], 'association_event_updated', 'event_id=' . (int) $eventId . '; status=' . $status);
            flash('success', 'Event and its linked announcement were updated.');
        } elseif ($module === 'events' && $action === 'register_event') {
            $eventId = filter_var($_POST['event_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$eventId) {
                throw new InvalidArgumentException('Select a valid event.');
            }
            db()->beginTransaction();
            $stmt = db()->prepare("SELECT id, capacity, registration_deadline FROM association_events WHERE id = ? AND status = 'published' AND starts_at >= UTC_TIMESTAMP() AND (registration_deadline IS NULL OR registration_deadline >= UTC_TIMESTAMP()) FOR UPDATE");
            $stmt->execute([(int) $eventId]);
            $event = $stmt->fetch();
            if (!$event) {
                db()->rollBack();
                throw new InvalidArgumentException('That event is not open for registration.');
            }
            $stmt = db()->prepare("SELECT registration_status FROM event_registrations WHERE event_id=? AND user_id=?");
            $stmt->execute([(int) $eventId, (int) $user['id']]);
            $existingRegistration = $stmt->fetchColumn();
            if ($existingRegistration === 'registered') {
                db()->rollBack();
                throw new InvalidArgumentException('You are already registered for this event.');
            }
            if ($event['capacity'] !== null) {
                $stmt = db()->prepare("SELECT COUNT(*) FROM event_registrations WHERE event_id = ? AND registration_status = 'registered'");
                $stmt->execute([(int) $eventId]);
                if ((int) $stmt->fetchColumn() >= (int) $event['capacity']) {
                    db()->rollBack();
                    throw new InvalidArgumentException('This event has reached its registration capacity.');
                }
            }
            $stmt = db()->prepare("INSERT INTO event_registrations (event_id, user_id, registration_status) VALUES (?, ?, 'registered') ON DUPLICATE KEY UPDATE registration_status = 'registered', registered_at = UTC_TIMESTAMP()");
            $stmt->execute([(int) $eventId, (int) $user['id']]);
            db()->commit();
            audit((int) $user['id'], 'event_registered', 'event_id=' . (int) $eventId);
            create_user_notification(
                (int) $user['id'],
                'event-registration:' . (int) $eventId,
                'event',
                'Event registration confirmed',
                'Your registration for the selected association event is confirmed.',
                'school_services.php?module=events'
            );
            flash('success', 'Your event registration is confirmed.');
        } elseif ($module === 'events' && $action === 'cancel_registration') {
            $eventId = filter_var($_POST['event_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$eventId) {
                throw new InvalidArgumentException('Select a valid event.');
            }
            $stmt = db()->prepare(
                "UPDATE event_registrations r
                 JOIN association_events e ON e.id=r.event_id
                 SET r.registration_status='cancelled'
                 WHERE r.event_id=? AND r.user_id=? AND r.registration_status='registered'
                   AND e.status='published' AND e.starts_at>=UTC_TIMESTAMP()"
            );
            $stmt->execute([(int) $eventId, (int) $user['id']]);
            flash($stmt->rowCount() ? 'success' : 'error', $stmt->rowCount() ? 'Your event registration was cancelled.' : 'No active registration was found.');
        } elseif ($module === 'events' && $action === 'mark_event_attendance' && user_has_permission($user, 'events.attendance.manage')) {
            $eventId = filter_var($_POST['event_id'] ?? null, FILTER_VALIDATE_INT);
            $memberId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
            $attendance = (string) ($_POST['attendance_status'] ?? '');
            if (!$eventId || !$memberId || !in_array($attendance, ['present', 'absent'], true)) {
                throw new InvalidArgumentException('Choose a registered member and a valid attendance status.');
            }
            $stmt = db()->prepare("SELECT 1 FROM event_registrations WHERE event_id=? AND user_id=? AND registration_status='registered'");
            $stmt->execute([(int) $eventId, (int) $memberId]);
            if (!$stmt->fetchColumn()) {
                throw new InvalidArgumentException('The member is not actively registered for that event.');
            }
            $stmt = db()->prepare("UPDATE event_registrations SET attendance_status = ?, marked_by = ?, marked_at = UTC_TIMESTAMP() WHERE event_id = ? AND user_id = ? AND registration_status = 'registered'");
            $stmt->execute([$attendance, (int) $user['id'], (int) $eventId, (int) $memberId]);
            audit((int) $user['id'], 'event_attendance_marked', 'event_id=' . (int) $eventId . '; user_id=' . (int) $memberId . '; status=' . $attendance);
            flash('success', 'Event attendance recorded.');
        } elseif ($module === 'announcements' && $action === 'create_announcement' && $canManage) {
            $title = trim((string) ($_POST['title'] ?? ''));
            $body = trim((string) ($_POST['body'] ?? ''));
            $audience = (string) ($_POST['audience'] ?? 'all');
            $status = (string) ($_POST['status'] ?? 'draft');
            if (preg_match('/^.{2,180}$/us', $title) !== 1 || $body === '' || strlen($body) > 30000
                || !in_array($audience, ['all', 'members', 'officials'], true)
                || !in_array($status, ['draft', 'published'], true)) {
                throw new InvalidArgumentException('Check the announcement title, message, audience, and status.');
            }
            $stmt = db()->prepare('INSERT INTO association_announcements (title, body, audience, status, publish_at, created_by) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$title, $body, $audience, $status, $status === 'published' ? gmdate('Y-m-d H:i:s') : null, (int) $user['id']]);
            $announcementId = (int) db()->lastInsertId();
            if ($status === 'published') {
                notify_announcement_recipients($announcementId, $title, $body, $audience);
            }
            audit((int) $user['id'], 'association_announcement_created', 'announcement_id=' . $announcementId . '; status=' . $status);
            flash('success', 'Announcement saved.');
        } elseif ($module === 'announcements' && $action === 'publish_announcement' && $canManage) {
            $announcementId = filter_var($_POST['announcement_id'] ?? null, FILTER_VALIDATE_INT);
            $status = (string) ($_POST['status'] ?? '');
            if (!$announcementId || !in_array($status, ['published', 'archived'], true)) {
                throw new InvalidArgumentException('Choose a valid announcement action.');
            }
            $stmt = db()->prepare('UPDATE association_announcements SET status = ?, publish_at = CASE WHEN ? = \'published\' THEN UTC_TIMESTAMP() ELSE publish_at END WHERE id = ?');
            $stmt->execute([$status, $status, (int) $announcementId]);
            if ($status === 'published') {
                $stmt = db()->prepare('SELECT title, body, audience FROM association_announcements WHERE id = ?');
                $stmt->execute([(int) $announcementId]);
                $announcement = $stmt->fetch();
                if ($announcement) {
                    notify_announcement_recipients((int) $announcementId, (string) $announcement['title'], (string) $announcement['body'], (string) $announcement['audience']);
                }
            }
            audit((int) $user['id'], 'association_announcement_status_changed', 'announcement_id=' . (int) $announcementId . '; status=' . $status);
            flash('success', 'Announcement status updated.');
        } elseif ($module === 'finance' && $action === 'save_monthly_dues_settings' && user_has_permission($user, 'finance.manage')) {
            $amount = trim((string) ($_POST['monthly_amount'] ?? ''));
            $currency = strtoupper(trim((string) ($_POST['monthly_currency'] ?? 'GHS')));
            $emailDay = filter_var($_POST['email_day'] ?? null, FILTER_VALIDATE_INT);
            if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $amount) || (float) $amount <= 0
                || !preg_match('/^[A-Z]{3}$/', $currency) || !$emailDay || $emailDay < 1 || $emailDay > 7) {
                throw new InvalidArgumentException('Enter a valid monthly amount, three-letter currency, and reminder day from 1 to 7.');
            }
            $stmt = db()->prepare(
                'INSERT INTO association_settings (setting_key, setting_value, updated_by)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),
                     updated_by=VALUES(updated_by), updated_at=UTC_TIMESTAMP()'
            );
            foreach ([
                'monthly_dues_amount' => $amount,
                'monthly_dues_currency' => $currency,
                'monthly_dues_email_day' => (string) $emailDay,
            ] as $key => $value) {
                $stmt->execute([$key, $value, (int) $user['id']]);
            }
            audit((int) $user['id'], 'monthly_dues_settings_updated');
            flash('success', 'Monthly dues and reminder settings saved. Existing dues and payment records were not changed.');
        } elseif ($module === 'finance' && $action === 'retry_dues_reminder' && user_has_permission($user, 'finance.manage')) {
            $dueId = filter_var($_POST['due_id'] ?? null, FILTER_VALIDATE_INT);
            $memberId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$dueId || !$memberId) {
                throw new InvalidArgumentException('Choose a valid failed reminder.');
            }
            $stmt = db()->prepare(
                "UPDATE membership_due_notifications n
                 JOIN membership_dues d ON d.id=n.due_id
                 SET n.status='retry_queued',n.last_error='Administrator-requested retry queued for the scheduled retry task.'
                 WHERE n.due_id=? AND n.user_id=? AND n.status='failed'
                   AND n.attempts < 5 AND d.due_type='monthly' AND d.status='open'"
            );
            $stmt->execute([(int) $dueId, (int) $memberId]);
            if (!$stmt->rowCount()) {
                throw new InvalidArgumentException('That reminder is no longer eligible for retry. It may have been sent, exhausted, or its dues cycle is closed.');
            }
            audit((int) $user['id'], 'dues_reminder_retry_requested', 'due_id=' . (int) $dueId . '; user_id=' . (int) $memberId);
            flash('success', 'Reminder queued for the next scheduled retry task run.');
        } elseif ($module === 'finance' && $action === 'send_reminder_test' && user_has_permission($user, 'finance.manage')) {
            $subject = 'Dues reminder email configuration test';
            $body = '<p>This is a test message requested by you from the association dues reminder settings.</p>';
            if (!send_mail((string) $user['email'], $subject, $body, 'This is a test message requested by you from the association dues reminder settings.')) {
                audit((int) $user['id'], 'dues_reminder_test_failed');
                flash('error', 'The reminder test email could not be handed to SMTP. Check the protected mail error log.');
                redirect('school_services.php?module=finance');
            }
            audit((int) $user['id'], 'dues_reminder_test_sent');
            flash('success', 'Test email accepted by SMTP and sent only to your account email address.');
        } elseif ($module === 'finance' && $action === 'create_due' && user_has_permission($user, 'finance.manage')) {
            $title = trim((string) ($_POST['title'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $amount = trim((string) ($_POST['amount'] ?? ''));
            $currency = strtoupper(trim((string) ($_POST['currency'] ?? 'GHS')));
            $dueDate = trim((string) ($_POST['due_date'] ?? ''));
            $targetYearInput = trim((string) ($_POST['target_year'] ?? ''));
            $targetCategory = trim((string) ($_POST['target_category'] ?? ''));
            $targetYear = $targetYearInput === '' ? null : filter_var($targetYearInput, FILTER_VALIDATE_INT);
            $dateValue = $dueDate === '' ? null : DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate);
            if (preg_match('/^.{2,160}$/us', $title) !== 1 || strlen($description) > 500
                || !preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $amount) || (float) $amount <= 0
                || !preg_match('/^[A-Z]{3}$/', $currency)
                || ($dueDate !== '' && (!$dateValue || $dateValue->format('Y-m-d') !== $dueDate))
                || ($targetYearInput !== '' && ($targetYear === false || $targetYear < 1900 || $targetYear > (int) gmdate('Y')))
                || ($targetCategory !== '' && (strlen($targetCategory) > 40 || preg_match('/^[A-Za-z0-9 _-]+$/', $targetCategory) !== 1))) {
                throw new InvalidArgumentException('Check the due title, amount, currency, deadline, and optional member category or graduation year.');
            }
            $stmt = db()->prepare('INSERT INTO membership_dues (title, description, amount, currency, due_date, target_year, target_category, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$title, $description !== '' ? $description : null, $amount, $currency, $dateValue?->format('Y-m-d'), $targetYear ?: null, $targetCategory !== '' ? $targetCategory : null, (int) $user['id']]);
            audit((int) $user['id'], 'membership_due_created', 'due_id=' . (int) db()->lastInsertId());
            flash('success', 'Membership due created.');
        } elseif ($module === 'finance' && $action === 'adjust_member_due' && user_has_permission($user, 'finance.manage')) {
            $dueId = filter_var($_POST['due_id'] ?? null, FILTER_VALIDATE_INT);
            $memberId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
            $adjustmentType = (string) ($_POST['adjustment_type'] ?? '');
            $amount = trim((string) ($_POST['amount'] ?? ''));
            $reason = trim((string) ($_POST['reason'] ?? ''));
            if (!$dueId || !$memberId || !in_array($adjustmentType, ['credit', 'exemption'], true)
                || preg_match('/^.{5,500}$/us', $reason) !== 1
                || ($adjustmentType === 'credit' && (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $amount) || (float) $amount <= 0))) {
                throw new InvalidArgumentException('Choose a due and member, enter a valid adjustment, and provide a reason of 5 to 500 characters.');
            }
            db()->beginTransaction();
            try {
                $stmt = db()->prepare("SELECT amount,target_year,target_category,currency FROM membership_dues WHERE id=? AND status='open' FOR UPDATE");
                $stmt->execute([(int) $dueId]);
                $due = $stmt->fetch();
                $stmt = db()->prepare(
                    "SELECT u.id,mp.graduation_year,mp.membership_category
                     FROM users u JOIN member_profiles mp ON mp.user_id=u.id
                     WHERE u.id=? AND u.status='active' AND u.email_verified_at IS NOT NULL
                       AND mp.membership_status='active' FOR UPDATE"
                );
                $stmt->execute([(int) $memberId]);
                $member = $stmt->fetch();
                if (!$due || !$member
                    || ($due['target_year'] !== null && (int) $due['target_year'] !== (int) $member['graduation_year'])
                    || ($due['target_category'] !== null && $due['target_category'] !== $member['membership_category'])) {
                    throw new InvalidArgumentException('Choose an open due that applies to this active, verified member.');
                }
                $stmt = db()->prepare(
                    "SELECT COALESCE(SUM(CASE WHEN adjustment_type='credit' THEN amount ELSE 0 END),0),
                            MAX(adjustment_type='exemption')
                     FROM membership_due_adjustments WHERE due_id=? AND user_id=?"
                );
                $stmt->execute([(int) $dueId, (int) $memberId]);
                [$existingCredit, $alreadyExempt] = $stmt->fetch(PDO::FETCH_NUM);
                $stmt = db()->prepare(
                    "SELECT COALESCE(SUM(p.amount-COALESCE(a.amount,0)),0)
                     FROM payment_records p
                     LEFT JOIN payment_adjustments a ON a.payment_id=p.id AND a.adjustment_type='refund'
                     WHERE p.user_id=? AND p.due_id=? AND p.status='confirmed'"
                );
                $stmt->execute([(int) $memberId, (int) $dueId]);
                $paidMinor = (int) round((float) $stmt->fetchColumn() * 100);
                $assessedMinor = max(0, (int) round(((float) $due['amount'] - (float) $existingCredit) * 100));
                if ($alreadyExempt) {
                    throw new InvalidArgumentException('This member is already exempt from the selected due.');
                }
                if ($adjustmentType === 'exemption' && $paidMinor > 0) {
                    throw new InvalidArgumentException('A due with confirmed payments cannot be fully exempted; record an authorized credit for the remaining unpaid amount instead.');
                }
                if ($adjustmentType === 'credit'
                    && (int) round((float) $amount * 100) > max(0, $assessedMinor - $paidMinor)) {
                    throw new InvalidArgumentException('The adjustment cannot reduce the assessed due below confirmed payments already recorded.');
                }
                $stmt = db()->prepare(
                    'INSERT INTO membership_due_adjustments
                     (due_id,user_id,adjustment_type,amount,reason,recorded_by)
                     VALUES (?,?,?,?,?,?)'
                );
                $stmt->execute([
                    (int) $dueId,
                    (int) $memberId,
                    $adjustmentType,
                    $adjustmentType === 'credit' ? $amount : null,
                    $reason,
                    (int) $user['id'],
                ]);
                audit(
                    (int) $user['id'],
                    'membership_due_' . $adjustmentType . '_recorded',
                    'due_id=' . (int) $dueId . '; user_id=' . (int) $memberId . '; reason=' . $reason
                );
                db()->commit();
            } catch (Throwable $exception) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                throw $exception;
            }
            flash('success', $adjustmentType === 'exemption' ? 'Member exemption recorded.' : 'Member due credit recorded.');
        } elseif ($module === 'finance' && $action === 'record_payment' && $canManagePayments) {
            $memberId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
            $dueId = filter_var($_POST['due_id'] ?? null, FILTER_VALIDATE_INT);
            $amount = trim((string) ($_POST['amount'] ?? ''));
            $currency = strtoupper(trim((string) ($_POST['currency'] ?? 'GHS')));
            $method = (string) ($_POST['payment_method'] ?? '');
            $reference = trim((string) ($_POST['reference'] ?? ''));
            $note = trim((string) ($_POST['note'] ?? ''));
            $paidAtInput = trim((string) ($_POST['paid_at'] ?? ''));
            $paidAt = association_datetime($paidAtInput);
            if (!$memberId || !preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $amount) || (float) $amount <= 0
                || !preg_match('/^[A-Z]{3}$/', $currency) || !in_array($method, ['cash', 'bank_transfer', 'mobile_money', 'cheque', 'other'], true)
                || strlen($reference) > 100 || strlen($note) > 500 || !$paidAt || $paidAt > gmdate('Y-m-d H:i:s')) {
                throw new InvalidArgumentException('Check the member, amount, currency, payment method, reference, and paid date.');
            }
            $stmt = db()->prepare(
                "SELECT u.id,mp.graduation_year,mp.membership_category,mp.membership_status
                 FROM users u JOIN member_profiles mp ON mp.user_id=u.id
                 WHERE u.id=? AND u.status='active' AND u.email_verified_at IS NOT NULL"
            );
            $stmt->execute([(int) $memberId]);
            $member = $stmt->fetch();
            if (!$member || $member['membership_status'] !== 'active') {
                throw new InvalidArgumentException('Payments can only be recorded for an active, email-verified member.');
            }
            db()->beginTransaction();
            try {
                if ($reference !== '') {
                    $stmt = db()->prepare('SELECT id FROM payment_records WHERE payment_method=? AND reference=?');
                    $stmt->execute([$method, $reference]);
                    if ($stmt->fetchColumn()) {
                        throw new InvalidArgumentException('That payment reference has already been recorded for this payment method.');
                    }
                }
                if ($dueId) {
                    $stmt = db()->prepare(                    "SELECT amount,currency,target_year,target_category FROM membership_dues WHERE id=? AND status='open' FOR UPDATE");
                    $stmt->execute([(int) $dueId]);
                    $due = $stmt->fetch();
                    if (!$due || $due['currency'] !== $currency
                        || ($due['target_year'] !== null && (int) $due['target_year'] !== (int) $member['graduation_year'])
                        || ($due['target_category'] !== null && $due['target_category'] !== $member['membership_category'])) {
                        throw new InvalidArgumentException('Choose an open due for this member with the same currency.');
                    }
                    $stmt = db()->prepare(
                        "SELECT COALESCE(SUM(p.amount-COALESCE(a.amount,0)),0)
                         FROM payment_records p
                         LEFT JOIN payment_adjustments a ON a.payment_id=p.id AND a.adjustment_type='refund'
                         WHERE p.user_id=? AND p.due_id=? AND p.status='confirmed'"
                    );
                    $stmt->execute([(int) $memberId, (int) $dueId]);
                    $paidMinor = (int) round((float) $stmt->fetchColumn() * 100);
                    $stmt = db()->prepare(
                        "SELECT COALESCE(SUM(CASE WHEN adjustment_type='credit' THEN amount ELSE 0 END),0),
                                MAX(adjustment_type='exemption')
                         FROM membership_due_adjustments WHERE due_id=? AND user_id=?"
                    );
                    $stmt->execute([(int) $dueId, (int) $memberId]);
                    [$dueCredit, $dueExempt] = $stmt->fetch(PDO::FETCH_NUM);
                    $balanceMinor = $dueExempt
                        ? 0
                        : max(0, (int) round(((float) $due['amount'] - (float) $dueCredit) * 100) - $paidMinor);
                    if ((int) round((float) $amount * 100) > $balanceMinor) {
                        throw new InvalidArgumentException('The payment exceeds this member’s remaining balance for the selected due.');
                    }
                }
                $stmt = db()->prepare('INSERT INTO payment_records (user_id, due_id, amount, currency, payment_method, reference, note, recorded_by, paid_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([(int) $memberId, $dueId ?: null, $amount, $currency, $method, $reference !== '' ? $reference : null, $note !== '' ? $note : null, (int) $user['id'], $paidAt]);
                $paymentId = (int) db()->lastInsertId();
                audit((int) $user['id'], 'payment_record_created', 'payment_id=' . $paymentId . '; user_id=' . (int) $memberId);
                db()->commit();
            } catch (Throwable $exception) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                if ($exception instanceof PDOException && $exception->getCode() === '23000' && $reference !== '') {
                    throw new InvalidArgumentException('That payment reference has already been recorded for this payment method.', 0, $exception);
                }
                throw $exception;
            }
            flash('success', 'Payment recorded as pending review.');
        } elseif ($module === 'finance' && $action === 'review_payment' && $canManagePayments) {
            $paymentId = filter_var($_POST['payment_id'] ?? null, FILTER_VALIDATE_INT);
            $status = (string) ($_POST['status'] ?? '');
            if (!$paymentId || !in_array($status, ['confirmed', 'rejected'], true)) {
                throw new InvalidArgumentException('Choose a valid payment review decision.');
            }
            db()->beginTransaction();
            try {
                $stmt = db()->prepare('SELECT user_id,due_id,amount,status FROM payment_records WHERE id=? FOR UPDATE');
                $stmt->execute([(int) $paymentId]);
                $payment = $stmt->fetch();
                if (!$payment || $payment['status'] !== 'pending') {
                    throw new InvalidArgumentException('That payment is not pending review.');
                }
                if ($status === 'confirmed' && $payment['due_id'] !== null) {
                    $stmt = db()->prepare("SELECT amount,currency,target_year,target_category FROM membership_dues WHERE id=? AND status='open' FOR UPDATE");
                    $stmt->execute([(int) $payment['due_id']]);
                    $due = $stmt->fetch();
                    if (!$due) {
                        throw new InvalidArgumentException('The selected due is no longer open; this payment cannot be confirmed against it.');
                    }
                    $stmt = db()->prepare(
                        "SELECT COALESCE(SUM(p.amount-COALESCE(a.amount,0)),0)
                         FROM payment_records p
                         LEFT JOIN payment_adjustments a ON a.payment_id=p.id AND a.adjustment_type='refund'
                         WHERE p.user_id=? AND p.due_id=? AND p.status='confirmed'"
                    );
                    $stmt->execute([(int) $payment['user_id'], (int) $payment['due_id']]);
                    $paidMinor = (int) round((float) $stmt->fetchColumn() * 100);
                    $stmt = db()->prepare('SELECT graduation_year,membership_category FROM member_profiles WHERE user_id=?');
                    $stmt->execute([(int) $payment['user_id']]);
                    $member = $stmt->fetch();
                    $stmt = db()->prepare(
                        "SELECT COALESCE(SUM(CASE WHEN adjustment_type='credit' THEN amount ELSE 0 END),0),
                                MAX(adjustment_type='exemption')
                         FROM membership_due_adjustments WHERE due_id=? AND user_id=?"
                    );
                    $stmt->execute([(int) $payment['due_id'], (int) $payment['user_id']]);
                    [$dueCredit, $dueExempt] = $stmt->fetch(PDO::FETCH_NUM);
                    if (!$member || ($due['target_year'] !== null && (int) $due['target_year'] !== (int) $member['graduation_year'])
                        || ($due['target_category'] !== null && $due['target_category'] !== $member['membership_category'])) {
                        throw new InvalidArgumentException('The member is no longer eligible for this due; payment cannot be confirmed.');
                    }
                    $balanceMinor = $dueExempt
                        ? 0
                        : max(0, (int) round(((float) $due['amount'] - (float) $dueCredit) * 100) - $paidMinor);
                    if ((int) round((float) $payment['amount'] * 100) > $balanceMinor) {
                        throw new InvalidArgumentException('This payment would exceed the member’s remaining balance; reject it or correct the payment record.');
                    }
                }
                $stmt = db()->prepare("UPDATE payment_records SET status=?,reviewed_by=?,reviewed_at=UTC_TIMESTAMP(),receipt_issued_at=CASE WHEN ?='confirmed' THEN UTC_TIMESTAMP() ELSE NULL END WHERE id=? AND status='pending'");
                $stmt->execute([$status, (int) $user['id'], $status, (int) $paymentId]);
                audit((int) $user['id'], 'payment_reviewed', 'payment_id=' . (int) $paymentId . '; status=' . $status);
                db()->commit();
            } catch (Throwable $exception) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                throw $exception;
            }
            flash('success', 'Payment review saved.');
        } elseif ($module === 'finance' && $action === 'change_due_status' && user_has_permission($user, 'finance.manage')) {
            $dueId = filter_var($_POST['due_id'] ?? null, FILTER_VALIDATE_INT);
            $status = (string) ($_POST['status'] ?? '');
            if (!$dueId || !in_array($status, ['open', 'closed'], true)) {
                throw new InvalidArgumentException('Choose a valid due and status.');
            }
            $stmt = db()->prepare('UPDATE membership_dues SET status = ? WHERE id = ?');
            $stmt->execute([$status, (int) $dueId]);
            audit((int) $user['id'], 'membership_due_status_changed', 'due_id=' . (int) $dueId . '; status=' . $status);
            flash('success', 'Membership due status updated.');
        } elseif ($module === 'finance' && $action === 'refund_payment' && $canManagePayments) {
            $paymentId = filter_var($_POST['payment_id'] ?? null, FILTER_VALIDATE_INT);
            $reason = trim((string) ($_POST['reason'] ?? ''));
            if (!$paymentId || preg_match('/^.{5,500}$/us', $reason) !== 1) {
                throw new InvalidArgumentException('Choose a payment record and provide a refund reason of 5 to 500 characters.');
            }
            db()->beginTransaction();
            $stmt = db()->prepare('SELECT amount, status FROM payment_records WHERE id = ? FOR UPDATE');
            $stmt->execute([(int) $paymentId]);
            $payment = $stmt->fetch();
            if (!$payment || $payment['status'] !== 'confirmed') {
                db()->rollBack();
                throw new InvalidArgumentException('Only confirmed payments can be refunded.');
            }
            $stmt = db()->prepare(
                "INSERT INTO payment_adjustments (payment_id, adjustment_type, amount, reason, recorded_by)
                 VALUES (?, 'refund', ?, ?, ?)"
            );
            $stmt->execute([(int) $paymentId, $payment['amount'], $reason, (int) $user['id']]);
            db()->commit();
            audit((int) $user['id'], 'payment_refund_recorded', 'payment_id=' . (int) $paymentId);
            flash('success', 'Refund recorded. The original payment record was preserved.');
        } elseif ($module === 'meetings' && $action === 'create_meeting' && $canManage) {
            $title = trim((string) ($_POST['title'] ?? ''));
            $when = association_datetime((string) ($_POST['meeting_at'] ?? ''));
            $location = trim((string) ($_POST['location'] ?? ''));
            $agenda = trim((string) ($_POST['agenda'] ?? ''));
            if (preg_match('/^.{2,160}$/us', $title) !== 1 || !$when || strlen($location) > 200 || strlen($agenda) > 20000) {
                throw new InvalidArgumentException('Check the meeting title, date, location, and agenda.');
            }
            $stmt = db()->prepare('INSERT INTO association_meetings (title, meeting_at, location, agenda, created_by) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$title, $when, $location !== '' ? $location : null, $agenda !== '' ? $agenda : null, (int) $user['id']]);
            audit((int) $user['id'], 'association_meeting_created', 'meeting_id=' . (int) db()->lastInsertId());
            flash('success', 'Meeting scheduled.');
        } elseif ($module === 'meetings' && $action === 'save_minutes' && $canManage) {
            $meetingId = filter_var($_POST['meeting_id'] ?? null, FILTER_VALIDATE_INT);
            $minutes = trim((string) ($_POST['minutes'] ?? ''));
            $status = (string) ($_POST['status'] ?? 'completed');
            if (!$meetingId || strlen($minutes) > 30000 || !in_array($status, ['scheduled', 'completed', 'cancelled'], true)) {
                throw new InvalidArgumentException('Check the minutes and meeting status.');
            }
            $stmt = db()->prepare('UPDATE association_meetings SET minutes = ?, status = ? WHERE id = ?');
            $stmt->execute([$minutes !== '' ? $minutes : null, $status, (int) $meetingId]);
            audit((int) $user['id'], 'meeting_minutes_updated', 'meeting_id=' . (int) $meetingId . '; status=' . $status);
            flash('success', 'Meeting record updated.');
        } elseif ($module === 'meetings' && $action === 'mark_meeting_attendance' && user_has_permission($user, 'meetings.attendance.manage')) {
            $meetingId = filter_var($_POST['meeting_id'] ?? null, FILTER_VALIDATE_INT);
            $memberId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
            $attendance = (string) ($_POST['attendance_status'] ?? '');
            if (!$meetingId || !$memberId || !in_array($attendance, ['present', 'absent', 'excused'], true)) {
                throw new InvalidArgumentException('Select a valid meeting, member, and attendance status.');
            }
            $stmt = db()->prepare("SELECT id FROM users WHERE id = ? AND status = 'active' AND email_verified_at IS NOT NULL");
            $stmt->execute([(int) $memberId]);
            if (!$stmt->fetchColumn()) {
                throw new InvalidArgumentException('Select an active, email-verified member.');
            }
            $stmt = db()->prepare("SELECT id FROM association_meetings WHERE id=? AND status <> 'cancelled'");
            $stmt->execute([(int) $meetingId]);
            if (!$stmt->fetchColumn()) {
                throw new InvalidArgumentException('Attendance can only be recorded for an active meeting.');
            }
            $stmt = db()->prepare('INSERT INTO meeting_attendance (meeting_id, user_id, attendance_status, marked_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE attendance_status = VALUES(attendance_status), marked_by = VALUES(marked_by), marked_at = UTC_TIMESTAMP()');
            $stmt->execute([(int) $meetingId, (int) $memberId, $attendance, (int) $user['id']]);
            audit((int) $user['id'], 'meeting_attendance_marked', 'meeting_id=' . (int) $meetingId . '; user_id=' . (int) $memberId . '; status=' . $attendance);
            flash('success', 'Meeting attendance recorded.');
        } elseif ($module === 'documents' && $action === 'upload_document' && user_has_permission($user, 'documents.upload')) {
            association_uploaded_document((int) $user['id']);
            flash('success', 'Document uploaded to private storage.');
        } elseif ($module === 'settings' && $action === 'save_settings' && $canManage) {
            $values = [
                'association_name' => trim((string) ($_POST['association_name'] ?? '')),
                'association_email' => trim((string) ($_POST['association_email'] ?? '')),
                'association_phone' => trim((string) ($_POST['association_phone'] ?? '')),
                'association_address' => trim((string) ($_POST['association_address'] ?? '')),
                'association_about' => trim((string) ($_POST['association_about'] ?? '')),
            ];
            if (strlen($values['association_name']) > 160 || strlen($values['association_phone']) > 40
                || strlen($values['association_address']) > 300 || strlen($values['association_about']) > 2000
                || ($values['association_email'] !== '' && !filter_var($values['association_email'], FILTER_VALIDATE_EMAIL))) {
                throw new InvalidArgumentException('Check the association contact details and field lengths.');
            }
            $stmt = db()->prepare('INSERT INTO association_settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = UTC_TIMESTAMP()');
            foreach ($values as $key => $value) {
                $stmt->execute([$key, $value !== '' ? $value : null, (int) $user['id']]);
            }
            audit((int) $user['id'], 'association_settings_updated');
            flash('success', 'Association settings saved.');
        } else {
            throw new InvalidArgumentException('Your account cannot perform that action.');
        }
    } catch (InvalidArgumentException $exception) {
        $fail($exception->getMessage());
    }
    redirect('school_services.php?module=' . rawurlencode($module));
}

function association_csv(array $headers, array $rows, string $filename): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private, no-store');
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        throw new RuntimeException('Could not create report output.');
    }
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, $headers);
    foreach ($rows as $row) {
        fputcsv($output, array_map(static function ($value): string {
            $value = (string) ($value ?? '');
            return preg_match('/^[\x00-\x20]*[=+\-@]/', $value) ? "'" . $value : $value;
        }, $row));
    }
    fclose($output);
    exit;
}

if ($module === 'reports' && isset($_GET['export'])) {
    $report = (string) $_GET['export'];
    if ($report === 'members') {
        if (!user_has_permission($user, 'members.view')) {
            http_response_code(403);
            exit('You cannot export the member register.');
        }
        $rows = db()->query("SELECT u.id, u.full_name, u.username, u.email, u.status, mp.membership_number, mp.membership_status FROM users u JOIN member_profiles mp ON mp.user_id=u.id ORDER BY u.created_at DESC")->fetchAll(PDO::FETCH_NUM);
        association_csv(['ID','Name','Username','Email','Account status','Membership number','Membership status','Requested role'], $rows, 'association-members.csv');
    } elseif ($report === 'payments' && user_has_permission($user, 'finance.view')) {
        $rows = db()->query(
            "SELECT p.id, u.full_name, p.amount, COALESCE(a.amount,0) AS refunded_amount,
                    p.currency, p.payment_method, p.reference,
                    CASE WHEN a.payment_id IS NOT NULL THEN 'refunded' ELSE p.status END AS current_status,
                    p.paid_at, p.note, a.reason AS refund_reason
             FROM payment_records p JOIN users u ON u.id=p.user_id
             LEFT JOIN payment_adjustments a ON a.payment_id=p.id AND a.adjustment_type='refund'
             ORDER BY p.paid_at DESC"
        )->fetchAll(PDO::FETCH_NUM);
        association_csv(['ID','Member','Recorded amount','Refunded amount','Currency','Method','Reference','Current status','Paid at (UTC)','Note','Refund reason'], $rows, 'association-payments.csv');
    } elseif ($report === 'events') {
        $rows = db()->query("SELECT e.id, e.title, e.starts_at, e.location, e.status, COUNT(CASE WHEN er.registration_status='registered' THEN 1 END), COUNT(CASE WHEN er.attendance_status='present' AND er.registration_status='registered' THEN 1 END) FROM association_events e LEFT JOIN event_registrations er ON er.event_id=e.id GROUP BY e.id ORDER BY e.starts_at DESC")->fetchAll(PDO::FETCH_NUM);
        association_csv(['ID','Event','Starts at (UTC)','Location','Status','Registered','Present'], $rows, 'association-events.csv');
    } else {
        http_response_code(403);
        exit('That report is unavailable for your account.');
    }
}

$data = [];
$settings = [];
$meetingMembers = [];
if ($module === 'events') {
    $data = db()->query(
        "SELECT e.*,
            (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id=e.id AND r.registration_status='registered') AS registration_count,
            (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id=e.id AND r.user_id=" . (int) $user['id'] . " AND r.registration_status='registered') AS is_registered
         FROM association_events e
         WHERE e.status = 'published' OR " . ($canManage ? '1=1' : '1=0') . "
         ORDER BY e.starts_at DESC LIMIT 200"
    )->fetchAll();
    if (user_has_permission($user, 'events.attendance.manage')) {
        $attendanceRows = db()->query(
            "SELECT r.event_id, r.user_id, r.attendance_status, u.full_name, e.title
             FROM event_registrations r JOIN users u ON u.id=r.user_id
             JOIN association_events e ON e.id=r.event_id
             WHERE r.registration_status='registered'
             ORDER BY e.starts_at DESC,u.full_name LIMIT 300"
        )->fetchAll();
    }
} elseif ($module === 'announcements') {
    $conditions = ["a.status = 'published'", '(a.publish_at IS NULL OR a.publish_at <= UTC_TIMESTAMP())'];
    if (!$canManage) {
        $conditions[] = $isOfficer ? "a.audience IN ('all','members','officials')" : "a.audience IN ('all','members')";
    }
    $data = db()->query(
        'SELECT a.*, u.full_name AS author FROM association_announcements a JOIN users u ON u.id=a.created_by
         WHERE ' . implode(' AND ', $conditions) . ($canManage ? ' OR a.status IN (\'draft\',\'archived\')' : '') .
         ' ORDER BY COALESCE(a.publish_at,a.created_at) DESC LIMIT 200'
    )->fetchAll();
    $publishedAnnouncementKeys = [];
    foreach ($data as $announcement) {
        if ($announcement['status'] === 'published'
            && ($announcement['publish_at'] === null
                || strtotime((string) $announcement['publish_at'] . ' UTC') <= time())) {
            $publishedAnnouncementKeys[] = 'announcement:' . (int) $announcement['id'];
        }
    }
    if ($publishedAnnouncementKeys !== []) {
        $keyPlaceholders = implode(',', array_fill(0, count($publishedAnnouncementKeys), '?'));
        $stmt = db()->prepare(
            "UPDATE association_notifications
             SET read_at=UTC_TIMESTAMP()
             WHERE user_id=? AND category='announcement' AND read_at IS NULL
               AND notification_key IN ({$keyPlaceholders})"
        );
        $stmt->execute(array_merge([(int) $user['id']], $publishedAnnouncementKeys));
    }
} elseif ($module === 'finance') {
    if ($canManagePayments) {
        $data['dues'] = db()->query(
            'SELECT d.*,u.full_name AS author
             FROM membership_dues d JOIN users u ON u.id=d.created_by
             ORDER BY d.created_at DESC LIMIT 200'
        )->fetchAll();
    } else {
        $stmt = db()->prepare(
            "SELECT d.*,u.full_name AS author,
                    CASE WHEN COALESCE(due_adjustment.is_exempt,0)=1 THEN 0
                         ELSE GREATEST(d.amount-COALESCE(due_adjustment.credit_amount,0),0) END AS member_assessed,
                    COALESCE(paid.paid_amount,0) AS member_paid,
                    GREATEST(CASE WHEN COALESCE(due_adjustment.is_exempt,0)=1 THEN 0
                                  ELSE d.amount-COALESCE(due_adjustment.credit_amount,0) END-COALESCE(paid.paid_amount,0),0) AS member_balance,
                    CASE
                        WHEN COALESCE(due_adjustment.is_exempt,0)=1 THEN 'exempt'
                        WHEN GREATEST(d.amount-COALESCE(due_adjustment.credit_amount,0)-COALESCE(paid.paid_amount,0),0)=0 THEN 'paid'
                        WHEN d.due_date IS NOT NULL AND d.due_date<UTC_DATE() THEN 'overdue'
                        WHEN COALESCE(paid.paid_amount,0)>0 THEN 'partially paid'
                        ELSE 'outstanding'
                    END AS member_due_status
             FROM membership_dues d
             JOIN users u ON u.id=d.created_by
             LEFT JOIN (
                 SELECT due_id,user_id,SUM(CASE WHEN adjustment_type='credit' THEN amount ELSE 0 END) AS credit_amount,
                        MAX(adjustment_type='exemption') AS is_exempt
                 FROM membership_due_adjustments GROUP BY due_id,user_id
             ) due_adjustment ON due_adjustment.due_id=d.id AND due_adjustment.user_id=?
             LEFT JOIN (
                 SELECT p.due_id,SUM(p.amount-COALESCE(a.amount,0)) AS paid_amount
                 FROM payment_records p
                 LEFT JOIN payment_adjustments a
                   ON a.payment_id=p.id AND a.adjustment_type='refund'
                 WHERE p.user_id=? AND p.status='confirmed' AND p.due_id IS NOT NULL
                 GROUP BY p.due_id
             ) paid ON paid.due_id=d.id
             LEFT JOIN member_profiles mp ON mp.user_id=?
             WHERE d.status='open' AND (d.target_year IS NULL OR d.target_year=mp.graduation_year)
               AND (d.target_category IS NULL OR d.target_category=mp.membership_category)
             ORDER BY d.due_date,d.created_at DESC LIMIT 200"
        );
        $stmt->execute([(int) $user['id'], (int) $user['id'], (int) $user['id']]);
        $data['dues'] = $stmt->fetchAll();
    }
    if (user_has_permission($user, 'finance.manage')) {
        $data['monthly_delivery'] = db()->query(
            "SELECT d.billing_period,
                    SUM(n.status='sent') AS sent_count,
                    SUM(n.status IN ('pending','failed','sending','retry_queued')) AS outstanding_count,
                    SUM(n.status='failed' AND n.attempts >= 5) AS exhausted_count
             FROM membership_dues d
             LEFT JOIN membership_due_notifications n ON n.due_id=d.id
             WHERE d.due_type='monthly'
             GROUP BY d.id
             ORDER BY d.billing_period DESC
             LIMIT 1"
        )->fetch() ?: null;
        $data['reminder_runs'] = db()->query(
            'SELECT billing_period,started_at,finished_at,eligible_count,sent_count,failed_count,error_message
             FROM dues_reminder_runs ORDER BY started_at DESC,id DESC LIMIT 12'
        )->fetchAll();
        $data['failed_reminders'] = db()->query(
            "SELECT n.due_id,n.user_id,n.attempts,n.last_attempt_at,n.last_error,n.status AS reminder_status,
                    d.title,d.billing_period,d.status AS due_status,u.full_name
             FROM membership_due_notifications n
             JOIN membership_dues d ON d.id=n.due_id
             JOIN users u ON u.id=n.user_id
             WHERE n.status IN ('failed','retry_queued') AND d.due_type='monthly'
             ORDER BY n.last_attempt_at DESC,n.due_id DESC LIMIT 100"
        )->fetchAll();
    }
    foreach (db()->query("SELECT setting_key,setting_value FROM association_settings WHERE setting_key IN ('monthly_dues_amount','monthly_dues_currency','monthly_dues_email_day')")->fetchAll() as $item) {
        $settings[$item['setting_key']] = $item['setting_value'];
    }
    $paymentSql = "SELECT p.*, u.full_name AS member_name, d.title AS due_title,
                          COALESCE(a.amount,0) AS refunded_amount,
                          a.reason AS refund_reason,
                          CASE WHEN a.payment_id IS NOT NULL THEN 'refunded' ELSE p.status END AS display_status
                   FROM payment_records p JOIN users u ON u.id=p.user_id
                   LEFT JOIN membership_dues d ON d.id=p.due_id
                   LEFT JOIN payment_adjustments a ON a.payment_id=p.id AND a.adjustment_type='refund' ";
    if (!$canManagePayments) {
        $stmt = db()->prepare($paymentSql . 'WHERE p.user_id=? ORDER BY p.paid_at DESC LIMIT 200');
        $stmt->execute([(int) $user['id']]);
        $data['payments'] = $stmt->fetchAll();
    } else {
        $data['payments'] = db()->query($paymentSql . 'ORDER BY p.paid_at DESC LIMIT 300')->fetchAll();
    }
    if (user_has_permission($user, 'finance.manage') || user_has_permission($user, 'payments.manage')) {
        $data['members'] = db()->query("SELECT u.id,u.full_name FROM users u JOIN member_profiles mp ON mp.user_id=u.id WHERE u.status='active' AND u.email_verified_at IS NOT NULL AND mp.membership_status='active' ORDER BY u.full_name LIMIT 500")->fetchAll();
    }
} elseif ($module === 'meetings') {
    $data = db()->query(
        'SELECT m.*, u.full_name AS author FROM association_meetings m JOIN users u ON u.id=m.created_by ORDER BY m.meeting_at DESC LIMIT 200'
    )->fetchAll();
    mark_sidebar_content_viewed(
        (int) $user['id'],
        'association_meeting',
        array_map(
            static fn(array $meeting): array => [
                'id' => (int) $meeting['id'],
                'updated_at' => (string) $meeting['updated_at'],
            ],
            array_values(array_filter(
                $data,
                static fn(array $meeting): bool => $meeting['status'] === 'scheduled'
                    && strtotime((string) $meeting['meeting_at'] . ' UTC') >= time()
            ))
        )
    );
    if (user_has_permission($user, 'meetings.attendance.manage')) {
        $meetingMembers = db()->query("SELECT u.id,u.full_name FROM users u JOIN member_profiles mp ON mp.user_id=u.id WHERE u.status='active' AND u.email_verified_at IS NOT NULL AND mp.membership_status='active' ORDER BY u.full_name LIMIT 500")->fetchAll();
    }
} elseif ($module === 'documents') {
    $docSql = 'SELECT d.*, u.full_name AS uploader FROM association_documents d JOIN users u ON u.id=d.uploaded_by';
    $data = $canManage ? db()->query($docSql . ' ORDER BY d.created_at DESC LIMIT 200')->fetchAll()
        : db()->query($docSql . " WHERE d.visibility='members' ORDER BY d.created_at DESC LIMIT 200")->fetchAll();
    mark_sidebar_content_viewed(
        (int) $user['id'],
        'association_document',
        array_map(
            static fn(array $document): array => [
                'id' => (int) $document['id'],
                'updated_at' => (string) $document['created_at'],
            ],
            $data
        )
    );
} elseif ($module === 'settings') {
    foreach (db()->query('SELECT setting_key, setting_value FROM association_settings')->fetchAll() as $item) {
        $settings[$item['setting_key']] = $item['setting_value'];
    }
} elseif ($module === 'reports') {
    $data = [
        'members' => (int) db()->query('SELECT COUNT(*) FROM member_profiles')->fetchColumn(),
        'active_members' => (int) db()->query("SELECT COUNT(*) FROM member_profiles mp JOIN users u ON u.id=mp.user_id WHERE mp.membership_status='active' AND u.status='active' AND u.email_verified_at IS NOT NULL")->fetchColumn(),
        'events' => (int) db()->query("SELECT COUNT(*) FROM association_events WHERE status='published'")->fetchColumn(),
        'registrations' => (int) db()->query("SELECT COUNT(*) FROM event_registrations WHERE registration_status='registered'")->fetchColumn(),
        'confirmed_payments' => user_has_permission($user, 'finance.view') ? (int) db()->query("SELECT COUNT(*) FROM payment_records p LEFT JOIN payment_adjustments a ON a.payment_id=p.id AND a.adjustment_type='refund' WHERE p.status='confirmed' AND a.payment_id IS NULL")->fetchColumn() : null,
        'payments_total' => user_has_permission($user, 'finance.view') ? db()->query("SELECT p.currency, SUM(p.amount - COALESCE(a.amount,0)) AS amount FROM payment_records p LEFT JOIN payment_adjustments a ON a.payment_id=p.id AND a.adjustment_type='refund' WHERE p.status='confirmed' GROUP BY p.currency ORDER BY p.currency")->fetchAll() : null,
        'pending_payments' => user_has_permission($user, 'finance.view') ? (int) db()->query("SELECT COUNT(*) FROM payment_records WHERE status='pending'")->fetchColumn() : null,
    ];
}

render_header($definition['title']);
page_title('ASSOCIATION MANAGEMENT', $definition['title'], $definition['description']);
?>
<?php if ($module === 'events'): ?>
    <?php if ($canManage): ?><section class="panel module-form-panel"><h2>Create event</h2><form method="post" class="module-form"><?= csrf_field() ?><input type="hidden" name="module" value="events"><input type="hidden" name="action" value="create_event">
        <label>Event title<input name="title" maxlength="160" required></label><label>Location<input name="location" maxlength="200"></label>
        <label>Starts (UTC)<input name="starts_at" type="datetime-local" required></label><label>Ends (UTC)<input name="ends_at" type="datetime-local"></label>
        <label>Registration deadline (UTC)<input name="registration_deadline" type="datetime-local"></label><label>Capacity<input name="capacity" type="number" min="1" max="1000000"></label><label>Publication<select name="status"><option value="draft">Draft</option><option value="published">Publish</option></select></label>
        <label class="span-all">Description<textarea name="description" maxlength="10000" rows="3"></textarea></label><button class="button button-primary" type="submit">Save event</button>
    </form></section><?php endif; ?>
    <div class="association-card-grid"><?php foreach ($data as $event): ?><article class="panel association-card">
        <div class="association-card-heading"><h2><?= e($event['title']) ?></h2><span class="status-pill <?= $event['status'] === 'published' ? 'status-good' : '' ?>"><?= e(ucfirst($event['status'])) ?></span></div>
        <p><strong><?= e(gmdate('M j, Y · H:i', strtotime($event['starts_at']))) ?> UTC</strong><?= $event['location'] ? ' · ' . e($event['location']) : '' ?></p>
        <?php if ($event['registration_deadline']): ?><p class="muted">Registration closes <?= e(gmdate('M j, Y H:i', strtotime($event['registration_deadline']))) ?> UTC.</p><?php endif; ?>
        <?php if ($event['description']): ?><p class="module-body"><?= nl2br(e($event['description'])) ?></p><?php endif; ?>
        <p class="muted"><?= (int) $event['registration_count'] ?> registered<?= $event['capacity'] ? ' · ' . (int) $event['capacity'] . ' capacity' : '' ?></p>
        <?php if ((int) $event['is_registered']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="module" value="events"><input type="hidden" name="action" value="cancel_registration"><input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>"><button class="button button-quiet button-small" type="submit" <?= strtotime($event['starts_at']) < time() ? 'disabled' : '' ?>>Cancel registration</button></form>
        <?php elseif ($event['status'] === 'published' && strtotime($event['starts_at']) >= time() && (!$event['registration_deadline'] || strtotime($event['registration_deadline']) >= time())): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="module" value="events"><input type="hidden" name="action" value="register_event"><input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>"><button class="button button-primary button-small" type="submit">Register</button></form><?php endif; ?>
        <?php if ($canManage): ?><details class="top-gap"><summary>Edit event</summary><form method="post" class="module-form top-gap"><?= csrf_field() ?><input type="hidden" name="module" value="events"><input type="hidden" name="action" value="update_event"><input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
            <label>Event title<input name="title" maxlength="160" value="<?= e($event['title']) ?>" required></label><label>Location<input name="location" maxlength="200" value="<?= e((string) $event['location']) ?>"></label>
            <label>Starts (UTC)<input name="starts_at" type="datetime-local" value="<?= e(gmdate('Y-m-d\TH:i', strtotime($event['starts_at']))) ?>" required></label><label>Ends (UTC)<input name="ends_at" type="datetime-local" value="<?= e($event['ends_at'] ? gmdate('Y-m-d\TH:i', strtotime($event['ends_at'])) : '') ?>"></label>
            <label>Registration deadline (UTC)<input name="registration_deadline" type="datetime-local" value="<?= e($event['registration_deadline'] ? gmdate('Y-m-d\TH:i', strtotime($event['registration_deadline'])) : '') ?>"></label><label>Capacity<input name="capacity" type="number" min="1" max="1000000" value="<?= e((string) ($event['capacity'] ?? '')) ?>"></label>
            <label>Status<select name="status"><?php foreach (['draft', 'published', 'cancelled', 'completed'] as $eventStatus): ?><option value="<?= e($eventStatus) ?>" <?= $event['status'] === $eventStatus ? 'selected' : '' ?>><?= e(ucfirst($eventStatus)) ?></option><?php endforeach; ?></select></label>
            <label class="span-all">Description<textarea name="description" maxlength="10000" rows="3"><?= e((string) $event['description']) ?></textarea></label><button class="button button-primary" type="submit">Save event</button>
        </form></details><?php endif; ?>
    </article><?php endforeach; ?><?php if (!$data): ?><article class="panel"><p class="muted">No events to show yet.</p></article><?php endif; ?></div>
    <?php if (!empty($attendanceRows)): ?><section class="panel table-panel module-table"><h2>Event attendance</h2><div class="table-scroll"><table><thead><tr><th>Event</th><th>Member</th><th>Attendance</th><th>Update</th></tr></thead><tbody><?php foreach ($attendanceRows as $row): ?><tr><td><?= e($row['title']) ?></td><td><?= e($row['full_name']) ?></td><td><?= e(ucfirst($row['attendance_status'])) ?></td><td><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="module" value="events"><input type="hidden" name="action" value="mark_event_attendance"><input type="hidden" name="event_id" value="<?= (int) $row['event_id'] ?>"><input type="hidden" name="user_id" value="<?= (int) $row['user_id'] ?>"><select name="attendance_status"><option value="present">Present</option><option value="absent">Absent</option></select><button class="button button-small" type="submit">Save</button></form></td></tr><?php endforeach; ?></tbody></table></div></section><?php endif; ?>
<?php elseif ($module === 'announcements'): ?>
    <?php if ($canManage): ?><section class="panel module-form-panel"><h2>Write announcement</h2><form method="post" class="module-form"><?= csrf_field() ?><input type="hidden" name="module" value="announcements"><input type="hidden" name="action" value="create_announcement">
        <label>Title<input name="title" maxlength="180" required></label><label>Audience<select name="audience"><option value="all">All portal users</option><option value="members">Association members</option><option value="officials">Association officers</option></select></label>
        <label>Status<select name="status"><option value="draft">Save draft</option><option value="published">Publish now</option></select></label><label class="span-all">Message<textarea name="body" rows="5" maxlength="30000" required></textarea></label><button class="button button-primary" type="submit">Save announcement</button>
    </form></section><?php endif; ?>
    <div class="association-card-grid"><?php foreach ($data as $announcement): ?><article class="panel association-card"><div class="association-card-heading"><h2><?= e($announcement['title']) ?></h2><span class="status-pill"><?= e(ucfirst($announcement['status'])) ?></span></div><p class="muted">By <?= e($announcement['author']) ?> · <?= e(ucfirst($announcement['audience'])) ?> · <?= e(gmdate('M j, Y H:i', strtotime($announcement['publish_at'] ?: $announcement['created_at']))) ?> UTC</p><div class="module-body"><?php render_dashboard_read_more((string) $announcement['body']); ?></div>
        <?php if ($canManage && $announcement['status'] !== 'archived'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="module" value="announcements"><input type="hidden" name="action" value="publish_announcement"><input type="hidden" name="announcement_id" value="<?= (int) $announcement['id'] ?>"><button class="button button-quiet button-small" name="status" value="<?= $announcement['status'] === 'published' ? 'archived' : 'published' ?>" type="submit"><?= $announcement['status'] === 'published' ? 'Archive' : 'Publish' ?></button></form><?php endif; ?>
    </article><?php endforeach; ?><?php if (!$data): ?><article class="panel"><p class="muted">No announcements are available.</p></article><?php endif; ?></div>
<?php elseif ($module === 'finance'): ?>
    <?php if (user_has_permission($user, 'finance.manage')): ?><section class="panel module-form-panel"><h2>Monthly membership dues</h2><p class="muted">The campaign runs during the first seven days in <?= e((string) ($config['timezone'] ?? 'UTC')) ?>. It emails only active, verified members with an unpaid balance on the open monthly obligation. Earlier dues and payment history are preserved.</p><form method="post" class="module-form"><?= csrf_field() ?><input type="hidden" name="module" value="finance"><input type="hidden" name="action" value="save_monthly_dues_settings">
        <label>Monthly amount<input name="monthly_amount" type="number" min="0.01" max="9999999999.99" step="0.01" value="<?= e($settings['monthly_dues_amount'] ?? '') ?>" required></label><label>Currency<input name="monthly_currency" value="<?= e($settings['monthly_dues_currency'] ?? 'GHS') ?>" maxlength="3" pattern="[A-Za-z]{3}" required></label><label>Campaign day<input name="email_day" type="number" min="1" max="7" value="<?= e($settings['monthly_dues_email_day'] ?? '1') ?>" required><small class="muted">Choose a day from 1 to 7.</small></label><button class="button button-primary" type="submit">Save monthly settings</button>
    </form><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="module" value="finance"><input type="hidden" name="action" value="send_reminder_test"><button class="button button-quiet button-small" type="submit">Send test email to my account</button></form>
    <?php if (!empty($data['monthly_delivery'])): ?><p class="help-text">Latest monthly cycle <?= e(gmdate('F Y', strtotime($data['monthly_delivery']['billing_period']))) ?>: <?= (int) $data['monthly_delivery']['sent_count'] ?> accepted by SMTP; <?= (int) $data['monthly_delivery']['outstanding_count'] ?> reminders pending or retryable, including <?= (int) $data['monthly_delivery']['exhausted_count'] ?> exhausted after five attempts.</p><?php endif; ?>
    <?php if (!empty($data['reminder_runs'][0])): ?><p class="help-text">Last run: <?= e((string) ($data['reminder_runs'][0]['finished_at'] ?: $data['reminder_runs'][0]['started_at'])) ?> UTC. Next run window: days 1–7 of next month, on the configured day.</p><?php endif; ?></section>
    <section class="panel table-panel module-table"><h2>Reminder campaign history</h2><div class="table-scroll"><table><thead><tr><th>Campaign period</th><th>Started (UTC)</th><th>Finished (UTC)</th><th>Eligible</th><th>SMTP accepted</th><th>Failed</th><th>Run error</th></tr></thead><tbody><?php foreach ($data['reminder_runs'] as $run): ?><tr><td><?= e(gmdate('F Y', strtotime($run['billing_period']))) ?></td><td><?= e($run['started_at']) ?></td><td><?= e((string) ($run['finished_at'] ?? 'In progress')) ?></td><td><?= (int) $run['eligible_count'] ?></td><td><?= (int) $run['sent_count'] ?></td><td><?= (int) $run['failed_count'] ?></td><td><?= e((string) ($run['error_message'] ?? '')) ?></td></tr><?php endforeach; ?><?php if (!$data['reminder_runs']): ?><tr><td colspan="7" class="muted">No campaign run has been recorded yet.</td></tr><?php endif; ?></tbody></table></div></section>
    <section class="panel table-panel module-table"><h2>Failed reminder attempts</h2><div class="table-scroll"><table><thead><tr><th>Member</th><th>Campaign</th><th>Attempts</th><th>Last attempt</th><th>Failure</th><th>Action</th></tr></thead><tbody><?php foreach ($data['failed_reminders'] as $reminder): ?><tr><td><?= e($reminder['full_name']) ?></td><td><?= e($reminder['title']) ?><?php if ($reminder['billing_period']): ?><br><span class="muted"><?= e($reminder['billing_period']) ?></span><?php endif; ?></td><td><?= (int) $reminder['attempts'] ?> / 5</td><td><?= e((string) ($reminder['last_attempt_at'] ?? 'Not attempted')) ?></td><td><?= e((string) ($reminder['last_error'] ?? 'SMTP handoff failed')) ?></td><td><?php if ($reminder['reminder_status'] === 'retry_queued'): ?><span class="muted">Queued for retry</span><?php elseif ($reminder['attempts'] < 5 && $reminder['due_status'] === 'open'): ?><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="module" value="finance"><input type="hidden" name="action" value="retry_dues_reminder"><input type="hidden" name="due_id" value="<?= (int) $reminder['due_id'] ?>"><input type="hidden" name="user_id" value="<?= (int) $reminder['user_id'] ?>"><button class="button button-small" type="submit">Queue retry</button></form><?php else: ?><span class="muted">Manual review required</span><?php endif; ?></td></tr><?php endforeach; ?><?php if (!$data['failed_reminders']): ?><tr><td colspan="6" class="muted">No failed reminders are recorded.</td></tr><?php endif; ?></tbody></table></div></section><?php endif; ?>
    <?php if (user_has_permission($user, 'finance.manage')): ?><section class="panel module-form-panel"><h2>Create membership due</h2><form method="post" class="module-form"><?= csrf_field() ?><input type="hidden" name="module" value="finance"><input type="hidden" name="action" value="create_due">
        <label>Due name<input name="title" maxlength="160" required></label><label>Amount<input name="amount" type="number" min="0.01" max="9999999999.99" step="0.01" required></label><label>Currency<input name="currency" value="GHS" maxlength="3" pattern="[A-Za-z]{3}" required></label><label>Due date<input name="due_date" type="date"></label><label>Graduation year (optional)<input name="target_year" type="number" min="1900" max="<?= (int) gmdate('Y') ?>"></label><label>Member category (optional)<input name="target_category" maxlength="40" placeholder="Matches member profile category"></label><label class="span-all">Description<input name="description" maxlength="500"></label><button class="button button-primary" type="submit">Create due</button>
    </form></section><?php endif; ?>
    <section class="panel table-panel module-table"><h2><?= $canManagePayments ? 'Membership dues' : 'My dues and balances' ?></h2><div class="table-scroll"><table><thead><tr><th>Due</th><th>Cycle</th><th>Assessed</th><?php if (!$canManagePayments): ?><th>Paid</th><th>Balance</th><?php endif; ?><th>Deadline</th><th>Status</th><?php if (user_has_permission($user, 'finance.manage')): ?><th>Manage</th><?php endif; ?></tr></thead><tbody><?php foreach ($data['dues'] as $due): ?><tr><td><strong><?= e($due['title']) ?></strong><?php if ($due['description']): ?><br><span class="muted"><?= e($due['description']) ?></span><?php endif; ?><?php if (!empty($due['target_year']) || !empty($due['target_category'])): ?><br><span class="muted">Applies to <?= e((string) ($due['target_category'] ?? 'all categories')) ?><?= !empty($due['target_year']) ? ' · class of ' . (int) $due['target_year'] : '' ?></span><?php endif; ?></td><td><?= e($due['billing_period'] ? gmdate('F Y', strtotime($due['billing_period'])) : ($due['due_type'] === 'monthly' ? 'Monthly' : 'One-time')) ?></td><td><?= e($due['currency']) ?> <?= number_format((float) ($canManagePayments ? $due['amount'] : $due['member_assessed']), 2) ?></td><?php if (!$canManagePayments): ?><td><?= e($due['currency']) ?> <?= number_format((float) $due['member_paid'], 2) ?></td><td><?= e($due['currency']) ?> <?= number_format((float) $due['member_balance'], 2) ?></td><?php endif; ?><td><?= e($due['due_date'] ?: 'No deadline') ?></td><td><?= e(ucfirst($canManagePayments ? $due['status'] : $due['member_due_status'])) ?></td><?php if (user_has_permission($user, 'finance.manage')): ?><td><form method="post"><?= csrf_field() ?><input type="hidden" name="module" value="finance"><input type="hidden" name="action" value="change_due_status"><input type="hidden" name="due_id" value="<?= (int) $due['id'] ?>"><button class="button button-quiet button-small" name="status" value="<?= $due['status'] === 'open' ? 'closed' : 'open' ?>" type="submit"><?= $due['status'] === 'open' ? 'Close due' : 'Reopen due' ?></button></form></td><?php endif; ?></tr><?php endforeach; ?><?php if (!$data['dues']): ?><tr><td colspan="<?= ($canManagePayments ? 3 : 5) + 2 + (user_has_permission($user, 'finance.manage') ? 1 : 0) ?>" class="muted">No membership dues have been configured.</td></tr><?php endif; ?></tbody></table></div></section>
    <?php if (user_has_permission($user, 'finance.manage')): ?><section class="panel module-form-panel"><h2>Member exemption or due credit</h2><p class="muted">Each adjustment is permanent and audited. Credits reduce the assessed amount; exemptions waive the remaining due and are available only before confirmed payments exist.</p><form method="post" class="module-form"><?= csrf_field() ?><input type="hidden" name="module" value="finance"><input type="hidden" name="action" value="adjust_member_due"><label>Member<select name="user_id" required><option value="">Select active member</option><?php foreach ($data['members'] as $member): ?><option value="<?= (int) $member['id'] ?>"><?= e($member['full_name']) ?></option><?php endforeach; ?></select></label><label>Open due<select name="due_id" required><option value="">Select due</option><?php foreach ($data['dues'] as $due): if ($due['status'] === 'open'): ?><option value="<?= (int) $due['id'] ?>"><?= e($due['title']) ?> · <?= e($due['currency']) ?> <?= number_format((float) $due['amount'], 2) ?></option><?php endif; endforeach; ?></select></label><label>Adjustment<select name="adjustment_type"><option value="credit">Credit / reduction</option><option value="exemption">Full exemption</option></select></label><label>Credit amount<input name="amount" type="number" min="0.01" step="0.01"></label><label class="span-all">Reason<input name="reason" minlength="5" maxlength="500" required></label><button class="button button-primary" type="submit">Record adjustment</button></form></section><?php endif; ?>
    <?php if (user_has_permission($user, 'payments.manage') || user_has_permission($user, 'finance.manage')): ?><section class="panel module-form-panel"><h2>Record member payment</h2><form method="post" class="module-form"><?= csrf_field() ?><input type="hidden" name="module" value="finance"><input type="hidden" name="action" value="record_payment">
        <label>Member<select name="user_id" required><option value="">Select active member</option><?php foreach ($data['members'] as $member): ?><option value="<?= (int) $member['id'] ?>"><?= e($member['full_name']) ?></option><?php endforeach; ?></select></label><label>Due<select name="due_id"><option value="">Not assigned to a specific due</option><?php foreach ($data['dues'] as $due): if ($due['status'] === 'open'): ?><option value="<?= (int) $due['id'] ?>"><?= e($due['title']) ?> · <?= e($due['currency']) ?> <?= number_format((float) $due['amount'], 2) ?></option><?php endif; endforeach; ?></select></label>
        <label>Amount<input name="amount" type="number" min="0.01" step="0.01" required></label><label>Currency<input name="currency" value="GHS" maxlength="3" pattern="[A-Za-z]{3}" required></label><label>Method<select name="payment_method"><option value="cash">Cash</option><option value="bank_transfer">Bank transfer</option><option value="mobile_money">Mobile money</option><option value="cheque">Cheque</option><option value="other">Other</option></select></label><label>Paid at (UTC)<input name="paid_at" type="datetime-local" required></label><label>Reference<input name="reference" maxlength="100"></label><label>Note<input name="note" maxlength="500"></label><button class="button button-primary" type="submit">Record for review</button>
    </form></section><?php endif; ?>
    <section class="panel table-panel module-table"><h2><?= $canManagePayments ? 'Payment records' : 'My payment records' ?></h2><div class="table-scroll"><table><thead><tr><th>Member</th><th>Due</th><th>Amount</th><th>Method / reference</th><th>Status</th><th>Paid</th><th>Receipt / record</th><?php if ($canManagePayments): ?><th>Review</th><?php endif; ?></tr></thead><tbody><?php foreach ($data['payments'] as $payment): ?><tr><td><?= e($payment['member_name']) ?></td><td><?= e($payment['due_title'] ?: 'General payment') ?></td><td><?= e($payment['currency']) ?> <?= number_format((float) $payment['amount'], 2) ?><?php if ((float) $payment['refunded_amount'] > 0): ?><br><span class="muted">Refunded: <?= number_format((float) $payment['refunded_amount'], 2) ?></span><?php endif; ?></td><td><?= e(str_replace('_', ' ', $payment['payment_method'])) ?><?= $payment['reference'] ? '<br><span class="muted">' . e($payment['reference']) . '</span>' : '' ?></td><td><?= e(ucfirst($payment['display_status'])) ?></td><td><?= e(gmdate('Y-m-d', strtotime($payment['paid_at']))) ?></td><td><a href="payment_receipt.php?id=<?= (int) $payment['id'] ?>"><?= $payment['display_status'] === 'confirmed' ? 'Receipt' : 'View payment record' ?></a></td><?php if ($canManagePayments): ?><td><?php if ($payment['status'] === 'pending'): ?><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="module" value="finance"><input type="hidden" name="action" value="review_payment"><input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>"><button class="button button-small" name="status" value="confirmed">Confirm</button><button class="button button-quiet button-small" name="status" value="rejected">Reject</button></form><?php elseif ($payment['status'] === 'confirmed' && (float) $payment['refunded_amount'] === 0.0): ?><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="module" value="finance"><input type="hidden" name="action" value="refund_payment"><input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>"><label class="visually-hidden" for="refund-reason-<?= (int) $payment['id'] ?>">Refund reason</label><input id="refund-reason-<?= (int) $payment['id'] ?>" name="reason" maxlength="500" placeholder="Refund reason" required><button class="button button-quiet button-small" type="submit">Record refund</button></form><?php else: ?>—<?php endif; ?></td><?php endif; ?></tr><?php endforeach; ?><?php if (!$data['payments']): ?><tr><td colspan="<?= $canManagePayments ? 8 : 7 ?>" class="muted">No payment records found.</td></tr><?php endif; ?></tbody></table></div></section>
<?php elseif ($module === 'meetings'): ?>
    <?php if ($canManage): ?><section class="panel module-form-panel"><h2>Schedule meeting</h2><form method="post" class="module-form"><?= csrf_field() ?><input type="hidden" name="module" value="meetings"><input type="hidden" name="action" value="create_meeting"><label>Meeting title<input name="title" maxlength="160" required></label><label>Date and time (UTC)<input name="meeting_at" type="datetime-local" required></label><label>Location<input name="location" maxlength="200"></label><label class="span-all">Agenda<textarea name="agenda" rows="3" maxlength="20000"></textarea></label><button class="button button-primary" type="submit">Schedule meeting</button></form></section><?php endif; ?>
    <div class="association-card-grid"><?php foreach ($data as $meeting): ?><article class="panel association-card"><div class="association-card-heading"><h2><?= e($meeting['title']) ?></h2><span class="status-pill"><?= e(ucfirst($meeting['status'])) ?></span></div><p><strong><?= e(gmdate('M j, Y · H:i', strtotime($meeting['meeting_at']))) ?> UTC</strong><?= $meeting['location'] ? ' · ' . e($meeting['location']) : '' ?></p><?php if ($meeting['agenda']): ?><h3>Agenda</h3><p class="module-body"><?= nl2br(e($meeting['agenda'])) ?></p><?php endif; ?>
        <?php if ($canManage): ?><form method="post" class="form-stack"><?= csrf_field() ?><input type="hidden" name="module" value="meetings"><input type="hidden" name="action" value="save_minutes"><input type="hidden" name="meeting_id" value="<?= (int) $meeting['id'] ?>"><label for="minutes-<?= (int) $meeting['id'] ?>">Minutes</label><textarea id="minutes-<?= (int) $meeting['id'] ?>" name="minutes" rows="3" maxlength="30000"><?= e($meeting['minutes']) ?></textarea><label for="meeting-status-<?= (int) $meeting['id'] ?>">Status</label><select id="meeting-status-<?= (int) $meeting['id'] ?>" name="status"><option value="scheduled" <?= $meeting['status'] === 'scheduled' ? 'selected' : '' ?>>Scheduled</option><option value="completed" <?= $meeting['status'] === 'completed' ? 'selected' : '' ?>>Completed</option><option value="cancelled" <?= $meeting['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option></select><button class="button button-small" type="submit">Save minutes</button></form><?php elseif ($meeting['minutes']): ?><h3>Minutes</h3><p class="module-body"><?= nl2br(e($meeting['minutes'])) ?></p><?php endif; ?>
        <?php if (user_has_permission($user, 'meetings.attendance.manage')): ?><form method="post" class="inline-form module-attendance-form"><?= csrf_field() ?><input type="hidden" name="module" value="meetings"><input type="hidden" name="action" value="mark_meeting_attendance"><input type="hidden" name="meeting_id" value="<?= (int) $meeting['id'] ?>"><label class="visually-hidden" for="meeting-member-<?= (int) $meeting['id'] ?>">Member</label><select id="meeting-member-<?= (int) $meeting['id'] ?>" name="user_id" required><option value="">Select active member</option><?php foreach ($meetingMembers as $member): ?><option value="<?= (int) $member['id'] ?>"><?= e($member['full_name']) ?></option><?php endforeach; ?></select><select name="attendance_status"><option value="present">Present</option><option value="absent">Absent</option><option value="excused">Excused</option></select><button class="button button-small" type="submit">Record attendance</button></form><?php endif; ?>
    </article><?php endforeach; ?><?php if (!$data): ?><article class="panel"><p class="muted">No meetings are scheduled.</p></article><?php endif; ?></div>
<?php elseif ($module === 'documents'): ?>
    <?php if (user_has_permission($user, 'documents.upload')): ?><section class="panel module-form-panel"><h2>Upload association document</h2><p class="muted">Files are stored outside the public folder and require permission to download. Maximum 10 MB; PDF, DOCX, XLSX, CSV, TXT, JPG or PNG.</p><form method="post" enctype="multipart/form-data" class="module-form"><?= csrf_field() ?><input type="hidden" name="module" value="documents"><input type="hidden" name="action" value="upload_document"><label>Document title<input name="title" maxlength="160" required></label><label>Visibility<select name="visibility"><option value="members">Verified members</option><option value="officers">Association officers</option></select></label><label class="span-all">Description<input name="description" maxlength="500"></label><label class="span-all">File<input name="document" type="file" accept=".pdf,.docx,.xlsx,.csv,.txt,.jpg,.jpeg,.png" required></label><button class="button button-primary" type="submit">Upload document</button></form></section><?php endif; ?>
    <section class="panel table-panel module-table"><div class="table-scroll"><table><thead><tr><th>Document</th><th>Visibility</th><th>Uploaded by</th><th>Date</th><th>Download</th></tr></thead><tbody><?php foreach ($data as $document): ?><tr><td><strong><?= e($document['title']) ?></strong><?php if ($document['description']): ?><br><span class="muted"><?= e($document['description']) ?></span><?php endif; ?><br><span class="muted"><?= e($document['original_name']) ?> · <?= number_format((int) $document['file_size'] / 1024) ?> KB</span></td><td><?= e(ucfirst($document['visibility'])) ?></td><td><?= e($document['uploader']) ?></td><td><?= e(gmdate('Y-m-d', strtotime($document['created_at']))) ?></td><td><a class="button button-quiet button-small" href="school_services.php?module=documents&amp;download=<?= (int) $document['id'] ?>">Download</a></td></tr><?php endforeach; ?><?php if (!$data): ?><tr><td colspan="5" class="muted">No documents have been shared.</td></tr><?php endif; ?></tbody></table></div></section>
<?php elseif ($module === 'reports'): ?>
    <section class="stat-grid"><article class="panel stat-card"><span class="muted">Member profiles</span><strong><?= $data['members'] ?></strong></article><article class="panel stat-card"><span class="muted">Active verified members</span><strong><?= $data['active_members'] ?></strong></article><article class="panel stat-card"><span class="muted">Published events</span><strong><?= $data['events'] ?></strong></article><article class="panel stat-card"><span class="muted">Event registrations</span><strong><?= $data['registrations'] ?></strong></article><?php if (user_has_permission($user, 'finance.view')): ?><article class="panel stat-card"><span class="muted">Confirmed payment totals</span><?php if ($data['payments_total']): foreach ($data['payments_total'] as $currencyTotal): ?><strong><?= e($currencyTotal['currency']) ?> <?= number_format((float) $currencyTotal['amount'], 2) ?></strong><?php endforeach; else: ?><strong>0.00</strong><?php endif; ?><span class="muted"><?= $data['confirmed_payments'] ?> records · <?= $data['pending_payments'] ?> pending</span></article><?php endif; ?></section>
    <section class="panel report-links"><h2>Export reports</h2><p class="muted">Exports are generated at request time and include only the report selected.</p><?php if (user_has_permission($user, 'members.view')): ?><a class="button button-quiet" href="school_services.php?module=reports&amp;export=members">Member register CSV</a><?php endif; ?><a class="button button-quiet" href="school_services.php?module=reports&amp;export=events">Event attendance CSV</a><?php if (user_has_permission($user, 'finance.view')): ?><a class="button button-quiet" href="school_services.php?module=reports&amp;export=payments">Payment ledger CSV</a><?php endif; ?></section>
<?php elseif ($module === 'settings'): ?>
    <section class="panel module-form-panel"><h2>Association details</h2><p class="muted">These fields do not contain authentication credentials or SMTP secrets.</p><form method="post" class="module-form"><?= csrf_field() ?><input type="hidden" name="module" value="settings"><input type="hidden" name="action" value="save_settings"><label>Association name<input name="association_name" maxlength="160" value="<?= e($settings['association_name'] ?? '') ?>"></label><label>Contact email<input name="association_email" type="email" maxlength="254" value="<?= e($settings['association_email'] ?? '') ?>"></label><label>Contact phone<input name="association_phone" maxlength="40" value="<?= e($settings['association_phone'] ?? '') ?>"></label><label>Address<input name="association_address" maxlength="300" value="<?= e($settings['association_address'] ?? '') ?>"></label><label class="span-all">About the association<textarea name="association_about" maxlength="2000" rows="4"><?= e($settings['association_about'] ?? '') ?></textarea></label><button class="button button-primary" type="submit">Save settings</button></form></section>
<?php endif; ?>
<?php render_footer(); ?>
