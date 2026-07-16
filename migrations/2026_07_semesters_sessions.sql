-- =====================================================================
-- Migration: add Semesters + Sessions ("Xiiso") to the attendance model
-- Run once against the existing admas_attendance database.
-- =====================================================================

USE admas_attendance;

-- ---------------------------------------------------------------------
-- SEMESTERS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS semesters (
  id                INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  academic_year_id  INT UNSIGNED NOT NULL,
  name              VARCHAR(50) NOT NULL,
  start_date        DATE NOT NULL,
  end_date          DATE NOT NULL,
  is_current        TINYINT(1) NOT NULL DEFAULT 0,
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_semesters_academic_year
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id),
  UNIQUE KEY uq_semester_name_per_year (academic_year_id, name)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- SESSIONS ("Xiiso")
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sessions (
  id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  semester_id     INT UNSIGNED NOT NULL,
  session_number  TINYINT UNSIGNED NOT NULL,
  type            ENUM('regular','midterm','final') NOT NULL DEFAULT 'regular',
  date            DATE NULL,
  CONSTRAINT fk_sessions_semester
    FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE CASCADE,
  UNIQUE KEY uq_session_number_per_semester (semester_id, session_number)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- ATTENDANCE: add session_id, replace the per-day unique key with a
-- per-session one. Old rows keep session_id = NULL and are left as-is.
-- ---------------------------------------------------------------------
ALTER TABLE attendance
  ADD COLUMN session_id INT UNSIGNED NULL AFTER course_id;

ALTER TABLE attendance
  ADD CONSTRAINT fk_attendance_session
    FOREIGN KEY (session_id) REFERENCES sessions(id);

ALTER TABLE attendance
  DROP INDEX uq_attendance_once_per_day,
  ADD UNIQUE KEY uq_attendance_once_per_session (student_id, course_id, session_id);

-- ---------------------------------------------------------------------
-- SETTINGS: current semester pointer
-- ---------------------------------------------------------------------
INSERT INTO settings (`key`, `value`)
  VALUES ('current_semester_id', '')
  ON DUPLICATE KEY UPDATE `value` = `value`;
