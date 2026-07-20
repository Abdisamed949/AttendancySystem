<?php
/**
 * Bulk-import Lecturers from an Excel (.xlsx/.xls) or CSV file.
 * Flow: upload -> preview (per-row validation) -> confirm -> results
 * (generated usernames/passwords are shown once, so this step renders
 * inline instead of redirecting away).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';
require_once __DIR__ . '/../includes/lecturer_accounts.php';
require_once __DIR__ . '/../vendor/autoload.php';

require_role(['system_admin']);

use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

$conn = db();

// ---------------------------------------------------------------------
// Downloadable template (must run before any HTML output)
// ---------------------------------------------------------------------
if (($_GET['action'] ?? '') === 'template') {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray(['Staff No', 'Full Name', 'Email', 'Department'], null, 'A1');
    $sheet->fromArray(['STF-001', 'Amina Hassan', 'amina.hassan@admas.edu.so', 'Computer Science'], null, 'A2');
    $sheet->getStyle('A1:D1')->getFont()->setBold(true);
    foreach (['A', 'B', 'C', 'D'] as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="lecturer_import_template.xlsx"');
    header('Cache-Control: max-age=0');

    (new XlsxWriter($spreadsheet))->save('php://output');
    exit;
}

$lecturerRoleId = 0;
$roleResult = $conn->query("SELECT id FROM roles WHERE name = 'lecturer'");
if ($roleResult && ($roleRow = $roleResult->fetch_assoc())) {
    $lecturerRoleId = (int) $roleRow['id'];
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
    unset($_SESSION['lecturer_import_preview']);
}

$step = 'upload';
$previewRows = [];
$resultRows = [];

$existingDepartments = $conn->query('SELECT id, name FROM departments ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$departmentByLowerName = [];
foreach ($existingDepartments as $dept) {
    $departmentByLowerName[mb_strtolower(trim((string) $dept['name']))] = (int) $dept['id'];
}

// ---------------------------------------------------------------------
// Handle POST actions: preview, confirm, cancel
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'preview') {
        if (empty($existingDepartments)) {
            $errorMessage = 'No departments exist yet — create at least one department before importing lecturers.';
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
                        $headerRow = array_map(static fn ($h) => str_replace('-', '', mb_strtolower(trim((string) $h))), array_shift($rows));

                        // Each field accepts English and Somali header synonyms, so
                        // staff can write the sheet in whichever language is natural
                        // for them without needing to rename columns first. Hyphens
                        // are stripped from both sides above, so "ID-ga"/"id ga"/
                        // "idga" all match the same candidate.
                        $staffCol = find_import_column($headerRow, ['staff no', 'staffno', 'id', 'idga macalinka', 'lambarka macalinka', 'lambarka shaqaalaha']);
                        $nameCol = find_import_column($headerRow, ['full name', 'name', 'magaca buuxa', 'magaca']);
                        $emailCol = find_import_column($headerRow, ['email', 'emailka', 'iimaylka']);
                        $departmentCol = find_import_column($headerRow, ['department', 'department name', 'waaxda', 'waax']);

                        if ($staffCol === false || $nameCol === false || $departmentCol === false) {
                            $errorMessage = 'The file must have "Staff No" (or "ID-ga Macalinka"), "Full Name" (or "Magaca Buuxa") and "Department" (or "Waaxda") column headers.';
                        } else {
                            $seenStaffNosInFile = [];
                            $seenEmailsInFile = [];
                            $rowNumber = 1;

                            foreach ($rows as $row) {
                                $rowNumber++;
                                $staffNo = strtoupper(trim((string) ($row[$staffCol] ?? '')));
                                $fullName = trim((string) ($row[$nameCol] ?? ''));
                                $emailInput = $emailCol !== false ? trim((string) ($row[$emailCol] ?? '')) : '';
                                $departmentInput = trim((string) ($row[$departmentCol] ?? ''));

                                if ($staffNo === '' && $fullName === '' && $departmentInput === '') {
                                    continue;
                                }

                                $status = 'ok';
                                $message = 'Ready to import';
                                $departmentId = 0;

                                if ($staffNo === '') {
                                    $status = 'error';
                                    $message = 'Missing Staff No';
                                } elseif ($fullName === '') {
                                    $status = 'error';
                                    $message = 'Missing Full Name';
                                } elseif ($departmentInput === '') {
                                    $status = 'error';
                                    $message = 'Missing Department';
                                } else {
                                    $departmentId = $departmentByLowerName[mb_strtolower($departmentInput)] ?? 0;
                                    if ($departmentId === 0) {
                                        $status = 'error';
                                        $message = 'Unknown department "' . $departmentInput . '"';
                                    }
                                }

                                if ($status === 'ok' && $emailInput !== '' && !filter_var($emailInput, FILTER_VALIDATE_EMAIL)) {
                                    $status = 'error';
                                    $message = 'Invalid email address';
                                }

                                if ($status === 'ok' && $emailInput !== '') {
                                    $emailKey = mb_strtolower($emailInput);
                                    if (isset($seenEmailsInFile[$emailKey])) {
                                        $status = 'error';
                                        $message = 'Duplicate email within this file';
                                    } else {
                                        $emailCheckStmt = $conn->prepare('SELECT id FROM users WHERE email = ?');
                                        $emailCheckStmt->bind_param('s', $emailInput);
                                        $emailCheckStmt->execute();
                                        if ($emailCheckStmt->get_result()->fetch_assoc()) {
                                            $status = 'error';
                                            $message = 'Email already in use by another account';
                                        }
                                        $emailCheckStmt->close();
                                    }
                                }

                                if ($status === 'ok' && $emailInput !== '') {
                                    $seenEmailsInFile[mb_strtolower($emailInput)] = true;
                                }

                                if ($status === 'ok') {
                                    if (isset($seenStaffNosInFile[$staffNo])) {
                                        $status = 'error';
                                        $message = 'Duplicate Staff No within this file';
                                    } else {
                                        $checkStmt = $conn->prepare('SELECT id FROM lecturers WHERE UPPER(staff_no) = ?');
                                        $checkStmt->bind_param('s', $staffNo);
                                        $checkStmt->execute();
                                        if ($checkStmt->get_result()->fetch_assoc()) {
                                            $status = 'error';
                                            $message = 'Staff No already exists';
                                        }
                                        $checkStmt->close();
                                    }

                                    if ($status === 'ok') {
                                        $seenStaffNosInFile[$staffNo] = true;
                                    }
                                }

                                $previewRows[] = [
                                    'row' => $rowNumber,
                                    'staff_no' => $staffNo,
                                    'full_name' => $fullName,
                                    'email' => $emailInput,
                                    'department_input' => $departmentInput,
                                    'department_id' => $departmentId,
                                    'status' => $status,
                                    'message' => $message,
                                ];
                            }

                            if (empty($previewRows)) {
                                $errorMessage = 'No data rows were found in the uploaded file.';
                            } else {
                                $_SESSION['lecturer_import_preview'] = $previewRows;
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
        $previewRows = $_SESSION['lecturer_import_preview'] ?? [];

        if (empty($previewRows)) {
            $_SESSION['flash_error'] = 'Your import session expired. Please upload the file again.';
            unset($_SESSION['lecturer_import_preview']);
            redirect_to('admin/lecturers.php');
        }

        $validRows = array_values(array_filter($previewRows, static fn ($r) => $r['status'] === 'ok'));

        if (empty($validRows)) {
            $_SESSION['flash_error'] = 'There were no valid rows to import.';
            unset($_SESSION['lecturer_import_preview']);
            redirect_to('admin/lecturers.php');
        }

        $imported = 0;
        $failed = 0;
        foreach ($validRows as $row) {
            $conn->begin_transaction();
            try {
                $staffNo = $row['staff_no'];
                $username = generate_lecturer_username($conn, $row['full_name'], $staffNo);
                $tempPassword = $staffNo;
                $passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);
                $emailParam = $row['email'] !== '' ? $row['email'] : null;

                $insertUserStmt = $conn->prepare(
                    'INSERT INTO users (username, password_hash, full_name, email, role_id, status) VALUES (?, ?, ?, ?, ?, "active")'
                );
                $insertUserStmt->bind_param('ssssi', $username, $passwordHash, $row['full_name'], $emailParam, $lecturerRoleId);
                $insertUserStmt->execute();
                $newUserId = (int) $conn->insert_id;
                $insertUserStmt->close();

                $insertLecturerStmt = $conn->prepare(
                    'INSERT INTO lecturers (staff_no, full_name, user_id, department_id, status) VALUES (?, ?, ?, ?, "active")'
                );
                $insertLecturerStmt->bind_param('ssii', $staffNo, $row['full_name'], $newUserId, $row['department_id']);
                $insertLecturerStmt->execute();
                $insertLecturerStmt->close();

                $conn->commit();
                $imported++;

                $resultRows[] = [
                    'staff_no' => $staffNo,
                    'full_name' => $row['full_name'],
                    'username' => $username,
                    'password' => $tempPassword,
                ];
            } catch (\Throwable $e) {
                $conn->rollback();
                $failed++;
            }
        }

        unset($_SESSION['lecturer_import_preview']);
        $skipped = count($previewRows) - count($validRows);
        $step = 'results';
        $successMessage = "Imported {$imported} lecturer(s) successfully."
            . ($skipped > 0 ? " Skipped {$skipped} invalid row(s)." : '')
            . ($failed > 0 ? " {$failed} row(s) failed unexpectedly and were not imported." : '');
    } elseif ($action === 'cancel') {
        unset($_SESSION['lecturer_import_preview']);
        redirect_to('admin/lecturers_import.php');
    }
}

if ($step === 'preview') {
    $previewRows = $_SESSION['lecturer_import_preview'] ?? $previewRows;
}

$validCount = count(array_filter($previewRows, static fn ($r) => $r['status'] === 'ok'));
$invalidCount = count($previewRows) - $validCount;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Lecturers — ADMAS Attendance System</title>
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
                Access scope: Full system — all faculties, departments, and lecturers
            </div>

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--admas-text);">Import Lecturers from Excel</h4>
                    <p class="text-muted mb-0">Bulk-register lecturers from a .xlsx, .xls, or .csv file.</p>
                </div>
                <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/lecturers.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left"></i> Back to Lecturers
                </a>
            </div>

            <?php if ($successMessage !== '' && $step !== 'results'): ?>
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
                        The file must have column headers: <strong>Staff No</strong> (the lecturer's existing
                        staff/employee number — must be unique), <strong>Full Name</strong>, and
                        <strong>Department</strong> (the Department value must match an existing department's
                        name). <strong>Email</strong> is optional. A username and temporary password will be
                        generated automatically from the Staff No for each imported lecturer.
                        <br>
                        Column headers may also be written in Somali (e.g. "Magaca Buuxa" for Full Name, "ID-ga
                        Macalinka" for Staff No, "Waaxda" for Department).
                        <br>
                        <a href="?action=template" class="fw-semibold">
                            <i class="bi bi-download"></i> Download a starter template (.xlsx)
                        </a>
                    </div>

                    <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/lecturers_import.php" enctype="multipart/form-data">
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
            <?php elseif ($step === 'preview'): ?>
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
                                    <th>Staff No</th>
                                    <th>Full Name</th>
                                    <th>Email</th>
                                    <th>Department</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($previewRows as $r): ?>
                                    <tr>
                                        <td><?= (int) $r['row'] ?></td>
                                        <td><?= htmlspecialchars($r['staff_no']) ?></td>
                                        <td><?= htmlspecialchars($r['full_name']) ?></td>
                                        <td><?= $r['email'] !== '' ? htmlspecialchars($r['email']) : '<span class="text-muted fst-italic">None</span>' ?></td>
                                        <td><?= htmlspecialchars($r['department_input']) ?></td>
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
                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/lecturers_import.php">
                            <input type="hidden" name="action" value="confirm">
                            <button type="submit" class="btn btn-primary" style="background-color: var(--admas-sky); border-color: var(--admas-sky);" <?= $validCount === 0 ? 'disabled' : '' ?>>
                                <i class="bi bi-check2-circle"></i> Confirm Import (<?= $validCount ?>)
                            </button>
                        </form>
                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/lecturers_import.php">
                            <input type="hidden" name="action" value="cancel">
                            <button type="submit" class="btn btn-outline-secondary">Upload a Different File</button>
                        </form>
                    </div>
                </div>
            <?php elseif ($step === 'results'): ?>
                <div class="admas-card p-4">
                    <div class="alert alert-success" role="alert">
                        <?= htmlspecialchars($successMessage) ?>
                    </div>

                    <?php if (!empty($resultRows)): ?>
                        <div class="alert alert-warning small">
                            <i class="bi bi-exclamation-triangle"></i>
                            These temporary passwords are shown only once. Copy them now and share securely with
                            each lecturer — they will not be shown again.
                        </div>

                        <div class="table-responsive mb-3">
                            <table class="table admas-table align-middle">
                                <thead>
                                    <tr>
                                        <th>Staff No</th>
                                        <th>Full Name</th>
                                        <th>Username</th>
                                        <th>Temporary Password</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($resultRows as $r): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($r['staff_no']) ?></td>
                                            <td><?= htmlspecialchars($r['full_name']) ?></td>
                                            <td><code><?= htmlspecialchars($r['username']) ?></code></td>
                                            <td><code><?= htmlspecialchars($r['password']) ?></code></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/lecturers.php" class="btn btn-primary" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                        <i class="bi bi-check-lg"></i> Done — Back to Lecturers
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
