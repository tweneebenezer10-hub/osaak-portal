ALTER TABLE student_attendance
    ADD COLUMN created_by BIGINT UNSIGNED NULL AFTER class_id,
    ADD COLUMN updated_by BIGINT UNSIGNED NULL AFTER created_by,
    ADD CONSTRAINT fk_student_attendance_creator
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_student_attendance_updater
        FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL;

UPDATE student_attendance
SET created_by = recorded_by,
    updated_by = recorded_by
WHERE created_by IS NULL OR updated_by IS NULL;

ALTER TABLE school_staff_attendance
    ADD COLUMN created_by BIGINT UNSIGNED NULL AFTER staff_id,
    ADD COLUMN updated_by BIGINT UNSIGNED NULL AFTER created_by,
    ADD CONSTRAINT fk_school_staff_attendance_creator
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_school_staff_attendance_updater
        FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL;

UPDATE school_staff_attendance
SET created_by = recorded_by,
    updated_by = recorded_by
WHERE created_by IS NULL OR updated_by IS NULL;
