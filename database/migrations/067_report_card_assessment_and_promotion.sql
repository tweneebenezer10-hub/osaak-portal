ALTER TABLE school_report_card_notes
    ADD COLUMN assessment_ratings JSON NULL AFTER headmaster_remarks,
    ADD COLUMN promotion_override ENUM('promoted','pending') NULL AFTER assessment_ratings,
    ADD COLUMN promotion_override_reason VARCHAR(500) NULL AFTER promotion_override;
