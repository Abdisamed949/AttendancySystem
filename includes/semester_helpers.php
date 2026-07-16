<?php
/**
 * Semester + Session ("Xiiso") helpers shared by semesters.php,
 * attendance.php, and reports.php.
 */
declare(strict_types=1);

/**
 * The 12 Xiiso positions within a semester: 1-5 and 7-11 are regular
 * teaching sessions, 6 is the Midterm, 12 is the Final.
 */
function session_type_for_number(int $sessionNumber): string
{
    return match ($sessionNumber) {
        6 => 'midterm',
        12 => 'final',
        default => 'regular',
    };
}

/**
 * Human label for a session row, e.g. "Xiiso 3", "Midterm", "Final".
 */
function session_label(array $session): string
{
    return match ($session['type']) {
        'midterm' => 'Midterm',
        'final' => 'Final',
        default => 'Xiiso ' . (int) $session['session_number'],
    };
}

/**
 * The semester currently marked is_current = 1 for one specific faculty, or
 * null if that faculty has none set (or $facultyId is invalid). "Current"
 * is a per-faculty concept — different faculties run independent semester
 * tracks and can be on different semesters at the same time — so every
 * caller must resolve the relevant faculty_id first (from the course,
 * student, or dean session in scope) rather than assuming a single
 * system-wide current semester.
 */
function get_current_semester(mysqli $conn, int $facultyId): ?array
{
    if ($facultyId <= 0) {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT s.id, s.academic_year_id, s.faculty_id, s.name, s.start_date, s.end_date, s.is_current,
                ay.label AS academic_year_label
         FROM semesters s
         JOIN academic_years ay ON ay.id = s.academic_year_id
         WHERE s.is_current = 1 AND s.faculty_id = ?
         LIMIT 1"
    );
    $stmt->bind_param('i', $facultyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

/**
 * Create the 12 Xiiso rows (1-5 regular, 6 Midterm, 7-11 regular, 12 Final)
 * for a semester. Safe to call more than once — existing session_number
 * rows for that semester are left untouched (INSERT IGNORE against the
 * uq_session_number_per_semester unique key).
 */
function generate_sessions_for_semester(mysqli $conn, int $semesterId): void
{
    $stmt = $conn->prepare(
        'INSERT IGNORE INTO sessions (semester_id, session_number, type) VALUES (?, ?, ?)'
    );
    for ($number = 1; $number <= 12; $number++) {
        $type = session_type_for_number($number);
        $stmt->bind_param('iis', $semesterId, $number, $type);
        $stmt->execute();
    }
    $stmt->close();
}

/**
 * All 12 sessions for a semester, ordered 1-12, with a ready-to-display label.
 */
function get_sessions_for_semester(mysqli $conn, int $semesterId): array
{
    $stmt = $conn->prepare(
        'SELECT id, semester_id, session_number, type, date
         FROM sessions
         WHERE semester_id = ?
         ORDER BY session_number'
    );
    $stmt->bind_param('i', $semesterId);
    $stmt->execute();
    $sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($sessions as &$session) {
        $session['label'] = session_label($session);
    }
    unset($session);

    return $sessions;
}
