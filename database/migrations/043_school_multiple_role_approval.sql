CREATE TABLE IF NOT EXISTS school_role_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    role_name VARCHAR(50) NOT NULL,
    request_reason VARCHAR(500) NOT NULL,
    supporting_information TEXT NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewer_user_id BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    decision_reason VARCHAR(500) NULL,
    PRIMARY KEY (id),
    KEY idx_school_role_requests_pending (school_id, status, requested_at),
    KEY idx_school_role_requests_user (user_id, school_id, requested_at),
    CONSTRAINT fk_school_role_request_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT,
    CONSTRAINT fk_school_role_request_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_school_role_request_reviewer FOREIGN KEY (reviewer_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS school_role_request_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id BIGINT UNSIGNED NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    subject_user_id BIGINT UNSIGNED NOT NULL,
    role_name VARCHAR(50) NOT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    event_type ENUM('requested', 'approved', 'rejected', 'revoked') NOT NULL,
    reason VARCHAR(500) NOT NULL,
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_school_role_events_subject (school_id, subject_user_id, occurred_at),
    CONSTRAINT fk_school_role_event_request FOREIGN KEY (request_id) REFERENCES school_role_requests(id) ON DELETE SET NULL,
    CONSTRAINT fk_school_role_event_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT,
    CONSTRAINT fk_school_role_event_subject FOREIGN KEY (subject_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_school_role_event_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO school_role_requests
    (school_id, user_id, role_name, request_reason, supporting_information, status,
     requested_at, reviewer_user_id, reviewed_at, decision_reason)
SELECT sm.school_id, sm.user_id, sm.role_name,
       'Migrated pending school access request',
       'Existing request submitted before multiple-role approval was enabled.',
       'pending', sm.joined_at, NULL, NULL, NULL
FROM school_memberships sm
WHERE sm.status = 'pending'
  AND NOT EXISTS (
      SELECT 1 FROM school_role_requests srr
      WHERE srr.school_id = sm.school_id AND srr.user_id = sm.user_id
        AND srr.role_name = sm.role_name AND srr.status = 'pending'
  );

INSERT INTO school_role_request_events
    (school_id, subject_user_id, role_name, actor_user_id, event_type, reason, occurred_at)
SELECT srr.school_id, srr.user_id, srr.role_name, srr.user_id, 'requested',
       srr.request_reason, srr.requested_at
FROM school_role_requests srr
WHERE srr.request_reason = 'Migrated pending school access request'
  AND NOT EXISTS (
      SELECT 1 FROM school_role_request_events sre
      WHERE sre.school_id = srr.school_id AND sre.subject_user_id = srr.user_id
        AND sre.role_name = srr.role_name AND sre.event_type = 'requested'
        AND sre.occurred_at = srr.requested_at
  );

INSERT INTO school_role_request_events
    (school_id, subject_user_id, role_name, actor_user_id, event_type, reason, occurred_at)
SELECT sm.school_id, sm.user_id, sm.role_name, sm.approved_by, 'approved',
       'Existing approved role carried forward, original approval predates per-role request auditing.',
       sm.joined_at
FROM school_memberships sm
WHERE sm.status = 'active'
  AND NOT EXISTS (
      SELECT 1 FROM school_role_request_events sre
      WHERE sre.school_id = sm.school_id AND sre.subject_user_id = sm.user_id
        AND sre.role_name = sm.role_name AND sre.event_type = 'approved'
  );

UPDATE school_memberships
SET status = 'disabled'
WHERE status = 'pending';
