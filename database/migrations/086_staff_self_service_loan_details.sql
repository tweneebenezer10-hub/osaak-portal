ALTER TABLE school_staff_loans
    ADD COLUMN request_reason TEXT NULL AFTER monthly_deduction,
    ADD COLUMN repayment_months SMALLINT UNSIGNED NULL AFTER request_reason,
    ADD COLUMN decision_notes VARCHAR(1000) NULL AFTER decided_at;
