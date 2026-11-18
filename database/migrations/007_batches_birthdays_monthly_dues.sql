USE secure_auth_system;

ALTER TABLE member_profiles
    ADD COLUMN IF NOT EXISTS date_of_birth DATE NULL AFTER phone;

ALTER TABLE membership_dues
    ADD COLUMN IF NOT EXISTS billing_period DATE NULL AFTER target_year,
    ADD COLUMN IF NOT EXISTS due_type ENUM('manual','monthly') NOT NULL DEFAULT 'manual' AFTER billing_period,
    ADD UNIQUE INDEX IF NOT EXISTS uq_membership_dues_monthly_period (due_type,billing_period);

CREATE TABLE IF NOT EXISTS membership_due_notifications (
    due_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    status ENUM('pending','sending','sent','failed') NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_attempt_at DATETIME NULL,
    sent_at DATETIME NULL,
    PRIMARY KEY (due_id,user_id),
    CONSTRAINT fk_due_notifications_due FOREIGN KEY (due_id) REFERENCES membership_dues(id) ON DELETE CASCADE,
    CONSTRAINT fk_due_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_due_notifications_retry (status,last_attempt_at)
) ENGINE=InnoDB;

INSERT IGNORE INTO role_permissions (role_id,permission_name)
SELECT r.id,'dashboard.manage'
FROM roles r
JOIN permissions p ON p.name='dashboard.manage'
WHERE r.name = 'executive_member';
