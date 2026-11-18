USE secure_auth_system;

CREATE TABLE IF NOT EXISTS membership_id_sequences (
    graduation_year SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
    last_sequence SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

SET @membership_review_column_exists = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'member_profiles'
      AND column_name = 'membership_id_review_required'
);
SET @membership_review_column_sql = IF(
    @membership_review_column_exists = 0,
    'ALTER TABLE member_profiles ADD COLUMN membership_id_review_required BOOLEAN NOT NULL DEFAULT FALSE AFTER membership_number',
    'SELECT 1'
);
PREPARE membership_review_column_statement FROM @membership_review_column_sql;
EXECUTE membership_review_column_statement;
DEALLOCATE PREPARE membership_review_column_statement;

UPDATE member_profiles
SET membership_id_review_required = 1
WHERE graduation_year IS NULL
   OR membership_number IS NULL
   OR membership_number NOT REGEXP CONCAT(
       '^OSAAK/', RIGHT(CAST(graduation_year AS CHAR), 2), '[0-9]{3}$'
   )
   OR CAST(RIGHT(membership_number, 3) AS UNSIGNED) = 0;

INSERT INTO membership_id_sequences (graduation_year, last_sequence)
SELECT graduation_year, MAX(CAST(RIGHT(membership_number, 3) AS UNSIGNED))
FROM member_profiles
WHERE graduation_year IS NOT NULL
  AND membership_number REGEXP CONCAT(
      '^OSAAK/', RIGHT(CAST(graduation_year AS CHAR), 2), '[0-9]{3}$'
  )
  AND CAST(RIGHT(membership_number, 3) AS UNSIGNED) BETWEEN 1 AND 999
GROUP BY graduation_year
ON DUPLICATE KEY UPDATE
    last_sequence = GREATEST(last_sequence, VALUES(last_sequence));
