<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

if (!empty($_SESSION['user_id'])) {
    $user = current_user();
    if ($user) {
        redirect('dashboard.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $_SESSION['password_reset_email'] = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $ipHash = keyed_hash($ip);

    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM password_reset_requests
         WHERE ip_hash = ? AND requested_at >= UTC_TIMESTAMP() - INTERVAL 15 MINUTE'
    );
    $stmt->execute([$ipHash]);
    $limited = (int) $stmt->fetchColumn() >= 5;
    if (!$limited) {
        $stmt = db()->prepare('INSERT INTO password_reset_requests (ip_hash) VALUES (?)');
        $stmt->execute([$ipHash]);

        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $stmt = db()->prepare(
                'SELECT id, email, full_name FROM users
                 WHERE email = ? AND status = \'active\' AND email_verified_at IS NOT NULL'
            );
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                $userId = (int) $user['id'];
                db()->beginTransaction();
                $lockUser = db()->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
                $lockUser->execute([$userId]);
                $lockUser->fetchColumn();

                $stmt = db()->prepare(
                    'SELECT COUNT(*) FROM password_reset_tokens
                     WHERE user_id = ? AND created_at >= UTC_TIMESTAMP() - INTERVAL 15 MINUTE'
                );
                $stmt->execute([$userId]);
                $requestCount = (int) $stmt->fetchColumn();

                $stmt = db()->prepare('SELECT MAX(sent_at) FROM password_reset_tokens WHERE user_id = ?');
                $stmt->execute([$userId]);
                $lastSentAt = $stmt->fetchColumn();
                $cooldownPassed = !$lastSentAt || strtotime((string) $lastSentAt . ' UTC') <= time() - 60;

                if ($requestCount < 3 && $cooldownPassed) {
                    $stmt = db()->prepare(
                        'UPDATE password_reset_tokens
                         SET used_at = UTC_TIMESTAMP(), token_hash = NULL
                         WHERE user_id = ? AND used_at IS NULL'
                    );
                    $stmt->execute([$userId]);

                    $code = (string) random_int(100000, 999999);
                    $codeHash = keyed_hash($code);
                    $stmt = db()->prepare(
                        'INSERT INTO password_reset_tokens
                            (user_id, token_hash, expires_at, sent_at, attempts)
                         VALUES (?, ?, UTC_TIMESTAMP() + INTERVAL 5 MINUTE, UTC_TIMESTAMP(), 0)'
                    );
                    $stmt->execute([$userId, $codeHash]);
                    $resetId = (int) db()->lastInsertId();
                    db()->commit();

                    $html = '<p>Hello ' . e((string) $user['full_name']) . ',</p>'
                        . '<p>A password reset was requested for your account.</p>'
                        . '<p>Your six-digit verification code is:</p>'
                        . '<p style="font-size:28px;font-weight:bold;letter-spacing:6px">' . e($code) . '</p>'
                        . '<p>This code expires in five minutes and can only be used once. Keep it private; CONNEZER EDUTECH SYSTEMS staff will never ask you to share it.</p>'
                        . '<p>If you did not request it, ignore this message.</p>';
                    $text = 'Hello ' . (string) $user['full_name'] . ",\n\n"
                        . "A password reset was requested for your account.\n\n"
                        . 'Your six-digit verification code is: ' . $code . "\n\n"
                        . 'This code expires in five minutes and can only be used once. Keep it private; our staff will never ask you to share it.';

                    if (send_mail((string) $user['email'], 'Password reset verification code', $html, $text)) {
                        audit($userId, 'password_reset_code_sent');
                    } else {
                        $stmt = db()->prepare(
                            'UPDATE password_reset_tokens
                             SET used_at = UTC_TIMESTAMP(), token_hash = NULL
                             WHERE id = ? AND used_at IS NULL'
                        );
                        $stmt->execute([$resetId]);
                    }
                } else {
                    db()->commit();
                }
            }
        }
    }

    flash('success', 'If the address belongs to an active, verified account and can receive email, a six-digit password reset code will be sent. The code expires after five minutes. Check your spam folder if it does not arrive.');
    redirect('reset_password.php');
}

render_header('Forgot password', false);
?>
<section class="auth-card auth-card-narrow">
    <p class="eyebrow">ACCOUNT RECOVERY</p><h1>Reset your password</h1>
    <p class="muted">Enter the email address associated with your verified account. If it qualifies, we will email a six-digit verification code. You will enter that code with your new password on the next screen.</p>
    <form method="post" action="forgot_password.php" class="form-stack" data-loading-form>
        <?= csrf_field() ?>
        <label for="email">Email address</label>
        <input id="email" name="email" type="email" autocomplete="email" maxlength="254" required>
        <button class="button button-primary button-wide" type="submit"><span class="button-label">Send verification code</span><span class="button-loading" aria-hidden="true">Submitting…</span></button>
    </form>
    <p class="auth-switch"><a href="index.php">Return to sign in</a></p>
</section>
<?php render_footer(); ?>
