USE secure_auth_system;

ALTER TABLE mentorship_profiles
    ADD COLUMN IF NOT EXISTS teach_areas VARCHAR(500) NULL AFTER expertise,
    ADD COLUMN IF NOT EXISTS career_interest VARCHAR(500) NULL AFTER interests,
    ADD COLUMN IF NOT EXISTS goals VARCHAR(1000) NULL AFTER career_interest,
    ADD COLUMN IF NOT EXISTS mentorship_bio VARCHAR(1000) NULL AFTER goals,
    ADD COLUMN IF NOT EXISTS is_available BOOLEAN NOT NULL DEFAULT TRUE AFTER availability;

CREATE TABLE IF NOT EXISTS mentorship_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sender_id BIGINT UNSIGNED NOT NULL,
    recipient_id BIGINT UNSIGNED NOT NULL,
    message VARCHAR(2000) NOT NULL,
    interests VARCHAR(500) NULL,
    status ENUM('pending','accepted','declined','cancelled','ended') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    responded_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    pending_member_low BIGINT UNSIGNED
        GENERATED ALWAYS AS (CASE WHEN status='pending' THEN LEAST(sender_id,recipient_id) ELSE NULL END) STORED,
    pending_member_high BIGINT UNSIGNED
        GENERATED ALWAYS AS (CASE WHEN status='pending' THEN GREATEST(sender_id,recipient_id) ELSE NULL END) STORED,
    CONSTRAINT chk_mentorship_request_distinct_members CHECK (sender_id <> recipient_id),
    CONSTRAINT fk_mentorship_request_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_mentorship_request_recipient FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_mentorship_pending_pair (pending_member_low,pending_member_high),
    INDEX idx_mentorship_request_inbox (recipient_id,status,created_at),
    INDEX idx_mentorship_request_outbox (sender_id,status,created_at)
) ENGINE=InnoDB;
