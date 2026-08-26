-- Adds a short "Code" to each faculty (e.g. "INF" for Informatics), so a
-- student's auto-generated username/password can be built from their own
-- Faculty Code + Student No ("{FacultyCode}-{StudentNo}") instead of their
-- Department Code — set by Head of Academic Affairs (or University Rector,
-- who has always had full CRUD on Faculty Management) when creating or
-- editing a Faculty, on admin/faculties.php.

ALTER TABLE faculties
  ADD COLUMN code VARCHAR(20) NULL AFTER name;

-- Best-effort backfill for any existing faculty rows: first 3 letters of
-- the faculty name, uppercased. An admin can rename these afterward via
-- the real Edit Faculty form if a different short code is preferred.
UPDATE faculties
SET code = UPPER(LEFT(REGEXP_REPLACE(name, '[^A-Za-z]', ''), 3))
WHERE code IS NULL OR code = '';

ALTER TABLE faculties
  MODIFY COLUMN code VARCHAR(20) NOT NULL,
  ADD UNIQUE KEY uq_faculties_code (code);
