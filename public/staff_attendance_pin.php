<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/school_attendance.php';
require_once APP_ROOT . '/app/school_staff_attendance.php';

$user = require_school_context();
$school = active_school_context();
if (!$school) {
    redirect('schools.php');
}
$schoolId = (int) $school['id'];
$userId = (int) $user['id'];
if (active_it_support_context($user) !== null) {
    http_response_code(403);
    render_header('Attendance PIN unavailable');
    echo '<section class="panel"><h1>Attendance PIN unavailable</h1><p>Staff self-service is unavailable during IT support access.</p></section>';
    render_footer();
    exit;
}
$staffStmt = db()->prepare(
    'SELECT staff.id, staff.role_name
     FROM school_staff staff
     JOIN users account ON account.id = staff.user_id
       AND account.status = "active" AND account.email_verified_at IS NOT NULL
     WHERE staff.school_id = ? AND staff.user_id = ? AND staff.status = "active"
       AND EXISTS (
           SELECT 1 FROM school_memberships membership
           WHERE membership.school_id = staff.school_id
             AND membership.user_id = staff.user_id
             AND membership.status = "active"
             AND membership.role_name NOT IN ("school_parent", "school_student")
       )
     LIMIT 1'
);
$staffStmt->execute([$schoolId, $userId]);
$staff = $staffStmt->fetch();
if (!$staff) {
    http_response_code(403);
    render_header('Attendance PIN access denied');
    echo '<section class="panel"><h1>Attendance PIN access denied</h1><p>Your account is not linked to an active staff record in this school.</p></section>';
    render_footer();
    exit;
}
$staffId = (int) $staff['id'];
$message = '';
$messageKind = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'submit_dispute') {
            $attendanceId = filter_var($_POST['attendance_id'] ?? null, FILTER_VALIDATE_INT);
            $disputeMessage = trim((string) ($_POST['message'] ?? ''));
            if ($disputeMessage === '' || mb_strlen($disputeMessage) > 1000) {
                throw new RuntimeException('Enter a dispute message of up to 1000 characters.');
            }
            if ($attendanceId === false || $attendanceId < 1) {
                $attendanceId = null;
            } else {
                $ownedRecord = db()->prepare(
                    'SELECT id FROM school_staff_attendance
                     WHERE id = ? AND school_id = ? AND staff_id = ?'
                );
                $ownedRecord->execute([$attendanceId, $schoolId, $staffId]);
                if (!$ownedRecord->fetchColumn()) {
                    throw new RuntimeException('Select one of your own attendance records.');
                }
            }
            db()->beginTransaction();
            db()->prepare(
                'INSERT INTO school_staff_attendance_disputes
                    (school_id, staff_id, attendance_id, submitted_by, message)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$schoolId, $staffId, $attendanceId, $userId, $disputeMessage]);
            audit($userId, 'school_staff_attendance_dispute_submitted', 'school_id=' . $schoolId . ';staff_id=' . $staffId);
            db()->commit();
            $message = 'Your attendance dispute has been sent to the school administrator.';
        } else {
            throw new RuntimeException('Choose a valid attendance action.');
        }
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        $messageKind = 'error';
        $message = $exception instanceof RuntimeException
            ? $exception->getMessage()
            : 'Your request could not be saved. No attendance record was changed.';
        if (!$exception instanceof RuntimeException) {
            error_log('Staff attendance self-service failed: ' . $exception->getMessage());
        }
    }
}

$historyStmt = db()->prepare(
    'SELECT id, attendance_date, arrival_at, departure_at, status, punctuality_status,
            minutes_late, verification_method
     FROM school_staff_attendance
     WHERE school_id = ? AND staff_id = ?
     ORDER BY attendance_date DESC LIMIT 30'
);
$historyStmt->execute([$schoolId, $staffId]);
$history = $historyStmt->fetchAll();
$disputesStmt = db()->prepare(
    'SELECT status, message, response, created_at
     FROM school_staff_attendance_disputes
     WHERE school_id = ? AND staff_id = ? AND submitted_by = ?
     ORDER BY created_at DESC LIMIT 20'
);
$disputesStmt->execute([$schoolId, $staffId, $userId]);
$disputes = $disputesStmt->fetchAll();

render_header('Staff attendance history');
?>
<section class="page-heading"><p class="eyebrow">STAFF SELF-SERVICE</p><h1>Attendance history</h1><p class="muted">Attendance records are read-only to staff.</p></section>
<?php if ($message !== ''): ?><p class="notice notice-<?= e($messageKind) ?>" role="<?= $messageKind === 'error' ? 'alert' : 'status' ?>"><?= e($message) ?></p><?php endif; ?>
<section class="panel"><h2>Attendance kiosk access</h2><p>Use your Staff ID and attendance PIN at the authorized school kiosk. This page does not ask you to save or change a PIN. If you cannot access the kiosk, ask the Headmaster or authorized administrator for in-person identity verification and PIN recovery.</p></section>
<section class="panel">
    <h2>My attendance history</h2>
    <?php if ($history === []): ?><p class="muted">No attendance records are available yet.</p><?php else: ?>
        <div class="table-scroll"><table><thead><tr><th>Date</th><th>Day</th><th>Arrival</th><th>Status</th><th>Minutes late</th><th>Verification</th><th>Departure</th><th>Dispute</th></tr></thead><tbody>
        <?php foreach ($history as $record): ?>
            <tr><td><?= e((string) $record['attendance_date']) ?></td><td><?= e((new DateTimeImmutable((string) $record['attendance_date']))->format('l')) ?></td><td><?= e((string) ($record['arrival_at'] ?? '—')) ?></td><td><?= e(ucwords(str_replace('_', ' ', (string) ($record['punctuality_status'] ?? $record['status'])))) ?></td><td><?= (int) $record['minutes_late'] ?></td><td><?= e(match ((string) $record['verification_method']) {
                'kiosk_verified' => 'KIOSK VERIFIED',
                'fingerprint_verified' => 'FINGERPRINT VERIFIED',
                'system_generated' => 'SYSTEM GENERATED',
                default => 'ADMINISTRATOR RECORDED',
            }) ?></td><td><?= e((string) ($record['departure_at'] ?? '—')) ?></td><td>
                <details><summary>Raise dispute</summary><form method="post" class="form-stack">
                    <?= csrf_field() ?><input type="hidden" name="action" value="submit_dispute"><input type="hidden" name="attendance_id" value="<?= (int) $record['id'] ?>">
                    <label>Explain the issue<textarea name="message" rows="3" maxlength="1000" required></textarea></label><button class="button button-small" type="submit">Submit dispute</button>
                </form></details>
            </td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>
<section class="panel">
    <h2>My disputes</h2>
    <?php if ($disputes === []): ?><p class="muted">You have not submitted any disputes.</p><?php else: ?><ul class="attendance-dispute-list">
        <?php foreach ($disputes as $dispute): ?><li><strong><?= e(ucfirst((string) $dispute['status'])) ?> · <?= e((string) $dispute['created_at']) ?></strong><p><?= e((string) $dispute['message']) ?></p><?php if ($dispute['response']): ?><p><strong>Administrator response:</strong> <?= e((string) $dispute['response']) ?></p><?php endif; ?></li><?php endforeach; ?>
    </ul><?php endif; ?>
</section>
<?php render_footer(); ?>
