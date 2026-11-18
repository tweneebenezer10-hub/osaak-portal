DELETE FROM school_notifications
WHERE notification_key LIKE 'staff-loan-%';

DELETE FROM security_logs
WHERE LEFT(event_type, 18) = 'school_staff_loan_';

DELETE FROM school_settings
WHERE setting_key = 'staff_loan_approval_policy';

ALTER TABLE school_payroll_entries
    DROP FOREIGN KEY fk_school_payroll_entry_loan,
    DROP COLUMN loan_id,
    DROP COLUMN loan_deduction;

DROP TABLE IF EXISTS school_staff_loan_schedule_changes;
DROP TABLE IF EXISTS school_staff_loan_repayments;
DROP TABLE IF EXISTS school_staff_loan_disbursements;
DROP TABLE IF EXISTS school_staff_loan_decisions;
DROP TABLE IF EXISTS school_staff_loans;
