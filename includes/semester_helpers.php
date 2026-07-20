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
 * Recompute every semester's is_current flag from its own start_date/
 * end_date against today — a semester is "current" for as long as today
 * falls within its own date range, full stop. Unlike a manually-toggled
 * flag, this lets any number of semesters be current at once (across
 * different faculties, or multiple concurrent batches within the same
 * faculty) with no admin action required and no risk of one semester
 * silently switching another off. Called once per request from
 * includes/auth.php, so every page always sees fresh flags before it runs
 * any of its own is_current-scoped queries.
 */
function refresh_semester_current_flags(mysqli $conn): void
{
    $conn->query('UPDATE semesters SET is_current = (CURDATE() BETWEEN start_date AND end_date)');
}

/**
 * The semester currently marked is_current = 1 for one specific faculty, or
 * null if that faculty has none set (or $facultyId is invalid). If more than
 * one of that faculty's semesters is concurrently current (multiple active
 * batches), the most recently started one is returned — callers that need
 * a single "your current semester" for display, not every caller needing
 * to know about every concurrently-running one.
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
         ORDER BY s.start_date DESC
         LIMIT 1"
    );
    $stmt->bind_param('i', $facultyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

/**
 * The next sequential semester number for a faculty, derived from the
 * digits in its existing semester names (e.g. "Semester 3" -> 3), not a
 * stored counter — so it stays correct even if a semester is later renamed
 * or deleted. Returns 1 if the faculty has no semesters yet.
 */
function next_semester_number_for_faculty(mysqli $conn, int $facultyId): int
{
    $stmt = $conn->prepare('SELECT name FROM semesters WHERE faculty_id = ?');
    $stmt->bind_param('i', $facultyId);
    $stmt->execute();
    $names = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'name');
    $stmt->close();

    $max = 0;
    foreach ($names as $name) {
        $digits = preg_replace('/\D/', '', (string) $name);
        if ($digits !== '') {
            $max = max($max, (int) $digits);
        }
    }

    return $max + 1;
}

/**
 * The day after a faculty's most recently ended semester, i.e. where its
 * next semester should start — or null if that faculty has no semesters
 * yet (the very first one needs an admin-provided start date).
 */
function next_semester_start_date_for_faculty(mysqli $conn, int $facultyId): ?string
{
    $stmt = $conn->prepare('SELECT MAX(end_date) AS last_end FROM semesters WHERE faculty_id = ?');
    $stmt->bind_param('i', $facultyId);
    $stmt->execute();
    $lastEnd = $stmt->get_result()->fetch_assoc()['last_end'] ?? null;
    $stmt->close();

    if ($lastEnd === null) {
        return null;
    }

    $next = new DateTime((string) $lastEnd);
    $next->modify('+1 day');

    return $next->format('Y-m-d');
}

/**
 * A semester's end date, fixed at 3 months after its start date (every
 * semester at this university is 3 months / 12 Xiiso long, regardless of
 * faculty — only how many semesters run per year differs).
 */
function semester_end_date_from_start(string $startDate): string
{
    $end = new DateTime($startDate);
    $end->modify('+3 months');
    $end->modify('-1 day');

    return $end->format('Y-m-d');
}

/**
 * 12 dates evenly spaced across [startDate, endDate] inclusive — session 1
 * lands on the start date, session 12 on the end date, sessions 2-11 spaced
 * between (so Xiiso 6, the Midterm, naturally falls near the midpoint).
 * Used to pre-fill Xiiso session dates automatically when a semester is
 * generated, instead of leaving admins to type all 12 by hand; any of them
 * can still be edited afterward for holidays etc.
 *
 * @return array<int, string> 12 Y-m-d dates, index 0 = session 1
 */
function compute_session_dates(string $startDate, string $endDate): array
{
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
    $totalDays = (int) $start->diff($end)->days;

    $dates = [];
    for ($i = 0; $i < 12; $i++) {
        $offset = (int) round($totalDays * $i / 11);
        $d = (clone $start)->modify('+' . $offset . ' days');
        $dates[] = $d->format('Y-m-d');
    }

    return $dates;
}

/**
 * Create the 12 Xiiso rows (1-5 regular, 6 Midterm, 7-11 regular, 12 Final)
 * for a semester, with dates pre-filled via compute_session_dates() from
 * the semester's own start_date/end_date. Safe to call more than once —
 * existing session_number rows for that semester are left untouched
 * (INSERT IGNORE against the uq_session_number_per_semester unique key),
 * and only rows still missing a date (freshly inserted, or left blank by an
 * older version of this function) get one filled in — a session an admin
 * already dated by hand is never overwritten.
 */
function generate_sessions_for_semester(mysqli $conn, int $semesterId): void
{
    $insertStmt = $conn->prepare(
        'INSERT IGNORE INTO sessions (semester_id, session_number, type) VALUES (?, ?, ?)'
    );
    for ($number = 1; $number <= 12; $number++) {
        $type = session_type_for_number($number);
        $insertStmt->bind_param('iis', $semesterId, $number, $type);
        $insertStmt->execute();
    }
    $insertStmt->close();

    $semStmt = $conn->prepare('SELECT start_date, end_date FROM semesters WHERE id = ?');
    $semStmt->bind_param('i', $semesterId);
    $semStmt->execute();
    $semester = $semStmt->get_result()->fetch_assoc();
    $semStmt->close();

    if (!$semester || $semester['start_date'] === null || $semester['end_date'] === null) {
        return;
    }

    $dates = compute_session_dates((string) $semester['start_date'], (string) $semester['end_date']);

    $dateStmt = $conn->prepare('UPDATE sessions SET date = ? WHERE semester_id = ? AND session_number = ? AND date IS NULL');
    for ($number = 1; $number <= 12; $number++) {
        $dateStmt->bind_param('sii', $dates[$number - 1], $semesterId, $number);
        $dateStmt->execute();
    }
    $dateStmt->close();
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
