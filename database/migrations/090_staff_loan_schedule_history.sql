CREATE TABLE IF NOT EXISTS school_staff_loan_schedule_changes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    staff_id BIGINT UNSIGNED NOT NULL,
    loan_id BIGINT UNSIGNED NOT NULL,
    previous_monthly_deduction DECIMAL(12,2) NOT NULL,
    new_monthly_deduction DECIMAL(12,2) NOT NULL,
    previous_repayment_months SMALLINT UNSIGNED NULL,
    new_repayment_months SMALLINT UNSIGNED NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    recorded_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_school_loan_schedule_history (school_id, loan_id, created_at),
    CONSTRAINT fk_school_loan_schedule_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_loan_schedule_staff FOREIGN KEY (staff_id) REFERENCES school_staff(id) ON DELETE RESTRICT,
    CONSTRAINT fk_school_loan_schedule_loan FOREIGN KEY (loan_id) REFERENCES school_staff_loans(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_loan_schedule_recorder FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
