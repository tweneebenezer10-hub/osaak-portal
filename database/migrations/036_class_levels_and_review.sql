ALTER TABLE school_classes
    ADD COLUMN level_review_required BOOLEAN NOT NULL DEFAULT FALSE AFTER level;

UPDATE school_classes
SET level = CASE UPPER(TRIM(level))
    WHEN '' THEN CASE UPPER(TRIM(name))
        WHEN 'KG 1' THEN 'KG 1'
        WHEN 'KG1' THEN 'KG 1'
        WHEN 'KG 2' THEN 'KG 2'
        WHEN 'KG2' THEN 'KG 2'
        WHEN 'PRIMARY 1' THEN 'Primary 1'
        WHEN 'PRIMARY 2' THEN 'Primary 2'
        WHEN 'PRIMARY 3' THEN 'Primary 3'
        WHEN 'PRIMARY 4' THEN 'Primary 4'
        WHEN 'PRIMARY 5' THEN 'Primary 5'
        WHEN 'PRIMARY 6' THEN 'Primary 6'
        WHEN 'JHS 1' THEN 'JHS 1'
        WHEN 'JHS 2' THEN 'JHS 2'
        WHEN 'JHS 3' THEN 'JHS 3'
        ELSE level
    END
    WHEN 'KG1' THEN 'KG 1'
    WHEN 'KG 1' THEN 'KG 1'
    WHEN 'KINDERGARTEN 1' THEN 'KG 1'
    WHEN 'KG2' THEN 'KG 2'
    WHEN 'KG 2' THEN 'KG 2'
    WHEN 'KINDERGARTEN 2' THEN 'KG 2'
    WHEN 'P1' THEN 'Primary 1'
    WHEN 'PRIMARY 1' THEN 'Primary 1'
    WHEN 'P2' THEN 'Primary 2'
    WHEN 'PRIMARY 2' THEN 'Primary 2'
    WHEN 'P3' THEN 'Primary 3'
    WHEN 'PRIMARY 3' THEN 'Primary 3'
    WHEN 'P4' THEN 'Primary 4'
    WHEN 'PRIMARY 4' THEN 'Primary 4'
    WHEN 'P5' THEN 'Primary 5'
    WHEN 'PRIMARY 5' THEN 'Primary 5'
    WHEN 'P6' THEN 'Primary 6'
    WHEN 'PRIMARY 6' THEN 'Primary 6'
    WHEN 'JHS1' THEN 'JHS 1'
    WHEN 'JHS 1' THEN 'JHS 1'
    WHEN 'JHS2' THEN 'JHS 2'
    WHEN 'JHS 2' THEN 'JHS 2'
    WHEN 'JHS3' THEN 'JHS 3'
    WHEN 'JHS 3' THEN 'JHS 3'
    ELSE level
END;

UPDATE school_classes
SET level_review_required = CASE
    WHEN level IN ('KG 1', 'KG 2', 'Primary 1', 'Primary 2', 'Primary 3',
                   'Primary 4', 'Primary 5', 'Primary 6', 'JHS 1', 'JHS 2', 'JHS 3')
    THEN FALSE
    ELSE TRUE
END;

UPDATE school_classes
SET academic_year = CONCAT(academic_year, '/', CAST(CAST(academic_year AS UNSIGNED) + 1 AS CHAR))
WHERE academic_year REGEXP '^[0-9]{4}$';
