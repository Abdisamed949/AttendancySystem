<?php
/**
 * Department Management — University Rector (all faculties, view-only/
 * supervisory), Head of Academic Affairs (all faculties, full CRUD — the
 * same create/edit/delete power University Rector had here before that
 * role was converted to view-only), and Dean (own faculty only, full CRUD,
 * per CLAUDE.md §4 "Full CRUD on Departments ... within their faculty").
 * Dean's faculty_id is always read from $_SESSION, never trusted from
 * request input (same pattern as attendance.php/reports.php).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';
require_once __DIR__ . '/../includes/audit_helpers.php';

require_role(['university_rector', 'head_academic', 'dean']);

$conn = db();
$currentUser = current_user();
$role = current_role();
// Dean converted from full CRUD to a faculty-scoped Viewer, per explicit
// request — the existing $role === 'dean' scoping below still narrows the
// list to their own faculty only.
$isReadOnly = in_array($role, ['university_rector', 'dean'], true);

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
// Add / Edit side-panel form state
// ---------------------------------------------------------------------
$formMode = 'create';
$formValues = ['id' => 0, 'code' => '', 'name' => '', 'faculty_id' => $deanFacultyId];

/**
 * Shared by both the single-row "Delete" button and the bulk "Delete
 * Selected" action so the two can never drift on blocker/validation logic.
 * Bulk delete is university_rector-only (see the bulk_delete action handler
 * below), but this function itself still honors the Dean's own-faculty
 * scoping so the single-row Delete button keeps working exactly as before.
 */
function delete_department_row(mysqli $conn, int $departmentId, string $role, int $deanFacultyId): array
{
    if ($role === 'dean') {
        $ownCheckStmt = $conn->prepare('SELECT id, name FROM departments WHERE id = ? AND faculty_id = ?');
        $ownCheckStmt->bind_param('ii', $departmentId, $deanFacultyId);
        $ownCheckStmt->execute();
        $deptRow = $ownCheckStmt->get_result()->fetch_assoc();
        $ownCheckStmt->close();
    } else {
        $deptStmt = $conn->prepare('SELECT id, name FROM departments WHERE id = ?');
        $deptStmt->bind_param('i', $departmentId);
        $deptStmt->execute();
        $deptRow = $deptStmt->get_result()->fetch_assoc();
        $deptStmt->close();
    }

    if (!$deptRow) {
        return ['ok' => false, 'message' => 'Department not found.'];
    }

    $label = (string) $deptRow['name'];

    $blockers = [];
    foreach (['courses' => 'course', 'students' => 'student', 'lecturers' => 'lecturer'] as $table => $blockerLabel) {
        $countStmt = $conn->prepare("SELECT COUNT(*) AS c FROM {$table} WHERE department_id = ?");
        $countStmt->bind_param('i', $departmentId);
        $countStmt->execute();
        $count = (int) ($countStmt->get_result()->fetch_assoc()['c'] ?? 0);
        $countStmt->close();
        if ($count > 0) {
            $blockers[] = $count . ' ' . $blockerLabel . ($count === 1 ? '' : 's');
        }
    }

    if (!empty($blockers)) {
        return ['ok' => false, 'message' => $label . ': still has ' . implode(', ', $blockers) . '.'];
    }

    $deleteStmt = $conn->prepare('DELETE FROM departments WHERE id = ?');
    $deleteStmt->bind_param('i', $departmentId);
    $deleteStmt->execute();
    $deleteStmt->close();

    return ['ok' => true, 'message' => $label . ' deleted.'];
}

