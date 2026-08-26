<?php
/**
 * Bulk-import Courses from an Excel (.xlsx/.xls) or CSV file.
 * Flow: upload -> preview (per-row validation) -> confirm.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';
require_once __DIR__ . '/../includes/lecturer_accounts.php';
require_once __DIR__ . '/../vendor/autoload.php';

// university_rector converted from full-CRUD to view-only oversight; bulk
// import has no meaningful "view" mode, so access is removed entirely
// rather than degraded (no other role has ever had access to this page).
require_role([]);

use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

$conn = db();

const IMPORT_SHIFT_LABELS = [
    'morning' => 'Morning Shift',
    'afternoon' => 'Afternoon Shift',
    'weekend' => 'Weekend',
    'any' => 'Any/All Shifts',
];

/**
 * Accept either the raw enum value ("morning") or the friendly label
 * ("Morning Shift"), case-insensitively — same helper as
 * admin/students_import.php's own (duplicated rather than shared, matching
 * that file's own local-helper convention).
 */
function normalize_shift_input_for_course(string $input): ?string
{
    $normalized = mb_strtolower(trim($input));
    if (array_key_exists($normalized, IMPORT_SHIFT_LABELS)) {
        return $normalized;
    }
    foreach (IMPORT_SHIFT_LABELS as $value => $label) {
        if (mb_strtolower($label) === $normalized) {
            return $value;
        }
    }

    return null;
}

// ---------------------------------------------------------------------
// Downloadable template (must run before any HTML output)
// ---------------------------------------------------------------------
if (($_GET['action'] ?? '') === 'template') {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray(['Code', 'Name', 'Department', 'Credit Hours', 'Academic Year', 'Semester', 'Shift', 'Lecturer'], null, 'A1');
    $sheet->fromArray(['CS101', 'Introduction to Programming', 'Computer Science', 3, '2025', 'Semester 1', 'Morning Shift', 'Ahmed Cali'], null, 'A2');
    $sheet->fromArray(['CS205', 'Databases', 'Computer Science', 3, '', '', '', ''], null, 'A3');
    $sheet->getStyle('A1:H1')->getFont()->setBold(true);
    foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'] as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="course_import_template.xlsx"');
    header('Cache-Control: max-age=0');

    (new XlsxWriter($spreadsheet))->save('php://output');
    exit;
}

$currentUser = current_user();

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
    // Never resume a stale preview across a fresh page load.
    unset($_SESSION['course_import_preview']);
}

$step = 'upload';
$previewRows = [];

