ALTER TABLE student_attendance
    ADD COLUMN row_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER status,
    ADD COLUMN last_sync_token CHAR(32) NULL AFTER row_version;
