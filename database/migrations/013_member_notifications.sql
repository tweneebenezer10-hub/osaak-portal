USE secure_auth_system;

CREATE TABLE IF NOT EXISTS association_notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    notification_key VARCHAR(120) NOT NULL,
    category ENUM('announcement','event','dues','security','support','other') NOT NULL DEFAULT 'other',
    title VARCHAR(180) NOT NULL,
    body VARCHAR(1000) NOT NULL,
    link_url VARCHAR(500) NULL,
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_association_notification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_association_notification_recipient_key (user_id, notification_key),
    INDEX idx_association_notification_unread (user_id, read_at, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_notification_preferences (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    announcements_in_app BOOLEAN NOT NULL DEFAULT TRUE,
    events_in_app BOOLEAN NOT NULL DEFAULT TRUE,
    dues_in_app BOOLEAN NOT NULL DEFAULT TRUE,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_notification_preferences_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT IGNORE INTO permissions (name, description) VALUES
    ('notifications.view_self', 'View and manage own in-app notifications');

INSERT IGNORE INTO role_permissions (role_id, permission_name)
SELECT r.id, 'notifications.view_self'
FROM roles AS r
WHERE r.name IN (
    'general_user','student','ordinary_member','executive_member',
    'administrator','it_manager','association_super_admin','president','vice_president',
    'patron','secretary','assistant_secretary','treasurer','assistant_treasurer',
    'organising_secretary','public_relations_officer','electoral_officer','auditor',
    'welfare_officer','committee_chairperson','class_representative','branch_coordinator'
);
