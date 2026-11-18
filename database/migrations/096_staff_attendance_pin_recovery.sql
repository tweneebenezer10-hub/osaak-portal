CREATE TABLE school_staff_attendance_pin_resets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    staff_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    initiated_by BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(255) NOT NULL,
    failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    completed_by BIGINT UNSIGNED NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_attendance_pin_reset_token (school_id, token_hash),
    KEY idx_staff_attendance_pin_reset_pending (school_id, staff_id, completed_at, revoked_at, expires_at),
    KEY idx_staff_attendance_pin_reset_history (school_id, created_at),
    CONSTRAINT fk_staff_attendance_pin_reset_school
        FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_staff_attendance_pin_reset_staff
        FOREIGN KEY (staff_id) REFERENCES school_staff(id) ON DELETE CASCADE,
    CONSTRAINT fk_staff_attendance_pin_reset_initiator
        FOREIGN KEY (initiated_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_staff_attendance_pin_reset_completer
        FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
