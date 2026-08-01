<?php
/**
 * Bulk-import Students from an Excel (.xlsx/.xls) or CSV file.
 * Flow: upload -> preview (per-row validation) -> confirm -> redirect back
 * to admin/students.php with a success message (same pattern as
 * courses_import.php and lecturers_import.php).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';
require_once __DIR__ . '/../includes/lecturer_accounts.php';
require_once __DIR__ . '/../vendor/autoload.php';

// Registration Office also has bulk Excel import per CLAUDE.md §4 — see
// admin/students.php for the same reasoning.
require_role(['system_admin', 'registration']);

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
];

/**
 * Accept either the raw enum value ("morning") or the friendly label
 * ("Morning Shift"), case-insensitively.
 */
function normalize_shift_input(string $input): ?string
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
    $sheet->fromArray(['Student No', 'First Name', "Father's Name", "Grandfather's Name", 'Academic Year', 'Faculty', 'Department', 'Semester', 'Shift'], null, 'A1');
    $sheet->fromArray(['1472/23', 'Amina', 'Hassan', 'Ali', '2025/2026', 'Engineering & IT', 'Computer Science', 'Semester 1', 'Morning Shift'], null, 'A2');
    $sheet->getStyle('A1:I1')->getFont()->setBold(true);
    foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'] as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="student_import_template.xlsx"');
    header('Cache-Control: max-age=0');

    (new XlsxWriter($spreadsheet))->save('php://output');
    exit;
}

$studentRoleId = 0;
$roleResult = $conn->query("SELECT id FROM roles WHERE name = 'student'");
if ($roleResult && ($roleRow = $roleResult->fetch_assoc())) {
    $studentRoleId = (int) $roleRow['id'];
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
    unset($_SESSION['student_import_preview']);
}

$step = 'upload';
$previewRows = [];

$existingAcademicYears = $conn->query('SELECT id, label FROM academic_years ORDER BY label')->fetch_all(MYSQLI_ASSOC);
$academicYearByLowerLabel = [];
foreach ($existingAcademicYears as $ay) {
    $academicYearByLowerLabel[mb_strtolower(trim((string) $ay['label']))] = (int) $ay['id'];
}

