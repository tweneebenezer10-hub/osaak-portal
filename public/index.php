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
    $identity = trim((string) ($_POST['identity'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if ($identity === '' || strlen($identity) > 254 || $password === '') {
        log_login_attempt($identity, false);
        flash('error', 'Sign-in failed or the temporary attempt limit was reached. Wait 15 minutes before trying again.');
        redirect('index.php');
    }
    if (login_is_limited($identity)) {
        flash('error', 'Sign-in failed or the temporary attempt limit was reached. Wait 15 minutes before trying again.');
        redirect('index.php');
    }

    $stmt = db()->prepare(
        'SELECT id, password_hash, status, email_verified_at, email
         FROM users
         WHERE username = ? OR email = ? LIMIT 1'
    );
    $stmt->execute([$identity, $identity]);
    $user = $stmt->fetch();
    $dummyHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
    $passwordOk = verify_password($password, (string) ($user['password_hash'] ?? $dummyHash));
    if (!$user || !$passwordOk) {
        log_login_attempt($identity, false);
        if ($user) {
            audit((int) $user['id'], 'login_failed');
        }
        flash('error', 'The username/email or password is incorrect, or the account is unavailable.');
        redirect('index.php');
    }
    if ($user['status'] !== 'active') {
        log_login_attempt($identity, false);
        audit((int) $user['id'], 'login_failed');
        flash('error', 'The username/email or password is incorrect, or the account is unavailable.');
        redirect('index.php');
    }
    if (!filter_var((string) $user['email'], FILTER_VALIDATE_EMAIL)) {
        log_login_attempt($identity, false);
        audit((int) $user['id'], 'login_failed');
        flash('error', 'Sign-in is unavailable because this account has no valid registered email address. Contact the platform administrator to correct it securely.');
        redirect('index.php');
    }
    log_login_attempt($identity, true);
    if ($user['email_verified_at'] === null) {
        set_pending_user((int) $user['id']);
        flash('error', 'Verify your email address before signing in. You can request a fresh code below.');
        redirect('verify_email.php');
    }
    $stmt = db()->prepare('SELECT enabled FROM totp_factors WHERE user_id = ?');
    $stmt->execute([(int) $user['id']]);
    $totpEnabled = (bool) $stmt->fetchColumn();
    $_SESSION['pending_login_user_id'] = (int) $user['id'];
    $_SESSION['pending_login_totp'] = $totpEnabled;
    $_SESSION['pending_login_email'] = true;
    $_SESSION['pending_login_email_required'] = true;
    $_SESSION['pending_login_totp_ok'] = false;
    $_SESSION['pending_login_email_ok'] = false;
    $_SESSION['pending_factor_attempts'] = 0;
    unset($_SESSION['pending_email_delivery_failed']);
    if (!issue_login_code((int) $user['id'], (string) $user['email'])) {
        $_SESSION['pending_email_delivery_failed'] = true;
    }
    redirect('verify_login.php');
}

render_header('School login', false);
?>
<section class="auth-layout auth-login-layout">
    <header class="login-brand-header">
        <div class="login-brand-inner">
            <img src="assets/images/super-admin-logo.jpeg" alt="">
            <strong>CONNEZER EDUTECH SYSTEMS</strong>
        </div>
    </header>
    <div class="auth-login-content">
        <section class="auth-login-welcome">
            <h1>Welcome Back</h1>
            <p>Sign in with your existing credentials</p>
        </section>
        <div class="auth-card auth-login-card">
            <form method="post" action="index.php" class="form-stack" data-loading-form>
                <?= csrf_field() ?>
                <label for="identity">Username or email</label>
                <div class="input-wrap"><span class="field-icon" aria-hidden="true">◎</span><input id="identity" name="identity" type="text" autocomplete="username" required maxlength="254" placeholder="you@example.com"></div>
                <label for="password">Password</label>
                <div class="login-password-field">
                    <div class="input-wrap"><span class="field-icon" aria-hidden="true">⌑</span><input id="password" name="password" type="password" autocomplete="current-password" required maxlength="200" placeholder="Enter your password"><button class="reveal-button" type="button" data-password-toggle="password" aria-label="Show password">Show</button></div>
                </div>
                <div class="login-action-panel">
                    <div class="login-control-grid">
                        <button class="button login-control login-submit-button" type="submit">
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M10 17l5-5-5-5M15 12H3M21 19V5a2 2 0 0 0-2-2h-5m0 18h5a2 2 0 0 0 2-2"></path></svg>
                            <span class="button-label">Sign In</span><span class="button-loading" aria-hidden="true">Please wait…</span>
                        </button>
                        <a class="button login-control login-staff-attendance-button" href="attendance_kiosk.php">
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path></svg>
                            <span>Staff Attendance</span>
                        </a>
                        <a class="button login-control login-secondary-button" href="forgot_password.php">Reset Password</a>
                        <a class="button login-control login-secondary-button" href="register.php">Register</a>
                    </div>
                </div>
            </form>
            <p class="help-text">Authorized platform administrators are routed automatically after sign-in.</p>
        </div>
    </div>
</section>
<?php render_footer(); ?>
