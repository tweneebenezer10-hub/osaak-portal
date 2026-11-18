ALTER TABLE school_academic_years
    ADD UNIQUE KEY uk_school_academic_year_scope_id (school_id, id);

ALTER TABLE school_terms
    ADD COLUMN academic_year_id BIGINT UNSIGNED NULL AFTER school_id;

UPDATE school_terms term
JOIN school_academic_years academic_year
  ON academic_year.school_id = term.school_id
 AND academic_year.academic_year = term.academic_year
SET term.academic_year_id = academic_year.id
WHERE term.academic_year_id IS NULL;

ALTER TABLE school_terms
    MODIFY COLUMN academic_year_id BIGINT UNSIGNED NOT NULL,
    ADD KEY idx_school_terms_academic_year_id (school_id, academic_year_id),
    ADD CONSTRAINT fk_school_term_academic_year
        FOREIGN KEY (school_id, academic_year_id)
        REFERENCES school_academic_years (school_id, id)
        ON DELETE RESTRICT;
