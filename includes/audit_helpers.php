<?php
/**
 * Shared audit-log writer. Called from every sensitive/high-blast-radius
 * write action across the app (deletes, reset password, bulk actions,
 * settings changes, factory reset, role appointment) — one function, so
 * every call site logs in the exact same shape and none can drift.
 *
 * Deliberately NOT wired into routine attendance marking — the
 * `attendance` table's own `recorded_by_user_id` already answers "who
 * marked this" per record, and logging every single Xiiso cell save here
 * would flood a log meant for occasional oversight review with thousands
 * of routine rows.
 */
declare(strict_types=1);

/**
 * @param string      $action      Short machine key, e.g. 'delete_student',
 *                                  'reset_password', 'bulk_delete',
 *                                  'factory_reset', 'role_assignment'.
 * @param string|null $targetType  What kind of record this acted on, e.g.
 *                                  'student', 'lecturer', 'user', 'settings'.
 * @param int|null    $targetId    The affected record's own id, if any.
 * @param string|null $targetLabel A human-readable label for the record
 *                                  (student_no, staff_no, username, etc.)
 *                                  — resolved at the time of the action, so
 *                                  the log entry stays readable even if the
 *                                  record is later deleted/renamed.
 * @param string|null $details     Any extra free-text context (e.g. a bulk
 *                                  action's "N of M" summary).
 */
function audit_log(mysqli $conn, string $action, ?string $targetType = null, ?int $targetId = null, ?string $targetLabel = null, ?string $details = null): void
{
    try {
        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        $role = (string) ($_SESSION['role'] ?? 'unknown');
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        // The session only ever stores user_id/role/full_name (see
        // login.php) — never a 'username' key — so it's looked up fresh
        // here rather than assumed to exist in $_SESSION.
        $username = 'unknown';
        if ($userId !== null) {
            $userStmt = $conn->prepare('SELECT username FROM users WHERE id = ?');
            $userStmt->bind_param('i', $userId);
            $userStmt->execute();
            $username = (string) ($userStmt->get_result()->fetch_assoc()['username'] ?? 'unknown');
            $userStmt->close();
        }

        $stmt = $conn->prepare(
            'INSERT INTO audit_log (user_id, target_id, username, role, action, target_type, target_label, details, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('iisssssss', $userId, $targetId, $username, $role, $action, $targetType, $targetLabel, $details, $ip);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        // An audit-log failure must never break the real action it's
        // logging — record it server-side only and move on.
        error_log('audit_log() failed: ' . $e->getMessage());
    }
}

/**
 * Friendly labels for the `action` column, used by audit_log.php's viewer
 * — kept as one shared map so the filter dropdown and the rendered table
 * can never show a different label for the same key.
 */
const AUDIT_ACTION_LABELS = [
    'delete_student' => 'Deleted Student',
    'delete_lecturer' => 'Deleted Lecturer',
    'delete_department' => 'Deleted Department',
    'delete_faculty' => 'Deleted Faculty',
    'delete_semester' => 'Deleted Semester',
    'delete_course' => 'Deleted Course',
    'bulk_delete' => 'Bulk Delete',
    'reset_password' => 'Reset Password',
    'bulk_reset_password' => 'Bulk Reset Password',
    'toggle_status' => 'Activate/Deactivate User',
    'role_assignment' => 'Role Appointment',
    'settings_update' => 'Settings Updated',
    'factory_reset' => 'Factory Reset',
];
