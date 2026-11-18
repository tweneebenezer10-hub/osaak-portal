USE secure_auth_system;

CREATE TABLE IF NOT EXISTS member_role_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    requested_role ENUM('executive_member') NOT NULL DEFAULT 'executive_member',
    status ENUM('pending','approved','rejected','revoked') NOT NULL DEFAULT 'pending',
    request_reason VARCHAR(500) NULL,
    review_reason VARCHAR(500) NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_member_role_requests_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_member_role_requests_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_member_role_requests_status (status, created_at),
    INDEX idx_member_role_requests_user (user_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS association_news (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    headline VARCHAR(180) NOT NULL,
    body MEDIUMTEXT NOT NULL,
    photograph_path VARCHAR(100) NULL,
    related_event_id BIGINT UNSIGNED NULL,
    status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    publish_at DATETIME NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_association_news_event FOREIGN KEY (related_event_id) REFERENCES association_events(id) ON DELETE SET NULL,
    CONSTRAINT fk_association_news_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_association_news_editor FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_association_news_publication (status, publish_at, created_at)
) ENGINE=InnoDB;

INSERT IGNORE INTO permissions (name,description) VALUES
    ('news.view','View published association news'),
    ('news.manage','Create, edit, publish, and archive association news');

INSERT IGNORE INTO role_permissions (role_id,permission_name)
SELECT r.id,p.name FROM roles r CROSS JOIN permissions p
WHERE r.name='administrator';

INSERT IGNORE INTO role_permissions (role_id,permission_name)
SELECT r.id,p.name FROM roles r CROSS JOIN permissions p
WHERE r.name IN ('it_manager','association_super_admin')
  AND p.name IN ('roles.manage','leadership.manage','news.view','news.manage',
                 'announcements.view','announcements.manage','events.view','events.manage',
                 'committees.view','committees.manage','elections.view','elections.vote','elections.manage',
                 'chapters.view','chapters.manage','welfare.submit','welfare.manage',
                 'mentorship.view','mentorship.manage','jobs.view','jobs.apply','jobs.manage',
                 'finance.view','finance.manage','payments.manage','meetings.view','meetings.manage',
                 'meetings.attendance.manage','documents.view','documents.manage','documents.upload',
                 'association.settings.manage','reports.view');

INSERT IGNORE INTO role_permissions (role_id,permission_name)
SELECT r.id,p.name FROM roles r CROSS JOIN permissions p
WHERE r.name IN ('president','vice_president','secretary','assistant_secretary','executive_member')
  AND p.name IN ('news.view','news.manage','announcements.manage','events.manage',
                 'committees.manage','elections.manage','jobs.manage','payments.manage');

INSERT IGNORE INTO role_permissions (role_id,permission_name)
SELECT r.id,p.name FROM roles r CROSS JOIN permissions p
WHERE r.name IN ('public_relations_officer','organising_secretary')
  AND p.name IN ('news.view','news.manage');

INSERT IGNORE INTO role_permissions (role_id,permission_name)
SELECT r.id,p.name FROM roles r CROSS JOIN permissions p
WHERE r.name='ordinary_member'
  AND p.name='news.view';
