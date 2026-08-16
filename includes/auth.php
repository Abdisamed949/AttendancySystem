<?php
/**
 * Shared authentication and role-gating helpers for the ADMAS Attendance System.
 */
declare(strict_types=1);

if (!defined('BASE_URL')) {
    $baseUrl = getenv('APP_BASE_URL') ?: '/AttendancySystem';
    define('BASE_URL', rtrim($baseUrl, '/'));
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/nav_items.php';
require_once __DIR__ . '/semester_helpers.php';

/**
 * Redirect to a path inside the application base URL.
 */
function redirect_to(string $path): void
{
    $target = BASE_URL . '/' . ltrim($path, '/');
    if (!headers_sent()) {
        header('Location: ' . $target);
    }
    exit;
}

/**
 * Ensure the visitor is logged in.
 */
function require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        redirect_to('login.php');
    }
}

/**
 * Ensure the visitor has one of the allowed roles.
 *
 * @param array<int, string> $allowedRoles
 */
function require_role(array $allowedRoles): void
{
    require_login();

    $currentRole = $_SESSION['role'] ?? '';
    if (!in_array($currentRole, $allowedRoles, true)) {
        if (!headers_sent()) {
            http_response_code(403);
        }
        redirect_to('unauthorized.php');
    }

    // A user who still has must_change_password=1 (fresh account, never
    // logged in and changed it) is locked to their own role's Profile &
    // Password page — every protected page calls require_role() as its
    // first line, so gating it here enforces this everywhere at once
    // instead of needing a check added to every individual file. The
    // basename check is what lets Profile & Password itself load (every
    // role's copy of that page is literally named profile.php), otherwise
    // this would redirect the user in an infinite loop.
    $currentScript = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (!empty($_SESSION['must_change_password']) && $currentScript !== 'profile.php') {
        redirect_to(role_folder($currentRole) . '/profile.php');
    }
}

/**
 * Get the current user record from the database.
 */
function current_user(): ?array
{
    require_login();

    $conn = db();
    $stmt = $conn->prepare('SELECT id, username, full_name, email, photo_path, role_id, faculty_id, status FROM users WHERE id = ?');
    if ($stmt === false) {
        return null;
    }

    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    return $user ?: null;
}

/**
 * Check whether the current user can access a faculty-scoped resource.
 */
function can_access_faculty(int $facultyId): bool
{
    require_login();

    $currentRole = $_SESSION['role'] ?? '';
    if ($currentRole === 'university_rector' || $currentRole === 'head_academic' || $currentRole === 'registration') {
        return true;
    }

    if ($currentRole === 'dean') {
        return (int) ($_SESSION['faculty_id'] ?? 0) === $facultyId;
    }

    return false;
}

/**
 * Return the current user's role name from the session.
 */
function current_role(): string
{
    return (string) ($_SESSION['role'] ?? '');
}

/**
 * Clear must_change_password (DB + session) once the user has set their
 * own password, whether via Profile & Password or the Forgot Password
 * email flow. Used by every role's profile.php after a successful
 * change_password action.
 */
function clear_must_change_password(mysqli $conn, int $userId): void
{
    $stmt = $conn->prepare('UPDATE users SET must_change_password = 0 WHERE id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();

    $_SESSION['must_change_password'] = 0;
}
