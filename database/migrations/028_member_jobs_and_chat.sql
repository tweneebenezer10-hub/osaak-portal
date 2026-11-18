USE secure_auth_system;

ALTER TABLE association_jobs
    ADD COLUMN IF NOT EXISTS submission_kind ENUM('association','member') NOT NULL DEFAULT 'association' AFTER created_by,
    ADD COLUMN IF NOT EXISTS review_status ENUM('not_required','pending','approved','rejected','withdrawn','removed') NOT NULL DEFAULT 'not_required' AFTER submission_kind,
    ADD COLUMN IF NOT EXISTS review_note VARCHAR(1000) NULL AFTER review_status,
    ADD COLUMN IF NOT EXISTS reviewed_by BIGINT UNSIGNED NULL AFTER review_note,
    ADD COLUMN IF NOT EXISTS reviewed_at DATETIME NULL AFTER reviewed_by,
    ADD COLUMN IF NOT EXISTS industry VARCHAR(120) NULL AFTER employment_type,
    ADD COLUMN IF NOT EXISTS attachment_path VARCHAR(100) NULL AFTER industry;

SET @add_job_reviewer_fk = IF(
    EXISTS (
        SELECT 1 FROM information_schema.referential_constraints
        WHERE constraint_schema=DATABASE()
          AND table_name='association_jobs'
          AND constraint_name='fk_association_jobs_reviewer'
    ),
    'SELECT 1',
    'ALTER TABLE association_jobs ADD CONSTRAINT fk_association_jobs_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL'
);
PREPARE add_job_reviewer_fk FROM @add_job_reviewer_fk;
EXECUTE add_job_reviewer_fk;
DEALLOCATE PREPARE add_job_reviewer_fk;

UPDATE association_jobs
SET review_status = CASE WHEN status = 'published' THEN 'approved' ELSE 'not_required' END
WHERE submission_kind = 'association' AND review_status = 'not_required';

CREATE TABLE IF NOT EXISTS job_opportunity_reads (
    user_id BIGINT UNSIGNED NOT NULL,
    job_id BIGINT UNSIGNED NOT NULL,
    read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, job_id),
    CONSTRAINT fk_job_opportunity_read_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_job_opportunity_read_job FOREIGN KEY (job_id) REFERENCES association_jobs(id) ON DELETE CASCADE,
    INDEX idx_job_opportunity_reads_job (job_id, user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS member_conversations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_a BIGINT UNSIGNED NOT NULL,
    member_b BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_member_conversation_order CHECK (member_a < member_b),
    CONSTRAINT fk_member_conversation_a FOREIGN KEY (member_a) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_member_conversation_b FOREIGN KEY (member_b) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_member_conversation_pair (member_a, member_b),
    INDEX idx_member_conversation_a (member_a, id),
    INDEX idx_member_conversation_b (member_b, id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS member_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id BIGINT UNSIGNED NOT NULL,
    sender_id BIGINT UNSIGNED NOT NULL,
    body VARCHAR(4000) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL,
    CONSTRAINT fk_member_message_conversation FOREIGN KEY (conversation_id) REFERENCES member_conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_member_message_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_member_message_conversation (conversation_id, id),
    INDEX idx_member_message_sender_time (sender_id, created_at),
    INDEX idx_member_message_unread (conversation_id, read_at, sender_id)
) ENGINE=InnoDB;

INSERT IGNORE INTO permissions (name, description) VALUES
    ('jobs.submit', 'Submit and manage own member-shared job opportunities'),
    ('jobs.review', 'Review member-submitted job opportunities'),
    ('messages.use', 'Search members and use private member messaging');

INSERT IGNORE INTO role_permissions (role_id, permission_name)
SELECT r.id, p.name
FROM roles AS r
CROSS JOIN permissions AS p
WHERE r.name IN (
    'ordinary_member','general_user','student','executive_member','it_manager'
)
AND p.name IN ('jobs.view','jobs.submit','messages.use');

INSERT IGNORE INTO role_permissions (role_id, permission_name)
SELECT r.id, 'jobs.view'
FROM roles AS r
WHERE r.name IN (
    'president','vice_president','patron','secretary','assistant_secretary',
    'treasurer','assistant_treasurer','organising_secretary','public_relations_officer',
    'electoral_officer','auditor','welfare_officer','committee_chairperson',
    'class_representative','branch_coordinator','association_super_admin'
);

INSERT IGNORE INTO role_permissions (role_id, permission_name)
SELECT r.id, 'jobs.submit'
FROM roles AS r
WHERE r.name IN (
    'president','vice_president','patron','secretary','assistant_secretary',
    'treasurer','assistant_treasurer','organising_secretary','public_relations_officer',
    'electoral_officer','auditor','welfare_officer','committee_chairperson',
    'class_representative','branch_coordinator','association_super_admin'
);

INSERT IGNORE INTO role_permissions (role_id, permission_name)
SELECT r.id, 'messages.use'
FROM roles AS r
WHERE r.name IN (
    'president','vice_president','patron','secretary','assistant_secretary',
    'treasurer','assistant_treasurer','organising_secretary','public_relations_officer',
    'electoral_officer','auditor','welfare_officer','committee_chairperson',
    'class_representative','branch_coordinator','association_super_admin'
);

INSERT IGNORE INTO role_permissions (role_id, permission_name)
SELECT r.id, 'jobs.review'
FROM roles AS r
WHERE r.name IN (
    'administrator','it_manager','association_super_admin','president','vice_president',
    'secretary','assistant_secretary','executive_member'
);
