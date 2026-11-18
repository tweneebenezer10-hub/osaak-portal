ALTER TABLE school_staff_attendance
    ADD UNIQUE KEY uk_staff_attendance_school_staff_id (id, school_id, staff_id);

ALTER TABLE school_staff_attendance_audit
    MODIFY change_type ENUM(
        'kiosk_check_in',
        'kiosk_check_out',
        'administrator_recorded',
        'corrected',
        'excuse_approved',
        'dispute_reviewed',
        'makeup_credit'
    ) NOT NULL;

CREATE TABLE school_staff_attendance_makeup_obligations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    staff_id BIGINT UNSIGNED NOT NULL,
    obligation_month DATE NOT NULL,
    required_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    elapsed_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    completed_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    excused_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    credited_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    pending_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    outstanding_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('open','complete') NOT NULL DEFAULT 'open',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_attendance_obligation_month (school_id, staff_id, obligation_month),
    UNIQUE KEY uk_staff_attendance_obligation_scope (id, school_id, staff_id),
    KEY idx_staff_attendance_obligation_open (school_id, staff_id, status, obligation_month),
    CONSTRAINT fk_staff_attendance_obligation_school
        FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_staff_attendance_obligation_staff
        FOREIGN KEY (staff_id) REFERENCES school_staff(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE school_staff_attendance_makeup_credits (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    staff_id BIGINT UNSIGNED NOT NULL,
    obligation_id BIGINT UNSIGNED NOT NULL,
    attendance_id BIGINT UNSIGNED NOT NULL,
    credited_by BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(255) NOT NULL,
    credited_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_staff_attendance_makeup_credit_attendance (school_id, attendance_id),
    KEY idx_staff_attendance_makeup_credit_obligation (school_id, obligation_id, credited_at),
    CONSTRAINT fk_staff_attendance_makeup_credit_school
        FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_staff_attendance_makeup_credit_obligation
        FOREIGN KEY (obligation_id, school_id, staff_id)
        REFERENCES school_staff_attendance_makeup_obligations(id, school_id, staff_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_staff_attendance_makeup_credit_attendance
        FOREIGN KEY (attendance_id, school_id, staff_id)
        REFERENCES school_staff_attendance(id, school_id, staff_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_staff_attendance_makeup_credit_actor
        FOREIGN KEY (credited_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

ALTER TABLE school_payroll_entries
    ADD COLUMN attendance_required_days SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER net_salary,
    ADD COLUMN attendance_elapsed_days SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER attendance_required_days,
    ADD COLUMN attendance_completed_days SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER attendance_elapsed_days,
    ADD COLUMN attendance_excused_days SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER attendance_completed_days,
    ADD COLUMN attendance_makeup_days SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER attendance_excused_days,
    ADD COLUMN attendance_pending_days SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER attendance_makeup_days,
    ADD COLUMN attendance_outstanding_days SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER attendance_pending_days,
    ADD COLUMN attendance_eligible TINYINT(1) NOT NULL DEFAULT 1 AFTER attendance_outstanding_days,
    ADD COLUMN attendance_reviewed_at DATETIME NULL AFTER attendance_eligible;
