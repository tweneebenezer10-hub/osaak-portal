CREATE DATABASE IF NOT EXISTS secure_auth_system_school_dev
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE secure_auth_system_school_dev;

CREATE TABLE roles (
    id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(32) NOT NULL UNIQUE
) ENGINE=InnoDB;

INSERT IGNORE INTO roles (name) VALUES ('administrator'), ('student'), ('general_user');

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    username VARCHAR(40) NOT NULL UNIQUE,
    email VARCHAR(254) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    email_verified_at DATETIME NULL,
    status ENUM('active', 'disabled') NOT NULL DEFAULT 'active',
    email_otp_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_status (status)
) ENGINE=InnoDB;

CREATE TABLE user_roles (
    user_id BIGINT UNSIGNED NOT NULL,
    role_id TINYINT UNSIGNED NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, role_id),
    CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE email_verification_tokens (
    user_id BIGINT UNSIGNED PRIMARY KEY,
    code_hash CHAR(64) NULL,
    expires_at DATETIME NULL,
    sent_at DATETIME NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    used_at DATETIME NULL,
    CONSTRAINT fk_email_verification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE login_otp_tokens (
    user_id BIGINT UNSIGNED PRIMARY KEY,
    code_hash CHAR(64) NULL,
    expires_at DATETIME NOT NULL,
    sent_at DATETIME NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_login_otp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE totp_factors (
    user_id BIGINT UNSIGNED PRIMARY KEY,
    secret_ciphertext VARBINARY(512) NOT NULL,
    enabled BOOLEAN NOT NULL DEFAULT FALSE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    enabled_at DATETIME NULL,
    CONSTRAINT fk_totp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE recovery_codes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_recovery_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_recovery_user_unused (user_id, used_at)
) ENGINE=InnoDB;

CREATE TABLE password_reset_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_password_reset_user (user_id, used_at)
) ENGINE=InnoDB;

CREATE TABLE password_reset_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_hash CHAR(64) NOT NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reset_requests_ip_time (ip_hash, requested_at)
) ENGINE=InnoDB;

CREATE TABLE remember_tokens (
    selector CHAR(24) PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    validator_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_remember_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_remember_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identity_hash CHAR(64) NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    succeeded BOOLEAN NOT NULL DEFAULT FALSE,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_identity_time (identity_hash, attempted_at),
    INDEX idx_login_ip_time (ip_hash, attempted_at)
) ENGINE=InnoDB;

CREATE TABLE two_factor_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_two_factor_attempt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_two_factor_attempt_user_time (user_id, attempted_at),
    INDEX idx_two_factor_attempt_ip_time (ip_hash, attempted_at)
) ENGINE=InnoDB;

CREATE TABLE security_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    event_type VARCHAR(64) NOT NULL,
    ip_address VARCHAR(45) NULL,
    details VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_security_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_security_logs_time (created_at),
    INDEX idx_security_logs_user_time (user_id, created_at)
) ENGINE=InnoDB;
