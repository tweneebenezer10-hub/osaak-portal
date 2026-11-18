ALTER TABLE school_students
    ADD COLUMN photo_path VARCHAR(100) NULL AFTER guardian_id;

CREATE TABLE IF NOT EXISTS school_calendar_days (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    calendar_date DATE NOT NULL,
    day_type ENUM('holiday','closure','school_day') NOT NULL,
    label VARCHAR(120) NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_school_calendar_date (school_id, calendar_date),
    KEY idx_school_calendar_range (school_id, calendar_date, day_type),
    CONSTRAINT fk_school_calendar_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_calendar_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS school_staff_attendance (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    staff_id BIGINT UNSIGNED NOT NULL,
    attendance_date DATE NOT NULL,
    status ENUM('present','absent','late','excused') NOT NULL,
    remarks VARCHAR(255) NULL,
    recorded_by BIGINT UNSIGNED NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_school_staff_attendance_day (school_id, staff_id, attendance_date),
    KEY idx_school_staff_attendance_date (school_id, attendance_date),
    CONSTRAINT fk_school_staff_attendance_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_staff_attendance_staff FOREIGN KEY (staff_id) REFERENCES school_staff(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_staff_attendance_recorder FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
