<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
$userId = (int) ($_SESSION['pending_login_user_id'] ?? 0);
if ($userId < 1) {
    flash('error', 'Sign in with your password before verifying a second factor.');
    redirect('index.php');
}
$totpRequired = !empty($_SESSION['pending_login_totp']);
if (empty($_SESSION['pending_login_email_required'])) {
    $_SESSION['pending_login_email_ok'] = false;
    $_SESSION['pending_login_email_required'] = true;
}
$_SESSION['pending_login_email'] = true;
$emailRequired = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (factor_is_limited($userId)) {
        flash('error', 'Too many second-factor attempts. Wait 15 minutes before starting a new sign-in.');
        unset(
            $_SESSION['pending_login_user_id'],
            $_SESSION['pending_login_totp'],
            $_SESSION['pending_login_email'],
            $_SESSION['pending_login_email_required'],
            $_SESSION['pending_login_email_ok'],
            $_SESSION['pending_login_totp_ok']
        );
        redirect('index.php');
    }
    $method = (string) ($_POST['method'] ?? '');
    $code = trim((string) ($_POST['code'] ?? ''));
    $valid = false;
    if ($method === 'email' && $emailRequired && empty($_SESSION['pending_login_email_ok'])) {
        $stmt = db()->prepare('SELECT code_hash, expires_at, attempts FROM login_otp_tokens WHERE user_id = ?');
        $stmt->execute([$userId]);
        $token = $stmt->fetch();
        $valid = $token && $token['code_hash'] !== null && (int) $token['attempts'] < 5 && $token['expires_at']
            && strtotime((string) $token['expires_at'] . ' UTC') >= time()
            && preg_match('/^\d{6}$/', $code) && hash_equals($token['code_hash'], keyed_hash($code));
        if ($token && !$valid) {
            $stmt = db()->prepare('UPDATE login_otp_tokens SET attempts = attempts + 1 WHERE user_id = ?');
            $stmt->execute([$userId]);
        }
        if ($valid) {
            $_SESSION['pending_login_email_ok'] = true;
            $stmt = db()->prepare('DELETE FROM login_otp_tokens WHERE user_id = ?');
            $stmt->execute([$userId]);
        }
    } elseif ($method === 'totp' && $totpRequired && empty($_SESSION['pending_login_totp_ok'])) {
        $stmt = db()->prepare('SELECT secret_ciphertext FROM totp_factors WHERE user_id = ? AND enabled = 1');
        $stmt->execute([$userId]);
        $ciphertext = $stmt->fetchColumn();
        if ($ciphertext !== false && preg_match('/^\d{6}$/', $code)) {
            $valid = verify_totp(decrypt_totp_secret((string) $ciphertext), $code);
        }
        if (!$valid && preg_match('/^[a-f0-9]{16}$/i', $code)) {
            $stmt = db()->prepare('SELECT id, code_hash FROM recovery_codes WHERE user_id = ? AND used_at IS NULL');
            $stmt->execute([$userId]);
            foreach ($stmt->fetchAll() as $recovery) {
                if (password_verify(strtolower($code), $recovery['code_hash'])) {
                    $stmt = db()->prepare('UPDATE recovery_codes SET used_at = UTC_TIMESTAMP() WHERE id = ? AND used_at IS NULL');
                    $stmt->execute([(int) $recovery['id']]);
                    $valid = $stmt->rowCount() === 1;
                    break;
                }
            }
        }
        if ($valid) {
            $_SESSION['pending_login_totp_ok'] = true;
        }
    } elseif ($method === 'resend' && $emailRequired && empty($_SESSION['pending_login_email_ok'])) {
        $stmt = db()->prepare('SELECT email FROM users WHERE id = ? AND status = \'active\' AND email_verified_at IS NOT NULL');
        $stmt->execute([$userId]);
        $email = $stmt->fetchColumn();
        $stmt = db()->prepare('SELECT sent_at FROM login_otp_tokens WHERE user_id = ?');
        $stmt->execute([$userId]);
        $sentAt = $stmt->fetchColumn();
        if ($email && filter_var((string) $email, FILTER_VALIDATE_EMAIL)
            && (!$sentAt || strtotime((string) $sentAt . ' UTC') <= time() - 60)
            && issue_login_code($userId, (string) $email)) {
            flash('success', 'A new sign-in code was sent.');
        } elseif (!$email || !filter_var((string) $email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'This account has no valid registered email address. Contact the platform administrator to correct it securely.');
        } else {
            flash('error', 'The code cannot be resent yet, or email delivery failed. Wait one minute and check SMTP configuration.');
        }
        redirect('verify_login.php');
    }
    if (!$valid && $method !== 'resend') {
        record_factor_failure($userId);
    }

    if ($valid) {
        $stmt = db()->prepare(
            'SELECT u.status, u.email_verified_at, u.email, mp.membership_status
             FROM users u LEFT JOIN member_profiles mp ON mp.user_id = u.id
             WHERE u.id = ?'
        );
        $stmt->execute([$userId]);
        $account = $stmt->fetch();
        if (!$account || $account['status'] !== 'active' || $account['email_verified_at'] === null
            || !filter_var((string) $account['email'], FILTER_VALIDATE_EMAIL)
            || ($account['membership_status'] !== null && $account['membership_status'] !== 'active')) {
            clear_auth_session();
            flash('error', 'Sign-in is unavailable because this account cannot complete its required email verification.');
            redirect('index.php');
        }
        if (login_factors_complete(
            !empty($_SESSION['pending_login_email_ok']),
            $totpRequired,
            !empty($_SESSION['pending_login_totp_ok'])
        )) {
            complete_login($userId);
        }
        flash('success', 'This factor is verified. Complete the remaining sign-in check.');
        redirect('verify_login.php');
    }
    $_SESSION['pending_factor_attempts'] = (int) ($_SESSION['pending_factor_attempts'] ?? 0) + 1;
    if ($_SESSION['pending_factor_attempts'] >= 10) {
        audit($userId, 'login_2fa_locked');
        unset(
            $_SESSION['pending_login_user_id'],
            $_SESSION['pending_login_totp'],
            $_SESSION['pending_login_email'],
            $_SESSION['pending_login_email_required'],
            $_SESSION['pending_login_email_ok'],
            $_SESSION['pending_login_totp_ok']
        );
        flash('error', 'Too many verification attempts. Sign in again to start a new challenge.');
        redirect('index.php');
    }
    flash('error', 'That verification code is incorrect or expired.');
    redirect('verify_login.php');
}

