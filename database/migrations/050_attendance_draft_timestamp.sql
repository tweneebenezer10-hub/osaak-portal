ALTER TABLE school_class_attendance_submissions
    ADD COLUMN draft_saved_at DATETIME NULL AFTER submitted_at;