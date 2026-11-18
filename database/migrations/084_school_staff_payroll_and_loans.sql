CREATE TABLE IF NOT EXISTS school_staff_salary_rates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    staff_id BIGINT UNSIGNED NOT NULL,
    monthly_salary DECIMAL(12,2) NOT NULL,
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    status ENUM('active','replaced') NOT NULL DEFAULT 'active',
    active_staff_id BIGINT UNSIGNED
        GENERATED ALWAYS AS (CASE WHEN status = 'active' THEN staff_id ELSE NULL END) STORED,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_school_salary_active_staff (school_id, active_staff_id),
    KEY idx_school_salary_history (school_id, staff_id, effective_from),
    CONSTRAINT fk_school_salary_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_salary_staff FOREIGN KEY (staff_id) REFERENCES school_staff(id) ON DELETE RESTRICT,
    CONSTRAINT fk_school_salary_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS school_staff_loans (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    staff_id BIGINT UNSIGNED NOT NULL,
    principal_amount DECIMAL(12,2) NOT NULL,
    outstanding_balance DECIMAL(12,2) NOT NULL,
    monthly_deduction DECIMAL(12,2) NOT NULL,
    status ENUM('pending','active','rejected','paid') NOT NULL DEFAULT 'pending',
    requested_by BIGINT UNSIGNED NULL,
    decided_by BIGINT UNSIGNED NULL,
    decided_at DATETIME NULL,
    paid_off_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    open_staff_id BIGINT UNSIGNED
        GENERATED ALWAYS AS (CASE WHEN status IN ('pending','active') THEN staff_id ELSE NULL END) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uk_school_staff_open_loan (school_id, open_staff_id),
    KEY idx_school_staff_loans_status (school_id, status, staff_id),
    CONSTRAINT fk_school_staff_loan_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_staff_loan_staff FOREIGN KEY (staff_id) REFERENCES school_staff(id) ON DELETE RESTRICT,
    CONSTRAINT fk_school_staff_loan_requester FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_school_staff_loan_decider FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS school_payroll_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    period_month DATE NOT NULL,
    status ENUM('draft','approved','paid') NOT NULL DEFAULT 'draft',
    created_by BIGINT UNSIGNED NULL,
    approved_by BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    paid_by BIGINT UNSIGNED NULL,
    paid_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_school_payroll_month (school_id, period_month),
    KEY idx_school_payroll_status (school_id, status, period_month),
    CONSTRAINT fk_school_payroll_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_payroll_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_school_payroll_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_school_payroll_payer FOREIGN KEY (paid_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS school_payroll_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    payroll_run_id BIGINT UNSIGNED NOT NULL,
    staff_id BIGINT UNSIGNED NOT NULL,
    salary_rate_id BIGINT UNSIGNED NULL,
    loan_id BIGINT UNSIGNED NULL,
    gross_salary DECIMAL(12,2) NOT NULL,
    loan_deduction DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    net_salary DECIMAL(12,2) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_school_payroll_entry_staff (payroll_run_id, staff_id),
    KEY idx_school_payroll_entry_school_staff (school_id, staff_id, payroll_run_id),
    CONSTRAINT fk_school_payroll_entry_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_payroll_entry_run FOREIGN KEY (payroll_run_id) REFERENCES school_payroll_runs(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_payroll_entry_staff FOREIGN KEY (staff_id) REFERENCES school_staff(id) ON DELETE RESTRICT,
    CONSTRAINT fk_school_payroll_entry_salary FOREIGN KEY (salary_rate_id) REFERENCES school_staff_salary_rates(id) ON DELETE SET NULL,
    CONSTRAINT fk_school_payroll_entry_loan FOREIGN KEY (loan_id) REFERENCES school_staff_loans(id) ON DELETE SET NULL
) ENGINE=InnoDB;
