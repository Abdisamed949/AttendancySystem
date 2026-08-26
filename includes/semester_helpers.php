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
 * Auto-promotes every active student whose semester_id is $endedSemesterId
 * to that faculty's next-numbered semester ("Semester N" -> "Semester
 * N+1"), called the moment a semester's status transitions into 'ended'
 * (both the single "End" button and "Save All Semesters"/end_all_current
 * on semesters.php call this). A semester whose name isn't the plain
 * "Semester N" pattern, or whose number is already at (or past) the
 * faculty's own total_semesters (its final semester — those students are
 * graduating, not advancing into a "Semester N+1" that doesn't exist by
 * design), is left untouched. If "Semester N+1" doesn't exist for that
 * faculty yet, nothing is promoted now — promote_students_from_previous_semester()
 * below closes that gap automatically once it's created.
 *
 * @return array{promoted: int, target_name: ?string, reason: ?string, pending: int}
 */
function promote_students_to_next_semester(mysqli $conn, int $endedSemesterId): array
{
    $result = ['promoted' => 0, 'target_name' => null, 'reason' => null, 'pending' => 0];

    $semStmt = $conn->prepare('SELECT name, faculty_id FROM semesters WHERE id = ?');
    $semStmt->bind_param('i', $endedSemesterId);
    $semStmt->execute();
    $semester = $semStmt->get_result()->fetch_assoc();
    $semStmt->close();

    if (!$semester || !preg_match('/^Semester (\d+)$/', trim((string) $semester['name']), $matches)) {
        // Not a real semester, or not the standard "Semester N" naming —
        // no reliable "next" to compute.
        return $result;
    }

    $facultyId = (int) $semester['faculty_id'];
    $currentNumber = (int) $matches[1];

    $facStmt = $conn->prepare('SELECT total_semesters FROM faculties WHERE id = ?');
    $facStmt->bind_param('i', $facultyId);
    $facStmt->execute();
    $totalSemesters = (int) ($facStmt->get_result()->fetch_assoc()['total_semesters'] ?? 0);
    $facStmt->close();

    if ($currentNumber >= $totalSemesters) {
        $result['reason'] = 'final';

        return $result;
    }

    $nextName = 'Semester ' . ($currentNumber + 1);
    $result['target_name'] = $nextName;

    $targetStmt = $conn->prepare(
        "SELECT id FROM semesters WHERE faculty_id = ? AND name = ?
         ORDER BY (status = 'current') DESC, (status = 'waiting') DESC, id DESC
         LIMIT 1"
    );
    $targetStmt->bind_param('is', $facultyId, $nextName);
    $targetStmt->execute();
    $targetRow = $targetStmt->get_result()->fetch_assoc();
    $targetStmt->close();

    $pendingStmt = $conn->prepare("SELECT COUNT(*) AS c FROM students WHERE semester_id = ? AND status = 'active'");
    $pendingStmt->bind_param('i', $endedSemesterId);
    $pendingStmt->execute();
    $pendingCount = (int) ($pendingStmt->get_result()->fetch_assoc()['c'] ?? 0);
    $pendingStmt->close();

    if (!$targetRow) {
        $result['reason'] = 'no_target';
        $result['pending'] = $pendingCount;

        return $result;
    }

    $targetSemesterId = (int) $targetRow['id'];
    $updateStmt = $conn->prepare("UPDATE students SET semester_id = ? WHERE semester_id = ? AND status = 'active'");
    $updateStmt->bind_param('ii', $targetSemesterId, $endedSemesterId);
    $updateStmt->execute();
    $result['promoted'] = $updateStmt->affected_rows;
    $updateStmt->close();

    return $result;
}

/**
 * The other half of auto-promotion: called right after a NEW semester is
 * created, in case its own predecessor ("Semester N-1" in the same
 * faculty) already ended before "Semester N" existed to promote students
 * into — promote_students_to_next_semester() above would have found no
 * target at that time and left those students on the ended semester. Once
 * "Semester N" exists, this sweeps them in automatically rather than
 * requiring anyone to remember to re-run promotion by hand.
 *
 * @return array{promoted: int, source_name: ?string}
 */
