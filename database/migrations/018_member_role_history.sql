USE secure_auth_system;

CREATE TABLE IF NOT EXISTS member_role_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    target_user_id BIGINT UNSIGNED NOT NULL,
    member_name VARCHAR(120) NOT NULL,
    membership_number VARCHAR(32) NULL,
    role_name VARCHAR(32) NOT NULL,
    action ENUM('assigned','removed') NOT NULL,
    changed_by BIGINT UNSIGNED NOT NULL,
    changed_by_name VARCHAR(120) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_member_role_history_target (target_user_id, created_at),
    INDEX idx_member_role_history_created (created_at)
) ENGINE=InnoDB;
