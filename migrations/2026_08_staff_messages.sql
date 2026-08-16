-- =====================================================================
-- Migration: Staff Messages (internal chat between staff roles)
--
-- Adds:
--   - messages table — simple direct-message thread between two users,
--     used by messages.php (a WhatsApp-style two-pane chat shared by
--     University Rector / Head of Academic Affairs / Dean / Lecturer /
--     Registration Office, so any one of them can reach any other
--     directly to ask about an issue). Students are not part of this —
--     it's a staff-to-staff channel only, enforced by messages.php's own
--     require_role(), not by this table.
--
-- Run once against the existing admas_attendance database.
-- =====================================================================
USE admas_attendance;

CREATE TABLE messages (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sender_id INT UNSIGNED NOT NULL,
  receiver_id INT UNSIGNED NOT NULL,
  body VARCHAR(2000) NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_messages_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_messages_receiver FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_messages_conversation (sender_id, receiver_id, created_at),
  INDEX idx_messages_receiver_unread (receiver_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
