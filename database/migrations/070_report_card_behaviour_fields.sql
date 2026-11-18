ALTER TABLE school_report_card_notes
    ADD COLUMN conduct TEXT NULL AFTER student_behaviour,
    ADD COLUMN interest TEXT NULL AFTER conduct,
    ADD COLUMN attitude TEXT NULL AFTER interest;

UPDATE school_report_card_notes
SET conduct = NULLIF(TRIM(student_behaviour), '')
WHERE conduct IS NULL AND student_behaviour IS NOT NULL;
