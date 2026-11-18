USE secure_auth_system;

ALTER TABLE membership_dues
    ADD COLUMN IF NOT EXISTS target_category VARCHAR(40) NULL AFTER target_year;

CREATE TABLE IF NOT EXISTS membership_due_adjustments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    due_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    adjustment_type ENUM('credit','exemption') NOT NULL,
    amount DECIMAL(12,2) NULL,
    reason VARCHAR(500) NOT NULL,
    recorded_by BIGINT UNSIGNED NOT NULL,
    exemption_marker TINYINT
        GENERATED ALWAYS AS (CASE WHEN adjustment_type='exemption' THEN 1 ELSE NULL END) STORED,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_due_adjustments_due FOREIGN KEY (due_id) REFERENCES membership_dues(id) ON DELETE RESTRICT,
    CONSTRAINT fk_due_adjustments_member FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_due_adjustments_actor FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_due_adjustment_amount CHECK (
        (adjustment_type='credit' AND amount IS NOT NULL AND amount>0)
        OR (adjustment_type='exemption' AND amount IS NULL)
    ),
    UNIQUE KEY uq_due_member_exemption (due_id,user_id,exemption_marker),
    INDEX idx_due_member_adjustment (due_id,user_id,adjustment_type)
) ENGINE=InnoDB;
