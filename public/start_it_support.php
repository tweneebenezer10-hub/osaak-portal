<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = require_platform_super_admin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Use the platform IT Support form to open a school portal.');
}
require_csrf();

$schoolId = filter_var($_POST['school_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$previewRole = trim((string) ($_POST['preview_role'] ?? ''));
$accessMode = trim((string) ($_POST['access_mode'] ?? 'read_only'));
$reason = trim((string) ($_POST['reason'] ?? ''));
$supportedRoles = school_role_permissions();
if ($schoolId === false || !isset($supportedRoles[$previewRole])
    || $previewRole === 'platform_super_admin'
    || !in_array($accessMode, ['read_only', 'administrative'], true)
    || strlen($reason) < 12 || strlen($reason) > 500
    || ($accessMode === 'administrative'
        && (in_array($previewRole, ['school_student', 'school_parent'], true)
            || empty($_POST['administrative_access_confirmed'])))) {
    flash('error', 'Choose a supported role and access mode, provide a support reason, and confirm administrative access when requested. Student and Parent previews are always read-only.');
    redirect('platform_admin.php');
}

$schoolStmt = db()->prepare('SELECT id FROM schools WHERE id = ? AND deleted_at IS NULL LIMIT 1');
$schoolStmt->execute([$schoolId]);
if (!$schoolStmt->fetchColumn()) {
    flash('error', 'The selected school could not be found.');
    redirect('platform_admin.php');
}

$sessionHash = hash('sha256', session_id());
$clientIp = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ?: null;
db()->beginTransaction();
try {
    $previousStmt = db()->prepare(
        'SELECT id FROM it_support_sessions
         WHERE admin_user_id = ? AND session_hash = ? AND ended_at IS NULL
         FOR UPDATE'
    );
    $previousStmt->execute([(int) $user['id'], $sessionHash]);
    foreach ($previousStmt->fetchAll(PDO::FETCH_COLUMN) as $previousId) {
        $endStmt = db()->prepare(
            "UPDATE it_support_sessions
             SET ended_at = UTC_TIMESTAMP(), end_reason = 'replaced'
             WHERE id = ? AND ended_at IS NULL"
        );
        $endStmt->execute([(int) $previousId]);
        audit((int) $user['id'], 'it_support_session_ended', 'support_session_id=' . (int) $previousId . ';reason=replaced');
    }

    $insertStmt = db()->prepare(
        'INSERT INTO it_support_sessions
         (admin_user_id, school_id, preview_role, access_mode, reason, session_hash, client_ip, started_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
    );
    $insertStmt->execute([
        (int) $user['id'],
        $schoolId,
        $previewRole,
        $accessMode,
        $reason,
        $sessionHash,
        $clientIp,
    ]);
    $supportSessionId = (int) db()->lastInsertId();
    audit(
        (int) $user['id'],
        'it_support_session_started',
        'support_session_id=' . $supportSessionId . ';school_id=' . $schoolId
            . ';role=' . $previewRole . ';mode=' . $accessMode
    );
    db()->commit();
} catch (Throwable $exception) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    throw $exception;
}

unset($_SESSION['active_school_id']);
$_SESSION['it_support_session_id'] = $supportSessionId;
flash(
    'success',
    $accessMode === 'administrative'
        ? 'Administrative IT Support Mode started. Actions are limited by the selected school role and audited.'
        : 'IT Support Mode started in read-only mode.'
);
redirect('school_dashboard.php');
