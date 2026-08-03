<?php
/**
 * Semester + Session ("Xiiso") management — shared by System Administrator,
 * Head of Academic Affairs, and Dean (own faculty only, per CLAUDE.md §4).
 * Lives at the app root (not under /admin) because it is reused across
 * roles, same pattern as attendance.php and reports.php. Dean's
 * faculty_id is always read from $_SESSION, never trusted from request
 * input (same pattern as admin/students.php/admin/departments.php/etc.).
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/nav_items.php';
require_once __DIR__ . '/includes/semester_helpers.php';

require_role(['system_admin', 'head_academic', 'dean']);

$conn = db();
$role = current_role();

// ---------------------------------------------------------------------
// University settings (drives the sky-blue top strip)
// ---------------------------------------------------------------------
$settings = [];
$settingsResult = $conn->query('SELECT `key`, `value` FROM settings');
if ($settingsResult) {
    while ($row = $settingsResult->fetch_assoc()) {
        $settings[$row['key']] = $row['value'];
    }
}

$deanFacultyId = 0;
$deanFacultyName = '';
if ($role === 'dean') {
    $deanFacultyId = (int) ($_SESSION['faculty_id'] ?? 0);
    if ($deanFacultyId > 0) {
        $fStmt = $conn->prepare('SELECT name FROM faculties WHERE id = ?');
        $fStmt->bind_param('i', $deanFacultyId);
        $fStmt->execute();
        $fRow = $fStmt->get_result()->fetch_assoc();
        $fStmt->close();
        $deanFacultyName = $fRow ? (string) $fRow['name'] : '';
    }
}

/**
 * Dean's write actions (generate sessions, set current, save dates, bulk
 * delete) must never operate on another faculty's semester even via a
 * crafted semester_id in the POST body — the list/detail side is already
 * naturally scoped since $semesters (built further below) only contains
 * the Dean's own faculty's rows, but every POST handler reads semester_id
 * straight from $_POST, so each one re-checks ownership here first.
 */
