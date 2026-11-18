ALTER TABLE school_invoices
    ADD UNIQUE KEY uk_school_invoice_scope_student (school_id, id, student_id);

ALTER TABLE school_payments
    ADD UNIQUE KEY uk_school_payment_scope_student (school_id, id, student_id);

CREATE TABLE IF NOT EXISTS school_payment_allocations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    payment_id BIGINT UNSIGNED NOT NULL,
    invoice_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    allocation_type ENUM('legacy','manual','automatic_credit') NOT NULL,
    status ENUM('pending','active','void') NOT NULL DEFAULT 'pending',
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_school_payment_invoice_allocation (payment_id, invoice_id, allocation_type),
    KEY idx_school_payment_allocation_invoice (school_id, invoice_id, status),
    KEY idx_school_payment_allocation_student (school_id, student_id, status),
    CONSTRAINT fk_school_payment_allocation_payment
        FOREIGN KEY (school_id, payment_id, student_id)
        REFERENCES school_payments (school_id, id, student_id) ON DELETE RESTRICT,
    CONSTRAINT fk_school_payment_allocation_invoice
        FOREIGN KEY (school_id, invoice_id, student_id)
        REFERENCES school_invoices (school_id, id, student_id) ON DELETE RESTRICT,
    CONSTRAINT fk_school_payment_allocation_actor
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS school_fee_credit_sources (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    payment_id BIGINT UNSIGNED NOT NULL,
    surplus_amount DECIMAL(12,2) NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_school_fee_credit_payment (payment_id),
    KEY idx_school_fee_credit_student (school_id, student_id),
    CONSTRAINT fk_school_fee_credit_payment
        FOREIGN KEY (school_id, payment_id, student_id)
        REFERENCES school_payments (school_id, id, student_id) ON DELETE RESTRICT,
    CONSTRAINT fk_school_fee_credit_actor
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO school_payment_allocations
    (school_id, student_id, payment_id, invoice_id, amount, allocation_type, status, created_by, created_at)
SELECT p.school_id, p.student_id, p.id, p.invoice_id, p.amount, 'legacy',
       CASE p.status WHEN 'verified' THEN 'active' WHEN 'pending' THEN 'pending' ELSE 'void' END,
       COALESCE(p.verified_by, p.received_by), p.received_at
FROM school_payments p;
