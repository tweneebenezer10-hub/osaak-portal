CREATE TABLE IF NOT EXISTS school_proprietor_invitations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    email VARCHAR(254) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    redeemed_by_user_id BIGINT UNSIGNED NULL,
    redeemed_request_id BIGINT UNSIGNED NULL,
    redeemed_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_school_proprietor_invitation_token (token_hash),
    KEY idx_school_proprietor_invitation_active (school_id, revoked_at, redeemed_at, expires_at),
    CONSTRAINT fk_school_proprietor_invitation_school
        FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT,
    CONSTRAINT fk_school_proprietor_invitation_creator
        FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_school_proprietor_invitation_redeemer
        FOREIGN KEY (redeemed_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_school_proprietor_invitation_request
        FOREIGN KEY (redeemed_request_id) REFERENCES school_role_requests(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
