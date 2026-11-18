USE secure_auth_system;

ALTER TABLE school_report_card_batches
    MODIFY COLUMN status ENUM('draft','ready','submitted','reviewed','correction_requested','published')
        NOT NULL DEFAULT 'draft',
    ADD COLUMN submitted_by BIGINT UNSIGNED NULL AFTER published_by,
    ADD COLUMN submitted_at DATETIME NULL AFTER submitted_by,
    ADD COLUMN reviewed_by BIGINT UNSIGNED NULL AFTER submitted_at,
    ADD COLUMN reviewed_at DATETIME NULL AFTER reviewed_by,
    ADD COLUMN correction_requested_by BIGINT UNSIGNED NULL AFTER reviewed_at,
    ADD COLUMN correction_requested_at DATETIME NULL AFTER correction_requested_by,
    ADD COLUMN correction_reason VARCHAR(500) NULL AFTER correction_requested_at,
    ADD KEY idx_school_report_card_review_queue (school_id, status, submitted_at),
    ADD CONSTRAINT fk_school_report_card_submitter_072
        FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_school_report_card_reviewer_072
        FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_school_report_card_correction_requester_072
        FOREIGN KEY (correction_requested_by) REFERENCES users(id) ON DELETE SET NULL;
