-- Converts settings.min_attendance_pct from the old 0-100 ratio scale to the
-- new 0-10 out-of-10 attendance score scale (1 point per Present *regular*
-- Xiiso session; Midterm/Final never count — see the "Attendance Scoring
-- Overhaul" plan and includes/attendance_helpers.php's ATTENDANCE_MAX_SCORE).
--
-- Proportional conversion: old default 75 (meaning 75%) -> new default 7.5
-- (meaning 7.5 of the 10 regular sessions). Take a mysqldump backup of the
-- `settings` table before running this, per this project's established
-- convention for schema/data changes.
UPDATE settings
SET value = ROUND(CAST(value AS DECIMAL(6,2)) / 10, 2)
WHERE `key` = 'min_attendance_pct';
