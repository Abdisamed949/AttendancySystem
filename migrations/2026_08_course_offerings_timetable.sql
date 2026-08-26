-- ---------------------------------------------------------------------
-- Class Time Table: optional weekly Day/Time/Room per course_offerings
-- row. One day/one time window per offering (not a repeating multi-day
-- schedule) — matches how ADMAS's real printed Class Time Table already
-- lists one slot per course. Room is display-only, no conflict/double-
-- booking validation is enforced anywhere on purpose (confirmed with the
-- user — it's cosmetic, not a scheduling constraint).
-- ---------------------------------------------------------------------
ALTER TABLE course_offerings
  ADD COLUMN day_of_week ENUM('saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday') NULL AFTER shift,
  ADD COLUMN start_time TIME NULL AFTER day_of_week,
  ADD COLUMN end_time TIME NULL AFTER start_time,
  ADD COLUMN room VARCHAR(50) NULL AFTER end_time;
