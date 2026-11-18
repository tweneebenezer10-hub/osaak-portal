USE secure_auth_system;

DELETE rp
FROM role_permissions AS rp
JOIN roles AS r ON r.id = rp.role_id
WHERE r.name = 'ordinary_member'
  AND rp.permission_name = 'dashboard.manage';

DELETE rp
FROM role_permissions AS rp
JOIN roles AS r ON r.id = rp.role_id
WHERE r.name = 'it_manager'
  AND rp.permission_name NOT IN (
      'dashboard.view',
      'dashboard.manage',
      'profile.manage_self',
      'activity.view_self',
      'security.factors.manage_self',
      'members.view',
      'members.manage',
      'security_logs.view',
      'security.manage',
      'system.settings.manage'
  );

INSERT IGNORE INTO role_permissions (role_id, permission_name)
SELECT r.id, p.name
FROM roles AS r
CROSS JOIN permissions AS p
WHERE r.name = 'it_manager'
  AND p.name IN (
      'dashboard.view',
      'dashboard.manage',
      'profile.manage_self',
      'activity.view_self',
      'security.factors.manage_self',
      'members.view',
      'members.manage',
      'security_logs.view',
      'security.manage',
      'system.settings.manage'
  );
