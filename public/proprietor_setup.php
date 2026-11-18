<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

if (current_user() !== null) {
    flash('error', 'Sign out before setting up a separate Proprietor account.');
    redirect('dashboard.php');
}

$email = strtolower(trim((string) ($_POST['email'] ?? $_GET['email'] ?? '')));
$school = null;
$proprietorEmailAvailable = school_has_proprietor_email_column();
if ($proprietorEmailAvailable && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $schoolStmt = db()->prepare(
        'SELECT school.id, school.name, membership.status AS membership_status,
                account.id AS user_id, account.email_verified_at
         FROM schools school
         JOIN school_memberships membership
           ON membership.school_id = school.id
          AND membership.role_name = "school_proprietor"
         JOIN users account ON account.id = membership.user_id
         WHERE LOWER(TRIM(school.proprietor_email)) = ?
           AND LOWER(TRIM(account.email)) = ?
           AND school.deleted_at IS NULL
           AND account.status = "active"
         ORDER BY school.id
         LIMIT 1'
    );
    $schoolStmt->execute([$email, $email]);
    $school = $schoolStmt->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if (!$school) {
        flash('error', 'No Proprietor account setup was found for that email. Contact the Platform Super Admin.');
        redirect('proprietor_setup.php');
    }

    if ($school['membership_status'] === 'active' && $school['email_verified_at'] !== null) {
        flash('success', 'Your Proprietor account is already set up. Sign in with your email and password.');
        redirect('index.php');
    }

    if ($action === 'send_code') {
        $sendResult = issue_email_verification_code(
            (int) $school['user_id'],
            $email,
            'proprietor_setup'
        );
        if ($sendResult === 'sent') {
            flash('success', 'A one-time verification code was sent to your email.');
        } elseif ($sendResult === 'rate_limited') {
            flash('error', 'A code was sent recently. Wait one minute before requesting another.');
        } else {
            flash('error', 'The code could not be sent. Check the email address and try again later.');
        }
        redirect('proprietor_setup.php?email=' . rawurlencode($email));
    }

    if ($action === 'activate_account') {
        $code = trim((string) ($_POST['code'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            flash('error', 'Enter the six-digit verification code sent to your email.');
            redirect('proprietor_setup.php?email=' . rawurlencode($email));
        }
        if (!valid_password($password)) {
            flash('error', 'Password must be between 12 and 200 characters.');
            redirect('proprietor_setup.php?email=' . rawurlencode($email));
        }
        if (!hash_equals($password, $confirmPassword)) {
            flash('error', 'The password confirmation does not match.');
            redirect('proprietor_setup.php?email=' . rawurlencode($email));
        }

        db()->beginTransaction();
        try {
            $lockMembership = db()->prepare(
                'SELECT school.id, membership.user_id, membership.status, account.email,
                        account.status AS account_status, token.code_hash, token.expires_at,
                        token.attempts
                 FROM schools school
                 JOIN school_memberships membership
                   ON membership.school_id = school.id
                  AND membership.role_name = "school_proprietor"
                 JOIN users account ON account.id = membership.user_id
                 LEFT JOIN email_verification_tokens token ON token.user_id = account.id
                 WHERE school.id = ? AND LOWER(TRIM(school.proprietor_email)) = ?
                   AND LOWER(TRIM(account.email)) = ?
                   AND school.deleted_at IS NULL
                 FOR UPDATE'
            );
            $lockMembership->execute([(int) $school['id'], $email, $email]);
            $setup = $lockMembership->fetch();
            if (!$setup || $setup['status'] !== 'pending' || $setup['account_status'] !== 'active') {
                throw new RuntimeException('This Proprietor account is no longer waiting for setup.');
            }

            $attempts = (int) ($setup['attempts'] ?? 0);
            $codeExpiresAt = $setup['expires_at'] ?? null;
            $validCode = $attempts < 5
                && is_string($setup['code_hash'] ?? null)
                && $codeExpiresAt !== null
                && strtotime((string) $codeExpiresAt . ' UTC') >= time()
                && hash_equals((string) $setup['code_hash'], keyed_hash($code));
            if (!$validCode) {
                $nextAttempts = min($attempts + 1, 5);
                $attemptStmt = db()->prepare(
                    'UPDATE email_verification_tokens
                     SET attempts = ?, code_hash = IF(? >= 5, NULL, code_hash)
                     WHERE user_id = ? AND used_at IS NULL'
                );
                $attemptStmt->execute([$nextAttempts, $nextAttempts, (int) $setup['user_id']]);
                db()->commit();
                flash('error', 'That code is invalid or expired. Request a new code and try again.');
                redirect('proprietor_setup.php?email=' . rawurlencode($email));
            }

            $updateUser = db()->prepare(
                'UPDATE users SET password_hash = ?, email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP())
                 WHERE id = ? AND status = "active"'
            );
            $updateUser->execute([make_password_hash($password), (int) $setup['user_id']]);
            $useCode = db()->prepare(
                'UPDATE email_verification_tokens
                 SET used_at = UTC_TIMESTAMP(), code_hash = NULL
                 WHERE user_id = ? AND used_at IS NULL'
            );
            $useCode->execute([(int) $setup['user_id']]);

            if (!school_assign_designated_proprietor(
                db(),
                (int) $setup['id'],
                (int) $setup['user_id']
            )) {
                throw new RuntimeException('The Proprietor account could not be activated. Contact the Platform Super Admin.');
            }
            audit((int) $setup['user_id'], 'school_proprietor_account_setup_completed',
                'school_id=' . (int) $setup['id']);
            db()->commit();
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            if ($exception instanceof RuntimeException) {
                flash('error', $exception->getMessage());
                redirect('proprietor_setup.php?email=' . rawurlencode($email));
            }
            throw $exception;
        }

        flash('success', 'Your email is verified and your Proprietor account is ready. Sign in with your email and new password.');
        redirect('index.php');
    }
}

render_header('Set up Proprietor account', false);
?>
<section class="auth-card auth-card-narrow">
    <p class="eyebrow">SCHOOL PROPRIETOR</p>
    <h1>Set up your account</h1>
    <?php if (!$proprietorEmailAvailable): ?>
        <p class="notice notice-warning">The school database needs migration 093_designated_proprietor_email.sql before Proprietor account setup is available.</p>
    <?php elseif ($school): ?>
        <p class="muted">School: <strong><?= e((string) $school['name']) ?></strong>. Enter the email designated by the Platform Super Admin, verify the OTP, and set your password.</p>
        <?php if ($school['membership_status'] === 'active' && $school['email_verified_at'] !== null): ?>
            <p class="notice">Your Proprietor account is already set up. <a href="index.php">Sign in</a>.</p>
        <?php else: ?>
            <form method="post" action="proprietor_setup.php" class="form-stack">
                <?= csrf_field() ?>
                <input type="hidden" name="email" value="<?= e($email) ?>">
                <label for="code">Email verification code</label>
                <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" required>
                <label for="password">Create password <span class="label-hint">12 characters minimum</span></label>
                <input id="password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="200" required>
                <label for="confirm_password">Confirm password</label>
                <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="12" maxlength="200" required>
                <button class="button button-primary button-wide" type="submit" name="action" value="activate_account">Verify code and set password</button>
            </form>
            <form method="post" action="proprietor_setup.php" class="form-stack compact-form">
                <?= csrf_field() ?>
                <input type="hidden" name="email" value="<?= e($email) ?>">
                <button class="button button-quiet button-wide" type="submit" name="action" value="send_code">Send or resend OTP</button>
            </form>
        <?php endif; ?>
    <?php else: ?>
        <p class="muted">Enter the email address the Platform Super Admin used to set up your Proprietor account.</p>
        <form method="get" action="proprietor_setup.php" class="form-stack">
            <label for="email">Designated email address</label>
            <input id="email" name="email" type="email" autocomplete="email" maxlength="254" required>
            <button class="button button-primary button-wide" type="submit">Continue</button>
        </form>
    <?php endif; ?>
    <p class="auth-switch"><a href="index.php">Return to sign in</a></p>
</section>
<?php render_footer(); ?>
