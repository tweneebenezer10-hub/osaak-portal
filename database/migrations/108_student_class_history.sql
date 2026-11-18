ALTER TABLE student_lifecycle_events
    ADD COLUMN effective_on DATE NULL AFTER exit_date;

UPDATE student_lifecycle_events
SET effective_on = COALESCE(exit_date, DATE(created_at))
WHERE effective_on IS NULL;

ALTER TABLE student_lifecycle_events
    MODIFY COLUMN effective_on DATE NOT NULL;

CREATE TABLE school_student_class_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    event_type ENUM(
        'initial_snapshot',
        'class_change',
        'reinstated',
        'graduation_reversed',
        'transfer_received'
    ) NOT NULL,
    previous_class_id BIGINT UNSIGNED NULL,
    new_class_id BIGINT UNSIGNED NULL,
    previous_class_snapshot VARCHAR(180) NULL,
    new_class_snapshot VARCHAR(180) NULL,
    previous_academic_year VARCHAR(30) NULL,
    new_academic_year VARCHAR(30) NULL,
    effective_on DATE NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_student_class_history (school_id, student_id, effective_on, id),
    CONSTRAINT fk_student_class_history_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT,
    CONSTRAINT fk_student_class_history_student FOREIGN KEY (student_id) REFERENCES school_students(id) ON DELETE RESTRICT,
    CONSTRAINT fk_student_class_history_previous_class FOREIGN KEY (previous_class_id) REFERENCES school_classes(id) ON DELETE SET NULL,
    CONSTRAINT fk_student_class_history_new_class FOREIGN KEY (new_class_id) REFERENCES school_classes(id) ON DELETE SET NULL,
    CONSTRAINT fk_student_class_history_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO school_student_class_history
    (school_id, student_id, event_type, new_class_id, new_class_snapshot,
     new_academic_year, effective_on, reason)
SELECT student.school_id, student.id, 'initial_snapshot', class.id,
       CONCAT(class.level, IF(class.section IS NULL OR class.section = '', '', CONCAT(' ', class.section))),
       class.academic_year, CURRENT_DATE,
       'Current class placement recorded when class-history tracking began; earlier placement history was not available.'
FROM school_students student
JOIN school_classes class ON class.id = student.class_id AND class.school_id = student.school_id;
