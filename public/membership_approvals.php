<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
$manager = require_role('it_manager');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $applicantId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
    $action = (string) ($_POST['action'] ?? '');
    if (!$applicantId || !in_array($action, ['approve', 'reject'], true)) {
        flash('error', 'Choose a valid membership decision.');
        redirect('membership_approvals.php');
    }
    if ((int) $applicantId === (int) $manager['id']) {
        flash('error', 'You cannot approve or reject your own membership application.');
        redirect('membership_approvals.php');
    }

    $schoolStaffProfileRetained = false;
    db()->beginTransaction();
    try {
        $stmt = db()->prepare(
            'SELECT u.id, u.full_name, u.email, u.email_verified_at, mp.membership_status
             FROM users u
             JOIN member_profiles mp ON mp.user_id = u.id
             WHERE u.id = ?
             FOR UPDATE'
        );
        $stmt->execute([(int) $applicantId]);
        $applicant = $stmt->fetch();
        if (!$applicant || $applicant['membership_status'] !== 'pending') {
            db()->rollBack();
            flash('error', 'That membership application is no longer pending.');
            redirect('membership_approvals.php');
        }
        if ($action === 'approve' && $applicant['email_verified_at'] === null) {
            db()->rollBack();
            flash('error', 'The applicant must verify their email address before membership can be approved.');
            redirect('membership_approvals.php');
        }

        $newMembershipStatus = $action === 'approve' ? 'active' : 'rejected';
        $stmt = db()->prepare(
            'UPDATE member_profiles
             SET membership_status = ?, reviewed_by = ?, reviewed_at = UTC_TIMESTAMP(),
                 review_note = NULL,
                 joined_at = CASE WHEN ? = \'active\' THEN COALESCE(joined_at, UTC_TIMESTAMP()) ELSE joined_at END
             WHERE user_id = ? AND membership_status = \'pending\''
        );
        $stmt->execute([
            $newMembershipStatus,
            (int) $manager['id'],
            $newMembershipStatus,
            (int) $applicantId,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('The membership application changed while it was being reviewed.');
        }

        if ($action === 'approve') {
            $stmt = db()->prepare('UPDATE users SET status = \'active\' WHERE id = ?');
            $stmt->execute([(int) $applicantId]);
        } else {
            $stmt = db()->prepare('DELETE FROM remember_tokens WHERE user_id = ?');
            $stmt->execute([(int) $applicantId]);
            $stmt = db()->prepare('DELETE FROM member_role_requests WHERE user_id = ?');
            $stmt->execute([(int) $applicantId]);
            $staffProfileStmt = db()->prepare('SELECT COUNT(*) FROM school_staff WHERE user_id = ?');
            $staffProfileStmt->execute([(int) $applicantId]);
            $schoolStaffProfileRetained = (int) $staffProfileStmt->fetchColumn() > 0;
            if ($schoolStaffProfileRetained) {
                db()->prepare('UPDATE school_staff SET status = "inactive" WHERE user_id = ?')
                    ->execute([(int) $applicantId]);
                db()->prepare('UPDATE users SET status = "disabled" WHERE id = ?')
                    ->execute([(int) $applicantId]);
            } else {
                $stmt = db()->prepare('DELETE FROM users WHERE id = ?');
                $stmt->execute([(int) $applicantId]);
                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException('The rejected applicant account could not be removed.');
                }
            }
        }
        db()->commit();
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $exception;
    }

    audit(
        (int) $manager['id'],
        $action === 'approve' ? 'membership_application_approved' : 'membership_application_rejected',
        'applicant_user_id=' . (int) $applicantId
            . ($schoolStaffProfileRetained ? ';school_staff_archived=1;account_disabled=1' : '')
    );
    $associationName = association_display_name();
    $decision = $action === 'approve' ? 'approved' : 'not approved';
    $subject = $action === 'approve' ? 'Membership application approved' : 'Membership application decision';
    if ($action === 'approve') {
        $html = '<p>Hello ' . e((string) $applicant['full_name']) . ',</p>'
            . '<p>Your membership application to ' . e($associationName) . ' has been approved.</p>'
            . '<p>You can now sign in to access your member dashboard and any other workspaces assigned to your account.</p>';
        $text = 'Hello ' . (string) $applicant['full_name'] . ",\n\n"
            . 'Your membership application to ' . $associationName . " has been approved.\n\n"
            . 'You can now sign in to access your member dashboard and any other workspaces assigned to your account.';
    } else {
        $html = '<p>Hello ' . e((string) $applicant['full_name']) . ',</p>'
            . '<p>Your membership application to ' . e($associationName) . ' was not approved.</p>'
            . '<p>Please contact the association if you need further information.</p>';
        $text = 'Hello ' . (string) $applicant['full_name'] . ",\n\n"
            . 'Your membership application to ' . $associationName . " was not approved.\n\n"
            . 'Please contact the association if you need further information.';
    }
    $emailSent = send_mail((string) $applicant['email'], $subject, $html, $text);
    $managerMessage = 'Membership application ' . $decision . '.';
    if ($action === 'reject') {
        $managerMessage .= $schoolStaffProfileRetained
            ? ' The account was disabled and its school staff record was retained; existing attendance remains available to administrators.'
            : ' The applicant account was deleted and can register again.';
    }
    $managerMessage .= $emailSent
        ? ' The applicant was notified by email.'
        : ' The decision email could not be sent; check SMTP configuration and server logs.';
    flash('success', $managerMessage);
    redirect('membership_approvals.php');
}

