USE secure_auth_system;

CREATE TABLE IF NOT EXISTS support_tickets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(32) NOT NULL UNIQUE,
    user_id BIGINT UNSIGNED NOT NULL,
    subject VARCHAR(180) NOT NULL,
    category ENUM('membership','account_access','payments','events','technical','other') NOT NULL DEFAULT 'other',
    status ENUM('open','in_progress','awaiting_member','resolved','closed') NOT NULL DEFAULT 'open',
    assigned_to BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_support_ticket_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_support_ticket_assignee FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_support_ticket_user_updated (user_id, updated_at),
    INDEX idx_support_ticket_queue (status, updated_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS support_ticket_replies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_support_reply_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_support_reply_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_support_reply_ticket_created (ticket_id, created_at)
) ENGINE=InnoDB;

INSERT IGNORE INTO permissions (name, description) VALUES
    ('support.view_self', 'Create and view own support tickets'),
    ('support.manage', 'Review and respond to association support tickets');

INSERT IGNORE INTO role_permissions (role_id, permission_name)
SELECT r.id, 'support.view_self'
FROM roles AS r
WHERE r.name IN ('general_user','student','ordinary_member','executive_member');

INSERT IGNORE INTO role_permissions (role_id, permission_name)
SELECT r.id, 'support.manage'
FROM roles AS r
WHERE r.name IN ('administrator','association_super_admin','secretary','assistant_secretary');
