ALTER TABLE school_staff_attendance_schedules
    ADD COLUMN morning_open_time TIME NOT NULL DEFAULT '05:45:00' AFTER reporting_time,
    ADD COLUMN morning_close_time TIME NOT NULL DEFAULT '09:00:00' AFTER morning_open_time,
    ADD COLUMN afternoon_open_time TIME NOT NULL DEFAULT '14:30:00' AFTER closing_time,
    ADD COLUMN afternoon_close_time TIME NOT NULL DEFAULT '16:00:00' AFTER afternoon_open_time,
    ADD COLUMN failed_pin_attempt_limit TINYINT UNSIGNED NOT NULL DEFAULT 5 AFTER photo_retention_days,
    ADD COLUMN lockout_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 15 AFTER failed_pin_attempt_limit;

INSERT IGNORE INTO school_settings (school_id, setting_key, setting_value, updated_by)
SELECT id, 'staff_attendance_timezone', 'Africa/Accra', NULL
FROM schools
WHERE deleted_at IS NULL;

INSERT IGNORE INTO school_settings (school_id, setting_key, setting_value, updated_by)
SELECT id, 'staff_attendance_kiosk_configured', '0', NULL
FROM schools
WHERE deleted_at IS NULL;

CREATE TABLE school_staff_attendance_kiosk_devices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    device_name VARCHAR(100) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    registered_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    deactivated_by BIGINT UNSIGNED NULL,
    deactivated_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_attendance_kiosk_device_token (school_id, token_hash),
    KEY idx_staff_attendance_kiosk_device_active (school_id, deactivated_at, created_at),
    CONSTRAINT fk_staff_attendance_kiosk_device_school
        FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_staff_attendance_kiosk_device_registrar
        FOREIGN KEY (registered_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_staff_attendance_kiosk_device_deactivator
        FOREIGN KEY (deactivated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE school_staff_attendance_kiosk_reopenings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    attendance_date DATE NOT NULL,
    window_type ENUM('morning', 'afternoon') NOT NULL,
    role_name VARCHAR(50) NOT NULL,
    opened_at DATETIME NOT NULL,
    closes_at DATETIME NOT NULL,
    reason VARCHAR(255) NOT NULL,
    authorized_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_staff_attendance_kiosk_reopen_window (school_id, attendance_date, role_name, window_type, closes_at),
    CONSTRAINT fk_staff_attendance_kiosk_reopen_school
        FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_staff_attendance_kiosk_reopen_authorizer
        FOREIGN KEY (authorized_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE school_staff_attendance_security_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    device_id BIGINT UNSIGNED NULL,
    staff_id BIGINT UNSIGNED NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    event_type VARCHAR(48) NOT NULL,
    client_key CHAR(64) NULL,
    reason VARCHAR(255) NULL,
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_staff_attendance_security_event_school_time (school_id, occurred_at),
    KEY idx_staff_attendance_security_event_type (school_id, event_type, occurred_at),
    KEY idx_staff_attendance_security_event_staff (school_id, staff_id, occurred_at),
    CONSTRAINT fk_staff_attendance_security_event_school
        FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_staff_attendance_security_event_device
        FOREIGN KEY (device_id) REFERENCES school_staff_attendance_kiosk_devices(id) ON DELETE SET NULL,
    CONSTRAINT fk_staff_attendance_security_event_staff
        FOREIGN KEY (staff_id) REFERENCES school_staff(id) ON DELETE SET NULL,
    CONSTRAINT fk_staff_attendance_security_event_actor
        FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
