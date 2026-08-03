<?php
/**
 * Import Attendance from Excel — bulk-imports historical attendance data
 * matching the university's own paper/Excel tracker format (REG/NO, three
 * name columns, P/A/% summary, then one column per class session) into
 * this app's own Xiiso-session model. Lives at the app root (not under
 * /admin) because it's shared by three roles, same placement/role-scoping
 * convention as attendance.php.
 *
 * Deliberately does NOT try to read a date out of the file at all — real
 * trackers vary too much in how they encode day/date headers (bare day
 * numbers, merged month-band cells, raw Excel date serials, ...) for that
 * to ever be fully reliable. Instead: any non-reserved column that
 * actually contains a 1 (Present) or 0 (Absent) mark in at least one
 * student's row is treated as a real session column, taken in left-to-right
 * order and mapped POSITIONALLY onto the selected semester's own Xiiso
 * 1-12 slots (1st such column -> Xiiso 1, 2nd -> Xiiso 2, ...); any columns
 * beyond the 12th are ignored. The semester's own already-assigned Xiiso
 * session dates (set via the Semesters page) are what gets used — if one
 * of the sessions being imported into has no date yet, the whole import is
 * blocked with a clear message rather than guessing one.
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
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

$conn = db();

// ---------------------------------------------------------------------
// Downloadable template (must run before any HTML output) — one header
// row (REG/NO + names + P/A/%), data starts immediately below. The day
// columns need NO header at all — any column with a 1/0 mark in it is
// picked up automatically, in left-to-right order, and mapped onto
// Xiiso 1, 2, 3... Cell values are 1 for Present, 0 for Absent, blank for
// unmarked.
// ---------------------------------------------------------------------
if (($_GET['action'] ?? '') === 'template') {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray(['REG/NO', 'First Names', "Father's", "G.Father's", 'P', 'A', '%'], null, 'A1');
    $sheet->fromArray(['1472/23', 'Amina', 'Hassan', 'Ali', '', '', '', 1, 1, 0, 1, 1], null, 'A2');
    $sheet->fromArray(['1489/23', 'Faisal', 'Ahmed', 'Warsame', '', '', '', 0, 1, 1, 1, 0], null, 'A3');
    $sheet->getStyle('A1:L1')->getFont()->setBold(true);
    foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L'] as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="attendance_import_template.xlsx"');
    header('Cache-Control: max-age=0');

    (new XlsxWriter($spreadsheet))->save('php://output');
    exit;
}

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
$skippedColumnCount = 0;
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
                        // Deliberately NOT setReadDataOnly(true) — merge-cell
                        // ranges (needed below to detect a single date label
                        // spanning several weekly session columns) are
                        // structural info that data-only mode discards.
                        $spreadsheet = $reader->load($_FILES['import_file']['tmp_name']);
                        $ws = $spreadsheet->getActiveSheet();
                        $rows = $ws->toArray(null, true, true, false);

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
                                $dataStartIdx = $headerRowIdx + 1;

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

                                    // Session columns are identified purely by content,
                                    // never by whatever (if anything) their header
                                    // cell says: any non-reserved column that holds a
                                    // real 1 (Present) or 0 (Absent) mark in at least
                                    // one student's row counts as one session, in
                                    // left-to-right order. This deliberately ignores
                                    // dates entirely — real trackers vary too much in
                                    // how they encode them (bare day numbers, merged
                                    // month-band cells, raw Excel date serials...) for
                                    // that to ever be fully reliable; the semester's
                                    // own already-assigned Xiiso dates are what's used
                                    // instead (checked further below).
                                    $dataRowsRaw = array_slice($rows, $dataStartIdx);
                                    $columnCount = 0;
                                    foreach ($rows as $rw) {
                                        $columnCount = max($columnCount, count($rw));
                                    }
                                    $sessionCols = [];
                                    for ($c = 0; $c < $columnCount; $c++) {
                                        if (in_array($c, $reservedCols, true)) {
                                            continue;
                                        }
                                        foreach ($dataRowsRaw as $rw) {
                                            $v = trim((string) ($rw[$c] ?? ''));
                                            if ($v === '1' || $v === '0') {
                                                $sessionCols[] = $c;
                                                break;
                                            }
                                        }
                                    }

                                    if (empty($sessionCols)) {
                                        $errorMessage = 'No attendance mark columns (1 for Present / 0 for Absent) could be detected in the uploaded file.';
                                    } else {
                                        // Cap at 12 — a semester only ever has 12 Xiiso
                                        // sessions. Extra columns beyond that are ignored.
                                        $usedCols = array_slice($sessionCols, 0, 12);
                                        $skippedColumnCount = count($sessionCols) - count($usedCols);

                                        // Ensure this semester's 12 Xiiso rows exist
                                        // (idempotent — safe to call every time).
                                        generate_sessions_for_semester($conn, $semesterId);
                                        $semesterSessions = get_sessions_for_semester($conn, $semesterId);

                                        $missingDateLabels = [];
                                        foreach ($semesterSessions as $i => $sess) {
                                            if (!isset($usedCols[$i])) {
                                                break;
                                            }
                                            if ($sess['date'] === null) {
                                                $missingDateLabels[] = $sess['label'];
                                                continue;
                                            }
                                            $mappedSessions[] = [
                                                'session_id' => (int) $sess['id'],
                                                'label' => $sess['label'],
                                                'excel_col' => $usedCols[$i],
                                                'date' => $sess['date'],
                                            ];
                                        }

                                        if (!empty($missingDateLabels)) {
                                            $errorMessage = 'These Xiiso sessions have no date assigned yet in this semester: '
                                                . implode(', ', $missingDateLabels)
                                                . '. Please assign dates on the Semesters page first, then try importing again.';
                                            $mappedSessions = [];
                                        }

                                        if (empty($missingDateLabels)) {
                                            $dataRows = $dataRowsRaw;
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
                                                    'skipped_column_count' => $skippedColumnCount,
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
                    save_attendance_record(
                        $conn,
                        (int) $row['student_id'],
                        $courseId,
                        (int) $ms['session_id'],
                        (int) $stuRow['academic_year_id'],
                        (string) $stuRow['shift'],
                        (string) $ms['date'],
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
                            file. The file must have a <strong>REG/NO</strong> column, and one column per class
                            session — <strong>dates in the file don't matter at all</strong> (headers, merged cells,
                            whatever you have is fine, or none at all): any column with a <strong>1</strong>
                            (Present) or <strong>0</strong> (Absent) mark is picked up automatically, in
                            left-to-right order, and mapped straight onto the selected Semester's own Xiiso 1–12 —
                            using that Semester's own already-assigned dates. Extra columns beyond 12 are ignored.
                            If a Xiiso session doesn't have a date yet, assign it on the
                            <a href="<?= htmlspecialchars(BASE_URL) ?>/semesters.php">Semesters</a> page first.
                            <strong>First Names</strong>, <strong>Father's</strong>, and <strong>G.Father's</strong>
                            name columns are optional (shown for your own confirmation only — matching is always by
                            REG/NO); blank cells are left unmarked.
                            <br>
                            <a href="?action=template" class="fw-semibold">
                                <i class="bi bi-download"></i> Download a starter template (.xlsx)
                            </a>
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
                                    <th>File column #</th>
                                    <th>Xiiso</th>
                                    <th>Date (from this semester)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($mappedSessions as $msPos => $ms): ?>
                                    <tr>
                                        <td><?= $msPos + 1 ?></td>
                                        <td><?= htmlspecialchars($ms['label']) ?></td>
                                        <td><?= htmlspecialchars((string) $ms['date']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($skippedColumnCount > 0): ?>
                        <div class="alert alert-warning small mb-0">
                            <strong><?= $skippedColumnCount ?> extra mark column(s) beyond the 12 Xiiso sessions were ignored.</strong>
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
