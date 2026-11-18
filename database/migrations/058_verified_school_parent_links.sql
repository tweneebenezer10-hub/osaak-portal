USE secure_auth_system;

ALTER TABLE school_students
    ADD UNIQUE KEY uk_school_student_scope_058 (school_id, id);

ALTER TABLE school_guardians
    ADD UNIQUE KEY uk_school_guardian_scope_058 (school_id, id);

CREATE TABLE IF NOT EXISTS school_parent_links (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    guardian_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    status ENUM('pending', 'verified', 'revoked') NOT NULL DEFAULT 'pending',
    created_by BIGINT UNSIGNED NULL,
    verified_by BIGINT UNSIGNED NULL,
    verified_at DATETIME NULL,
    revoked_by BIGINT UNSIGNED NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_school_parent_link_scope_id_058 (school_id, id),
    UNIQUE KEY uk_school_parent_link_student_user_058 (school_id, student_id, user_id),
    KEY idx_school_parent_link_user_status_058 (school_id, user_id, status, student_id),
    KEY idx_school_parent_link_student_status_058 (school_id, student_id, status),
    CONSTRAINT fk_school_parent_link_school_058
        FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT,
    CONSTRAINT fk_school_parent_link_student_scope_058
        FOREIGN KEY (school_id, student_id) REFERENCES school_students(school_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_school_parent_link_guardian_scope_058
        FOREIGN KEY (school_id, guardian_id) REFERENCES school_guardians(school_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_school_parent_link_user_058
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_school_parent_link_creator_058
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_school_parent_link_verifier_058
        FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_school_parent_link_revoker_058
        FOREIGN KEY (revoked_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS school_parent_link_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    link_id BIGINT UNSIGNED NOT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    action ENUM('created', 'verified', 'revoked', 'reopened') NOT NULL,
    previous_status ENUM('pending', 'verified', 'revoked') NULL,
    new_status ENUM('pending', 'verified', 'revoked') NOT NULL,
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_school_parent_link_audit_history_058 (school_id, link_id, created_at),
    CONSTRAINT fk_school_parent_link_audit_link_scope_058
        FOREIGN KEY (school_id, link_id)
        REFERENCES school_parent_links(school_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_school_parent_link_audit_actor_058
        FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
