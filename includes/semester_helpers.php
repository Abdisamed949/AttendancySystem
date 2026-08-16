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
 * The semester currently marked status = 'current' for one specific
 * faculty, or null if that faculty has none set (or $facultyId is
 * invalid). "Current" is set by hand via semesters.php's Start/End/Waiting
 * buttons, not derived from calendar dates. If more than one of that
 * faculty's semesters is concurrently current (multiple active batches),
 * the one belonging to the most recent Academic Year is returned — tied by
 * comparing `academic_years.label` (e.g. "2025/2026" > "2023/2024"), NOT
 * `academic_years.id` or `semesters.id`, since neither is guaranteed to
 * have been created in chronological order (a real incident: Informatics
 * had Semester 9/academic year "2023/2024" and Semester 3/academic year
 * "2025/2026" both marked current at once, and ordering by id alone picked
 * the wrong one — "Take Attendance" links kept landing on a semester the
 * clicked course had no real offering in). Labels are a reliable sort key
 * here because they're always the consistent "YYYY/YYYY" shape, so a plain
 * string DESC comparison already sorts chronologically. `semesters.id DESC`
 * is still the final tie-break for two semesters sharing one academic year.
 * Callers that need a single "your current semester" for display, not
 * every caller needing to know about every concurrently-running one.
 */
function get_current_semester(mysqli $conn, int $facultyId): ?array
{
    if ($facultyId <= 0) {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT s.id, s.academic_year_id, s.faculty_id, s.name, s.start_date, s.end_date, s.is_current, s.status,
                ay.label AS academic_year_label
         FROM semesters s
         JOIN academic_years ay ON ay.id = s.academic_year_id
         WHERE s.status = 'current' AND s.faculty_id = ?
         ORDER BY ay.label DESC, s.id DESC
         LIMIT 1"
    );
    $stmt->bind_param('i', $facultyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

/**
 * The Semester dropdown/box options for a given faculty — "Semester 1"
 * through "Semester {total_semesters}" — generated fresh rather than
 * stored, so raising a faculty's Total Semesters later immediately makes
 * more options available with no data migration needed. Shared by
 * semesters.php (Create/Edit Semester dropdown) and student/courses.php
 * (the per-faculty Semester box picker).
 *
 * @return array<int, string>
 */
function semester_name_options_for_faculty(int $totalSemesters): array
{
    $options = [];
    for ($n = 1; $n <= $totalSemesters; $n++) {
        $options[] = 'Semester ' . $n;
    }

    return $options;
}

/**
 * A semester's end date, fixed at 3 months after its start date (every
 * semester at this university is 3 months / 12 Xiiso long, regardless of
 * faculty — only how many semesters run per year differs). Used to
 * auto-fill End Date + all 12 Xiiso dates from a manually-entered Start
 * Date on semesters.php, instead of requiring every date to be typed in
 * one by one.
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
