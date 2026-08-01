-- Splits students.full_name into three separate columns matching the
-- university's real paper/Excel attendance tracker: FIRST NAMES / FATHER'S /
-- G.FATHER'S (standard Somali given + father's + grandfather's naming).
-- full_name is kept as a MySQL/MariaDB GENERATED column computed from the
-- three parts, so every existing read-only consumer of `full_name` across
-- the app keeps working unchanged.
--
-- Applies to `students` only. `lecturers`/`users` keep a single full_name
-- field (the real tracker shows the lecturer as one free-text line).
--
-- Run against a fresh mysqldump backup. Confirmed against the live dev DB:
-- every existing student's full_name is exactly 3 words, so the backfill
-- below (SUBSTRING_INDEX-based, first/second/last word) is lossless for all
-- current rows. Any future student whose name doesn't split into exactly
-- 3 words should be corrected by hand via admin/students.php's edit form.

-- 1. Add the three new columns (nullable for now, populated in step 2).
ALTER TABLE students
  ADD COLUMN first_name VARCHAR(60) NULL AFTER student_no,
  ADD COLUMN father_name VARCHAR(60) NULL AFTER first_name,
  ADD COLUMN grandfather_name VARCHAR(60) NULL AFTER father_name;

-- 2. Backfill from the existing full_name (still a plain writable column
--    at this point). word 1 -> first_name, word 2 -> father_name, every
--    remaining word -> grandfather_name.
UPDATE students
SET
  first_name = TRIM(SUBSTRING_INDEX(full_name, ' ', 1)),
  father_name = TRIM(
    CASE
      WHEN full_name LIKE '% %'
        THEN SUBSTRING_INDEX(SUBSTRING_INDEX(full_name, ' ', 2), ' ', -1)
      ELSE ''
    END
  ),
  grandfather_name = TRIM(
    CASE
      WHEN (LENGTH(full_name) - LENGTH(REPLACE(full_name, ' ', ''))) >= 2
        THEN SUBSTRING(full_name, LENGTH(SUBSTRING_INDEX(full_name, ' ', 2)) + 2)
      ELSE ''
    END
  );

-- 3. Require first_name/father_name going forward; grandfather_name stays
--    optional (not every real name has 3 parts).
ALTER TABLE students
  MODIFY COLUMN first_name VARCHAR(60) NOT NULL,
  MODIFY COLUMN father_name VARCHAR(60) NOT NULL;

-- 4. Convert full_name into a generated column. From this point on, no INSERT
--    /UPDATE may list full_name explicitly for `students` -- MySQL/MariaDB
--    computes and stores it automatically from the three parts above.
ALTER TABLE students
  MODIFY COLUMN full_name VARCHAR(150)
    GENERATED ALWAYS AS (TRIM(CONCAT_WS(' ', first_name, father_name, grandfather_name))) STORED;
