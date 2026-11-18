<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = require_school_context();
$school = active_school_context();
$schoolId = (int) $school['id'];
if (active_it_support_context($user) !== null) {
    http_response_code(403);
    exit('Connezer Ai is unavailable in IT Support preview sessions.');
}
$role = school_user_role_for_school((int) $user['id'], $schoolId);
$assistantKeys = school_ai_assistant_keys_for_role((string) $role);
$assistantOrder = ['general', 'teaching', 'school_management'];
$assistantKeys = array_values(array_merge(
    array_values(array_intersect($assistantOrder, $assistantKeys)),
    array_values(array_diff($assistantKeys, $assistantOrder))
));
$profiles = school_ai_assistant_profiles();
$teachingClassLevels = school_class_levels($schoolId);
$assistantLabels = [
    'general' => 'General Assistant',
    'teaching' => 'Teaching Assistant',
    'school_management' => 'Management Assistant',
];
$assistant = (string) ($_GET['assistant'] ?? 'general');
if (!in_array($assistant, $assistantKeys, true) || !isset($profiles[$assistant])) {
    $assistant = 'general';
}
$conversationId = filter_var($_GET['conversation'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if ($conversationId === false) {
    $conversationId = null;
}

$conversations = [];
if ($assistantKeys !== []) {
    $assistantPlaceholders = implode(',', array_fill(0, count($assistantKeys), '?'));
    $conversationsStmt = db()->prepare(
        'SELECT c.id, c.assistant_key,
                (SELECT m.content_ciphertext
                 FROM school_ai_messages m
                 WHERE m.conversation_id = c.id AND m.school_id = c.school_id
                   AND m.user_id = c.user_id AND m.assistant_key = c.assistant_key
                   AND m.role = "user"
                 ORDER BY m.id ASC LIMIT 1) AS first_user_message
         FROM school_ai_conversations c
         WHERE c.school_id = ? AND c.user_id = ?
           AND c.assistant_key IN (' . $assistantPlaceholders . ')
         ORDER BY c.updated_at DESC, c.id DESC'
    );
    $conversationsStmt->execute([$schoolId, (int) $user['id'], ...$assistantKeys]);
    $conversations = $conversationsStmt->fetchAll();
}
foreach ($conversations as &$conversation) {
    $firstMessage = $conversation['first_user_message'] ?? null;
    $conversation['title'] = is_string($firstMessage) && $firstMessage !== ''
        ? school_ai_conversation_title(school_ai_decrypt_message($firstMessage))
        : 'New conversation';
    unset($conversation['first_user_message']);
}
unset($conversation);
$activeConversation = null;
$history = [];
if ($conversationId !== null) {
    $conversationStmt = db()->prepare(
        'SELECT id FROM school_ai_conversations
         WHERE id = ? AND school_id = ? AND user_id = ? AND assistant_key = ? LIMIT 1'
    );
    $conversationStmt->execute([$conversationId, $schoolId, (int) $user['id'], $assistant]);
    if ($conversationStmt->fetchColumn() === false) {
        http_response_code(404);
        exit('Conversation not found.');
    }
    $activeConversation = (int) $conversationId;
    $messagesStmt = db()->prepare(
        'SELECT role, content_ciphertext
         FROM school_ai_messages
         WHERE conversation_id = ? AND school_id = ? AND user_id = ? AND assistant_key = ?
         ORDER BY id DESC LIMIT 30'
    );
    $messagesStmt->execute([$activeConversation, $schoolId, (int) $user['id'], $assistant]);
    $history = array_reverse($messagesStmt->fetchAll());
    foreach ($history as &$message) {
        if (!is_string($message['content_ciphertext'] ?? null)) {
            throw new RuntimeException('Stored AI conversation content is invalid.');
        }
        $message['content'] = school_ai_decrypt_message($message['content_ciphertext']);
        unset($message['content_ciphertext']);
    }
    unset($message);
}
$profile = $profiles[$assistant];
render_header('Connezer Ai');
?>
<div class="container ai-dashboard">
    <h1 class="ai-visually-hidden">Connezer Ai</h1>
    <div class="ai-dashboard-grid">
        <aside class="panel ai-sidebar" aria-label="Connezer AI chats and assistants">
            <h2 class="ai-brand-heading">Connezer AI <span aria-hidden="true">🤖</span></h2>
            <nav class="ai-assistant-list" aria-label="Available assistants">
                <?php foreach ($assistantKeys as $key): ?>
                    <?php if (!isset($profiles[$key])) { continue; } ?>
                    <a class="<?= $key === $assistant ? 'active' : '' ?>"
                       href="ai_dashboard.php?assistant=<?= e($key) ?>"
                       aria-label="<?= e($profiles[$key]['name'] . ': ' . $profiles[$key]['description']) ?>"
                       title="<?= e($profiles[$key]['description']) ?>"
                       <?= $key === $assistant ? 'aria-current="page"' : '' ?>>
                        <strong><?= e($assistantLabels[$key] ?? $profiles[$key]['name']) ?></strong>
                    </a>
                <?php endforeach; ?>
            </nav>
            <a class="button secondary ai-new-chat" href="ai_dashboard.php?assistant=<?= e($assistant) ?>">
                <span aria-hidden="true">+</span> New chat
            </a>
            <section class="ai-recents" aria-labelledby="ai-recents-title">
                <div class="ai-conversation-heading">
                    <h2 class="ai-sidebar-label" id="ai-recents-title">Recents</h2>
                </div>
            <?php if ($conversations === []): ?>
                <p class="muted ai-no-chats" data-ai-no-conversations>No recent conversations.</p>
            <?php else: ?>
                <ul class="ai-conversation-list" data-ai-conversations>
                    <?php foreach ($conversations as $conversation): ?>
                        <?php $conversationAssistant = (string) $conversation['assistant_key']; ?>
                        <li>
                            <a class="<?= (int) $conversation['id'] === $activeConversation && $conversationAssistant === $assistant ? 'active' : '' ?>"
                               data-conversation-id="<?= (int) $conversation['id'] ?>"
                               <?= (int) $conversation['id'] === $activeConversation && $conversationAssistant === $assistant ? 'aria-current="page"' : '' ?>
                               href="ai_dashboard.php?assistant=<?= e($conversationAssistant) ?>&amp;conversation=<?= (int) $conversation['id'] ?>"
                               title="<?= e($conversation['title']) ?>">
                                <?= e($conversation['title']) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            </section>
        </aside>
        <section class="panel ai-chat-panel" aria-labelledby="ai-assistant-title">
            <div class="ai-chat-header">
                <div>
                    <h1 id="ai-assistant-title"><?= e($profile['name']) ?></h1>
                    <p class="ai-context-note">
                        <?= $assistant === 'general' ? 'No school database access' : ($assistant === 'school_management' ? 'Read-only school data · role-scoped' : 'Learning support · school-defined classes') ?>
                    </p>
                </div>
                <span class="ai-local-badge">Local · Private</span>
            </div>
            <div class="ai-messages" data-ai-messages aria-live="polite">
                <?php if ($history === []): ?>
                    <div class="ai-empty-state" data-ai-empty>
                        <strong>How can I help?</strong>
                        <span>Ask a question or describe what you need.</span>
                    </div>
                <?php else: ?>
                    <?php foreach ($history as $message): ?>
                        <article class="ai-message <?= $message['role'] === 'user' ? 'user' : 'assistant' ?>">
                            <strong><?= $message['role'] === 'user' ? 'You' : e($profile['name']) ?></strong>
                            <p><?= nl2br(e((string) $message['content'])) ?></p>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <form class="ai-chat-form" data-ai-form method="post" action="ai_chat.php" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="assistant" value="<?= e($assistant) ?>">
                <input type="hidden" name="conversation_id" value="<?= $activeConversation === null ? '' : $activeConversation ?>" data-ai-conversation>
                <div class="ai-composer">
                    <details class="ai-options">
                        <summary class="ai-composer-icon ai-add-context" aria-label="Add context or a file" title="Add context or a file">
                            <span aria-hidden="true">+</span>
                        </summary>
                        <div class="ai-options-panel">
                            <div class="ai-options-grid">
                                <?php if ($assistant === 'teaching'): ?>
                                    <label for="ai-class-level">Class level
                                        <select id="ai-class-level" name="class_level">
                                            <option value="">Choose a class level</option>
                                            <?php foreach ($teachingClassLevels as $classLevel): ?>
                                                <option value="<?= e($classLevel) ?>"><?= e($classLevel) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label for="ai-subject">Subject
                                        <input id="ai-subject" type="text" name="subject" maxlength="120" autocomplete="off" placeholder="Optional">
                                    </label>
                                <?php endif; ?>
                                <label class="ai-source-label" for="ai-source-material">Source text
                                    <textarea id="ai-source-material" name="source_material" rows="3" maxlength="8000"
                                              placeholder="Paste notes or reference text"></textarea>
                                </label>
                                <label class="ai-source-label" for="ai-source-file">Attach a text file
                                    <input id="ai-source-file" type="file" name="source_file" accept=".txt,.md,.csv,text/plain">
                                </label>
                            </div>
                            <p class="ai-option-note">TXT, MD, or CSV · up to 256 KB. Files are not stored.</p>
                        </div>
                    </details>
                    <label class="ai-visually-hidden" for="ai-message">Message</label>
                    <textarea id="ai-message" name="message" rows="1" maxlength="4000" required
                              placeholder="<?= $assistant === 'teaching' ? 'Ask about a lesson, topic, or class...' : 'Ask anything...' ?>"></textarea>
                    <button class="ai-composer-icon ai-voice" type="button" data-ai-voice aria-label="Voice input unavailable" title="Voice input is unavailable in this browser" disabled>
                        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="9" y="3" width="6" height="12" rx="3"></rect>
                            <path d="M5.5 11a6.5 6.5 0 0 0 13 0M12 18v3m-4 0h8"></path>
                        </svg>
                    </button>
                    <button class="button ai-send" type="submit" data-ai-submit aria-label="Send message" title="Send message" disabled>
                        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 19V5m-7 7 7-7 7 7"></path>
                        </svg>
                    </button>
                </div>
                <div class="ai-form-footer">
                    <p class="ai-status" data-ai-status aria-live="polite">Ready</p>
                    <p class="form-error" data-ai-error role="alert" hidden></p>
                </div>
            </form>
        </section>
    </div>
</div>
<script src="assets/js/ai-dashboard.js?v=5" defer></script>
<?php render_footer(); ?>
