ALTER TABLE school_payroll_runs
    ADD COLUMN payment_method ENUM('cash','bank_transfer','mobile_money','other') NULL AFTER paid_at,
    ADD COLUMN payment_reference VARCHAR(100) NULL AFTER payment_method,
    ADD COLUMN payment_note VARCHAR(500) NULL AFTER payment_reference;

CREATE TABLE IF NOT EXISTS school_staff_loan_repayments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    staff_id BIGINT UNSIGNED NOT NULL,
    loan_id BIGINT UNSIGNED NOT NULL,
    payroll_entry_id BIGINT UNSIGNED NULL,
    amount DECIMAL(12,2) NOT NULL,
    source ENUM('payroll','manual') NOT NULL,
    payment_method ENUM('payroll_deduction','cash','bank_transfer','mobile_money','other') NOT NULL,
    payment_reference VARCHAR(100) NULL,
    notes VARCHAR(500) NULL,
    recorded_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_school_loan_repayment_payroll_entry (payroll_entry_id),
    KEY idx_school_loan_repayments_history (school_id, loan_id, created_at),
    CONSTRAINT fk_school_loan_repayment_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_loan_repayment_staff FOREIGN KEY (staff_id) REFERENCES school_staff(id) ON DELETE RESTRICT,
    CONSTRAINT fk_school_loan_repayment_loan FOREIGN KEY (loan_id) REFERENCES school_staff_loans(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_loan_repayment_payroll_entry FOREIGN KEY (payroll_entry_id) REFERENCES school_payroll_entries(id) ON DELETE SET NULL,
    CONSTRAINT fk_school_loan_repayment_recorder FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO school_staff_loan_repayments
    (school_id, staff_id, loan_id, payroll_entry_id, amount, source,
     payment_method, recorded_by, created_at)
SELECT entry.school_id, entry.staff_id, entry.loan_id, entry.id, entry.loan_deduction,
       'payroll', 'payroll_deduction', run.paid_by, COALESCE(run.paid_at, entry.created_at)
FROM school_payroll_entries entry
JOIN school_payroll_runs run
  ON run.id = entry.payroll_run_id AND run.school_id = entry.school_id
JOIN school_staff_loans loan
  ON loan.id = entry.loan_id AND loan.school_id = entry.school_id
WHERE run.status = 'paid'
  AND entry.loan_id IS NOT NULL
  AND entry.loan_deduction > 0;
