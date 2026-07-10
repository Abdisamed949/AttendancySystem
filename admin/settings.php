<?php
/**
 * Settings (System Administrator only) — university info, Academic Year
 * management, default Faculty/Department scope, and the minimum attendance
 * threshold the Notifications module already reads.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';

require_role(['system_admin']);

$conn = db();
$currentUser = current_user();

// ---------------------------------------------------------------------
// Settings (drives the sky-blue top strip + this page's own form defaults)
// ---------------------------------------------------------------------
$settings = [];
$settingsResult = $conn->query('SELECT `key`, `value` FROM settings');
if ($settingsResult) {
    while ($row = $settingsResult->fetch_assoc()) {
        $settings[$row['key']] = $row['value'];
    }
}

/**
 * Upsert a single settings row — the `key` column is the primary key, so
 * this works whether or not the row already exists.
 */
function save_setting(mysqli $conn, string $key, string $value): void
{
    $stmt = $conn->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
    $stmt->bind_param('ss', $key, $value);
    $stmt->execute();
    $stmt->close();
}

// ---------------------------------------------------------------------
// Flash messages (post-redirect-get)
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

// ---------------------------------------------------------------------
// Form state (defaults from the current settings; overridden below only
// on a failed submit of that specific form, so the others stay untouched)
// ---------------------------------------------------------------------
$universityFormValues = [
    'university_name' => $settings['university_name'] ?? '',
    'campus' => $settings['campus'] ?? '',
    'contact_email' => $settings['contact_email'] ?? '',
    'contact_phone' => $settings['contact_phone'] ?? '',
];
$addYearFormValues = ['label' => ''];
$scopeFormValues = [
    'default_faculty_id' => (int) ($settings['default_faculty_id'] ?? 0),
    'default_department_id' => (int) ($settings['default_department_id'] ?? 0),
    'min_attendance_pct' => (string) ($settings['min_attendance_pct'] ?? '75'),
];

