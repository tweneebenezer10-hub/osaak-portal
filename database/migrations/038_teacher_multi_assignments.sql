CREATE TABLE IF NOT EXISTS teacher_class_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    class_id BIGINT UNSIGNED NOT NULL,
    teacher_user_id BIGINT UNSIGNED NOT NULL,
    academic_year VARCHAR(30) NOT NULL,
    assigned_by BIGINT UNSIGNED NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_teacher_class_assignment_class (class_id),
    KEY idx_teacher_class_assignments_teacher_year (school_id, teacher_user_id, academic_year),
    CONSTRAINT fk_teacher_class_assignment_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_teacher_class_assignment_class FOREIGN KEY (class_id) REFERENCES school_classes(id) ON DELETE CASCADE,
    CONSTRAINT fk_teacher_class_assignment_teacher FOREIGN KEY (teacher_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_teacher_class_assignment_actor FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO teacher_class_assignments (school_id, class_id, teacher_user_id, academic_year)
SELECT school_id, id, class_teacher_id, academic_year
FROM school_classes
WHERE class_teacher_id IS NOT NULL
ON DUPLICATE KEY UPDATE teacher_user_id = VALUES(teacher_user_id), academic_year = VALUES(academic_year);
