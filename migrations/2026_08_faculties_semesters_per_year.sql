-- =====================================================================
-- Migration: faculties.semesters_per_year
--
-- Adds:
--   - faculties.semesters_per_year TINYINT UNSIGNED NOT NULL DEFAULT 3
--
-- How many semesters that faculty's program runs per calendar year (e.g.
-- 3 for most faculties, 2 for a Health faculty on a longer per-semester
-- calendar). Purely informational/display today (used to show "Year 2" next
-- to a semester's own sequence number in semesters.php) — it does not drive
-- semester generation itself, since "Generate Next Semester" already derives
-- everything it needs (the next sequence number, and the next start date)
-- straight from that faculty's existing semesters.
--
-- Run once against the existing admas_attendance database.
-- =====================================================================
USE admas_attendance;

ALTER TABLE faculties
  ADD COLUMN semesters_per_year TINYINT UNSIGNED NOT NULL DEFAULT 3 AFTER name;
