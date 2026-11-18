<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
$prefillEmail = (string) ($_SESSION['password_reset_email'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $code = trim((string) ($_POST['code'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    if (!valid_password($password) || !hash_equals($password, $confirm)) {
        flash('error', 'Use a password of 12–200 characters and make sure both entries match.');
        redirect('reset_password.php');
    }

    $reset = null;
    if (filter_var($email, FILTER_VALIDATE_EMAIL) && preg_match('/^\d{6}$/', $code) === 1) {
        $stmt = db()->prepare(
            'SELECT id FROM users
             WHERE email = ? AND status = \'active\' AND email_verified_at IS NOT NULL'
        );
        $stmt->execute([$email]);
        $userId = $stmt->fetchColumn();

        if ($userId !== false) {
            db()->beginTransaction();
            $stmt = db()->prepare(
                'SELECT id, token_hash, expires_at, attempts FROM password_reset_tokens
                 WHERE user_id = ? AND used_at IS NULL
                 ORDER BY id DESC LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([(int) $userId]);
            $reset = $stmt->fetch();

            $valid = $reset
                && $reset['token_hash'] !== null
                && (int) $reset['attempts'] < 5
                && strtotime((string) $reset['expires_at'] . ' UTC') >= time()
                && hash_equals((string) $reset['token_hash'], keyed_hash($code));

            if (!$valid) {
                if ($reset && $reset['token_hash'] !== null && (int) $reset['attempts'] < 5) {
                    $attempts = (int) $reset['attempts'] + 1;
                    $stmt = db()->prepare(
                        'UPDATE password_reset_tokens
                         SET attempts = ?, token_hash = IF(? >= 5, NULL, token_hash),
                             used_at = IF(? >= 5, UTC_TIMESTAMP(), used_at)
                         WHERE id = ?'
                    );
                    $stmt->execute([$attempts, $attempts, $attempts, (int) $reset['id']]);
                }
                db()->commit();
                flash('error', 'The email or verification code is incorrect, expired, or has already been used. Request a new code and try again.');
                redirect('reset_password.php');
            }

            $stmt = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $stmt->execute([make_password_hash($password), (int) $userId]);
            $stmt = db()->prepare(
                'UPDATE password_reset_tokens
                 SET used_at = UTC_TIMESTAMP(), token_hash = NULL
                 WHERE user_id = ? AND used_at IS NULL'
            );
            $stmt->execute([(int) $userId]);
            $stmt = db()->prepare('DELETE FROM remember_tokens WHERE user_id = ?');
            $stmt->execute([(int) $userId]);
            db()->commit();
            audit((int) $userId, 'password_reset_completed');
            unset($_SESSION['password_reset_email']);
            flash('success', 'Your password has been changed. Sign in with the new password.');
            redirect('index.php');
        }
    }

    flash('error', 'The email or verification code is incorrect, expired, or has already been used. Request a new code and try again.');
    redirect('reset_password.php');
}

render_header('Choose a new password', false);
?>
<section class="auth-card auth-card-narrow">
    <p class="eyebrow">ACCOUNT RECOVERY</p><h1>Verify your code</h1>
    <p class="muted">Enter the account email address, the six-digit code sent to it, and your new password. Codes expire after five minutes and allow up to five attempts.</p>
    <form method="post" action="reset_password.php" class="form-stack" data-loading-form>
        <?= csrf_field() ?>
        <label for="email">Email address</label>
        <input id="email" name="email" type="email" autocomplete="email" maxlength="254" value="<?= e($prefillEmail) ?>" required>
        <label for="code">Six-digit verification code</label>
        <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" required>
        <label for="password">New password <span class="label-hint">12 characters minimum</span></label>
        <div class="input-wrap"><input id="password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="200" required><button class="reveal-button" type="button" data-password-toggle="password" aria-label="Show password">Show</button></div>
        <label for="confirm_password">Confirm new password</label>
        <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="12" maxlength="200" required>
        <button class="button button-primary button-wide" type="submit">Verify code and save password</button>
    </form>
    <p class="auth-switch"><a href="forgot_password.php">Request a new code</a> · <a href="index.php">Return to sign in</a></p>
</section>
<?php render_footer(); ?>
