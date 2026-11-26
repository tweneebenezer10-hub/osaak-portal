<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/school_fees.php';
require_once dirname(__DIR__) . '/app/school_support_schema.php';
require_once dirname(__DIR__) . '/app/platform_school_purge.php';
require_once dirname(__DIR__) . '/app/platform_docs_section.php';

$user = require_platform_super_admin();
$supportSchema = school_support_schema();
$supportReadTrackingAvailable = school_support_has_column($supportSchema['tickets'], 'read_at')
    && school_support_has_column($supportSchema['tickets'], 'replied_at')
    && school_support_has_column($supportSchema['messages'], 'read_at')
    && school_support_has_column($supportSchema['messages'], 'read_by_user_id');
$supportReplyIdempotencyAvailable = school_support_has_column($supportSchema['messages'], 'request_key');

$superAdminContact = platform_super_admin_contact_details();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save_super_admin_contact') {
        flash('error', 'Platform support contacts are centrally managed and cannot be changed here.');
        redirect('platform_admin.php');
    }
    if ($action === 'open_school_support_ticket') {
        $ticketId = filter_var($_POST['ticket_id'] ?? null, FILTER_VALIDATE_INT);
        if ($ticketId === false || $ticketId < 1) {
            flash('error', 'Choose a valid school support request.');
            redirect('platform_admin.php#school-support-requests');
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $ticketStmt = $pdo->prepare(
                'SELECT id FROM school_support_tickets WHERE id = ? FOR UPDATE'
            );
            $ticketStmt->execute([$ticketId]);
            if (!$ticketStmt->fetchColumn()) {
                throw new RuntimeException('The selected school support request was not found.');
            }
            if ($supportReadTrackingAvailable) {
                $pdo->prepare(
                    'UPDATE school_support_tickets
                     SET read_by_user_id = CASE WHEN read_at IS NULL THEN ? ELSE read_by_user_id END,
                         read_at = COALESCE(read_at, UTC_TIMESTAMP())
                     WHERE id = ?'
                )->execute([(int) $user['id'], $ticketId]);
                $pdo->prepare(
                    'UPDATE school_support_messages
                     SET read_by_user_id = CASE WHEN read_at IS NULL THEN ? ELSE read_by_user_id END,
                         read_at = COALESCE(read_at, UTC_TIMESTAMP())
                     WHERE ticket_id = ? AND read_at IS NULL
                       AND NOT EXISTS (
                           SELECT 1 FROM user_roles ur
                           JOIN roles r ON r.id = ur.role_id AND r.name = "platform_super_admin"
                           WHERE ur.user_id = school_support_messages.sender_user_id
                       )'
                )->execute([(int) $user['id'], $ticketId]);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Platform support message read update failed: ' . $exception->getMessage());
            flash('error', 'The support conversation could not be opened.');
            redirect('platform_admin.php#school-support-requests');
        }
        redirect('platform_admin.php?support_ticket_id=' . $ticketId . '#school-support-requests');
    }
    if ($action === 'reply_school_support_ticket') {
        $ticketId = filter_var($_POST['ticket_id'] ?? null, FILTER_VALIDATE_INT);
        $message = trim((string) ($_POST['message'] ?? ''));
        $replyKey = strtolower(trim((string) ($_POST['reply_key'] ?? '')));
        if ($ticketId === false || $ticketId < 1 || $message === '' || strlen($message) > 10000
            || preg_match('/\A[a-f0-9]{32}\z/', $replyKey) !== 1) {
            flash('error', 'Choose a valid school support request and enter a reply of up to 10,000 characters.');
            redirect('platform_admin.php#school-support-requests');
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $ticketStmt = $pdo->prepare(
                'SELECT id, school_id, user_id, subject FROM school_support_tickets
                 WHERE id = ? FOR UPDATE'
            );
            $ticketStmt->execute([$ticketId]);
            $ticket = $ticketStmt->fetch();
            if (!$ticket) {
                throw new RuntimeException('The selected school support request was not found.');
            }
            if ($supportReplyIdempotencyAvailable) {
                $pdo->prepare(
                    'INSERT INTO school_support_messages (ticket_id, sender_user_id, message, request_key)
                     VALUES (?, ?, ?, ?)'
                )->execute([$ticketId, (int) $user['id'], $message, $replyKey]);
            } else {
                $pdo->prepare(
                    'INSERT INTO school_support_messages (ticket_id, sender_user_id, message)
                     VALUES (?, ?, ?)'
                )->execute([$ticketId, (int) $user['id'], $message]);
            }
            if ($supportReadTrackingAvailable) {
                $pdo->prepare(
                    'UPDATE school_support_tickets
                     SET status = ?, replied_at = UTC_TIMESTAMP(), updated_at = NOW()
                     WHERE id = ? AND school_id = ?'
                )->execute(['in_progress', $ticketId, (int) $ticket['school_id']]);
            } else {
                $pdo->prepare(
                    'UPDATE school_support_tickets
                     SET status = ?, updated_at = NOW()
                     WHERE id = ? AND school_id = ?'
                )->execute(['in_progress', $ticketId, (int) $ticket['school_id']]);
            }
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
                    redirect('platform_admin.php?support_ticket_id=' . $ticketId . '#school-support-requests');
                }
            }
            error_log('Platform reply to school support ticket failed: ' . $exception->getMessage());
            flash('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'The support reply could not be saved.');
            redirect('platform_admin.php#school-support-requests');
        }
        try {
            create_user_notification(
                (int) $ticket['user_id'],
                'school-support-reply-' . $ticketId . '-' . substr(hash('sha256', $message . microtime()), 0, 24),
                'support',
                'IT Support replied to your request',
                'CONNEZER IT Support replied to: ' . (string) $ticket['subject'],
                'school_support.php?ticket_id=' . $ticketId
            );
        } catch (Throwable $exception) {
            error_log('School support reply notification failed for ticket ' . $ticketId . ': ' . $exception->getMessage());
        }
        audit((int) $user['id'], 'school_support_ticket_replied', 'school_id=' . (int) $ticket['school_id'] . ';ticket_id=' . $ticketId);
        flash('success', 'Your reply was saved to the school support request.');
        redirect('platform_admin.php?support_ticket_id=' . $ticketId . '#school-support-requests');
    }
    if ($action === 'update_school_logo') {
        audit((int) $user['id'], 'platform_school_logo_update_denied');
        http_response_code(403);
        exit('School logos can only be managed by authorized school administrators.');
    }
    if ($action === 'delete_school') {
        $schoolId = filter_var($_POST['school_id'] ?? null, FILTER_VALIDATE_INT);
        if ($schoolId === false || $schoolId < 1) {
            flash('error', 'Choose a valid school.');
            redirect('platform_admin.php');
        }

        $schoolStmt = db()->prepare('SELECT name, status, subscription_status, deleted_at FROM schools WHERE id = ? LIMIT 1');
        $schoolStmt->execute([$schoolId]);
        $school = $schoolStmt->fetch();
        if (!$school) {
            flash('error', 'The selected school could not be found.');
            redirect('platform_admin.php');
        }
        if ($school['deleted_at'] !== null) {
            flash('error', 'This school has already been deleted from active service.');
            redirect('platform_admin.php');
        }

        db()->beginTransaction();
        try {
            $suspendSchool = db()->prepare(
                'UPDATE schools
                 SET deleted_previous_status = status,
                     deleted_previous_subscription_status = subscription_status,
                     deleted_at = UTC_TIMESTAMP(),
                     status = "suspended",
                     subscription_status = "suspended",
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = ? AND deleted_at IS NULL'
            );
            $suspendSchool->execute([$schoolId]);
            if ($suspendSchool->rowCount() !== 1) {
                throw new RuntimeException('The school was already deleted or changed by another administrator.');
            }
            audit(
                (int) $user['id'],
                'platform_school_soft_deleted',
                'school_id=' . $schoolId . ';school_name=' . $school['name'] . ';previous_status=' . $school['status']
            );
            db()->commit();
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            error_log('Platform school soft-delete failed for school ' . $schoolId . ': ' . $exception->getMessage());
            flash('error', 'The school could not be removed from active service. No changes were saved.');
            redirect('platform_admin.php');
        }
        flash('success', 'School deleted from active service. Its records and uploaded files are retained and can be restored by a Platform Super Admin.');
        redirect('platform_admin.php');
    }
    if ($action === 'purge_school') {
        $schoolId = filter_var($_POST['school_id'] ?? null, FILTER_VALIDATE_INT);
        $confirmedName = trim((string) ($_POST['confirm_school_name'] ?? ''));
        if ($schoolId === false || $schoolId < 1
            || ($_POST['confirm_permanent_delete'] ?? '') !== '1'
            || $confirmedName === '') {
            flash('error', 'Confirm the permanent deletion by entering the school name and checking the confirmation box.');
            redirect('platform_admin.php#school-records');
        }

        db()->beginTransaction();
        try {
            $purgedSchool = platform_purge_school(db(), (int) $schoolId, $confirmedName);
            audit(
                (int) $user['id'],
                'platform_school_permanently_deleted',
                'school_id=' . (int) $schoolId . ';school_name=' . $purgedSchool['name']
                    . ';direct_records=' . (int) $purgedSchool['deleted_rows']
            );
            db()->commit();
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            error_log('Platform school permanent-delete failed for school ' . (int) $schoolId . ': ' . $exception->getMessage());
            flash('error', $exception instanceof DomainException
                ? $exception->getMessage()
                : 'The school could not be permanently deleted. No database changes were saved.');
            redirect('platform_admin.php#school-records');
        }

        $failedFiles = remove_purged_school_files($purgedSchool['files']);
        if ($failedFiles !== []) {
            error_log('School ' . (int) $schoolId . ' was permanently deleted, but private files could not be removed: ' . implode(', ', $failedFiles));
            flash('error', 'School data was permanently deleted, but some stored files could not be removed. Contact the system administrator to complete file cleanup.');
        } else {
            flash('success', 'School and all school-owned records and uploaded files were permanently deleted. Shared user accounts were retained.');
        }
        redirect('platform_admin.php#school-records');
    }
    if ($action === 'restore_school') {
        $schoolId = filter_var($_POST['school_id'] ?? null, FILTER_VALIDATE_INT);
        if ($schoolId === false || $schoolId < 1) {
            flash('error', 'Choose a valid school.');
            redirect('platform_admin.php');
        }
        db()->beginTransaction();
        try {
            $schoolNameStmt = db()->prepare('SELECT name FROM schools WHERE id = ? AND deleted_at IS NOT NULL FOR UPDATE');
            $schoolNameStmt->execute([$schoolId]);
            $schoolName = $schoolNameStmt->fetchColumn();
            if (!is_string($schoolName)) {
                throw new RuntimeException('The school is not deleted or has already been restored.');
            }
            $restoreSchool = db()->prepare(
                'UPDATE schools
                 SET status = COALESCE(deleted_previous_status, "pending"),
                     subscription_status = COALESCE(deleted_previous_subscription_status, "suspended"),
                     deleted_at = NULL,
                     deleted_previous_status = NULL,
                     deleted_previous_subscription_status = NULL,
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = ? AND deleted_at IS NOT NULL'
            );
            $restoreSchool->execute([$schoolId]);
            if ($restoreSchool->rowCount() !== 1) {
                throw new RuntimeException('The school is not deleted or has already been restored.');
            }
            audit((int) $user['id'], 'platform_school_restored', 'school_id=' . $schoolId . ';school_name=' . $schoolName);
            db()->commit();
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            throw $exception;
        }
        flash('success', 'School record restored with its previous status and subscription settings.');
        redirect('platform_admin.php');
    }
    if ($action === 'update_school') {
        $schoolId = filter_var($_POST['school_id'] ?? null, FILTER_VALIDATE_INT);
        $status = in_array((string) ($_POST['status'] ?? ''), ['pending', 'active', 'suspended', 'expired'], true) ? (string) $_POST['status'] : null;
        $subscriptionStatus = in_array((string) ($_POST['subscription_status'] ?? ''), ['trial', 'active', 'expired', 'suspended', 'cancelled'], true) ? (string) $_POST['subscription_status'] : null;
        $feeCents = school_fee_amount_cents($_POST['subscription_term_fee'] ?? null);
        $expiryInput = trim((string) ($_POST['subscription_expires_at'] ?? ''));
        $expiry = null;
        if ($expiryInput !== '') {
            $parsedExpiry = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $expiryInput, new DateTimeZone('UTC'));
            $dateErrors = DateTimeImmutable::getLastErrors();
            if ($parsedExpiry === false
                || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
                || $parsedExpiry->format('Y-m-d\TH:i') !== $expiryInput) {
                $expiry = false;
            } else {
                $expiry = $parsedExpiry->format('Y-m-d H:i:s');
            }
        }
        if ($schoolId === false || $schoolId < 1 || $status === null || $subscriptionStatus === null
            || $feeCents === null
            || $expiry === false) {
            flash('error', 'Choose valid school, subscription, fee, and expiry values before saving.');
            redirect('platform_admin.php');
        }

        $deletedCheck = db()->prepare('SELECT deleted_at FROM schools WHERE id = ? LIMIT 1');
        $deletedCheck->execute([$schoolId]);
        $deletedAt = $deletedCheck->fetchColumn();
        if ($deletedAt === false || $deletedAt !== null) {
            flash('error', 'This school is unavailable for editing. Restore it first if it was deleted.');
            redirect('platform_admin.php');
        }

        $startingTrial = $status === 'active'
            && $subscriptionStatus === 'trial'
            && trim((string) ($expiry ?? '')) === '';
        if ($startingTrial) {
            $expiry = school_subscription_trial_expiration(
                new DateTimeImmutable('now', new DateTimeZone('UTC'))
            )->format('Y-m-d H:i:s');
        }

        $stmt = db()->prepare(
            'UPDATE schools
             SET status = ?, subscription_status = ?, subscription_term_fee = ?,
                 subscription_expires_at = ?, updated_at = UTC_TIMESTAMP()
             WHERE id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([
            $status,
            $subscriptionStatus,
            intdiv($feeCents, 100) . '.' . str_pad((string) ($feeCents % 100), 2, '0', STR_PAD_LEFT),
            $expiry,
            $schoolId,
        ]);
        audit(
            (int) $user['id'],
            'platform_school_updated',
            'school_id=' . $schoolId
                . ';status=' . $status
                . ';subscription=' . $subscriptionStatus
                . ';subscription_term_fee=' . intdiv($feeCents, 100) . '.' . str_pad((string) ($feeCents % 100), 2, '0', STR_PAD_LEFT)
                . ';subscription_expires_at=' . ($expiry ?? 'none')
        );
        flash(
            'success',
            $startingTrial
                ? 'School record updated. The 14-day trial starts now and expires at ' . $expiry . ' UTC.'
                : 'School record updated.'
        );
        redirect('platform_admin.php');
    }
}

