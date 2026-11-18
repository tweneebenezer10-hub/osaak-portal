USE secure_auth_system;

ALTER TABLE membership_due_notifications
    MODIFY status ENUM('pending','sending','sent','failed','retry_queued') NOT NULL DEFAULT 'pending';
