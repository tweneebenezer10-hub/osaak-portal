USE secure_auth_system;

ALTER TABLE school_report_card_entries
    ADD COLUMN vacation_date DATE NULL AFTER fee_summary;

UPDATE school_report_card_entries entry
JOIN school_report_card_batches batch
  ON batch.id = entry.batch_id AND batch.school_id = entry.school_id
SET entry.vacation_date = COALESCE(
    (
        SELECT term.ends_on
        FROM school_terms term
        JOIN school_academic_years year
          ON year.id = term.academic_year_id AND year.school_id = term.school_id
        WHERE term.school_id = batch.school_id
          AND year.academic_year = batch.academic_year
          AND term.term_name = batch.term_name
        ORDER BY term.starts_on DESC
        LIMIT 1
    ),
    DATE(batch.created_at)
)
WHERE entry.vacation_date IS NULL;
