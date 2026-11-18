USE secure_auth_system;

CREATE TABLE IF NOT EXISTS platform_settings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    setting_key VARCHAR(80) NOT NULL,
    setting_value TEXT NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_platform_settings_key (setting_key),
    KEY idx_platform_settings_key (setting_key),
    CONSTRAINT fk_platform_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO platform_settings (setting_key, setting_value, updated_by)
VALUES
    ('super_admin_phone_1', '0533785133', NULL),
    ('super_admin_phone_2', '0205919781', NULL),
    ('super_admin_whatsapp', '0533785133', NULL),
    ('super_admin_email', 'tweneebenezer897@gmail.com', NULL);