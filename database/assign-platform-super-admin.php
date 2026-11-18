<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if ($argc !== 2 || $argv[1] !== '--confirm-platform-owner') {
    fwrite(STDERR, "Usage: php database/assign-platform-super-admin.php --confirm-platform-owner\n");
    exit(2);
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

function owner_setup_prompt(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    $value = fgets(STDIN);
    return $value === false ? '' : trim($value);
}

function owner_setup_fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

if (strtolower(owner_setup_prompt('Type ' . PLATFORM_SUPER_ADMIN_EMAIL . ' to confirm owner setup: '))
    !== PLATFORM_SUPER_ADMIN_EMAIL) {
    owner_setup_fail('Owner setup was not confirmed.');
}

$pdo = db();
$userStmt = $pdo->prepare(
    'SELECT id, full_name, username, status, email_verified_at
     FROM users WHERE LOWER(TRIM(email)) = ? LIMIT 1'
);
$userStmt->execute([PLATFORM_SUPER_ADMIN_EMAIL]);
$owner = $userStmt->fetch();
if (!$owner) {
    owner_setup_fail('No existing account matches the exact authorized email. No account was created and no database records were changed.');
}

if ($owner['status'] !== 'active') {
    owner_setup_fail('The existing owner account is disabled. Re-enable it through the authorized account recovery process first.');
}

if ($owner['email_verified_at'] === null) {
    $sendResult = issue_email_verification_code((int) $owner['id'], PLATFORM_SUPER_ADMIN_EMAIL);
    if ($sendResult !== 'sent') {
        owner_setup_fail($sendResult === 'rate_limited'
            ? 'A verification code was sent less than one minute ago. Wait and run setup again.'
            : 'Email verification could not be sent. Check the configured mail service and retry; no platform role was granted.');
    }

    $code = owner_setup_prompt('Enter the six-digit code sent to the owner email: ');
    if (preg_match('/^\d{6}$/', $code) !== 1) {
        owner_setup_fail('The verification code format is invalid. No platform role was granted.');
    }

    $pdo->beginTransaction();
    try {
        $tokenStmt = $pdo->prepare(
            'SELECT code_hash, expires_at, attempts, used_at
             FROM email_verification_tokens WHERE user_id = ? FOR UPDATE'
        );
        $tokenStmt->execute([(int) $owner['id']]);
        $token = $tokenStmt->fetch();
        $valid = $token && $token['used_at'] === null && $token['code_hash'] !== null
            && (int) $token['attempts'] < 5 && $token['expires_at'] !== null
            && strtotime((string) $token['expires_at'] . ' UTC') >= time()
            && hash_equals((string) $token['code_hash'], keyed_hash($code));
        if (!$valid) {
            if ($token && $token['used_at'] === null) {
                $attempts = min(255, (int) $token['attempts'] + 1);
                $failStmt = $pdo->prepare(
                    'UPDATE email_verification_tokens
                     SET attempts = ?, code_hash = IF(? >= 5, NULL, code_hash)
                     WHERE user_id = ?'
                );
                $failStmt->execute([$attempts, $attempts, (int) $owner['id']]);
            }
            $pdo->commit();
            owner_setup_fail('The verification code is invalid, expired, or exhausted. No platform role was granted.');
        }

        $verifyStmt = $pdo->prepare(
            'UPDATE users SET email_verified_at = UTC_TIMESTAMP()
             WHERE id = ? AND email_verified_at IS NULL'
        );
        $verifyStmt->execute([(int) $owner['id']]);
        $consumeStmt = $pdo->prepare(
            'UPDATE email_verification_tokens
             SET used_at = UTC_TIMESTAMP(), code_hash = NULL
             WHERE user_id = ?'
        );
        $consumeStmt->execute([(int) $owner['id']]);
        $pdo->commit();
        audit((int) $owner['id'], 'email_verified', 'source=platform_owner_cli');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

$pdo->beginTransaction();
try {
    $verifiedStmt = $pdo->prepare(
        'SELECT id FROM users
         WHERE id = ? AND status = ? AND email_verified_at IS NOT NULL
           AND LOWER(TRIM(email)) = ?
         FOR UPDATE'
    );
    $verifiedStmt->execute([(int) $owner['id'], 'active', PLATFORM_SUPER_ADMIN_EMAIL]);
    $ownerId = $verifiedStmt->fetchColumn();
    if ($ownerId === false) {
        throw new RuntimeException('The exact owner account must be active and email-verified before elevation.');
    }

    $roleStmt = $pdo->prepare(
        "SELECT id, name FROM roles
         WHERE name IN ('platform_super_admin', 'it_manager')
         ORDER BY name FOR UPDATE"
    );
    $roleStmt->execute();
    $roleIds = [];
    foreach ($roleStmt->fetchAll() as $role) {
        $roleIds[(string) $role['name']] = (int) $role['id'];
    }
    if (!isset($roleIds['platform_super_admin'], $roleIds['it_manager'])) {
        throw new RuntimeException('The Platform Super Admin and IT Manager roles must both exist before elevation.');
    }

    $removeOtherAdmins = $pdo->prepare(
        "DELETE ur FROM user_roles ur
         JOIN roles r ON r.id = ur.role_id AND r.name = 'platform_super_admin'
         WHERE ur.user_id <> ?"
    );
    $removeOtherAdmins->execute([(int) $ownerId]);
    $removeOwnerRoles = $pdo->prepare('DELETE FROM user_roles WHERE user_id = ?');
    $removeOwnerRoles->execute([(int) $ownerId]);
    $assignRole = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
    foreach ([$roleIds['platform_super_admin'], $roleIds['it_manager']] as $roleId) {
        $assignRole->execute([(int) $ownerId, $roleId]);
    }

    $archiveStaff = $pdo->prepare(
        'UPDATE school_staff SET status = "inactive" WHERE user_id = ? AND status <> "inactive"'
    );
    $archiveStaff->execute([(int) $ownerId]);
    $removeMemberships = $pdo->prepare('DELETE FROM school_memberships WHERE user_id = ?');
    $removeMemberships->execute([(int) $ownerId]);

    audit(
        (int) $ownerId,
        'platform_super_admin_bootstrap',
        'source=local_cli;other_admins_removed=' . $removeOtherAdmins->rowCount()
            . ';school_staff_archived=' . $archiveStaff->rowCount()
            . ';school_memberships_removed=' . $removeMemberships->rowCount()
    );
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    owner_setup_fail($exception->getMessage());
}

fwrite(STDOUT, 'The verified owner account now has exclusive Platform Super Admin access.' . PHP_EOL);
fwrite(STDOUT, 'Platform Super Admin and IT Manager roles were assigned; the existing account password was not changed.' . PHP_EOL);
