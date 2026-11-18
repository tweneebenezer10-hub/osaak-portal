USE secure_auth_system;

ALTER TABLE association_events
    ADD COLUMN IF NOT EXISTS registration_deadline DATETIME NULL AFTER ends_at;

ALTER TABLE association_announcements
    ADD COLUMN IF NOT EXISTS linked_event_id BIGINT UNSIGNED NULL AFTER created_by,
    ADD UNIQUE INDEX IF NOT EXISTS uq_association_announcement_event (linked_event_id);

SET @event_announcement_fk_exists = (
    SELECT COUNT(*)
    FROM information_schema.referential_constraints
    WHERE constraint_schema = DATABASE()
      AND table_name = 'association_announcements'
      AND constraint_name = 'fk_announcement_linked_event'
);
SET @event_announcement_fk_sql = IF(
    @event_announcement_fk_exists = 0,
    'ALTER TABLE association_announcements ADD CONSTRAINT fk_announcement_linked_event FOREIGN KEY (linked_event_id) REFERENCES association_events(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE event_announcement_fk_statement FROM @event_announcement_fk_sql;
EXECUTE event_announcement_fk_statement;
DEALLOCATE PREPARE event_announcement_fk_statement;
