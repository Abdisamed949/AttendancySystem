<?php
/**
 * Shared helpers for auto-creating login accounts, used by
 * admin/lecturers.php, admin/lecturers_import.php, admin/students.php,
 * admin/students_import.php, and admin/users.php.
 */
declare(strict_types=1);

/**
 * Build a unique username from a full name: first letter of the first name +
 * the last name, lowercase, alphanumeric only. Appends an incrementing
 * numeric suffix if the base username is already taken.
 */
function generate_lecturer_username(mysqli $conn, string $fullName): string
{
    $parts = array_values(array_filter(preg_split('/\s+/', trim($fullName)), static fn ($p) => $p !== ''));

    if (count($parts) === 0) {
        $base = 'lecturer';
    } elseif (count($parts) === 1) {
        $base = strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', $parts[0]));
    } else {
        $first = $parts[0];
        $last = end($parts);
        $base = strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', substr($first, 0, 1) . $last));
    }

    if ($base === '') {
        $base = 'lecturer';
    }

    $username = $base;
    $suffix = 1;
    while (lecturer_username_exists($conn, $username)) {
        $username = $base . $suffix;
        $suffix++;
    }

    return $username;
}

function lecturer_username_exists(mysqli $conn, string $username): bool
{
    $stmt = $conn->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $exists;
}

/**
 * Build a unique username for a student: first name, lowercase, + "123".
 * Appends an incrementing numeric suffix if the base username is taken.
 */
function generate_student_username(mysqli $conn, string $fullName): string
{
    $parts = array_values(array_filter(preg_split('/\s+/', trim($fullName)), static fn ($p) => $p !== ''));
    $first = $parts[0] ?? 'student';
    $base = strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', $first)) . '123';

    if ($base === '123') {
        $base = 'student123';
    }

    $username = $base;
    $suffix = 1;
    while (lecturer_username_exists($conn, $username)) {
        $username = $base . $suffix;
        $suffix++;
    }

    return $username;
}

/**
 * Generate a unique student number in the form ADM-{year}-{sequence}, e.g.
 * ADM-2026-0001, based on the highest existing sequence for the current year.
 */
function generate_student_no(mysqli $conn): string
{
    $prefix = 'ADM-' . date('Y') . '-';

    $stmt = $conn->prepare("SELECT student_no FROM students WHERE student_no LIKE CONCAT(?, '%') ORDER BY student_no DESC LIMIT 1");
    $likeParam = $prefix;
    $stmt->bind_param('s', $likeParam);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $sequence = 1;
    if ($row) {
        $numericPart = substr((string) $row['student_no'], strlen($prefix));
        if (ctype_digit($numericPart)) {
            $sequence = ((int) $numericPart) + 1;
        }
    }

    return $prefix . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
}

/**
 * Build a unique username for a general admin-appointed account — Dean, Head
 * of Academic Affairs, Registration Office — using the same first-initial +
 * last-name scheme as generate_lecturer_username().
 */
function generate_admin_username(mysqli $conn, string $fullName): string
{
    return generate_lecturer_username($conn, $fullName);
}

/**
 * Generate a random temporary password containing at least one uppercase
 * letter, one lowercase letter, one digit, and one symbol.
 */
function generate_temp_password(): string
{
    $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lower = 'abcdefghijkmnopqrstuvwxyz';
    $digits = '23456789';
    $symbols = '!@#$%*?';
    $all = $upper . $lower . $digits . $symbols;

    $password = [
        $upper[random_int(0, strlen($upper) - 1)],
        $lower[random_int(0, strlen($lower) - 1)],
        $digits[random_int(0, strlen($digits) - 1)],
        $symbols[random_int(0, strlen($symbols) - 1)],
    ];

    for ($i = 0; $i < 6; $i++) {
        $password[] = $all[random_int(0, strlen($all) - 1)];
    }

    shuffle($password);

    return implode('', $password);
}
