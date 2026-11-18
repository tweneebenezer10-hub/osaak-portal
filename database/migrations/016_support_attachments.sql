USE secure_auth_system;

CREATE TABLE IF NOT EXISTS support_ticket_attachments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reply_id BIGINT UNSIGNED NOT NULL,
    uploaded_by BIGINT UNSIGNED NOT NULL,
    stored_name VARCHAR(80) NOT NULL UNIQUE,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_support_attachment_reply
        FOREIGN KEY (reply_id) REFERENCES support_ticket_replies(id) ON DELETE CASCADE,
    CONSTRAINT fk_support_attachment_uploader
        FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_support_attachment_reply (reply_id, created_at)
) ENGINE=InnoDB;
