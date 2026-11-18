ALTER TABLE school_staff_loans
    MODIFY COLUMN status ENUM(
        'pending',
        'returned',
        'under_review',
        'approved_awaiting_payment',
        'active',
        'rejected',
        'paid',
        'cancelled'
    ) NOT NULL DEFAULT 'pending',
    ADD COLUMN approved_amount DECIMAL(12,2) NULL AFTER principal_amount,
    ADD COLUMN approved_monthly_deduction DECIMAL(12,2) NULL AFTER monthly_deduction,
    ADD COLUMN approved_repayment_months SMALLINT UNSIGNED NULL AFTER repayment_months,
    ADD COLUMN repayment_start_month DATE NULL AFTER approved_repayment_months,
    ADD COLUMN terms_acknowledged_at DATETIME NULL AFTER repayment_start_month;

ALTER TABLE school_staff_loans
    DROP INDEX uk_school_staff_open_loan,
    DROP COLUMN open_staff_id,
    ADD COLUMN open_staff_id BIGINT UNSIGNED
        GENERATED ALWAYS AS (
            CASE WHEN status IN ('pending','returned','under_review','approved_awaiting_payment','active')
                 THEN staff_id ELSE NULL END
        ) STORED,
    ADD UNIQUE KEY uk_school_staff_open_loan (school_id, open_staff_id);

UPDATE school_staff_loans
SET approved_amount = principal_amount,
    approved_monthly_deduction = monthly_deduction,
    approved_repayment_months = repayment_months
WHERE status IN ('active', 'paid');

CREATE TABLE IF NOT EXISTS school_staff_loan_decisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    staff_id BIGINT UNSIGNED NOT NULL,
    loan_id BIGINT UNSIGNED NOT NULL,
    decision ENUM('approved','rejected','returned') NOT NULL,
    requested_amount DECIMAL(12,2) NOT NULL,
    approved_amount DECIMAL(12,2) NULL,
    monthly_deduction DECIMAL(12,2) NULL,
    repayment_months SMALLINT UNSIGNED NULL,
    repayment_start_month DATE NULL,
    reason VARCHAR(1000) NULL,
    approver_role ENUM('school_headmaster','school_proprietor') NOT NULL,
    decided_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_school_staff_loan_decisions (school_id, loan_id, created_at),
    CONSTRAINT fk_school_staff_loan_decision_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_staff_loan_decision_staff FOREIGN KEY (staff_id) REFERENCES school_staff(id) ON DELETE RESTRICT,
    CONSTRAINT fk_school_staff_loan_decision_loan FOREIGN KEY (loan_id) REFERENCES school_staff_loans(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_staff_loan_decision_user FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS school_staff_loan_disbursements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    staff_id BIGINT UNSIGNED NOT NULL,
    loan_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_date DATE NOT NULL,
    payment_method ENUM('cash','bank_transfer','mobile_money','other') NOT NULL,
    payment_reference VARCHAR(100) NULL,
    notes VARCHAR(500) NULL,
    recorded_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_school_staff_loan_disbursement (loan_id),
    KEY idx_school_staff_loan_disbursements (school_id, staff_id, payment_date),
    CONSTRAINT fk_school_staff_loan_disbursement_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_staff_loan_disbursement_staff FOREIGN KEY (staff_id) REFERENCES school_staff(id) ON DELETE RESTRICT,
    CONSTRAINT fk_school_staff_loan_disbursement_loan FOREIGN KEY (loan_id) REFERENCES school_staff_loans(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_staff_loan_disbursement_user FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
