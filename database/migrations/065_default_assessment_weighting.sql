USE secure_auth_system;

ALTER TABLE school_result_settings
    MODIFY COLUMN class_score_weight DECIMAL(5,2) NOT NULL DEFAULT 30.00;

ALTER TABLE school_assessments
    MODIFY COLUMN total_marks DECIMAL(7,2) NOT NULL DEFAULT 100.00,
    MODIFY COLUMN class_score_weight DECIMAL(5,2) NOT NULL DEFAULT 30.00,
    MODIFY COLUMN exam_score_weight DECIMAL(5,2) NOT NULL DEFAULT 70.00;

UPDATE school_result_settings settings
SET settings.class_score_weight = 30.00
WHERE settings.class_score_weight = 0.00
  AND settings.updated_by IS NULL;

UPDATE school_assessments assessment
LEFT JOIN school_result_settings settings ON settings.school_id = assessment.school_id
SET assessment.total_marks = 100.00,
    assessment.class_score_weight = 30.00,
    assessment.exam_score_weight = 70.00
WHERE assessment.workflow_status = 'draft'
  AND (
      assessment.total_marks <> 100.00
      OR assessment.class_score_weight <> 30.00
      OR assessment.exam_score_weight <> 70.00
  )
  AND settings.updated_by IS NULL
  AND NOT EXISTS (
      SELECT 1 FROM assessment_results result
      WHERE result.school_id = assessment.school_id
        AND result.assessment_id = assessment.id
  );
