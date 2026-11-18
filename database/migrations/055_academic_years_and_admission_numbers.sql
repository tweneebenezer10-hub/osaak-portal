ALTER TABLE schools
    ADD COLUMN admission_abbreviation VARCHAR(20) NULL AFTER short_name;

ALTER TABLE school_students
    ADD COLUMN admission_date DATE NULL AFTER admission_number;

UPDATE school_students
SET admission_date = DATE(created_at)
WHERE admission_date IS NULL;

CREATE TABLE IF NOT EXISTS school_academic_years (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    academic_year VARCHAR(30) NOT NULL,
    starts_on DATE NULL,
    ends_on DATE NULL,
    status ENUM('open','closed') NOT NULL DEFAULT 'open',
    is_active BOOLEAN NOT NULL DEFAULT FALSE,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_school_academic_year (school_id, academic_year),
    KEY idx_school_academic_year_active (school_id, is_active, status),
    CONSTRAINT fk_school_academic_year_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_academic_year_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_school_academic_year_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO school_academic_years (school_id, academic_year, starts_on, ends_on)
SELECT calendar_rows.school_id, calendar_rows.academic_year,
       MIN(calendar_rows.starts_on), MAX(calendar_rows.ends_on)
FROM (
    SELECT school_id, academic_year, starts_on, ends_on FROM school_terms
    UNION ALL
    SELECT school_id, academic_year, NULL, NULL FROM school_classes
    UNION ALL
    SELECT school_id, academic_year, NULL, NULL FROM school_fee_structures WHERE academic_year IS NOT NULL
    UNION ALL
    SELECT school_id, academic_year, NULL, NULL FROM school_invoices WHERE academic_year IS NOT NULL
    UNION ALL
    SELECT school_id, academic_year, NULL, NULL FROM student_graduations
    UNION ALL
    SELECT school_id, academic_year, NULL, NULL FROM school_completion_certificates
    UNION ALL
    SELECT school_id, academic_year, NULL, NULL FROM school_report_card_batches
) AS calendar_rows
GROUP BY calendar_rows.school_id, calendar_rows.academic_year
ON DUPLICATE KEY UPDATE
    starts_on = COALESCE(school_academic_years.starts_on, VALUES(starts_on)),
    ends_on = COALESCE(school_academic_years.ends_on, VALUES(ends_on));

UPDATE school_academic_years AS academic_year
JOIN (
    SELECT school_id, MIN(academic_year) AS academic_year
    FROM school_terms
    WHERE is_current = 1
    GROUP BY school_id
    HAVING COUNT(*) = 1
) AS single_current_term
  ON single_current_term.school_id = academic_year.school_id
 AND single_current_term.academic_year = academic_year.academic_year
SET academic_year.is_active = 1;

CREATE TABLE IF NOT EXISTS school_admission_sequences (
    school_id BIGINT UNSIGNED NOT NULL,
    admission_year SMALLINT UNSIGNED NOT NULL,
    next_sequence INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (school_id, admission_year),
    CONSTRAINT fk_school_admission_sequence_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB;
