SET @it_support_access_mode_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'it_support_sessions'
      AND COLUMN_NAME = 'access_mode'
);

SET @it_support_access_mode_migration = IF(
    @it_support_access_mode_exists = 0,
    'ALTER TABLE it_support_sessions ADD COLUMN access_mode ENUM(''read_only'', ''administrative'') NOT NULL DEFAULT ''read_only'' AFTER preview_role',
    'SELECT 1'
);

PREPARE it_support_access_mode_statement FROM @it_support_access_mode_migration;
EXECUTE it_support_access_mode_statement;
DEALLOCATE PREPARE it_support_access_mode_statement;