// ---------------------------------------------------------------------
// Handle POST actions: create, update, delete, bulk_delete (university_rector only)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($isReadOnly) {
        $_SESSION['flash_error'] = 'Access scope: View only — this role cannot modify records.';
        redirect_to('admin/departments.php');
    }

    if ($action === 'create' || $action === 'update') {
        $departmentId = $action === 'update' ? (int) ($_POST['department_id'] ?? 0) : 0;
        $name = trim((string) ($_POST['name'] ?? ''));
        $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
        // A Dean's faculty is always the session's own faculty_id — never the
        // posted value — so a crafted faculty_id in the request cannot move a
        // department into (or create one in) a faculty they don't oversee.
        $facultyId = $role === 'dean' ? $deanFacultyId : (int) ($_POST['faculty_id'] ?? 0);

        $formMode = $action === 'update' ? 'edit' : 'create';
        $formValues = ['id' => $departmentId, 'code' => $code, 'name' => $name, 'faculty_id' => $facultyId];

        $validationError = '';
        if ($name === '') {
            $validationError = 'Department name is required.';
        } elseif ($code === '') {
            $validationError = 'Department code is required.';
        } elseif ($facultyId <= 0) {
            $validationError = 'Please select a faculty.';
        } elseif ($action === 'update' && $departmentId <= 0) {
            $validationError = 'Invalid department selected for editing.';
        }

        if ($validationError === '') {
            $facultyCheckStmt = $conn->prepare('SELECT id FROM faculties WHERE id = ?');
            $facultyCheckStmt->bind_param('i', $facultyId);
            $facultyCheckStmt->execute();
            if (!$facultyCheckStmt->get_result()->fetch_assoc()) {
                $validationError = 'Selected faculty does not exist.';
            }
            $facultyCheckStmt->close();
        }

        // A Dean editing an existing department must currently own it — blocks
        // a crafted department_id belonging to another faculty from being
        // "adopted" into the Dean's own faculty via this form.
        if ($validationError === '' && $role === 'dean' && $action === 'update') {
            $ownCheckStmt = $conn->prepare('SELECT id FROM departments WHERE id = ? AND faculty_id = ?');
            $ownCheckStmt->bind_param('ii', $departmentId, $deanFacultyId);
            $ownCheckStmt->execute();
            if (!$ownCheckStmt->get_result()->fetch_assoc()) {
                $validationError = 'Invalid department selected for editing.';
            }
            $ownCheckStmt->close();
        }

        if ($validationError === '') {
            // Code only needs to be unique within the same faculty (schema: uq_dept_code_per_faculty).
            $dupStmt = $conn->prepare('SELECT id FROM departments WHERE faculty_id = ? AND UPPER(code) = ? AND id != ?');
            $dupStmt->bind_param('isi', $facultyId, $code, $departmentId);
            $dupStmt->execute();
            if ($dupStmt->get_result()->fetch_assoc()) {
                $validationError = 'This department code is already used within the selected faculty.';
            }
            $dupStmt->close();
        }

        if ($validationError === '') {
            if ($action === 'create') {
                $insertStmt = $conn->prepare('INSERT INTO departments (code, name, faculty_id) VALUES (?, ?, ?)');
                $insertStmt->bind_param('ssi', $code, $name, $facultyId);
                $insertStmt->execute();
                $insertStmt->close();
                $_SESSION['flash_success'] = 'Department added successfully.';
            } else {
                $updateStmt = $conn->prepare('UPDATE departments SET code = ?, name = ?, faculty_id = ? WHERE id = ?');
                $updateStmt->bind_param('ssii', $code, $name, $facultyId, $departmentId);
                $updateStmt->execute();
                $updateStmt->close();
                $_SESSION['flash_success'] = 'Department updated successfully.';
            }

            redirect_to('admin/departments.php');
        }

        $errorMessage = $validationError;
    } elseif ($action === 'delete') {
        $departmentId = (int) ($_POST['department_id'] ?? 0);
        $result = delete_department_row($conn, $departmentId, $role, $deanFacultyId);
        if ($result['ok']) {
            audit_log($conn, 'delete_department', 'department', $departmentId, isset($result['message']) ? preg_replace('/ deleted\.?$/', '', $result['message']) : null);
            $_SESSION['flash_success'] = 'Department deleted successfully.';
            redirect_to('admin/departments.php');
        } else {
            $errorMessage = $result['message'];
        }
    } elseif ($action === 'bulk_delete') {
        // Per this feature's scope, bulk delete is university_rector-only —
        // Dean keeps single-row delete (above) but not the bulk UI/action.
        if ($role !== 'university_rector') {
            $_SESSION['flash_error'] = 'You are not permitted to bulk-delete departments.';
        } else {
            $ids = array_values(array_unique(array_filter(
                array_map('intval', (array) ($_POST['department_ids'] ?? [])),
                static fn ($id) => $id > 0
            )));

            if (empty($ids)) {
                $_SESSION['flash_error'] = 'No departments were selected.';
            } else {
                $deletedCount = 0;
                $skippedMessages = [];
                foreach ($ids as $did) {
                    $result = delete_department_row($conn, $did, $role, $deanFacultyId);
                    if ($result['ok']) {
                        $deletedCount++;
                    } else {
                        $skippedMessages[] = $result['message'];
                    }
                }

                $summary = $deletedCount . ' of ' . count($ids) . ' selected department' . (count($ids) === 1 ? '' : 's') . ' deleted.';
                if (!empty($skippedMessages)) {
                    $summary .= ' Skipped: ' . implode(' | ', $skippedMessages);
                }
                if ($deletedCount > 0) {
                    audit_log($conn, 'bulk_delete', 'department', null, null, $summary);
                    $_SESSION['flash_success'] = $summary;
                } else {
                    $_SESSION['flash_error'] = $summary;
                }
            }
        }
        redirect_to('admin/departments.php');
    }
}

