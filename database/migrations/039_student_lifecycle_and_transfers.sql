ALTER TABLE school_students
    MODIFY COLUMN school_status ENUM(
        'active',
        'graduated',
        'transferred',
        'withdrawn',
        'dropped_out',
        'expelled',
        'deceased',
        'suspended'
    ) NOT NULL DEFAULT 'active';

CREATE TABLE IF NOT EXISTS student_lifecycle_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    event_type ENUM('exit', 'reinstated', 'transfer_received') NOT NULL,
    previous_status VARCHAR(30) NOT NULL,
    new_status VARCHAR(30) NOT NULL,
    previous_class_id BIGINT UNSIGNED NULL,
    new_class_id BIGINT UNSIGNED NULL,
    exit_date DATE NULL,
    reason VARCHAR(1000) NULL,
    destination_school_id BIGINT UNSIGNED NULL,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_student_lifecycle_school_student (school_id, student_id, created_at),
    KEY idx_student_lifecycle_destination (destination_school_id, event_type),
    CONSTRAINT fk_student_lifecycle_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT,
    CONSTRAINT fk_student_lifecycle_student FOREIGN KEY (student_id) REFERENCES school_students(id) ON DELETE RESTRICT,
    CONSTRAINT fk_student_lifecycle_previous_class FOREIGN KEY (previous_class_id) REFERENCES school_classes(id) ON DELETE SET NULL,
    CONSTRAINT fk_student_lifecycle_new_class FOREIGN KEY (new_class_id) REFERENCES school_classes(id) ON DELETE SET NULL,
    CONSTRAINT fk_student_lifecycle_destination FOREIGN KEY (destination_school_id) REFERENCES schools(id) ON DELETE RESTRICT,
    CONSTRAINT fk_student_lifecycle_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS student_lifecycle_documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    lifecycle_event_id BIGINT UNSIGNED NOT NULL,
    uploaded_by BIGINT UNSIGNED NOT NULL,
    stored_name VARCHAR(80) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_student_lifecycle_document_stored_name (stored_name),
    KEY idx_student_lifecycle_documents_school_student (school_id, student_id, lifecycle_event_id),
    CONSTRAINT fk_student_lifecycle_document_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT,
    CONSTRAINT fk_student_lifecycle_document_student FOREIGN KEY (student_id) REFERENCES school_students(id) ON DELETE RESTRICT,
    CONSTRAINT fk_student_lifecycle_document_event FOREIGN KEY (lifecycle_event_id) REFERENCES student_lifecycle_events(id) ON DELETE RESTRICT,
    CONSTRAINT fk_student_lifecycle_document_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS student_record_transfer_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_school_id BIGINT UNSIGNED NOT NULL,
    target_school_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    lifecycle_event_id BIGINT UNSIGNED NOT NULL,
    consent_confirmed_by BIGINT UNSIGNED NOT NULL,
    consent_confirmed_at DATETIME NOT NULL,
    target_reviewed_by BIGINT UNSIGNED NULL,
    target_reviewed_at DATETIME NULL,
    source_released_by BIGINT UNSIGNED NULL,
    source_released_at DATETIME NULL,
    received_student_id BIGINT UNSIGNED NULL,
    received_at DATETIME NULL,
    package_json LONGTEXT NULL,
    status ENUM('pending_target_approval', 'target_approved', 'released', 'declined') NOT NULL DEFAULT 'pending_target_approval',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_student_transfer_lifecycle_event (lifecycle_event_id),
    KEY idx_student_transfer_source_status (source_school_id, status, created_at),
    KEY idx_student_transfer_target_status (target_school_id, status, created_at),
    CONSTRAINT fk_student_transfer_source_school FOREIGN KEY (source_school_id) REFERENCES schools(id) ON DELETE RESTRICT,
    CONSTRAINT fk_student_transfer_target_school FOREIGN KEY (target_school_id) REFERENCES schools(id) ON DELETE RESTRICT,
    CONSTRAINT fk_student_transfer_student FOREIGN KEY (student_id) REFERENCES school_students(id) ON DELETE RESTRICT,
    CONSTRAINT fk_student_transfer_event FOREIGN KEY (lifecycle_event_id) REFERENCES student_lifecycle_events(id) ON DELETE RESTRICT,
    CONSTRAINT fk_student_transfer_consent_user FOREIGN KEY (consent_confirmed_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_student_transfer_reviewer FOREIGN KEY (target_reviewed_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_student_transfer_releaser FOREIGN KEY (source_released_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_student_transfer_received_student FOREIGN KEY (received_student_id) REFERENCES school_students(id) ON DELETE RESTRICT,
    CONSTRAINT chk_student_transfer_different_schools CHECK (source_school_id <> target_school_id)
) ENGINE=InnoDB;

CREATE TRIGGER trg_school_students_prevent_permanent_delete
BEFORE DELETE ON school_students
FOR EACH ROW
SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Student records cannot be permanently deleted; use the audited lifecycle process.';
