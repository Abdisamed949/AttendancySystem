-- Tracks whether a student has already claimed/set up their own login via
-- the new public register.php self-registration page (Student ID + Faculty
-- + Department + Shift + Academic Year, matched against their own existing
-- students row, then choose a real username/password) — nullable, set once
-- the first (and only) time they successfully register. NULL means the
-- account was created by Registration Office but the student has not yet
-- claimed it with their own credentials.
ALTER TABLE students
    ADD COLUMN self_registered_at TIMESTAMP NULL DEFAULT NULL AFTER status;
