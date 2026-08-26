-- Audit Log: a university-wide, University Rector-only record of every
-- sensitive/high-blast-radius write action across the app (deletes, reset
-- password, bulk actions, settings changes, factory reset, role
-- appointment) — distinct from `role_assignments`, which only ever tracked
-- one specific action (Dean/Head of Academic Affairs/Registration Office
-- appointment). Deliberately does NOT log routine attendance marking (the
-- `attendance` table's own `recorded_by_user_id` already answers "who
-- marked this" per record, and logging every single Xiiso cell save here
-- would flood a log meant for occasional oversight review with thousands
-- of routine rows).
--
-- `user_id` is nullable with ON DELETE SET NULL — a user account is almost
-- always deactivated rather than deleted in this app, but the row stays
-- meaningful either way since `username`/`role` are denormalized at the
-- moment the action happened, not looked up live.
CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    target_id INT UNSIGNED NULL,
    username VARCHAR(100) NOT NULL,
    role VARCHAR(30) NOT NULL,
    action VARCHAR(60) NOT NULL,
    target_type VARCHAR(40) NULL,
    target_label VARCHAR(255) NULL,
    details VARCHAR(500) NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_log_created_at (created_at),
    INDEX idx_audit_log_user_id (user_id),
    INDEX idx_audit_log_action (action),
    CONSTRAINT fk_audit_log_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;
