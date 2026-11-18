CREATE TABLE school_class_levels (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    category VARCHAR(60) NOT NULL DEFAULT 'Other',
    academic_stage ENUM('primary','secondary','other') NOT NULL DEFAULT 'other',
    is_graduation_level TINYINT(1) NOT NULL DEFAULT 0,
    display_order INT NOT NULL DEFAULT 0,
    next_level_id BIGINT UNSIGNED NULL,
    status ENUM('active','archived') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_school_class_level_name (school_id, name),
    UNIQUE KEY uk_school_class_level_school_id (school_id, id),
    KEY idx_school_class_levels_order (school_id, status, display_order, name),
    KEY idx_school_class_levels_next (school_id, next_level_id),
    CONSTRAINT fk_school_class_levels_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_class_levels_next FOREIGN KEY (school_id, next_level_id)
        REFERENCES school_class_levels(school_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB;

ALTER TABLE school_classes
    MODIFY COLUMN name VARCHAR(130) NOT NULL,
    MODIFY COLUMN level VARCHAR(80) NOT NULL,
    ADD COLUMN class_level_id BIGINT UNSIGNED NULL AFTER school_id,
    ADD COLUMN category VARCHAR(60) NOT NULL DEFAULT 'Other' AFTER level,
    ADD COLUMN academic_stage ENUM('primary','secondary','other') NOT NULL DEFAULT 'other' AFTER category,
    ADD COLUMN capacity INT UNSIGNED NULL AFTER class_teacher_id;

UPDATE school_classes
SET level = LEFT(COALESCE(NULLIF(TRIM(level), ''), NULLIF(TRIM(name), ''), 'Other'), 80)
WHERE TRIM(level) = '';

INSERT INTO school_class_levels (school_id, name, category, academic_stage, display_order)
SELECT source.school_id,
       source.level_name,
       CASE
           WHEN LOWER(source.level_name) REGEXP '^(creche|crèche)' THEN 'Creche'
           WHEN LOWER(source.level_name) REGEXP '^nursery' THEN 'Nursery'
           WHEN LOWER(source.level_name) REGEXP '^(kg|kindergarten)' THEN 'Kindergarten'
           WHEN LOWER(source.level_name) REGEXP '^(primary|elementary)' THEN 'Primary'
           WHEN LOWER(source.level_name) REGEXP '^(jhs|junior high)' THEN 'Junior High School'
           WHEN LOWER(source.level_name) REGEXP '^(shs|senior high)' THEN 'Senior High School'
           WHEN LOWER(source.level_name) REGEXP '^vocational' THEN 'Vocational'
           ELSE 'Other'
       END,
       CASE
           WHEN LOWER(source.level_name) REGEXP '^(creche|crèche|nursery|kg|kindergarten|primary|elementary)' THEN 'primary'
           WHEN LOWER(source.level_name) REGEXP '^(jhs|junior high|shs|senior high)' THEN 'secondary'
           ELSE 'other'
       END,
       0
FROM (
    SELECT school_id,
           MIN(LEFT(COALESCE(NULLIF(TRIM(level), ''), NULLIF(TRIM(name), ''), 'Other'), 80)) AS level_name
    FROM school_classes
    GROUP BY school_id, LOWER(LEFT(COALESCE(NULLIF(TRIM(level), ''), NULLIF(TRIM(name), ''), 'Other'), 80))
) source;

UPDATE school_classes class
JOIN school_class_levels class_level
  ON class_level.school_id = class.school_id
 AND LOWER(TRIM(class_level.name)) = LOWER(TRIM(class.level))
SET class.class_level_id = class_level.id,
    class.category = class_level.category,
    class.academic_stage = class_level.academic_stage;

UPDATE school_class_levels
SET is_graduation_level = 1
WHERE LOWER(TRIM(name)) REGEXP '^(jhs|junior high)[[:space:]]*3$';

ALTER TABLE student_graduations
    ADD COLUMN completion_program VARCHAR(180) NULL AFTER academic_year;

UPDATE student_graduations graduation
SET completion_program = COALESCE(
    (
        SELECT history.previous_class_snapshot
        FROM school_student_class_history history
        WHERE history.school_id = graduation.school_id
          AND history.student_id = graduation.student_id
          AND history.event_type = 'class_change'
          AND history.new_class_id IS NULL
          AND history.effective_on = graduation.graduation_date
          AND history.previous_class_snapshot IS NOT NULL
        ORDER BY history.id DESC
        LIMIT 1
    ),
    'JHS 3'
)
WHERE completion_program IS NULL;

ALTER TABLE student_graduations
    MODIFY COLUMN completion_program VARCHAR(180) NOT NULL;

ALTER TABLE school_classes
    MODIFY COLUMN class_level_id BIGINT UNSIGNED NOT NULL,
    ADD KEY idx_school_classes_level (school_id, class_level_id, academic_year, status),
    ADD CONSTRAINT fk_school_classes_level FOREIGN KEY (school_id, class_level_id)
        REFERENCES school_class_levels(school_id, id) ON DELETE RESTRICT;
