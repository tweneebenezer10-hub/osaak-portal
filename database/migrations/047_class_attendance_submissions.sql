USE secure_auth_system;

CREATE TABLE IF NOT EXISTS school_class_attendance_submissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    class_id BIGINT UNSIGNED NOT NULL,
    attendance_date DATE NOT NULL,
    teacher_user_id BIGINT UNSIGNED NOT NULL,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_school_class_attendance_submission (school_id, class_id, attendance_date),
    KEY idx_school_class_attendance_date (school_id, attendance_date),
    CONSTRAINT fk_school_class_attendance_submission_school
        FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_class_attendance_submission_class
        FOREIGN KEY (class_id) REFERENCES school_classes(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_class_attendance_submission_teacher
        FOREIGN KEY (teacher_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_school_class_attendance_submission_reviewer
        FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
