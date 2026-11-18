USE secure_auth_system;

ALTER TABLE school_result_settings
    ADD COLUMN class_score_weight DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER ranking_policy,
    ADD COLUMN ranking_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER class_score_weight;

ALTER TABLE school_assessments
    ADD COLUMN class_score_weight DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER total_marks,
    ADD COLUMN exam_score_weight DECIMAL(5,2) NOT NULL DEFAULT 100.00 AFTER class_score_weight,
    ADD KEY idx_school_assessment_subject_term (school_id, class_id, subject_id, term_name);

ALTER TABLE assessment_results
    ADD COLUMN class_score DECIMAL(7,2) NULL AFTER score,
    ADD COLUMN exam_score DECIMAL(7,2) NULL AFTER class_score;

UPDATE assessment_results
SET exam_score = score
WHERE exam_score IS NULL;

ALTER TABLE school_assessment_result_revisions
    ADD COLUMN previous_class_score DECIMAL(7,2) NULL AFTER new_score,
    ADD COLUMN new_class_score DECIMAL(7,2) NULL AFTER previous_class_score,
    ADD COLUMN previous_exam_score DECIMAL(7,2) NULL AFTER new_class_score,
    ADD COLUMN new_exam_score DECIMAL(7,2) NULL AFTER previous_exam_score;

ALTER TABLE school_report_card_entries
    MODIFY COLUMN class_position INT UNSIGNED NULL,
    ADD COLUMN grading_scale JSON NULL AFTER subject_results;