// ---------------------------------------------------------------------
// Handle POST actions
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_university_info') {
        $universityName = trim((string) ($_POST['university_name'] ?? ''));
        $campus = trim((string) ($_POST['campus'] ?? ''));
        $contactEmail = trim((string) ($_POST['contact_email'] ?? ''));
        $contactPhone = trim((string) ($_POST['contact_phone'] ?? ''));

        $universityFormValues = [
            'university_name' => $universityName,
            'campus' => $campus,
            'contact_email' => $contactEmail,
            'contact_phone' => $contactPhone,
        ];

        $validationError = '';
        if ($universityName === '') {
            $validationError = 'University name is required.';
        } elseif ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            $validationError = 'Please enter a valid contact email address.';
        }

        if ($validationError === '') {
            save_setting($conn, 'university_name', $universityName);
            save_setting($conn, 'campus', $campus);
            save_setting($conn, 'contact_email', $contactEmail);
            save_setting($conn, 'contact_phone', $contactPhone);

            $_SESSION['flash_success'] = 'University information updated successfully.';
            redirect_to('admin/settings.php');
        }

        $errorMessage = $validationError;
    } elseif ($action === 'add_academic_year') {
        $label = trim((string) ($_POST['label'] ?? ''));
        $addYearFormValues = ['label' => $label];

        $validationError = '';
        if ($label === '') {
            $validationError = 'Academic Year label is required.';
        } elseif (mb_strlen($label) > 20) {
            $validationError = 'Academic Year label must be 20 characters or fewer.';
        }

        if ($validationError === '') {
            $dupStmt = $conn->prepare('SELECT id FROM academic_years WHERE label = ?');
            $dupStmt->bind_param('s', $label);
            $dupStmt->execute();
            if ($dupStmt->get_result()->fetch_assoc()) {
                $validationError = 'This Academic Year already exists.';
            }
            $dupStmt->close();
        }

        if ($validationError === '') {
            $insertStmt = $conn->prepare('INSERT INTO academic_years (label, is_current) VALUES (?, 0)');
            $insertStmt->bind_param('s', $label);
            $insertStmt->execute();
            $insertStmt->close();

            $_SESSION['flash_success'] = 'Academic Year "' . $label . '" added successfully.';
            redirect_to('admin/settings.php');
        }

        $errorMessage = $validationError;
    } elseif ($action === 'set_current_year') {
        $yearId = (int) ($_POST['academic_year_id'] ?? 0);

        $yearStmt = $conn->prepare('SELECT label FROM academic_years WHERE id = ?');
        $yearStmt->bind_param('i', $yearId);
        $yearStmt->execute();
        $yearRow = $yearStmt->get_result()->fetch_assoc();
        $yearStmt->close();

        if (!$yearRow) {
            $errorMessage = 'Selected Academic Year does not exist.';
        } else {
            $conn->begin_transaction();
            try {
                $conn->query('UPDATE academic_years SET is_current = 0');

                $updateStmt = $conn->prepare('UPDATE academic_years SET is_current = 1 WHERE id = ?');
                $updateStmt->bind_param('i', $yearId);
                $updateStmt->execute();
                $updateStmt->close();

                save_setting($conn, 'current_academic_year_id', (string) $yearId);

                $conn->commit();
                $_SESSION['flash_success'] = '"' . $yearRow['label'] . '" is now the current Academic Year.';
            } catch (Throwable $e) {
                $conn->rollback();
                $_SESSION['flash_error'] = 'Could not update the current Academic Year. Please try again.';
            }

            redirect_to('admin/settings.php');
        }
    } elseif ($action === 'save_scope_and_threshold') {
        $defaultFacultyIdInput = (int) ($_POST['default_faculty_id'] ?? 0);
        $defaultDepartmentIdInput = (int) ($_POST['default_department_id'] ?? 0);
        $minAttendancePctInput = trim((string) ($_POST['min_attendance_pct'] ?? ''));

        $scopeFormValues = [
            'default_faculty_id' => $defaultFacultyIdInput,
            'default_department_id' => $defaultDepartmentIdInput,
            'min_attendance_pct' => $minAttendancePctInput,
        ];

        $validationError = '';
        if (!is_numeric($minAttendancePctInput) || (float) $minAttendancePctInput < 0 || (float) $minAttendancePctInput > 100) {
            $validationError = 'Minimum Attendance % must be a number between 0 and 100.';
        }

        if ($validationError === '' && $defaultFacultyIdInput > 0) {
            $facCheckStmt = $conn->prepare('SELECT id FROM faculties WHERE id = ?');
            $facCheckStmt->bind_param('i', $defaultFacultyIdInput);
            $facCheckStmt->execute();
            if (!$facCheckStmt->get_result()->fetch_assoc()) {
                $validationError = 'Selected default Faculty does not exist.';
            }
            $facCheckStmt->close();
        }

        if ($validationError === '' && $defaultDepartmentIdInput > 0) {
            $deptCheckStmt = $conn->prepare('SELECT faculty_id FROM departments WHERE id = ?');
            $deptCheckStmt->bind_param('i', $defaultDepartmentIdInput);
            $deptCheckStmt->execute();
            $deptRow = $deptCheckStmt->get_result()->fetch_assoc();
            $deptCheckStmt->close();

            if (!$deptRow) {
                $validationError = 'Selected default Department does not exist.';
            } elseif ($defaultFacultyIdInput > 0 && (int) $deptRow['faculty_id'] !== $defaultFacultyIdInput) {
                $validationError = 'Selected default Department does not belong to the selected default Faculty.';
            }
        }

        if ($validationError === '') {
            $minPctFormatted = (string) round((float) $minAttendancePctInput, 2);
            save_setting($conn, 'default_faculty_id', (string) $defaultFacultyIdInput);
            save_setting($conn, 'default_department_id', (string) $defaultDepartmentIdInput);
            save_setting($conn, 'min_attendance_pct', $minPctFormatted);

            $_SESSION['flash_success'] = 'Default scope and attendance threshold updated successfully.';
            redirect_to('admin/settings.php');
        }

        $errorMessage = $validationError;
    }
}