function dean_owns_semester(mysqli $conn, int $semesterId, int $deanFacultyId): bool
{
    $stmt = $conn->prepare('SELECT id FROM semesters WHERE id = ? AND faculty_id = ?');
    $stmt->bind_param('ii', $semesterId, $deanFacultyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row !== null;
}

/**
 * Which program year a semester falls in, e.g. "Semester 4" at 3
 * semesters/year is Year 2. Purely a display convenience — returns null if
 * the semester's name has no digits to derive a sequence number from.
 */
function semester_year_number(string $semesterName, int $semestersPerYear): ?int
{
    $digits = preg_replace('/\D/', '', $semesterName);
    if ($digits === '' || $semestersPerYear <= 0) {
        return null;
    }

    return (int) ceil(((int) $digits) / $semestersPerYear);
}

// ---------------------------------------------------------------------
// Flash messages (post-redirect-get, same pattern as the other admin pages)
// ---------------------------------------------------------------------
$successMessage = '';
$errorMessage = '';
if (!empty($_SESSION['flash_success'])) {
    $successMessage = (string) $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    $errorMessage = (string) $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

$academicYears = $conn->query('SELECT id, label, is_current FROM academic_years ORDER BY label DESC')->fetch_all(MYSQLI_ASSOC);
$faculties = $role === 'dean'
    ? array_filter($conn->query('SELECT id, name, semesters_per_year, total_semesters FROM faculties ORDER BY name')->fetch_all(MYSQLI_ASSOC), static fn ($f) => (int) $f['id'] === $deanFacultyId)
    : $conn->query('SELECT id, name, semesters_per_year, total_semesters FROM faculties ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$semestersPerYearByFacultyId = [];
$totalSemestersByFacultyId = [];
foreach ($faculties as $f) {
    $semestersPerYearByFacultyId[(int) $f['id']] = (int) $f['semesters_per_year'];
    $totalSemestersByFacultyId[(int) $f['id']] = (int) $f['total_semesters'];
}

$createFormValues = [
    'academic_year_id' => '',
    'faculty_id' => $role === 'dean' ? (string) $deanFacultyId : '',
    'name' => '',
    'start_date' => '',
];

const SEMESTER_STATUSES = ['waiting', 'current', 'ended'];

// Edit mode: the Create Semester card doubles as the Edit Semester card,
// same toggle-by-GET-param convention as admin/departments.php. On a fresh
// GET load with ?edit=1, it's pre-filled from $selectedSemester once that's
// resolved further below (never trusted from request input directly — the
// values come from the already role-scoped $semesters list). On a failed
// update_semester POST, $editMode/$createFormValues are set directly by
// that handler instead, and $forcedSelectedSemesterId keeps the right
// semester's detail panel open for the re-rendered form.
$editMode = isset($_GET['edit']);
$forcedSelectedSemesterId = null;

/**
 * Shared by the bulk "Delete Selected" action over a semester's Xiiso
 * sessions list — there is no single-row delete button for sessions (they
 * are auto-generated as a fixed set of 12 via "Generate Sessions"), so this
 * is the one and only delete path, used per-row inside the bulk loop.
 * $semesterId scopes the lookup so a crafted session_id from a different
 * semester can't be deleted via this page's selected-semester context.
 */
function delete_session_row(mysqli $conn, int $sessionId, int $semesterId): array
{
    $sessionStmt = $conn->prepare(
        "SELECT id, session_number, type FROM sessions WHERE id = ? AND semester_id = ?"
    );
    $sessionStmt->bind_param('ii', $sessionId, $semesterId);
    $sessionStmt->execute();
    $sessionRow = $sessionStmt->get_result()->fetch_assoc();
    $sessionStmt->close();

    if (!$sessionRow) {
        return ['ok' => false, 'message' => 'Session not found.'];
    }

    $label = session_label($sessionRow);

    $attendanceCountStmt = $conn->prepare('SELECT COUNT(*) AS c FROM attendance WHERE session_id = ?');
    $attendanceCountStmt->bind_param('i', $sessionId);
    $attendanceCountStmt->execute();
    $attendanceCount = (int) ($attendanceCountStmt->get_result()->fetch_assoc()['c'] ?? 0);
    $attendanceCountStmt->close();

    if ($attendanceCount > 0) {
        return [
            'ok' => false,
            'message' => $label . ': still has ' . $attendanceCount . ' attendance record' . ($attendanceCount === 1 ? '' : 's') . '.',
        ];
    }

    $deleteStmt = $conn->prepare('DELETE FROM sessions WHERE id = ?');
    $deleteStmt->bind_param('i', $sessionId);
    $deleteStmt->execute();
    $deleteStmt->close();

    return ['ok' => true, 'message' => $label . ' deleted.'];
}

/**
 * Delete a semester — system_admin (any) or dean (own faculty only, via
 * $deanFacultyId). Blocked if any course_offerings reference it, if any
 * attendance record is reachable through its sessions, or if any student
 * is still assigned to it. Sessions with no attendance yet, and the
 * semester's own row, cascade-delete automatically (see
 * fk_sessions_semester / fk_offerings_semester ON DELETE CASCADE) once
 * those checks pass — no separate cleanup needed.
 */
function delete_semester_row(mysqli $conn, int $semesterId, string $role, int $deanFacultyId): array
{
    if ($role === 'dean') {
        $semStmt = $conn->prepare('SELECT id, name, faculty_id, status FROM semesters WHERE id = ? AND faculty_id = ?');
        $semStmt->bind_param('ii', $semesterId, $deanFacultyId);
    } else {
        $semStmt = $conn->prepare('SELECT id, name, faculty_id, status FROM semesters WHERE id = ?');
        $semStmt->bind_param('i', $semesterId);
    }
    $semStmt->execute();
    $semRow = $semStmt->get_result()->fetch_assoc();
    $semStmt->close();

    if (!$semRow) {
        return ['ok' => false, 'message' => 'Semester not found.'];
    }

    $label = (string) $semRow['name'];

    if ($semRow['status'] === 'current') {
        return ['ok' => false, 'message' => $label . ' is the current semester for this faculty — set it to Waiting or Ended before deleting it.'];
    }

    $blockers = [];

    $offeringCountStmt = $conn->prepare('SELECT COUNT(*) AS c FROM course_offerings WHERE semester_id = ?');
    $offeringCountStmt->bind_param('i', $semesterId);
    $offeringCountStmt->execute();
    $offeringCount = (int) ($offeringCountStmt->get_result()->fetch_assoc()['c'] ?? 0);
    $offeringCountStmt->close();
    if ($offeringCount > 0) {
        $blockers[] = $offeringCount . ' course offering' . ($offeringCount === 1 ? '' : 's');
    }

    $attendanceCountStmt = $conn->prepare(
        'SELECT COUNT(*) AS c FROM attendance a JOIN sessions se ON se.id = a.session_id WHERE se.semester_id = ?'
    );
    $attendanceCountStmt->bind_param('i', $semesterId);
    $attendanceCountStmt->execute();
    $attendanceCount = (int) ($attendanceCountStmt->get_result()->fetch_assoc()['c'] ?? 0);
    $attendanceCountStmt->close();
    if ($attendanceCount > 0) {
        $blockers[] = $attendanceCount . ' attendance record' . ($attendanceCount === 1 ? '' : 's');
    }

    $studentCountStmt = $conn->prepare('SELECT COUNT(*) AS c FROM students WHERE semester_id = ?');
    $studentCountStmt->bind_param('i', $semesterId);
    $studentCountStmt->execute();
    $studentCount = (int) ($studentCountStmt->get_result()->fetch_assoc()['c'] ?? 0);
    $studentCountStmt->close();
    if ($studentCount > 0) {
        $blockers[] = $studentCount . ' student' . ($studentCount === 1 ? '' : 's') . ' assigned to this semester';
    }

    if (!empty($blockers)) {
        return ['ok' => false, 'message' => $label . ': still has ' . implode(', ', $blockers) . '.'];
    }

    $deleteStmt = $conn->prepare('DELETE FROM semesters WHERE id = ?');
    $deleteStmt->bind_param('i', $semesterId);
    $deleteStmt->execute();
    $deleteStmt->close();

    return ['ok' => true, 'message' => $label . ' deleted.'];
}

// ---------------------------------------------------------------------
// Handle POST actions
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_semester') {
        // A Dean's faculty is always the session's own faculty_id — never the
        // posted value — so a crafted faculty_id cannot create a semester in
        // a faculty they don't oversee.
        $facultyId = $role === 'dean' ? $deanFacultyId : (int) ($_POST['faculty_id'] ?? 0);
        $academicYearId = (int) ($_POST['academic_year_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $startDateInput = trim((string) ($_POST['start_date'] ?? ''));
        $createFormValues = [
            'faculty_id' => (string) $facultyId,
            'academic_year_id' => (string) $academicYearId,
            'name' => $name,
            'start_date' => $startDateInput,
        ];

        $facultyValid = false;
        foreach ($faculties as $f) {
            if ((int) $f['id'] === $facultyId) {
                $facultyValid = true;
                break;
            }
        }
        $academicYearValid = false;
        foreach ($academicYears as $ay) {
            if ((int) $ay['id'] === $academicYearId) {
                $academicYearValid = true;
                break;
            }
        }

        // Start Date is always typed by hand (never auto-suggested/chained
        // from a previous semester) — it drives the automatic End Date + 12
        // Xiiso date fill-in below.
        $startDate = null;
        $endDate = null;
        $validSemesterNames = $facultyValid ? semester_name_options_for_faculty($totalSemestersByFacultyId[$facultyId] ?? 0) : [];
        $validationError = '';
        if (!$facultyValid) {
            $validationError = 'Please select a valid faculty.';
        } elseif (!$academicYearValid) {
            $validationError = 'Please select a valid academic year.';
        } elseif (!in_array($name, $validSemesterNames, true)) {
            $validationError = 'Please select which semester this is from the dropdown.';
        } elseif ($startDateInput === '') {
            $validationError = 'Please provide a start date.';
        } elseif (!DateTime::createFromFormat('Y-m-d', $startDateInput)) {
            $validationError = 'Please provide a valid start date.';
        } else {
            $dupStmt = $conn->prepare('SELECT id FROM semesters WHERE faculty_id = ? AND academic_year_id = ? AND name = ?');
            $dupStmt->bind_param('iis', $facultyId, $academicYearId, $name);
            $dupStmt->execute();
            if ($dupStmt->get_result()->fetch_assoc()) {
                $validationError = 'This faculty already has a semester with this same name and academic year.';
            }
            $dupStmt->close();

            if ($validationError === '' && $startDateInput !== '') {
                $startDate = $startDateInput;
                $endDate = semester_end_date_from_start($startDate);
            }
        }

        if ($validationError === '') {
            $conn->begin_transaction();
            try {
                $insertStmt = $conn->prepare(
                    'INSERT INTO semesters (academic_year_id, faculty_id, name, start_date, end_date) VALUES (?, ?, ?, ?, ?)'
                );
                $insertStmt->bind_param('iisss', $academicYearId, $facultyId, $name, $startDate, $endDate);
                $insertStmt->execute();
                $newSemesterId = (int) $conn->insert_id;
                $insertStmt->close();

                generate_sessions_for_semester($conn, $newSemesterId);

                $conn->commit();
                $_SESSION['flash_success'] = $startDate !== null
                    ? '"' . $name . '" created (' . $startDate . ' to ' . $endDate . ') with all 12 Xiiso dates filled in automatically.'
                    : '"' . $name . '" created with all 12 Xiiso sessions — set its status and Xiiso dates below.';
                redirect_to('semesters.php?semester_id=' . $newSemesterId);
            } catch (Throwable $e) {
                $conn->rollback();
                $_SESSION['flash_error'] = 'Could not create the semester. Please try again.';
            }
        }

        $errorMessage = $validationError;
    } elseif ($action === 'update_semester') {
        $semesterId = (int) ($_POST['semester_id'] ?? 0);
        if ($role === 'dean' && !dean_owns_semester($conn, $semesterId, $deanFacultyId)) {
            $_SESSION['flash_error'] = 'Selected semester does not exist.';
            redirect_to('semesters.php');
        }

        $currentStmt = $conn->prepare('SELECT faculty_id, academic_year_id, name, start_date FROM semesters WHERE id = ?');
        $currentStmt->bind_param('i', $semesterId);
        $currentStmt->execute();
        $currentRow = $currentStmt->get_result()->fetch_assoc();
        $currentStmt->close();

        if (!$currentRow) {
            $_SESSION['flash_error'] = 'Selected semester does not exist.';
            redirect_to('semesters.php');
        }

        // A Dean's faculty is always the session's own faculty_id — never the
        // posted value — same lock as create_semester above.
        $facultyId = $role === 'dean' ? $deanFacultyId : (int) ($_POST['faculty_id'] ?? 0);
        $academicYearId = (int) ($_POST['academic_year_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $startDateInput = trim((string) ($_POST['start_date'] ?? ''));
        $createFormValues = [
            'faculty_id' => (string) $facultyId,
            'academic_year_id' => (string) $academicYearId,
            'name' => $name,
            'start_date' => $startDateInput,
        ];

        $facultyValid = false;
        foreach ($faculties as $f) {
            if ((int) $f['id'] === $facultyId) {
                $facultyValid = true;
                break;
            }
        }
        $academicYearValid = false;
        foreach ($academicYears as $ay) {
            if ((int) $ay['id'] === $academicYearId) {
                $academicYearValid = true;
                break;
            }
        }

        // Same required, hand-typed Start Date as create_semester — a value
        // only ever fills in still-empty Xiiso dates
        // (generate_sessions_for_semester() never overwrites a date that's
        // already set), so re-submitting the same or a new Start Date on an
        // already-dated semester is always safe.
        $startDate = null;
        $endDate = null;
        $validSemesterNames = $facultyValid ? semester_name_options_for_faculty($totalSemestersByFacultyId[$facultyId] ?? 0) : [];
        $validationError = '';
        if (!$facultyValid) {
            $validationError = 'Please select a valid faculty.';
        } elseif (!$academicYearValid) {
            $validationError = 'Please select a valid academic year.';
        } elseif (!in_array($name, $validSemesterNames, true)) {
            $validationError = 'Please select which semester this is from the dropdown.';
        } elseif ($startDateInput === '') {
            $validationError = 'Please provide a start date.';
        } elseif (!DateTime::createFromFormat('Y-m-d', $startDateInput)) {
            $validationError = 'Please provide a valid start date.';
        } else {
            $dupStmt = $conn->prepare('SELECT id FROM semesters WHERE faculty_id = ? AND academic_year_id = ? AND name = ? AND id != ?');
            $dupStmt->bind_param('iisi', $facultyId, $academicYearId, $name, $semesterId);
            $dupStmt->execute();
            if ($dupStmt->get_result()->fetch_assoc()) {
                $validationError = 'Another semester already has this same Faculty, Academic Year, and Name.';
            }
            $dupStmt->close();

            if ($validationError === '' && $startDateInput !== '') {
                $startDate = $startDateInput;
                $endDate = semester_end_date_from_start($startDate);
            }
        }

        // Changing Faculty on a semester that already has course_offerings
        // or students pointing at it would silently orphan them
        // (course_offerings' faculty match against courses' own
        // department, students' own faculty — both computed elsewhere,
        // neither updates itself when a semester's faculty_id changes
        // underneath it), so that specific change is blocked once real data
        // depends on it — same "block, don't corrupt" convention as
        // delete_semester_row() below. Academic Year is purely a label
        // (semester_year_number()'s "Year N" display, report filters) —
        // nothing scopes course_offerings/attendance/students by it, so
        // correcting a semester's Academic Year (e.g. it was picked wrong
        // when the semester was created) stays allowed even with real data
        // already attached. Renaming stays allowed regardless of either.
        $facultyChanged = $validationError === '' && $facultyId !== (int) $currentRow['faculty_id'];

        if ($facultyChanged) {
            $blockers = [];

            $offeringCountStmt = $conn->prepare('SELECT COUNT(*) AS c FROM course_offerings WHERE semester_id = ?');
            $offeringCountStmt->bind_param('i', $semesterId);
            $offeringCountStmt->execute();
            $offeringCount = (int) ($offeringCountStmt->get_result()->fetch_assoc()['c'] ?? 0);
            $offeringCountStmt->close();
            if ($offeringCount > 0) {
                $blockers[] = $offeringCount . ' course offering' . ($offeringCount === 1 ? '' : 's');
            }

            $studentCountStmt = $conn->prepare('SELECT COUNT(*) AS c FROM students WHERE semester_id = ?');
            $studentCountStmt->bind_param('i', $semesterId);
            $studentCountStmt->execute();
            $studentCount = (int) ($studentCountStmt->get_result()->fetch_assoc()['c'] ?? 0);
            $studentCountStmt->close();
            if ($studentCount > 0) {
                $blockers[] = $studentCount . ' student' . ($studentCount === 1 ? '' : 's') . ' assigned';
            }

            if (!empty($blockers)) {
                $validationError = 'Cannot change Faculty: this semester still has ' . implode(', ', $blockers) . '. You can still rename it or correct its Academic Year.';
            }
        }

        if ($validationError === '') {
            if ($startDate !== null) {
                $updateStmt = $conn->prepare('UPDATE semesters SET faculty_id = ?, academic_year_id = ?, name = ?, start_date = ?, end_date = ? WHERE id = ?');
                $updateStmt->bind_param('iisssi', $facultyId, $academicYearId, $name, $startDate, $endDate, $semesterId);
            } else {
                $updateStmt = $conn->prepare('UPDATE semesters SET faculty_id = ?, academic_year_id = ?, name = ? WHERE id = ?');
                $updateStmt->bind_param('iisi', $facultyId, $academicYearId, $name, $semesterId);
            }
            $updateStmt->execute();
            $updateStmt->close();

            if ($startDate !== null) {
                // Only fills whichever of the 12 Xiiso sessions still have no
                // date at all — never touches one already set, whether that
                // was auto-filled before or typed in by hand.
                generate_sessions_for_semester($conn, $semesterId);
                $_SESSION['flash_success'] = 'Semester updated (' . $startDate . ' to ' . $endDate . ') — any still-empty Xiiso dates were filled in automatically.';
            } else {
                $_SESSION['flash_success'] = 'Semester updated.';
            }
            redirect_to('semesters.php?semester_id=' . $semesterId);
        }

        $errorMessage = $validationError;
        $editMode = true;
        $forcedSelectedSemesterId = $semesterId;
    } elseif ($action === 'assign_faculty') {
        // Not applicable to Dean: a Dean's own semesters always already have
        // faculty_id set at creation (locked to their own faculty above), so
        // this "backfill a legacy unassigned semester" action has nothing a
        // Dean could legitimately use it for.
        if ($role === 'dean') {
            $_SESSION['flash_error'] = 'Not permitted.';
            redirect_to('semesters.php');
        }

        $semesterId = (int) ($_POST['semester_id'] ?? 0);
        $facultyId = (int) ($_POST['faculty_id'] ?? 0);

        $facultyValid = false;
        foreach ($faculties as $f) {
            if ((int) $f['id'] === $facultyId) {
                $facultyValid = true;
                break;
            }
        }

        $semStmt = $conn->prepare('SELECT name FROM semesters WHERE id = ?');
        $semStmt->bind_param('i', $semesterId);
        $semStmt->execute();
        $semRow = $semStmt->get_result()->fetch_assoc();
        $semStmt->close();

        if (!$semRow) {
            $_SESSION['flash_error'] = 'Selected semester does not exist.';
        } elseif (!$facultyValid) {
            $_SESSION['flash_error'] = 'Please select a valid faculty.';
        } else {
            $dupStmt = $conn->prepare('SELECT id FROM semesters WHERE faculty_id = ? AND academic_year_id = (SELECT academic_year_id FROM semesters WHERE id = ?) AND name = (SELECT name FROM semesters WHERE id = ?) AND id != ?');
            $dupStmt->bind_param('iiii', $facultyId, $semesterId, $semesterId, $semesterId);
            $dupStmt->execute();
            if ($dupStmt->get_result()->fetch_assoc()) {
                $_SESSION['flash_error'] = 'That faculty already has a semester with this same name and academic year.';
            } else {
                $updateStmt = $conn->prepare('UPDATE semesters SET faculty_id = ? WHERE id = ?');
                $updateStmt->bind_param('ii', $facultyId, $semesterId);
                $updateStmt->execute();
                $updateStmt->close();
                $_SESSION['flash_success'] = 'Faculty assigned to "' . $semRow['name'] . '".';
            }
            $dupStmt->close();
        }
        redirect_to('semesters.php?semester_id=' . $semesterId);
    } elseif ($action === 'set_status') {
        // The three manual states (Waiting / Current / Ended) are set
        // directly by whichever button was clicked — no date arithmetic,
        // no automatic recompute. Nothing here clears another semester's
        // status, so more than one semester (even within the same faculty)
        // can be "current" at once if the admin/dean chooses that.
        $semesterId = (int) ($_POST['semester_id'] ?? 0);
        $newStatus = (string) ($_POST['status'] ?? '');
        if ($role === 'dean' && !dean_owns_semester($conn, $semesterId, $deanFacultyId)) {
            $_SESSION['flash_error'] = 'Selected semester does not exist.';
            redirect_to('semesters.php');
        }

        if (!in_array($newStatus, SEMESTER_STATUSES, true)) {
            $_SESSION['flash_error'] = 'Invalid status.';
            redirect_to('semesters.php?semester_id=' . $semesterId);
        }

        $semStmt = $conn->prepare('SELECT name FROM semesters WHERE id = ?');
        $semStmt->bind_param('i', $semesterId);
        $semStmt->execute();
        $semRow = $semStmt->get_result()->fetch_assoc();
        $semStmt->close();

        if (!$semRow) {
            $_SESSION['flash_error'] = 'Selected semester does not exist.';
        } else {
            $isCurrent = $newStatus === 'current' ? 1 : 0;
            $updateStmt = $conn->prepare('UPDATE semesters SET status = ?, is_current = ? WHERE id = ?');
            $updateStmt->bind_param('sii', $newStatus, $isCurrent, $semesterId);
            $updateStmt->execute();
            $updateStmt->close();

            $statusLabel = ['waiting' => 'Waiting', 'current' => 'Current', 'ended' => 'Ended'][$newStatus];
            $_SESSION['flash_success'] = '"' . $semRow['name'] . '" set to ' . $statusLabel . '.';
        }
        redirect_to('semesters.php?semester_id=' . $semesterId);
    } elseif ($action === 'generate_sessions') {
        $semesterId = (int) ($_POST['semester_id'] ?? 0);
        if ($role === 'dean' && !dean_owns_semester($conn, $semesterId, $deanFacultyId)) {
            $_SESSION['flash_error'] = 'Selected semester does not exist.';
            redirect_to('semesters.php');
        }
        $semStmt = $conn->prepare('SELECT name FROM semesters WHERE id = ?');
        $semStmt->bind_param('i', $semesterId);
        $semStmt->execute();
        $semRow = $semStmt->get_result()->fetch_assoc();
        $semStmt->close();

        if (!$semRow) {
            $_SESSION['flash_error'] = 'Selected semester does not exist.';
        } else {
            generate_sessions_for_semester($conn, $semesterId);
            $_SESSION['flash_success'] = '12 Xiiso sessions generated for "' . $semRow['name'] . '".';
        }
        redirect_to('semesters.php?semester_id=' . $semesterId);
    } elseif ($action === 'save_session_dates') {
        $semesterId = (int) ($_POST['semester_id'] ?? 0);
        if ($role === 'dean' && !dean_owns_semester($conn, $semesterId, $deanFacultyId)) {
            $_SESSION['flash_error'] = 'Selected semester does not exist.';
            redirect_to('semesters.php');
        }
        $dates = (array) ($_POST['session_date'] ?? []);

        $updateStmt = $conn->prepare('UPDATE sessions SET date = ? WHERE id = ? AND semester_id = ?');
        foreach ($dates as $sessionId => $dateValue) {
            $sessionIdInt = (int) $sessionId;
            $dateValue = trim((string) $dateValue);
            $dateOrNull = $dateValue !== '' && DateTime::createFromFormat('Y-m-d', $dateValue) ? $dateValue : null;
            $updateStmt->bind_param('sii', $dateOrNull, $sessionIdInt, $semesterId);
            $updateStmt->execute();
        }
        $updateStmt->close();

        $_SESSION['flash_success'] = 'Session dates saved.';
        redirect_to('semesters.php?semester_id=' . $semesterId);
    } elseif ($action === 'bulk_delete_sessions') {
        $semesterId = (int) ($_POST['semester_id'] ?? 0);
        if ($role === 'dean' && !dean_owns_semester($conn, $semesterId, $deanFacultyId)) {
            $_SESSION['flash_error'] = 'Selected semester does not exist.';
            redirect_to('semesters.php');
        }
        $ids = array_values(array_unique(array_filter(
            array_map('intval', (array) ($_POST['session_ids'] ?? [])),
            static fn ($id) => $id > 0
        )));

        if (empty($ids)) {
            $_SESSION['flash_error'] = 'No Xiiso sessions were selected.';
        } else {
            $deletedCount = 0;
            $skippedMessages = [];
            foreach ($ids as $sid) {
                $result = delete_session_row($conn, $sid, $semesterId);
                if ($result['ok']) {
                    $deletedCount++;
                } else {
                    $skippedMessages[] = $result['message'];
                }
            }

            $summary = $deletedCount . ' of ' . count($ids) . ' selected session' . (count($ids) === 1 ? '' : 's') . ' deleted.';
            if (!empty($skippedMessages)) {
                $summary .= ' Skipped: ' . implode(' | ', $skippedMessages);
            }
            if ($deletedCount > 0) {
                $_SESSION['flash_success'] = $summary;
            } else {
                $_SESSION['flash_error'] = $summary;
            }
        }
        redirect_to('semesters.php?semester_id=' . $semesterId);
    } elseif ($action === 'delete_semester') {
        if (!in_array($role, ['system_admin', 'dean'], true)) {
            $_SESSION['flash_error'] = 'Not permitted.';
            redirect_to('semesters.php');
        }

        $semesterId = (int) ($_POST['semester_id'] ?? 0);
        $result = delete_semester_row($conn, $semesterId, $role, $deanFacultyId);

        if ($result['ok']) {
            $_SESSION['flash_success'] = $result['message'];
        } else {
            $_SESSION['flash_error'] = $result['message'];
        }
        redirect_to('semesters.php');
    }
}

// ---------------------------------------------------------------------
// Data for rendering
// ---------------------------------------------------------------------
if ($role === 'dean') {
    $semListStmt = $conn->prepare(
        "SELECT s.id, s.academic_year_id, s.faculty_id, s.context_department_id, s.name, s.start_date, s.end_date, s.is_current, s.status,
                ay.label AS academic_year_label, f.name AS faculty_name, cd.name AS context_department_name
         FROM semesters s
         JOIN academic_years ay ON ay.id = s.academic_year_id
         JOIN faculties f ON f.id = s.faculty_id
         LEFT JOIN departments cd ON cd.id = s.context_department_id
         WHERE s.faculty_id = ?
         ORDER BY ay.label DESC, s.id DESC"
    );
    $semListStmt->bind_param('i', $deanFacultyId);
    $semListStmt->execute();
    $semesters = $semListStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $semListStmt->close();
} else {
    $semesters = $conn->query(
        "SELECT s.id, s.academic_year_id, s.faculty_id, s.context_department_id, s.name, s.start_date, s.end_date, s.is_current, s.status,
                ay.label AS academic_year_label, f.name AS faculty_name, cd.name AS context_department_name
         FROM semesters s
         JOIN academic_years ay ON ay.id = s.academic_year_id
         LEFT JOIN faculties f ON f.id = s.faculty_id
         LEFT JOIN departments cd ON cd.id = s.context_department_id
         ORDER BY (s.faculty_id IS NULL), f.name, ay.label DESC, s.id DESC"
    )->fetch_all(MYSQLI_ASSOC);
}

$selectedSemesterId = $forcedSelectedSemesterId ?? (int) ($_GET['semester_id'] ?? 0);
if ($selectedSemesterId === 0 && !empty($semesters)) {
    $selectedSemesterId = (int) $semesters[0]['id'];
}

$selectedSemester = null;
$selectedSemesterSessions = [];
foreach ($semesters as $s) {
    if ((int) $s['id'] === $selectedSemesterId) {
        $selectedSemester = $s;
        break;
    }
}
if ($selectedSemester) {
    $selectedSemesterSessions = get_sessions_for_semester($conn, $selectedSemesterId);
}

// Pre-fill the Create/Edit card from the (already role-scoped) selected
// semester only on a fresh GET load — a failed update_semester POST above
// already set $createFormValues from what the admin actually typed, which
// must win over re-fetching the unchanged DB row here.
if ($editMode && $_SERVER['REQUEST_METHOD'] !== 'POST' && $selectedSemester !== null) {
    $createFormValues = [
        'faculty_id' => (string) $selectedSemester['faculty_id'],
        'academic_year_id' => (string) $selectedSemester['academic_year_id'],
        'name' => $selectedSemester['name'],
        'start_date' => (string) ($selectedSemester['start_date'] ?? ''),
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Semesters — ADMAS Attendance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(BASE_URL) ?>/assets/css/app.css" rel="stylesheet">
</head>
<body>
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="main-content">
        <?php include __DIR__ . '/includes/topbar.php'; ?>

        <div class="page-body">
            <div class="scope-banner">
                <i class="bi bi-shield-check"></i>
                <?php if ($role === 'dean'): ?>
                    Access scope: <?= htmlspecialchars($deanFacultyName) ?> Faculty only
                <?php else: ?>
                    Access scope: All academic years and semesters
                <?php endif; ?>
            </div>

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--admas-text);">Semesters</h4>
                    <p class="text-muted mb-0">Create semesters and generate their 12 Xiiso sessions (10 regular + Midterm + Final).</p>
                </div>
            </div>

            <?php if ($successMessage !== ''): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($successMessage) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if ($errorMessage !== ''): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($errorMessage) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="row g-4">
                <div class="col-lg-5">
                    <div class="admas-card p-4 mb-4">
                        <h6 class="small text-uppercase text-muted mb-2"><?= $editMode ? 'Edit Semester' : 'Create Semester' ?></h6>
                        <p class="text-muted small mb-2">
                            <?php if ($editMode): ?>
                                Update this semester's Faculty, Academic Year, or name. Its status and Xiiso dates are
                                set separately, in the panel on the right.
                            <?php else: ?>
                                Pick a faculty and academic year, and state which semester this is. After creating it,
                                set its status (Waiting / Current / Ended) and its 12 Xiiso dates below.
                            <?php endif; ?>
                        </p>
                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/semesters.php" class="d-flex flex-column gap-2">
                            <input type="hidden" name="action" value="<?= $editMode ? 'update_semester' : 'create_semester' ?>">
                            <?php if ($editMode): ?>
                                <input type="hidden" name="semester_id" value="<?= $selectedSemesterId ?>">
                            <?php endif; ?>

                            <div>
                                <label class="form-label small mb-1">Faculty</label>
                                <?php if ($role === 'dean'): ?>
                                    <select class="form-select form-select-sm" disabled>
                                        <option selected value="<?= (int) $deanFacultyId ?>"><?= htmlspecialchars($deanFacultyName) ?></option>
                                    </select>
                                    <div class="form-text">Locked to your own faculty.</div>
                                <?php else: ?>
                                    <select class="form-select form-select-sm" name="faculty_id" id="semesterFacultySelect" required onchange="admasUpdateSemesterNameOptions(this.value)">
                                        <option value="">Select faculty</option>
                                        <?php foreach ($faculties as $f): ?>
                                            <option value="<?= (int) $f['id'] ?>" <?= (string) $f['id'] === $createFormValues['faculty_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($f['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </div>
                            <div>
                                <label class="form-label small mb-1">Semester</label>
                                <?php $initialFacultyId = $role === 'dean' ? $deanFacultyId : (int) $createFormValues['faculty_id']; ?>
                                <select class="form-select form-select-sm" name="name" id="semesterNameSelect" required <?= $initialFacultyId <= 0 ? 'disabled' : '' ?>>
                                    <?php if ($initialFacultyId <= 0): ?>
                                        <option value="">Select faculty first</option>
                                    <?php else: ?>
                                        <option value="">Select semester</option>
                                        <?php foreach (semester_name_options_for_faculty($totalSemestersByFacultyId[$initialFacultyId] ?? 0) as $opt): ?>
                                            <option value="<?= htmlspecialchars($opt) ?>" <?= $opt === $createFormValues['name'] ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div>
                                <label class="form-label small mb-1">Academic Year</label>
                                <select class="form-select form-select-sm" name="academic_year_id" required <?= empty($academicYears) ? 'disabled' : '' ?>>
                                    <option value="">Select year</option>
                                    <?php foreach ($academicYears as $ay): ?>
                                        <option value="<?= (int) $ay['id'] ?>" <?= (string) $ay['id'] === $createFormValues['academic_year_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($ay['label']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (empty($academicYears)): ?>
                                    <div class="form-text text-danger">No academic years exist yet — ask a System Administrator or Head of Academic Affairs to add one before you can create a semester.</div>
                                <?php endif; ?>
                            </div>
                            <div>
                                <label class="form-label small mb-1">Start Date</label>
                                <input type="date" class="form-control form-control-sm" name="start_date"
                                       value="<?= htmlspecialchars($createFormValues['start_date']) ?>" required>
                                <div class="form-text">End Date and all 12 Xiiso dates are filled in automatically (3 months from this date) — you can still edit individual Xiiso dates afterward.</div>
                            </div>
                            <div class="d-flex gap-2 mt-2">
                                <button type="submit" class="btn btn-primary text-nowrap" style="background-color: var(--admas-sky); border-color: var(--admas-sky);" <?= empty($academicYears) ? 'disabled' : '' ?>>
                                    <?php if ($editMode): ?>
                                        <i class="bi bi-check-lg"></i> Update Semester
                                    <?php else: ?>
                                        <i class="bi bi-plus-lg"></i> Create Semester
                                    <?php endif; ?>
                                </button>
                                <?php if ($editMode): ?>
                                    <a href="<?= htmlspecialchars(BASE_URL) ?>/semesters.php?semester_id=<?= $selectedSemesterId ?>" class="btn btn-outline-secondary text-nowrap">Cancel</a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>

                    <div class="admas-card p-4">
                        <h6 class="small text-uppercase text-muted mb-2">All Semesters</h6>
                        <div class="table-responsive">
                            <table class="table admas-table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Semester</th>
                                        <th>Year</th>
                                        <th>Faculty</th>
                                        <th>Academic Year</th>
                                        <th>Current</th>
                                        <?php if ($role === 'system_admin' || $role === 'dean'): ?>
                                            <th></th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($semesters)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted py-3">No semesters exist yet.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($semesters as $s): ?>
                                            <?php $rowYear = $s['faculty_id'] === null ? null : semester_year_number($s['name'], $semestersPerYearByFacultyId[(int) $s['faculty_id']] ?? 3); ?>
                                            <tr class="<?= (int) $s['id'] === $selectedSemesterId ? 'table-active' : '' ?>" style="cursor:pointer;" onclick="window.location='<?= htmlspecialchars(BASE_URL) ?>/semesters.php?semester_id=<?= (int) $s['id'] ?>'">
                                                <td class="fw-semibold" style="color: var(--admas-text);"><?= htmlspecialchars($s['name']) ?></td>
                                                <td>
                                                    <?= $rowYear !== null ? 'Year ' . $rowYear : '<span class="text-muted">—</span>' ?>
                                                </td>
                                                <td>
                                                    <?php if ($s['faculty_id'] === null): ?>
                                                        <span class="badge-pill badge-warning">Unassigned</span>
                                                    <?php else: ?>
                                                        <?= htmlspecialchars($s['faculty_name']) ?>
                                                        <?php if ($s['context_department_name']): ?>
                                                            <div class="text-muted small"><?= htmlspecialchars($s['context_department_name']) ?></div>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars($s['academic_year_label']) ?></td>
                                                <td>
                                                    <?php if ($s['status'] === 'current'): ?>
                                                        <span class="badge-pill badge-active">Current</span>
                                                    <?php elseif ($s['status'] === 'ended'): ?>
                                                        <span class="badge-pill badge-inactive">Ended</span>
                                                    <?php else: ?>
                                                        <span class="badge-pill badge-neutral">Waiting</span>
                                                    <?php endif; ?>
                                                </td>
                                                <?php if ($role === 'system_admin' || $role === 'dean'): ?>
                                                    <td class="text-end">
                                                        <a href="<?= htmlspecialchars(BASE_URL) ?>/semesters.php?semester_id=<?= (int) $s['id'] ?>&edit=1"
                                                           class="btn-icon" title="Edit semester" aria-label="Edit semester"
                                                           onclick="event.stopPropagation();">
                                                            <i class="bi bi-pencil"></i>
                                                        </a>
                                                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/semesters.php" class="d-inline"
                                                              onclick="event.stopPropagation();"
                                                              onsubmit="event.stopPropagation(); return confirm('Delete this semester? This cannot be undone.');">
                                                            <input type="hidden" name="action" value="delete_semester">
                                                            <input type="hidden" name="semester_id" value="<?= (int) $s['id'] ?>">
                                                            <button type="submit" class="btn-icon text-danger" title="Delete semester" aria-label="Delete semester">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </form>
                                                    </td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="col-lg-7">
                    <?php if ($selectedSemester === null): ?>
                        <div class="admas-card p-4">
                            <p class="text-muted mb-0">Create a semester to manage its Xiiso sessions.</p>
                        </div>
                    <?php else: ?>
                        <div class="admas-card p-4">
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                                <div>
                                    <h6 class="fw-bold mb-1" style="color: var(--admas-text);">
                                        <?= htmlspecialchars($selectedSemester['name']) ?>
                                        <span class="text-muted fw-normal">(<?= htmlspecialchars($selectedSemester['academic_year_label']) ?>)</span>
                                        <?php if ($selectedSemester['status'] === 'current'): ?>
                                            <span class="badge-pill badge-active">Active now</span>
                                        <?php elseif ($selectedSemester['status'] === 'ended'): ?>
                                            <span class="badge-pill badge-inactive">Ended</span>
                                        <?php else: ?>
                                            <span class="badge-pill badge-neutral">Waiting</span>
                                        <?php endif; ?>
                                    </h6>
                                    <p class="text-muted small mb-0">
                                        <?= $selectedSemester['faculty_id'] === null ? 'No faculty assigned' : htmlspecialchars($selectedSemester['faculty_name']) ?>
                                        <?php if ($selectedSemester['context_department_name']): ?>
                                            <span class="fst-italic">(<?= htmlspecialchars($selectedSemester['context_department_name']) ?>)</span>
                                        <?php endif; ?>
                                        <?php if ($selectedSemester['start_date'] || $selectedSemester['end_date']): ?>
                                            &middot; <?= htmlspecialchars($selectedSemester['start_date'] ?? '—') ?> to <?= htmlspecialchars($selectedSemester['end_date'] ?? '—') ?>
                                        <?php endif; ?>
                                        <?php $selYear = semester_year_number($selectedSemester['name'], $semestersPerYearByFacultyId[(int) $selectedSemester['faculty_id']] ?? 3); ?>
                                        <?php if ($selYear !== null): ?>
                                            &middot; Year <?= $selYear ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <div class="d-flex gap-2 flex-wrap">
                                    <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/semesters.php" onsubmit="return confirm('Set this semester to Current?');">
                                        <input type="hidden" name="action" value="set_status">
                                        <input type="hidden" name="status" value="current">
                                        <input type="hidden" name="semester_id" value="<?= $selectedSemesterId ?>">
                                        <button type="submit" class="btn btn-sm <?= $selectedSemester['status'] === 'current' ? 'btn-success' : 'btn-outline-success' ?>" <?= $selectedSemester['status'] === 'current' ? 'disabled' : '' ?>>
                                            <i class="bi bi-play-fill"></i> Start
                                        </button>
                                    </form>
                                    <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/semesters.php" onsubmit="return confirm('Set this semester to Ended?');">
                                        <input type="hidden" name="action" value="set_status">
                                        <input type="hidden" name="status" value="ended">
                                        <input type="hidden" name="semester_id" value="<?= $selectedSemesterId ?>">
                                        <button type="submit" class="btn btn-sm <?= $selectedSemester['status'] === 'ended' ? 'btn-danger' : 'btn-outline-danger' ?>" <?= $selectedSemester['status'] === 'ended' ? 'disabled' : '' ?>>
                                            <i class="bi bi-stop-fill"></i> End
                                        </button>
                                    </form>
                                    <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/semesters.php" onsubmit="return confirm('Set this semester to Waiting?');">
                                        <input type="hidden" name="action" value="set_status">
                                        <input type="hidden" name="status" value="waiting">
                                        <input type="hidden" name="semester_id" value="<?= $selectedSemesterId ?>">
                                        <button type="submit" class="btn btn-sm <?= $selectedSemester['status'] === 'waiting' ? 'btn-secondary' : 'btn-outline-secondary' ?>" <?= $selectedSemester['status'] === 'waiting' ? 'disabled' : '' ?>>
                                            <i class="bi bi-hourglass-split"></i> Waiting
                                        </button>
                                    </form>
                                    <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/semesters.php">
                                        <input type="hidden" name="action" value="generate_sessions">
                                        <input type="hidden" name="semester_id" value="<?= $selectedSemesterId ?>">
                                        <button type="submit" class="btn btn-primary btn-sm" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                                            <i class="bi bi-magic"></i> Generate Sessions
                                        </button>
                                    </form>
                                    <a href="<?= htmlspecialchars(BASE_URL) ?>/semesters.php?semester_id=<?= $selectedSemesterId ?>&edit=1" class="btn btn-sm text-white" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                                        <i class="bi bi-pencil"></i> Edit
                                    </a>
                                </div>
                            </div>

                            <?php if ($selectedSemester['faculty_id'] === null): ?>
                                <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/semesters.php" class="d-flex align-items-end gap-2 mb-3 p-3" style="background: #fff8e6; border-radius: 10px;">
                                    <input type="hidden" name="action" value="assign_faculty">
                                    <input type="hidden" name="semester_id" value="<?= $selectedSemesterId ?>">
                                    <div class="flex-grow-1">
                                        <label class="form-label small mb-1">This semester has no faculty yet — assign one before it can become active</label>
                                        <select class="form-select form-select-sm" name="faculty_id" required>
                                            <option value="">Select faculty</option>
                                            <?php foreach ($faculties as $f): ?>
                                                <option value="<?= (int) $f['id'] ?>"><?= htmlspecialchars($f['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <button type="submit" class="btn btn-sm text-white" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">Assign Faculty</button>
                                </form>
                            <?php endif; ?>

                            <?php if (empty($selectedSemesterSessions)): ?>
                                <p class="text-muted mb-0">No sessions yet — click "Generate Sessions" to create the 12 Xiiso rows (10 regular + Midterm + Final).</p>
                            <?php else: ?>
                                <div class="d-flex justify-content-end mb-2">
                                    <button type="button" id="bulkDeleteSessionsBtn" class="btn btn-outline-danger btn-sm d-none">Delete Selected</button>
                                </div>
                                <form id="bulkDeleteSessionsForm" method="post" action="<?= htmlspecialchars(BASE_URL) ?>/semesters.php" class="d-none">
                                    <input type="hidden" name="action" value="bulk_delete_sessions">
                                    <input type="hidden" name="semester_id" value="<?= $selectedSemesterId ?>">
                                    <div id="bulkDeleteSessionsIds"></div>
                                </form>
                                <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/semesters.php">
                                    <input type="hidden" name="action" value="save_session_dates">
                                    <input type="hidden" name="semester_id" value="<?= $selectedSemesterId ?>">
                                    <div class="table-responsive mb-3">
                                        <table class="table admas-table align-middle mb-0">
                                            <thead>
                                                <tr>
                                                    <th><input type="checkbox" id="selectAllSessions"></th>
                                                    <th>#</th>
                                                    <th>Xiiso</th>
                                                    <th>Type</th>
                                                    <th>Date</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($selectedSemesterSessions as $session): ?>
                                                    <tr>
                                                        <td>
                                                            <input type="checkbox" class="row-check-session" value="<?= (int) $session['id'] ?>"
                                                                   data-label="<?= htmlspecialchars($session['label']) ?>">
                                                        </td>
                                                        <td><?= (int) $session['session_number'] ?></td>
                                                        <td class="fw-semibold" style="color: var(--admas-text);"><?= htmlspecialchars($session['label']) ?></td>
                                                        <td class="text-capitalize"><?= htmlspecialchars($session['type']) ?></td>
                                                        <td>
                                                            <input type="date" class="form-control form-control-sm" name="session_date[<?= (int) $session['id'] ?>]"
                                                                   value="<?= htmlspecialchars((string) ($session['date'] ?? '')) ?>">
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <button type="submit" class="btn btn-primary btn-sm" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                                        <i class="bi bi-save"></i> Save Dates
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= htmlspecialchars(BASE_URL) ?>/assets/js/bulk_delete.js"></script>
    <script>
        window.addEventListener('DOMContentLoaded', () => {
            admasInitBulkDelete({
                checkboxSelector: '.row-check-session',
                selectAllSelector: '#selectAllSessions',
                buttonSelector: '#bulkDeleteSessionsBtn',
                formSelector: '#bulkDeleteSessionsForm',
                hiddenContainerSelector: '#bulkDeleteSessionsIds',
                hiddenInputName: 'session_ids[]',
                entityLabel: 'Xiiso session',
                entityLabelPlural: 'Xiiso sessions',
            });
        });

        // Semester dropdown options depend on the selected Faculty's own
        // Total Semesters — rebuilt client-side on Faculty change so no page
        // reload is needed (server-side validation re-checks this regardless
        // of what the client sends).
        const semesterOptionsByFacultyId = <?php
            $semesterOptionsByFacultyId = [];
            foreach ($faculties as $f) {
                $semesterOptionsByFacultyId[(int) $f['id']] = semester_name_options_for_faculty((int) $f['total_semesters']);
            }
            echo json_encode($semesterOptionsByFacultyId, JSON_HEX_APOS | JSON_HEX_QUOT);
        ?>;

        function admasUpdateSemesterNameOptions(facultyId) {
            const select = document.getElementById('semesterNameSelect');
            if (!select) {
                return;
            }
            const options = semesterOptionsByFacultyId[facultyId] || [];
            select.innerHTML = '';

            if (options.length === 0) {
                const placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = facultyId ? 'No semesters configured for this faculty' : 'Select faculty first';
                select.appendChild(placeholder);
                select.disabled = true;
                return;
            }

            select.disabled = false;
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = 'Select semester';
            select.appendChild(placeholder);

            options.forEach((name) => {
                const opt = document.createElement('option');
                opt.value = name;
                opt.textContent = name;
                select.appendChild(opt);
            });
        }
    </script>
</body>
</html>