$stmt = db()->query(
    'SELECT s.id, s.name, s.short_name, s.logo_path, s.status, s.subscription_status, s.deleted_at,
            s.deleted_previous_status, s.deleted_previous_subscription_status,
            s.subscription_term_fee, s.subscription_expires_at, s.region, s.district,
            (SELECT COUNT(DISTINCT sm.user_id) FROM school_memberships sm WHERE sm.school_id = s.id AND sm.status = "active") AS member_count,
            (SELECT COUNT(*) FROM school_students student
             WHERE student.school_id = s.id AND student.school_status = "active") AS active_student_count,
            (SELECT COUNT(*) FROM school_staff staff
             WHERE staff.school_id = s.id AND staff.status = "active") AS active_staff_count
     FROM schools s ORDER BY s.name ASC'
);
$schools = $stmt->fetchAll();
$schoolStatusCounts = ['pending' => 0, 'active' => 0, 'suspended' => 0, 'expired' => 0, 'Deleted' => 0];
$schoolEnrollmentChart = [];
$platformStudentTotal = 0;
$platformStaffTotal = 0;
foreach ($schools as $schoolRow) {
    $schoolStatus = $schoolRow['deleted_at'] !== null
        ? 'Deleted'
        : (string) $schoolRow['status'];
    if (isset($schoolStatusCounts[$schoolStatus])) {
        $schoolStatusCounts[$schoolStatus]++;
    }
    if ($schoolRow['deleted_at'] === null) {
        $schoolEnrollmentChart[(string) $schoolRow['name']] = (int) $schoolRow['active_student_count'];
        $platformStudentTotal += (int) $schoolRow['active_student_count'];
        $platformStaffTotal += (int) $schoolRow['active_staff_count'];
    }
}
arsort($schoolEnrollmentChart);
$schoolEnrollmentChart = array_slice($schoolEnrollmentChart, 0, 10, true);
$registrationTrendStmt = db()->query(
    'SELECT DATE_FORMAT(created_at, "%Y-%m") AS month_label, COUNT(*) AS registrations
     FROM schools
     GROUP BY DATE_FORMAT(created_at, "%Y-%m")
     ORDER BY month_label DESC
     LIMIT 12'
);
$registrationTrendRows = array_reverse($registrationTrendStmt->fetchAll());
$schoolRegistrationChart = [];
foreach ($registrationTrendRows as $row) {
    $schoolRegistrationChart[(string) $row['month_label']] = (int) $row['registrations'];
}
if ($schoolRegistrationChart !== []) {
    $registrationCounts = $schoolRegistrationChart;
    $schoolRegistrationChart = [];
    $firstMonth = DateTimeImmutable::createFromFormat('!Y-m', (string) array_key_first($registrationCounts));
    $lastMonth = DateTimeImmutable::createFromFormat('!Y-m', (string) array_key_last($registrationCounts));
    if ($firstMonth !== false && $lastMonth !== false) {
        for ($month = $firstMonth; $month <= $lastMonth; $month = $month->modify('+1 month')) {
            $monthKey = $month->format('Y-m');
            $schoolRegistrationChart[$monthKey] = $registrationCounts[$monthKey] ?? 0;
        }
    } else {
        $schoolRegistrationChart = $registrationCounts;
    }
}
$pendingSchoolRequests = [];
try {
    $pendingRequestStmt = db()->query(
        "SELECT srr.id, srr.school_id, srr.role_name, srr.requested_at AS joined_at,
                s.name AS school_name, u.full_name, u.email
         FROM school_role_requests srr
         JOIN schools s ON s.id = srr.school_id
         JOIN users u ON u.id = srr.user_id
         JOIN school_proprietor_invitations invitation
           ON invitation.school_id = srr.school_id
          AND invitation.redeemed_request_id = srr.id
          AND invitation.redeemed_by_user_id = srr.user_id
          AND LOWER(TRIM(invitation.email)) = LOWER(TRIM(u.email))
         WHERE srr.status = 'pending' AND srr.role_name = 'school_proprietor'
           AND invitation.revoked_at IS NULL AND invitation.redeemed_at IS NOT NULL
           AND s.deleted_at IS NULL
           AND u.status = 'active' AND u.email_verified_at IS NOT NULL
           AND NOT EXISTS (
               SELECT 1 FROM school_memberships active_role
               WHERE active_role.school_id = srr.school_id AND active_role.status = 'active'
                 AND active_role.role_name IN ('school_proprietor', 'school_headmaster')
           )
         ORDER BY srr.requested_at ASC"
    );
    $pendingSchoolRequests = $pendingRequestStmt ? $pendingRequestStmt->fetchAll() : [];
} catch (Throwable $e) {}
$supportTicketTrackingSelect = $supportReadTrackingAvailable
    ? 'ticket.read_at, ticket.replied_at'
    : 'NULL AS read_at, NULL AS replied_at';
