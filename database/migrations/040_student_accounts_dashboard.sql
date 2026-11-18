CREATE TABLE IF NOT EXISTS school_student_account_links (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    requested_admission_number VARCHAR(50) NULL,
    status ENUM('pending', 'active', 'rejected') NOT NULL DEFAULT 'pending',
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_school_student_account_student (student_id),
    UNIQUE KEY uk_school_student_account_user (user_id),
    KEY idx_school_student_account_pending (school_id, status, requested_at),
    CONSTRAINT fk_school_student_account_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT,
    CONSTRAINT fk_school_student_account_student FOREIGN KEY (student_id) REFERENCES school_students(id) ON DELETE RESTRICT,
    CONSTRAINT fk_school_student_account_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_school_student_account_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

ALTER TABLE school_assessments
    ADD COLUMN published_at DATETIME NULL AFTER total_marks,
    ADD COLUMN published_by BIGINT UNSIGNED NULL AFTER published_at,
    ADD KEY idx_school_assessments_published (school_id, published_at, class_id),
    ADD CONSTRAINT fk_school_assessment_publisher FOREIGN KEY (published_by) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS school_timetable_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    class_id BIGINT UNSIGNED NOT NULL,
    subject_id BIGINT UNSIGNED NULL,
    weekday TINYINT UNSIGNED NOT NULL,
    starts_at TIME NOT NULL,
    ends_at TIME NOT NULL,
    room VARCHAR(80) NULL,
    published_at DATETIME NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_school_timetable_student_view (school_id, class_id, published_at, weekday, starts_at),
    CONSTRAINT fk_school_timetable_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_timetable_class FOREIGN KEY (class_id) REFERENCES school_classes(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_timetable_subject FOREIGN KEY (subject_id) REFERENCES school_subjects(id) ON DELETE SET NULL,
    CONSTRAINT fk_school_timetable_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_school_timetable_weekday CHECK (weekday BETWEEN 1 AND 7),
    CONSTRAINT chk_school_timetable_time CHECK (ends_at > starts_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS school_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    class_id BIGINT UNSIGNED NOT NULL,
    subject_id BIGINT UNSIGNED NULL,
    title VARCHAR(180) NOT NULL,
    instructions TEXT NOT NULL,
    learning_material_url VARCHAR(1000) NULL,
    due_at DATETIME NULL,
    published_at DATETIME NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_school_assignment_student_view (school_id, class_id, published_at, due_at),
    CONSTRAINT fk_school_assignment_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_assignment_class FOREIGN KEY (class_id) REFERENCES school_classes(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_assignment_subject FOREIGN KEY (subject_id) REFERENCES school_subjects(id) ON DELETE SET NULL,
    CONSTRAINT fk_school_assignment_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
