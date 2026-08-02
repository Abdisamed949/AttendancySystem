-- Multi-Faculty Course Offerings, Phase 0.
--
-- Lets a course_offerings row explicitly say which department's students
-- form its roster, instead of always implicitly falling back to the
-- course's own catalog department (courses.department_id). Needed because
-- a course can now be cross-listed into a DIFFERENT faculty's own
-- semester track (see admin/course_offerings_search.php) — for that
-- guest-faculty offering, the course's own catalog department lives in
-- the wrong faculty entirely and would pull the wrong students if used
-- as a roster fallback.
--
-- Nullable, ON DELETE SET NULL (same convention as
-- semesters.context_department_id): every existing offering keeps this
-- NULL and falls back to courses.department_id exactly as it does today
-- (see get_xiiso_grid_data() in includes/attendance_helpers.php) — zero
-- behavior change for any existing home-faculty offering.

ALTER TABLE course_offerings
  ADD COLUMN roster_department_id INT UNSIGNED NULL AFTER lecturer_id,
  ADD CONSTRAINT fk_offerings_roster_department
    FOREIGN KEY (roster_department_id) REFERENCES departments(id) ON DELETE SET NULL;
