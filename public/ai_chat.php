<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
$user = require_school_context();
$school = active_school_context();
$schoolId = (int) $school['id'];
if (active_it_support_context($user) !== null) {
    http_response_code(403);
    echo json_encode(['error' => 'Connezer Ai is unavailable in IT Support preview sessions.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Use POST to send a message.']);
    exit;
}
require_csrf();

$role = school_user_role_for_school((int) $user['id'], $schoolId);
$allowedAssistants = school_ai_assistant_keys_for_role((string) $role);
$assistant = is_string($_POST['assistant'] ?? null) ? $_POST['assistant'] : '';
$profiles = school_ai_assistant_profiles();
if (!in_array($assistant, $allowedAssistants, true) || !isset($profiles[$assistant])) {
    http_response_code(403);
    echo json_encode(['error' => 'This assistant is not available for your active school role.']);
    exit;
}
$learningContext = '';
if ($assistant === 'teaching') {
    $classLevel = $_POST['class_level'] ?? '';
    $subject = $_POST['subject'] ?? '';
    if (!is_string($classLevel) || !is_string($subject)) {
        http_response_code(422);
        echo json_encode(['error' => 'Choose a supported class level and enter a valid subject.']);
        exit;
    }
    try {
        $learningContext = school_ai_learning_context($classLevel, $subject, $schoolId);
    } catch (InvalidArgumentException $exception) {
        http_response_code(422);
        echo json_encode(['error' => $exception->getMessage()]);
        exit;
    }
}

$message = $_POST['message'] ?? null;
$sourceText = $_POST['source_material'] ?? '';
if (!is_string($message) || trim($message) === '' || strlen($message) > 4000) {
    http_response_code(422);
    echo json_encode(['error' => 'Enter a message of 1 to 4,000 bytes.']);
    exit;
}
if (!is_string($sourceText) || strlen($sourceText) > 8000 || preg_match('//u', $sourceText) !== 1
    || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $sourceText) === 1) {
    http_response_code(422);
    echo json_encode(['error' => 'Source text must be valid UTF-8 and no more than 8,000 bytes.']);
    exit;
}
$sourceFile = $_FILES['source_file'] ?? null;
if (is_array($sourceFile) && (int) ($sourceFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    if ((int) ($sourceFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || !isset($sourceFile['tmp_name'], $sourceFile['name'], $sourceFile['size'])
        || !is_uploaded_file((string) $sourceFile['tmp_name'])
        || (int) $sourceFile['size'] < 1
        || (int) $sourceFile['size'] > 262144
        || !in_array(strtolower(pathinfo((string) $sourceFile['name'], PATHINFO_EXTENSION)), ['txt', 'md', 'csv'], true)) {
        http_response_code(422);
        echo json_encode(['error' => 'Attach a valid .txt, .md, or .csv document no larger than 256 KB.']);
        exit;
    }
    $fileContents = file_get_contents((string) $sourceFile['tmp_name']);
    if (!is_string($fileContents)
        || strlen($fileContents) > 8000
        || preg_match('//u', $fileContents) !== 1
        || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $fileContents) === 1) {
        http_response_code(422);
        echo json_encode(['error' => 'The text document must contain valid UTF-8 text no larger than 8,000 bytes.']);
        exit;
    }
    $sourceText = trim($sourceText . "\n" . $fileContents);
}
if (strlen($sourceText) > 8000) {
    http_response_code(422);
    echo json_encode(['error' => 'Combined source text must be no more than 8,000 bytes.']);
    exit;
}
if (preg_match('//u', $message) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $message) === 1) {
    http_response_code(422);
    echo json_encode(['error' => 'The message must contain valid UTF-8 text.']);
    exit;
}
if ($sourceText !== '') {
    $message = rtrim($message) . "\n\nUser-provided source material (untrusted):\n" . $sourceText;
}
if ($learningContext !== '') {
    $message = rtrim($message) . "\n\n" . $learningContext;
}
if (strlen($message) > 12500) {
    http_response_code(422);
    echo json_encode(['error' => 'The combined message and source material exceeds the allowed size.']);
    exit;
}
$conversationTitle = school_ai_conversation_title($message);