// ---------------------------------------------------------------------
// GET ?edit=ID switches the side panel into edit mode (skipped if a
// failed POST above already put the form into edit mode).
// ---------------------------------------------------------------------
if ($formMode === 'create' && isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    if ($role === 'dean') {
        $editStmt = $conn->prepare('SELECT id, code, name, faculty_id FROM departments WHERE id = ? AND faculty_id = ?');
        $editStmt->bind_param('ii', $editId, $deanFacultyId);
    } else {
        $editStmt = $conn->prepare('SELECT id, code, name, faculty_id FROM departments WHERE id = ?');
        $editStmt->bind_param('i', $editId);
    }
    $editStmt->execute();
    $editRow = $editStmt->get_result()->fetch_assoc();
    $editStmt->close();

    if ($editRow) {
        $formMode = 'edit';
        $formValues = [
            'id' => (int) $editRow['id'],
            'code' => (string) $editRow['code'],
            'name' => (string) $editRow['name'],
            'faculty_id' => (int) $editRow['faculty_id'],
        ];
    }
}

// ---------------------------------------------------------------------
// Data for rendering
// ---------------------------------------------------------------------
$faculties = $role === 'dean'
    ? array_filter($conn->query('SELECT id, name FROM faculties ORDER BY name')->fetch_all(MYSQLI_ASSOC), static fn ($f) => (int) $f['id'] === $deanFacultyId)
    : $conn->query('SELECT id, name FROM faculties ORDER BY name')->fetch_all(MYSQLI_ASSOC);

