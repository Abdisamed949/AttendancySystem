-- Allows a single course to be offered on multiple shifts within the same
-- semester at once (e.g. Morning taught by Lecturer A, Afternoon by
-- Lecturer B), which the previous (course_id, semester_id) unique key
-- could not represent — see migrations/2026_08_course_offerings_shift.sql,
-- whose header comment explicitly deferred this exact change to "its own
-- scoped migration that also updates every consumer." This is that
-- follow-up.
--
-- shift becomes NOT NULL with a new sentinel value 'any' (meaning "applies
-- to every shift") instead of staying nullable — MySQL/MariaDB never treat
-- two NULLs as equal in a unique index, so a nullable "wildcard" shift
-- could not be protected from accidental duplicates by the unique key
-- itself. A real, comparable sentinel value lets the new 3-column unique
-- key (and ON DUPLICATE KEY UPDATE) enforce uniqueness natively.

-- 1. Widen the ENUM first (column stays nullable so old NULLs are still valid mid-migration)
ALTER TABLE course_offerings
  MODIFY COLUMN shift ENUM('morning','afternoon','weekend','any') NULL;

-- 2. Backfill every existing NULL row to the wildcard sentinel
UPDATE course_offerings SET shift = 'any' WHERE shift IS NULL;

-- 3. Lock it down: NOT NULL, default 'any'
ALTER TABLE course_offerings
  MODIFY COLUMN shift ENUM('morning','afternoon','weekend','any') NOT NULL DEFAULT 'any';

-- 4. Replace the identity key
ALTER TABLE course_offerings
  DROP INDEX uq_course_per_semester,
  ADD UNIQUE KEY uq_course_semester_shift (course_id, semester_id, shift);
