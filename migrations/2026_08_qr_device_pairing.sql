-- QR Code Login / Device Pairing
--
-- Two new tables:
--   paired_devices       — long-lived record of a phone "paired" to a user
--                          account (created once when the user scans the
--                          Profile & Password pairing QR and taps Confirm).
--   qr_login_challenges  — short-lived, single-use tokens for both the
--                          pairing flow and the later login-via-QR flow.
--                          Every state transition is an atomic
--                          UPDATE ... WHERE status = '<expected>' so a
--                          replayed/duplicate confirm can never succeed
--                          twice (see qr_pair.php / qr_login_confirm.php /
--                          ajax/qr_login_status.php).
--
-- device_token is stored HASHED (sha256) — it's a 90-day bearer credential
-- functionally equivalent to a password. challenge_token is stored PLAIN,
-- same as password_resets.code, since it's already visible on-screen in
-- the QR image and is single-use + short-lived (3 minutes).

USE admas_attendance;

CREATE TABLE paired_devices (
  id                  INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  user_id             INT UNSIGNED NOT NULL,
  device_token_hash   CHAR(64) NOT NULL,
  device_label        VARCHAR(150) NOT NULL DEFAULT '',
  user_agent          VARCHAR(255) NULL,
  paired_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at        DATETIME NULL,
  revoked_at          DATETIME NULL,
  CONSTRAINT fk_paired_devices_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_paired_devices_token_hash (device_token_hash),
  INDEX idx_paired_devices_user_active (user_id, revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE qr_login_challenges (
  id                     INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  purpose                ENUM('pair','login') NOT NULL,
  challenge_token        VARCHAR(64) NOT NULL,
  user_id                INT UNSIGNED NULL,
  device_id              INT UNSIGNED NULL,
  status                 ENUM('pending','confirmed','completed','expired','cancelled') NOT NULL DEFAULT 'pending',
  created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at             DATETIME NOT NULL,
  confirmed_at           DATETIME NULL,
  completed_at           DATETIME NULL,
  requesting_ip          VARCHAR(45) NULL,
  requesting_user_agent  VARCHAR(255) NULL,
  confirming_ip          VARCHAR(45) NULL,
  confirming_user_agent  VARCHAR(255) NULL,
  CONSTRAINT fk_qr_challenges_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_qr_challenges_device
    FOREIGN KEY (device_id) REFERENCES paired_devices(id) ON DELETE SET NULL,
  UNIQUE KEY uq_qr_challenges_token (challenge_token),
  INDEX idx_qr_challenges_status_expiry (status, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
