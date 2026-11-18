USE secure_auth_system;

ALTER TABLE school_assessments
    ADD COLUMN academic_year VARCHAR(30) NULL AFTER class_id,
    ADD COLUMN term_id BIGINT UNSIGNED NULL AFTER academic_year,
    ADD COLUMN submitted_to_user_id BIGINT UNSIGNED NULL AFTER submitted_by;

UPDATE school_assessments assessment
JOIN school_classes class
  ON class.id = assessment.class_id AND class.school_id = assessment.school_id
SET assessment.academic_year = class.academic_year;

UPDATE school_assessments assessment
JOIN school_classes class
  ON class.id = assessment.class_id AND class.school_id = assessment.school_id
JOIN school_academic_years year
  ON year.school_id = assessment.school_id AND year.academic_year = class.academic_year
JOIN school_terms term
  ON term.school_id = year.school_id AND term.academic_year_id = year.id
 AND term.term_name = assessment.term_name
SET assessment.term_id = term.id
WHERE assessment.term_id IS NULL;

UPDATE school_assessments assessment
JOIN teacher_class_assignments assignment
  ON assignment.school_id = assessment.school_id
 AND assignment.class_id = assessment.class_id
 AND assignment.academic_year = assessment.academic_year
SET assessment.submitted_to_user_id = assignment.teacher_user_id
WHERE assessment.submitted_at IS NOT NULL
  AND assessment.submitted_to_user_id IS NULL;

ALTER TABLE school_assessments
    MODIFY COLUMN academic_year VARCHAR(30) NOT NULL,
    ADD KEY idx_school_assessment_term_context (school_id, academic_year, term_id, class_id),
    ADD UNIQUE KEY uk_school_assessment_context (school_id, class_id, subject_id, term_id, assessment_name),
    ADD CONSTRAINT fk_school_assessment_term_066
        FOREIGN KEY (term_id) REFERENCES school_terms(id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_school_assessment_recipient_066
        FOREIGN KEY (submitted_to_user_id) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS school_assessment_submissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    assessment_id BIGINT UNSIGNED NOT NULL,
    submission_number INT UNSIGNED NOT NULL,
    submitted_by BIGINT UNSIGNED NULL,
    submitted_to_user_id BIGINT UNSIGNED NULL,
    workflow_status ENUM('submitted','correction_requested','corrected','reviewed','published') NOT NULL,
    submitted_at DATETIME NOT NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    correction_reason VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_school_assessment_submission_number (assessment_id, submission_number),
    KEY idx_school_assessment_submission_inbox
        (school_id, submitted_to_user_id, workflow_status, submitted_at),
    CONSTRAINT fk_school_assessment_submission_school_066
        FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_assessment_submission_assessment_066
        FOREIGN KEY (assessment_id) REFERENCES school_assessments(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_assessment_submission_sender_066
        FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_school_assessment_submission_recipient_066
        FOREIGN KEY (submitted_to_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_school_assessment_submission_reviewer_066
        FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO school_assessment_submissions
    (school_id, assessment_id, submission_number, submitted_by, submitted_to_user_id,
     workflow_status, submitted_at, reviewed_by, reviewed_at, correction_reason)
SELECT assessment.school_id, assessment.id, 1, assessment.submitted_by,
       assessment.submitted_to_user_id,
       CASE WHEN assessment.workflow_status = 'draft' THEN 'submitted'
            ELSE assessment.workflow_status END,
       assessment.submitted_at, assessment.reviewed_by, assessment.reviewed_at,
       assessment.correction_reason
FROM school_assessments assessment
WHERE assessment.submitted_at IS NOT NULL;

ALTER TABLE school_report_card_batches
    ADD COLUMN is_outdated TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN outdated_at DATETIME NULL AFTER is_outdated,
    ADD COLUMN revision_number INT UNSIGNED NOT NULL DEFAULT 1 AFTER outdated_at,
    DROP INDEX uq_school_report_card_batch,
    ADD UNIQUE KEY uk_school_report_card_batch_revision_066
        (school_id, class_id, academic_year, term_name, assessment_name, revision_number);

ALTER TABLE school_report_card_entries
    ADD COLUMN student_name VARCHAR(255) NULL AFTER student_id,
    ADD COLUMN admission_number VARCHAR(60) NULL AFTER student_name;

UPDATE school_report_card_entries entry
JOIN school_students student
  ON student.id = entry.student_id AND student.school_id = entry.school_id
SET entry.student_name = student.full_name,
    entry.admission_number = student.admission_number
WHERE entry.student_name IS NULL;

ALTER TABLE school_report_card_entries
    MODIFY COLUMN student_name VARCHAR(255) NOT NULL;
