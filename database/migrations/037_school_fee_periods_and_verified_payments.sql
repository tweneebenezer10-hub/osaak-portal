ALTER TABLE school_fee_structures
    ADD COLUMN class_level VARCHAR(40) NULL AFTER school_id,
    ADD COLUMN academic_year VARCHAR(30) NULL AFTER class_level,
    ADD COLUMN period_name VARCHAR(50) NULL AFTER academic_year,
    ADD COLUMN fee_category ENUM('tuition','books','uniform','examination','other') NOT NULL DEFAULT 'other' AFTER amount,
    ADD COLUMN effective_from DATE NULL AFTER description,
    ADD COLUMN is_active BOOLEAN NOT NULL DEFAULT TRUE AFTER effective_from,
    ADD KEY idx_school_fee_period (school_id, class_level, academic_year, period_name, is_active);

ALTER TABLE school_invoices
    ADD COLUMN class_level VARCHAR(40) NULL AFTER fee_structure_id,
    ADD COLUMN academic_year VARCHAR(30) NULL AFTER class_level,
    ADD COLUMN period_name VARCHAR(50) NULL AFTER academic_year,
    ADD COLUMN fee_snapshot JSON NULL AFTER period_name,
    ADD COLUMN adjustments_total DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER balance,
    ADD UNIQUE KEY uk_school_invoice_student_period (school_id, student_id, academic_year, period_name);

ALTER TABLE school_payments
    ADD COLUMN status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'verified' AFTER payment_reference,
    ADD COLUMN verified_by BIGINT UNSIGNED NULL AFTER received_by,
    ADD COLUMN verified_at DATETIME NULL AFTER verified_by,
    ADD KEY idx_school_payments_status (school_id, invoice_id, status),
    ADD CONSTRAINT fk_school_payment_verifier FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS school_fee_adjustments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    fee_structure_id BIGINT UNSIGNED NOT NULL,
    previous_amount DECIMAL(12,2) NOT NULL,
    new_amount DECIMAL(12,2) NOT NULL,
    effective_from DATE NOT NULL,
    reason VARCHAR(500) NOT NULL,
    changed_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_school_fee_adjustment_date (fee_structure_id, effective_from),
    KEY idx_school_fee_adjustment_school (school_id, effective_from),
    CONSTRAINT fk_school_fee_adjustment_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_fee_adjustment_structure FOREIGN KEY (fee_structure_id) REFERENCES school_fee_structures(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_fee_adjustment_actor FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS school_invoice_adjustments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    invoice_id BIGINT UNSIGNED NOT NULL,
    adjustment_type ENUM('discount','scholarship','waiver','payment_arrangement') NOT NULL,
    amount DECIMAL(12,2) NULL,
    effective_on DATE NOT NULL,
    valid_until DATE NULL,
    reason VARCHAR(500) NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    requested_by BIGINT UNSIGNED NOT NULL,
    approved_by BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_school_invoice_adjustment_invoice (school_id, invoice_id, status),
    CONSTRAINT fk_school_invoice_adjustment_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_invoice_adjustment_invoice FOREIGN KEY (invoice_id) REFERENCES school_invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_invoice_adjustment_requester FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_school_invoice_adjustment_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
