USE secure_auth_system;

CREATE TABLE IF NOT EXISTS payment_adjustments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_id BIGINT UNSIGNED NOT NULL,
    adjustment_type ENUM('refund') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    recorded_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_payment_adjustment_amount CHECK (amount > 0),
    CONSTRAINT fk_payment_adjustment_payment FOREIGN KEY (payment_id) REFERENCES payment_records(id) ON DELETE RESTRICT,
    CONSTRAINT fk_payment_adjustment_actor FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE RESTRICT,
    UNIQUE KEY uq_payment_adjustment_once (payment_id, adjustment_type),
    INDEX idx_payment_adjustments_created (created_at)
) ENGINE=InnoDB;