$existingDepartments = $conn->query('SELECT id, name, faculty_id FROM departments ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$departmentByLowerName = [];
$departmentFacultyIdById = [];
foreach ($existingDepartments as $dept) {
    $departmentByLowerName[mb_strtolower(trim((string) $dept['name']))] = (int) $dept['id'];
    $departmentFacultyIdById[(int) $dept['id']] = (int) $dept['faculty_id'];
}

// The four "first offering" columns below are all optional, as a group —
// left blank, a row imports as a catalog-only course exactly like before
// (same opt-in rule as admin/courses.php's manual Add Course form). Only
// consulted when a row actually provides a Semester.
$existingAcademicYears = $conn->query('SELECT id, label FROM academic_years ORDER BY label')->fetch_all(MYSQLI_ASSOC);
$academicYearByLowerLabel = [];
foreach ($existingAcademicYears as $ay) {
    $academicYearByLowerLabel[mb_strtolower(trim((string) $ay['label']))] = (int) $ay['id'];
}

// Semesters are scoped by (faculty, academic year, name) — the same name
// ("Semester 1") can legitimately exist in more than one academic year for
// the same faculty, so Academic Year is what disambiguates which row this
// resolves to (not just faculty+name, unlike the simpler per-faculty-only
// lookup students_import.php uses).
$existingSemesters = $conn->query('SELECT id, name, faculty_id, academic_year_id FROM semesters ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$semesterByFacultyYearAndLowerName = [];
foreach ($existingSemesters as $sem) {
    $key = (int) $sem['faculty_id'] . '|' . (int) $sem['academic_year_id'] . '|' . mb_strtolower(trim((string) $sem['name']));
    $semesterByFacultyYearAndLowerName[$key] = (int) $sem['id'];
}

// Any active lecturer system-wide may be assigned (not just ones in the
// course's own department) — same "common courses across faculties" reasoning
// as admin/courses.php's own offering-lecturer field.
$existingLecturers = $conn->query("SELECT id, full_name FROM lecturers WHERE status = 'active'")->fetch_all(MYSQLI_ASSOC);
$lecturerByLowerName = [];
foreach ($existingLecturers as $lec) {
    $lecturerByLowerName[mb_strtolower(trim((string) $lec['full_name']))] = (int) $lec['id'];
}

// ---------------------------------------------------------------------
// Handle POST actions: preview, confirm, cancel
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'preview') {
        if (empty($existingDepartments)) {
            $errorMessage = 'No departments exist yet — create at least one department before importing courses.';
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
                        // Some real sheets have a decorative title banner above the
                        // real header row (university name, faculty, a note, etc.) —
                        // same idea as admin/students_import.php's own banner-skipping.
                        // Keep advancing past any row that doesn't actually look like
                        // the real header row (no Code-like or Name-like cell anywhere
                        // in it) until the real one is found.
                        $looksLikeCourseHeaderRow = static function (array $row): bool {
                            $normalizedCells = array_map(
                                static fn ($h) => mb_strtolower(trim((string) $h)),
                                $row
                            );
                            $codeCandidates = ['code', 'course code'];
                            $nameCandidates = ['name', 'course name'];

                            return find_import_column($normalizedCells, $codeCandidates) !== false
                                || find_import_column($normalizedCells, $nameCandidates) !== false;
                        };
                        $tableStart = 0;
                        while ($tableStart < count($rows) && !$looksLikeCourseHeaderRow($rows[$tableStart])) {
                            $tableStart++;
                        }

                        if ($tableStart >= count($rows)) {
                            $errorMessage = 'No course table was found in the uploaded file.';
                        } else {
                        $headerRow = array_map(static fn ($h) => mb_strtolower(trim((string) $h)), $rows[$tableStart]);
                        $dataRows = array_slice($rows, $tableStart + 1);
                        $rowNumber = $tableStart + 1;

                        $codeCol = find_import_column($headerRow, ['code', 'course code']);
                        $nameCol = find_import_column($headerRow, ['name', 'course name']);
                        $departmentCol = find_import_column($headerRow, ['department', 'department name']);
                        $creditCol = find_import_column($headerRow, ['credit hours', 'credit_hours', 'credits']);
                        // Optional "first offering" columns — a group, opt-in per row.
                        $academicYearCol = find_import_column($headerRow, ['academic year', 'sanadka waxbarasho', 'sanadka waxbarashada', 'sanadka']);
                        $semesterCol = find_import_column($headerRow, ['semester', 'semesterka']);
                        $shiftCol = find_import_column($headerRow, ['shift', 'shiftka']);
                        $lecturerCol = find_import_column($headerRow, ['lecturer', 'lecturer name', 'macalinka', 'macallinka']);

                        if ($codeCol === false || $nameCol === false || $departmentCol === false) {
                            $errorMessage = 'The file must have "Code", "Name" and "Department" column headers.';
                        } else {
                            $seenInFile = [];

                            foreach ($dataRows as $row) {
                                $rowNumber++;
                                $code = trim((string) ($row[$codeCol] ?? ''));
                                $name = trim((string) ($row[$nameCol] ?? ''));
                                $departmentInput = trim((string) ($row[$departmentCol] ?? ''));
                                $creditInput = $creditCol !== false ? trim((string) ($row[$creditCol] ?? '')) : '';
                                $academicYearInput = $academicYearCol !== false ? trim((string) ($row[$academicYearCol] ?? '')) : '';
                                $semesterInput = $semesterCol !== false ? trim((string) ($row[$semesterCol] ?? '')) : '';
                                $shiftInput = $shiftCol !== false ? trim((string) ($row[$shiftCol] ?? '')) : '';
                                $lecturerInput = $lecturerCol !== false ? trim((string) ($row[$lecturerCol] ?? '')) : '';

                                if ($code === '' && $name === '' && $departmentInput === '') {
                                    continue;
                                }

                                $status = 'ok';
                                $message = 'Ready to import';
                                $departmentId = 0;
                                $departmentFacultyId = 0;
                                $creditHours = 3;
                                $codeUpper = strtoupper($code);
                                $academicYearId = 0;
                                $semesterId = 0;
                                $shift = null;
                                $lecturerId = 0;

                                if ($code === '') {
                                    $status = 'error';
                                    $message = 'Missing Code';
                                } elseif ($name === '') {
                                    $status = 'error';
                                    $message = 'Missing Name';
                                } elseif ($departmentInput === '') {
                                    $status = 'error';
                                    $message = 'Missing Department';
                                } else {
                                    $departmentId = $departmentByLowerName[mb_strtolower($departmentInput)] ?? 0;
                                    if ($departmentId === 0) {
                                        $status = 'error';
                                        $message = 'Unknown department "' . $departmentInput . '"';
                                    } else {
                                        $departmentFacultyId = $departmentFacultyIdById[$departmentId] ?? 0;
                                    }
                                }

                                if ($status === 'ok' && $creditInput !== '') {
                                    if (!ctype_digit($creditInput) || (int) $creditInput < 1 || (int) $creditInput > 10) {
                                        $status = 'error';
                                        $message = 'Invalid Credit Hours (must be a number 1-10)';
                                    } else {
                                        $creditHours = (int) $creditInput;
                                    }
                                }

                                // The offering columns are only validated at all when a
                                // Semester was actually given — left blank, the row
                                // imports as a catalog-only course, same opt-in rule as
                                // the manual Add Course form.
                                if ($status === 'ok' && $semesterInput !== '') {
                                    if ($academicYearInput === '') {
                                        $status = 'error';
                                        $message = 'Semester given but Academic Year is missing (needed to identify which "' . $semesterInput . '")';
                                    } elseif ($shiftInput === '') {
                                        $status = 'error';
                                        $message = 'Semester given but Shift is missing';
                                    } else {
                                        $academicYearId = $academicYearByLowerLabel[mb_strtolower($academicYearInput)] ?? 0;
                                        if ($academicYearId === 0) {
                                            $status = 'error';
                                            $message = 'Unknown academic year "' . $academicYearInput . '"';
                                        } else {
                                            $semKey = $departmentFacultyId . '|' . $academicYearId . '|' . mb_strtolower($semesterInput);
                                            $semesterId = $semesterByFacultyYearAndLowerName[$semKey] ?? 0;
                                            if ($semesterId === 0) {
                                                $status = 'error';
                                                $message = 'Unknown semester "' . $semesterInput . '" for "' . $departmentInput . '"\'s faculty in ' . $academicYearInput;
                                            } else {
                                                $shift = normalize_shift_input_for_course($shiftInput);
                                                if ($shift === null) {
                                                    $status = 'error';
                                                    $message = 'Invalid Shift "' . $shiftInput . '" (use Morning Shift, Afternoon Shift, or Weekend)';
                                                } elseif ($lecturerInput !== '') {
                                                    $lecturerId = $lecturerByLowerName[mb_strtolower($lecturerInput)] ?? 0;
                                                    if ($lecturerId === 0) {
                                                        $status = 'error';
                                                        $message = 'Unknown lecturer "' . $lecturerInput . '"';
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }

                                if ($status === 'ok') {
                                    $dedupeKey = $departmentId . '|' . $codeUpper;
                                    if (isset($seenInFile[$dedupeKey])) {
                                        $status = 'error';
                                        $message = 'Duplicate code within this file for the same department';
                                    } else {
                                        $checkStmt = $conn->prepare('SELECT id FROM courses WHERE department_id = ? AND UPPER(code) = ?');
                                        $checkStmt->bind_param('is', $departmentId, $codeUpper);
                                        $checkStmt->execute();
                                        if ($checkStmt->get_result()->fetch_assoc()) {
                                            $status = 'error';
                                            $message = 'Code already exists in this department';
                                        }
                                        $checkStmt->close();
                                    }

                                    if ($status === 'ok') {
                                        $seenInFile[$dedupeKey] = true;
                                    }
                                }

                                $previewRows[] = [
                                    'row' => $rowNumber,
                                    'code' => $codeUpper,
                                    'name' => $name,
                                    'department_input' => $departmentInput,
                                    'department_id' => $departmentId,
                                    'credit_hours' => $creditHours,
                                    'academic_year_input' => $academicYearInput,
                                    'semester_input' => $semesterInput,
                                    'shift_input' => $shiftInput,
                                    'lecturer_input' => $lecturerInput,
                                    'academic_year_id' => $academicYearId,
                                    'semester_id' => $semesterId,
                                    'shift' => $shift,
                                    'lecturer_id' => $lecturerId,
                                    'status' => $status,
                                    'message' => $message,
                                ];
                            }

                            if (empty($previewRows)) {
                                $errorMessage = 'No data rows were found in the uploaded file.';
                            } else {
                                $_SESSION['course_import_preview'] = $previewRows;
                                $step = 'preview';
                            }
                        }
                        }
                    }
                } catch (\Throwable $e) {
                    $errorMessage = 'Could not read the uploaded file. Please make sure it is a valid Excel or CSV file.';
                }
            }
        }
    } elseif ($action === 'confirm') {
        $previewRows = $_SESSION['course_import_preview'] ?? [];

        if (empty($previewRows)) {
            $_SESSION['flash_error'] = 'Your import session expired. Please upload the file again.';
        } else {
            $validRows = array_values(array_filter($previewRows, static fn ($r) => $r['status'] === 'ok'));

            if (empty($validRows)) {
                $_SESSION['flash_error'] = 'There were no valid rows to import.';
            } else {
                // Each row is re-validated and committed independently —
                // the preview step's duplicate-code check ran earlier and
                // may now be stale (another admin, or a second import, may
                // have created a colliding course in the meantime). One
                // row's failure only skips that row, matching this app's
                // established skip-and-report convention for bulk actions
                // (e.g. bulk delete) rather than discarding the entire
                // batch — previously a single stale collision anywhere in
                // the file would roll back and drop every valid row.
                $insertStmt = $conn->prepare('INSERT INTO courses (code, name, department_id, credit_hours) VALUES (?, ?, ?, ?)');
                $offeringStmt = $conn->prepare(
                    'INSERT INTO course_offerings (course_id, semester_id, lecturer_id, shift) VALUES (?, ?, ?, ?)'
                );
                $dupCheckStmt = $conn->prepare('SELECT id FROM courses WHERE department_id = ? AND UPPER(code) = ?');

                $imported = 0;
                $offeringsCreated = 0;
                $failedRows = [];

                foreach ($validRows as $row) {
                    $dupCheckStmt->bind_param('is', $row['department_id'], $row['code']);
                    $dupCheckStmt->execute();
                    if ($dupCheckStmt->get_result()->fetch_assoc()) {
                        $failedRows[] = 'Row ' . $row['row'] . ' (' . $row['code'] . '): code now already exists in this department';
                        continue;
                    }

                    $conn->begin_transaction();
                    try {
                        $insertStmt->bind_param('ssii', $row['code'], $row['name'], $row['department_id'], $row['credit_hours']);
                        $insertStmt->execute();
                        $newCourseId = (int) $conn->insert_id;

                        // Same opt-in rule as the manual Add Course form: only rows
                        // that resolved a Semester (validated in the preview step
                        // above) also get a course_offerings row.
                        if ($row['semester_id'] > 0) {
                            $lecturerParam = $row['lecturer_id'] > 0 ? $row['lecturer_id'] : null;
                            $offeringStmt->bind_param('iiis', $newCourseId, $row['semester_id'], $lecturerParam, $row['shift']);
                            $offeringStmt->execute();
                            $offeringsCreated++;
                        }

                        $conn->commit();
                        $imported++;
                    } catch (\Throwable $e) {
                        $conn->rollback();
                        $failedRows[] = 'Row ' . $row['row'] . ' (' . $row['code'] . '): could not be saved';
                    }
                }
                $insertStmt->close();
                $offeringStmt->close();
                $dupCheckStmt->close();

                $skipped = count($previewRows) - count($validRows) + count($failedRows);
                $message = "Imported {$imported} course(s) successfully."
                    . ($offeringsCreated > 0 ? " Created {$offeringsCreated} semester offering(s)." : '')
                    . ($skipped > 0 ? " Skipped {$skipped} invalid row(s)." : '');
                if (!empty($failedRows)) {
                    $message .= ' Failed at confirm time: ' . implode(' | ', $failedRows);
                }

                if ($imported > 0) {
                    $_SESSION['flash_success'] = $message;
                } else {
                    $_SESSION['flash_error'] = $message;
                }
            }
        }

        unset($_SESSION['course_import_preview']);
        redirect_to('admin/courses.php');
    } elseif ($action === 'cancel') {
        unset($_SESSION['course_import_preview']);
        redirect_to('admin/courses_import.php');
    }
}

$validCount = count(array_filter($previewRows, static fn ($r) => $r['status'] === 'ok'));
$invalidCount = count($previewRows) - $validCount;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Courses — ADMAS Attendance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(BASE_URL) ?>/assets/css/app.css" rel="stylesheet">
</head>
<body>
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="main-content">
        <?php include __DIR__ . '/../includes/topbar.php'; ?>

        <div class="page-body">
            <div class="scope-banner">
                <i class="bi bi-shield-check"></i>
                Access scope: Full system — all faculties, departments, and courses
            </div>

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--admas-text);">Import Courses from Excel</h4>
                    <p class="text-muted mb-0">Bulk-register courses from a .xlsx, .xls, or .csv file.</p>
                </div>
                <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/courses.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left"></i> Back to Courses
                </a>
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
                <div class="admas-card p-4" style="max-width: 640px;">
                    <h6 class="fw-bold mb-3" style="color: var(--admas-text);">Upload File</h6>

                    <div class="alert alert-light border small mb-3">
                        The file must have column headers: <strong>Code</strong>, <strong>Name</strong>,
                        and <strong>Department</strong> (the Department value must match an existing
                        department's name). <strong>Credit Hours</strong> is optional and defaults to 3
                        if left blank.
                        <br><br>
                        You can also, per row, optionally add <strong>Academic Year</strong>,
                        <strong>Semester</strong>, <strong>Shift</strong>, and <strong>Lecturer</strong> —
                        when a row has a Semester, that course is immediately offered for that semester
                        too (same as "Manage Offerings"), all in this one import. Leave all four blank
                        on a row to import just the course itself, to offer later. Any decorative title
                        rows above your real header row (university name, notes, etc.) are automatically
                        skipped.
                        <br><br>
                        <a href="?action=template" class="fw-semibold">
                            <i class="bi bi-download"></i> Download a starter template (.xlsx)
                        </a>
                    </div>

                    <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/courses_import.php" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="preview">
                        <div class="mb-3">
                            <label for="importFileInput" class="form-label">Excel or CSV file</label>
                            <input type="file" class="form-control" id="importFileInput" name="import_file" accept=".xlsx,.xls,.csv" required>
                        </div>
                        <button type="submit" class="btn btn-primary" style="background-color: var(--admas-sky); border-color: var(--admas-sky);" <?= empty($existingDepartments) ? 'disabled' : '' ?>>
                            <i class="bi bi-eye"></i> Preview Import
                        </button>
                    </form>
                </div>
            <?php else: ?>
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
                                    <th>Row</th>
                                    <th>Code</th>
                                    <th>Name</th>
                                    <th>Department</th>
                                    <th>Credit Hours</th>
                                    <th>Offering</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($previewRows as $r): ?>
                                    <tr>
                                        <td><?= (int) $r['row'] ?></td>
                                        <td><?= htmlspecialchars($r['code']) ?></td>
                                        <td><?= htmlspecialchars($r['name']) ?></td>
                                        <td><?= htmlspecialchars($r['department_input']) ?></td>
                                        <td><?= (int) $r['credit_hours'] ?></td>
                                        <td>
                                            <?php if ($r['semester_input'] === ''): ?>
                                                <span class="text-muted">&mdash;</span>
                                            <?php else: ?>
                                                <?= htmlspecialchars($r['semester_input']) ?>
                                                <?php if ($r['academic_year_input'] !== ''): ?>
                                                    (<?= htmlspecialchars($r['academic_year_input']) ?>)
                                                <?php endif; ?>
                                                <?php if ($r['shift'] !== null): ?>
                                                    &middot; <?= htmlspecialchars(IMPORT_SHIFT_LABELS[$r['shift']]) ?>
                                                <?php endif; ?>
                                                <?php if ($r['lecturer_input'] !== ''): ?>
                                                    &middot; <?= htmlspecialchars($r['lecturer_input']) ?>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
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
                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/courses_import.php">
                            <input type="hidden" name="action" value="confirm">
                            <button type="submit" class="btn btn-primary" style="background-color: var(--admas-sky); border-color: var(--admas-sky);" <?= $validCount === 0 ? 'disabled' : '' ?>>
                                <i class="bi bi-check2-circle"></i> Confirm Import (<?= $validCount ?>)
                            </button>
                        </form>
                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/courses_import.php">
                            <input type="hidden" name="action" value="cancel">
                            <button type="submit" class="btn btn-outline-secondary">Upload a Different File</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
