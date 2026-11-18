USE secure_auth_system;

ALTER TABLE support_tickets
    MODIFY COLUMN category ENUM(
        'login_account',
        'membership',
        'payments_dues',
        'website_problem',
        'technical_support',
        'other',
        'account_access',
        'payments',
        'events',
        'technical'
    ) NOT NULL DEFAULT 'other',
    ADD COLUMN IF NOT EXISTS priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
    ADD COLUMN IF NOT EXISTS acknowledged_at DATETIME NULL,
    ADD INDEX IF NOT EXISTS idx_support_ticket_attention (acknowledged_at, status, created_at);

CREATE TABLE IF NOT EXISTS support_ticket_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id BIGINT UNSIGNED NOT NULL,
    changed_by BIGINT UNSIGNED NOT NULL,
    event_type ENUM('created','acknowledged','updated') NOT NULL,
    old_status VARCHAR(32) NULL,
    new_status VARCHAR(32) NULL,
    old_priority VARCHAR(16) NULL,
    new_priority VARCHAR(16) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_support_history_ticket
        FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_support_history_user
        FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_support_history_ticket_created (ticket_id, created_at, id)
) ENGINE=InnoDB;

INSERT IGNORE INTO role_permissions (role_id, permission_name)
SELECT r.id, 'support.manage'
FROM roles AS r
WHERE r.name = 'it_manager';