$schoolSupportTickets = [];
try {
    $schoolSupportStmt = db()->query(
        'SELECT ticket.id, ticket.school_id, ticket.user_id, ticket.subject, ticket.body, ticket.category,
                ticket.status, ticket.priority, ticket.created_at, ' . $supportTicketTrackingSelect . ',
                school.name AS school_name,
                reporter.full_name AS reporter_name,
                (SELECT GROUP_CONCAT(membership.role_name ORDER BY membership.role_name SEPARATOR ", ")
                 FROM school_memberships membership
                 WHERE membership.school_id = ticket.school_id AND membership.user_id = ticket.user_id
                   AND membership.status = "active") AS reporter_roles
         FROM school_support_tickets ticket
         JOIN schools school ON school.id = ticket.school_id
         JOIN users reporter ON reporter.id = ticket.user_id
         WHERE ticket.status <> "closed"
         ORDER BY FIELD(ticket.priority, "urgent", "high", "normal", "low"), ticket.created_at DESC
         LIMIT 100'
    );
    $schoolSupportTickets = $schoolSupportStmt ? $schoolSupportStmt->fetchAll() : [];
} catch (Throwable $e) {}

$platformSupportStatusChart = [];
try {
    $supportStatusStmt = db()->query(
        'SELECT status, COUNT(*) AS ticket_count
         FROM school_support_tickets
         GROUP BY status ORDER BY status'
    );
    if ($supportStatusStmt) {
        foreach ($supportStatusStmt->fetchAll() as $supportStatus) {
            $statusLabel = ucwords(str_replace('_', ' ', (string) $supportStatus['status']));
            $platformSupportStatusChart[$statusLabel] = (int) $supportStatus['ticket_count'];
        }
    }
} catch (Throwable $e) {}

