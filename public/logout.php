<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Use the sign-out form to log out.');
}
require_csrf();
$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
clear_remember_cookie();
if ($userId) {
    $supportContext = active_it_support_context();
    if ($supportContext !== null) {
        $supportStmt = db()->prepare(
            "UPDATE it_support_sessions
             SET ended_at = UTC_TIMESTAMP(), end_reason = 'logout'
             WHERE id = ? AND admin_user_id = ? AND ended_at IS NULL"
        );
        $supportStmt->execute([(int) $supportContext['id'], $userId]);
        audit($userId, 'it_support_session_ended', 'support_session_id=' . (int) $supportContext['id'] . ';reason=logout');
    }
    audit($userId, 'logout');
}
destroy_auth_session();
redirect('index.php');
