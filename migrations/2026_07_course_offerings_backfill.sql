-- =====================================================================
-- Migration: Backfill course_offerings from courses.lecturer_id
--
-- Run only after 2026_07_faculty_scoped_semesters_and_offerings.sql has
-- been applied AND at least some faculties have a current semester set
-- via semesters.php (the tier-2 fallback below depends on it).
--
-- Two-tier rule, safest first:
--   Tier 1: a course whose attendance history (via sessions.semester_id)
--           maps to exactly one semester, AND that semester already
--           belongs to the course's own faculty (via department_id) —
--           i.e. trustworthy historical signal, not a cross-faculty
--           mismatch left over from the old single-global-semester model.
--   Tier 2: everything else falls back to the course's own faculty's
--           *current* semester, if that faculty has one set.
-- Courses whose faculty still has no current semester at backfill time
-- get no row at all — left for manual assignment via the "Manage
-- Offerings" screen (admin/courses.php) rather than guessed. Run the
-- verification SELECT at the bottom to see which courses still need it.
--
-- courses.lecturer_id itself is left untouched — dropped only in a later
-- cleanup migration, once every course has an offering and no code reads
-- the column anymore.
-- =====================================================================
USE admas_attendance;

-- Tier 1
INSERT INTO course_offerings (course_id, semester_id, lecturer_id)
SELECT c.id, x.semester_id, c.lecturer_id
FROM courses c
JOIN departments d ON d.id = c.department_id
JOIN (
    SELECT a.course_id, ses.semester_id
    FROM attendance a
    JOIN sessions ses ON ses.id = a.session_id
    GROUP BY a.course_id
    HAVING COUNT(DISTINCT ses.semester_id) = 1
) x ON x.course_id = c.id
JOIN semesters se ON se.id = x.semester_id
WHERE se.faculty_id = d.faculty_id;

-- Tier 2
INSERT INTO course_offerings (course_id, semester_id, lecturer_id)
SELECT c.id, se.id, c.lecturer_id
FROM courses c
JOIN departments d ON d.id = c.department_id
JOIN semesters se ON se.faculty_id = d.faculty_id AND se.is_current = 1
WHERE c.id NOT IN (SELECT course_id FROM course_offerings);

-- Verification: courses still without any offering (need manual
-- assignment via admin/courses.php's "Manage Offerings" before
-- courses.lecturer_id can be safely dropped).
SELECT c.id, c.code, c.name, d.faculty_id
FROM courses c
JOIN departments d ON d.id = c.department_id
WHERE c.id NOT IN (SELECT course_id FROM course_offerings)
ORDER BY d.faculty_id, c.code;
