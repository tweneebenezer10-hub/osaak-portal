-- Performance indexes for hot-path queries in production (TiDB Cloud / MySQL 8 / MariaDB)
-- Safe to run multiple times; apply via fix_all_schema or mysql client.

ALTER TABLE school_notifications
    ADD INDEX IF NOT EXISTS idx_school_notif_recipient_read (school_id, recipient_user_id, read_at);

ALTER TABLE school_notifications
    ADD INDEX IF NOT EXISTS idx_school_notif_school_unread (school_id, recipient_user_id, archived_at, read_at);

ALTER TABLE association_notifications
    ADD INDEX IF NOT EXISTS idx_assoc_notif_user_read (user_id, read_at);

ALTER TABLE school_memberships
    ADD INDEX IF NOT EXISTS idx_school_memberships_user_status (user_id, status, school_id);

ALTER TABLE school_staff
    ADD INDEX IF NOT EXISTS idx_school_staff_school_user_status (school_id, user_id, status);

ALTER TABLE users
    ADD INDEX IF NOT EXISTS idx_users_id_status (id, status, email_verified_at);

ALTER TABLE user_roles
    ADD INDEX IF NOT EXISTS idx_user_roles_user (user_id, role_id);

ALTER TABLE app_sessions
    ADD INDEX IF NOT EXISTS idx_app_sessions_id_expires (id, expires_at);
