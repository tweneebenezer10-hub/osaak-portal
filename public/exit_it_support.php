<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = require_platform_super_admin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Use the Exit IT Support Mode button to return to platform administration.');
}
require_csrf();

$supportContext = active_it_support_context($user);
if ($supportContext !== null) {
    $stmt = db()->prepare(
        "UPDATE it_support_sessions
         SET ended_at = UTC_TIMESTAMP(), end_reason = 'owner_exit'
         WHERE id = ? AND admin_user_id = ? AND ended_at IS NULL"
    );
    $stmt->execute([(int) $supportContext['id'], (int) $user['id']]);
    audit(
        (int) $user['id'],
        'it_support_session_ended',
        'support_session_id=' . (int) $supportContext['id'] . ';reason=owner_exit'
    );
}

unset($_SESSION['it_support_session_id'], $_SESSION['active_school_id']);
flash('success', 'IT Support Mode ended. You are back in platform administration.');
redirect('platform_admin.php');
