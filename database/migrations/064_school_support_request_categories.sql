USE secure_auth_system;

ALTER TABLE school_support_tickets
    ADD COLUMN category ENUM('academic_calendar','fees','access','technical','other')
        NOT NULL DEFAULT 'other' AFTER body;