$supportMessageTrendChart = [];
try {
    $supportMessageTrendStmt = db()->query(
        'SELECT DATE_FORMAT(message.created_at, "%Y-%m") AS month_label,
                COUNT(*) AS message_count
         FROM school_support_messages message
         JOIN school_support_tickets ticket ON ticket.id = message.ticket_id
         GROUP BY DATE_FORMAT(message.created_at, "%Y-%m")
         ORDER BY month_label DESC LIMIT 12'
    );
    if ($supportMessageTrendStmt) {
        foreach (array_reverse($supportMessageTrendStmt->fetchAll()) as $supportMonth) {
            $supportMessageTrendChart[(string) $supportMonth['month_label']] = (int) $supportMonth['message_count'];
        }
    }
} catch (Throwable $e) {}
$selectedSupportTicketId = filter_var($_GET['support_ticket_id'] ?? null, FILTER_VALIDATE_INT);
$selectedSupportTicketId = $selectedSupportTicketId === false || $selectedSupportTicketId < 1
    ? 0
    : (int) $selectedSupportTicketId;
$schoolSupportMessages = [];
$unreadSchoolSupportCount = 0;
if ($selectedSupportTicketId > 0) {
    foreach ($schoolSupportTickets as $supportTicket) {
        if ((int) $supportTicket['id'] === $selectedSupportTicketId) {
            if ($supportReadTrackingAvailable) {
                $readPdo = db();
                $readPdo->beginTransaction();
                try {
                    $readPdo->prepare(
                        'UPDATE school_support_tickets
                         SET read_by_user_id = CASE WHEN read_at IS NULL THEN ? ELSE read_by_user_id END,
                             read_at = COALESCE(read_at, UTC_TIMESTAMP())
                         WHERE id = ?'
                    )->execute([(int) $user['id'], $selectedSupportTicketId]);
                    $readPdo->prepare(
                        'UPDATE school_support_messages
                         SET read_by_user_id = CASE WHEN read_at IS NULL THEN ? ELSE read_by_user_id END,
                             read_at = COALESCE(read_at, UTC_TIMESTAMP())
                         WHERE ticket_id = ? AND read_at IS NULL
                           AND NOT EXISTS (
                               SELECT 1 FROM user_roles ur
                               JOIN roles r ON r.id = ur.role_id AND r.name = "platform_super_admin"
                               WHERE ur.user_id = school_support_messages.sender_user_id
                           )'
                    )->execute([
                        (int) $user['id'],
                        $selectedSupportTicketId,
                    ]);
                    $readPdo->commit();
                } catch (Throwable $exception) {
                    if ($readPdo->inTransaction()) {
                        $readPdo->rollBack();
                    }
                    error_log('Platform support conversation read update failed: ' . $exception->getMessage());
                    throw $exception;
                }
            }
            $supportMessageReadSelect = $supportReadTrackingAvailable
                ? 'message.read_at'
                : 'NULL AS read_at';
            $messageStmt = db()->prepare(
                'SELECT message.id, message.sender_user_id, message.message, message.created_at,
                        ' . $supportMessageReadSelect . ',
                        EXISTS (
                            SELECT 1 FROM user_roles ur
                            JOIN roles r ON r.id = ur.role_id AND r.name = "platform_super_admin"
                            WHERE ur.user_id = message.sender_user_id
                        ) AS sender_is_support,
                        sender.full_name AS sender_name
                 FROM school_support_messages message
                 JOIN users sender ON sender.id = message.sender_user_id
                 WHERE message.ticket_id = ? ORDER BY message.created_at ASC, message.id ASC'
            );
            $messageStmt->execute([$selectedSupportTicketId]);
            $schoolSupportMessages = $messageStmt->fetchAll();
            break;
        }
    }
}
if ($supportReadTrackingAvailable) {
    $unreadSchoolSupportStmt = db()->query(
        'SELECT COUNT(*) FROM school_support_tickets
         WHERE status <> "closed" AND read_at IS NULL'
    );
    $unreadSchoolSupportCount = (int) $unreadSchoolSupportStmt->fetchColumn();
}
$supportRoles = school_role_permissions();
$roleLabels = [
    'school_proprietor' => 'Proprietor / School Owner',
    'school_headmaster' => 'Headmaster',
    'school_assistant_headmaster' => 'Assistant Headmaster',
    'school_secretary' => 'Secretary / Administrator',
    'class_teacher' => 'Class Teacher',
    'subject_teacher' => 'Subject Teacher',
    'school_accountant' => 'Accountant',
    'school_parent' => 'Parent / Guardian',
    'school_student' => 'Student',
];
$transferRoles = [
    'school_proprietor',
    'school_headmaster',
    'school_assistant_headmaster',
    'school_secretary',
    'class_teacher',
    'subject_teacher',
    'school_accountant',
    'school_parent',
];

