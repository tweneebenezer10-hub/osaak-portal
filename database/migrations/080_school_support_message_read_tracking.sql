ALTER TABLE school_support_messages
    ADD COLUMN request_key CHAR(32) NULL AFTER message,
    ADD COLUMN read_at DATETIME NULL AFTER created_at,
    ADD COLUMN read_by_user_id BIGINT UNSIGNED NULL AFTER read_at,
    ADD KEY idx_school_support_messages_unread (ticket_id, read_at),
    ADD UNIQUE KEY uq_school_support_reply_request (ticket_id, sender_user_id, request_key),
    ADD CONSTRAINT fk_school_support_messages_reader
        FOREIGN KEY (read_by_user_id) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE school_support_tickets
    ADD COLUMN read_at DATETIME NULL AFTER updated_at,
    ADD COLUMN read_by_user_id BIGINT UNSIGNED NULL AFTER read_at,
    ADD COLUMN replied_at DATETIME NULL AFTER read_by_user_id,
    ADD KEY idx_school_support_ticket_unread (status, read_at),
    ADD CONSTRAINT fk_school_support_tickets_reader
        FOREIGN KEY (read_by_user_id) REFERENCES users(id) ON DELETE SET NULL;
