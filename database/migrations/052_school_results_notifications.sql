USE secure_auth_system;

ALTER TABLE school_assessments
    ADD COLUMN workflow_status ENUM('draft','submitted','correction_requested','reviewed','published') NOT NULL DEFAULT 'draft' AFTER total_marks,
    ADD COLUMN submitted_by BIGINT UNSIGNED NULL AFTER workflow_status,
    ADD COLUMN submitted_at DATETIME NULL AFTER submitted_by,
    ADD COLUMN reviewed_by BIGINT UNSIGNED NULL AFTER submitted_at,
    ADD COLUMN reviewed_at DATETIME NULL AFTER reviewed_by,
    ADD COLUMN correction_reason VARCHAR(500) NULL AFTER reviewed_at,
    ADD KEY idx_school_assessment_workflow (school_id, class_id, term_name, workflow_status),
    ADD CONSTRAINT fk_school_assessment_submitter FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_school_assessment_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL;

UPDATE school_assessments
SET workflow_status = CASE
        WHEN published_at IS NOT NULL THEN 'published'
        WHEN EXISTS (
            SELECT 1 FROM assessment_results result
            WHERE result.school_id = school_assessments.school_id
              AND result.assessment_id = school_assessments.id
        ) THEN 'submitted'
        ELSE 'draft'
    END,
    submitted_by = (
        SELECT result.recorded_by FROM assessment_results result
        WHERE result.school_id = school_assessments.school_id
          AND result.assessment_id = school_assessments.id
        ORDER BY result.created_at DESC, result.id DESC LIMIT 1
    ),
    submitted_at = (
        SELECT MAX(result.created_at) FROM assessment_results result
        WHERE result.school_id = school_assessments.school_id
          AND result.assessment_id = school_assessments.id
    );

ALTER TABLE student_attendance
    MODIFY COLUMN status ENUM('present','absent','excused','late') NOT NULL DEFAULT 'present';

UPDATE student_attendance
SET status = legacy_status
WHERE legacy_status IN ('late','excused');

ALTER TABLE school_class_attendance_submissions
    MODIFY COLUMN workflow_status ENUM('draft','submitted','correction_requested','corrected','reviewed') NOT NULL DEFAULT 'submitted';

INSERT IGNORE INTO school_class_attendance_submissions
    (school_id, class_id, attendance_date, teacher_user_id, revision, workflow_status, submitted_at)
SELECT attendance.school_id, attendance.class_id, attendance.attendance_date,
       COALESCE(MAX(attendance.recorded_by), MAX(class_teacher.teacher_user_id)),
       1, 'submitted', MAX(attendance.recorded_at)
FROM student_attendance attendance
LEFT JOIN school_classes class ON class.id = attendance.class_id
    AND class.school_id = attendance.school_id
LEFT JOIN teacher_class_assignments class_teacher ON class_teacher.school_id = class.school_id
    AND class_teacher.class_id = class.id AND class_teacher.academic_year = class.academic_year
WHERE attendance.class_id IS NOT NULL
GROUP BY attendance.school_id, attendance.class_id, attendance.attendance_date
HAVING COALESCE(MAX(attendance.recorded_by), MAX(class_teacher.teacher_user_id)) IS NOT NULL;