render_header('Platform administration');
?>
<section class="panel page-heading">
    <p class="eyebrow">PLATFORM ADMIN</p>
    <h1>School management</h1>
    <p class="muted">Manage school registration, activation, suspension, and subscription status across the multi-school SaaS environment.</p>
</section>

<section class="dashboard-group" aria-labelledby="platform-overview-heading">
    <div class="dashboard-group-heading">
        <p class="eyebrow">PLATFORM OVERVIEW</p>
        <h2 id="platform-overview-heading">Schools, students, and staff</h2>
    </div>
<section class="stat-grid" aria-label="Platform totals">
    <?php foreach ([
        ['school', 'Schools', count($schools) - $schoolStatusCounts['Deleted']],
        ['students', 'Active students', $platformStudentTotal],
        ['staff', 'Active staff', $platformStaffTotal],
    ] as [$icon, $label, $value]): ?>
        <article class="panel stat-card"><?php dashboard_section_heading($icon, $label); ?><strong><?= (int) $value ?></strong></article>
    <?php endforeach; ?>
</section>
<details class="dashboard-secondary-details">
    <summary>View school trends and enrollment charts</summary>
    <section class="dashboard-grid spaced-top" aria-label="School overview charts">
        <article class="panel"><?php render_dashboard_bar_chart('Schools by status', $schoolStatusCounts); ?></article>
        <article class="panel"><?php render_dashboard_bar_chart('Active student enrollment · top 10 non-deleted schools', $schoolEnrollmentChart, 'students'); ?></article>
        <article class="panel"><?php render_dashboard_line_chart('School registrations by month', $schoolRegistrationChart, 'schools'); ?></article>
    </section>
</details>
</section>
<section class="dashboard-group spaced-top" aria-labelledby="platform-monitoring-heading">
    <div class="dashboard-group-heading">
        <p class="eyebrow">ADMINISTRATIVE MONITORING</p>
        <h2 id="platform-monitoring-heading">Support activity</h2>
    </div>
<details class="dashboard-secondary-details">
    <summary>View support activity charts</summary>
    <section class="dashboard-grid spaced-top">
        <article class="panel"><?php render_dashboard_bar_chart('School support tickets by status', $platformSupportStatusChart, 'tickets'); ?></article>
        <article class="panel"><?php render_dashboard_line_chart('School support messages by month', $supportMessageTrendChart, 'messages'); ?></article>
    </section>
</details>
</section>

<div class="dashboard-group-heading">
    <p class="eyebrow">SCHOOL ADMINISTRATION</p>
    <h2>Manage schools and access</h2>
</div>
<section class="report-action-grid" aria-label="Platform administration actions">
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('person-check') ?></span>
        <div><h2>Correct invalid account email</h2><p>Use an audited, password-confirmed process and require the account holder to verify the replacement address.</p></div>
        <a class="button button-quiet" href="platform_email_correction.php">Correct Email</a>
    </article>
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('school') ?></span>
        <div><h2>Create a School Portal</h2><p>Enter the school and first Proprietor’s name, email, and phone. The Super Admin activates the school portal after setup.</p></div>
        <a class="button button-primary" href="schools.php#school-create-form">Create School</a>
    </article>
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('support') ?></span>
        <div><h2>School support requests<?= $supportReadTrackingAvailable ? ' (' . $unreadSchoolSupportCount . ' unread)' : '' ?></h2><p>Review school requests and reply to the staff who submitted them.</p></div>
        <a class="button button-primary" href="#school-support-requests" data-action-open="school-support-requests">Review Requests</a>
    </article>
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('person-check') ?></span>
        <div><h2>Approve School Access</h2><p>Review verified school-role requests awaiting platform approval.</p></div>
        <a class="button" href="#pending-school-requests" data-action-open="pending-school-requests">Review Access</a>
    </article>
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('transfer') ?></span>
        <div><h2>Transfer a School Assignment</h2><p>Move a verified account to another school with an audited reason.</p></div>
        <a class="button" href="#school-assignment-transfer" data-action-open="school-assignment-transfer">Transfer Assignment</a>
    </article>
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('school') ?></span>
        <div><h2>School Portal Access</h2><p>Open a read-only school role preview for authorized troubleshooting.</p></div>
        <a class="button" href="#school-portal-access" data-action-open="school-portal-access">Open Portal Access</a>
    </article>
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('classroom') ?></span>
        <div><h2>Manage School Records</h2><p>Review school status and subscriptions, or restore retained school records.</p></div>
        <a class="button" href="#school-records" data-action-open="school-records">Manage Schools</a>
    </article>
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('security') ?></span>
        <div><h2>User Password Hashes</h2><p>Inspect every registered user account's full name, username, email, and Argon2id password hash.</p></div>
        <a class="button" href="#user-password-hashes" data-action-open="user-password-hashes">View User Hashes</a>
    </article>
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('documents') ?></span>
        <div><h2>Project Documentation</h2><p>Open Project Documentation &amp; Security Information in an independent window.</p></div>
        <a class="button" href="platform_documentation.php" target="_blank" rel="noopener noreferrer">Open Documentation ↗</a>
    </article>
