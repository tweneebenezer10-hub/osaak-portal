CREATE TABLE school_timetable_rooms (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    building VARCHAR(100) NULL,
    room_type VARCHAR(60) NULL,
    capacity SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    facilities VARCHAR(500) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_school_timetable_room_name (school_id, name),
    KEY idx_school_timetable_rooms_active (school_id, is_active, capacity),
    CONSTRAINT fk_school_timetable_room_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_timetable_room_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE school_timetable_settings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    term_id BIGINT UNSIGNED NOT NULL,
    revision_number SMALLINT UNSIGNED NOT NULL,
    weekdays_json VARCHAR(64) NOT NULL,
    starts_at TIME NOT NULL,
    lesson_minutes SMALLINT UNSIGNED NOT NULL,
    lesson_count TINYINT UNSIGNED NOT NULL,
    break_after_period TINYINT UNSIGNED NULL,
    break_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    lunch_after_period TINYINT UNSIGNED NULL,
    lunch_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    assembly_after_period TINYINT UNSIGNED NULL,
    assembly_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    max_periods_per_teacher_day TINYINT UNSIGNED NOT NULL DEFAULT 6,
    max_periods_per_teacher_week SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    max_periods_per_class_day TINYINT UNSIGNED NOT NULL DEFAULT 8,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_school_timetable_setting_revision (school_id, term_id, revision_number),
    KEY idx_school_timetable_settings_term (school_id, academic_year_id, term_id, revision_number),
    CONSTRAINT fk_school_timetable_settings_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_timetable_settings_year FOREIGN KEY (academic_year_id) REFERENCES school_academic_years(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_timetable_settings_term FOREIGN KEY (term_id) REFERENCES school_terms(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_timetable_settings_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_school_timetable_lesson_minutes CHECK (lesson_minutes BETWEEN 15 AND 180),
    CONSTRAINT chk_school_timetable_lesson_count CHECK (lesson_count BETWEEN 1 AND 20),
    CONSTRAINT chk_school_timetable_teacher_day CHECK (max_periods_per_teacher_day BETWEEN 1 AND 20),
    CONSTRAINT chk_school_timetable_teacher_week CHECK (max_periods_per_teacher_week BETWEEN 1 AND 100),
    CONSTRAINT chk_school_timetable_class_day CHECK (max_periods_per_class_day BETWEEN 1 AND 20)
) ENGINE=InnoDB;

CREATE TABLE school_timetable_periods (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    settings_id BIGINT UNSIGNED NOT NULL,
    weekday TINYINT UNSIGNED NOT NULL,
    slot_number TINYINT UNSIGNED NOT NULL,
    period_number TINYINT UNSIGNED NULL,
    label VARCHAR(60) NOT NULL,
    kind ENUM('lesson','break','lunch','assembly') NOT NULL,
    starts_at TIME NOT NULL,
    ends_at TIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_school_timetable_period_slot (settings_id, weekday, slot_number),
    UNIQUE KEY uk_school_timetable_period_number (settings_id, weekday, period_number),
    KEY idx_school_timetable_period_school (school_id, settings_id, weekday, starts_at),
    CONSTRAINT fk_school_timetable_period_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_timetable_period_settings FOREIGN KEY (settings_id) REFERENCES school_timetable_settings(id) ON DELETE CASCADE,
    CONSTRAINT chk_school_timetable_period_weekday CHECK (weekday BETWEEN 1 AND 7),
    CONSTRAINT chk_school_timetable_period_time CHECK (ends_at > starts_at)
) ENGINE=InnoDB;

CREATE TABLE school_timetable_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    academic_year_id BIGINT UNSIGNED NULL,
    academic_year VARCHAR(30) NOT NULL,
    term_id BIGINT UNSIGNED NULL,
    term_name VARCHAR(50) NOT NULL,
    settings_id BIGINT UNSIGNED NULL,
    version_number SMALLINT UNSIGNED NOT NULL,
    title VARCHAR(120) NOT NULL,
    status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    is_legacy TINYINT(1) NOT NULL DEFAULT 0,
    effective_from DATE NULL,
    effective_to DATE NULL,
    current_revision INT UNSIGNED NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NULL,
    published_by BIGINT UNSIGNED NULL,
    published_at DATETIME NULL,
    superseded_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_school_timetable_version_number (school_id, academic_year, term_name, version_number),
    KEY idx_school_timetable_version_active (school_id, academic_year_id, term_id, status, effective_from, effective_to),
    CONSTRAINT fk_school_timetable_version_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_timetable_version_year FOREIGN KEY (academic_year_id) REFERENCES school_academic_years(id) ON DELETE SET NULL,
    CONSTRAINT fk_school_timetable_version_term FOREIGN KEY (term_id) REFERENCES school_terms(id) ON DELETE SET NULL,
    CONSTRAINT fk_school_timetable_version_settings FOREIGN KEY (settings_id) REFERENCES school_timetable_settings(id) ON DELETE SET NULL,
    CONSTRAINT fk_school_timetable_version_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_school_timetable_version_publisher FOREIGN KEY (published_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_school_timetable_version_dates CHECK (effective_to IS NULL OR effective_from IS NULL OR effective_to >= effective_from)
) ENGINE=InnoDB;

ALTER TABLE school_timetable_entries
    ADD COLUMN version_id BIGINT UNSIGNED NULL AFTER id,
    ADD COLUMN period_id BIGINT UNSIGNED NULL AFTER subject_id,
    ADD COLUMN teacher_user_id BIGINT UNSIGNED NULL AFTER period_id,
    ADD COLUMN room_id BIGINT UNSIGNED NULL AFTER room,
    ADD COLUMN double_period_group VARCHAR(36) NULL AFTER room_id,
    ADD KEY idx_school_timetable_version_class (school_id, version_id, class_id, weekday, starts_at),
    ADD KEY idx_school_timetable_teacher_view (school_id, teacher_user_id, version_id, weekday, starts_at),
    ADD KEY idx_school_timetable_room_view (school_id, room_id, version_id, weekday, starts_at),
    ADD KEY idx_school_timetable_double_period (version_id, double_period_group),
    ADD CONSTRAINT fk_school_timetable_entry_version FOREIGN KEY (version_id) REFERENCES school_timetable_versions(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_school_timetable_entry_period FOREIGN KEY (period_id) REFERENCES school_timetable_periods(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_school_timetable_entry_teacher FOREIGN KEY (teacher_user_id) REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_school_timetable_entry_room FOREIGN KEY (room_id) REFERENCES school_timetable_rooms(id) ON DELETE SET NULL;

INSERT INTO school_timetable_versions
    (school_id, academic_year, term_name, version_number, title, status, is_legacy,
     effective_from, created_by, published_by, published_at, created_at)
SELECT entry.school_id, class.academic_year, 'Legacy schedule', 1, 'Legacy timetable',
       'published', 1, DATE(MIN(entry.published_at)), MIN(entry.created_by),
       MIN(entry.created_by), MAX(entry.published_at), MIN(entry.created_at)
FROM school_timetable_entries entry
JOIN school_classes class
  ON class.id = entry.class_id AND class.school_id = entry.school_id
WHERE entry.published_at IS NOT NULL
GROUP BY entry.school_id, class.academic_year;

UPDATE school_timetable_entries entry
JOIN school_classes class
  ON class.id = entry.class_id AND class.school_id = entry.school_id
JOIN school_timetable_versions version
  ON version.school_id = entry.school_id
 AND version.academic_year = class.academic_year
 AND version.term_name = 'Legacy schedule'
 AND version.is_legacy = 1
SET entry.version_id = version.id
WHERE entry.version_id IS NULL AND entry.published_at IS NOT NULL;

CREATE TABLE school_timetable_requirements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    term_id BIGINT UNSIGNED NOT NULL,
    class_id BIGINT UNSIGNED NOT NULL,
    subject_id BIGINT UNSIGNED NOT NULL,
    teacher_user_id BIGINT UNSIGNED NOT NULL,
    periods_per_week TINYINT UNSIGNED NOT NULL,
    double_periods TINYINT UNSIGNED NOT NULL DEFAULT 0,
    preferred_room_id BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_school_timetable_requirement (school_id, term_id, class_id, subject_id),
    KEY idx_school_timetable_requirement_class (school_id, term_id, class_id),
    KEY idx_school_timetable_requirement_teacher (school_id, term_id, teacher_user_id),
    CONSTRAINT fk_school_timetable_requirement_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_timetable_requirement_term FOREIGN KEY (term_id) REFERENCES school_terms(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_timetable_requirement_class FOREIGN KEY (class_id) REFERENCES school_classes(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_timetable_requirement_subject FOREIGN KEY (subject_id) REFERENCES school_subjects(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_timetable_requirement_teacher FOREIGN KEY (teacher_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_school_timetable_requirement_room FOREIGN KEY (preferred_room_id) REFERENCES school_timetable_rooms(id) ON DELETE SET NULL,
    CONSTRAINT fk_school_timetable_requirement_editor FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_school_timetable_requirement_periods CHECK (periods_per_week BETWEEN 1 AND 50),
    CONSTRAINT chk_school_timetable_requirement_doubles CHECK (double_periods <= 25)
) ENGINE=InnoDB;

CREATE TABLE school_timetable_unavailable_slots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    term_id BIGINT UNSIGNED NOT NULL,
    entity_type ENUM('teacher','class','room') NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    weekday TINYINT UNSIGNED NOT NULL,
    period_number TINYINT UNSIGNED NOT NULL,
    reason VARCHAR(180) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_school_timetable_unavailability (school_id, term_id, entity_type, entity_id, weekday, period_number),
    KEY idx_school_timetable_unavailable_term (school_id, term_id, weekday, period_number, entity_type),
    CONSTRAINT fk_school_timetable_unavailable_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_timetable_unavailable_term FOREIGN KEY (term_id) REFERENCES school_terms(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_timetable_unavailable_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_school_timetable_unavailable_weekday CHECK (weekday BETWEEN 1 AND 7)
) ENGINE=InnoDB;

CREATE TABLE school_timetable_edit_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    version_id BIGINT UNSIGNED NOT NULL,
    revision_number INT UNSIGNED NOT NULL,
    action_name VARCHAR(40) NOT NULL,
    snapshot_json LONGTEXT NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_school_timetable_edit_revision (version_id, revision_number),
    KEY idx_school_timetable_edit_history (school_id, version_id, revision_number),
    CONSTRAINT fk_school_timetable_edit_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_timetable_edit_version FOREIGN KEY (version_id) REFERENCES school_timetable_versions(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_timetable_edit_actor FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE school_timetable_substitutions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    entry_id BIGINT UNSIGNED NOT NULL,
    substitution_date DATE NOT NULL,
    original_teacher_user_id BIGINT UNSIGNED NULL,
    substitute_teacher_user_id BIGINT UNSIGNED NOT NULL,
    status ENUM('proposed','approved','cancelled') NOT NULL DEFAULT 'proposed',
    reason VARCHAR(500) NULL,
    proposed_by BIGINT UNSIGNED NULL,
    approved_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_school_timetable_substitution (school_id, entry_id, substitution_date),
    KEY idx_school_timetable_substitution_date (school_id, substitution_date, status),
    CONSTRAINT fk_school_timetable_substitution_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_timetable_substitution_entry FOREIGN KEY (entry_id) REFERENCES school_timetable_entries(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_timetable_substitution_original FOREIGN KEY (original_teacher_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_school_timetable_substitution_substitute FOREIGN KEY (substitute_teacher_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_school_timetable_substitution_proposer FOREIGN KEY (proposed_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_school_timetable_substitution_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
