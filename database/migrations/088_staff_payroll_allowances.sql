ALTER TABLE school_staff_salary_rates
    ADD COLUMN monthly_allowance DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER monthly_salary;

ALTER TABLE school_payroll_entries
    ADD COLUMN base_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER salary_rate_id,
    ADD COLUMN allowance_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER base_salary;

UPDATE school_payroll_entries
SET base_salary = gross_salary
WHERE base_salary = 0.00 AND gross_salary > 0.00;
