USE secure_auth_system;

DELETE FROM role_permissions
WHERE permission_name IN ('groups.view', 'groups.manage');

DELETE FROM permissions
WHERE name IN ('groups.view', 'groups.manage');
