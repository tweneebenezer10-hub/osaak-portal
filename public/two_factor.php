<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
$user = require_platform_super_admin();
$userId = (int) $user['id'];
$stmt = db()->prepare('SELECT enabled, secret_ciphertext FROM totp_factors WHERE user_id = ?');
$stmt->execute([$userId]);
$factor = $stmt->fetch();
$totpEnabled = $factor && (bool) $factor['enabled'];
$stmt = db()->prepare('SELECT email_verified_at, password_hash FROM users WHERE id = ?');
$stmt->execute([$userId]);
$settings = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'begin_totp' && !$totpEnabled) {
        $secret = totp_secret();
        $stmt = db()->prepare(
            'INSERT INTO totp_factors (user_id, secret_ciphertext, enabled, enabled_at)
             VALUES (?, ?, 0, NULL)
             ON DUPLICATE KEY UPDATE secret_ciphertext = VALUES(secret_ciphertext), enabled = 0, enabled_at = NULL'
        );
        $stmt->execute([$userId, encrypt_totp_secret($secret)]);
        $_SESSION['totp_setup_secret'] = $secret;
        redirect('two_factor.php');
    }
    if ($action === 'confirm_totp' && !$totpEnabled) {
        $secret = (string) ($_SESSION['totp_setup_secret'] ?? '');
        if ($secret === '' || !verify_totp($secret, trim((string) ($_POST['code'] ?? '')))) {
            flash('error', 'That authenticator code did not match. Check that your phone time is correct and try again.');
            redirect('two_factor.php');
        }
        $stmt = db()->prepare('UPDATE totp_factors SET enabled = 1, enabled_at = UTC_TIMESTAMP() WHERE user_id = ?');
        $stmt->execute([$userId]);
        $stmt = db()->prepare('DELETE FROM remember_tokens WHERE user_id = ?');
        $stmt->execute([$userId]);
        $codes = [];
        $stmt = db()->prepare('INSERT INTO recovery_codes (user_id, code_hash) VALUES (?, ?)');
        for ($i = 0; $i < 8; $i++) {
            $code = bin2hex(random_bytes(8));
            $codeHash = password_hash($code, PASSWORD_DEFAULT);
            if ($codeHash === false) {
                throw new RuntimeException('Recovery-code hashing failed.');
            }
            $stmt->execute([$userId, $codeHash]);
            $codes[] = $code;
        }
        unset($_SESSION['totp_setup_secret']);
        $_SESSION['new_recovery_codes'] = $codes;
        audit($userId, 'totp_enabled');
        flash('success', 'Authenticator enabled. Save your recovery codes now; they are shown only once.');
        redirect('two_factor.php');
    }
    if ($action === 'disable_totp' && $totpEnabled) {
        $code = trim((string) ($_POST['code'] ?? ''));
        $secret = decrypt_totp_secret((string) $factor['secret_ciphertext']);
        if (!verify_password((string) ($_POST['password'] ?? ''), (string) $settings['password_hash'])
            || !verify_totp($secret, $code)) {
            flash('error', 'Current password or authenticator code is incorrect.');
            redirect('two_factor.php');
        }
        $stmt = db()->prepare('DELETE FROM totp_factors WHERE user_id = ?');
        $stmt->execute([$userId]);
        $stmt = db()->prepare('DELETE FROM recovery_codes WHERE user_id = ?');
        $stmt->execute([$userId]);
        $stmt = db()->prepare('DELETE FROM remember_tokens WHERE user_id = ?');
        $stmt->execute([$userId]);
        audit($userId, 'totp_disabled');
        flash('success', 'Authenticator and its recovery codes have been removed.');
        redirect('two_factor.php');
    }
    flash('error', 'Unknown two-factor action.');
    redirect('two_factor.php');
}

$setupSecret = (string) ($_SESSION['totp_setup_secret'] ?? '');
if (!$totpEnabled && $setupSecret === '' && $factor) {
    $setupSecret = decrypt_totp_secret((string) $factor['secret_ciphertext']);
    $_SESSION['totp_setup_secret'] = $setupSecret;
}
$recoveryCodes = $_SESSION['new_recovery_codes'] ?? null;
unset($_SESSION['new_recovery_codes']);
$stmt = db()->prepare('SELECT COUNT(*) FROM recovery_codes WHERE user_id = ? AND used_at IS NULL');
$stmt->execute([$userId]);
$remainingCodes = (int) $stmt->fetchColumn();
render_header('Sign-in security');
page_title('PLATFORM ADMINISTRATION', 'Sign-in security', 'Email OTP is required on every sign-in; an authenticator is an optional additional factor.');
?>
<section class="settings-grid">
    <article class="panel">
        <span class="eyebrow">AUTHENTICATOR APP</span><h2><?= $totpEnabled ? 'Authenticator is enabled' : 'Time-based one-time password' ?></h2>
        <p class="muted">Compatible with Google Authenticator and Microsoft Authenticator (RFC 6238).</p>
        <?php if ($totpEnabled): ?>
            <p class="status-pill status-good">Enabled</p><p class="help-text"><?= $remainingCodes ?> unused recovery codes remain.</p>
            <form method="post" action="two_factor.php" class="form-stack top-gap"><?= csrf_field() ?><input type="hidden" name="action" value="disable_totp">
                <label for="disable-password">Current password</label><input id="disable-password" name="password" type="password" autocomplete="current-password" required>
                <label for="disable-code">Current authenticator code</label><input id="disable-code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required>
                <button class="button button-danger" type="submit">Disable authenticator</button>
            </form>
        <?php elseif ($setupSecret !== ''): ?>
            <p class="help-text">In your authenticator app, add an account using this secret key. Enter the current six-digit code to confirm setup.</p>
            <div class="secret-box"><span>Secret key</span><code><?= e($setupSecret) ?></code></div>
            <p class="help-text">Account: <?= e($user['email']) ?> · Issuer: Secure Auth System · 30-second codes</p>
            <form method="post" action="two_factor.php" class="form-stack"><?= csrf_field() ?><input type="hidden" name="action" value="confirm_totp">
                <label for="setup-code">Authenticator code</label><input id="setup-code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>
                <button class="button button-primary" type="submit">Confirm and enable</button>
            </form>
        <?php else: ?>
            <form method="post" action="two_factor.php"><?= csrf_field() ?><input type="hidden" name="action" value="begin_totp"><button class="button button-primary" type="submit">Set up authenticator</button></form>
        <?php endif; ?>
        <?php if (is_array($recoveryCodes)): ?>
            <div class="recovery-box"><h3>Save these recovery codes</h3><p>Each code works once. This list will disappear when you leave or reload this page.</p><ul><?php foreach ($recoveryCodes as $code): ?><li><code><?= e($code) ?></code></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
    </article>
    <article class="panel">
        <span class="eyebrow">EMAIL OTP</span><h2>Required at every sign-in</h2>
        <p class="muted">A six-digit email code expires after five minutes and is limited to five attempts.</p>
        <p class="status-pill <?= $settings['email_verified_at'] !== null ? 'status-good' : '' ?>"><?= $settings['email_verified_at'] !== null ? 'Required' : 'Verify your email first' ?></p>
    </article>
</section>
<?php render_footer(); ?>
