USE secure_auth_system;

ALTER TABLE school_grade_rules
    ADD COLUMN grade VARCHAR(10) NULL AFTER grade_code,
    ADD COLUMN grade_order SMALLINT UNSIGNED NULL AFTER minimum_percent,
    ADD COLUMN remark VARCHAR(120) NULL AFTER grade_order;

UPDATE school_grade_rules AS rules
JOIN (
    SELECT ordered.id, ordered.grade_order
    FROM (
        SELECT id,
               ROW_NUMBER() OVER (PARTITION BY school_id ORDER BY minimum_percent DESC, id) AS grade_order
        FROM school_grade_rules
    ) AS ordered
) AS grade_ordering ON grade_ordering.id = rules.id
SET rules.grade = rules.grade_code,
    rules.grade_order = grade_ordering.grade_order,
    rules.remark = CASE rules.grade_code
        WHEN 'A' THEN 'Excellent'
        WHEN 'B' THEN 'Very good'
        WHEN 'C' THEN 'Good'
        WHEN 'D' THEN 'Credit'
        WHEN 'E' THEN 'Pass'
        WHEN 'F' THEN 'Needs improvement'
        ELSE NULL
    END;

ALTER TABLE school_grade_rules
    MODIFY COLUMN grade VARCHAR(10) NOT NULL,
    MODIFY COLUMN grade_order SMALLINT UNSIGNED NOT NULL,
    ADD UNIQUE KEY uk_school_grade_label_054 (school_id, grade),
    ADD UNIQUE KEY uk_school_grade_order_054 (school_id, grade_order);

CREATE TABLE IF NOT EXISTS school_result_settings (
    school_id BIGINT UNSIGNED NOT NULL,
    ranking_policy ENUM('competition','dense') NOT NULL DEFAULT 'competition',
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (school_id),
    CONSTRAINT fk_school_result_settings_school_054
        FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_result_settings_user_054
        FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO school_result_settings (school_id, ranking_policy, updated_by)
SELECT schools.id,
       CASE WHEN policies.tie_policy = 'dense' THEN 'dense' ELSE 'competition' END,
       policies.updated_by
FROM schools
LEFT JOIN school_result_policies policies ON policies.school_id = schools.id;

ALTER TABLE school_assessments
    MODIFY COLUMN workflow_status ENUM('draft','submitted','correction_requested','reviewed','published')
        NOT NULL DEFAULT 'draft',
    ADD COLUMN correction_reason VARCHAR(500) NULL AFTER review_note;

ALTER TABLE school_class_attendance_submissions
    ADD COLUMN teacher_user_id BIGINT UNSIGNED NULL AFTER submitted_by;

UPDATE school_class_attendance_submissions
SET teacher_user_id = submitted_by
WHERE teacher_user_id IS NULL;

ALTER TABLE school_class_attendance_submissions
    MODIFY COLUMN teacher_user_id BIGINT UNSIGNED NOT NULL,
    MODIFY COLUMN workflow_status ENUM('draft','submitted','correction_requested','corrected','reviewed')
        NOT NULL DEFAULT 'submitted',
    ADD KEY idx_school_attendance_teacher_054 (teacher_user_id),
    ADD CONSTRAINT fk_school_attendance_teacher_054
        FOREIGN KEY (teacher_user_id) REFERENCES users(id) ON DELETE CASCADE;

CREATE TABLE IF NOT EXISTS school_assessment_result_revisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    assessment_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    changed_by BIGINT UNSIGNED NOT NULL,
    previous_score DECIMAL(7,2) NULL,
    new_score DECIMAL(7,2) NOT NULL,
    previous_remarks VARCHAR(255) NULL,
    new_remarks VARCHAR(255) NULL,
    reason VARCHAR(500) NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_school_result_revision_054 (school_id, assessment_id, student_id, changed_at),
    CONSTRAINT fk_school_result_revision_school_054
        FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_result_revision_assessment_054
        FOREIGN KEY (assessment_id) REFERENCES school_assessments(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_result_revision_student_054
        FOREIGN KEY (student_id) REFERENCES school_students(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_result_revision_actor_054
        FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

ALTER TABLE school_report_card_batches
    MODIFY COLUMN status ENUM('draft','ready','published') NOT NULL DEFAULT 'draft',
    ADD COLUMN generated_by BIGINT UNSIGNED NULL AFTER status,
    ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER published_at,
    ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
    ADD KEY idx_school_report_card_generator_054 (generated_by),
    ADD CONSTRAINT fk_school_report_card_generator_054
        FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL;

UPDATE school_report_card_batches
SET generated_by = prepared_by
WHERE generated_by IS NULL AND prepared_by IS NOT NULL;

CREATE TABLE IF NOT EXISTS school_report_card_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    batch_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    total_score DECIMAL(9,2) NOT NULL,
    average_percent DECIMAL(6,2) NOT NULL,
    overall_grade VARCHAR(10) NOT NULL,
    class_position INT UNSIGNED NOT NULL,
    subject_results JSON NOT NULL,
    attendance_summary JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_school_report_card_entry_student_054 (batch_id, student_id),
    KEY idx_school_report_card_entry_scope_054 (school_id, student_id),
    CONSTRAINT fk_school_report_card_entry_school_054
        FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_report_card_entry_batch_054
        FOREIGN KEY (batch_id) REFERENCES school_report_card_batches(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_report_card_entry_student_054
        FOREIGN KEY (student_id) REFERENCES school_students(id) ON DELETE CASCADE
) ENGINE=InnoDB;
