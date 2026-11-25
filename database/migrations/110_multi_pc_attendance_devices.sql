ALTER TABLE school_staff_attendance_kiosk_devices
    ADD COLUMN device_location VARCHAR(120) NOT NULL DEFAULT 'Main Office' AFTER device_name,
    ADD COLUMN status ENUM('active', 'inactive', 'revoked') NOT NULL DEFAULT 'active' AFTER token_hash,
    ADD COLUMN device_uid CHAR(64) NULL AFTER status,
    ADD COLUMN agent_secret_hash CHAR(64) NULL AFTER device_uid,
    ADD COLUMN auth_mode ENUM('local_agent', 'browser_credential') NOT NULL DEFAULT 'browser_credential' AFTER agent_secret_hash,
    ADD COLUMN enrollment_code_hash CHAR(64) NULL AFTER auth_mode,
    ADD COLUMN enrollment_expires_at DATETIME NULL AFTER enrollment_code_hash,
    ADD COLUMN revoked_by BIGINT UNSIGNED NULL AFTER deactivated_at,
    ADD COLUMN revoked_at DATETIME NULL AFTER revoked_by,
    ADD COLUMN replaced_by_device_id BIGINT UNSIGNED NULL AFTER revoked_at,
    ADD UNIQUE KEY uk_staff_attendance_kiosk_school_uid (school_id, device_uid);

ALTER TABLE school_staff_attendance
    ADD COLUMN device_id BIGINT UNSIGNED NULL AFTER verification_method,
    ADD COLUMN checkout_device_id BIGINT UNSIGNED NULL AFTER departure_verification_method,
    ADD KEY idx_school_staff_attendance_device (school_id, device_id, attendance_date);
