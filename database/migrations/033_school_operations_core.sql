CREATE TABLE IF NOT EXISTS school_subjects (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    code VARCHAR(30) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_school_subjects (school_id, code, name),
    KEY idx_school_subjects_school (school_id),
    CONSTRAINT fk_school_subjects_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS class_subjects (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    class_id BIGINT UNSIGNED NOT NULL,
    subject_id BIGINT UNSIGNED NOT NULL,
    teacher_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_class_subjects (class_id, subject_id),
    KEY idx_class_subjects_school (school_id),
    CONSTRAINT fk_class_subjects_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_class_subjects_class FOREIGN KEY (class_id) REFERENCES school_classes(id) ON DELETE CASCADE,
    CONSTRAINT fk_class_subjects_subject FOREIGN KEY (subject_id) REFERENCES school_subjects(id) ON DELETE CASCADE,
    CONSTRAINT fk_class_subjects_teacher FOREIGN KEY (teacher_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS student_attendance (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    class_id BIGINT UNSIGNED NULL,
    attendance_date DATE NOT NULL,
    status ENUM('present','absent','late','excused') NOT NULL DEFAULT 'present',
    remarks VARCHAR(255) NULL,
    recorded_by BIGINT UNSIGNED NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_student_attendance_date (school_id, student_id, attendance_date),
    KEY idx_student_attendance_school_date (school_id, attendance_date),
    CONSTRAINT fk_attendance_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_attendance_student FOREIGN KEY (student_id) REFERENCES school_students(id) ON DELETE CASCADE,
    CONSTRAINT fk_attendance_class FOREIGN KEY (class_id) REFERENCES school_classes(id) ON DELETE SET NULL,
    CONSTRAINT fk_attendance_recorder FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS school_announcements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    author_user_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    body TEXT NOT NULL,
    audience ENUM('all','staff','parents','students','teachers') NOT NULL DEFAULT 'all',
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_school_announcements_school (school_id, published_at),
    CONSTRAINT fk_school_announcement_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_announcement_author FOREIGN KEY (author_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS school_support_tickets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    subject VARCHAR(180) NOT NULL,
    body TEXT NOT NULL,
    status ENUM('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
    priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_school_support_status (school_id, status),
    CONSTRAINT fk_school_ticket_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_ticket_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS school_support_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_id BIGINT UNSIGNED NOT NULL,
    sender_user_id BIGINT UNSIGNED NOT NULL,
    message TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_school_support_messages_ticket (ticket_id, created_at),
    CONSTRAINT fk_school_support_message_ticket FOREIGN KEY (ticket_id) REFERENCES school_support_tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_school_support_message_sender FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS school_fee_structures (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    due_day TINYINT UNSIGNED NOT NULL DEFAULT 10,
    description VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_school_fee_structures_school (school_id),
    CONSTRAINT fk_fee_structure_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS school_invoices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    fee_structure_id BIGINT UNSIGNED NULL,
    invoice_number VARCHAR(60) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status ENUM('draft','issued','partial','paid','overdue') NOT NULL DEFAULT 'issued',
    due_date DATE NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_school_invoice_number (school_id, invoice_number),
    KEY idx_school_invoices_student (school_id, student_id),
    CONSTRAINT fk_invoice_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_invoice_student FOREIGN KEY (student_id) REFERENCES school_students(id) ON DELETE CASCADE,
    CONSTRAINT fk_invoice_fee_structure FOREIGN KEY (fee_structure_id) REFERENCES school_fee_structures(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS school_payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    invoice_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_method ENUM('cash','bank_transfer','mobile_money','card','offline') NOT NULL DEFAULT 'cash',
    payment_reference VARCHAR(80) NULL,
    received_by BIGINT UNSIGNED NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_school_payments_invoice (invoice_id),
    KEY idx_school_payments_student (student_id),
    CONSTRAINT fk_payment_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_payment_invoice FOREIGN KEY (invoice_id) REFERENCES school_invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_payment_student FOREIGN KEY (student_id) REFERENCES school_students(id) ON DELETE CASCADE,
    CONSTRAINT fk_payment_receiver FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS school_assessments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    class_id BIGINT UNSIGNED NOT NULL,
    subject_id BIGINT UNSIGNED NOT NULL,
    assessment_name VARCHAR(120) NOT NULL,
    term_name VARCHAR(50) NOT NULL,
    total_marks DECIMAL(7,2) NOT NULL DEFAULT 100.00,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_school_assessments_class (school_id, class_id),
    CONSTRAINT fk_assessment_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_assessment_class FOREIGN KEY (class_id) REFERENCES school_classes(id) ON DELETE CASCADE,
    CONSTRAINT fk_assessment_subject FOREIGN KEY (subject_id) REFERENCES school_subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS assessment_results (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    assessment_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    score DECIMAL(7,2) NOT NULL,
    grade VARCHAR(10) NULL,
    remarks VARCHAR(255) NULL,
    recorded_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_assessment_result_student (assessment_id, student_id),
    KEY idx_assessment_results_school (school_id),
    CONSTRAINT fk_result_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_result_assessment FOREIGN KEY (assessment_id) REFERENCES school_assessments(id) ON DELETE CASCADE,
    CONSTRAINT fk_result_student FOREIGN KEY (student_id) REFERENCES school_students(id) ON DELETE CASCADE,
    CONSTRAINT fk_result_teacher FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
