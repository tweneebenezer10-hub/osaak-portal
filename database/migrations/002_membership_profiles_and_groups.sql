USE secure_auth_system;

CREATE TABLE IF NOT EXISTS member_profiles (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    phone VARCHAR(32) NULL,
    alternative_phone VARCHAR(32) NULL,
    institution VARCHAR(160) NULL,
    graduation_year SMALLINT UNSIGNED NULL,
    class_group VARCHAR(120) NULL,
    programme VARCHAR(160) NULL,
    occupation VARCHAR(160) NULL,
    employer VARCHAR(160) NULL,
    location VARCHAR(160) NULL,
    biography TEXT NULL,
    membership_number VARCHAR(32) NULL UNIQUE,
    membership_category VARCHAR(40) NOT NULL DEFAULT 'ordinary',
    membership_status ENUM('pending','active','suspended','rejected','resigned','deceased','archived') NOT NULL DEFAULT 'pending',
    directory_visible BOOLEAN NOT NULL DEFAULT FALSE,
    phone_visible BOOLEAN NOT NULL DEFAULT FALSE,
    joined_at DATETIME NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    review_note VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_member_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_member_profiles_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_member_profile_directory (membership_status, directory_visible),
    INDEX idx_member_profile_graduation (graduation_year, class_group),
    INDEX idx_member_profile_name_lookup (occupation, location)
) ENGINE=InnoDB;

INSERT IGNORE INTO member_profiles (user_id, membership_status, joined_at)
SELECT id,
       CASE
           WHEN status = 'disabled' THEN 'suspended'
           WHEN email_verified_at IS NULL THEN 'pending'
           ELSE 'active'
       END,
       CASE WHEN email_verified_at IS NOT NULL THEN created_at ELSE NULL END
FROM users;

CREATE TABLE IF NOT EXISTS graduation_year_groups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    graduation_year SMALLINT UNSIGNED NULL,
    class_group VARCHAR(120) NULL,
    description VARCHAR(500) NULL,
    status ENUM('active','archived') NOT NULL DEFAULT 'active',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_year_groups_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    UNIQUE KEY uq_year_group_name_year_class (name, graduation_year, class_group),
    INDEX idx_year_groups_status_year (status, graduation_year)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS member_year_group_assignments (
    group_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    assigned_by BIGINT UNSIGNED NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (group_id, user_id),
    CONSTRAINT fk_group_assignments_group FOREIGN KEY (group_id) REFERENCES graduation_year_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_group_assignments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_group_assignments_actor FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_group_assignments_user (user_id)
) ENGINE=InnoDB;

INSERT IGNORE INTO permissions (name, description) VALUES
    ('directory.view', 'View permitted member directory fields'),
    ('groups.view', 'View graduation-year and class groups'),
    ('groups.manage', 'Create and manage graduation-year and class groups');

INSERT IGNORE INTO role_permissions (role_id, permission_name)
SELECT r.id, p.name
FROM roles AS r
CROSS JOIN permissions AS p
WHERE r.name IN ('administrator', 'it_manager')
  AND p.name IN ('directory.view', 'groups.view', 'groups.manage');

INSERT IGNORE INTO role_permissions (role_id, permission_name)
SELECT r.id, p.name
FROM roles AS r
CROSS JOIN permissions AS p
WHERE r.name = 'association_super_admin'
  AND p.name IN ('directory.view', 'groups.view', 'groups.manage');

INSERT IGNORE INTO role_permissions (role_id, permission_name)
SELECT r.id, p.name
FROM roles AS r
CROSS JOIN permissions AS p
WHERE r.name IN ('secretary', 'assistant_secretary', 'executive_member')
  AND p.name IN ('members.view', 'directory.view', 'groups.view');

INSERT IGNORE INTO role_permissions (role_id, permission_name)
SELECT r.id, p.name
FROM roles AS r
CROSS JOIN permissions AS p
WHERE r.name IN ('class_representative', 'branch_coordinator')
  AND p.name IN ('directory.view', 'groups.view');

INSERT IGNORE INTO role_permissions (role_id, permission_name)
SELECT r.id, p.name
FROM roles AS r
CROSS JOIN permissions AS p
WHERE r.name IN ('general_user', 'student', 'ordinary_member')
  AND p.name IN ('directory.view', 'groups.view');
