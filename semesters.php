<?php
/**
 * Semester + Session ("Xiiso") management — shared by System Administrator
 * and Head of Academic Affairs. Lives at the app root (not under /admin)
 * because it is reused by both roles, same pattern as attendance.php and
 * reports.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/nav_items.php';
require_once __DIR__ . '/includes/semester_helpers.php';

require_role(['system_admin', 'head_academic']);

$conn = db();

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
$faculties = $conn->query('SELECT id, name FROM faculties ORDER BY name')->fetch_all(MYSQLI_ASSOC);

$addSemesterFormValues = ['academic_year_id' => '', 'faculty_id' => '', 'name' => '', 'start_date' => '', 'end_date' => ''];

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

// ---------------------------------------------------------------------
// Handle POST actions
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add_semester') {
        $academicYearId = (int) ($_POST['academic_year_id'] ?? 0);
        $facultyId = (int) ($_POST['faculty_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $startDate = (string) ($_POST['start_date'] ?? '');
        $endDate = (string) ($_POST['end_date'] ?? '');
        $addSemesterFormValues = compact('name', 'startDate', 'endDate') + [
            'academic_year_id' => (string) $academicYearId,
            'faculty_id' => (string) $facultyId,
        ];

        $academicYearValid = false;
        foreach ($academicYears as $ay) {
            if ((int) $ay['id'] === $academicYearId) {
                $academicYearValid = true;
                break;
            }
        }
        $facultyValid = false;
        foreach ($faculties as $f) {
            if ((int) $f['id'] === $facultyId) {
                $facultyValid = true;
                break;
            }
        }
        $startValid = (bool) DateTime::createFromFormat('Y-m-d', $startDate);
        $endValid = (bool) DateTime::createFromFormat('Y-m-d', $endDate);

        $validationError = '';
        if (!$academicYearValid) {
            $validationError = 'Please select a valid academic year.';
        } elseif (!$facultyValid) {
            $validationError = 'Please select a valid faculty.';
        } elseif ($name === '') {
            $validationError = 'Semester name is required.';
        } elseif (mb_strlen($name) > 50) {
            $validationError = 'Semester name must be 50 characters or fewer.';
        } elseif (!$startValid || !$endValid) {
            $validationError = 'Please provide valid start and end dates.';
        } elseif ($startDate >= $endDate) {
            $validationError = 'Start date must be before end date.';
        }

        if ($validationError === '') {
            $dupStmt = $conn->prepare('SELECT id FROM semesters WHERE faculty_id = ? AND academic_year_id = ? AND name = ?');
            $dupStmt->bind_param('iis', $facultyId, $academicYearId, $name);
            $dupStmt->execute();
            if ($dupStmt->get_result()->fetch_assoc()) {
                $validationError = 'This semester name already exists for that faculty and academic year.';
            }
            $dupStmt->close();
        }

        if ($validationError === '') {
            $insertStmt = $conn->prepare('INSERT INTO semesters (academic_year_id, faculty_id, name, start_date, end_date) VALUES (?, ?, ?, ?, ?)');
            $insertStmt->bind_param('iisss', $academicYearId, $facultyId, $name, $startDate, $endDate);
            $insertStmt->execute();
            $insertStmt->close();

            $_SESSION['flash_success'] = 'Semester "' . $name . '" created.';
            redirect_to('semesters.php');
        }

        $errorMessage = $validationError;
    } elseif ($action === 'assign_faculty') {
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
    } elseif ($action === 'generate_sessions') {
        $semesterId = (int) ($_POST['semester_id'] ?? 0);
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
    } elseif ($action === 'set_current_semester') {
        $semesterId = (int) ($_POST['semester_id'] ?? 0);
        $semStmt = $conn->prepare('SELECT name, faculty_id FROM semesters WHERE id = ?');
        $semStmt->bind_param('i', $semesterId);
        $semStmt->execute();
        $semRow = $semStmt->get_result()->fetch_assoc();
        $semStmt->close();

        if (!$semRow) {
            $_SESSION['flash_error'] = 'Selected semester does not exist.';
        } elseif ($semRow['faculty_id'] === null) {
            $_SESSION['flash_error'] = 'Assign a faculty to this semester before setting it as current.';
        } else {
            $facultyId = (int) $semRow['faculty_id'];
            $conn->begin_transaction();
            try {
                // "Current" is scoped per faculty — clearing only ever
                // touches that same faculty's other semesters, never every
                // semester system-wide, so setting Faculty A's current
                // semester never affects Faculty B's.
                $clearStmt = $conn->prepare('UPDATE semesters SET is_current = 0 WHERE faculty_id = ?');
                $clearStmt->bind_param('i', $facultyId);
                $clearStmt->execute();
                $clearStmt->close();

                $updateStmt = $conn->prepare('UPDATE semesters SET is_current = 1 WHERE id = ?');
                $updateStmt->bind_param('i', $semesterId);
                $updateStmt->execute();
                $updateStmt->close();

                $conn->commit();
                $_SESSION['flash_success'] = '"' . $semRow['name'] . '" is now the current semester.';
            } catch (Throwable $e) {
                $conn->rollback();
                $_SESSION['flash_error'] = 'Could not update the current semester. Please try again.';
            }
        }
        redirect_to('semesters.php?semester_id=' . $semesterId);
    } elseif ($action === 'save_session_dates') {
        $semesterId = (int) ($_POST['semester_id'] ?? 0);
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
    }
}

// ---------------------------------------------------------------------
// Data for rendering
// ---------------------------------------------------------------------
$semesters = $conn->query(
    "SELECT s.id, s.academic_year_id, s.faculty_id, s.name, s.start_date, s.end_date, s.is_current,
            ay.label AS academic_year_label, f.name AS faculty_name
     FROM semesters s
     JOIN academic_years ay ON ay.id = s.academic_year_id
     LEFT JOIN faculties f ON f.id = s.faculty_id
     ORDER BY (s.faculty_id IS NULL), f.name, ay.label DESC, s.start_date DESC"
)->fetch_all(MYSQLI_ASSOC);

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
                Access scope: All academic years and semesters
            </div>

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: #0b1f3a;">Semesters</h4>
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
                        <h6 class="small text-uppercase text-muted mb-2">Add New Semester</h6>
                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/semesters.php" class="d-flex flex-column gap-2">
                            <input type="hidden" name="action" value="add_semester">

                            <div>
                                <label class="form-label small mb-1">Faculty</label>
                                <select class="form-select form-select-sm" name="faculty_id" required>
                                    <option value="">Select faculty</option>
                                    <?php foreach ($faculties as $f): ?>
                                        <option value="<?= (int) $f['id'] ?>" <?= (string) $f['id'] === $addSemesterFormValues['faculty_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($f['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="form-label small mb-1">Academic Year</label>
                                <select class="form-select form-select-sm" name="academic_year_id" required>
                                    <option value="">Select year</option>
                                    <?php foreach ($academicYears as $ay): ?>
                                        <option value="<?= (int) $ay['id'] ?>" <?= (string) $ay['id'] === $addSemesterFormValues['academic_year_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($ay['label']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="form-label small mb-1">Name</label>
                                <input type="text" class="form-control form-control-sm" name="name" maxlength="50" placeholder="e.g. Semester 3" required
                                       value="<?= htmlspecialchars($addSemesterFormValues['name']) ?>">
                            </div>
                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="form-label small mb-1">Start Date</label>
                                    <input type="date" class="form-control form-control-sm" name="start_date" required
                                           value="<?= htmlspecialchars($addSemesterFormValues['start_date']) ?>">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small mb-1">End Date</label>
                                    <input type="date" class="form-control form-control-sm" name="end_date" required
                                           value="<?= htmlspecialchars($addSemesterFormValues['end_date']) ?>">
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary text-nowrap mt-2" style="background-color: #0ea5e9; border-color: #0ea5e9;">
                                <i class="bi bi-plus-lg"></i> Create Semester
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
                                        <th>Faculty</th>
                                        <th>Academic Year</th>
                                        <th>Current</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($semesters)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted py-3">No semesters exist yet.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($semesters as $s): ?>
                                            <tr class="<?= (int) $s['id'] === $selectedSemesterId ? 'table-active' : '' ?>" style="cursor:pointer;" onclick="window.location='<?= htmlspecialchars(BASE_URL) ?>/semesters.php?semester_id=<?= (int) $s['id'] ?>'">
                                                <td class="fw-semibold" style="color: #0b1f3a;"><?= htmlspecialchars($s['name']) ?></td>
                                                <td>
                                                    <?php if ($s['faculty_id'] === null): ?>
                                                        <span class="badge-pill badge-late">Unassigned</span>
                                                    <?php else: ?>
                                                        <?= htmlspecialchars($s['faculty_name']) ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars($s['academic_year_label']) ?></td>
                                                <td>
                                                    <?php if ((int) $s['is_current'] === 1): ?>
                                                        <span class="badge-pill badge-active">Current</span>
                                                    <?php else: ?>
                                                        <span class="badge-pill badge-inactive">—</span>
                                                    <?php endif; ?>
                                                </td>
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
                                    <h6 class="fw-bold mb-1" style="color: #0b1f3a;">
                                        <?= htmlspecialchars($selectedSemester['name']) ?>
                                        <span class="text-muted fw-normal">(<?= htmlspecialchars($selectedSemester['academic_year_label']) ?>)</span>
                                    </h6>
                                    <p class="text-muted small mb-0">
                                        <?= $selectedSemester['faculty_id'] === null ? 'No faculty assigned' : htmlspecialchars($selectedSemester['faculty_name']) ?>
                                        &middot; <?= htmlspecialchars($selectedSemester['start_date']) ?> to <?= htmlspecialchars($selectedSemester['end_date']) ?>
                                    </p>
                                </div>
                                <div class="d-flex gap-2">
                                    <?php if ($selectedSemester['faculty_id'] !== null && (int) $selectedSemester['is_current'] !== 1): ?>
                                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/semesters.php">
                                            <input type="hidden" name="action" value="set_current_semester">
                                            <input type="hidden" name="semester_id" value="<?= $selectedSemesterId ?>">
                                            <button type="submit" class="btn btn-outline-secondary btn-sm">Set as Current</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/semesters.php">
                                        <input type="hidden" name="action" value="generate_sessions">
                                        <input type="hidden" name="semester_id" value="<?= $selectedSemesterId ?>">
                                        <button type="submit" class="btn btn-primary btn-sm" style="background-color: #0ea5e9; border-color: #0ea5e9;">
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
                                        <label class="form-label small mb-1">This semester has no faculty yet — assign one before it can be set as current</label>
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
                                                        <td class="fw-semibold" style="color: #0b1f3a;"><?= htmlspecialchars($session['label']) ?></td>
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
                                    <button type="submit" class="btn btn-primary btn-sm" style="background-color: #0ea5e9; border-color: #0ea5e9;">
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
    </script>
</body>
</html>