$existingFaculties = $conn->query('SELECT id, name FROM faculties ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$facultyByLowerName = [];
foreach ($existingFaculties as $fac) {
    $facultyByLowerName[mb_strtolower(trim((string) $fac['name']))] = (int) $fac['id'];
}

$existingDepartments = $conn->query('SELECT id, name, faculty_id FROM departments ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$departmentByFacultyAndLowerName = [];
foreach ($existingDepartments as $dept) {
    $key = (int) $dept['faculty_id'] . '|' . mb_strtolower(trim((string) $dept['name']));
    $departmentByFacultyAndLowerName[$key] = (int) $dept['id'];
}

// Semesters are scoped to a faculty, same lookup shape as departments —
// a "Semester 1" name only resolves within the row's already-resolved
// Faculty, never globally.
$existingSemesters = $conn->query('SELECT id, name, faculty_id FROM semesters ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$semesterByFacultyAndLowerName = [];
// Fallback for a bare number ("1", "Semester 1") against a semester named
// e.g. "semester1" — only kept when the digits are unambiguous within the
// faculty (no two semesters in the same faculty reducing to the same digits).
$semesterByFacultyAndDigits = [];
foreach ($existingSemesters as $sem) {
    $facultyId = (int) $sem['faculty_id'];
    $name = mb_strtolower(trim((string) $sem['name']));
    $key = $facultyId . '|' . $name;
    $semesterByFacultyAndLowerName[$key] = (int) $sem['id'];

    $digits = preg_replace('/\D/', '', $name);
    if ($digits !== '') {
        $digitsKey = $facultyId . '|' . $digits;
        $semesterByFacultyAndDigits[$digitsKey] = array_key_exists($digitsKey, $semesterByFacultyAndDigits)
            ? -1 // ambiguous: two semesters in this faculty share the same digits — don't guess
            : (int) $sem['id'];
    }
}

// ---------------------------------------------------------------------
// Handle POST actions: preview, confirm, cancel
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'preview') {
        if (empty($existingFaculties) || empty($existingDepartments) || empty($existingAcademicYears) || empty($existingSemesters)) {
            $errorMessage = 'At least one Academic Year, Faculty, Department, and Semester must exist before importing students.';
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
                        // Some sheets put shared info at the top as "Field:", "value"
                        // rows (e.g. "Faculty:", "Informatic") before the actual
                        // Name/ID table — common when a whole sheet is one class
                        // where every student shares the same Academic Year/Faculty/
                        // Department/Semester/Shift. Peel those off as defaults so
                        // the per-row table only strictly needs Student No + Full
                        // Name; any of these 5 fields can still be overridden with
                        // its own column in the table below.
                        $batchDefaults = ['academic_year' => '', 'faculty' => '', 'department' => '', 'semester' => '', 'shift' => ''];
                        $batchFieldLabels = [
                            'academic_year' => ['academic year', 'sanadka waxbarasho', 'sanadka waxbarashada', 'sanadka'],
                            'faculty' => ['faculty', 'kulliyadda', 'kulliyad'],
                            'department' => ['department', 'waaxda', 'waax'],
                            'semester' => ['semester', 'semesterka'],
                            'shift' => ['shift', 'shiftka'],
                        ];

                        $tableStart = 0;
                        while ($tableStart < count($rows)) {
                            $label = str_replace([':', '-'], '', mb_strtolower(trim((string) ($rows[$tableStart][0] ?? ''))));
                            $matchedField = null;
                            foreach ($batchFieldLabels as $field => $labels) {
                                if (in_array($label, $labels, true)) {
                                    $matchedField = $field;
                                    break;
                                }
                            }
                            if ($matchedField === null) {
                                break;
                            }
                            $batchDefaults[$matchedField] = trim((string) ($rows[$tableStart][1] ?? ''));
                            $tableStart++;
                        }
                        // Skip a blank separator row between the batch-header block
                        // and the table.
                        while ($tableStart < count($rows) && count(array_filter($rows[$tableStart], static fn ($c) => trim((string) $c) !== '')) === 0) {
                            $tableStart++;
                        }

                        // Some real sheets also have a decorative title banner above
                        // the table (university name, faculty, course/lecturer info —
                        // same idea as attendance_import.php's own banner-skipping),
                        // which won't match the "Field:", "value" pattern above and
                        // isn't blank either. Keep advancing past any row that doesn't
                        // actually look like the real header row (i.e. doesn't contain
                        // a Student No/REG-No-like or First Name-like cell anywhere in
                        // it) until the real header row is found.
                        $looksLikeHeaderRow = static function (array $row): bool {
                            $normalizedCells = array_map(
                                static fn ($h) => str_replace('-', '', mb_strtolower(trim((string) $h))),
                                $row
                            );
                            $studentNoCandidates = ['student no', 'studentno', 'id', 'idga ardayga', 'idga', 'lambarka ardayga', 'lambarka', 'reg no', 'regno', 'reg/no'];
                            $firstNameCandidates = ['first names', 'first name', 'firstname', 'magaca koowaad', 'magaca hore'];

                            return find_import_column($normalizedCells, $studentNoCandidates) !== false
                                || find_import_column($normalizedCells, $firstNameCandidates) !== false;
                        };
                        while ($tableStart < count($rows) && !$looksLikeHeaderRow($rows[$tableStart])) {
                            $tableStart++;
                        }

                        if ($tableStart >= count($rows)) {
                            $errorMessage = 'No student table was found in the uploaded file.';
                        } else {
                        $headerRow = array_map(static fn ($h) => str_replace('-', '', mb_strtolower(trim((string) $h))), $rows[$tableStart]);
                        $dataRows = array_slice($rows, $tableStart + 1);
                        $rowNumber = $tableStart + 1;

                        // Each field accepts English and Somali header synonyms, so
                        // staff can write the sheet in whichever language is natural
                        // for them without needing to rename columns first. Hyphens
                        // are stripped from both sides above, so "ID-ga"/"id ga"/
                        // "idga" all match the same candidate.
                        $studentNoCol = find_import_column($headerRow, ['student no', 'studentno', 'id', 'idga ardayga', 'idga', 'lambarka ardayga', 'lambarka', 'reg no', 'regno', 'reg/no']);
                        $firstNameCol = find_import_column($headerRow, ['first names', 'first name', 'firstname', 'magaca koowaad', 'magaca hore']);
                        $fatherNameCol = find_import_column($headerRow, ["father's", "father's name", 'fathers name', 'father name', 'magaca aabaha']);
                        $grandfatherNameCol = find_import_column($headerRow, ["g.father's", "g father's", "grandfather's", 'grandfather', 'grandfathers name', "grandfather's name", 'magaca awoowaha']);
                        $yearCol = find_import_column($headerRow, ['academic year', 'sanadka waxbarasho', 'sanadka waxbarashada', 'sanadka']);
                        $facultyCol = find_import_column($headerRow, ['faculty', 'kulliyadda', 'kulliyad']);
                        $departmentCol = find_import_column($headerRow, ['department', 'department name', 'waaxda', 'waax']);
                        $semesterCol = find_import_column($headerRow, ['semester', 'semesterka']);
                        $shiftCol = find_import_column($headerRow, ['shift', 'shiftka']);

                        $missingRequired = $studentNoCol === false || $firstNameCol === false || $fatherNameCol === false
                            || ($yearCol === false && $batchDefaults['academic_year'] === '')
                            || ($facultyCol === false && $batchDefaults['faculty'] === '')
                            || ($departmentCol === false && $batchDefaults['department'] === '')
                            || ($semesterCol === false && $batchDefaults['semester'] === '')
                            || ($shiftCol === false && $batchDefaults['shift'] === '');

                        if ($missingRequired) {
                            $errorMessage = 'The file must have "Student No" (or "REG/NO"), "First Names", and "Father\'s Name" columns, plus Academic Year, Faculty, Department, Semester and Shift — either as columns in the table, or as "Field:", "value" rows above it. "Grandfather\'s Name" is optional.';
                        } else {
                            $seenStudentNosInFile = [];

                            foreach ($dataRows as $row) {
                                $rowNumber++;
                                $studentNo = strtoupper(trim((string) ($row[$studentNoCol] ?? '')));
                                $firstName = trim((string) ($row[$firstNameCol] ?? ''));
                                $fatherName = trim((string) ($row[$fatherNameCol] ?? ''));
                                $grandfatherName = $grandfatherNameCol !== false ? trim((string) ($row[$grandfatherNameCol] ?? '')) : '';
                                $fullName = trim($firstName . ' ' . $fatherName . ' ' . $grandfatherName);

                                if ($studentNo === '' && $firstName === '' && $fatherName === '') {
                                    continue;
                                }

                                $yearInput = $yearCol !== false ? trim((string) ($row[$yearCol] ?? '')) : '';
                                if ($yearInput === '') {
                                    $yearInput = $batchDefaults['academic_year'];
                                }
                                $facultyInput = $facultyCol !== false ? trim((string) ($row[$facultyCol] ?? '')) : '';
                                if ($facultyInput === '') {
                                    $facultyInput = $batchDefaults['faculty'];
                                }
                                $departmentInput = $departmentCol !== false ? trim((string) ($row[$departmentCol] ?? '')) : '';
                                if ($departmentInput === '') {
                                    $departmentInput = $batchDefaults['department'];
                                }
                                $semesterInput = $semesterCol !== false ? trim((string) ($row[$semesterCol] ?? '')) : '';
                                if ($semesterInput === '') {
                                    $semesterInput = $batchDefaults['semester'];
                                }
                                $shiftInput = $shiftCol !== false ? trim((string) ($row[$shiftCol] ?? '')) : '';
                                if ($shiftInput === '') {
                                    $shiftInput = $batchDefaults['shift'];
                                }

                                $status = 'ok';
                                $message = 'Ready to import';
                                $academicYearId = 0;
                                $facultyId = 0;
                                $departmentId = 0;
                                $semesterId = 0;
                                $shift = null;

                                if ($studentNo === '') {
                                    $status = 'error';
                                    $message = 'Missing Student No';
                                } elseif ($firstName === '') {
                                    $status = 'error';
                                    $message = 'Missing First Name';
                                } elseif ($fatherName === '') {
                                    $status = 'error';
                                    $message = "Missing Father's Name";
                                } elseif ($yearInput === '') {
                                    $status = 'error';
                                    $message = 'Missing Academic Year';
                                } elseif ($facultyInput === '') {
                                    $status = 'error';
                                    $message = 'Missing Faculty';
                                } elseif ($departmentInput === '') {
                                    $status = 'error';
                                    $message = 'Missing Department';
                                } elseif ($semesterInput === '') {
                                    $status = 'error';
                                    $message = 'Missing Semester';
                                } elseif ($shiftInput === '') {
                                    $status = 'error';
                                    $message = 'Missing Shift';
                                } else {
                                    $academicYearId = $academicYearByLowerLabel[mb_strtolower($yearInput)] ?? 0;
                                    if ($academicYearId === 0) {
                                        $status = 'error';
                                        $message = 'Unknown academic year "' . $yearInput . '"';
                                    }
                                }

                                if ($status === 'ok') {
                                    $facultyId = $facultyByLowerName[mb_strtolower($facultyInput)] ?? 0;
                                    if ($facultyId === 0) {
                                        $status = 'error';
                                        $message = 'Unknown faculty "' . $facultyInput . '"';
                                    }
                                }

                                if ($status === 'ok') {
                                    $deptKey = $facultyId . '|' . mb_strtolower($departmentInput);
                                    $departmentId = $departmentByFacultyAndLowerName[$deptKey] ?? 0;
                                    if ($departmentId === 0) {
                                        $status = 'error';
                                        $message = 'Unknown department "' . $departmentInput . '" in faculty "' . $facultyInput . '"';
                                    }
                                }

                                if ($status === 'ok') {
                                    $semKey = $facultyId . '|' . mb_strtolower($semesterInput);
                                    $semesterId = $semesterByFacultyAndLowerName[$semKey] ?? 0;

                                    if ($semesterId === 0) {
                                        // Fall back to matching a bare number ("1", "Semester 1")
                                        // against a semester named e.g. "semester1", but only when
                                        // that's unambiguous within the faculty.
                                        $digits = preg_replace('/\D/', '', $semesterInput);
                                        if ($digits !== '') {
                                            $digitsId = $semesterByFacultyAndDigits[$facultyId . '|' . $digits] ?? 0;
                                            if ($digitsId > 0) {
                                                $semesterId = $digitsId;
                                            }
                                        }
                                    }

                                    if ($semesterId === 0) {
                                        $status = 'error';
                                        $message = 'Unknown semester "' . $semesterInput . '" in faculty "' . $facultyInput . '"';
                                    }
                                }

                                if ($status === 'ok') {
                                    $shift = normalize_shift_input($shiftInput);
                                    if ($shift === null) {
                                        $status = 'error';
                                        $message = 'Invalid Shift "' . $shiftInput . '" (use Morning Shift, Afternoon Shift, or Weekend)';
                                    }
                                }

                                if ($status === 'ok') {
                                    if (isset($seenStudentNosInFile[$studentNo])) {
                                        $status = 'error';
                                        $message = 'Duplicate Student No within this file';
                                    } else {
                                        $dupNoStmt = $conn->prepare('SELECT id FROM students WHERE UPPER(student_no) = ?');
                                        $dupNoStmt->bind_param('s', $studentNo);
                                        $dupNoStmt->execute();
                                        if ($dupNoStmt->get_result()->fetch_assoc()) {
                                            $status = 'error';
                                            $message = 'Student No already exists';
                                        }
                                        $dupNoStmt->close();
                                    }

                                    if ($status === 'ok') {
                                        $seenStudentNosInFile[$studentNo] = true;
                                    }
                                }

                                $previewRows[] = [
                                    'row' => $rowNumber,
                                    'student_no' => $studentNo,
                                    'first_name' => $firstName,
                                    'father_name' => $fatherName,
                                    'grandfather_name' => $grandfatherName,
                                    'full_name' => $fullName,
                                    'year_input' => $yearInput,
                                    'academic_year_id' => $academicYearId,
                                    'faculty_input' => $facultyInput,
                                    'faculty_id' => $facultyId,
                                    'department_input' => $departmentInput,
                                    'department_id' => $departmentId,
                                    'semester_input' => $semesterInput,
                                    'semester_id' => $semesterId,
                                    'shift_input' => $shiftInput,
                                    'shift' => $shift,
                                    'status' => $status,
                                    'message' => $message,
                                ];
                            }

                            if (empty($previewRows)) {
                                $errorMessage = 'No data rows were found in the uploaded file.';
                            } else {
                                $_SESSION['student_import_preview'] = $previewRows;
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
        $previewRows = $_SESSION['student_import_preview'] ?? [];

        if (empty($previewRows)) {
            $_SESSION['flash_error'] = 'Your import session expired. Please upload the file again.';
            unset($_SESSION['student_import_preview']);
            redirect_to('admin/students.php');
        }

        $validRows = array_values(array_filter($previewRows, static fn ($r) => $r['status'] === 'ok'));

        if (empty($validRows)) {
            $_SESSION['flash_error'] = 'There were no valid rows to import.';
            unset($_SESSION['student_import_preview']);
            redirect_to('admin/students.php');
        }

        $imported = 0;
        foreach ($validRows as $row) {
            $conn->begin_transaction();
            try {
                $studentNo = $row['student_no'];
                $username = generate_student_username($conn, $row['first_name'], $studentNo);
                $tempPassword = $studentNo;
                $passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);

                $insertUserStmt = $conn->prepare(
                    'INSERT INTO users (username, password_hash, full_name, role_id, status) VALUES (?, ?, ?, ?, "active")'
                );
                $insertUserStmt->bind_param('sssi', $username, $passwordHash, $row['full_name'], $studentRoleId);
                $insertUserStmt->execute();
                $newUserId = (int) $conn->insert_id;
                $insertUserStmt->close();

                $insertStudentStmt = $conn->prepare(
                    'INSERT INTO students (student_no, first_name, father_name, grandfather_name, user_id, academic_year_id, faculty_id, department_id, semester_id, shift, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "active")'
                );
                $grandfatherParam = $row['grandfather_name'] !== '' ? $row['grandfather_name'] : null;
                $insertStudentStmt->bind_param(
                    'ssssiiiiis',
                    $studentNo,
                    $row['first_name'],
                    $row['father_name'],
                    $grandfatherParam,
                    $newUserId,
                    $row['academic_year_id'],
                    $row['faculty_id'],
                    $row['department_id'],
                    $row['semester_id'],
                    $row['shift']
                );
                $insertStudentStmt->execute();
                $insertStudentStmt->close();

                $conn->commit();
                $imported++;
            } catch (\Throwable $e) {
                $conn->rollback();
            }
        }

        unset($_SESSION['student_import_preview']);
        $skipped = count($previewRows) - $imported;
        $_SESSION['flash_success'] = "Imported {$imported} student(s) successfully."
            . ($skipped > 0 ? " Skipped {$skipped} invalid row(s)." : '')
            . ($imported > 0 ? ' Use "Reset Password" on a student\'s row if you need to issue their credentials again.' : '');

        redirect_to('admin/students.php');
    } elseif ($action === 'cancel') {
        unset($_SESSION['student_import_preview']);
        redirect_to('admin/students_import.php');
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
    <title>Import Students — ADMAS Attendance System</title>
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
                <?= current_role() === 'registration'
                    ? 'Access scope: All faculties — enrollment-focused'
                    : 'Access scope: Full system — all faculties, departments, and students' ?>
            </div>

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--admas-text);">Import Students from Excel</h4>
                    <p class="text-muted mb-0">Bulk-register students from a .xlsx, .xls, or .csv file.</p>
                </div>
                <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/students.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left"></i> Back to Students
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
                <div class="admas-card p-4" style="max-width: 720px;">
                    <h6 class="fw-bold mb-3" style="color: var(--admas-text);">Upload File</h6>

                    <div class="alert alert-light border small mb-3">
                        The file must have column headers: <strong>Student No</strong> (or "REG/NO" — the student's
                        existing admission/ID number, must be unique), <strong>First Names</strong>,
                        <strong>Father's Name</strong>, <strong>Academic Year</strong>,
                        <strong>Faculty</strong>, <strong>Department</strong>, <strong>Semester</strong> (must match
                        an existing semester name within that Faculty), and
                        <strong>Shift</strong> (Morning Shift / Afternoon Shift / Weekend). <strong>Grandfather's
                        Name</strong> is optional. A username and temporary password
                        will be generated automatically from the Student No for each imported student.
                        <br>
                        Column headers may also be written in Somali (e.g. "Magaca Koowaad" for First Name,
                        "ID-ga Ardayga" for Student No, "Kulliyadda" for Faculty, "Waaxda" for Department).
                        <br>
                        <a href="?action=template" class="fw-semibold">
                            <i class="bi bi-download"></i> Download a starter template (.xlsx)
                        </a>
                    </div>

                    <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/students_import.php" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="preview">
                        <div class="mb-3">
                            <label for="importFileInput" class="form-label">Excel or CSV file</label>
                            <input type="file" class="form-control" id="importFileInput" name="import_file" accept=".xlsx,.xls,.csv" required>
                        </div>
                        <button type="submit" class="btn btn-primary" style="background-color: var(--admas-sky); border-color: var(--admas-sky);"
                                <?= (empty($existingFaculties) || empty($existingDepartments) || empty($existingAcademicYears) || empty($existingSemesters)) ? 'disabled' : '' ?>>
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
                                    <th>Student No</th>
                                    <th>First Name</th>
                                    <th>Father's Name</th>
                                    <th>G.Father's Name</th>
                                    <th>Academic Year</th>
                                    <th>Faculty</th>
                                    <th>Department</th>
                                    <th>Semester</th>
                                    <th>Shift</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($previewRows as $r): ?>
                                    <tr>
                                        <td><?= (int) $r['row'] ?></td>
                                        <td><?= htmlspecialchars($r['student_no']) ?></td>
                                        <td><?= htmlspecialchars($r['first_name']) ?></td>
                                        <td><?= htmlspecialchars($r['father_name']) ?></td>
                                        <td><?= htmlspecialchars($r['grandfather_name']) ?></td>
                                        <td><?= htmlspecialchars($r['year_input']) ?></td>
                                        <td><?= htmlspecialchars($r['faculty_input']) ?></td>
                                        <td><?= htmlspecialchars($r['department_input']) ?></td>
                                        <td><?= htmlspecialchars($r['semester_input']) ?></td>
                                        <td><?= htmlspecialchars($r['shift_input']) ?></td>
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
                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/students_import.php">
                            <input type="hidden" name="action" value="confirm">
                            <button type="submit" class="btn btn-primary" style="background-color: var(--admas-sky); border-color: var(--admas-sky);" <?= $validCount === 0 ? 'disabled' : '' ?>>
                                <i class="bi bi-check2-circle"></i> Confirm Import (<?= $validCount ?>)
                            </button>
                        </form>
                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/students_import.php">
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
