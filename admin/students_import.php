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
    $sheet->fromArray(['Full Name', 'Email', 'Academic Year', 'Faculty', 'Department', 'Level', 'Shift'], null, 'A1');
    $sheet->fromArray(['Amina Hassan', 'amina.hassan@admas.edu.so', '2025/2026', 'Engineering & IT', 'Computer Science', 1, 'Morning Shift'], null, 'A2');
    $sheet->getStyle('A1:G1')->getFont()->setBold(true);
    foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $col) {
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

// ---------------------------------------------------------------------
// Handle POST actions: preview, confirm, cancel
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'preview') {
        if (empty($existingFaculties) || empty($existingDepartments) || empty($existingAcademicYears)) {
            $errorMessage = 'At least one Academic Year, Faculty, and Department must exist before importing students.';
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
                        $headerRow = array_map(static fn ($h) => mb_strtolower(trim((string) $h)), array_shift($rows));

                        $nameCol = array_search('full name', $headerRow, true);
                        if ($nameCol === false) {
                            $nameCol = array_search('name', $headerRow, true);
                        }
                        $emailCol = array_search('email', $headerRow, true);
                        $yearCol = array_search('academic year', $headerRow, true);
                        $facultyCol = array_search('faculty', $headerRow, true);
                        $departmentCol = array_search('department', $headerRow, true);
                        $levelCol = array_search('level', $headerRow, true);
                        $shiftCol = array_search('shift', $headerRow, true);

                        if ($nameCol === false || $yearCol === false || $facultyCol === false
                            || $departmentCol === false || $levelCol === false || $shiftCol === false) {
                            $errorMessage = 'The file must have "Full Name", "Academic Year", "Faculty", "Department", "Level" and "Shift" column headers.';
                        } else {
                            $rowNumber = 1;

                            foreach ($rows as $row) {
                                $rowNumber++;
                                $fullName = trim((string) ($row[$nameCol] ?? ''));
                                $emailInput = $emailCol !== false ? trim((string) ($row[$emailCol] ?? '')) : '';
                                $yearInput = trim((string) ($row[$yearCol] ?? ''));
                                $facultyInput = trim((string) ($row[$facultyCol] ?? ''));
                                $departmentInput = trim((string) ($row[$departmentCol] ?? ''));
                                $levelInput = trim((string) ($row[$levelCol] ?? ''));
                                $shiftInput = trim((string) ($row[$shiftCol] ?? ''));

                                if ($fullName === '' && $yearInput === '' && $facultyInput === '' && $departmentInput === '') {
                                    continue;
                                }

                                $status = 'ok';
                                $message = 'Ready to import';
                                $academicYearId = 0;
                                $facultyId = 0;
                                $departmentId = 0;
                                $level = 0;
                                $shift = null;

                                if ($fullName === '') {
                                    $status = 'error';
                                    $message = 'Missing Full Name';
                                } elseif ($yearInput === '') {
                                    $status = 'error';
                                    $message = 'Missing Academic Year';
                                } elseif ($facultyInput === '') {
                                    $status = 'error';
                                    $message = 'Missing Faculty';
                                } elseif ($departmentInput === '') {
                                    $status = 'error';
                                    $message = 'Missing Department';
                                } elseif ($levelInput === '') {
                                    $status = 'error';
                                    $message = 'Missing Level';
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
                                    if (!ctype_digit($levelInput) || (int) $levelInput < 1 || (int) $levelInput > 5) {
                                        $status = 'error';
                                        $message = 'Invalid Level (must be a number 1-5)';
                                    } else {
                                        $level = (int) $levelInput;
                                    }
                                }

                                if ($status === 'ok') {
                                    $shift = normalize_shift_input($shiftInput);
                                    if ($shift === null) {
                                        $status = 'error';
                                        $message = 'Invalid Shift "' . $shiftInput . '" (use Morning Shift, Afternoon Shift, or Weekend)';
                                    }
                                }

                                if ($status === 'ok' && $emailInput !== '' && !filter_var($emailInput, FILTER_VALIDATE_EMAIL)) {
                                    $status = 'error';
                                    $message = 'Invalid email address';
                                }

                                if ($status === 'ok' && $emailInput !== '') {
                                    $emailCheckStmt = $conn->prepare('SELECT id FROM users WHERE email = ?');
                                    $emailCheckStmt->bind_param('s', $emailInput);
                                    $emailCheckStmt->execute();
                                    if ($emailCheckStmt->get_result()->fetch_assoc()) {
                                        $status = 'error';
                                        $message = 'Email already in use by another account';
                                    }
                                    $emailCheckStmt->close();
                                }

                                $previewRows[] = [
                                    'row' => $rowNumber,
                                    'full_name' => $fullName,
                                    'email' => $emailInput,
                                    'year_input' => $yearInput,
                                    'academic_year_id' => $academicYearId,
                                    'faculty_input' => $facultyInput,
                                    'faculty_id' => $facultyId,
                                    'department_input' => $departmentInput,
                                    'department_id' => $departmentId,
                                    'level' => $level,
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
                $studentNo = generate_student_no($conn);
                $username = generate_student_username($conn, $row['full_name']);
                $tempPassword = generate_temp_password();
                $passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);
                $emailParam = $row['email'] !== '' ? $row['email'] : null;

                $insertUserStmt = $conn->prepare(
                    'INSERT INTO users (username, password_hash, full_name, email, role_id, status) VALUES (?, ?, ?, ?, ?, "active")'
                );
                $insertUserStmt->bind_param('ssssi', $username, $passwordHash, $row['full_name'], $emailParam, $studentRoleId);
                $insertUserStmt->execute();
                $newUserId = (int) $conn->insert_id;
                $insertUserStmt->close();

                $insertStudentStmt = $conn->prepare(
                    'INSERT INTO students (student_no, full_name, user_id, academic_year_id, faculty_id, department_id, level, shift, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, "active")'
                );
                $insertStudentStmt->bind_param(
                    'ssiiiiis',
                    $studentNo,
                    $row['full_name'],
                    $newUserId,
                    $row['academic_year_id'],
                    $row['faculty_id'],
                    $row['department_id'],
                    $row['level'],
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
                    <h4 class="fw-bold mb-1" style="color: #0b1f3a;">Import Students from Excel</h4>
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
                    <h6 class="fw-bold mb-3" style="color: #0b1f3a;">Upload File</h6>

                    <div class="alert alert-light border small mb-3">
                        The file must have column headers: <strong>Full Name</strong>, <strong>Academic Year</strong>,
                        <strong>Faculty</strong>, <strong>Department</strong>, <strong>Level</strong> (1-5), and
                        <strong>Shift</strong> (Morning Shift / Afternoon Shift / Weekend). <strong>Email</strong> is
                        optional. A student number, username, and temporary password will be generated automatically
                        for each imported student.
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
                        <button type="submit" class="btn btn-primary" style="background-color: #0ea5e9; border-color: #0ea5e9;"
                                <?= (empty($existingFaculties) || empty($existingDepartments) || empty($existingAcademicYears)) ? 'disabled' : '' ?>>
                            <i class="bi bi-eye"></i> Preview Import
                        </button>
                    </form>
                </div>
            <?php else: ?>
                <div class="admas-card p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                        <h6 class="fw-bold mb-0" style="color: #0b1f3a;">Preview</h6>
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
                                    <th>Full Name</th>
                                    <th>Academic Year</th>
                                    <th>Faculty</th>
                                    <th>Department</th>
                                    <th>Level</th>
                                    <th>Shift</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($previewRows as $r): ?>
                                    <tr>
                                        <td><?= (int) $r['row'] ?></td>
                                        <td><?= htmlspecialchars($r['full_name']) ?></td>
                                        <td><?= htmlspecialchars($r['year_input']) ?></td>
                                        <td><?= htmlspecialchars($r['faculty_input']) ?></td>
                                        <td><?= htmlspecialchars($r['department_input']) ?></td>
                                        <td><?= htmlspecialchars((string) $r['level']) ?></td>
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
                            <button type="submit" class="btn btn-primary" style="background-color: #0ea5e9; border-color: #0ea5e9;" <?= $validCount === 0 ? 'disabled' : '' ?>>
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
