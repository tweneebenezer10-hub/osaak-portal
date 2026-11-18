USE secure_auth_system;

ALTER TABLE school_report_card_batches
    MODIFY COLUMN status ENUM(
        'draft',
        'ready',
        'submitted',
        'under_review',
        'reviewed',
        'correction_requested',
        'published'
    ) NOT NULL DEFAULT 'draft';
