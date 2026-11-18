ALTER TABLE school_staff
    ADD COLUMN position_title VARCHAR(120) NULL AFTER role_name,
    ADD COLUMN employment_status ENUM(
        'active',
        'suspended',
        'termination_scheduled',
        'terminated',
        'resigned',
        'retired',
        'transferred',
        'inactive'
    ) NOT NULL DEFAULT 'active' AFTER status,
    ADD COLUMN employment_effective_on DATE NULL AFTER employment_status,
    ADD KEY idx_school_staff_employment (school_id, employment_status, employment_effective_on);

UPDATE school_staff
SET employment_status = CASE
    WHEN status = 'suspended' THEN 'suspended'
    WHEN status = 'inactive' THEN 'inactive'
    ELSE 'active'
END;

CREATE TABLE school_staff_employment_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    staff_id BIGINT UNSIGNED NOT NULL,
    staff_name_snapshot VARCHAR(180) NOT NULL,
    employee_number_snapshot VARCHAR(50) NULL,
    position_title_snapshot VARCHAR(120) NULL,
    previous_position_title VARCHAR(120) NULL,
    new_position_title VARCHAR(120) NULL,
    event_type ENUM(
        'suspended',
        'termination_scheduled',
        'terminated',
        'resigned',
        'retired',
        'transferred',
        'reinstated',
        'position_changed',
        'schedule_cancelled'
    ) NOT NULL,
    previous_status VARCHAR(30) NOT NULL,
    new_status VARCHAR(30) NOT NULL,
    effective_on DATE NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    remarks VARCHAR(2000) NULL,
    prior_roles_json TEXT NULL,
    replacement_plan_json TEXT NULL,
    event_state ENUM('scheduled','applied','cancelled') NOT NULL DEFAULT 'applied',
    authorized_by BIGINT UNSIGNED NOT NULL,
    authorized_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_school_staff_employment_events_history (school_id, staff_id, effective_on, id),
    KEY idx_school_staff_employment_events_due (school_id, event_state, effective_on),
    CONSTRAINT fk_school_staff_employment_event_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT,
    CONSTRAINT fk_school_staff_employment_event_staff FOREIGN KEY (staff_id) REFERENCES school_staff(id) ON DELETE RESTRICT,
    CONSTRAINT fk_school_staff_employment_event_actor FOREIGN KEY (authorized_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE school_staff_responsibility_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    employment_event_id BIGINT UNSIGNED NOT NULL,
    former_staff_id BIGINT UNSIGNED NOT NULL,
    replacement_staff_id BIGINT UNSIGNED NULL,
    responsibility_type ENUM(
        'class_teacher',
        'subject_teacher',
        'administrative',
        'timetable'
    ) NOT NULL,
    responsibility_key VARCHAR(160) NOT NULL,
    responsibility_json LONGTEXT NOT NULL,
    effective_on DATE NOT NULL,
    attention_required TINYINT(1) NOT NULL DEFAULT 0,
    recorded_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_school_staff_responsibility_history (school_id, former_staff_id, effective_on),
    KEY idx_school_staff_responsibility_attention (school_id, attention_required, effective_on),
    CONSTRAINT fk_school_staff_responsibility_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT,
    CONSTRAINT fk_school_staff_responsibility_event FOREIGN KEY (employment_event_id) REFERENCES school_staff_employment_events(id) ON DELETE RESTRICT,
    CONSTRAINT fk_school_staff_responsibility_former FOREIGN KEY (former_staff_id) REFERENCES school_staff(id) ON DELETE RESTRICT,
    CONSTRAINT fk_school_staff_responsibility_replacement FOREIGN KEY (replacement_staff_id) REFERENCES school_staff(id) ON DELETE SET NULL,
    CONSTRAINT fk_school_staff_responsibility_actor FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
