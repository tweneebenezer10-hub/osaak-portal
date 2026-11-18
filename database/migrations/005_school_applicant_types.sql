USE secure_auth_system;

ALTER TABLE member_profiles
    ADD COLUMN IF NOT EXISTS applicant_type ENUM('alumni','current_student','teacher')
    NOT NULL DEFAULT 'alumni' AFTER institution;