</section>

<?php render_dashboard_profile_card($user); ?>

<div class="spaced-top">
    <?php render_it_support_contact_section($superAdminContact); ?>
</div>
<section class="panel spaced-top">
    <p class="eyebrow">SUPER ADMIN CONTACT INFORMATION</p>
    <h2>Platform support</h2>
    <p class="muted">Use Create a School to register a school portal, then designate its first Proprietor on the School workspaces page.</p>
    <p class="muted">Official platform support contacts are configured centrally.</p>
</section>

<section class="panel spaced-top" id="school-support-requests" data-action-view="school-support-requests">
    <p class="eyebrow">IT SUPPORT</p>
    <h2>School support requests</h2>
    <?php if (!$supportReadTrackingAvailable): ?>
        <p class="notice notice-warning">Conversation read tracking requires database migration 080. Existing support requests and replies remain available.</p>
    <?php endif; ?>
    <?php if (!$supportReplyIdempotencyAvailable): ?>
        <p class="notice notice-warning">Duplicate-reply protection requires database migration 080.</p>
    <?php endif; ?>
    <?php if ($schoolSupportTickets === []): ?>
        <p class="muted">There are no open school support requests.</p>
    <?php else: ?>
        <div class="table-scroll"><table>
            <thead><tr><th>School / requester</th><th>Request</th><th>Category</th><th>Priority / status</th><th>Submitted</th><th>Reply</th></tr></thead>
            <tbody><?php foreach ($schoolSupportTickets as $ticket): ?>
                <tr>
                    <td><?= e((string) $ticket['school_name']) ?><br><?= e((string) $ticket['reporter_name']) ?><br><small><?= e((string) ($ticket['reporter_roles'] ?: 'School user')) ?></small></td>
                    <td>
                        <strong><?= e((string) $ticket['subject']) ?></strong><br>
                        <?php if ($selectedSupportTicketId === (int) $ticket['id']): ?>
                            <?= nl2br(e((string) $ticket['body'])) ?>
                            <?php foreach ($schoolSupportMessages as $supportMessage): ?>
                                <p><strong><?= e((string) $supportMessage['sender_name']) ?></strong> · <?= e((string) $supportMessage['created_at']) ?> · <?= !$supportReadTrackingAvailable ? 'Read tracking pending migration 080' : ((int) $supportMessage['sender_is_support'] === 1 ? ($supportMessage['read_at'] === null ? 'Sent' : 'Read by school') : 'Read by support') ?><br><?= nl2br(e((string) $supportMessage['message'])) ?></p>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <form method="post" action="platform_admin.php#school-support-requests">
                                <?= csrf_field() ?><input type="hidden" name="action" value="open_school_support_ticket"><input type="hidden" name="ticket_id" value="<?= (int) $ticket['id'] ?>">
                                <button class="button button-quiet button-small" type="submit">Open conversation</button>
                            </form>
                        <?php endif; ?>
                    </td>
                    <td><?= e(ucwords(str_replace('_', ' ', (string) $ticket['category']))) ?></td>
                    <td>
                        <?= e(ucfirst((string) $ticket['priority'])) ?> / <?= e(ucwords(str_replace('_', ' ', (string) $ticket['status']))) ?><br>
                        <?php if ($supportReadTrackingAvailable): ?>
                            <?= $selectedSupportTicketId === (int) $ticket['id'] || $ticket['read_at'] !== null ? 'Read' : 'Unread' ?>
                            <?= $ticket['replied_at'] === null ? ' · Awaiting reply' : ' · Replied' ?>
                        <?php else: ?>
                            Read tracking pending migration 080
                        <?php endif; ?>
                    </td>
                    <td><?= e((string) $ticket['created_at']) ?></td>
                    <td><form method="post" action="platform_admin.php#school-support-requests" class="form-stack">
                        <?= csrf_field() ?><input type="hidden" name="action" value="reply_school_support_ticket"><input type="hidden" name="ticket_id" value="<?= (int) $ticket['id'] ?>">
                        <input type="hidden" name="reply_key" value="<?= e(bin2hex(random_bytes(16))) ?>">
                        <label class="visually-hidden" for="school-support-reply-<?= (int) $ticket['id'] ?>">Reply to <?= e((string) $ticket['school_name']) ?></label>
                        <textarea id="school-support-reply-<?= (int) $ticket['id'] ?>" name="message" rows="3" maxlength="10000" required></textarea>
                        <button class="button button-small" type="submit">Send reply</button>
                    </form></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
        <?php if (count($schoolSupportTickets) === 100): ?><p class="muted">Showing the 100 most recent open requests.</p><?php endif; ?>
    <?php endif; ?>
</section>

<section class="panel spaced-top" id="pending-school-requests" data-action-view="pending-school-requests">
    <p class="eyebrow">SCHOOL ACCOUNT ACTIVATION</p>
    <?php dashboard_section_heading('person-check', 'Older Proprietor requests awaiting review'); ?>
    <p class="muted">New designated Proprietors receive access automatically after verifying their email. This list contains only older invitation-based requests.</p>
    <?php if ($pendingSchoolRequests === []): ?>
        <p class="muted">There are no verified school requests waiting for approval.</p>
    <?php else: ?>
        <div class="table-scroll"><table>
            <thead><tr><th>Applicant</th><th>Email</th><th>School</th><th>Role</th><th>Requested</th><th>Action</th></tr></thead>
            <tbody>
                <?php foreach ($pendingSchoolRequests as $request): ?>
                    <tr>
                        <td><?= e((string) $request['full_name']) ?></td>
                        <td><?= e((string) $request['email']) ?></td>
                        <td><?= e((string) $request['school_name']) ?></td>
                        <td><?= e($roleLabels[$request['role_name']] ?? role_display_name((string) $request['role_name'])) ?></td>
                        <td><?= e((string) $request['joined_at']) ?></td>
                        <td>
                            <a class="button button-primary" href="school_role_requests.php?school_id=<?= (int) $request['school_id'] ?>">Review designated Proprietor request</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>

