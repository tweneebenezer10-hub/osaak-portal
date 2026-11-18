CREATE TABLE IF NOT EXISTS schools (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(180) NOT NULL,
    short_name VARCHAR(40) NOT NULL,
    school_type ENUM('basic_school', 'kindergarten', 'primary', 'jhs', 'multi_level') NOT NULL DEFAULT 'basic_school',
    logo_path VARCHAR(255) NULL,
    address VARCHAR(255) NULL,
    region VARCHAR(80) NULL,
    district VARCHAR(80) NULL,
    contact_email VARCHAR(254) NULL,
    contact_phone VARCHAR(32) NULL,
    proprietor_name VARCHAR(120) NULL,
    status ENUM('pending', 'active', 'suspended', 'expired') NOT NULL DEFAULT 'pending',
    subscription_status ENUM('trial', 'active', 'expired', 'suspended', 'cancelled') NOT NULL DEFAULT 'trial',
    annual_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    subscription_expires_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_schools_short_name (short_name),
    KEY idx_schools_status (status),
    KEY idx_schools_region (region, district)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS school_memberships (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    role_name VARCHAR(50) NOT NULL,
    status ENUM('active','invited','pending','disabled') NOT NULL DEFAULT 'active',
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_by BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_school_membership_user_school_role (school_id, user_id, role_name),
    KEY idx_school_membership_school_user (school_id, user_id),
    KEY idx_school_membership_user_school (user_id, school_id),
    CONSTRAINT fk_school_membership_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_membership_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_membership_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS school_settings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    setting_key VARCHAR(80) NOT NULL,
    setting_value TEXT NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_school_settings_key (school_id, setting_key),
    KEY idx_school_settings_school_key (school_id, setting_key),
    CONSTRAINT fk_school_settings_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS school_classes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    level VARCHAR(40) NOT NULL,
    section VARCHAR(40) NULL,
    class_teacher_id BIGINT UNSIGNED NULL,
    academic_year VARCHAR(30) NOT NULL,
    status ENUM('active','archived') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_school_class_name (school_id, name, academic_year),
    KEY idx_school_classes_school (school_id, status),
    CONSTRAINT fk_school_class_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_class_teacher FOREIGN KEY (class_teacher_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS school_students (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    class_id BIGINT UNSIGNED NULL,
    full_name VARCHAR(120) NOT NULL,
    admission_number VARCHAR(50) NULL,
    date_of_birth DATE NULL,
    gender ENUM('male','female','other','prefer_not_to_say') NULL,
    guardian_id BIGINT UNSIGNED NULL,
    school_status ENUM('active','graduated','transferred','suspended') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_school_student_admission (school_id, admission_number),
    KEY idx_school_students_school (school_id, school_status),
    CONSTRAINT fk_school_student_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_student_class FOREIGN KEY (class_id) REFERENCES school_classes(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS school_guardians (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(254) NULL,
    phone VARCHAR(32) NULL,
    relationship VARCHAR(60) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_school_guardians_school (school_id),
    CONSTRAINT fk_school_guardian_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS school_staff (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    employee_number VARCHAR(50) NULL,
    role_name VARCHAR(50) NOT NULL,
    status ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_school_staff_user_school (school_id, user_id),
    KEY idx_school_staff_role (school_id, role_name),
    CONSTRAINT fk_school_staff_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_staff_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS school_terms (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    academic_year VARCHAR(30) NOT NULL,
    term_name VARCHAR(50) NOT NULL,
    starts_on DATE NOT NULL,
    ends_on DATE NOT NULL,
    is_current BOOLEAN NOT NULL DEFAULT FALSE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_school_term_year_name (school_id, academic_year, term_name),
    KEY idx_school_terms_school_current (school_id, is_current),
    CONSTRAINT fk_school_term_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT IGNORE INTO roles (name) VALUES
    ('platform_super_admin'),
    ('school_proprietor'),
    ('school_headmaster'),
    ('class_teacher'),
    ('subject_teacher'),
    ('school_bursar'),
    ('school_accountant'),
    ('school_parent');
