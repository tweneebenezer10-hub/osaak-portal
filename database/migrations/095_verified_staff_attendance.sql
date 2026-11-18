ALTER TABLE school_staff_attendance
    ADD COLUMN punctuality_status ENUM('early','punctual','late','very_late') NULL AFTER status,
    ADD COLUMN minutes_late SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER punctuality_status,
    ADD COLUMN verification_method ENUM('administrator_recorded','kiosk_verified','fingerprint_verified')
        NOT NULL DEFAULT 'administrator_recorded' AFTER minutes_late,
    ADD COLUMN departure_at DATETIME NULL AFTER arrival_at,
    ADD COLUMN working_minutes SMALLINT UNSIGNED NULL AFTER departure_at,
    ADD COLUMN early_departure TINYINT(1) NOT NULL DEFAULT 0 AFTER working_minutes,
    ADD COLUMN departure_verification_method ENUM('administrator_recorded','kiosk_verified','fingerprint_verified')
        NULL AFTER early_departure,
    ADD KEY idx_school_staff_attendance_punctuality (school_id, attendance_date, punctuality_status);

CREATE TABLE school_staff_attendance_schedules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    role_name VARCHAR(50) NOT NULL,
    reporting_time TIME NOT NULL DEFAULT '08:00:00',
    grace_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 15,
    very_late_after_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    closing_time TIME NOT NULL DEFAULT '15:00:00',
    early_departure_threshold_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    working_weekdays VARCHAR(32) NOT NULL DEFAULT '[1,2,3,4,5]',
    clock_out_enabled TINYINT(1) NOT NULL DEFAULT 0,
    photo_verification_enabled TINYINT(1) NOT NULL DEFAULT 0,
    photo_retention_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_attendance_schedule_role (school_id, role_name),
    KEY idx_staff_attendance_schedule_school (school_id),
    CONSTRAINT fk_staff_attendance_schedule_school
        FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_staff_attendance_schedule_editor
        FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE school_staff_attendance_credentials (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    staff_id BIGINT UNSIGNED NOT NULL,
    pin_hash VARCHAR(255) NOT NULL,
    failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    last_failed_at DATETIME NULL,
    photo_consent_at DATETIME NULL,
    pin_changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_attendance_credential_staff (school_id, staff_id),
    KEY idx_staff_attendance_credential_lock (school_id, locked_until),
    CONSTRAINT fk_staff_attendance_credential_school
        FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_staff_attendance_credential_staff
        FOREIGN KEY (staff_id) REFERENCES school_staff(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE school_staff_attendance_kiosk_limits (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    client_key CHAR(64) NOT NULL,
    failed_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_until DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_attendance_kiosk_client (school_id, client_key),
    KEY idx_staff_attendance_kiosk_lock (school_id, locked_until),
    CONSTRAINT fk_staff_attendance_kiosk_school
        FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE school_staff_attendance_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    attendance_id BIGINT UNSIGNED NOT NULL,
    staff_id BIGINT UNSIGNED NOT NULL,
    changed_by BIGINT UNSIGNED NULL,
    change_type ENUM('kiosk_check_in','kiosk_check_out','administrator_recorded','corrected',
        'excuse_approved','dispute_reviewed') NOT NULL,
    original_snapshot LONGTEXT NULL,
    updated_snapshot LONGTEXT NOT NULL,
    reason VARCHAR(500) NULL,
    verification_method ENUM('administrator_recorded','kiosk_verified','fingerprint_verified') NOT NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_staff_attendance_audit_record (school_id, attendance_id, changed_at),
    KEY idx_staff_attendance_audit_staff (school_id, staff_id, changed_at),
    CONSTRAINT fk_staff_attendance_audit_school
        FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_staff_attendance_audit_record
        FOREIGN KEY (attendance_id) REFERENCES school_staff_attendance(id) ON DELETE CASCADE,
    CONSTRAINT fk_staff_attendance_audit_staff
        FOREIGN KEY (staff_id) REFERENCES school_staff(id) ON DELETE CASCADE,
    CONSTRAINT fk_staff_attendance_audit_actor
        FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE school_staff_attendance_disputes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    staff_id BIGINT UNSIGNED NOT NULL,
    attendance_id BIGINT UNSIGNED NULL,
    submitted_by BIGINT UNSIGNED NOT NULL,
    message VARCHAR(1000) NOT NULL,
    status ENUM('pending','in_review','resolved') NOT NULL DEFAULT 'pending',
    response VARCHAR(1000) NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_staff_attendance_dispute_review (school_id, status, created_at),
    KEY idx_staff_attendance_dispute_staff (school_id, staff_id, created_at),
    CONSTRAINT fk_staff_attendance_dispute_school
        FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_staff_attendance_dispute_staff
        FOREIGN KEY (staff_id) REFERENCES school_staff(id) ON DELETE CASCADE,
    CONSTRAINT fk_staff_attendance_dispute_record
        FOREIGN KEY (attendance_id) REFERENCES school_staff_attendance(id) ON DELETE SET NULL,
    CONSTRAINT fk_staff_attendance_dispute_submitter
        FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_staff_attendance_dispute_reviewer
        FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE school_staff_attendance_photos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    staff_id BIGINT UNSIGNED NOT NULL,
    attendance_id BIGINT UNSIGNED NOT NULL,
    stored_name CHAR(48) NOT NULL,
    mime_type VARCHAR(32) NOT NULL,
    captured_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_attendance_photo_name (stored_name),
    KEY idx_staff_attendance_photo_expiry (expires_at),
    KEY idx_staff_attendance_photo_record (school_id, attendance_id),
    CONSTRAINT fk_staff_attendance_photo_school
        FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_staff_attendance_photo_staff
        FOREIGN KEY (staff_id) REFERENCES school_staff(id) ON DELETE CASCADE,
    CONSTRAINT fk_staff_attendance_photo_record
        FOREIGN KEY (attendance_id) REFERENCES school_staff_attendance(id) ON DELETE CASCADE
) ENGINE=InnoDB;
