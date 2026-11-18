ALTER TABLE password_reset_tokens
    MODIFY token_hash CHAR(64) NULL,
    ADD COLUMN sent_at DATETIME NULL AFTER created_at,
    ADD COLUMN attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER sent_at;

UPDATE password_reset_tokens
SET used_at = UTC_TIMESTAMP(), token_hash = NULL
WHERE used_at IS NULL;
