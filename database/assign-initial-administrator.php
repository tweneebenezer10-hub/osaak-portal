<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if ($argc !== 2 || filter_var($argv[1], FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Usage: php database/assign-initial-administrator.php verified-account@example.com\n");
    exit(2);
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

$email = strtolower(trim($argv[1]));
$pdo = db();
$pdo->beginTransaction();

try {
    $stmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'administrator' FOR UPDATE");
    $stmt->execute();
    $administratorRoleId = $stmt->fetchColumn();
    if ($administratorRoleId === false) {
        throw new RuntimeException('The administrator role is missing.');
    }

    $stmt = $pdo->prepare('SELECT user_id FROM user_roles WHERE role_id = ? LIMIT 1 FOR UPDATE');
    $stmt->execute([(int) $administratorRoleId]);
    if ($stmt->fetchColumn() !== false) {
        throw new RuntimeException('An administrator account is already assigned; bootstrap is disabled.');
    }

    $stmt = $pdo->prepare(
        "SELECT id
         FROM users
         WHERE email = ? AND email_verified_at IS NOT NULL AND status = 'active'
         FOR UPDATE"
    );
    $stmt->execute([$email]);
    $userId = $stmt->fetchColumn();
    if ($userId === false) {
        throw new RuntimeException('No active account with a verified matching email was found.');
    }

    $stmt = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
    $stmt->execute([(int) $userId, (int) $administratorRoleId]);
    audit((int) $userId, 'initial_administrator_assigned', 'source=local_cli_bootstrap');
    $pdo->commit();
    fwrite(STDOUT, "Initial administrator role assigned to the verified account.\n");
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
