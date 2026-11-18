CREATE TABLE IF NOT EXISTS school_staff_concerns (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    staff_id BIGINT UNSIGNED NOT NULL,
    submitted_by BIGINT UNSIGNED NULL,
    subject VARCHAR(160) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('pending', 'in_review', 'resolved') NOT NULL DEFAULT 'pending',
    response TEXT NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_school_staff_concerns_status (school_id, status, created_at),
    KEY idx_school_staff_concerns_staff (school_id, staff_id, created_at),
    CONSTRAINT fk_school_staff_concern_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_staff_concern_staff FOREIGN KEY (staff_id) REFERENCES school_staff(id) ON DELETE RESTRICT,
    CONSTRAINT fk_school_staff_concern_submitter FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_school_staff_concern_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS school_staff_promotions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    staff_id BIGINT UNSIGNED NOT NULL,
    previous_job_title VARCHAR(120) NOT NULL,
    new_job_title VARCHAR(120) NOT NULL,
    effective_on DATE NOT NULL,
    reason TEXT NOT NULL,
    recorded_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_school_staff_promotions_history (school_id, staff_id, effective_on),
    CONSTRAINT fk_school_staff_promotion_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_staff_promotion_staff FOREIGN KEY (staff_id) REFERENCES school_staff(id) ON DELETE RESTRICT,
    CONSTRAINT fk_school_staff_promotion_recorder FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS school_staff_documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    staff_id BIGINT UNSIGNED NOT NULL,
    document_type VARCHAR(80) NOT NULL,
    title VARCHAR(160) NOT NULL,
    stored_name VARCHAR(80) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    uploaded_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_school_staff_document_stored_name (stored_name),
    KEY idx_school_staff_documents_history (school_id, staff_id, created_at),
    CONSTRAINT fk_school_staff_document_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_staff_document_staff FOREIGN KEY (staff_id) REFERENCES school_staff(id) ON DELETE RESTRICT,
    CONSTRAINT fk_school_staff_document_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