$stmt = db()->query(
    'SELECT u.id, u.full_name, u.username, u.email, u.email_verified_at,
            u.created_at, mp.membership_number, mp.graduation_year,
            mp.requested_role, mp.phone
     FROM users u
     JOIN member_profiles mp ON mp.user_id = u.id
     WHERE mp.membership_status = \'pending\'
     ORDER BY u.created_at ASC, u.id ASC
     LIMIT 200'
);
$applications = $stmt->fetchAll();

render_header('Membership approvals');
page_title('TECHNICAL SETTINGS', 'Membership approvals', 'Review new member applications. Applicants cannot access member pages until an IT Manager approves their membership.');
?>
<section class="panel table-panel">
    <h2>Pending applications</h2>
    <?php if (!$applications): ?>
        <p class="muted">There are no pending membership applications.</p>
    <?php else: ?>
        <div class="table-scroll">
            <table>
                <thead>
                    <tr><th>Applicant</th><th>Contact</th><th>Membership details</th><th>Applied</th><th>Decision</th></tr>
                </thead>
                <tbody>
                <?php foreach ($applications as $application): ?>
                    <tr>
                        <td><strong><?= e((string) $application['full_name']) ?></strong><br><span class="muted">@<?= e((string) $application['username']) ?></span></td>
                        <td><?= e((string) $application['email']) ?><br><span class="status-pill <?= $application['email_verified_at'] !== null ? 'status-good' : '' ?>"><?= $application['email_verified_at'] !== null ? 'Email verified' : 'Email not verified' ?></span><br><?= e((string) ($application['phone'] ?? '')) ?></td>
                        <td><?= e((string) ($application['membership_number'] ?? 'No membership number')) ?><br><span class="muted">Graduation year: <?= e((string) ($application['graduation_year'] ?? 'Not recorded')) ?> · <?= e(str_replace('_', ' ', (string) $application['requested_role'])) ?></span></td>
                        <td><?= e((string) $application['created_at']) ?></td>
                        <td>
                            <form method="post" action="membership_approvals.php" class="form-stack membership-review-form" data-loading-form>
                                <?= csrf_field() ?>
                                <input type="hidden" name="user_id" value="<?= (int) $application['id'] ?>">
                                <div class="inline-form">
                                    <?php if ($application['email_verified_at'] !== null): ?>
                                        <button class="button button-small button-primary" type="submit" name="action" value="approve">Approve</button>
                                    <?php else: ?>
                                        <button class="button button-small button-primary" type="button" disabled aria-label="Approve — Applicant must verify their email first" title="Approve — Applicant must verify their email first">Approve</button>
                                    <?php endif; ?>
                                    <button class="button button-small button-danger" type="submit" name="action" value="reject" onclick="return confirm('Reject this membership application? The applicant will be notified by email.');">Reject</button>
                                </div>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
