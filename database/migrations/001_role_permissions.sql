USE secure_auth_system;

CREATE TABLE IF NOT EXISTS permissions (
    name VARCHAR(80) NOT NULL PRIMARY KEY,
    description VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS role_permissions (
    role_id TINYINT UNSIGNED NOT NULL,
    permission_name VARCHAR(80) NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (role_id, permission_name),
    CONSTRAINT fk_role_permissions_role
        FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_role_permissions_permission
        FOREIGN KEY (permission_name) REFERENCES permissions(name) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT IGNORE INTO roles (name) VALUES
    ('it_manager'),
    ('association_super_admin'),
    ('president'),
    ('vice_president'),
    ('patron'),
    ('secretary'),
    ('assistant_secretary'),
    ('treasurer'),
    ('assistant_treasurer'),
    ('organising_secretary'),
    ('public_relations_officer'),
    ('executive_member'),
    ('electoral_officer'),
    ('auditor'),
    ('welfare_officer'),
    ('committee_chairperson'),
    ('class_representative'),
    ('branch_coordinator'),
    ('ordinary_member');

INSERT IGNORE INTO permissions (name, description) VALUES
    ('dashboard.view', 'View the authenticated user dashboard'),
    ('dashboard.manage', 'View association-wide administration dashboard'),
    ('profile.manage_self', 'View and update permitted fields on own profile'),
    ('activity.view_self', 'View own account security activity'),
    ('security.factors.manage_self', 'Manage own sign-in factors and recovery settings'),
    ('dues.view_self', 'View own membership dues and payment history'),
    ('members.view', 'View permitted member account records'),
    ('members.manage', 'Manage membership accounts and lifecycle status'),
    ('security_logs.view', 'View authentication and system audit logs'),
    ('security.manage', 'Manage technical security settings and diagnostics'),
    ('system.settings.manage', 'Manage technical system configuration'),
    ('association.settings.manage', 'Manage association configuration'),
    ('roles.manage', 'Manage system roles and technical permissions'),
    ('leadership.manage', 'Manage association leadership assignments'),
    ('events.view', 'View permitted association events'),
    ('events.manage', 'Create and manage association events'),
    ('finance.view', 'View permitted association financial records'),
    ('finance.manage', 'Manage association dues and financial records'),
    ('meetings.view', 'View permitted meeting and governance records'),
    ('meetings.manage', 'Manage meeting agendas, minutes, and actions'),
    ('announcements.view', 'View permitted association announcements'),
    ('announcements.manage', 'Create and manage association announcements'),
    ('documents.view', 'View permitted association documents'),
    ('documents.manage', 'Manage association documents'),
    ('reports.view', 'View permitted association reports'),
    ('elections.manage', 'Manage election administration'),
    ('welfare.manage', 'Manage confidential welfare cases'),
    ('chapters.manage', 'Manage assigned association chapters and year groups'),
    ('committees.manage', 'Manage assigned association committees and tasks'),
    ('community.moderate', 'Moderate member community content');

INSERT IGNORE INTO role_permissions (role_id, permission_name)
SELECT r.id, p.name
FROM roles AS r
CROSS JOIN permissions AS p
WHERE r.name = 'administrator';

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

INSERT IGNORE INTO role_permissions (role_id, permission_name)
SELECT r.id, p.name
FROM roles AS r
CROSS JOIN permissions AS p
WHERE r.name = 'association_super_admin'
  AND p.name NOT IN ('security.manage', 'system.settings.manage', 'roles.manage');

INSERT IGNORE INTO role_permissions (role_id, permission_name)
SELECT r.id, p.name
FROM roles AS r
CROSS JOIN permissions AS p
WHERE r.name IN ('general_user', 'student', 'ordinary_member')
  AND p.name IN (
      'dashboard.view',
      'profile.manage_self',
      'activity.view_self',
      'security.factors.manage_self',
      'dues.view_self',
      'events.view',
      'announcements.view',
      'documents.view'
  );

INSERT IGNORE INTO role_permissions (role_id, permission_name)
SELECT r.id, p.name
FROM roles AS r
CROSS JOIN permissions AS p
WHERE r.name IN ('president', 'vice_president')
  AND p.name IN (
      'dashboard.view',
      'dashboard.manage',
      'profile.manage_self',
      'activity.view_self',
      'security.factors.manage_self',
      'dues.view_self',
      'members.view',
      'events.view',
      'meetings.view',
      'announcements.view',
      'documents.view',
      'reports.view',
      'leadership.manage',
      'committees.manage'
  );

INSERT IGNORE INTO role_permissions (role_id, permission_name)
SELECT r.id, p.name
FROM roles AS r
CROSS JOIN permissions AS p
WHERE r.name IN ('secretary', 'assistant_secretary')
  AND p.name IN (
      'dashboard.view',
      'dashboard.manage',
      'profile.manage_self',
      'activity.view_self',
      'security.factors.manage_self',
      'dues.view_self',
      'members.view',
      'members.manage',
      'events.view',
      'meetings.view',
      'meetings.manage',
      'announcements.view',
      'announcements.manage',
      'documents.view',
      'documents.manage',
      'reports.view'
  );

INSERT IGNORE INTO role_permissions (role_id, permission_name)
SELECT r.id, p.name
FROM roles AS r
CROSS JOIN permissions AS p
WHERE r.name IN ('treasurer', 'assistant_treasurer', 'auditor')
  AND p.name IN (
      'dashboard.view',
      'profile.manage_self',
      'activity.view_self',
      'security.factors.manage_self',
      'dues.view_self',
      'finance.view',
      'reports.view'
  );

INSERT IGNORE INTO role_permissions (role_id, permission_name)
SELECT r.id, p.name
FROM roles AS r
CROSS JOIN permissions AS p
WHERE r.name IN ('treasurer', 'assistant_treasurer')
  AND p.name = 'finance.manage';

INSERT IGNORE INTO role_permissions (role_id, permission_name)
SELECT r.id, p.name
FROM roles AS r
CROSS JOIN permissions AS p
WHERE r.name IN ('organising_secretary', 'executive_member')
  AND p.name IN (
      'dashboard.view',
      'profile.manage_self',
      'activity.view_self',
      'security.factors.manage_self',
      'dues.view_self',
      'members.view',
      'events.view',
      'events.manage',
      'meetings.view',
      'announcements.view',
      'documents.view',
      'reports.view'
  );

INSERT IGNORE INTO role_permissions (role_id, permission_name)
SELECT r.id, p.name
FROM roles AS r
CROSS JOIN permissions AS p
WHERE r.name = 'public_relations_officer'
  AND p.name IN (
      'dashboard.view',
      'profile.manage_self',
      'activity.view_self',
      'security.factors.manage_self',
      'dues.view_self',
      'announcements.view',
      'announcements.manage',
      'documents.view',
      'documents.manage'
  );

INSERT IGNORE INTO role_permissions (role_id, permission_name)
SELECT r.id, p.name
FROM roles AS r
CROSS JOIN permissions AS p
WHERE r.name = 'electoral_officer'
  AND p.name IN (
      'dashboard.view',
      'profile.manage_self',
      'activity.view_self',
      'security.factors.manage_self',
      'dues.view_self',
      'members.view',
      'elections.manage',
      'meetings.view',
      'documents.view'
  );

INSERT IGNORE INTO role_permissions (role_id, permission_name)
SELECT r.id, p.name
FROM roles AS r
CROSS JOIN permissions AS p
WHERE r.name = 'welfare_officer'
  AND p.name IN (
      'dashboard.view',
      'profile.manage_self',
      'activity.view_self',
      'security.factors.manage_self',
      'dues.view_self',
      'welfare.manage'
  );

INSERT IGNORE INTO role_permissions (role_id, permission_name)
SELECT r.id, p.name
FROM roles AS r
CROSS JOIN permissions AS p
WHERE r.name IN ('committee_chairperson', 'class_representative', 'branch_coordinator')
  AND p.name IN (
      'dashboard.view',
      'profile.manage_self',
      'activity.view_self',
      'security.factors.manage_self',
      'dues.view_self',
      'members.view',
      'events.view',
      'meetings.view',
      'announcements.view',
      'documents.view',
      'chapters.manage',
      'committees.manage'
  );

INSERT IGNORE INTO role_permissions (role_id, permission_name)
SELECT r.id, p.name
FROM roles AS r
CROSS JOIN permissions AS p
WHERE r.name = 'patron'
  AND p.name IN (
      'dashboard.view',
      'profile.manage_self',
      'activity.view_self',
      'security.factors.manage_self',
      'dues.view_self',
      'events.view',
      'meetings.view',
      'announcements.view',
      'documents.view',
      'reports.view'
  );
