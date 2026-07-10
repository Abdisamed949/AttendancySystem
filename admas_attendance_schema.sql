-- =====================================================================
-- ADMAS UNIVERSITY ATTENDANCE MANAGEMENT SYSTEM
-- Database Schema (MySQL 8.0+)
-- Matches CLAUDE.md §6 and Chapter Four System Design
-- =====================================================================

CREATE DATABASE IF NOT EXISTS admas_attendance
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE admas_attendance;

-- ---------------------------------------------------------------------
-- 1. ROLES  (fixed lookup list — do not let end users edit this table)
-- ---------------------------------------------------------------------
CREATE TABLE roles (
  id   TINYINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(30) NOT NULL UNIQUE
) ENGINE=InnoDB;

INSERT INTO roles (name) VALUES
  ('system_admin'),
  ('head_academic'),
  ('registration'),
  ('dean'),
  ('lecturer'),
  ('student');

-- ---------------------------------------------------------------------
-- 2. FACULTIES
-- ---------------------------------------------------------------------
CREATE TABLE faculties (
  id            INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  name          VARCHAR(150) NOT NULL,
  dean_user_id  INT UNSIGNED NULL,   -- FK added after users table exists
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3. USERS  (every login account, regardless of role)
-- ---------------------------------------------------------------------
CREATE TABLE users (
  id             INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  username       VARCHAR(60)  NOT NULL UNIQUE,
  password_hash  VARCHAR(255) NOT NULL,      -- use PHP password_hash()
  full_name      VARCHAR(150) NOT NULL,
  email          VARCHAR(150) NULL UNIQUE,
  role_id        TINYINT UNSIGNED NOT NULL,
  faculty_id     INT UNSIGNED NULL,          -- only set when role = 'dean'
  status         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  must_change_password TINYINT(1) NOT NULL DEFAULT 1, -- forces a password change on first login; 0 once the user has set their own
  last_login_at  DATETIME NULL,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_role
    FOREIGN KEY (role_id) REFERENCES roles(id),
  CONSTRAINT fk_users_faculty
    FOREIGN KEY (faculty_id) REFERENCES faculties(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Now that users exists, link faculties.dean_user_id
ALTER TABLE faculties
  ADD CONSTRAINT fk_faculties_dean
    FOREIGN KEY (dean_user_id) REFERENCES users(id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------
-- 3b. PASSWORD RESETS (Forgot Password — 6-digit email codes)
-- ---------------------------------------------------------------------
CREATE TABLE password_resets (
  id          INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  code        VARCHAR(6) NOT NULL,
  expires_at  DATETIME NOT NULL,
  used        TINYINT(1) NOT NULL DEFAULT 0,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_password_resets_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_password_resets_user_code (user_id, code, used)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 4. DEPARTMENTS
-- ---------------------------------------------------------------------
CREATE TABLE departments (
  id          INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  code        VARCHAR(20)  NOT NULL,
  name        VARCHAR(150) NOT NULL,
  faculty_id  INT UNSIGNED NOT NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_departments_faculty
    FOREIGN KEY (faculty_id) REFERENCES faculties(id) ON DELETE CASCADE,
  UNIQUE KEY uq_dept_code_per_faculty (faculty_id, code)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 5. ACADEMIC YEARS
-- ---------------------------------------------------------------------
CREATE TABLE academic_years (
  id         INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  label      VARCHAR(20) NOT NULL UNIQUE,   -- e.g. '2025/2026'
  is_current TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB;

-- Only one academic year may be "current" at a time — enforce in application
-- logic (set all rows to 0, then set the chosen one to 1, inside a transaction).

-- ---------------------------------------------------------------------
-- 6. LECTURERS
-- ---------------------------------------------------------------------
CREATE TABLE lecturers (
  id             INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  staff_no       VARCHAR(20)  NOT NULL UNIQUE,
  full_name      VARCHAR(150) NOT NULL,
  user_id        INT UNSIGNED NOT NULL UNIQUE,   -- 1:1 login account
  department_id  INT UNSIGNED NOT NULL,
  status         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_lecturers_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_lecturers_department
    FOREIGN KEY (department_id) REFERENCES departments(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 7. COURSES
-- ---------------------------------------------------------------------
CREATE TABLE courses (
  id             INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  code           VARCHAR(20)  NOT NULL,
  name           VARCHAR(150) NOT NULL,
  department_id  INT UNSIGNED NOT NULL,
  lecturer_id    INT UNSIGNED NULL,
  credit_hours   TINYINT UNSIGNED NOT NULL DEFAULT 3,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_courses_department
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  CONSTRAINT fk_courses_lecturer
    FOREIGN KEY (lecturer_id) REFERENCES lecturers(id) ON DELETE SET NULL,
  -- Same course code can exist in different departments/faculties —
  -- uniqueness is enforced per department, not globally.
  UNIQUE KEY uq_course_code_per_department (department_id, code)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 8. STUDENTS
-- ---------------------------------------------------------------------
CREATE TABLE students (
  id                INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  student_no        VARCHAR(20)  NOT NULL UNIQUE,   -- e.g. ADM-2301
  full_name         VARCHAR(150) NOT NULL,
  user_id           INT UNSIGNED NOT NULL UNIQUE,   -- 1:1 login account
  academic_year_id  INT UNSIGNED NOT NULL,
  faculty_id        INT UNSIGNED NOT NULL,
  department_id     INT UNSIGNED NOT NULL,
  level             TINYINT UNSIGNED NOT NULL,      -- 1-5
  shift             ENUM('morning','afternoon','weekend') NOT NULL,
  status            ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_students_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_students_academic_year
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id),
  CONSTRAINT fk_students_faculty
    FOREIGN KEY (faculty_id) REFERENCES faculties(id),
  CONSTRAINT fk_students_department
    FOREIGN KEY (department_id) REFERENCES departments(id),
  INDEX idx_students_scope (faculty_id, department_id, academic_year_id)
) ENGINE=InnoDB;

-- Students can enroll in multiple courses — join table:
CREATE TABLE course_enrollments (
  id          INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  student_id  INT UNSIGNED NOT NULL,
  course_id   INT UNSIGNED NOT NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_enroll_student
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_enroll_course
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  UNIQUE KEY uq_student_course (student_id, course_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 9. ATTENDANCE
-- ---------------------------------------------------------------------
CREATE TABLE attendance (
  id                  BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  student_id          INT UNSIGNED NOT NULL,
  course_id           INT UNSIGNED NOT NULL,
  academic_year_id    INT UNSIGNED NOT NULL,
  shift               ENUM('morning','afternoon','weekend') NOT NULL,
  attendance_date     DATE NOT NULL,
  status              ENUM('present','absent','late','excused') NOT NULL,
  recorded_by_user_id INT UNSIGNED NOT NULL,   -- the lecturer who marked it
  created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_attendance_student
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_attendance_course
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  CONSTRAINT fk_attendance_academic_year
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id),
  CONSTRAINT fk_attendance_recorder
    FOREIGN KEY (recorded_by_user_id) REFERENCES users(id),
  -- one attendance record per student, per course, per exact date
  UNIQUE KEY uq_attendance_once_per_day (student_id, course_id, attendance_date)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 10. NOTIFICATIONS  (low-attendance alerts, in-app only)
-- ---------------------------------------------------------------------
CREATE TABLE notifications (
  id                  INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  student_id          INT UNSIGNED NOT NULL,
  course_id           INT UNSIGNED NOT NULL,
  attendance_pct      DECIMAL(5,2) NOT NULL,   -- e.g. 62.00
  threshold_at_time   DECIMAL(5,2) NOT NULL,   -- e.g. 75.00
  is_read             TINYINT(1) NOT NULL DEFAULT 0,
  created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notif_student
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_notif_course
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 11. SETTINGS  (single-row key/value store)
-- ---------------------------------------------------------------------
CREATE TABLE settings (
  `key`   VARCHAR(60) PRIMARY KEY,
  `value` VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

INSERT INTO settings (`key`, `value`) VALUES
  ('university_name', 'ADMAS University'),
  ('campus', 'Garoowe Campus'),
  ('contact_email', 'info@admas.edu.so'),
  ('contact_phone', '+252 90 555 0142'),
  ('current_academic_year_id', '1'),
  ('min_attendance_pct', '75');

-- ---------------------------------------------------------------------
-- 12. ROLE ASSIGNMENTS  (audit trail of who appointed whom)
-- ---------------------------------------------------------------------
CREATE TABLE role_assignments (
  id            INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  user_id       INT UNSIGNED NOT NULL,
  role_id       TINYINT UNSIGNED NOT NULL,
  faculty_id    INT UNSIGNED NULL,          -- only used when role = 'dean'
  assigned_by   INT UNSIGNED NOT NULL,      -- system_admin user who appointed
  assigned_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ra_user    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ra_role    FOREIGN KEY (role_id) REFERENCES roles(id),
  CONSTRAINT fk_ra_faculty FOREIGN KEY (faculty_id) REFERENCES faculties(id) ON DELETE SET NULL,
  CONSTRAINT fk_ra_admin   FOREIGN KEY (assigned_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- =====================================================================
-- SEED DATA — enough to log in and start testing
-- =====================================================================

INSERT INTO academic_years (label, is_current) VALUES ('2025/2026', 1);

-- Default System Administrator
-- Password: Admin@2026  (hash generated with PHP password_hash(), PASSWORD_DEFAULT)
INSERT INTO users (username, password_hash, full_name, email, role_id, status)
VALUES ('admin01', '$2y$10$VsLCKQeu9sg46LtTjDsAHOdXTRMWWz3tI7pS/a531c5BA5Cbmo1qe', 'Sakariye S. Nuor',
        'admin@admas.edu.so',
        (SELECT id FROM roles WHERE name = 'system_admin'), 'active');
SET @admin_id = LAST_INSERT_ID();

-- Sample Faculty + Department to start building against
INSERT INTO faculties (name) VALUES ('Engineering & IT');
SET @faculty_id = LAST_INSERT_ID();
INSERT INTO departments (code, name, faculty_id)
VALUES ('CS', 'Computer Science', @faculty_id);
SET @dept_id = LAST_INSERT_ID();

-- =====================================================================
-- TEST ACCOUNTS — one per role (all passwords hashed with PHP password_hash())
-- =====================================================================

-- head_academic — username: headacad01 / password: HeadAcad@2026
INSERT INTO users (username, password_hash, full_name, email, role_id, status)
VALUES ('headacad01', '$2y$10$/Kwmuh6IzFSpJFKoDPEgTu7YlEQdIpLKIi/WWs3rIeQogDvaNylKa',
        'Head Academic Test', 'headacad01@admas.edu.so',
        (SELECT id FROM roles WHERE name = 'head_academic'), 'active');

-- registration — username: registrar01 / password: Registrar@2026
INSERT INTO users (username, password_hash, full_name, email, role_id, status)
VALUES ('registrar01', '$2y$10$HrRpeIR4HJmmfMvywigaXe/C5HKmDFlRcYQtcSlP1MMAw8NnFwyky',
        'Registration Test', 'registrar01@admas.edu.so',
        (SELECT id FROM roles WHERE name = 'registration'), 'active');

-- dean — username: dean01 / password: Dean@2026 (assigned to Engineering & IT faculty)
INSERT INTO users (username, password_hash, full_name, email, role_id, faculty_id, status)
VALUES ('dean01', '$2y$10$p4amDcLpQznvx5cnsSy4Cugf/N04haR38kC4Fy0a3ZLLSzB5aae.C',
        'Dean Test', 'dean01@admas.edu.so',
        (SELECT id FROM roles WHERE name = 'dean'), @faculty_id, 'active');
SET @dean_id = LAST_INSERT_ID();
UPDATE faculties SET dean_user_id = @dean_id WHERE id = @faculty_id;

-- lecturer — username: lecturer01 / password: Lecturer@2026
INSERT INTO users (username, password_hash, full_name, email, role_id, status)
VALUES ('lecturer01', '$2y$10$TWI7NLri4mnZqDgeMmFyR.CXCdXijleixu5U9ETojNAldYvXTyXEG',
        'Lecturer Test', 'lecturer01@admas.edu.so',
        (SELECT id FROM roles WHERE name = 'lecturer'), 'active');
SET @lecturer_user_id = LAST_INSERT_ID();
INSERT INTO lecturers (staff_no, full_name, user_id, department_id)
VALUES ('STF-0001', 'Lecturer Test', @lecturer_user_id, @dept_id);

-- student — username: student01 / password: Student@2026
INSERT INTO users (username, password_hash, full_name, email, role_id, status)
VALUES ('student01', '$2y$10$OYwvkyykHB5R3NVSONHSz.QRA5BKpYFXmCE7akFRD1b2O1wX1z4By',
        'Student Test', 'student01@admas.edu.so',
        (SELECT id FROM roles WHERE name = 'student'), 'active');
SET @student_user_id = LAST_INSERT_ID();
INSERT INTO students (student_no, full_name, user_id, academic_year_id, faculty_id, department_id, level, shift)
VALUES ('ADM-2601', 'Student Test', @student_user_id,
        (SELECT id FROM academic_years WHERE is_current = 1), @faculty_id, @dept_id, 1, 'morning');

-- =====================================================================
-- USEFUL QUERIES (for the Reports and Notifications modules)
-- =====================================================================

-- Attendance % per student per course (use in Reports + Notifications):
-- SELECT student_id, course_id,
--        ROUND(100 * SUM(status = 'present') / COUNT(*), 2) AS attendance_pct
-- FROM attendance
-- WHERE course_id = ? AND academic_year_id = ?
-- GROUP BY student_id, course_id;

-- Students below the current threshold (drives the Notifications module):
-- SELECT s.id, s.full_name, a.course_id,
--        ROUND(100 * SUM(a.status = 'present') / COUNT(*), 2) AS attendance_pct
-- FROM attendance a
-- JOIN students s ON s.id = a.student_id
-- WHERE a.academic_year_id = (SELECT value FROM settings WHERE `key`='current_academic_year_id')
-- GROUP BY s.id, a.course_id
-- HAVING attendance_pct < (SELECT value FROM settings WHERE `key`='min_attendance_pct');
