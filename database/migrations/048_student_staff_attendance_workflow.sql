ALTER TABLE student_attendance
    ADD COLUMN legacy_status ENUM('late','excused') NULL AFTER status;

UPDATE student_attendance
SET legacy_status = status,
    status = CASE status WHEN 'late' THEN 'present' ELSE 'absent' END
WHERE status IN ('late','excused');

ALTER TABLE student_attendance
    MODIFY COLUMN status ENUM('present','absent') NOT NULL DEFAULT 'present';

ALTER TABLE school_staff_attendance
    ADD COLUMN arrival_at DATETIME NULL AFTER attendance_date,
    ADD COLUMN reviewed_by BIGINT UNSIGNED NULL AFTER recorded_at,
    ADD COLUMN reviewed_at DATETIME NULL AFTER reviewed_by,
    MODIFY COLUMN status ENUM('pending','present','absent','excused','late') NOT NULL DEFAULT 'pending',
    ADD KEY idx_school_staff_attendance_review (school_id, attendance_date, status),
    ADD CONSTRAINT fk_school_staff_attendance_reviewer
        FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL;

INSERT IGNORE INTO school_settings (school_id, setting_key, setting_value)
SELECT id, 'staff_reporting_time', '08:00' FROM schools;

INSERT IGNORE INTO school_settings (school_id, setting_key, setting_value)
SELECT id, 'staff_late_grace_minutes', '15' FROM schools;