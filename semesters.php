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
    ? array_filter($conn->query('SELECT id, name, semesters_per_year FROM faculties ORDER BY name')->fetch_all(MYSQLI_ASSOC), static fn ($f) => (int) $f['id'] === $deanFacultyId)
    : $conn->query('SELECT id, name, semesters_per_year FROM faculties ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$semestersPerYearByFacultyId = [];
foreach ($faculties as $f) {
    $semestersPerYearByFacultyId[(int) $f['id']] = (int) $f['semesters_per_year'];
}
$nextStartDateByFacultyId = [];
foreach ($faculties as $f) {
    $nextStartDateByFacultyId[(int) $f['id']] = next_semester_start_date_for_faculty($conn, (int) $f['id']);
}

$generateFormValues = [
    'academic_year_id' => '',
    'faculty_id' => $role === 'dean' ? (string) $deanFacultyId : '',
    'start_date' => '',
];

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
        $semStmt = $conn->prepare('SELECT id, name, faculty_id, is_current FROM semesters WHERE id = ? AND faculty_id = ?');
        $semStmt->bind_param('ii', $semesterId, $deanFacultyId);
    } else {
        $semStmt = $conn->prepare('SELECT id, name, faculty_id, is_current FROM semesters WHERE id = ?');
        $semStmt->bind_param('i', $semesterId);
    }
    $semStmt->execute();
    $semRow = $semStmt->get_result()->fetch_assoc();
    $semStmt->close();

    if (!$semRow) {
        return ['ok' => false, 'message' => 'Semester not found.'];
    }

    $label = (string) $semRow['name'];

    if ((int) $semRow['is_current'] === 1) {
        return ['ok' => false, 'message' => $label . ' is the current semester for this faculty — set a different semester as current before deleting it.'];
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

    if ($action === 'generate_next_semester') {
        // A Dean's faculty is always the session's own faculty_id — never the
        // posted value — so a crafted faculty_id cannot create a semester in
        // a faculty they don't oversee.
        $facultyId = $role === 'dean' ? $deanFacultyId : (int) ($_POST['faculty_id'] ?? 0);
        $academicYearId = (int) ($_POST['academic_year_id'] ?? 0);
        $startDateOverride = trim((string) ($_POST['start_date'] ?? ''));
        $generateFormValues = [
            'faculty_id' => (string) $facultyId,
            'academic_year_id' => (string) $academicYearId,
            'start_date' => $startDateOverride,
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

        $validationError = '';
        if (!$facultyValid) {
            $validationError = 'Please select a valid faculty.';
        } elseif (!$academicYearValid) {
            $validationError = 'Please select a valid academic year.';
        }

        // The Start Date field is always editable client-side (pre-filled
        // with the suggested chained date, but the admin can override it —
        // e.g. to skip ahead if there's a gap since the last semester
        // ended) — so whatever was actually posted wins. Only fall back to
        // computing the chained date server-side if nothing usable was
        // posted (e.g. JS-disabled).
        $startDate = null;
        if ($validationError === '') {
            $startDate = $startDateOverride;
            if ($startDate === '' || !DateTime::createFromFormat('Y-m-d', $startDate)) {
                $startDate = next_semester_start_date_for_faculty($conn, $facultyId);
            }
            if ($startDate === null || $startDate === '') {
                $validationError = 'This is the first semester for this faculty — please provide a start date.';
            } elseif (!DateTime::createFromFormat('Y-m-d', $startDate)) {
                $validationError = 'Please provide a valid start date.';
            }
        }

        if ($validationError === '') {
            $semesterNumber = next_semester_number_for_faculty($conn, $facultyId);
            $name = 'Semester ' . $semesterNumber;
            $endDate = semester_end_date_from_start($startDate);

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
                refresh_semester_current_flags($conn);
                $_SESSION['flash_success'] = '"' . $name . '" created (' . $startDate . ' to ' . $endDate . ') with all 12 Xiiso sessions dated automatically.';
                redirect_to('semesters.php?semester_id=' . $newSemesterId);
            } catch (Throwable $e) {
                $conn->rollback();
                $_SESSION['flash_error'] = 'Could not create the semester. Please try again.';
            }
        }

        $errorMessage = $validationError;
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
    } elseif ($action === 'start_now') {
        // Manual shortcut for a semester whose Start Date was set wrong
        // (future, or otherwise not covering today) — moves start_date to
        // today so is_current picks it up on the very next
        // refresh_semester_current_flags() call, without touching the
        // date-derived "current" system itself (no separate flag is set).
        $semesterId = (int) ($_POST['semester_id'] ?? 0);
        if ($role === 'dean' && !dean_owns_semester($conn, $semesterId, $deanFacultyId)) {
            $_SESSION['flash_error'] = 'Selected semester does not exist.';
            redirect_to('semesters.php');
        }
        $semStmt = $conn->prepare('SELECT name, end_date FROM semesters WHERE id = ?');
        $semStmt->bind_param('i', $semesterId);
        $semStmt->execute();
        $semRow = $semStmt->get_result()->fetch_assoc();
        $semStmt->close();

        if (!$semRow) {
            $_SESSION['flash_error'] = 'Selected semester does not exist.';
        } elseif ($semRow['end_date'] < date('Y-m-d')) {
            $_SESSION['flash_error'] = 'This semester\'s End Date is already in the past — delete it and generate a new one instead.';
        } else {
            $updateStmt = $conn->prepare('UPDATE semesters SET start_date = CURDATE() WHERE id = ?');
            $updateStmt->bind_param('i', $semesterId);
            $updateStmt->execute();
            $updateStmt->close();
            refresh_semester_current_flags($conn);

            $_SESSION['flash_success'] = '"' . $semRow['name'] . '" now starts today and is current.';
        }
        redirect_to('semesters.php?semester_id=' . $semesterId);
    } elseif ($action === 'end_now') {
        // Manual shortcut to close a semester out today (e.g. once its
        // attendance is finished), instead of waiting for its originally
        // computed End Date. Same mechanism as start_now — only the date
        // boundary moves, is_current is still recomputed from it.
        $semesterId = (int) ($_POST['semester_id'] ?? 0);
        if ($role === 'dean' && !dean_owns_semester($conn, $semesterId, $deanFacultyId)) {
            $_SESSION['flash_error'] = 'Selected semester does not exist.';
            redirect_to('semesters.php');
        }
        $semStmt = $conn->prepare('SELECT name, start_date FROM semesters WHERE id = ?');
        $semStmt->bind_param('i', $semesterId);
        $semStmt->execute();
        $semRow = $semStmt->get_result()->fetch_assoc();
        $semStmt->close();

        if (!$semRow) {
            $_SESSION['flash_error'] = 'Selected semester does not exist.';
        } elseif ($semRow['start_date'] > date('Y-m-d')) {
            $_SESSION['flash_error'] = 'This semester has not started yet.';
        } else {
            $updateStmt = $conn->prepare('UPDATE semesters SET end_date = CURDATE() WHERE id = ?');
            $updateStmt->bind_param('i', $semesterId);
            $updateStmt->execute();
            $updateStmt->close();
            refresh_semester_current_flags($conn);

            $_SESSION['flash_success'] = '"' . $semRow['name'] . '" ends today and is no longer current.';
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
        "SELECT s.id, s.academic_year_id, s.faculty_id, s.context_department_id, s.name, s.start_date, s.end_date, s.is_current,
                ay.label AS academic_year_label, f.name AS faculty_name, cd.name AS context_department_name
         FROM semesters s
         JOIN academic_years ay ON ay.id = s.academic_year_id
         JOIN faculties f ON f.id = s.faculty_id
         LEFT JOIN departments cd ON cd.id = s.context_department_id
         WHERE s.faculty_id = ?
         ORDER BY ay.label DESC, s.start_date DESC"
    );
    $semListStmt->bind_param('i', $deanFacultyId);
    $semListStmt->execute();
    $semesters = $semListStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $semListStmt->close();
} else {
    $semesters = $conn->query(
        "SELECT s.id, s.academic_year_id, s.faculty_id, s.context_department_id, s.name, s.start_date, s.end_date, s.is_current,
                ay.label AS academic_year_label, f.name AS faculty_name, cd.name AS context_department_name
         FROM semesters s
         JOIN academic_years ay ON ay.id = s.academic_year_id
         LEFT JOIN faculties f ON f.id = s.faculty_id
         LEFT JOIN departments cd ON cd.id = s.context_department_id
         ORDER BY (s.faculty_id IS NULL), f.name, ay.label DESC, s.start_date DESC"
    )->fetch_all(MYSQLI_ASSOC);
}

$selectedSemesterId = (int) ($_GET['semester_id'] ?? 0);
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
                        <h6 class="small text-uppercase text-muted mb-2">Generate Next Semester</h6>
                        <p class="text-muted small mb-2">
                            Every semester runs 3 months (12 Xiiso). Pick a faculty and academic year — the semester
                            number, dates, and all 12 Xiiso dates are filled in automatically, chained from that
                            faculty's last semester. You can still edit individual Xiiso dates afterward.
                        </p>
                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/semesters.php" class="d-flex flex-column gap-2">
                            <input type="hidden" name="action" value="generate_next_semester">

                            <div>
                                <label class="form-label small mb-1">Faculty</label>
                                <?php if ($role === 'dean'): ?>
                                    <select class="form-select form-select-sm" id="semesterFacultySelect" disabled>
                                        <option selected value="<?= (int) $deanFacultyId ?>"><?= htmlspecialchars($deanFacultyName) ?></option>
                                    </select>
                                    <div class="form-text">Locked to your own faculty.</div>
                                <?php else: ?>
                                    <select class="form-select form-select-sm" id="semesterFacultySelect" name="faculty_id" required onchange="admasUpdateNextStartDate(this.value)">
                                        <option value="">Select faculty</option>
                                        <?php foreach ($faculties as $f): ?>
                                            <option value="<?= (int) $f['id'] ?>" <?= (string) $f['id'] === $generateFormValues['faculty_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($f['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </div>
                            <div>
                                <label class="form-label small mb-1">Academic Year</label>
                                <select class="form-select form-select-sm" name="academic_year_id" required <?= empty($academicYears) ? 'disabled' : '' ?>>
                                    <option value="">Select year</option>
                                    <?php foreach ($academicYears as $ay): ?>
                                        <option value="<?= (int) $ay['id'] ?>" <?= (string) $ay['id'] === $generateFormValues['academic_year_id'] ? 'selected' : '' ?>>
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
                                <input type="date" class="form-control form-control-sm" id="semesterStartDateInput" name="start_date"
                                       value="<?= htmlspecialchars($generateFormValues['start_date']) ?>" <?= $role === 'dean' ? 'required' : '' ?>>
                                <div class="form-text" id="semesterStartDateHelp">&nbsp;</div>
                            </div>
                            <button type="submit" class="btn btn-primary text-nowrap mt-2" style="background-color: var(--admas-sky); border-color: var(--admas-sky);" <?= empty($academicYears) ? 'disabled' : '' ?>>
                                <i class="bi bi-magic"></i> Generate Next Semester
                            </button>
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
                                                    <?php if ((int) $s['is_current'] === 1): ?>
                                                        <span class="badge-pill badge-active">Current</span>
                                                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/semesters.php" class="mt-1"
                                                              onclick="event.stopPropagation();"
                                                              onsubmit="event.stopPropagation(); return confirm('End this semester today?');">
                                                            <input type="hidden" name="action" value="end_now">
                                                            <input type="hidden" name="semester_id" value="<?= (int) $s['id'] ?>">
                                                            <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-2" style="font-size: 0.72rem;">
                                                                <i class="bi bi-stop-fill"></i> End
                                                            </button>
                                                        </form>
                                                    <?php elseif ($s['end_date'] < date('Y-m-d')): ?>
                                                        <span class="badge-pill badge-inactive">Ended</span>
                                                    <?php else: ?>
                                                        <span class="badge-pill badge-neutral">Waiting</span>
                                                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/semesters.php" class="mt-1"
                                                              onclick="event.stopPropagation();"
                                                              onsubmit="event.stopPropagation(); return confirm('Start this semester today?');">
                                                            <input type="hidden" name="action" value="start_now">
                                                            <input type="hidden" name="semester_id" value="<?= (int) $s['id'] ?>">
                                                            <button type="submit" class="btn btn-outline-success btn-sm py-0 px-2" style="font-size: 0.72rem;">
                                                                <i class="bi bi-play-fill"></i> Start
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </td>
                                                <?php if ($role === 'system_admin' || $role === 'dean'): ?>
                                                    <td class="text-end">
                                                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/semesters.php"
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
                                        <?php if ((int) $selectedSemester['is_current'] === 1): ?>
                                            <span class="badge-pill badge-active">Active now</span>
                                        <?php endif; ?>
                                    </h6>
                                    <p class="text-muted small mb-0">
                                        <?= $selectedSemester['faculty_id'] === null ? 'No faculty assigned' : htmlspecialchars($selectedSemester['faculty_name']) ?>
                                        <?php if ($selectedSemester['context_department_name']): ?>
                                            <span class="fst-italic">(<?= htmlspecialchars($selectedSemester['context_department_name']) ?>)</span>
                                        <?php endif; ?>
                                        &middot; <?= htmlspecialchars($selectedSemester['start_date']) ?> to <?= htmlspecialchars($selectedSemester['end_date']) ?>
                                        <?php $selYear = semester_year_number($selectedSemester['name'], $semestersPerYearByFacultyId[(int) $selectedSemester['faculty_id']] ?? 3); ?>
                                        <?php if ($selYear !== null): ?>
                                            &middot; Year <?= $selYear ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <div class="d-flex gap-2 flex-wrap">
                                    <?php if ((int) $selectedSemester['is_current'] !== 1 && $selectedSemester['end_date'] >= date('Y-m-d')): ?>
                                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/semesters.php" onsubmit="return confirm('Start this semester today? Its Start Date will move to today.');">
                                            <input type="hidden" name="action" value="start_now">
                                            <input type="hidden" name="semester_id" value="<?= $selectedSemesterId ?>">
                                            <button type="submit" class="btn btn-outline-success btn-sm">
                                                <i class="bi bi-play-fill"></i> Start Current
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ((int) $selectedSemester['is_current'] === 1): ?>
                                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/semesters.php" onsubmit="return confirm('End this semester today? It will stop being current from tomorrow.');">
                                            <input type="hidden" name="action" value="end_now">
                                            <input type="hidden" name="semester_id" value="<?= $selectedSemesterId ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-sm">
                                                <i class="bi bi-stop-fill"></i> End Semester
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/semesters.php">
                                        <input type="hidden" name="action" value="generate_sessions">
                                        <input type="hidden" name="semester_id" value="<?= $selectedSemesterId ?>">
                                        <button type="submit" class="btn btn-primary btn-sm" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                                            <i class="bi bi-magic"></i> Generate Sessions
                                        </button>
                                    </form>
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
                                    <button type="submit" class="btn btn-outline-secondary btn-sm">Assign Faculty</button>
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

        // "Generate Next Semester" form: Start Date auto-fills (and locks)
        // to the day after that faculty's last semester ended; a faculty
        // with no semesters yet gets an empty, editable Start Date since
        // there's nothing to chain from.
        const nextStartDateByFacultyId = <?= json_encode($nextStartDateByFacultyId, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        function admasUpdateNextStartDate(facultyId) {
            const input = document.getElementById('semesterStartDateInput');
            const help = document.getElementById('semesterStartDateHelp');
            if (!input || !help) {
                return;
            }
            const chained = nextStartDateByFacultyId[facultyId];
            if (chained) {
                input.value = chained;
                input.required = false;
                help.textContent = 'Suggested date, right after this faculty\'s last semester ended. You can change it.';
            } else {
                input.value = '';
                input.required = true;
                help.textContent = facultyId ? 'First semester for this faculty — pick a start date.' : ' ';
            }
        }

        window.addEventListener('DOMContentLoaded', () => {
            const facultySelect = document.getElementById('semesterFacultySelect');
            if (facultySelect) {
                admasUpdateNextStartDate(facultySelect.value);
            }
        });
    </script>
</body>
</html>
