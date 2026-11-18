<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$admin = require_platform_super_admin();
$adminId = (int) $admin['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $targetUserId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
    $newEmail = strtolower(trim((string) ($_POST['email'] ?? '')));
    $adminPassword = (string) ($_POST['admin_password'] ?? '');
    $identityConfirmed = ($_POST['identity_confirmed'] ?? '') === '1';

    if ($targetUserId === false || $targetUserId < 1 || $targetUserId === $adminId
        || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)
        || strlen($newEmail) > 254 || !$identityConfirmed || $adminPassword === '') {
        flash('error', 'Choose another account, enter a valid email address, confirm the identity check, and re-enter your password.');
        redirect('platform_email_correction.php');
    }

    $passwordStmt = db()->prepare('SELECT password_hash FROM users WHERE id = ? AND status = "active"');
    $passwordStmt->execute([$adminId]);
    $adminPasswordHash = $passwordStmt->fetchColumn();
    if (!is_string($adminPasswordHash) || !verify_password($adminPassword, $adminPasswordHash)) {
        flash('error', 'The current Platform Super Admin password is incorrect.');
        redirect('platform_email_correction.php?user_id=' . $targetUserId);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $targetStmt = $pdo->prepare('SELECT email FROM users WHERE id = ? FOR UPDATE');
        $targetStmt->execute([$targetUserId]);
        $oldEmail = $targetStmt->fetchColumn();
        if (!is_string($oldEmail)) {
            throw new RuntimeException('The selected account does not exist.');
        }
        if (!account_email_correction_allowed($oldEmail, $newEmail)) {
            throw new RuntimeException('This correction flow is only for accounts whose current email address is invalid.');
        }

        $updateStmt = $pdo->prepare(
            'UPDATE users SET email = ?, email_verified_at = NULL WHERE id = ?'
        );
        $updateStmt->execute([$newEmail, $targetUserId]);
        $pdo->prepare(
            'UPDATE email_verification_tokens
             SET code_hash = NULL, expires_at = NULL, sent_at = NULL, attempts = 0
             WHERE user_id = ? AND used_at IS NULL'
        )->execute([$targetUserId]);
        $pdo->prepare(
            'UPDATE login_otp_tokens
             SET code_hash = NULL, expires_at = NULL, sent_at = NULL, attempts = 0
             WHERE user_id = ?'
        )->execute([$targetUserId]);
        audit($adminId, 'user_email_correction_initiated', 'target_user_id=' . $targetUserId);
        $pdo->commit();
        flash('success', 'The address was updated and marked unverified. The account holder must verify the new address before signing in.');
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($exception->getCode() === '23000') {
            flash('error', 'That email address is already assigned to another account. No changes were saved.');
        } else {
            error_log('Platform email correction failed for target user ' . $targetUserId . ': ' . $exception->getMessage());
            flash('error', 'The email correction could not be saved. No changes were committed.');
        }
    } catch (RuntimeException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', $exception->getMessage());
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Platform email correction failed for target user ' . $targetUserId . ': ' . $exception->getMessage());
        flash('error', 'The email correction could not be saved. No changes were committed.');
    }
    redirect('platform_email_correction.php?user_id=' . $targetUserId);
}

$targetUserId = filter_var($_GET['user_id'] ?? null, FILTER_VALIDATE_INT);
$target = null;
if ($targetUserId !== false && $targetUserId !== null && $targetUserId > 0) {
    $targetStmt = db()->prepare('SELECT id, full_name, username, email, email_verified_at FROM users WHERE id = ?');
    $targetStmt->execute([$targetUserId]);
    $target = $targetStmt->fetch() ?: null;
}

render_header('Correct account email');
?>
<section class="panel page-heading">
    <p class="eyebrow">PLATFORM ADMINISTRATION</p>
    <h1>Correct an invalid account email</h1>
    <p class="muted">Use this audited process only when an account’s stored address is invalid. Confirm the account holder’s identity and the new address through your approved verification process. The account holder must then verify control of the new address before signing in.</p>
</section>
<section class="panel auth-card-narrow">
    <?php if ($target): ?>
        <p><strong><?= e((string) $target['full_name']) ?></strong> · @<?= e((string) $target['username']) ?></p>
        <p class="muted">Account ID <?= (int) $target['id'] ?> · Current email: <?= e((string) $target['email']) ?> · <?= $target['email_verified_at'] === null ? 'Unverified' : 'Currently marked verified' ?></p>
    <?php else: ?>
        <p class="muted">Enter the account ID supplied by the account holder. This process cannot change a currently valid email address.</p>
    <?php endif; ?>
    <form method="post" action="platform_email_correction.php" class="form-stack">
        <?= csrf_field() ?>
        <label for="user-id">Account ID</label>
        <input id="user-id" name="user_id" type="number" min="1" required value="<?= $target ? (int) $target['id'] : '' ?>">
        <label for="new-email">New email address</label>
        <input id="new-email" name="email" type="email" maxlength="254" autocomplete="email" required>
        <label for="admin-password">Your current password</label>
        <input id="admin-password" name="admin_password" type="password" autocomplete="current-password" required>
        <label class="check-label"><input type="checkbox" name="identity_confirmed" value="1" required> I have independently verified the account holder’s identity and authorization to update this address.</label>
        <button class="button button-primary" type="submit">Update and require email verification</button>
    </form>
    <p class="help-text">The operation clears outstanding email-verification and sign-in codes, sets the new address to unverified, and records an audit event. It does not change the account password, status, or roles.</p>
    <a class="text-link" href="platform_admin.php">Return to Platform Administration</a>
</section>
<?php render_footer(); ?>
