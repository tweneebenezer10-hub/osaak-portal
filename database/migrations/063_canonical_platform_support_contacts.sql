USE secure_auth_system;

INSERT INTO platform_settings (setting_key, setting_value, updated_by)
VALUES
    ('super_admin_phone_1', '0533785133', NULL),
    ('super_admin_phone_2', '', NULL),
    ('super_admin_whatsapp', '0533785133', NULL),
    ('super_admin_email', 'connezeredutech@gmail.com', NULL)
ON DUPLICATE KEY UPDATE
    setting_value = VALUES(setting_value),
    updated_by = NULL,
    updated_at = UTC_TIMESTAMP();