$needTotp = $totpRequired && empty($_SESSION['pending_login_totp_ok']);
$needEmail = $emailRequired && empty($_SESSION['pending_login_email_ok']);
render_header('Verify sign-in', false);
?>
<section class="auth-card auth-card-narrow">
    <p class="eyebrow">SECOND-FACTOR CHECK</p><h1>Verify your sign-in</h1>
    <p class="muted">Email verification is required on every sign-in. Complete the authenticator check too, if it is enabled.</p>
    <?php if (!empty($_SESSION['pending_email_delivery_failed'])): ?><div class="notice notice-error" role="alert">The email code could not be sent, or a code is still within its resend cooldown. Use a code you already received, or resend after one minute. Sign-in remains blocked until email verification succeeds.</div><?php unset($_SESSION['pending_email_delivery_failed']); endif; ?>
    <?php if ($needTotp): ?>
    <form method="post" action="verify_login.php" class="form-stack"><?= csrf_field() ?><input type="hidden" name="method" value="totp"><label for="totp-code">Authenticator code or recovery code</label><input id="totp-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="16" required><button class="button button-primary button-wide" type="submit">Verify authenticator</button><p class="help-text">Enter the current six-digit authenticator code, or one unused 16-character recovery code.</p></form>
    <?php endif; ?>
    <?php if ($needEmail): ?>
    <form method="post" action="verify_login.php" class="form-stack"><?= csrf_field() ?><input type="hidden" name="method" value="email"><label for="email-code">Email verification code</label><input id="email-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required><button class="button button-primary button-wide" type="submit">Verify email code</button><p class="help-text">The email code expires in five minutes and has five attempts.</p></form>
    <form method="post" action="verify_login.php" class="compact-form"><?= csrf_field() ?><input type="hidden" name="method" value="resend"><button class="button button-quiet button-wide" type="submit">Resend email code</button></form>
    <?php endif; ?>
    <form method="post" action="logout.php" class="compact-form"><?= csrf_field() ?><button class="link-button" type="submit">Cancel and sign out</button></form>
</section>
<?php render_footer(); ?>
