<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/school_staff_attendance.php';

header('Content-Type: application/json; charset=UTF-8');

$pdo = db();
$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');

if ($action === 'challenge') {
    $schoolId = filter_var($_GET['school_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($schoolId === false) {
        http_response_code(400);
        echo json_encode(['error' => 'Valid school_id is required.']);
        exit;
    }
    $nonce = bin2hex(random_bytes(16));
    $_SESSION['kiosk_agent_nonce'] = [
        'school_id' => (int) $schoolId,
        'nonce' => $nonce,
        'expires_at' => time() + 120,
    ];
    echo json_encode(['school_id' => (int) $schoolId, 'nonce' => $nonce]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$rawBody = file_get_contents('php://input');
$payload = is_string($rawBody) && $rawBody !== '' ? json_decode($rawBody, true) : $_POST;
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request payload.']);
    exit;
}

if ($action === 'verify') {
    $schoolId = (int) ($payload['school_id'] ?? 0);
    $deviceUid = (string) ($payload['device_uid'] ?? '');
    $signature = (string) ($payload['signature'] ?? '');
    $challenge = $_SESSION['kiosk_agent_nonce'] ?? null;
    if (!is_array($challenge)
        || (int) ($challenge['school_id'] ?? 0) !== $schoolId
        || (int) ($challenge['expires_at'] ?? 0) < time()) {
        http_response_code(400);
        echo json_encode(['error' => 'Challenge expired. Reload the kiosk page.']);
        exit;
    }
    $nonce = (string) ($challenge['nonce'] ?? '');
    unset($_SESSION['kiosk_agent_nonce']);

    $device = school_staff_attendance_verify_agent_challenge($pdo, $schoolId, $deviceUid, $nonce, $signature);
    if ($device === null) {
        http_response_code(403);
        echo json_encode(['verified' => false, 'error' => 'Physical device is not authorised or has been revoked.']);
        exit;
    }
    $_SESSION['kiosk_agent_verified'][$schoolId] = [
        'device_id' => (int) $device['id'],
        'verified_at' => time(),
    ];
    echo json_encode([
        'verified' => true,
        'device_id' => (int) $device['id'],
        'device_name' => (string) $device['device_name'],
    ]);
    exit;
}

if ($action === 'enroll') {
    $user = require_login();
    $schoolId = (int) ($payload['school_id'] ?? 0);
    if (!school_staff_attendance_can_manage_devices($user, $schoolId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Only the Headmaster or Proprietor may enroll attendance devices.']);
        exit;
    }
    try {
        $result = school_staff_attendance_enroll_agent(
            $pdo,
            $schoolId,
            (string) ($payload['enrollment_code'] ?? ''),
            (string) ($payload['device_uid'] ?? ''),
            (string) ($payload['agent_secret'] ?? '')
        );
        echo json_encode(['enrolled' => true, 'device' => $result]);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['enrolled' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unsupported action.']);
