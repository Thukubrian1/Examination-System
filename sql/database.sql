-- ============================================================
-- KYU Online Examination System — Full Database Setup
-- SSE 2304 | Run this once to create all tables and seed data
-- ============================================================

-- Create and select database
CREATE DATABASE IF NOT EXISTS examination_system
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE examination_system;

-- ============================================================
-- TABLE: users
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    full_name     VARCHAR(150)    NOT NULL,
    email         VARCHAR(200)    NOT NULL,
    password_hash VARCHAR(255)    NOT NULL,
    role          ENUM('student','lecturer','admin') NOT NULL DEFAULT 'student',
    reg_no        VARCHAR(50)     NULL,
    phone         VARCHAR(20)     NULL,
    department    VARCHAR(100)    NULL,
    is_active     TINYINT(1)      NOT NULL DEFAULT 1,
    last_login    DATETIME        NULL,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: tests
-- ============================================================
CREATE TABLE IF NOT EXISTS tests (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    lecturer_id        INT UNSIGNED NOT NULL,
    title                VARCHAR(200) NOT NULL,
    description          TEXT         NULL,
    duration_minutes     INT          NOT NULL DEFAULT 60,
    total_marks          INT          NOT NULL DEFAULT 0,
    pass_marks           INT          NOT NULL DEFAULT 0,
    start_time           DATETIME     NOT NULL,
    end_time             DATETIME     NOT NULL,
    is_active            TINYINT(1)   NOT NULL DEFAULT 1,
    randomize_questions  TINYINT(1)   NOT NULL DEFAULT 0,
    created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_lecturer (lecturer_id),
    CONSTRAINT fk_test_lecturer FOREIGN KEY (lecturer_id)
        REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: questions
-- ============================================================
CREATE TABLE IF NOT EXISTS questions (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    test_id        INT UNSIGNED NOT NULL,
    question_text  TEXT         NOT NULL,
    marks          INT          NOT NULL DEFAULT 1,
    question_order INT          NOT NULL DEFAULT 0,

    PRIMARY KEY (id),
    KEY idx_test (test_id),
    CONSTRAINT fk_question_test FOREIGN KEY (test_id)
        REFERENCES tests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: options
-- ============================================================
CREATE TABLE IF NOT EXISTS options (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    question_id   INT UNSIGNED NOT NULL,
    option_text   TEXT         NOT NULL,
    is_correct    TINYINT(1)   NOT NULL DEFAULT 0,

    PRIMARY KEY (id),
    KEY idx_question (question_id),
    CONSTRAINT fk_option_question FOREIGN KEY (question_id)
        REFERENCES questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: results
-- ============================================================
CREATE TABLE IF NOT EXISTS results (
    id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    student_id          INT UNSIGNED    NOT NULL,
    test_id             INT UNSIGNED    NOT NULL,
    score               INT             NOT NULL DEFAULT 0,
    total_marks         INT             NOT NULL DEFAULT 0,
    percentage          DECIMAL(5,2)    NOT NULL DEFAULT 0.00,
    passed              TINYINT(1)      NOT NULL DEFAULT 0,
    status              ENUM('in_progress','submitted','timed_out') NOT NULL DEFAULT 'in_progress',
    started_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    submitted_at        DATETIME        NULL,
    time_taken_seconds  INT             NOT NULL DEFAULT 0,

    PRIMARY KEY (id),
    UNIQUE KEY uq_student_test (student_id, test_id),
    KEY idx_test_results (test_id),
    CONSTRAINT fk_result_student FOREIGN KEY (student_id)
        REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_result_test FOREIGN KEY (test_id)
        REFERENCES tests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: student_answers
-- ============================================================
CREATE TABLE IF NOT EXISTS student_answers (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    result_id          INT UNSIGNED NOT NULL,
    question_id        INT UNSIGNED NOT NULL,
    selected_option_id INT UNSIGNED NOT NULL,
    is_correct         TINYINT(1)   NOT NULL DEFAULT 0,
    marks_awarded      INT          NOT NULL DEFAULT 0,

    PRIMARY KEY (id),
    UNIQUE KEY uq_result_question (result_id, question_id),
    KEY idx_result (result_id),
    CONSTRAINT fk_answer_result FOREIGN KEY (result_id)
        REFERENCES results(id) ON DELETE CASCADE,
    CONSTRAINT fk_answer_question FOREIGN KEY (question_id)
        REFERENCES questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================
-- SAFE ALTER: Add missing columns to existing tables
-- (Safe to run even if columns already exist — uses IF NOT EXISTS)
-- ============================================================

-- Add is_active to users if missing
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'users'
      AND COLUMN_NAME  = 'is_active'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add phone to users if missing
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'users'
      AND COLUMN_NAME  = 'phone'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE users ADD COLUMN phone VARCHAR(20) NULL AFTER reg_no',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add department to users if missing
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'users'
      AND COLUMN_NAME  = 'department'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE users ADD COLUMN department VARCHAR(100) NULL AFTER phone',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ============================================================
-- SEED DATA: Demo users (password = "password" for all)
-- ============================================================

INSERT IGNORE INTO users (full_name, email, password_hash, role, reg_no, department, is_active) VALUES

-- Admin
(
    'System Admin',
    'admin@kyu.ac.ke',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'admin',
    NULL,
    'IT Department',
    1
),

-- lecturers
(
    'Brian Ngugi',
    'b.ngugi@kyu.ac.ke',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'lecturer',
    NULL,
    'School of Pure and Applied Sciences',
    1
),
(
    'Grace Njeri',
    'g.njeri@kyu.ac.ke',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'lecturer',
    NULL,
    'School of Computing',
    1
),

-- Students
(
    'Brian Ngugi',
    'brianngugi@students.kyu.ac.ke',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'student',
    'KYU/2022/001',
    'School of Engineering',
    1
),
(
    'Brian Ochieng',
    'brian@students.kyu.ac.ke',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'student',
    'KYU/2022/002',
    'School of Engineering',
    1
),
(
    'Carol Auma',
    'carol@students.kyu.ac.ke',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'student',
    'KYU/2022/003',
    'School of Computing',
    1
),
(
    'David Kamau',
    'david@students.kyu.ac.ke',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'student',
    'KYU/2022/004',
    'School of Computing',
    1
);


-- ============================================================
-- SEED DATA: Sample test created by Brian Ngugi
-- ============================================================

SET @lecturer_id = (SELECT id FROM users WHERE email = 'b.ngugi@kyu.ac.ke' LIMIT 1);

INSERT IGNORE INTO tests
    (lecturer_id, title, description, duration_minutes, total_marks, pass_marks,
     start_time, end_time, is_active, randomize_questions)
VALUES (
    @lecturer_id,
    'Introduction to Software Engineering',
    'CAT 1 — Covers software development life cycle, requirements engineering, and system design principles.',
    45,
    10,
    6,
    DATE_SUB(NOW(), INTERVAL 1 DAY),
    DATE_ADD(NOW(), INTERVAL 7 DAY),
    1,
    0
);

SET @test_id = LAST_INSERT_ID();

-- Question 1
INSERT INTO questions (test_id, question_text, marks, question_order) VALUES
(@test_id, 'Which phase of the SDLC involves gathering and documenting what the system must do?', 1, 1);
SET @q1 = LAST_INSERT_ID();
INSERT INTO options (question_id, option_text, is_correct) VALUES
(@q1, 'Implementation',        0),
(@q1, 'Requirements Analysis', 1),
(@q1, 'Testing',               0),
(@q1, 'Maintenance',           0);

-- Question 2
INSERT INTO questions (test_id, question_text, marks, question_order) VALUES
(@test_id, 'What does UML stand for?', 1, 2);
SET @q2 = LAST_INSERT_ID();
INSERT INTO options (question_id, option_text, is_correct) VALUES
(@q2, 'Universal Modelling Language',  0),
(@q2, 'Unified Modelling Language',    1),
(@q2, 'Unique Modelling Language',     0),
(@q2, 'Uniform Modelling Language',    0);

-- Question 3
INSERT INTO questions (test_id, question_text, marks, question_order) VALUES
(@test_id, 'Which software development model delivers working software in increments?', 1, 3);
SET @q3 = LAST_INSERT_ID();
INSERT INTO options (question_id, option_text, is_correct) VALUES
(@q3, 'Waterfall Model', 0),
(@q3, 'V-Model',         0),
(@q3, 'Agile Model',     1),
(@q3, 'Spiral Model',    0);

-- Question 4
INSERT INTO questions (test_id, question_text, marks, question_order) VALUES
(@test_id, 'A use case diagram is used to describe:', 1, 4);
SET @q4 = LAST_INSERT_ID();
INSERT INTO options (question_id, option_text, is_correct) VALUES
(@q4, 'The internal structure of the database',     0),
(@q4, 'System interactions between users and the system', 1),
(@q4, 'The physical deployment of software',        0),
(@q4, 'The sequence of method calls',               0);

-- Question 5
INSERT INTO questions (test_id, question_text, marks, question_order) VALUES
(@test_id, 'Which of the following is a non-functional requirement?', 1, 5);
SET @q5 = LAST_INSERT_ID();
INSERT INTO options (question_id, option_text, is_correct) VALUES
(@q5, 'The system shall allow users to register',    0),
(@q5, 'The system shall process payments',           0),
(@q5, 'The system shall respond in under 2 seconds', 1),
(@q5, 'The system shall generate monthly reports',   0);

-- Question 6
INSERT INTO questions (test_id, question_text, marks, question_order) VALUES
(@test_id, 'What type of testing is performed without access to source code?', 1, 6);
SET @q6 = LAST_INSERT_ID();
INSERT INTO options (question_id, option_text, is_correct) VALUES
(@q6, 'White-box testing', 0),
(@q6, 'Unit testing',      0),
(@q6, 'Black-box testing', 1),
(@q6, 'Stress testing',    0);

-- Question 7
INSERT INTO questions (test_id, question_text, marks, question_order) VALUES
(@test_id, 'In object-oriented design, encapsulation refers to:', 1, 7);
SET @q7 = LAST_INSERT_ID();
INSERT INTO options (question_id, option_text, is_correct) VALUES
(@q7, 'Inheriting properties from a parent class',        0),
(@q7, 'Hiding internal details and exposing only what is necessary', 1),
(@q7, 'Creating multiple methods with the same name',     0),
(@q7, 'Splitting a class into multiple interfaces',       0);

-- Question 8
INSERT INTO questions (test_id, question_text, marks, question_order) VALUES
(@test_id, 'Which diagram shows the flow of control between objects over time?', 1, 8);
SET @q8 = LAST_INSERT_ID();
INSERT INTO options (question_id, option_text, is_correct) VALUES
(@q8, 'Class Diagram',    0),
(@q8, 'ER Diagram',       0),
(@q8, 'Sequence Diagram', 1),
(@q8, 'State Diagram',    0);

-- Question 9
INSERT INTO questions (test_id, question_text, marks, question_order) VALUES
(@test_id, 'Which of the following best describes a software prototype?', 1, 9);
SET @q9 = LAST_INSERT_ID();
INSERT INTO options (question_id, option_text, is_correct) VALUES
(@q9, 'The final tested version of the software',              0),
(@q9, 'A preliminary model used to explore requirements',      1),
(@q9, 'A document describing coding standards',                0),
(@q9, 'A backup copy of the production system',                0);

-- Question 10
INSERT INTO questions (test_id, question_text, marks, question_order) VALUES
(@test_id, 'The primary goal of software maintenance is to:', 1, 10);
SET @q10 = LAST_INSERT_ID();
INSERT INTO options (question_id, option_text, is_correct) VALUES
(@q10, 'Rewrite the system from scratch',          0),
(@q10, 'Modify the system after delivery to fix faults or improve performance', 1),
(@q10, 'Train users on how to use the software',   0),
(@q10, 'Document the original requirements',       0);


-- ============================================================
-- VERIFY
-- ============================================================
SELECT 'Setup complete!' AS status;
SELECT role, COUNT(*) AS count FROM users GROUP BY role;
SELECT title, total_marks, pass_marks FROM tests;