CREATE TABLE IF NOT EXISTS school_grade_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    grade VARCHAR(10) NOT NULL,
    minimum_percent DECIMAL(5,2) NOT NULL,
    grade_order SMALLINT UNSIGNED NOT NULL,
    remark VARCHAR(120) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_school_grade_rule (school_id, grade),
    UNIQUE KEY uk_school_grade_order (school_id, grade_order),
    KEY idx_school_grade_threshold (school_id, minimum_percent),
    CONSTRAINT fk_school_grade_rule_school
        FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT chk_school_grade_percent CHECK (minimum_percent >= 0 AND minimum_percent <= 100)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS school_result_settings (
    school_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    ranking_policy ENUM('competition','dense') NOT NULL DEFAULT 'competition',
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_school_result_settings_school
        FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_result_settings_user
        FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO school_result_settings (school_id, ranking_policy)
SELECT id, 'competition' FROM schools;

INSERT IGNORE INTO school_grade_rules (school_id, grade, minimum_percent, grade_order, remark)
SELECT schools.id, grades.grade, grades.minimum_percent, grades.grade_order, grades.remark
FROM schools
CROSS JOIN (
    SELECT 'A' AS grade, 90.00 AS minimum_percent, 1 AS grade_order, 'Excellent' AS remark
    UNION ALL SELECT 'B', 80.00, 2, 'Very good'
    UNION ALL SELECT 'C', 70.00, 3, 'Good'
    UNION ALL SELECT 'D', 60.00, 4, 'Credit'
    UNION ALL SELECT 'E', 50.00, 5, 'Pass'
    UNION ALL SELECT 'F', 0.00, 6, 'Needs improvement'
) AS grades;

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
    KEY idx_school_result_revision (school_id, assessment_id, student_id, changed_at),
    CONSTRAINT fk_school_result_revision_school
        FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_result_revision_assessment
        FOREIGN KEY (assessment_id) REFERENCES school_assessments(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_result_revision_student
        FOREIGN KEY (student_id) REFERENCES school_students(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_result_revision_actor
        FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS school_report_card_batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    class_id BIGINT UNSIGNED NOT NULL,
    academic_year VARCHAR(30) NOT NULL,
    term_name VARCHAR(50) NOT NULL,
    assessment_name VARCHAR(120) NOT NULL,
    status ENUM('draft','ready','published') NOT NULL DEFAULT 'draft',
    generated_by BIGINT UNSIGNED NOT NULL,
    published_by BIGINT UNSIGNED NULL,
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_school_report_card_batch (school_id, class_id, academic_year, term_name, assessment_name),
    KEY idx_school_report_card_batch_status (school_id, status, published_at),
    CONSTRAINT fk_school_report_card_batch_school
        FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_report_card_batch_class
        FOREIGN KEY (class_id) REFERENCES school_classes(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_report_card_batch_generator
        FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_school_report_card_batch_publisher
        FOREIGN KEY (published_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS school_report_card_notes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    batch_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    teacher_comment VARCHAR(1000) NULL,
    headmaster_remarks VARCHAR(1000) NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_school_report_card_note_student (batch_id, student_id),
    KEY idx_school_report_card_note_scope (school_id, batch_id),
    CONSTRAINT fk_school_report_card_note_school
        FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_report_card_note_batch
        FOREIGN KEY (batch_id) REFERENCES school_report_card_batches(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_report_card_note_student
        FOREIGN KEY (student_id) REFERENCES school_students(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_report_card_note_actor
        FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

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
    UNIQUE KEY uk_school_report_card_entry_student (batch_id, student_id),
    KEY idx_school_report_card_entry_scope (school_id, student_id),
    CONSTRAINT fk_school_report_card_entry_school
        FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_report_card_entry_batch
        FOREIGN KEY (batch_id) REFERENCES school_report_card_batches(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_report_card_entry_student
        FOREIGN KEY (student_id) REFERENCES school_students(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS school_notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    recipient_user_id BIGINT UNSIGNED NOT NULL,
    sender_user_id BIGINT UNSIGNED NULL,
    notification_key VARCHAR(180) NOT NULL,
    category ENUM('attendance','assessment','report_card','other') NOT NULL DEFAULT 'other',
    title VARCHAR(180) NOT NULL,
    body VARCHAR(1000) NOT NULL,
    link_url VARCHAR(500) NULL,
    class_id BIGINT UNSIGNED NULL,
    subject_id BIGINT UNSIGNED NULL,
    assessment_id BIGINT UNSIGNED NULL,
    attendance_date DATE NULL,
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_school_notification_recipient_key (school_id, recipient_user_id, notification_key),
    KEY idx_school_notification_unread (school_id, recipient_user_id, read_at, created_at),
    CONSTRAINT fk_school_notification_school
        FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_notification_recipient
        FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_notification_sender
        FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_school_notification_class
        FOREIGN KEY (class_id) REFERENCES school_classes(id) ON DELETE SET NULL,
    CONSTRAINT fk_school_notification_subject
        FOREIGN KEY (subject_id) REFERENCES school_subjects(id) ON DELETE SET NULL,
    CONSTRAINT fk_school_notification_assessment
        FOREIGN KEY (assessment_id) REFERENCES school_assessments(id) ON DELETE SET NULL
) ENGINE=InnoDB;
