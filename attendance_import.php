<?php
/**
 * Import Attendance from Excel — bulk-imports historical attendance data
 * matching the university's own paper/Excel tracker format (REG/NO, three
 * name columns, P/A/% summary, then one column per calendar day grouped
 * into colored month bands) into this app's own Xiiso-session model. Lives
 * at the app root (not under /admin) because it's shared by three roles,
 * same placement/role-scoping convention as attendance.php.
 *
 * Excel day-columns map onto a semester's 12 fixed Xiiso slots
 * automatically, in chronological order: the first 12 detected dates
 * become Xiiso 1-12; any further dates are ignored (flagged on the
 * preview page) since a semester only ever has 12 sessions.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/nav_items.php';
require_once __DIR__ . '/includes/semester_helpers.php';
require_once __DIR__ . '/includes/attendance_helpers.php';
require_once __DIR__ . '/includes/lecturer_accounts.php';
require_once __DIR__ . '/vendor/autoload.php';

require_role(['system_admin', 'dean', 'lecturer']);

use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

$conn = db();
$currentUser = current_user();
$role = current_role();

$settings = [];
$settingsResult = $conn->query('SELECT `key`, `value` FROM settings');
if ($settingsResult) {
    while ($row = $settingsResult->fetch_assoc()) {
        $settings[$row['key']] = $row['value'];
    }
}

// ---------------------------------------------------------------------
// Role scope + course list — identical query shapes to attendance.php for
// system_admin/dean, so "which courses can this user import into" can
// never drift from "which courses can this user mark attendance for".
// The lecturer branch deliberately differs from attendance.php: this page
// is for historical backfill, so it lists every course the lecturer has
// EVER held a course_offerings row for (any semester), not just their
// current-semester assignment — actual write access to a specific
// course+semester is still independently re-checked at Preview and
// Confirm time via user_can_write_course_attendance().
// ---------------------------------------------------------------------
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

$lecturerRecordId = 0;
if ($role === 'lecturer') {
    $lecStmt = $conn->prepare('SELECT id FROM lecturers WHERE user_id = ?');
    $lecStmt->bind_param('i', $currentUser['id']);
    $lecStmt->execute();
    $lecRow = $lecStmt->get_result()->fetch_assoc();
    $lecStmt->close();
    $lecturerRecordId = $lecRow ? (int) $lecRow['id'] : 0;
}

$courses = [];
if ($role === 'system_admin') {
    $courses = $conn->query(
        "SELECT c.id, c.code, c.name, c.department_id,
                d.name AS department_name, d.faculty_id, f.name AS faculty_name
         FROM courses c
         JOIN departments d ON d.id = c.department_id
         JOIN faculties f ON f.id = d.faculty_id
         ORDER BY f.name, d.name, c.code"
    )->fetch_all(MYSQLI_ASSOC);
} elseif ($role === 'dean') {
    $stmt = $conn->prepare(
        "SELECT c.id, c.code, c.name, c.department_id,
                d.name AS department_name, d.faculty_id, f.name AS faculty_name
         FROM courses c
         JOIN departments d ON d.id = c.department_id
         JOIN faculties f ON f.id = d.faculty_id
         WHERE d.faculty_id = ?
         ORDER BY d.name, c.code"
    );
    $stmt->bind_param('i', $deanFacultyId);
    $stmt->execute();
    $courses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} elseif ($role === 'lecturer') {
    $stmt = $conn->prepare(
        "SELECT c.id, c.code, c.name, c.department_id,
                d.name AS department_name, d.faculty_id, f.name AS faculty_name
         FROM courses c
         JOIN departments d ON d.id = c.department_id
         JOIN faculties f ON f.id = d.faculty_id
         JOIN course_offerings co ON co.course_id = c.id AND co.lecturer_id = ?
         GROUP BY c.id
         ORDER BY c.code"
    );
    $stmt->bind_param('i', $lecturerRecordId);
    $stmt->execute();
    $courses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$courseById = [];
foreach ($courses as $c) {
    $courseById[(int) $c['id']] = $c;
}

// Semesters for each represented faculty (all semesters, not just
// current — this page is specifically for historical backfill).
$facultyIds = array_values(array_unique(array_map(static fn ($c) => (int) $c['faculty_id'], $courses)));
$semestersByFacultyId = [];
if (!empty($facultyIds)) {
    $placeholders = implode(',', array_fill(0, count($facultyIds), '?'));
    $types = str_repeat('i', count($facultyIds));
    $semStmt = $conn->prepare("SELECT id, name, faculty_id FROM semesters WHERE faculty_id IN ($placeholders) ORDER BY start_date DESC");
    $semStmt->bind_param($types, ...$facultyIds);
    $semStmt->execute();
    $semRows = $semStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $semStmt->close();
    foreach ($semRows as $sem) {
        $semestersByFacultyId[(int) $sem['faculty_id']][] = ['id' => (int) $sem['id'], 'name' => $sem['name']];
    }
}

// ---------------------------------------------------------------------
// Flash messages + step tracking (same upload -> preview -> confirm shape
// as admin/students_import.php)
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

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    unset($_SESSION['attendance_import_preview']);
}

$step = 'upload';
$previewRows = [];
$mappedSessions = [];
$skippedDates = [];
$previewCourseId = 0;
$previewSemesterId = 0;

// ---------------------------------------------------------------------
// Handle POST actions: preview, confirm, cancel
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'preview') {
        $courseId = (int) ($_POST['course_id'] ?? 0);
        $semesterId = (int) ($_POST['semester_id'] ?? 0);

        if (!array_key_exists($courseId, $courseById)) {
            $errorMessage = 'Please select a valid course.';
        } elseif ($semesterId <= 0) {
            $errorMessage = 'Please select a semester.';
        } else {
            $courseFacultyId = (int) $courseById[$courseId]['faculty_id'];
            $semStmt = $conn->prepare('SELECT id, name, faculty_id FROM semesters WHERE id = ? AND faculty_id = ?');
            $semStmt->bind_param('ii', $semesterId, $courseFacultyId);
            $semStmt->execute();
            $semRow = $semStmt->get_result()->fetch_assoc();
            $semStmt->close();

            if (!$semRow) {
                $errorMessage = 'Selected semester does not belong to the selected course\'s faculty.';
            } elseif (!user_can_write_course_attendance($conn, $role, $currentUser, $courseId, $semesterId)) {
                $errorMessage = 'You do not have permission to record attendance for this course and semester.';
            } elseif (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
                $errorMessage = 'Please choose a valid Excel or CSV file to upload.';
            } else {
                $originalName = (string) $_FILES['import_file']['name'];
                $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

                if (!in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
                    $errorMessage = 'Unsupported file type. Please upload a .xlsx, .xls, or .csv file.';
                } else {
                    try {
                        $reader = match ($extension) {
                            'xlsx' => new Xlsx(),
                            'xls' => new Xls(),
                            'csv' => new Csv(),
                        };
                        $reader->setReadDataOnly(true);
                        $spreadsheet = $reader->load($_FILES['import_file']['tmp_name']);
                        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);

                        if (empty($rows)) {
                            $errorMessage = 'The uploaded file is empty.';
                        } else {
                            // Locate the real header row by scanning column A for a
                            // "REG/NO"-like label, skipping the ADMAS UNIVERSITY /
                            // Faculty / Course Name / Lecturer banner rows above it.
                            $headerRowIdx = null;
                            foreach ($rows as $idx => $rw) {
                                $normalized = preg_replace('/[^a-z]/', '', mb_strtolower(trim((string) ($rw[0] ?? ''))));
                                if (in_array($normalized, ['regno', 'reg', 'studentno', 'id'], true)) {
                                    $headerRowIdx = $idx;
                                    break;
                                }
                            }

                            if ($headerRowIdx === null) {
                                $errorMessage = 'Could not find the "REG/NO" header row in the uploaded file.';
                            } else {
                                $headerRow = array_map(static fn ($h) => str_replace('-', '', mb_strtolower(trim((string) $h))), $rows[$headerRowIdx]);

                                // The row that actually holds the day-numbers (3, 4,
                                // 5...) may be the same row as REG/NO, or the row
                                // immediately below it — real trackers often
                                // vertically merge REG/NO/names/P/A/% across two
                                // header rows, with the month-band label sharing the
                                // REG/NO row and bare day-numbers on the row below.
                                // Pick whichever of the two rows has more cells that
                                // look like bare day-numbers (1-31).
                                $countDayLikeCells = static function (array $row): int {
                                    $n = 0;
                                    foreach ($row as $cell) {
                                        $v = trim((string) $cell);
                                        if ($v !== '' && ctype_digit($v) && (int) $v >= 1 && (int) $v <= 31) {
                                            $n++;
                                        }
                                    }

                                    return $n;
                                };
                                $rowBelowIdx = $headerRowIdx + 1;
                                $rowBelow = $rows[$rowBelowIdx] ?? [];
                                $dayRowIdx = $headerRowIdx;
                                if (isset($rows[$rowBelowIdx]) && $countDayLikeCells($rowBelow) > $countDayLikeCells($rows[$headerRowIdx])) {
                                    $dayRowIdx = $rowBelowIdx;
                                }
                                $dayRow = $rows[$dayRowIdx];
                                // Month-band labels live on whichever row is NOT the
                                // day-number row: the REG/NO row itself if day-numbers
                                // turned out to be on the row below it, otherwise the
                                // row above the REG/NO row.
                                $bandRow = $dayRowIdx === $headerRowIdx ? ($rows[$headerRowIdx - 1] ?? []) : $rows[$headerRowIdx];
                                $dataStartIdx = max($headerRowIdx, $dayRowIdx) + 1;

                                $regNoCol = find_import_column($headerRow, ['reg/no', 'regno', 'reg no', 'student no', 'studentno', 'id']);
                                $firstNameCol = find_import_column($headerRow, ['first names', 'first name', 'firstname']);
                                $fatherNameCol = find_import_column($headerRow, ["father's", "father's name", 'fathers name', 'father name']);
                                $grandfatherNameCol = find_import_column($headerRow, ["g.father's", "grandfather's", 'grandfather', "grandfather's name"]);
                                $presentCol = find_import_column($headerRow, ['p', 'present']);
                                $absentCol = find_import_column($headerRow, ['a', 'absent']);
                                $pctCol = find_import_column($headerRow, ['%', 'pct', 'percentage']);

                                if ($regNoCol === false) {
                                    $errorMessage = 'The file must have a "REG/NO" (or "Student No") column.';
                                } else {
                                    $reservedCols = array_values(array_filter(
                                        [$regNoCol, $firstNameCol, $fatherNameCol, $grandfatherNameCol, $presentCol, $absentCol, $pctCol],
                                        static fn ($c) => $c !== false
                                    ));

                                    // Detect date columns: try a direct date parse
                                    // first (a fully-qualified date header on the
                                    // day-number row), else treat that row's cell as a
                                    // bare day-number and combine it with the nearest
                                    // month-band cell to its left in the band row
                                    // (forward-filled, since merged month cells only
                                    // populate their leftmost cell once PhpSpreadsheet
                                    // flattens them via toArray()).
                                    $dateByCol = [];
                                    $columnCount = count($dayRow);
                                    for ($c = 0; $c < $columnCount; $c++) {
                                        if (in_array($c, $reservedCols, true)) {
                                            continue;
                                        }
                                        $rawHeader = trim((string) ($dayRow[$c] ?? ''));
                                        if ($rawHeader === '') {
                                            continue;
                                        }

                                        $parsedDate = null;
                                        if (preg_match('/\d{4}/', $rawHeader) || preg_match('/[A-Za-z]{3,}/', $rawHeader)) {
                                            $ts = strtotime($rawHeader);
                                            if ($ts !== false) {
                                                $parsedDate = date('Y-m-d', $ts);
                                            }
                                        }
                                        if ($parsedDate === null) {
                                            $dayNum = (int) preg_replace('/\D/', '', $rawHeader);
                                            if ($dayNum >= 1 && $dayNum <= 31) {
                                                for ($b = $c; $b >= 0; $b--) {
                                                    $bandVal = trim((string) ($bandRow[$b] ?? ''));
                                                    if ($bandVal !== '') {
                                                        $bts = strtotime($bandVal);
                                                        if ($bts !== false && checkdate((int) date('m', $bts), $dayNum, (int) date('Y', $bts))) {
                                                            $parsedDate = date('Y-m-', $bts) . str_pad((string) $dayNum, 2, '0', STR_PAD_LEFT);
                                                        }
                                                        break;
                                                    }
                                                }
                                            }
                                        }

                                        if ($parsedDate !== null) {
                                            $dateByCol[$c] = $parsedDate;
                                        }
                                    }

                                    if (empty($dateByCol)) {
                                        $errorMessage = 'No date columns could be detected in the uploaded file.';
                                    } else {
                                        // Sort chronologically, cap at 12 — a semester
                                        // only ever has 12 Xiiso sessions.
                                        asort($dateByCol);
                                        $orderedCols = array_keys($dateByCol);
                                        $usedCols = array_slice($orderedCols, 0, 12);
                                        foreach (array_slice($orderedCols, 12) as $sc) {
                                            $skippedDates[] = $dateByCol[$sc];
                                        }

                                        // Ensure this semester's 12 Xiiso rows exist
                                        // (idempotent — safe to call every time).
                                        generate_sessions_for_semester($conn, $semesterId);
                                        $semesterSessions = get_sessions_for_semester($conn, $semesterId);

                                        foreach ($semesterSessions as $i => $sess) {
                                            if (!isset($usedCols[$i])) {
                                                break;
                                            }
                                            $col = $usedCols[$i];
                                            $mappedSessions[] = [
                                                'session_id' => (int) $sess['id'],
                                                'label' => $sess['label'],
                                                'excel_col' => $col,
                                                'excel_date' => $dateByCol[$col],
                                                'existing_date' => $sess['date'],
                                                'date_conflict' => $sess['date'] !== null && $sess['date'] !== $dateByCol[$col],
                                            ];
                                        }

                                        $dataRows = array_slice($rows, $dataStartIdx);
                                        foreach ($dataRows as $row) {
                                            $regNo = strtoupper(trim((string) ($row[$regNoCol] ?? '')));
                                            if ($regNo === '') {
                                                continue;
                                            }

                                            $rowFirstName = $firstNameCol !== false ? trim((string) ($row[$firstNameCol] ?? '')) : '';
                                            $rowFatherName = $fatherNameCol !== false ? trim((string) ($row[$fatherNameCol] ?? '')) : '';
                                            $studentDisplayName = trim($rowFirstName . ' ' . $rowFatherName);

                                            $status = 'ok';
                                            $message = 'Ready to import';
                                            $studentId = 0;

                                            $studStmt = $conn->prepare('SELECT id, full_name FROM students WHERE UPPER(student_no) = ?');
                                            $studStmt->bind_param('s', $regNo);
                                            $studStmt->execute();
                                            $studRow = $studStmt->get_result()->fetch_assoc();
                                            $studStmt->close();

                                            if (!$studRow) {
                                                $status = 'error';
                                                $message = 'No student found with this REG/NO';
                                            } else {
                                                $studentId = (int) $studRow['id'];
                                                if ($studentDisplayName === '') {
                                                    $studentDisplayName = (string) $studRow['full_name'];
                                                }
                                            }

                                            $marks = [];
                                            foreach ($mappedSessions as $ms) {
                                                $cellVal = trim((string) ($row[$ms['excel_col']] ?? ''));
                                                $marks[$ms['session_id']] = match (true) {
                                                    $cellVal === '1' => 'present',
                                                    $cellVal === '0' => 'absent',
                                                    default => null,
                                                };
                                            }

                                            $previewRows[] = [
                                                'reg_no' => $regNo,
                                                'display_name' => $studentDisplayName,
                                                'student_id' => $studentId,
                                                'marks' => $marks,
                                                'status' => $status,
                                                'message' => $message,
                                            ];
                                        }

                                        if (empty($previewRows)) {
                                            $errorMessage = 'No student rows were found in the uploaded file.';
                                        } else {
                                            $_SESSION['attendance_import_preview'] = [
                                                'course_id' => $courseId,
                                                'semester_id' => $semesterId,
                                                'mapped_sessions' => $mappedSessions,
                                                'skipped_dates' => $skippedDates,
                                                'rows' => $previewRows,
                                            ];
                                            $step = 'preview';
                                            $previewCourseId = $courseId;
                                            $previewSemesterId = $semesterId;
                                        }
                                    }
                                }
                            }
                        }
                    } catch (\Throwable $e) {
                        $errorMessage = 'Could not read the uploaded file. Please make sure it is a valid Excel or CSV file.';
                    }
                }
            }
        }
    } elseif ($action === 'confirm') {
        $preview = $_SESSION['attendance_import_preview'] ?? null;

        if (!$preview) {
            $_SESSION['flash_error'] = 'Your import session expired. Please upload the file again.';
            unset($_SESSION['attendance_import_preview']);
            redirect_to('attendance_import.php');
        }

        $courseId = (int) $preview['course_id'];
        $semesterId = (int) $preview['semester_id'];

        if (!array_key_exists($courseId, $courseById) || !user_can_write_course_attendance($conn, $role, $currentUser, $courseId, $semesterId)) {
            $_SESSION['flash_error'] = 'You no longer have permission to import attendance for this course and semester.';
            unset($_SESSION['attendance_import_preview']);
            redirect_to('attendance_import.php');
        }

        $validRows = array_values(array_filter($preview['rows'], static fn ($r) => $r['status'] === 'ok'));

        if (empty($validRows)) {
            $_SESSION['flash_error'] = 'There were no valid rows to import.';
            unset($_SESSION['attendance_import_preview']);
            redirect_to('attendance_import.php');
        }

        $conn->begin_transaction();
        try {
            // Fill in any of the mapped sessions' dates that are still NULL.
            foreach ($preview['mapped_sessions'] as $ms) {
                if ($ms['existing_date'] === null) {
                    $dateStmt = $conn->prepare('UPDATE sessions SET date = ? WHERE id = ?');
                    $dateStmt->bind_param('si', $ms['excel_date'], $ms['session_id']);
                    $dateStmt->execute();
                    $dateStmt->close();
                }
            }

            $importedCells = 0;
            $studentsTouched = [];
            foreach ($validRows as $row) {
                // Each student's own shift/academic_year_id, same lookup
                // pattern as ajax/save_attendance_cell.php.
                $stuStmt = $conn->prepare('SELECT shift, academic_year_id FROM students WHERE id = ?');
                $stuStmt->bind_param('i', $row['student_id']);
                $stuStmt->execute();
                $stuRow = $stuStmt->get_result()->fetch_assoc();
                $stuStmt->close();
                if (!$stuRow) {
                    continue;
                }

                foreach ($preview['mapped_sessions'] as $ms) {
                    $mark = $row['marks'][$ms['session_id']] ?? null;
                    if ($mark === null) {
                        continue;
                    }
                    $sessionDate = $ms['existing_date'] ?? $ms['excel_date'];
                    save_attendance_record(
                        $conn,
                        (int) $row['student_id'],
                        $courseId,
                        (int) $ms['session_id'],
                        (int) $stuRow['academic_year_id'],
                        (string) $stuRow['shift'],
                        (string) $sessionDate,
                        $mark,
                        (int) $currentUser['id']
                    );
                    $importedCells++;
                }
                $studentsTouched[$row['student_id']] = true;
            }

            $conn->commit();
            unset($_SESSION['attendance_import_preview']);
            $_SESSION['flash_success'] = "Imported {$importedCells} attendance mark(s) across " . count($studentsTouched) . ' student(s).';
            redirect_to('reports.php?report_type=xiiso_grid&xiiso_course_id=' . $courseId . '&xiiso_semester_id=' . $semesterId);
        } catch (\Throwable $e) {
            $conn->rollback();
            $_SESSION['flash_error'] = 'Could not import attendance. Please try again.';
            unset($_SESSION['attendance_import_preview']);
            redirect_to('attendance_import.php');
        }
    } elseif ($action === 'cancel') {
        unset($_SESSION['attendance_import_preview']);
        redirect_to('attendance_import.php');
    }
}

$validCount = count(array_filter($previewRows, static fn ($r) => $r['status'] === 'ok'));
$invalidCount = count($previewRows) - $validCount;
$previewCourse = $previewCourseId > 0 ? ($courseById[$previewCourseId] ?? null) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Attendance — ADMAS Attendance System</title>
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
                <?php elseif ($role === 'lecturer'): ?>
                    Access scope: Your own assigned courses only
                <?php else: ?>
                    Access scope: Full system — all faculties, departments, and courses
                <?php endif; ?>
            </div>

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--admas-text);">Import Attendance from Excel</h4>
                    <p class="text-muted mb-0">Bulk-import historical attendance from a paper/Excel tracker (REG/NO, names, daily marks).</p>
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

            <?php if ($step === 'upload'): ?>
                <?php if (empty($courses)): ?>
                    <div class="admas-card p-4" style="max-width: 720px;">
                        <p class="text-muted mb-0">You have no courses available to import attendance for yet.</p>
                    </div>
                <?php else: ?>
                    <div class="admas-card p-4" style="max-width: 720px;">
                        <h6 class="fw-bold mb-3" style="color: var(--admas-text);">Upload File</h6>

                        <div class="alert alert-light border small mb-3">
                            Select the Course and the Semester this file's data belongs to, then upload the .xlsx/.xls/.csv
                            file. The file must have a <strong>REG/NO</strong> column, and one column per day
                            (grouped under month-band headers like "Feb-2026" is fine). The first 12 dates found
                            (in date order) are mapped to Xiiso 1–12 automatically; any extra dates are ignored.
                            <strong>First Names</strong>, <strong>Father's</strong>, and <strong>G.Father's</strong>
                            name columns are optional (shown for your own confirmation only — matching is always by
                            REG/NO). Cells should contain <strong>1</strong> for Present or <strong>0</strong> for
                            Absent; blank cells are left unmarked.
                        </div>

                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/attendance_import.php" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="preview">

                            <div class="mb-3">
                                <label for="importCourseSelect" class="form-label">Course</label>
                                <select class="form-select" id="importCourseSelect" name="course_id" required onchange="admasUpdateImportSemesterOptions(this.value)">
                                    <option value="">Select course</option>
                                    <?php
                                    $coursesByDept = [];
                                    foreach ($courses as $c) {
                                        $deptLabel = $role === 'system_admin'
                                            ? $c['department_name'] . ' — ' . $c['faculty_name']
                                            : $c['department_name'];
                                        $coursesByDept[$deptLabel][] = $c;
                                    }
                                    ?>
                                    <?php foreach ($coursesByDept as $deptLabel => $deptCourses): ?>
                                        <optgroup label="<?= htmlspecialchars($deptLabel) ?>">
                                            <?php foreach ($deptCourses as $c): ?>
                                                <option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars($c['code'] . ' — ' . $c['name']) ?></option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="importSemesterSelect" class="form-label">Semester</label>
                                <select class="form-select" id="importSemesterSelect" name="semester_id" required>
                                    <option value="">Select course first</option>
                                </select>
                                <div class="form-text">Any semester belonging to the course's faculty — not just the current one, since this is for historical data.</div>
                            </div>

                            <div class="mb-3">
                                <label for="importFileInput" class="form-label">Excel or CSV file</label>
                                <input type="file" class="form-control" id="importFileInput" name="import_file" accept=".xlsx,.xls,.csv" required>
                            </div>

                            <button type="submit" class="btn btn-primary" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                                <i class="bi bi-eye"></i> Preview Import
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="admas-card p-4 mb-3">
                    <h6 class="fw-bold mb-3" style="color: var(--admas-text);">
                        Xiiso Session Mapping
                        <?php if ($previewCourse): ?>
                            <span class="text-muted fw-normal">(<?= htmlspecialchars($previewCourse['code'] . ' — ' . $previewCourse['name']) ?>)</span>
                        <?php endif; ?>
                    </h6>
                    <div class="table-responsive mb-2">
                        <table class="table admas-table align-middle">
                            <thead>
                                <tr>
                                    <th>Xiiso</th>
                                    <th>Date (from file)</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($mappedSessions as $ms): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($ms['label']) ?></td>
                                        <td><?= htmlspecialchars($ms['excel_date']) ?></td>
                                        <td>
                                            <?php if ($ms['date_conflict']): ?>
                                                <span class="badge-pill badge-absent">Already has a different date (<?= htmlspecialchars((string) $ms['existing_date']) ?>) — will keep the existing date</span>
                                            <?php elseif ($ms['existing_date'] !== null): ?>
                                                <span class="badge-pill badge-active">Matches existing date</span>
                                            <?php else: ?>
                                                <span class="badge-pill badge-active">Will set this date</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (!empty($skippedDates)): ?>
                        <div class="alert alert-warning small mb-0">
                            <strong><?= count($skippedDates) ?> extra date column(s) were ignored</strong> (a semester only has 12 Xiiso sessions):
                            <?= htmlspecialchars(implode(', ', $skippedDates)) ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="admas-card p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                        <h6 class="fw-bold mb-0" style="color: var(--admas-text);">Preview</h6>
                        <div>
                            <span class="badge-pill badge-active me-1"><?= $validCount ?> ready</span>
                            <?php if ($invalidCount > 0): ?>
                                <span class="badge-pill badge-absent"><?= $invalidCount ?> with errors</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="table-responsive mb-3">
                        <table class="table admas-table align-middle">
                            <thead>
                                <tr>
                                    <th>REG/NO</th>
                                    <th>Name</th>
                                    <?php foreach ($mappedSessions as $msIdx => $ms): ?>
                                        <th class="text-center"><?= htmlspecialchars($ms['label']) ?></th>
                                    <?php endforeach; ?>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($previewRows as $r): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($r['reg_no']) ?></td>
                                        <td><?= htmlspecialchars($r['display_name']) ?></td>
                                        <?php foreach ($mappedSessions as $ms): ?>
                                            <?php $mark = $r['marks'][$ms['session_id']] ?? null; ?>
                                            <td class="text-center">
                                                <?= $mark === 'present' ? 'P' : ($mark === 'absent' ? 'A' : '—') ?>
                                            </td>
                                        <?php endforeach; ?>
                                        <td>
                                            <?php if ($r['status'] === 'ok'): ?>
                                                <span class="badge-pill badge-active"><i class="bi bi-check-lg"></i> <?= htmlspecialchars($r['message']) ?></span>
                                            <?php else: ?>
                                                <span class="badge-pill badge-absent"><i class="bi bi-x-lg"></i> <?= htmlspecialchars($r['message']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex gap-2">
                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/attendance_import.php">
                            <input type="hidden" name="action" value="confirm">
                            <button type="submit" class="btn btn-primary" style="background-color: var(--admas-sky); border-color: var(--admas-sky);" <?= $validCount === 0 ? 'disabled' : '' ?>>
                                <i class="bi bi-check2-circle"></i> Confirm Import (<?= $validCount ?> student<?= $validCount === 1 ? '' : 's' ?>)
                            </button>
                        </form>
                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/attendance_import.php">
                            <input type="hidden" name="action" value="cancel">
                            <button type="submit" class="btn btn-outline-secondary">Upload a Different File</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const coursesFacultyById = <?= json_encode(array_map(static fn ($c) => (int) $c['faculty_id'], $courseById), JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const importSemestersByFacultyId = <?= json_encode($semestersByFacultyId, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        function admasUpdateImportSemesterOptions(courseId) {
            const select = document.getElementById('importSemesterSelect');
            const facultyId = coursesFacultyById[courseId];
            const semesters = (facultyId !== undefined && importSemestersByFacultyId[facultyId]) || [];
            select.innerHTML = '';

            const blank = document.createElement('option');
            blank.value = '';
            blank.textContent = semesters.length === 0 ? 'No semesters for this faculty yet' : 'Select semester';
            select.appendChild(blank);

            semesters.forEach((sem) => {
                const opt = document.createElement('option');
                opt.value = String(sem.id);
                opt.textContent = sem.name;
                select.appendChild(opt);
            });
        }
    </script>
</body>
</html>
