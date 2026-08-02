-- How many semesters a faculty's whole program runs for in total (e.g. a
-- 4-year program at 2 semesters/year = 8) — distinct from
-- semesters_per_year, which only feeds the "Year N" display calculation.
-- Drives the Semester dropdown on semesters.php's Create/Edit Semester
-- form: options are generated as "Semester 1".."Semester {total_semesters}"
-- for whichever faculty is selected.

ALTER TABLE faculties
  ADD COLUMN total_semesters TINYINT UNSIGNED NOT NULL DEFAULT 8 AFTER semesters_per_year;

-- Safety backfill: if a faculty already has a semester numbered higher than
-- the default 8 (e.g. "Semester 9"), raise total_semesters so that
-- semester's own name stays a valid dropdown option instead of becoming
-- unselectable the moment this migration runs.
UPDATE faculties f
SET total_semesters = GREATEST(
    total_semesters,
    (SELECT COALESCE(MAX(CAST(REGEXP_REPLACE(s.name, '[^0-9]', '') AS UNSIGNED)), 0)
     FROM semesters s WHERE s.faculty_id = f.id)
);
