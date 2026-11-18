USE secure_auth_system;

ALTER TABLE member_profiles
    ADD COLUMN IF NOT EXISTS requested_role ENUM('ordinary_member','executive_member')
        NOT NULL DEFAULT 'ordinary_member' AFTER membership_category,
    ADD COLUMN IF NOT EXISTS profile_picture_path VARCHAR(100) NULL AFTER requested_role;

INSERT IGNORE INTO role_permissions (role_id, permission_name)
SELECT r.id, 'dashboard.manage'
FROM roles r
JOIN permissions p ON p.name='dashboard.manage'
WHERE r.name='executive_member';
