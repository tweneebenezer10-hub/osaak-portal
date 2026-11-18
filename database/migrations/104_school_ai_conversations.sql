CREATE TABLE school_ai_conversations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    assistant_key VARCHAR(32) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_school_ai_conversation_scope (id, school_id, user_id, assistant_key),
    KEY idx_school_ai_conversations_user (school_id, user_id, assistant_key, updated_at),
    CONSTRAINT fk_school_ai_conversation_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_ai_conversation_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE school_ai_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id BIGINT UNSIGNED NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    assistant_key VARCHAR(32) NOT NULL,
    role ENUM('user', 'assistant') NOT NULL,
    content_ciphertext MEDIUMBLOB NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_school_ai_messages_history (conversation_id, school_id, user_id, assistant_key, id),
    CONSTRAINT fk_school_ai_message_conversation
        FOREIGN KEY (conversation_id, school_id, user_id, assistant_key)
        REFERENCES school_ai_conversations(id, school_id, user_id, assistant_key)
        ON DELETE CASCADE
) ENGINE=InnoDB;
