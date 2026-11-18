CREATE TABLE IF NOT EXISTS school_staff_administrative_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    staff_id BIGINT UNSIGNED NULL,
    staff_name VARCHAR(120) NOT NULL,
    job_title VARCHAR(120) NOT NULL,
    responsibilities TEXT NOT NULL,
    status ENUM('active', 'removed') NOT NULL DEFAULT 'active',
    active_staff_id BIGINT UNSIGNED
        GENERATED ALWAYS AS (CASE WHEN status = 'active' THEN staff_id ELSE NULL END) STORED,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    removed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_school_admin_assignment_active_staff (school_id, active_staff_id),
    KEY idx_school_admin_assignment_history (school_id, staff_id, status, created_at),
    CONSTRAINT fk_school_admin_assignment_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_admin_assignment_staff FOREIGN KEY (staff_id) REFERENCES school_staff(id) ON DELETE SET NULL,
    CONSTRAINT fk_school_admin_assignment_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_school_admin_assignment_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