if ($role === 'dean') {
    $deptListStmt = $conn->prepare(
        "SELECT d.id, d.code, d.name, d.faculty_id, f.name AS faculty_name,
                (SELECT COUNT(*) FROM students s WHERE s.department_id = d.id) AS student_count
         FROM departments d
         JOIN faculties f ON f.id = d.faculty_id
         WHERE d.faculty_id = ?
         ORDER BY d.name"
    );
    $deptListStmt->bind_param('i', $deanFacultyId);
    $deptListStmt->execute();
    $departments = $deptListStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $deptListStmt->close();
} else {
    $departments = $conn->query(
        "SELECT d.id, d.code, d.name, d.faculty_id, f.name AS faculty_name,
                (SELECT COUNT(*) FROM students s WHERE s.department_id = d.id) AS student_count
         FROM departments d
         JOIN faculties f ON f.id = d.faculty_id
         ORDER BY f.name, d.name"
    )->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Department Management — ADMAS Attendance System</title>
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
                <?php if ($role === 'dean'): ?>
                    Access scope: <?= htmlspecialchars($deanFacultyName) ?> Faculty only — view only
                <?php elseif ($isReadOnly): ?>
                    Access scope: Full system — view only (oversight)
                <?php else: ?>
                    Access scope: Full system — all faculties, departments, and courses
                <?php endif; ?>
            </div>

            <?php if (in_array($role, ['university_rector', 'head_academic'], true)): ?>
                <div class="export-card">
                    <div>
                        <p class="export-card-title"><i class="bi bi-cloud-arrow-down-fill"></i> Export Departments</p>
                        <p class="export-card-sub">Download the full, university-wide department list.</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/export.php?type=departments&format=excel" class="btn btn-sm"><i class="bi bi-file-earmark-excel"></i> Excel</a>
                        <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/export.php?type=departments&format=pdf" class="btn btn-sm"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
                    </div>
                </div>
            <?php endif; ?>

            <div class="mb-4">
                <h4 class="fw-bold mb-1" style="color: var(--admas-text);">Department Management</h4>
                <p class="text-muted mb-0">Create and manage academic departments.</p>
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

            <div class="row g-3">
                <div class="<?= $isReadOnly ? 'col-lg-12' : 'col-lg-8' ?>">
                    <div class="admas-card p-4">
                        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                            <h6 class="fw-bold mb-0" style="color: var(--admas-text);">Departments</h6>
                            <div class="d-flex gap-2">
                                <?php if (!$isReadOnly): ?>
                                    <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/departments_import.php" class="btn btn-sm text-white" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                                        <i class="bi bi-file-earmark-arrow-up"></i> Import from Excel
                                    </a>
                                    <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/departments.php" class="btn btn-primary btn-sm" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                                        <i class="bi bi-plus-lg"></i> Add Department
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table admas-table align-middle">
                                <thead>
                                    <tr>
                                        <th>Code</th>
                                        <th>Department Name</th>
                                        <th>Faculty</th>
                                        <th># Students</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($departments)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted py-4">No departments have been created yet.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($departments as $d): ?>
                                            <tr>
                                                <td><span class="badge-pill badge-active"><?= htmlspecialchars($d['code']) ?></span></td>
                                                <td class="fw-semibold" style="color: var(--admas-text);"><?= htmlspecialchars($d['name']) ?></td>
                                                <td><?= htmlspecialchars($d['faculty_name']) ?></td>
                                                <td><?= number_format((int) $d['student_count']) ?></td>
                                                <td>
                                                    <?php if (!$isReadOnly): ?>
                                                    <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/departments.php?edit=<?= (int) $d['id'] ?>" class="btn-icon-label" title="Edit">
                                                        <i class="bi bi-pencil"></i> Edit
                                                    </a>
                                                    <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/departments.php" style="display:inline;"
                                                          onsubmit="return confirm('Delete this department? This cannot be undone.');">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="department_id" value="<?= (int) $d['id'] ?>">
                                                        <button type="submit" class="btn-icon-label text-danger" title="Delete">
                                                            <i class="bi bi-trash"></i> Delete
                                                        </button>
                                                    </form>
                                                    <?php else: ?>
                                                        <span class="text-muted">&mdash;</span>
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

                <?php if (!$isReadOnly): ?>
                <div class="col-lg-4">
                    <div class="admas-card p-4">
                        <h6 class="fw-bold mb-3" style="color: var(--admas-text);">
                            <?= $formMode === 'edit' ? 'Edit Department' : 'Add Department' ?>
                        </h6>
                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/departments.php">
                            <input type="hidden" name="action" value="<?= $formMode === 'edit' ? 'update' : 'create' ?>">
                            <?php if ($formMode === 'edit'): ?>
                                <input type="hidden" name="department_id" value="<?= (int) $formValues['id'] ?>">
                            <?php endif; ?>

                            <div class="mb-3">
                                <label for="deptNameInput" class="form-label">Department Name</label>
                                <input type="text" class="form-control" id="deptNameInput" name="name" maxlength="150"
                                       value="<?= htmlspecialchars($formValues['name']) ?>" required>
                            </div>

                            <div class="mb-3">
                                <label for="deptCodeInput" class="form-label">Code</label>
                                <input type="text" class="form-control text-uppercase" id="deptCodeInput" name="code" maxlength="20"
                                       value="<?= htmlspecialchars($formValues['code']) ?>" required>
                            </div>

                            <div class="mb-3">
                                <label for="deptFacultySelect" class="form-label">Faculty</label>
                                <?php if ($role === 'dean'): ?>
                                    <select class="form-select" disabled>
                                        <option selected><?= htmlspecialchars($deanFacultyName) ?></option>
                                    </select>
                                    <div class="form-text">Locked to your own faculty.</div>
                                <?php else: ?>
                                    <select class="form-select" id="deptFacultySelect" name="faculty_id" required>
                                        <option value="">Select faculty</option>
                                        <?php foreach ($faculties as $f): ?>
                                            <option value="<?= (int) $f['id'] ?>" <?= (int) $formValues['faculty_id'] === (int) $f['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($f['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (empty($faculties)): ?>
                                        <div class="form-text text-danger">No faculties exist yet — create one first.</div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>

                            <button type="submit" class="btn btn-primary w-100" style="background-color: var(--admas-sky); border-color: var(--admas-sky);" <?= empty($faculties) ? 'disabled' : '' ?>>
                                <?= $formMode === 'edit' ? 'Update Department' : 'Save Department' ?>
                            </button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
