USE secure_auth_system;

CREATE TABLE IF NOT EXISTS association_chapters (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    region VARCHAR(120) NULL,
    location VARCHAR(200) NULL,
    coordinator_user_id BIGINT UNSIGNED NULL,
    status ENUM('active','archived') NOT NULL DEFAULT 'active',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_association_chapters_coordinator FOREIGN KEY (coordinator_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_association_chapters_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_association_chapters_status (status, region)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS association_committees (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    mandate VARCHAR(1000) NULL,
    chair_user_id BIGINT UNSIGNED NULL,
    status ENUM('active','archived') NOT NULL DEFAULT 'active',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_association_committees_chair FOREIGN KEY (chair_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_association_committees_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_association_committees_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS committee_members (
    committee_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    committee_role VARCHAR(80) NOT NULL DEFAULT 'Member',
    assigned_by BIGINT UNSIGNED NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (committee_id, user_id),
    CONSTRAINT fk_committee_members_committee FOREIGN KEY (committee_id) REFERENCES association_committees(id) ON DELETE CASCADE,
    CONSTRAINT fk_committee_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_committee_members_actor FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS association_elections (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(160) NOT NULL,
    position VARCHAR(120) NOT NULL,
    description VARCHAR(1000) NULL,
    nominations_open_at DATETIME NOT NULL,
    nominations_close_at DATETIME NOT NULL,
    voting_open_at DATETIME NOT NULL,
    voting_close_at DATETIME NOT NULL,
    status ENUM('draft','nominations_open','voting_open','closed','cancelled') NOT NULL DEFAULT 'draft',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_association_elections_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_association_elections_status_dates (status, voting_open_at, voting_close_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS election_candidates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    election_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    statement VARCHAR(2000) NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    nominated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_election_candidates_election FOREIGN KEY (election_id) REFERENCES association_elections(id) ON DELETE CASCADE,
    CONSTRAINT fk_election_candidates_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_election_candidates_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_election_candidate_user (election_id, user_id),
    UNIQUE KEY uq_election_candidate_pair (id, election_id),
    INDEX idx_election_candidates_status (election_id, status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS election_voters (
    election_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (election_id, user_id),
    CONSTRAINT fk_election_voters_election FOREIGN KEY (election_id) REFERENCES association_elections(id) ON DELETE CASCADE,
    CONSTRAINT fk_election_voters_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS election_votes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    election_id BIGINT UNSIGNED NOT NULL,
    candidate_id BIGINT UNSIGNED NOT NULL,
    ballot_hash CHAR(64) NOT NULL UNIQUE,
    cast_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_election_votes_election FOREIGN KEY (election_id) REFERENCES association_elections(id) ON DELETE CASCADE,
    CONSTRAINT fk_election_votes_candidate_election FOREIGN KEY (candidate_id, election_id) REFERENCES election_candidates(id, election_id) ON DELETE CASCADE,
    INDEX idx_election_votes_tally (election_id, candidate_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS welfare_cases (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    category VARCHAR(80) NOT NULL,
    subject VARCHAR(160) NOT NULL,
    details MEDIUMTEXT NOT NULL,
    status ENUM('submitted','in_review','resolved','referred','closed') NOT NULL DEFAULT 'submitted',
    staff_note MEDIUMTEXT NULL,
    assigned_to BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_welfare_cases_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_welfare_cases_assignee FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_welfare_cases_status (status, created_at),
    INDEX idx_welfare_cases_user (user_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS mentorship_profiles (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    offering_mentorship BOOLEAN NOT NULL DEFAULT FALSE,
    seeking_mentorship BOOLEAN NOT NULL DEFAULT FALSE,
    expertise VARCHAR(500) NULL,
    interests VARCHAR(500) NULL,
    availability VARCHAR(300) NULL,
    visible BOOLEAN NOT NULL DEFAULT TRUE,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_mentorship_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_mentorship_matching (visible, offering_mentorship, seeking_mentorship)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS association_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(160) NOT NULL,
    organisation VARCHAR(160) NOT NULL,
    location VARCHAR(160) NULL,
    description MEDIUMTEXT NOT NULL,
    application_url VARCHAR(1000) NULL,
    closes_at DATETIME NULL,
    status ENUM('draft','published','closed','archived') NOT NULL DEFAULT 'draft',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_association_jobs_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_association_jobs_status (status, closes_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS job_applications (
    job_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    cover_note VARCHAR(3000) NULL,
    status ENUM('submitted','reviewing','shortlisted','declined','withdrawn') NOT NULL DEFAULT 'submitted',
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    PRIMARY KEY (job_id, user_id),
    CONSTRAINT fk_job_applications_job FOREIGN KEY (job_id) REFERENCES association_jobs(id) ON DELETE CASCADE,
    CONSTRAINT fk_job_applications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_job_applications_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_job_applications_user (user_id, applied_at)
) ENGINE=InnoDB;

INSERT IGNORE INTO permissions (name, description) VALUES
    ('chapters.view', 'View association chapters'),
    ('committees.view', 'View association committees'),
    ('elections.view', 'View and participate in eligible association elections'),
    ('elections.vote', 'Cast one secret ballot in eligible association elections'),
    ('welfare.submit', 'Submit and view own welfare requests'),
    ('mentorship.view', 'View opted-in mentorship profiles'),
    ('mentorship.manage', 'Manage mentorship programme records'),
    ('jobs.view', 'View association job and opportunity listings'),
    ('jobs.apply', 'Apply for opportunities and view own application'),
    ('jobs.manage', 'Manage job listings and application review');

INSERT IGNORE INTO role_permissions (role_id, permission_name)
SELECT r.id, p.name FROM roles r CROSS JOIN permissions p
WHERE r.name IN ('administrator','it_manager','association_super_admin')
  AND p.name IN ('chapters.view','committees.view','elections.view','elections.vote','welfare.submit','mentorship.view','mentorship.manage','jobs.view','jobs.apply','jobs.manage');

INSERT IGNORE INTO role_permissions (role_id, permission_name)
SELECT r.id, p.name FROM roles r CROSS JOIN permissions p
WHERE r.name IN ('ordinary_member','general_user','student')
  AND p.name IN ('chapters.view','committees.view','elections.view','elections.vote','welfare.submit','mentorship.view','jobs.view','jobs.apply');

INSERT IGNORE INTO role_permissions (role_id, permission_name)
SELECT r.id, p.name FROM roles r CROSS JOIN permissions p
WHERE r.name IN ('president','vice_president','patron','secretary','assistant_secretary','executive_member','committee_chairperson','branch_coordinator')
  AND p.name IN ('chapters.view','committees.view','elections.view','mentorship.view','jobs.view');

INSERT IGNORE INTO role_permissions (role_id, permission_name)
SELECT r.id, p.name FROM roles r CROSS JOIN permissions p
WHERE r.name IN ('welfare_officer')
  AND p.name IN ('welfare.submit','welfare.manage');

INSERT IGNORE INTO role_permissions (role_id, permission_name)
SELECT r.id, p.name FROM roles r CROSS JOIN permissions p
WHERE r.name = 'association_super_admin'
  AND p.name IN ('chapters.manage','committees.manage','elections.manage','welfare.manage','leadership.manage','jobs.manage');
