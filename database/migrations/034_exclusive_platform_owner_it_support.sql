DELETE ur
FROM user_roles ur
JOIN roles r ON r.id = ur.role_id AND r.name = 'platform_super_admin'
LEFT JOIN users u
    ON u.id = ur.user_id
   AND u.status = 'active'
   AND u.email_verified_at IS NOT NULL
   AND LOWER(TRIM(u.email)) = 'tweneebenezer897@gmail.com'
WHERE u.id IS NULL;

CREATE TABLE IF NOT EXISTS it_support_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_user_id BIGINT UNSIGNED NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    preview_role VARCHAR(50) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    session_hash CHAR(64) NOT NULL,
    client_ip VARCHAR(45) NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at DATETIME NULL,
    end_reason VARCHAR(32) NULL,
    PRIMARY KEY (id),
    KEY idx_it_support_actor (admin_user_id, started_at),
    KEY idx_it_support_school (school_id, started_at),
    KEY idx_it_support_session_active (session_hash, ended_at),
    CONSTRAINT fk_it_support_actor FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_it_support_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
