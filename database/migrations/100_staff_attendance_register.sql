ALTER TABLE school_staff_attendance
    MODIFY verification_method ENUM(
        'administrator_recorded',
        'kiosk_verified',
        'fingerprint_verified',
        'system_generated'
    ) NOT NULL DEFAULT 'administrator_recorded';

ALTER TABLE school_staff_attendance_audit
    MODIFY change_type ENUM(
        'kiosk_check_in',
        'kiosk_check_out',
        'administrator_recorded',
        'corrected',
        'excuse_approved',
        'dispute_reviewed',
        'makeup_credit',
        'system_absence_recorded'
    ) NOT NULL,
    MODIFY verification_method ENUM(
        'administrator_recorded',
        'kiosk_verified',
        'fingerprint_verified',
        'system_generated'
    ) NOT NULL;

SET @staff_user_restrict_constraint = CONCAT(
    'fk_school_staff_user_100_',
    MD5(DATABASE())
);
SET @staff_user_restrict_sql = CONCAT(
    'ALTER TABLE `school_staff` DROP FOREIGN KEY `fk_school_staff_user`, ',
    'ADD CONSTRAINT `', @staff_user_restrict_constraint, '` ',
    'FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT'
);
PREPARE staff_user_restrict_statement FROM @staff_user_restrict_sql;
EXECUTE staff_user_restrict_statement;
DEALLOCATE PREPARE staff_user_restrict_statement;

INSERT IGNORE INTO school_settings (school_id, setting_key, setting_value)
SELECT id, 'staff_attendance_register_start_date', DATE_FORMAT(CURRENT_DATE, '%Y-%m-%d')
FROM schools;
