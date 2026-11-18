ALTER TABLE school_assessments
    MODIFY COLUMN workflow_status
        ENUM('draft','submitted','correction_requested','corrected','reviewed','published')
        NOT NULL DEFAULT 'draft';
