ALTER TABLE student_attendance
    ADD COLUMN created_at DATETIME NULL AFTER recorded_by,
    ADD COLUMN updated_at DATETIME NULL AFTER created_at;

UPDATE student_attendance
SET created_at = recorded_at,
    updated_at = recorded_at
WHERE created_at IS NULL OR updated_at IS NULL;

ALTER TABLE student_attendance
    MODIFY COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    MODIFY COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE school_staff_attendance
    ADD COLUMN expected_reporting_time TIME NULL AFTER arrival_at;

UPDATE school_staff_attendance attendance
JOIN school_settings setting
  ON setting.school_id = attendance.school_id
 AND setting.setting_key = 'staff_reporting_time'
SET attendance.expected_reporting_time = STR_TO_DATE(setting.setting_value, '%H:%i')
WHERE attendance.expected_reporting_time IS NULL;

ALTER TABLE school_staff_attendance
    ADD COLUMN created_at DATETIME NULL AFTER recorded_by,
    ADD COLUMN updated_at DATETIME NULL AFTER created_at;

UPDATE school_staff_attendance
SET created_at = recorded_at,
    updated_at = recorded_at
WHERE created_at IS NULL OR updated_at IS NULL;

ALTER TABLE school_staff_attendance
    MODIFY COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    MODIFY COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
