-- =====================================================================
-- Migration: course_offerings.shift (informational, not part of identity)
--
-- Adds:
--   - course_offerings.shift ENUM('morning','afternoon','weekend') NULL
--
-- Investigated whether Shift should instead become part of this table's
-- identity — i.e. change the unique key from (course_id, semester_id) to
-- (course_id, semester_id, shift), allowing a different lecturer per
-- shift for the same course+semester. Rejected for now: every existing
-- course_offerings consumer (the lecturer write-authorization check in
-- includes/attendance_helpers.php::user_can_write_course_attendance(),
-- the lecturer's own course list on attendance.php/lecturer/courses.php,
-- lecturer/dashboard.php's KPIs, student/courses.php's displayed
-- lecturer) keys off (course_id, semester_id, lecturer_id) with no
-- concept of shift at all. Making shift part of the identity without
-- auditing/updating all of those would silently fail to separate write
-- access per shift — a lecturer assigned to only the Morning offering
-- would still pass every one of those checks for the Afternoon/Weekend
-- rosters too, since none of them filter by shift. That's a materially
-- larger, riskier change than "add an optional field to a form," so
-- shift stays a plain display column for now: unique key on
-- course_offerings is UNCHANGED (still just course_id + semester_id).
-- If genuine multi-lecturer-per-shift support is needed later, it should
-- be its own scoped migration that also updates every consumer above.
--
-- Run once against the existing admas_attendance database.
-- =====================================================================
USE admas_attendance;

ALTER TABLE course_offerings
  ADD COLUMN shift ENUM('morning', 'afternoon', 'weekend') NULL AFTER lecturer_id;
