USE secure_auth_system;

ALTER TABLE association_jobs
    ADD COLUMN IF NOT EXISTS qualifications MEDIUMTEXT NULL AFTER description,
    ADD COLUMN IF NOT EXISTS employment_type VARCHAR(40) NULL AFTER location,
    ADD COLUMN IF NOT EXISTS salary_information VARCHAR(200) NULL AFTER employment_type,
    ADD COLUMN IF NOT EXISTS application_instructions MEDIUMTEXT NULL AFTER application_url,
    ADD COLUMN IF NOT EXISTS application_email VARCHAR(254) NULL AFTER application_instructions;
