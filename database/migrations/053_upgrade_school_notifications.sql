USE secure_auth_system;

DELIMITER //

DROP PROCEDURE IF EXISTS upgrade_school_notification_schema//
CREATE PROCEDURE upgrade_school_notification_schema()
BEGIN
    DECLARE legacy_user_column INT DEFAULT 0;
    DECLARE recipient_column INT DEFAULT 0;
    DECLARE category_column INT DEFAULT 0;
    DECLARE legacy_user_foreign_key INT DEFAULT 0;
    DECLARE recipient_foreign_key INT DEFAULT 0;

    SELECT COUNT(*) INTO legacy_user_column
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'school_notifications'
      AND COLUMN_NAME = 'user_id';

    SELECT COUNT(*) INTO recipient_column
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'school_notifications'
      AND COLUMN_NAME = 'recipient_user_id';

    IF legacy_user_column = 1 AND recipient_column = 0 THEN
        SELECT COUNT(*) INTO legacy_user_foreign_key
        FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = 'school_notifications'
          AND CONSTRAINT_NAME = 'fk_school_notification_user'
          AND CONSTRAINT_TYPE = 'FOREIGN KEY';

        IF legacy_user_foreign_key = 1 THEN
            ALTER TABLE school_notifications
                DROP FOREIGN KEY fk_school_notification_user;
        END IF;

        ALTER TABLE school_notifications
            CHANGE COLUMN user_id recipient_user_id BIGINT UNSIGNED NOT NULL;
        SET recipient_column = 1;
    END IF;

    SELECT COUNT(*) INTO category_column
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'school_notifications'
      AND COLUMN_NAME = 'category';

    IF category_column = 0 THEN
        ALTER TABLE school_notifications
            ADD COLUMN category ENUM('attendance','assessment','report_card','other')
                NOT NULL DEFAULT 'other' AFTER notification_key;
    END IF;

    SELECT COUNT(*) INTO recipient_foreign_key
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'school_notifications'
      AND CONSTRAINT_NAME = 'fk_school_notification_recipient'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY';

    IF recipient_column = 1 AND recipient_foreign_key = 0 THEN
        ALTER TABLE school_notifications
            ADD CONSTRAINT fk_school_notification_recipient
            FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE CASCADE;
    END IF;
END//

CALL upgrade_school_notification_schema()//
DROP PROCEDURE upgrade_school_notification_schema//

DELIMITER ;
