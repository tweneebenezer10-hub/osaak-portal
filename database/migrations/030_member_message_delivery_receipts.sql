USE secure_auth_system;

ALTER TABLE member_messages
    ADD COLUMN IF NOT EXISTS delivered_at DATETIME NULL AFTER created_at;

UPDATE member_messages
SET delivered_at=read_at
WHERE read_at IS NOT NULL AND delivered_at IS NULL;