<section class="panel spaced-top" id="school-assignment-transfer" data-action-view="school-assignment-transfer">
    <p class="eyebrow">ACCOUNT ADMINISTRATION</p>
    <?php dashboard_section_heading('transfer', 'Transfer a school assignment'); ?>
    <p class="muted">Transfers are restricted to the Platform Super Admin, require a verified account and an audit reason, and leave one active school assignment. The account password is not changed.</p>
    <form method="post" action="transfer_school_assignment.php" class="form-stack">
        <?= csrf_field() ?>
        <div class="form-grid two-columns">
            <div><label for="account_email">Verified account email</label><input id="account_email" name="account_email" type="email" maxlength="254" required></div>
            <div><label for="source_school_id">Current school</label>
                <select id="source_school_id" name="source_school_id" required>
                    <option value="">Select source school</option>
                    <?php foreach ($schools as $school): ?>
                        <?php if ($school['deleted_at'] === null): ?>
                        <option value="<?= (int) $school['id'] ?>"><?= e((string) $school['name']) ?> (<?= e((string) $school['short_name']) ?>)</option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label for="target_school_id">New school</label>
                <select id="target_school_id" name="target_school_id" required>
                    <option value="">Select target school</option>
                    <?php foreach ($schools as $school): ?>
                        <?php if ($school['deleted_at'] === null && $school['status'] === 'active'): ?>
                            <option value="<?= (int) $school['id'] ?>"><?= e((string) $school['name']) ?> (<?= e((string) $school['short_name']) ?>)</option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label for="transfer_role">Role at new school</label>
                <select id="transfer_role" name="role_name" required>
                    <?php foreach ($transferRoles as $role): ?>
                        <option value="<?= e($role) ?>"><?= e($roleLabels[$role] ?? role_display_name($role)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="span-full"><label for="transfer_reason">Transfer reason</label><textarea id="transfer_reason" name="reason" rows="3" minlength="12" maxlength="200" required></textarea></div>
        </div>
        <button class="button button-primary" type="submit">Transfer school assignment</button>
    </form>
</section>

<section class="panel spaced-top" id="school-portal-access" data-action-view="school-portal-access">
    <p class="eyebrow">IT SUPPORT</p>
    <?php dashboard_section_heading('school', 'School portal access'); ?>
    <p class="muted">Open a role-scoped read-only preview or explicitly enable administrative support access. Student and Parent previews remain read-only; administrative changes are audited under your Super Admin account.</p>
    <label class="school-filter" for="school-portal-filter">Search schools</label>
    <input id="school-portal-filter" type="search" data-school-filter placeholder="Search by school, abbreviation, or status">
    <div class="school-support-grid" data-school-list>
        <?php foreach ($schools as $school): ?>
            <?php if ($school['deleted_at'] !== null) { continue; } ?>
                <article class="panel school-support-card" data-school-card data-school-search="<?= e(strtolower((string) $school['name'] . ' ' . (string) $school['short_name'] . ' ' . (string) $school['status'] . ' ' . (string) $school['subscription_status'])) ?>">
                    <div>
                        <?= school_identity_markup($school) ?>
                        <p class="muted">School: <?= e((string) $school['status']) ?> · Subscription: <?= e((string) $school['subscription_status']) ?></p>
                    </div>
                    <form method="post" action="start_it_support.php" class="form-stack">
                    <?= csrf_field() ?>
                    <input type="hidden" name="school_id" value="<?= (int) $school['id'] ?>">
                    <label for="preview-role-<?= (int) $school['id'] ?>">Preview portal as</label>
                    <select id="preview-role-<?= (int) $school['id'] ?>" name="preview_role" required>
                        <?php foreach ($supportRoles as $role => $_permissions): ?>
                            <option value="<?= e($role) ?>"><?= e($roleLabels[$role] ?? role_display_name($role)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label for="support-mode-<?= (int) $school['id'] ?>">Support access</label>
                    <select id="support-mode-<?= (int) $school['id'] ?>" name="access_mode" required>
                        <option value="read_only">Read-only preview</option>
                        <option value="administrative">Administrative access</option>
                    </select>
                    <label class="check-label">
                        <input type="checkbox" name="administrative_access_confirmed" value="1">
                        I understand administrative changes will affect this school and be audited.
                    </label>
                    <label for="support-reason-<?= (int) $school['id'] ?>">Support reason</label>
                    <input id="support-reason-<?= (int) $school['id'] ?>" name="reason" type="text" minlength="12" maxlength="500" required placeholder="Describe the troubleshooting request">
                    <button class="button button-primary" type="submit">Open school portal</button>
                </form>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<section id="school-records" class="spaced-top" data-action-view="school-records">
    <div class="page-heading">
        <p class="eyebrow">SCHOOL DIRECTORY</p>
        <h2>All schools (<?= count($schools) ?>)</h2>
        <p class="muted">This list includes all school records, including recoverable deleted schools. Active student counts use the school’s current student records.</p>
    </div>
    <div class="dashboard-grid">
    <?php foreach ($schools as $school): ?>
        <article class="panel">
            <p class="eyebrow"><?= $school['deleted_at'] === null ? e((string) $school['status']) : 'DELETED · RECOVERABLE' ?></p>
            <?= school_identity_markup($school) ?>
            <p class="muted">School ID: <?= (int) $school['id'] ?> · Identifier: <?= e((string) $school['short_name']) ?></p>
            <p class="muted">Active students: <?= (int) $school['active_student_count'] ?> · Active members: <?= (int) $school['member_count'] ?> · Region: <?= e((string) ($school['region'] ?: 'Not set')) ?></p>
            <p class="muted">Subscription: <?= e((string) $school['subscription_status']) ?> · Fee per term: GH₵<?= e(number_format((float) $school['subscription_term_fee'], 2)) ?></p>
            <?php if ($school['deleted_at'] !== null): ?>
                <div class="school-delete-warning">
                    <strong>Deleted from active service on <?= e((string) $school['deleted_at']) ?>.</strong>
                    <p>The school record, role assignments, academic and financial history, and uploaded files remain stored.</p>
                </div>
                <form method="post" action="platform_admin.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="restore_school">
                    <input type="hidden" name="school_id" value="<?= (int) $school['id'] ?>">
                    <button class="button button-primary" type="submit">Restore school</button>
                </form>
                <button class="button button-danger" type="button" data-open-school-purge-dialog data-school-id="<?= (int) $school['id'] ?>" data-school-name="<?= e((string) $school['name']) ?>">Delete permanently</button>
            <?php else: ?>
            <form method="post" action="platform_admin.php" class="form-stack">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_school">
                <input type="hidden" name="school_id" value="<?= (int) $school['id'] ?>">
                <label>School status</label>
                <select name="status">
                    <?php foreach (['pending', 'active', 'suspended', 'expired'] as $status): ?>
                        <option value="<?= e($status) ?>" <?= $school['status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                    <?php endforeach; ?>
                </select>
                <label>Subscription status</label>
                <select name="subscription_status">
                    <?php foreach (['trial', 'active', 'expired', 'suspended', 'cancelled'] as $status): ?>
                        <option value="<?= e($status) ?>" <?= $school['subscription_status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                    <?php endforeach; ?>
                </select>
                <label>Subscription fee per term (GH₵)</label>
                <input type="number" name="subscription_term_fee" step="0.01" min="0" max="9999999999.99" value="<?= e(number_format((float) $school['subscription_term_fee'], 2, '.', '')) ?>" required>
                <label>Subscription expires at</label>
                <input type="datetime-local" name="subscription_expires_at" value="<?= $school['subscription_expires_at'] ? date('Y-m-d\TH:i', strtotime((string) $school['subscription_expires_at'])) : '' ?>">
                <button class="button button-primary" type="submit">Update school</button>
            </form>
            <button class="button button-danger" type="button" data-open-school-delete-dialog data-school-id="<?= (int) $school['id'] ?>" data-school-name="<?= e((string) $school['name']) ?>">Delete school</button>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
    </div>
</section>
<dialog class="school-delete-dialog" data-school-delete-dialog aria-labelledby="school-delete-dialog-title">
    <form method="post" action="platform_admin.php" class="form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete_school">
        <input type="hidden" name="school_id" value="" data-school-delete-dialog-id>
        <h2 id="school-delete-dialog-title">Delete school?</h2>
        <p>Delete <strong data-school-delete-dialog-name></strong> from active service? School access will stop, but its records, history, and uploaded files will be retained and can be restored later.</p>
        <div class="school-delete-actions">
            <button class="button button-danger" type="submit">Delete</button>
            <button class="button button-quiet" type="submit" formmethod="dialog" formnovalidate>Cancel</button>
        </div>
    </form>
</dialog>
<dialog class="school-delete-dialog" data-school-purge-dialog aria-labelledby="school-purge-dialog-title">
    <form method="post" action="platform_admin.php" class="form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="purge_school">
        <input type="hidden" name="school_id" value="" data-school-purge-dialog-id>
        <h2 id="school-purge-dialog-title">Permanently delete school?</h2>
        <p>This permanently removes <strong data-school-purge-dialog-name></strong>, its school-owned records, and uploaded files. Global user accounts will be retained. This cannot be undone.</p>
        <label for="confirm-school-name">Type the exact school name to confirm</label>
        <input id="confirm-school-name" name="confirm_school_name" type="text" required autocomplete="off" data-school-purge-dialog-confirm-name>
        <label class="checkbox-label">
            <input type="checkbox" name="confirm_permanent_delete" value="1" required>
            I understand this action is permanent.
        </label>
        <div class="school-delete-actions">
            <button class="button button-danger" type="submit">Permanently delete</button>
            <button class="button button-quiet" type="submit" formmethod="dialog" formnovalidate>Cancel</button>
        </div>
    </form>
</dialog>

<?php
$platformUserHashes = [];
try {
    $userHashStmt = db()->query(
        'SELECT id, full_name, username, email, status, password_hash
         FROM users
         ORDER BY full_name ASC, id ASC'
    );
    $platformUserHashes = $userHashStmt ? $userHashStmt->fetchAll() : [];
} catch (Throwable $e) {
    error_log('Platform user hash query failed: ' . $e->getMessage());
}
?>
<section id="user-password-hashes" class="panel spaced-top" data-action-view="user-password-hashes" aria-labelledby="user-password-hashes-heading">
    <div class="page-heading">
        <p class="eyebrow">SECURITY AUDIT</p>
        <h2 id="user-password-hashes-heading">User Account Password Hashes (<?= count($platformUserHashes) ?>)</h2>
        <p class="muted">Displays each registered account's full name, username, email, status, and stored one-way password hash (Argon2id with per-password salt + HMAC-SHA256 pepper). Plaintext passwords and the server-side pepper are never stored or exposed.</p>
    </div>
    <?php if ($platformUserHashes === []): ?>
        <p class="muted">No user accounts were found.</p>
    <?php else: ?>
        <div class="table-scroll"><table>
            <thead>
                <tr>
                    <th>Full Name</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Hashed Password</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($platformUserHashes as $account): ?>
                    <tr>
                        <td><?= e((string) ($account['full_name'] ?? '')) ?></td>
                        <td><code><?= e((string) ($account['username'] ?? '')) ?></code></td>
                        <td><?= e((string) ($account['email'] ?? '')) ?></td>
                        <td><?= e((string) ($account['status'] ?? '')) ?></td>
                        <td><code><?= e((string) ($account['password_hash'] ?? '')) ?></code></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>

<?php
render_platform_documentation_section(true);
render_footer();
