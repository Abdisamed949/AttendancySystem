-- =====================================================================
-- Migration: semesters.context_department_id (display-only, not scoping)
--
-- Adds:
--   - semesters.context_department_id INT UNSIGNED NULL, FK -> departments(id)
--
-- This is a lightweight "note" of which department a Dean/Admin had in
-- mind when creating a semester — e.g. they were really thinking about
-- Computer Science's calendar specifically. It is NOT a scoping column:
-- a semester still applies to its entire faculty_id exactly as before
-- (every department in that faculty shares the same current semester).
-- Nothing in get_current_semester() (includes/semester_helpers.php) or
-- any other per-faculty scoping logic reads this column — it is read
-- only for display, wherever semesters are already listed. Named
-- "context_department_id" (not just "department_id") specifically so it
-- can never be mistaken for a real scoping/ownership column the way
-- faculty_id is.
--
-- ON DELETE SET NULL: if the noted department is later deleted, the
-- semester itself must never be affected — it just loses its optional
-- context note.
--
-- Run once against the existing admas_attendance database.
-- =====================================================================
USE admas_attendance;

ALTER TABLE semesters
  ADD COLUMN context_department_id INT UNSIGNED NULL AFTER faculty_id,
  ADD CONSTRAINT fk_semesters_context_department
    FOREIGN KEY (context_department_id) REFERENCES departments(id) ON DELETE SET NULL;
