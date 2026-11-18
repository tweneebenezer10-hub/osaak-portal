USE secure_auth_system;

SET @notification_error_column_exists = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'membership_due_notifications'
      AND column_name = 'last_error'
);
SET @notification_error_column_sql = IF(
    @notification_error_column_exists = 0,
    'ALTER TABLE membership_due_notifications ADD COLUMN last_error VARCHAR(500) NULL AFTER sent_at',
    'SELECT 1'
);
PREPARE notification_error_column_statement FROM @notification_error_column_sql;
EXECUTE notification_error_column_statement;
DEALLOCATE PREPARE notification_error_column_statement;

CREATE TABLE IF NOT EXISTS dues_reminder_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    billing_period DATE NOT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL,
    eligible_count INT UNSIGNED NOT NULL DEFAULT 0,
    sent_count INT UNSIGNED NOT NULL DEFAULT 0,
    failed_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_message VARCHAR(500) NULL,
    INDEX idx_dues_reminder_runs_period (billing_period, started_at)
) ENGINE=InnoDB;

UPDATE association_settings
SET setting_value = '1'
WHERE setting_key = 'monthly_dues_email_day'
  AND CAST(setting_value AS UNSIGNED) > 7;
