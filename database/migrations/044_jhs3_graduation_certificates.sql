ALTER TABLE schools
    ADD COLUMN require_proprietor_certificate_signature TINYINT(1) NOT NULL DEFAULT 0
        AFTER proprietor_name;

CREATE TABLE IF NOT EXISTS student_graduations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    academic_year VARCHAR(30) NOT NULL,
    graduation_date DATE NOT NULL,
    final_results_reviewed TINYINT(1) NOT NULL DEFAULT 0,
    clearance_reviewed TINYINT(1) NOT NULL DEFAULT 0,
    clearance_notes VARCHAR(1000) NOT NULL,
    approved_by BIGINT UNSIGNED NOT NULL,
    approved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status ENUM('approved', 'reversed') NOT NULL DEFAULT 'approved',
    reversed_by BIGINT UNSIGNED NULL,
    reversed_at DATETIME NULL,
    reversal_reason VARCHAR(1000) NULL,
    PRIMARY KEY (id),
    KEY idx_student_graduation_student (school_id, student_id, status),
    KEY idx_student_graduation_year (school_id, academic_year, status),
    CONSTRAINT fk_student_graduation_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT,
    CONSTRAINT fk_student_graduation_student FOREIGN KEY (student_id) REFERENCES school_students(id) ON DELETE RESTRICT,
    CONSTRAINT fk_student_graduation_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_student_graduation_reverser FOREIGN KEY (reversed_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS school_completion_certificates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    graduation_id BIGINT UNSIGNED NOT NULL,
    status ENUM('issued', 'replaced', 'revoked') NOT NULL DEFAULT 'issued',
    replaces_certificate_id BIGINT UNSIGNED NULL,
    student_name VARCHAR(180) NOT NULL,
    student_identifier VARCHAR(50) NOT NULL,
    academic_year VARCHAR(30) NOT NULL,
    completion_date DATE NOT NULL,
    date_issued DATE NOT NULL,
    school_name VARCHAR(180) NOT NULL,
    school_address VARCHAR(500) NULL,
    school_contact VARCHAR(300) NULL,
    school_logo_path VARCHAR(100) NULL,
    headmaster_name VARCHAR(180) NOT NULL,
    proprietor_name VARCHAR(180) NULL,
    issued_by BIGINT UNSIGNED NOT NULL,
    issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status_changed_by BIGINT UNSIGNED NULL,
    status_changed_at DATETIME NULL,
    status_reason VARCHAR(1000) NULL,
    PRIMARY KEY (id),
    KEY idx_completion_certificate_student (school_id, student_id, status),
    KEY idx_completion_certificate_graduation (graduation_id, issued_at),
    CONSTRAINT fk_completion_certificate_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT,
    CONSTRAINT fk_completion_certificate_student FOREIGN KEY (student_id) REFERENCES school_students(id) ON DELETE RESTRICT,
    CONSTRAINT fk_completion_certificate_graduation FOREIGN KEY (graduation_id) REFERENCES student_graduations(id) ON DELETE RESTRICT,
    CONSTRAINT fk_completion_certificate_replacement FOREIGN KEY (replaces_certificate_id) REFERENCES school_completion_certificates(id) ON DELETE RESTRICT,
    CONSTRAINT fk_completion_certificate_issuer FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_completion_certificate_status_actor FOREIGN KEY (status_changed_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS student_graduation_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    graduation_id BIGINT UNSIGNED NULL,
    certificate_id BIGINT UNSIGNED NULL,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    event_type ENUM(
        'graduation_approved',
        'graduation_reversed',
        'certificate_issued',
        'certificate_reprinted',
        'certificate_replaced',
        'certificate_revoked'
    ) NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_student_graduation_events_history (school_id, student_id, occurred_at),
    CONSTRAINT fk_student_graduation_event_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT,
    CONSTRAINT fk_student_graduation_event_student FOREIGN KEY (student_id) REFERENCES school_students(id) ON DELETE RESTRICT,
    CONSTRAINT fk_student_graduation_event_graduation FOREIGN KEY (graduation_id) REFERENCES student_graduations(id) ON DELETE SET NULL,
    CONSTRAINT fk_student_graduation_event_certificate FOREIGN KEY (certificate_id) REFERENCES school_completion_certificates(id) ON DELETE SET NULL,
    CONSTRAINT fk_student_graduation_event_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
