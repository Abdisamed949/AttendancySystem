-- Replaces the automatic CURDATE()-BETWEEN-start_date-AND-end_date "current
-- semester" engine with a manually-set status the admin/head_academic/dean
-- controls directly via three buttons (Start / End / Waiting) on
-- semesters.php. Start Date and End Date become optional reference-only
-- fields instead of the mechanism that drives is_current.

ALTER TABLE semesters
  MODIFY COLUMN start_date DATE NULL,
  MODIFY COLUMN end_date DATE NULL,
  ADD COLUMN status ENUM('waiting', 'current', 'ended') NOT NULL DEFAULT 'waiting' AFTER is_current;

-- One-time backfill so existing semesters keep showing the same state they
-- displayed under the old date-derived badges, instead of every row
-- reverting to "waiting" the moment this migration runs.
UPDATE semesters SET status = CASE
    WHEN is_current = 1 THEN 'current'
    WHEN end_date IS NOT NULL AND end_date < CURDATE() THEN 'ended'
    ELSE 'waiting'
END;