// ---------------------------------------------------------------------
// Data for rendering
// ---------------------------------------------------------------------
$academicYears = $conn->query('SELECT id, label, is_current FROM academic_years ORDER BY label DESC')->fetch_all(MYSQLI_ASSOC);

$faculties = $conn->query('SELECT id, name FROM faculties ORDER BY name')->fetch_all(MYSQLI_ASSOC);

$departments = $conn->query('SELECT id, name, faculty_id FROM departments ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$departmentsByFacultyId = [];
foreach ($departments as $d) {
    $departmentsByFacultyId[(int) $d['faculty_id']][] = ['id' => (int) $d['id'], 'name' => $d['name']];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings — ADMAS Attendance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(BASE_URL) ?>/assets/css/app.css" rel="stylesheet">
    <style>
        .btn-icon {
            border: none;
            background: transparent;
            color: #64748b;
            padding: 0.25rem 0.4rem;
            border-radius: 6px;
        }

        .btn-icon:hover {
            background: var(--admas-bg);
            color: #0b1f3a;
        }
    </style>
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
                    <h4 class="fw-bold mb-1" style="color: #0b1f3a;">Settings</h4>
                    <p class="text-muted mb-0">University information, Academic Year, default scope, and the attendance threshold.</p>
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

            <!-- University Information -->
            <div class="admas-card p-4 mb-3">
                <h6 class="fw-bold mb-3" style="color: #0b1f3a;">University Information</h6>
                <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/settings.php" class="row g-3">
                    <input type="hidden" name="action" value="save_university_info">

                    <div class="col-md-6">
                        <label for="universityNameInput" class="form-label">University Name</label>
                        <input type="text" class="form-control" id="universityNameInput" name="university_name" maxlength="150" required
                               value="<?= htmlspecialchars($universityFormValues['university_name']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label for="campusInput" class="form-label">Campus</label>
                        <input type="text" class="form-control" id="campusInput" name="campus" maxlength="150"
                               value="<?= htmlspecialchars($universityFormValues['campus']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label for="contactEmailInput" class="form-label">Contact Email</label>
                        <input type="email" class="form-control" id="contactEmailInput" name="contact_email" maxlength="150"
                               value="<?= htmlspecialchars($universityFormValues['contact_email']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label for="contactPhoneInput" class="form-label">Contact Phone</label>
                        <input type="text" class="form-control" id="contactPhoneInput" name="contact_phone" maxlength="60"
                               value="<?= htmlspecialchars($universityFormValues['contact_phone']) ?>">
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-primary" style="background-color: #0ea5e9; border-color: #0ea5e9;">
                            <i class="bi bi-save"></i> Save University Information
                        </button>
                    </div>
                </form>
            </div>

            <!-- Academic Year Settings -->
            <div class="admas-card p-4">
                <h6 class="fw-bold mb-3" style="color: #0b1f3a;">Academic Year Settings</h6>

                <div class="row g-4">
                    <div class="col-lg-6">
                        <h6 class="small text-uppercase text-muted mb-2">Academic Years</h6>
                        <div class="table-responsive mb-3">
                            <table class="table admas-table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Label</th>
                                        <th>Current</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($academicYears)): ?>
                                        <tr>
                                            <td colspan="3" class="text-center text-muted py-3">No academic years exist yet.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($academicYears as $ay): ?>
                                            <tr>
                                                <td class="fw-semibold" style="color: #0b1f3a;"><?= htmlspecialchars($ay['label']) ?></td>
                                                <td>
                                                    <?php if ((int) $ay['is_current'] === 1): ?>
                                                        <span class="badge-pill badge-active">Current</span>
                                                    <?php else: ?>
                                                        <span class="badge-pill badge-inactive">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ((int) $ay['is_current'] !== 1): ?>
                                                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/settings.php" style="display:inline;">
                                                            <input type="hidden" name="action" value="set_current_year">
                                                            <input type="hidden" name="academic_year_id" value="<?= (int) $ay['id'] ?>">
                                                            <button type="submit" class="btn btn-outline-secondary btn-sm">Set as Current</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <h6 class="small text-uppercase text-muted mb-2">Add New Academic Year</h6>
                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/settings.php" class="d-flex gap-2">
                            <input type="hidden" name="action" value="add_academic_year">
                            <input type="text" class="form-control" name="label" maxlength="20" placeholder="e.g. 2026/2027" required
                                   value="<?= htmlspecialchars($addYearFormValues['label']) ?>">
                            <button type="submit" class="btn btn-primary text-nowrap" style="background-color: #0ea5e9; border-color: #0ea5e9;">
                                <i class="bi bi-plus-lg"></i> Add
                            </button>
                        </form>
                    </div>

                    <div class="col-lg-6">
                        <h6 class="small text-uppercase text-muted mb-2">Default Scope &amp; Attendance Threshold</h6>
                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/settings.php">
                            <input type="hidden" name="action" value="save_scope_and_threshold">

                            <div class="mb-3">
                                <label for="defaultFacultySelect" class="form-label">Default Faculty</label>
                                <select class="form-select" id="defaultFacultySelect" name="default_faculty_id" onchange="updateDefaultDepartmentOptions(this.value, 0)">
                                    <option value="0">None</option>
                                    <?php foreach ($faculties as $f): ?>
                                        <option value="<?= (int) $f['id'] ?>" <?= $scopeFormValues['default_faculty_id'] === (int) $f['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($f['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="defaultDepartmentSelect" class="form-label">Default Department</label>
                                <select class="form-select" id="defaultDepartmentSelect" name="default_department_id">
                                    <option value="0">None</option>
                                </select>
                                <div class="form-text">Used to pre-select filters (e.g. on Reports) when no other filter has been chosen yet.</div>
                            </div>

                            <div class="mb-3">
                                <label for="minAttendancePctInput" class="form-label">Minimum Attendance %</label>
                                <input type="number" class="form-control" id="minAttendancePctInput" name="min_attendance_pct"
                                       min="0" max="100" step="0.01" required
                                       value="<?= htmlspecialchars($scopeFormValues['min_attendance_pct']) ?>">
                                <div class="form-text">Students below this percentage are surfaced in Notifications / Alerts.</div>
                            </div>

                            <button type="submit" class="btn btn-primary w-100" style="background-color: #0ea5e9; border-color: #0ea5e9;">
                                <i class="bi bi-save"></i> Save Default Scope &amp; Threshold
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const departmentsByFacultyId = <?= json_encode($departmentsByFacultyId, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        function updateDefaultDepartmentOptions(facultyId, selectedDepartmentId) {
            const select = document.getElementById('defaultDepartmentSelect');
            const list = departmentsByFacultyId[facultyId] || [];

            select.innerHTML = '';
            const noneOption = document.createElement('option');
            noneOption.value = '0';
            noneOption.textContent = 'None';
            select.appendChild(noneOption);

            list.forEach((dept) => {
                const opt = document.createElement('option');
                opt.value = String(dept.id);
                opt.textContent = dept.name;
                select.appendChild(opt);
            });

            select.value = String(selectedDepartmentId || 0);
            if (select.value !== String(selectedDepartmentId || 0)) {
                select.value = '0';
            }
        }

        window.addEventListener('DOMContentLoaded', () => {
            const facultySelect = document.getElementById('defaultFacultySelect');
            updateDefaultDepartmentOptions(facultySelect.value, <?= (int) $scopeFormValues['default_department_id'] ?>);
        });
    </script>
</body>
</html>
