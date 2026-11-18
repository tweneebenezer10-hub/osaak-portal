USE secure_auth_system;

CREATE TABLE IF NOT EXISTS association_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(160) NOT NULL,
    description TEXT NULL,
    location VARCHAR(200) NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NULL,
    capacity INT UNSIGNED NULL,
    status ENUM('draft','published','cancelled','completed') NOT NULL DEFAULT 'draft',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_association_events_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_association_events_schedule (status, starts_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS event_registrations (
    event_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    registration_status ENUM('registered','cancelled') NOT NULL DEFAULT 'registered',
    attendance_status ENUM('unmarked','present','absent') NOT NULL DEFAULT 'unmarked',
    registered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    marked_by BIGINT UNSIGNED NULL,
    marked_at DATETIME NULL,
    PRIMARY KEY (event_id, user_id),
    CONSTRAINT fk_event_registrations_event FOREIGN KEY (event_id) REFERENCES association_events(id) ON DELETE CASCADE,
    CONSTRAINT fk_event_registrations_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_event_registrations_marker FOREIGN KEY (marked_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_event_registration_user (user_id, registration_status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS association_announcements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    body MEDIUMTEXT NOT NULL,
    audience ENUM('all','members','officials') NOT NULL DEFAULT 'all',
    status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    publish_at DATETIME NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_association_announcements_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_announcements_publication (status, publish_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS membership_dues (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(160) NOT NULL,
    description VARCHAR(500) NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'GHS',
    due_date DATE NULL,
    target_year SMALLINT UNSIGNED NULL,
    status ENUM('open','closed') NOT NULL DEFAULT 'open',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_membership_dues_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_membership_dues_status_date (status, due_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payment_records (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    due_id BIGINT UNSIGNED NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'GHS',
    payment_method VARCHAR(40) NOT NULL,
    reference VARCHAR(100) NULL,
    status ENUM('pending','confirmed','rejected','refunded') NOT NULL DEFAULT 'pending',
    note VARCHAR(500) NULL,
    recorded_by BIGINT UNSIGNED NOT NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    paid_at DATETIME NOT NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_payment_records_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_payment_records_due FOREIGN KEY (due_id) REFERENCES membership_dues(id) ON DELETE SET NULL,
    CONSTRAINT fk_payment_records_recorder FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_payment_records_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_payment_records_user_status (user_id, status, paid_at),
    INDEX idx_payment_records_status_date (status, paid_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS association_meetings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(160) NOT NULL,
    meeting_at DATETIME NOT NULL,
    location VARCHAR(200) NULL,
    agenda MEDIUMTEXT NULL,
    minutes MEDIUMTEXT NULL,
    status ENUM('scheduled','completed','cancelled') NOT NULL DEFAULT 'scheduled',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_association_meetings_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_association_meetings_schedule (status, meeting_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS meeting_attendance (
    meeting_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    attendance_status ENUM('present','absent','excused') NOT NULL,
    marked_by BIGINT UNSIGNED NOT NULL,
    marked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (meeting_id, user_id),
    CONSTRAINT fk_meeting_attendance_meeting FOREIGN KEY (meeting_id) REFERENCES association_meetings(id) ON DELETE CASCADE,
    CONSTRAINT fk_meeting_attendance_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_meeting_attendance_marker FOREIGN KEY (marked_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS association_documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(160) NOT NULL,
    description VARCHAR(500) NULL,
    stored_name VARCHAR(80) NOT NULL UNIQUE,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    visibility ENUM('members','officers') NOT NULL DEFAULT 'members',
    uploaded_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_association_documents_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_association_documents_visibility (visibility, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS association_settings (
    setting_key VARCHAR(80) NOT NULL PRIMARY KEY,
    setting_value TEXT NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_association_settings_editor FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO permissions (name, description) VALUES
    ('events.attendance.manage', 'Manage member registrations and event attendance'),
    ('meetings.attendance.manage', 'Record meeting attendance'),
    ('payments.manage', 'Record, review, and reconcile membership payments'),
    ('documents.upload', 'Upload association documents'),
    ('association.settings.manage', 'Manage association public settings');

INSERT IGNORE INTO role_permissions (role_id, permission_name)
SELECT r.id, p.name FROM roles r CROSS JOIN permissions p
WHERE r.name IN ('administrator','it_manager','association_super_admin')
  AND p.name IN ('events.attendance.manage','meetings.attendance.manage','payments.manage','documents.upload');

INSERT IGNORE INTO role_permissions (role_id, permission_name)
SELECT r.id, p.name FROM roles r CROSS JOIN permissions p
WHERE r.name IN ('secretary','assistant_secretary','executive_member','organising_secretary')
  AND p.name IN ('events.attendance.manage','meetings.attendance.manage','documents.upload');

INSERT IGNORE INTO role_permissions (role_id, permission_name)
SELECT r.id, p.name FROM roles r CROSS JOIN permissions p
WHERE r.name IN ('treasurer','assistant_treasurer')
  AND p.name IN ('payments.manage');

INSERT IGNORE INTO role_permissions (role_id, permission_name)
SELECT r.id, p.name FROM roles r CROSS JOIN permissions p
WHERE r.name = 'association_super_admin'
  AND p.name = 'association.settings.manage';
