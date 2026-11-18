<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
$userId = pending_user_id();
if (!$userId) {
    flash('error', 'Start by registering or signing in with your account.');
    redirect('index.php');
}
$stmt = db()->prepare('SELECT email, email_verified_at FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();
if (!$user) {
    unset($_SESSION['pending_user_id']);
    redirect('index.php');
}
if ($user['email_verified_at'] !== null) {
    unset($_SESSION['pending_user_id']);
    $proprietorAccessStmt = db()->prepare(
        'SELECT s.name, s.status AS school_status
         FROM school_memberships sm
         JOIN schools s ON s.id = sm.school_id
         WHERE sm.user_id = ? AND sm.role_name = "school_proprietor"
           AND sm.status = "active" AND s.deleted_at IS NULL
         ORDER BY sm.id DESC LIMIT 1'
    );
    $proprietorAccessStmt->execute([$userId]);
    $proprietorAccess = $proprietorAccessStmt->fetch();
    if ($proprietorAccess) {
        flash('success', $proprietorAccess['school_status'] === 'active'
            ? 'Your email is verified and Proprietor access is active for ' . (string) $proprietorAccess['name'] . '. You can now sign in.'
            : 'Your email is verified and Proprietor access is active for ' . (string) $proprietorAccess['name'] . '. The Platform Super Admin must activate the school portal before it can be used.');
        redirect('index.php');
    }
    $schoolMembershipStmt = db()->prepare(
        "SELECT s.name
         FROM school_memberships sm
         JOIN schools s ON s.id = sm.school_id
         WHERE sm.user_id = ? AND sm.status = 'pending' AND s.deleted_at IS NULL
         ORDER BY sm.id DESC LIMIT 1"
    );
    $schoolMembershipStmt->execute([$userId]);
    $schoolName = $schoolMembershipStmt->fetchColumn();
    flash('success', $schoolName
        ? 'Your email is verified. Your access request for ' . (string) $schoolName . ' is awaiting school approval.'
        : 'Your email address is verified. Your membership application is awaiting review.');
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? 'verify');
    if ($action === 'resend') {
        $sendResult = issue_email_verification_code($userId, (string) $user['email']);
        if ($sendResult === 'sent') {
            flash('success', 'A new verification code was sent.');
        } elseif ($sendResult === 'rate_limited') {
            flash('error', 'A code was sent less than one minute ago. Wait a moment before requesting another.');
        } else {
            flash('error', 'Email delivery failed. Check the SMTP settings and server logs, then try again; a new code was not sent.');
        }
        redirect('verify_email.php');
    }
    $code = trim((string) ($_POST['code'] ?? ''));
    $stmt = db()->prepare('SELECT code_hash, expires_at, attempts FROM email_verification_tokens WHERE user_id = ? AND used_at IS NULL');
    $stmt->execute([$userId]);
    $token = $stmt->fetch();
    $valid = $token && (int) $token['attempts'] < 5 && $token['expires_at']
        && strtotime((string) $token['expires_at'] . ' UTC') >= time()
        && preg_match('/^\d{6}$/', $code)
        && hash_equals((string) $token['code_hash'], keyed_hash($code));
    if (!$valid) {
        if ($token) {
            $attempts = (int) $token['attempts'] + 1;
            $stmt = db()->prepare('UPDATE email_verification_tokens SET attempts = ?, code_hash = IF(? >= 5, NULL, code_hash) WHERE user_id = ?');
            $stmt->execute([$attempts, $attempts, $userId]);
        }
        flash('error', 'That code is invalid, expired, or has used all attempts. Request a new code and try again.');
        redirect('verify_email.php');
    }
    db()->beginTransaction();
    $stmt = db()->prepare('UPDATE users SET email_verified_at = UTC_TIMESTAMP() WHERE id = ? AND email_verified_at IS NULL');
    $stmt->execute([$userId]);
    $stmt = db()->prepare('UPDATE email_verification_tokens SET used_at = UTC_TIMESTAMP(), code_hash = NULL WHERE user_id = ?');
    $stmt->execute([$userId]);
    $designatedSchools = [];
    if (school_has_proprietor_email_column()) {
        $designatedSchoolStmt = db()->prepare(
            'SELECT id, name, status
             FROM schools
             WHERE LOWER(TRIM(proprietor_email)) = LOWER(TRIM(?))
               AND deleted_at IS NULL AND status IN ("pending", "active")
               AND subscription_status IN ("trial", "active")
               AND (subscription_expires_at IS NULL OR subscription_expires_at > UTC_TIMESTAMP())
             ORDER BY id
             FOR UPDATE'
        );
        $designatedSchoolStmt->execute([(string) $user['email']]);
        $designatedSchools = $designatedSchoolStmt->fetchAll();
    }
    $activatedProprietorSchool = null;
    $activatedProprietorSchoolStatus = null;
    $proprietorActivationBlocked = false;
    foreach ($designatedSchools as $designatedSchool) {
        try {
            $assigned = school_assign_designated_proprietor(
                db(),
                (int) $designatedSchool['id'],
                $userId
            );
        } catch (RuntimeException) {
            $disableProprietorStmt = db()->prepare(
                'UPDATE school_memberships SET status = "disabled"
                 WHERE school_id = ? AND user_id = ? AND role_name = "school_proprietor" AND status = "pending"'
            );
            $disableProprietorStmt->execute([(int) $designatedSchool['id'], $userId]);
            $proprietorActivationBlocked = true;
            continue;
        }
        if (!$assigned) {
            continue;
        }
        $activatedProprietorSchool = (string) $designatedSchool['name'];
        $activatedProprietorSchoolStatus = (string) $designatedSchool['status'];
        break;
    }
    db()->commit();
    unset($_SESSION['pending_user_id']);
    audit($userId, 'email_verified');
    if ($activatedProprietorSchool !== null) {
        flash('success', $activatedProprietorSchoolStatus === 'active'
            ? 'Email verified. Proprietor access is active for ' . $activatedProprietorSchool . '. You can now sign in.'
            : 'Email verified. Proprietor access is active for ' . $activatedProprietorSchool . '. The Platform Super Admin must activate the school portal before it can be used.');
        redirect('index.php');
    }
    if ($proprietorActivationBlocked) {
        flash('error', 'Your email is verified, but a school authority was assigned before your Proprietor access could activate. Contact the Platform Super Admin.');
        redirect('index.php');
    }
    $schoolMembershipStmt = db()->prepare(
        "SELECT s.name
         FROM school_memberships sm
         JOIN schools s ON s.id = sm.school_id
         WHERE sm.user_id = ? AND sm.status = 'pending' AND s.deleted_at IS NULL
         ORDER BY sm.id DESC LIMIT 1"
    );
    $schoolMembershipStmt->execute([$userId]);
    $schoolName = $schoolMembershipStmt->fetchColumn();
    flash('success', $schoolName
        ? 'Email verified. Your account request for ' . (string) $schoolName . ' is awaiting school approval.'
        : 'Email verified. Your membership application is awaiting review.');
    redirect('index.php');
}

render_header('Verify email', false);
?>
<section class="auth-card auth-card-narrow">
    <p class="eyebrow">ONE MORE STEP</p><h1>Verify your email</h1>
    <p class="muted">Enter the six-digit code sent to <strong><?= e((string) $user['email']) ?></strong>. The code is valid for five minutes.</p>
    <form method="post" action="verify_email.php" class="form-stack" data-loading-form>
        <?= csrf_field() ?><input type="hidden" name="action" value="verify">
        <label for="code">Verification code</label><input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" required>
        <button class="button button-primary button-wide" type="submit"><span class="button-label">Verify email</span><span class="button-loading" aria-hidden="true">Checking…</span></button>
    </form>
    <form method="post" action="verify_email.php" class="form-stack compact-form"><?= csrf_field() ?><input type="hidden" name="action" value="resend"><button class="button button-quiet button-wide" type="submit">Resend code</button></form>
    <p class="auth-switch"><a href="index.php">Return to sign in</a></p>
</section>
<?php render_footer(); ?>
