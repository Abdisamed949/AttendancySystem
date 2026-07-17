-- =====================================================================
-- Migration: course_offerings teaching-period dates
--
-- Adds:
--   - course_offerings.start_date DATE NULL
--   - course_offerings.end_date DATE NULL
--   Both nullable — an offering (course + semester + lecturer) can exist
--   before its exact teaching-period dates within the semester are known,
--   same "set it when you're ready" spirit as sessions.date. No default
--   and no backfill: existing course_offerings rows get NULL for both,
--   same as any other not-yet-set optional field elsewhere in this schema
--   (e.g. sessions.date, students.semester_id).
--
-- Run once against the existing admas_attendance database.
-- =====================================================================
USE admas_attendance;

ALTER TABLE course_offerings
  ADD COLUMN start_date DATE NULL AFTER lecturer_id,
  ADD COLUMN end_date DATE NULL AFTER start_date;
