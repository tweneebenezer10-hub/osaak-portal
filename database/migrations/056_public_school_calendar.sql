ALTER TABLE school_calendar_events
    ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 0 AFTER audience,
    ADD KEY idx_school_calendar_public (school_id, is_public, status, starts_on, ends_on);
