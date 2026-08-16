<?php
/**
 * Bulk-import Departments from an Excel (.xlsx/.xls) or CSV file.
 * Flow: upload -> preview (per-row validation) -> confirm.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';
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

// ---------------------------------------------------------------------
// Downloadable template (must run before any HTML output)
// ---------------------------------------------------------------------
if (($_GET['action'] ?? '') === 'template') {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray(['Code', 'Department Name', 'Faculty'], null, 'A1');
    $sheet->fromArray(['CS', 'Computer Science', 'Engineering & IT'], null, 'A2');
    $sheet->getStyle('A1:C1')->getFont()->setBold(true);
    foreach (['A', 'B', 'C'] as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="department_import_template.xlsx"');
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
    unset($_SESSION['dept_import_preview']);
}

$step = 'upload';
$previewRows = [];

$existingFaculties = $conn->query('SELECT id, name FROM faculties ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$facultyByLowerName = [];
foreach ($existingFaculties as $fac) {
    $facultyByLowerName[mb_strtolower(trim((string) $fac['name']))] = (int) $fac['id'];
}

// ---------------------------------------------------------------------
// Handle POST actions: preview, confirm, cancel
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'preview') {
        if (empty($existingFaculties)) {
            $errorMessage = 'No faculties exist yet — create at least one faculty before importing departments.';
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

                        $codeCol = array_search('code', $headerRow, true);
                        $nameCol = array_search('department name', $headerRow, true);
                        if ($nameCol === false) {
                            $nameCol = array_search('name', $headerRow, true);
                        }
                        $facultyCol = array_search('faculty', $headerRow, true);
                        if ($facultyCol === false) {
                            $facultyCol = array_search('faculty name', $headerRow, true);
                        }

                        if ($codeCol === false || $nameCol === false || $facultyCol === false) {
                            $errorMessage = 'The file must have "Code", "Department Name" and "Faculty" column headers.';
                        } else {
                            $seenInFile = [];
                            $rowNumber = 1;

                            foreach ($rows as $row) {
                                $rowNumber++;
                                $code = trim((string) ($row[$codeCol] ?? ''));
                                $name = trim((string) ($row[$nameCol] ?? ''));
                                $facultyInput = trim((string) ($row[$facultyCol] ?? ''));

                                if ($code === '' && $name === '' && $facultyInput === '') {
                                    continue;
                                }

                                $status = 'ok';
                                $message = 'Ready to import';
                                $facultyId = 0;
                                $codeUpper = strtoupper($code);

                                if ($code === '') {
                                    $status = 'error';
                                    $message = 'Missing Code';
                                } elseif ($name === '') {
                                    $status = 'error';
                                    $message = 'Missing Department Name';
                                } elseif ($facultyInput === '') {
                                    $status = 'error';
                                    $message = 'Missing Faculty';
                                } else {
                                    $facultyId = $facultyByLowerName[mb_strtolower($facultyInput)] ?? 0;
                                    if ($facultyId === 0) {
                                        $status = 'error';
                                        $message = 'Unknown faculty "' . $facultyInput . '"';
                                    }
                                }

                                if ($status === 'ok') {
                                    $dedupeKey = $facultyId . '|' . $codeUpper;
                                    if (isset($seenInFile[$dedupeKey])) {
                                        $status = 'error';
                                        $message = 'Duplicate code within this file for the same faculty';
                                    } else {
                                        $checkStmt = $conn->prepare('SELECT id FROM departments WHERE faculty_id = ? AND UPPER(code) = ?');
                                        $checkStmt->bind_param('is', $facultyId, $codeUpper);
                                        $checkStmt->execute();
                                        if ($checkStmt->get_result()->fetch_assoc()) {
                                            $status = 'error';
                                            $message = 'Code already exists in this faculty';
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
                                    'faculty_input' => $facultyInput,
                                    'faculty_id' => $facultyId,
                                    'status' => $status,
                                    'message' => $message,
                                ];
                            }

                            if (empty($previewRows)) {
                                $errorMessage = 'No data rows were found in the uploaded file.';
                            } else {
                                $_SESSION['dept_import_preview'] = $previewRows;
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
        $previewRows = $_SESSION['dept_import_preview'] ?? [];

        if (empty($previewRows)) {
            $_SESSION['flash_error'] = 'Your import session expired. Please upload the file again.';
        } else {
            $validRows = array_values(array_filter($previewRows, static fn ($r) => $r['status'] === 'ok'));

            if (empty($validRows)) {
                $_SESSION['flash_error'] = 'There were no valid rows to import.';
            } else {
                $conn->begin_transaction();
                try {
                    $insertStmt = $conn->prepare('INSERT INTO departments (code, name, faculty_id) VALUES (?, ?, ?)');
                    $imported = 0;
                    foreach ($validRows as $row) {
                        $insertStmt->bind_param('ssi', $row['code'], $row['name'], $row['faculty_id']);
                        $insertStmt->execute();
                        $imported++;
                    }
                    $insertStmt->close();
                    $conn->commit();

                    $skipped = count($previewRows) - $imported;
                    $_SESSION['flash_success'] = "Imported {$imported} department(s) successfully."
                        . ($skipped > 0 ? " Skipped {$skipped} invalid row(s)." : '');
                } catch (\Throwable $e) {
                    $conn->rollback();
                    $_SESSION['flash_error'] = 'Import failed while saving to the database. No rows were added.';
                }
            }
        }

        unset($_SESSION['dept_import_preview']);
        redirect_to('admin/departments.php');
    } elseif ($action === 'cancel') {
        unset($_SESSION['dept_import_preview']);
        redirect_to('admin/departments_import.php');
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
    <title>Import Departments — ADMAS Attendance System</title>
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
                    <h4 class="fw-bold mb-1" style="color: var(--admas-text);">Import Departments from Excel</h4>
                    <p class="text-muted mb-0">Bulk-register departments from a .xlsx, .xls, or .csv file.</p>
                </div>
                <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/departments.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left"></i> Back to Departments
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
                        The file must have column headers: <strong>Code</strong>, <strong>Department Name</strong>,
                        and <strong>Faculty</strong> (the Faculty value must match an existing faculty's name).
                        <br>
                        <a href="?action=template" class="fw-semibold">
                            <i class="bi bi-download"></i> Download a starter template (.xlsx)
                        </a>
                    </div>

                    <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/departments_import.php" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="preview">
                        <div class="mb-3">
                            <label for="importFileInput" class="form-label">Excel or CSV file</label>
                            <input type="file" class="form-control" id="importFileInput" name="import_file" accept=".xlsx,.xls,.csv" required>
                        </div>
                        <button type="submit" class="btn btn-primary" style="background-color: var(--admas-sky); border-color: var(--admas-sky);" <?= empty($existingFaculties) ? 'disabled' : '' ?>>
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
                                    <th>Department Name</th>
                                    <th>Faculty</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($previewRows as $r): ?>
                                    <tr>
                                        <td><?= (int) $r['row'] ?></td>
                                        <td><?= htmlspecialchars($r['code']) ?></td>
                                        <td><?= htmlspecialchars($r['name']) ?></td>
                                        <td><?= htmlspecialchars($r['faculty_input']) ?></td>
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
                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/departments_import.php">
                            <input type="hidden" name="action" value="confirm">
                            <button type="submit" class="btn btn-primary" style="background-color: var(--admas-sky); border-color: var(--admas-sky);" <?= $validCount === 0 ? 'disabled' : '' ?>>
                                <i class="bi bi-check2-circle"></i> Confirm Import (<?= $validCount ?>)
                            </button>
                        </form>
                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/departments_import.php">
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
