-- Collapse the 'late' and 'excused' attendance statuses into 'absent' —
-- the system now tracks only Present/Absent — then shrink the ENUM so the
-- removed values can never be written again.
UPDATE attendance SET status = 'absent' WHERE status IN ('late', 'excused');

ALTER TABLE attendance
  MODIFY COLUMN status ENUM('present','absent') NOT NULL;