$conversationId = filter_var($_POST['conversation_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if ($conversationId === false) {
    $conversationId = null;
}
$isNewConversation = $conversationId === null;
if ($isNewConversation) {
    $createStmt = db()->prepare(
        'INSERT INTO school_ai_conversations (school_id, user_id, assistant_key)
         VALUES (?, ?, ?)'
    );
    $createStmt->execute([$schoolId, (int) $user['id'], $assistant]);
    $conversationId = (int) db()->lastInsertId();
} else {
    $conversationStmt = db()->prepare(
        'SELECT id FROM school_ai_conversations
         WHERE id = ? AND school_id = ? AND user_id = ? AND assistant_key = ? LIMIT 1'
    );
    $conversationStmt->execute([$conversationId, $schoolId, (int) $user['id'], $assistant]);
    if ($conversationStmt->fetchColumn() === false) {
        http_response_code(404);
        echo json_encode(['error' => 'Conversation not found.']);
        exit;
    }
}
if (!$isNewConversation) {
    $firstUserMessageStmt = db()->prepare(
        'SELECT content_ciphertext FROM school_ai_messages
         WHERE conversation_id = ? AND school_id = ? AND user_id = ? AND assistant_key = ? AND role = "user"
         ORDER BY id ASC LIMIT 1'
    );
    $firstUserMessageStmt->execute([$conversationId, $schoolId, (int) $user['id'], $assistant]);
    $firstUserMessage = $firstUserMessageStmt->fetchColumn();
    if (is_string($firstUserMessage) && $firstUserMessage !== '') {
        $conversationTitle = school_ai_conversation_title(school_ai_decrypt_message($firstUserMessage));
    }
}

$saveUserMessage = db()->prepare(
    'INSERT INTO school_ai_messages
        (conversation_id, school_id, user_id, assistant_key, role, content_ciphertext)
     VALUES (?, ?, ?, ?, "user", ?)'
);
$saveUserMessage->execute([
    $conversationId,
    $schoolId,
    (int) $user['id'],
    $assistant,
    school_ai_encrypt_message($message),
]);
$touchConversation = db()->prepare(
    'UPDATE school_ai_conversations SET updated_at = UTC_TIMESTAMP()
     WHERE id = ? AND school_id = ? AND user_id = ? AND assistant_key = ?'
);
$touchConversation->execute([$conversationId, $schoolId, (int) $user['id'], $assistant]);

$historyStmt = db()->prepare(
    'SELECT role, content_ciphertext FROM school_ai_messages
     WHERE conversation_id = ? AND school_id = ? AND user_id = ? AND assistant_key = ?
     ORDER BY id DESC LIMIT 10'
);
$historyStmt->execute([$conversationId, $schoolId, (int) $user['id'], $assistant]);
$history = array_reverse($historyStmt->fetchAll());
foreach ($history as &$historyMessage) {
    if (!is_string($historyMessage['role'] ?? null)
        || !in_array($historyMessage['role'], ['user', 'assistant'], true)
        || !is_string($historyMessage['content_ciphertext'] ?? null)) {
        throw new RuntimeException('Stored AI conversation content is invalid.');
    }
    $historyMessage = [
        'role' => $historyMessage['role'],
        'content' => school_ai_decrypt_message($historyMessage['content_ciphertext']),
    ];
}
unset($historyMessage);

$tools = school_ai_tools_for_assistant($assistant, $user, $schoolId, (string) $role);
try {
    $answer = school_ai_run_turn(
        $profiles[$assistant]['prompt'],
        $history,
        $tools,
        'school_ai_call_ollama'
    );
} catch (SchoolAiProviderException $exception) {
    http_response_code(503);
    echo json_encode([
        'error' => $exception->getMessage(),
        'conversation_id' => $conversationId,
        'conversation_title' => $conversationTitle,
    ], JSON_THROW_ON_ERROR);
    exit;
}

$saveAssistantMessage = db()->prepare(
    'INSERT INTO school_ai_messages
        (conversation_id, school_id, user_id, assistant_key, role, content_ciphertext)
     VALUES (?, ?, ?, ?, "assistant", ?)'
);
$saveAssistantMessage->execute([
    $conversationId,
    $schoolId,
    (int) $user['id'],
    $assistant,
    school_ai_encrypt_message($answer),
]);
$updateConversation = db()->prepare(
    'UPDATE school_ai_conversations
     SET updated_at = UTC_TIMESTAMP()
     WHERE id = ? AND school_id = ? AND user_id = ? AND assistant_key = ?'
);
$updateConversation->execute([$conversationId, $schoolId, (int) $user['id'], $assistant]);

echo json_encode([
    'answer' => $answer,
    'conversation_id' => $conversationId,
    'conversation_title' => $conversationTitle,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
