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
  ('university_rector'),
  ('head_academic'),
  ('registration'),
  ('dean'),
  ('lecturer'),
  ('student');

-- ---------------------------------------------------------------------
-- 2. FACULTIES
-- ---------------------------------------------------------------------
CREATE TABLE faculties (
  id                  INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  name                VARCHAR(150) NOT NULL,
  semesters_per_year  TINYINT UNSIGNED NOT NULL DEFAULT 3,  -- e.g. 3 for most faculties, 2 for Health; display-only (see migrations/2026_08_faculties_semesters_per_year.sql)
  total_semesters     TINYINT UNSIGNED NOT NULL DEFAULT 8,  -- whole program length in semesters (e.g. 4 years x 2/year = 8); drives the Semester dropdown options on semesters.php (see migrations/2026_08_faculties_total_semesters.sql)
  dean_user_id        INT UNSIGNED NULL,   -- FK added after users table exists
  created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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
  photo_path     VARCHAR(255) NULL,          -- filename only, under uploads/profile_photos/; NULL = use the initials-circle avatar
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
-- 3c. PAIRED DEVICES / QR LOGIN CHALLENGES
-- ---------------------------------------------------------------------
-- paired_devices: long-lived record of a phone "paired" to a user account
-- (created once when the user scans the Profile & Password pairing QR and
-- taps Confirm). device_token is stored HASHED (sha256) since it's a
-- 90-day bearer credential functionally equivalent to a password.
--
-- qr_login_challenges: short-lived, single-use tokens for both the
-- pairing flow and the later login-via-QR flow. Every state transition is
-- an atomic UPDATE ... WHERE status = '<expected>' so a replayed/duplicate
-- confirm can never succeed twice. challenge_token is stored PLAIN, same
-- as password_resets.code, since it's already visible on-screen in the QR
-- image and is single-use + short-lived (3 minutes).
CREATE TABLE paired_devices (
  id                  INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  user_id             INT UNSIGNED NOT NULL,
  device_token_hash   CHAR(64) NOT NULL,
  device_label        VARCHAR(150) NOT NULL DEFAULT '',
  user_agent          VARCHAR(255) NULL,
  paired_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at        DATETIME NULL,
  revoked_at          DATETIME NULL,
  CONSTRAINT fk_paired_devices_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_paired_devices_token_hash (device_token_hash),
  INDEX idx_paired_devices_user_active (user_id, revoked_at)
) ENGINE=InnoDB;

CREATE TABLE qr_login_challenges (
  id                     INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  purpose                ENUM('pair','login') NOT NULL,
  challenge_token        VARCHAR(64) NOT NULL,
  user_id                INT UNSIGNED NULL,
  device_id              INT UNSIGNED NULL,
  status                 ENUM('pending','confirmed','completed','expired','cancelled') NOT NULL DEFAULT 'pending',
  created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at             DATETIME NOT NULL,
  confirmed_at           DATETIME NULL,
  completed_at           DATETIME NULL,
  requesting_ip          VARCHAR(45) NULL,
  requesting_user_agent  VARCHAR(255) NULL,
  confirming_ip          VARCHAR(45) NULL,
  confirming_user_agent  VARCHAR(255) NULL,
  CONSTRAINT fk_qr_challenges_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_qr_challenges_device
    FOREIGN KEY (device_id) REFERENCES paired_devices(id) ON DELETE SET NULL,
  UNIQUE KEY uq_qr_challenges_token (challenge_token),
  INDEX idx_qr_challenges_status_expiry (status, expires_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3d. STAFF MESSAGES (internal chat between staff roles)
-- ---------------------------------------------------------------------
-- Simple direct-message thread between two users, used by messages.php —
-- a WhatsApp-style two-pane chat shared by University Rector / Head of
-- Academic Affairs / Dean / Lecturer / Registration Office. Students are
-- not part of this; enforced by messages.php's own require_role(), not
-- by this table.
CREATE TABLE messages (
  id           INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  sender_id    INT UNSIGNED NOT NULL,
  receiver_id  INT UNSIGNED NOT NULL,
  body         VARCHAR(2000) NOT NULL,
  is_read      TINYINT(1) NOT NULL DEFAULT 0,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_messages_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_messages_receiver FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_messages_conversation (sender_id, receiver_id, created_at),
  INDEX idx_messages_receiver_unread (receiver_id, is_read)
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
-- 5b. SEMESTERS  (a 3-month term within an academic year)
-- ---------------------------------------------------------------------
CREATE TABLE semesters (
  id                INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  academic_year_id  INT UNSIGNED NOT NULL,
  faculty_id        INT UNSIGNED NULL,              -- NULL = not yet assigned to a faculty (must be set via semesters.php before it can be marked current)
  context_department_id INT UNSIGNED NULL,          -- display-only note of which department this was created for — NOT scoping; the semester still applies to the whole faculty_id above. Never read by get_current_semester() or any scoping logic.
  name              VARCHAR(50) NOT NULL,           -- e.g. 'Semester 3'
  start_date        DATE NULL,                      -- optional reference dates only — no longer drives is_current
  end_date          DATE NULL,
  is_current        TINYINT(1) NOT NULL DEFAULT 0,   -- kept in sync with status = 'current' whenever status changes
  status            ENUM('waiting', 'current', 'ended') NOT NULL DEFAULT 'waiting', -- set by hand via semesters.php's Start/End/Waiting buttons, not derived from dates
  hidden_from_picker TINYINT(1) NOT NULL DEFAULT 0,   -- hides this semester from student/courses.php's Semester Box Picker only (a same-named duplicate across academic years) — never read by get_current_semester() or any scoping/write logic
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_semesters_academic_year
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id),
  CONSTRAINT fk_semesters_faculty
    FOREIGN KEY (faculty_id) REFERENCES faculties(id),
  CONSTRAINT fk_semesters_context_department
    FOREIGN KEY (context_department_id) REFERENCES departments(id) ON DELETE SET NULL,
  INDEX idx_semesters_academic_year (academic_year_id),
  UNIQUE KEY uq_semester_name_per_faculty_year (faculty_id, academic_year_id, name)
) ENGINE=InnoDB;

-- "Current" is set by hand (semesters.php's Start/End/Waiting buttons), not
-- derived from calendar dates. More than one semester can be "current" at
-- once, including within the same faculty (e.g. two concurrent batches) —
-- nothing here auto-clears another semester's status. A semester with
-- faculty_id IS NULL can never be marked current.

-- ---------------------------------------------------------------------
-- 5c. SESSIONS  ("Xiiso" — 12 numbered sessions per semester:
--     10 regular teaching sessions + Midterm (6) + Final (12))
-- ---------------------------------------------------------------------
CREATE TABLE sessions (
  id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  semester_id     INT UNSIGNED NOT NULL,
  session_number  TINYINT UNSIGNED NOT NULL,        -- 1-12
  type            ENUM('regular','midterm','final') NOT NULL DEFAULT 'regular',
  date            DATE NULL,                        -- assigned later by admin/lecturer
  CONSTRAINT fk_sessions_semester
    FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE CASCADE,
  UNIQUE KEY uq_session_number_per_semester (semester_id, session_number)
) ENGINE=InnoDB;

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
  lecturer_id    INT UNSIGNED NULL,               -- deprecated: who teaches a course is now per-semester, via course_offerings below. Kept unused after the Phase 2 migration/cleanup; not read by application code once course_offerings is live.
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
-- 7b. COURSE_OFFERINGS  (which lecturer teaches a course in a given
--     semester — a course is a reusable catalog entry; who teaches it can
--     change every semester, so this is tracked separately rather than as
--     a permanent column on courses)
-- ---------------------------------------------------------------------
CREATE TABLE course_offerings (
  id           INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  course_id    INT UNSIGNED NOT NULL,
  semester_id  INT UNSIGNED NOT NULL,
  lecturer_id  INT UNSIGNED NULL,                 -- NULL = unassigned for that semester+shift
  roster_department_id INT UNSIGNED NULL,         -- which department's students form THIS offering's roster; NULL = fall back to courses.department_id (the default, unchanged behavior — only set explicitly for a guest-faculty/cross-listed offering, see migrations/2026_08_course_offerings_roster_department.sql)
  shift        ENUM('morning','afternoon','weekend','any') NOT NULL DEFAULT 'any', -- 'any' = applies to every shift; part of the unique key below, so a course can have one offering per shift within the same semester (see migrations/2026_08_course_offerings_multi_shift.sql)
  start_date   DATE NULL,                         -- this course's actual teaching-period start within the semester; optional
  end_date     DATE NULL,                         -- and end — both set together, whenever known
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_offerings_course
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  CONSTRAINT fk_offerings_semester
    FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE CASCADE,
  CONSTRAINT fk_offerings_lecturer
    FOREIGN KEY (lecturer_id) REFERENCES lecturers(id) ON DELETE SET NULL,
  CONSTRAINT fk_offerings_roster_department
    FOREIGN KEY (roster_department_id) REFERENCES departments(id) ON DELETE SET NULL,
  UNIQUE KEY uq_course_semester_shift (course_id, semester_id, shift)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 7c. LECTURER_CHECKINS (Lecturer Check-In / Check-Out)
-- ---------------------------------------------------------------------
-- A lecturer's own arrival/departure log, recorded per (course, Xiiso
-- session) they actually teach — NOT the same thing as `attendance` above
-- (which records STUDENT presence). One row per session a lecturer checks
-- into; check_out_at stays NULL until they check out.
CREATE TABLE lecturer_checkins (
  id             INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  lecturer_id    INT UNSIGNED NOT NULL,
  course_id      INT UNSIGNED NOT NULL,
  session_id     INT UNSIGNED NOT NULL,
  check_in_at    DATETIME NOT NULL,
  check_out_at   DATETIME NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_checkins_lecturer FOREIGN KEY (lecturer_id) REFERENCES lecturers(id) ON DELETE CASCADE,
  CONSTRAINT fk_checkins_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  CONSTRAINT fk_checkins_session FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
  UNIQUE KEY uq_checkin_once_per_session (lecturer_id, course_id, session_id),
  INDEX idx_checkins_lecturer_date (lecturer_id, check_in_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 8. STUDENTS
-- ---------------------------------------------------------------------
CREATE TABLE students (
  id                INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  student_no        VARCHAR(20)  NOT NULL UNIQUE,   -- e.g. ADM-2301
  first_name        VARCHAR(60)  NOT NULL,
  father_name       VARCHAR(60)  NOT NULL,
  grandfather_name  VARCHAR(60)  NULL,              -- optional: not every real name has 3 parts
  full_name         VARCHAR(150) GENERATED ALWAYS AS (TRIM(CONCAT_WS(' ', first_name, father_name, grandfather_name))) STORED,
  user_id           INT UNSIGNED NOT NULL UNIQUE,   -- 1:1 login account
  academic_year_id  INT UNSIGNED NOT NULL,
  faculty_id        INT UNSIGNED NOT NULL,
  department_id     INT UNSIGNED NOT NULL,
  level             TINYINT UNSIGNED NOT NULL,      -- deprecated: superseded by semester_id below. Kept unused (no reliable 1-5 -> semesters.id mapping exists to auto-backfill it); not read by application code once semester_id is live.
  semester_id       INT UNSIGNED NULL,              -- which semester (of the student's own faculty's track) they're on; NULL = not yet assigned (pre-migration students, until an admin edits the record)
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
  CONSTRAINT fk_students_semester
    FOREIGN KEY (semester_id) REFERENCES semesters(id),
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
  session_id          INT UNSIGNED NULL,       -- Xiiso this mark belongs to; NULL = legacy pre-semester row
  academic_year_id    INT UNSIGNED NOT NULL,
  shift               ENUM('morning','afternoon','weekend') NOT NULL,
  attendance_date     DATE NOT NULL,           -- denormalized from sessions.date at save time (see semester_helpers.php)
  status              ENUM('present','absent') NOT NULL,
  recorded_by_user_id INT UNSIGNED NOT NULL,   -- the lecturer who marked it
  created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_attendance_student
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_attendance_course
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  CONSTRAINT fk_attendance_session
    FOREIGN KEY (session_id) REFERENCES sessions(id),
  CONSTRAINT fk_attendance_academic_year
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id),
  CONSTRAINT fk_attendance_recorder
    FOREIGN KEY (recorded_by_user_id) REFERENCES users(id),
  -- one attendance record per student, per course, per Xiiso session
  -- (legacy rows with session_id NULL are not covered by this constraint —
  -- MySQL treats NULL as distinct in a UNIQUE index, so old date-only rows
  -- remain valid history and are never touched by new session-based inserts)
  UNIQUE KEY uq_attendance_once_per_session (student_id, course_id, session_id)
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
  ('min_attendance_pct', '7.5'),
  ('current_semester_id', '');

-- ---------------------------------------------------------------------
-- 12. ROLE ASSIGNMENTS  (audit trail of who appointed whom)
-- ---------------------------------------------------------------------
CREATE TABLE role_assignments (
  id            INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  user_id       INT UNSIGNED NOT NULL,
  role_id       TINYINT UNSIGNED NOT NULL,
  faculty_id    INT UNSIGNED NULL,          -- only used when role = 'dean'
  assigned_by   INT UNSIGNED NOT NULL,      -- university_rector user who appointed
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

-- Default University Rector
-- Password: Admin@2026  (hash generated with PHP password_hash(), PASSWORD_DEFAULT)
INSERT INTO users (username, password_hash, full_name, email, role_id, status)
VALUES ('admin01', '$2y$10$VsLCKQeu9sg46LtTjDsAHOdXTRMWWz3tI7pS/a531c5BA5Cbmo1qe', 'Sakariye S. Nuor',
        'admin@admas.edu.so',
        (SELECT id FROM roles WHERE name = 'university_rector'), 'active');
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
INSERT INTO students (student_no, first_name, father_name, user_id, academic_year_id, faculty_id, department_id, level, shift)
VALUES ('ADM-2601', 'Student', 'Test', @student_user_id,
        (SELECT id FROM academic_years WHERE is_current = 1), @faculty_id, @dept_id, 1, 'morning');

-- =====================================================================
-- USEFUL QUERIES (for the Reports and Notifications modules)
-- =====================================================================

-- Attendance score per student per course (out of 10 — 1 point per Present
-- *regular* Xiiso session; Midterm/Final never count; see
-- includes/attendance_helpers.php's ATTENDANCE_MAX_SCORE):
-- SELECT student_id, course_id,
--        LEAST(10, SUM(a.status = 'present')) AS attendance_pct
-- FROM attendance a
-- JOIN sessions sess ON sess.id = a.session_id AND sess.type = 'regular'
-- WHERE course_id = ? AND sess.semester_id = ?
-- GROUP BY student_id, course_id;

-- Students below the current threshold (drives the Notifications module):
-- SELECT s.id, s.full_name, a.course_id,
--        LEAST(10, SUM(a.status = 'present')) AS attendance_pct
-- FROM attendance a
-- JOIN students s ON s.id = a.student_id
-- JOIN sessions sess ON sess.id = a.session_id AND sess.type = 'regular'
-- WHERE sess.semester_id = ?  -- that faculty's current semester
-- GROUP BY s.id, a.course_id
-- HAVING attendance_pct < (SELECT value FROM settings WHERE `key`='min_attendance_pct');
