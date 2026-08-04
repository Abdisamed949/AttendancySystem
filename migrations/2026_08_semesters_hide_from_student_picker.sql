-- Adds a per-semester "hide from the student Semester Box Picker" flag.
--
-- Motivated by a real duplicate-name situation on Informatics: two real
-- semester rows both named "Semester 6" exist for one faculty across two
-- different academic years (2023/2024, ended, with real historical
-- attendance now backfilled into it; 2024/2025, current, with 41 real
-- students already placed into it via students.semester_id and a real
-- course_offerings row). Deleting the 2024/2025 row is not safe — it would
-- strip 41 students of their semester placement (students.semester_id has
-- no ON DELETE CASCADE, by design — see delete_semester_row()'s own
-- students-count blocker). The admin's actual want was narrower: stop the
-- 2024/2025 duplicate from cluttering student/courses.php's own Semester
-- Box Picker (which shows one box per "Semester N" slot per faculty) while
-- leaving the semester itself, its students, and its course_offerings row
-- completely untouched everywhere else (attendance.php, reports.php,
-- admin/dean views, etc. all still see and can use it normally).
--
-- Purely a display filter on ONE screen — never read by get_current_semester()
-- or any scoping/write-authorization logic, same "cosmetic, not scoping"
-- precedent as semesters.context_department_id above it in this schema.
ALTER TABLE semesters
  ADD COLUMN hidden_from_picker TINYINT(1) NOT NULL DEFAULT 0
  COMMENT 'Hides this semester from student/courses.php''s Semester Box Picker only — every other page (attendance, reports, admin/dean semester management) is unaffected.'
  AFTER status;

-- Applied to the real Informatics duplicate this migration was written for:
-- UPDATE semesters SET hidden_from_picker = 1 WHERE id = 36;
-- (left commented out here since the id is environment-specific — set via
-- semesters.php's own toggle instead, per the app's normal write path.)
