-- =====================================================================
-- Migration: Lecturer Check-In / Check-Out
--
-- Adds:
--   - lecturer_checkins table — a lecturer's own arrival/departure log,
--     recorded per (course, Xiiso session) they actually teach, NOT the
--     same thing as the existing `attendance` table (which records
--     STUDENT presence). One row per session a lecturer checks into;
--     check_out_at stays NULL until they check out.
--
-- Run once against the existing admas_attendance database.
-- =====================================================================
USE admas_attendance;

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
