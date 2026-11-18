DELIMITER //

DROP PROCEDURE IF EXISTS upgrade_school_notification_attention//
CREATE PROCEDURE upgrade_school_notification_attention()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'school_notifications'
          AND COLUMN_NAME = 'priority'
    ) THEN
        ALTER TABLE school_notifications
            ADD COLUMN priority ENUM('critical','high','normal','informational')
                NOT NULL DEFAULT 'normal' AFTER category;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'school_notifications'
          AND COLUMN_NAME = 'action_required'
    ) THEN
        ALTER TABLE school_notifications
            ADD COLUMN action_required TINYINT(1) NOT NULL DEFAULT 0 AFTER link_url;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'school_notifications'
          AND COLUMN_NAME = 'acknowledged_at'
    ) THEN
        ALTER TABLE school_notifications
            ADD COLUMN acknowledged_at DATETIME NULL AFTER read_at;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'school_notifications'
          AND COLUMN_NAME = 'archived_at'
    ) THEN
        ALTER TABLE school_notifications
            ADD COLUMN archived_at DATETIME NULL AFTER acknowledged_at;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'school_notifications'
          AND INDEX_NAME = 'idx_school_notification_attention'
    ) THEN
        ALTER TABLE school_notifications
            ADD INDEX idx_school_notification_attention
                (school_id, recipient_user_id, archived_at, priority, read_at, created_at);
    END IF;
END//

CALL upgrade_school_notification_attention()//
DROP PROCEDURE upgrade_school_notification_attention//

DELIMITER ;
