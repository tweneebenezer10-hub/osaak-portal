ALTER TABLE school_report_card_notes
    ADD COLUMN student_behaviour TEXT NULL AFTER assessment_ratings,
    ADD COLUMN next_term_date DATE NULL AFTER student_behaviour,
    ADD COLUMN class_teacher_signature_name VARCHAR(160) NULL AFTER next_term_date,
    ADD COLUMN headmaster_signature_name VARCHAR(160) NULL AFTER class_teacher_signature_name;
