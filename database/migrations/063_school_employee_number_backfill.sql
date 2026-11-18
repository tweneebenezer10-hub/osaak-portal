USE secure_auth_system;

UPDATE school_staff
SET employee_number = NULL
WHERE employee_number IS NOT NULL AND TRIM(employee_number) = '';

SET @has_appointed_on = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'school_staff' AND column_name = 'appointed_on'
);
SET @add_appointed_on_sql = IF(
    @has_appointed_on = 0,
    'ALTER TABLE school_staff ADD COLUMN appointed_on DATE NULL AFTER employee_number',
    'DO 0'
);
PREPARE add_appointed_on_stmt FROM @add_appointed_on_sql;
EXECUTE add_appointed_on_stmt;
DEALLOCATE PREPARE add_appointed_on_stmt;

SELECT COUNT(*) INTO @duplicate_employee_numbers
FROM (
    SELECT school_id, employee_number
    FROM school_staff
    WHERE employee_number IS NOT NULL AND TRIM(employee_number) <> ''
    GROUP BY school_id, employee_number
    HAVING COUNT(*) > 1
) AS duplicate_numbers;

SET @has_employee_number_index = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'school_staff'
      AND index_name = 'uk_school_staff_employee_number'
);
SET @add_employee_number_index_sql = IF(
    @has_employee_number_index > 0,
    'DO 0',
    IF(
        @duplicate_employee_numbers = 0,
        'ALTER TABLE school_staff ADD UNIQUE KEY uk_school_staff_employee_number (school_id, employee_number)',
        'SELECT "Duplicate employee numbers found; unique index requires authorized manual review." AS migration_status'
    )
);
PREPARE add_employee_number_index_stmt FROM @add_employee_number_index_sql;
EXECUTE add_employee_number_index_stmt;
DEALLOCATE PREPARE add_employee_number_index_stmt;