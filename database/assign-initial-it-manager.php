<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if ($argc !== 2 || filter_var($argv[1], FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Usage: php database/assign-initial-it-manager.php verified-account@example.com\n");
    exit(2);
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

$email = strtolower(trim($argv[1]));
$pdo = db();
$pdo->beginTransaction();

try {
    $stmt = $pdo->prepare(
        "SELECT id FROM users WHERE email = ? AND email_verified_at IS NOT NULL AND status = 'active' FOR UPDATE"
    );
    $stmt->execute([$email]);
    $userId = $stmt->fetchColumn();
    if ($userId === false) {
        throw new RuntimeException('No active account with a verified matching email was found.');
    }

    $stmt = $pdo->query(
        "SELECT COUNT(*)
         FROM user_roles AS ur
         JOIN roles AS r ON r.id = ur.role_id
         WHERE r.name IN ('administrator', 'it_manager')"
    );
    if ((int) $stmt->fetchColumn() !== 0) {
        throw new RuntimeException('An administrator account is already assigned; bootstrap is disabled.');
    }

    $stmt = $pdo->prepare(
        "INSERT INTO user_roles (user_id, role_id)
         SELECT ?, id FROM roles WHERE name = 'it_manager'"
    );
    $stmt->execute([(int) $userId]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('The IT Manager role is missing or could not be assigned.');
    }
    audit((int) $userId, 'initial_it_manager_assigned', 'source=local_cli_bootstrap');
    $pdo->commit();
    fwrite(STDOUT, "IT Manager role assigned to the verified account.\n");
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