function promote_students_from_previous_semester(mysqli $conn, int $newSemesterId): array
{
    $result = ['promoted' => 0, 'source_name' => null];

    $semStmt = $conn->prepare('SELECT name, faculty_id FROM semesters WHERE id = ?');
    $semStmt->bind_param('i', $newSemesterId);
    $semStmt->execute();
    $semester = $semStmt->get_result()->fetch_assoc();
    $semStmt->close();

    if (!$semester || !preg_match('/^Semester (\d+)$/', trim((string) $semester['name']), $matches)) {
        return $result;
    }

    $facultyId = (int) $semester['faculty_id'];
    $currentNumber = (int) $matches[1];
    if ($currentNumber <= 1) {
        return $result;
    }

    $previousName = 'Semester ' . ($currentNumber - 1);

    $sourceStmt = $conn->prepare(
        "SELECT id FROM semesters WHERE faculty_id = ? AND name = ? AND status = 'ended' ORDER BY id DESC LIMIT 1"
    );
    $sourceStmt->bind_param('is', $facultyId, $previousName);
    $sourceStmt->execute();
    $sourceRow = $sourceStmt->get_result()->fetch_assoc();
    $sourceStmt->close();

    if (!$sourceRow) {
        return $result;
    }

    $sourceSemesterId = (int) $sourceRow['id'];
    $result['source_name'] = $previousName;

    $updateStmt = $conn->prepare("UPDATE students SET semester_id = ? WHERE semester_id = ? AND status = 'active'");
    $updateStmt->bind_param('ii', $newSemesterId, $sourceSemesterId);
    $updateStmt->execute();
    $result['promoted'] = $updateStmt->affected_rows;
    $updateStmt->close();

    return $result;
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

/**
 * One row per (course, session) this lecturer's current-semester offerings
 * make them eligible to have checked in for, limited to sessions whose date
 * has already arrived (today or earlier) — a lecturer isn't "accountable"
 * for a class that hasn't happened yet. Each row carries whether they
 * actually did (the matching lecturer_checkins row, or null).
 *
 * The single source of truth behind Lecturer Check-In's own per-course
 * totals (lecturer/checkin.php) AND the university-wide accountability
 * summary on lecturer_checkins.php (Dean/Head of Academic Affairs/
 * University Rector) — both build their own totals from this same row set,
 * so the two views can never disagree on what "N of M sessions" means for
 * a given lecturer.
 *
 * @return array<int, array{course_id: int, course_code: string, course_name: string,
 *   semester_name: string, session_id: int, session_label: string, session_date: string,
 *   scheduled_start_time: ?string, scheduled_end_time: ?string,
 *   checkin: ?array{id: int, check_in_at: string, check_out_at: ?string}}>
 */
function lecturer_checkin_eligible_sessions(mysqli $conn, int $lecturerId): array
{
    $today = date('Y-m-d');

    $offeringsStmt = $conn->prepare(
        "SELECT c.id AS course_id, c.code, c.name, se.id AS semester_id, se.name AS semester_name,
                co.start_time AS scheduled_start_time, co.end_time AS scheduled_end_time
         FROM course_offerings co
         JOIN courses c ON c.id = co.course_id
         JOIN semesters se ON se.id = co.semester_id AND se.status = 'current'
         WHERE co.lecturer_id = ?
         ORDER BY c.code"
    );
    $offeringsStmt->bind_param('i', $lecturerId);
    $offeringsStmt->execute();
    $offerings = $offeringsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $offeringsStmt->close();

    $rows = [];
    $sessionsBySemesterId = [];
    foreach ($offerings as $off) {
        $semesterId = (int) $off['semester_id'];
        if (!isset($sessionsBySemesterId[$semesterId])) {
            $sessionsBySemesterId[$semesterId] = get_sessions_for_semester($conn, $semesterId);
        }

        foreach ($sessionsBySemesterId[$semesterId] as $session) {
            if ($session['date'] === null || $session['date'] > $today) {
                continue;
            }

            $checkinStmt = $conn->prepare('SELECT id, check_in_at, check_out_at FROM lecturer_checkins WHERE lecturer_id = ? AND course_id = ? AND session_id = ?');
            $checkinStmt->bind_param('iii', $lecturerId, $off['course_id'], $session['id']);
            $checkinStmt->execute();
            $checkin = $checkinStmt->get_result()->fetch_assoc();
            $checkinStmt->close();

            $rows[] = [
                'course_id' => (int) $off['course_id'],
                'course_code' => (string) $off['code'],
                'course_name' => (string) $off['name'],
                'semester_name' => (string) $off['semester_name'],
                'session_id' => (int) $session['id'],
                'session_label' => $session['label'],
                'session_date' => $session['date'],
                'scheduled_start_time' => $off['scheduled_start_time'],
                'scheduled_end_time' => $off['scheduled_end_time'],
                'checkin' => $checkin ?: null,
            ];
        }
    }

    usort($rows, static fn ($a, $b) => $b['session_date'] <=> $a['session_date']);

    return $rows;
}

/**
 * Rolls lecturer_checkin_eligible_sessions() up into one row per course
 * (total eligible sessions vs. how many were actually checked into) —
 * used for the "Total Xiiso Check-Ins by Course" summary on both
 * lecturer/checkin.php and lecturer_checkins.php.
 *
 * @return array<int, array{course_id: int, course_label: string, total: int, checked_in: int, pct: float}>
 */
function lecturer_checkin_course_summary(mysqli $conn, int $lecturerId): array
{
    $byCourse = [];
    foreach (lecturer_checkin_eligible_sessions($conn, $lecturerId) as $row) {
        $cid = $row['course_id'];
        if (!isset($byCourse[$cid])) {
            $byCourse[$cid] = [
                'course_id' => $cid,
                'course_label' => $row['course_code'] . ' — ' . $row['course_name'],
                'total' => 0,
                'checked_in' => 0,
            ];
        }
        $byCourse[$cid]['total']++;
        if ($row['checkin'] !== null) {
            $byCourse[$cid]['checked_in']++;
        }
    }

    foreach ($byCourse as &$c) {
        $c['pct'] = $c['total'] > 0 ? round(100 * $c['checked_in'] / $c['total'], 1) : 0.0;
    }
    unset($c);

    return array_values($byCourse);
}
