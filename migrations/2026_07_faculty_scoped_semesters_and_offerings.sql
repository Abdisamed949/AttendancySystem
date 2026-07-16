-- =====================================================================
-- Migration: Faculty-scoped semesters + course_offerings + student semester
--
-- Adds:
--   - semesters.faculty_id (nullable FK to faculties) — "current" becomes
--     a per-faculty concept instead of one global flag. Existing rows keep
--     faculty_id = NULL until assigned via semesters.php; a NULL-faculty
--     semester can never be marked current going forward.
--   - course_offerings (course_id + semester_id + lecturer_id) — replaces
--     the permanent courses.lecturer_id as the source of "who teaches this
--     course, in which semester." courses.lecturer_id is left in place for
--     now; it is backfilled into course_offerings by a follow-up migration
--     (2026_07_course_offerings_backfill.sql) and only dropped once no
--     application code reads it anymore.
--   - students.semester_id (nullable FK to semesters) — replaces
--     students.level going forward. students.level is left in place,
--     unused: there is no reliable formula mapping an existing 1-5 level
--     value to a real semesters row, so it is not auto-backfilled.
--
-- Run once against the existing admas_attendance database.
-- =====================================================================
USE admas_attendance;

-- ---------------------------------------------------------------------
-- SEMESTERS: add faculty_id
-- ---------------------------------------------------------------------
ALTER TABLE semesters
  ADD COLUMN faculty_id INT UNSIGNED NULL AFTER academic_year_id,
  ADD CONSTRAINT fk_semesters_faculty
    FOREIGN KEY (faculty_id) REFERENCES faculties(id);

-- The old uq_semester_name_per_year (academic_year_id, name) is the only
-- index supporting fk_semesters_academic_year, so InnoDB won't let it be
-- dropped until a replacement index covering academic_year_id exists.
-- Add a plain index for that FK first, then the real replacement unique
-- key (which itself becomes the supporting index for fk_semesters_faculty,
-- since it leads with faculty_id), then drop the old unique key last.
ALTER TABLE semesters ADD INDEX idx_semesters_academic_year (academic_year_id);
ALTER TABLE semesters ADD UNIQUE KEY uq_semester_name_per_faculty_year (faculty_id, academic_year_id, name);
ALTER TABLE semesters DROP INDEX uq_semester_name_per_year;

-- ---------------------------------------------------------------------
-- COURSE_OFFERINGS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS course_offerings (
  id           INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  course_id    INT UNSIGNED NOT NULL,
  semester_id  INT UNSIGNED NOT NULL,
  lecturer_id  INT UNSIGNED NULL,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_offerings_course
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  CONSTRAINT fk_offerings_semester
    FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE CASCADE,
  CONSTRAINT fk_offerings_lecturer
    FOREIGN KEY (lecturer_id) REFERENCES lecturers(id) ON DELETE SET NULL,
  UNIQUE KEY uq_course_per_semester (course_id, semester_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- STUDENTS: add semester_id (level stays, unused — see header note)
-- ---------------------------------------------------------------------
ALTER TABLE students
  ADD COLUMN semester_id INT UNSIGNED NULL AFTER level,
  ADD CONSTRAINT fk_students_semester
    FOREIGN KEY (semester_id) REFERENCES semesters(id);
