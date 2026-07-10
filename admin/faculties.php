<?php
/**
 * Faculty Management (System Administrator only).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';

require_role(['system_admin']);

$conn = db();
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
// Dean role id (used to scope the Dean dropdown + the Dean column join)
// ---------------------------------------------------------------------
$deanRoleId = 0;
$roleResult = $conn->query("SELECT id FROM roles WHERE name = 'dean'");
if ($roleResult && ($roleRow = $roleResult->fetch_assoc())) {
    $deanRoleId = (int) $roleRow['id'];
}

// State used to re-open the modal with the user's input if validation fails.
$reopenModal = false;
$reopenMode = 'create';
$reopenId = 0;
$reopenName = '';
$reopenDeanId = 0;

// ---------------------------------------------------------------------
// Handle POST actions: create, update, delete
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create' || $action === 'update') {
        $facultyId = $action === 'update' ? (int) ($_POST['faculty_id'] ?? 0) : 0;
        $name = trim((string) ($_POST['name'] ?? ''));
        $deanUserId = (int) ($_POST['dean_user_id'] ?? 0);

        $validationError = '';

        if ($name === '') {
            $validationError = 'Faculty name is required.';
        } elseif ($action === 'update' && $facultyId <= 0) {
            $validationError = 'Invalid faculty selected for editing.';
        }

        if ($validationError === '') {
            $dupStmt = $conn->prepare('SELECT id FROM faculties WHERE LOWER(name) = LOWER(?) AND id != ?');
            $dupStmt->bind_param('si', $name, $facultyId);
            $dupStmt->execute();
            if ($dupStmt->get_result()->fetch_assoc()) {
                $validationError = 'A faculty with this name already exists.';
            }
            $dupStmt->close();
        }

        if ($validationError === '' && $deanUserId > 0) {
            $deanCheckStmt = $conn->prepare(
                'SELECT id FROM users WHERE id = ? AND role_id = ? AND (faculty_id IS NULL OR faculty_id = ?)'
            );
            $deanCheckStmt->bind_param('iii', $deanUserId, $deanRoleId, $facultyId);
            $deanCheckStmt->execute();
            if (!$deanCheckStmt->get_result()->fetch_assoc()) {
                $validationError = 'The selected Dean is invalid or already assigned to another faculty.';
            }
            $deanCheckStmt->close();
        }

        if ($validationError === '') {
            $conn->begin_transaction();
            try {
                $deanParam = $deanUserId > 0 ? $deanUserId : null;

                if ($action === 'create') {
                    $insertStmt = $conn->prepare('INSERT INTO faculties (name, dean_user_id) VALUES (?, ?)');
                    $insertStmt->bind_param('si', $name, $deanParam);
                    $insertStmt->execute();
                    $facultyId = (int) $conn->insert_id;
                    $insertStmt->close();
                } else {
                    // Release any dean currently tied to this faculty before reassigning.
                    $releaseStmt = $conn->prepare('UPDATE users SET faculty_id = NULL WHERE faculty_id = ? AND role_id = ?');
                    $releaseStmt->bind_param('ii', $facultyId, $deanRoleId);
                    $releaseStmt->execute();
                    $releaseStmt->close();

                    $updateStmt = $conn->prepare('UPDATE faculties SET name = ?, dean_user_id = ? WHERE id = ?');
                    $updateStmt->bind_param('sii', $name, $deanParam, $facultyId);
                    $updateStmt->execute();
                    $updateStmt->close();
                }

                if ($deanUserId > 0) {
                    $assignStmt = $conn->prepare('UPDATE users SET faculty_id = ? WHERE id = ? AND role_id = ?');
                    $assignStmt->bind_param('iii', $facultyId, $deanUserId, $deanRoleId);
                    $assignStmt->execute();
                    $assignStmt->close();
                }

                $conn->commit();
                $_SESSION['flash_success'] = $action === 'create' ? 'Faculty added successfully.' : 'Faculty updated successfully.';
            } catch (Throwable $e) {
                $conn->rollback();
                $_SESSION['flash_error'] = 'Could not save the faculty. Please try again.';
            }

            redirect_to('admin/faculties.php');
        }

        $errorMessage = $validationError;
        $reopenModal = true;
        $reopenMode = $action === 'update' ? 'edit' : 'create';
        $reopenId = $facultyId;
        $reopenName = $name;
        $reopenDeanId = $deanUserId;
    } elseif ($action === 'delete') {
        $facultyId = (int) ($_POST['faculty_id'] ?? 0);

        $deptCountStmt = $conn->prepare('SELECT COUNT(*) AS c FROM departments WHERE faculty_id = ?');
        $deptCountStmt->bind_param('i', $facultyId);
        $deptCountStmt->execute();
        $deptCount = (int) ($deptCountStmt->get_result()->fetch_assoc()['c'] ?? 0);
        $deptCountStmt->close();

        if ($deptCount > 0) {
            $errorMessage = "Cannot delete this faculty: it still has {$deptCount} department(s) linked to it. Reassign or remove them first.";
        } else {
            $deleteStmt = $conn->prepare('DELETE FROM faculties WHERE id = ?');
            $deleteStmt->bind_param('i', $facultyId);
            $deleteStmt->execute();
            $deleteStmt->close();

            $_SESSION['flash_success'] = 'Faculty deleted successfully.';
            redirect_to('admin/faculties.php');
        }
    }
}

// ---------------------------------------------------------------------
// Data for rendering
// ---------------------------------------------------------------------
$allDeans = [];
$deanListStmt = $conn->prepare('SELECT id, full_name, faculty_id FROM users WHERE role_id = ? ORDER BY full_name');
$deanListStmt->bind_param('i', $deanRoleId);
$deanListStmt->execute();
$allDeans = $deanListStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$deanListStmt->close();

$faculties = [];
$listStmt = $conn->prepare(
    "SELECT f.id, f.name,
            (SELECT du.id FROM users du WHERE du.faculty_id = f.id AND du.role_id = ? LIMIT 1) AS dean_id,
            (SELECT du.full_name FROM users du WHERE du.faculty_id = f.id AND du.role_id = ? LIMIT 1) AS dean_name,
            (SELECT COUNT(*) FROM departments d WHERE d.faculty_id = f.id) AS dept_count,
            (SELECT COUNT(*) FROM students s WHERE s.faculty_id = f.id) AS student_count
     FROM faculties f
     ORDER BY f.name"
);
$listStmt->bind_param('ii', $deanRoleId, $deanRoleId);
$listStmt->execute();
$faculties = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$listStmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faculty Management — ADMAS Attendance System</title>
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

        .btn-icon.text-danger:hover {
            background: rgba(220, 38, 38, 0.08);
            color: #dc2626;
        }

        .faculty-summary-card {
            padding: 1.1rem 1.25rem;
        }

        .faculty-summary-name {
            font-weight: 700;
            color: #0b1f3a;
            margin-bottom: 0.15rem;
        }

        .faculty-summary-meta {
            font-size: 0.82rem;
            color: #64748b;
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
                    <h4 class="fw-bold mb-1" style="color: #0b1f3a;">Faculty Management</h4>
                    <p class="text-muted mb-0">Create and manage faculties.</p>
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

            <!-- Per-faculty summary cards -->
            <?php if (!empty($faculties)): ?>
                <div class="row g-3 mb-4">
                    <?php foreach ($faculties as $f): ?>
                        <div class="col-sm-6 col-xl-4">
                            <div class="admas-card faculty-summary-card h-100">
                                <div class="faculty-summary-name"><?= htmlspecialchars($f['name']) ?></div>
                                <div class="faculty-summary-meta">
                                    <?= (int) $f['dept_count'] ?> Departments &middot; <?= number_format((int) $f['student_count']) ?> Students
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- All Faculties table -->
            <div class="admas-card p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold mb-0" style="color: #0b1f3a;">All Faculties</h6>
                    <button type="button" class="btn btn-primary btn-sm" style="background-color: #0ea5e9; border-color: #0ea5e9;"
                            onclick='openFacultyModal("create", 0, "", 0)'>
                        <i class="bi bi-plus-lg"></i> Add Faculty
                    </button>
                </div>

                <div class="table-responsive">
                    <table class="table admas-table align-middle">
                        <thead>
                            <tr>
                                <th>Faculty Name</th>
                                <th>Dean</th>
                                <th>Departments</th>
                                <th>Students</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($faculties)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">No faculties have been created yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($faculties as $f): ?>
                                    <tr>
                                        <td class="fw-semibold" style="color: #0b1f3a;"><?= htmlspecialchars($f['name']) ?></td>
                                        <td>
                                            <?php if ($f['dean_name']): ?>
                                                <?= htmlspecialchars($f['dean_name']) ?>
                                            <?php else: ?>
                                                <span class="text-muted fst-italic">Not assigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= (int) $f['dept_count'] ?></td>
                                        <td><?= number_format((int) $f['student_count']) ?></td>
                                        <td>
                                            <button type="button" class="btn-icon" title="Edit"
                                                    onclick='openFacultyModal("edit", <?= (int) $f['id'] ?>, <?= json_encode($f['name'], JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= (int) ($f['dean_id'] ?? 0) ?>)'>
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/faculties.php" style="display:inline;"
                                                  onsubmit="return confirm('Delete this faculty? This cannot be undone.');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="faculty_id" value="<?= (int) $f['id'] ?>">
                                                <button type="submit" class="btn-icon text-danger" title="Delete">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add / Edit Faculty modal (shared) -->
    <div class="modal fade" id="facultyModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content" style="border-radius: 14px;">
                <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/faculties.php">
                    <div class="modal-header">
                        <h5 class="modal-title" id="facultyModalLabel">Add Faculty</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" id="facultyFormAction" value="create">
                        <input type="hidden" name="faculty_id" id="facultyFormId" value="0">

                        <div class="mb-3">
                            <label for="facultyNameInput" class="form-label">Faculty Name</label>
                            <input type="text" class="form-control" id="facultyNameInput" name="name" required maxlength="150">
                        </div>

                        <div class="mb-1">
                            <label for="facultyDeanSelect" class="form-label">Dean</label>
                            <select class="form-select" id="facultyDeanSelect" name="dean_user_id">
                                <option value="0">None</option>
                                <?php foreach ($allDeans as $dean): ?>
                                    <option value="<?= (int) $dean['id'] ?>" data-assigned-faculty="<?= $dean['faculty_id'] !== null ? (int) $dean['faculty_id'] : '' ?>">
                                        <?= htmlspecialchars($dean['full_name']) ?><?= $dean['faculty_id'] !== null ? ' (assigned)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Only Deans who aren't already assigned to another faculty are selectable.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" style="background-color: #0ea5e9; border-color: #0ea5e9;">Save Faculty</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function openFacultyModal(mode, facultyId, name, deanId) {
            document.getElementById('facultyModalLabel').textContent = mode === 'edit' ? 'Edit Faculty' : 'Add Faculty';
            document.getElementById('facultyFormAction').value = mode === 'edit' ? 'update' : 'create';
            document.getElementById('facultyFormId').value = facultyId || 0;
            document.getElementById('facultyNameInput').value = name || '';

            const select = document.getElementById('facultyDeanSelect');
            Array.from(select.options).forEach((opt) => {
                const assigned = opt.getAttribute('data-assigned-faculty');
                const isThisFaculty = assigned !== null && assigned !== '' && parseInt(assigned, 10) === parseInt(facultyId || 0, 10);
                const isUnassigned = !assigned;
                opt.hidden = !(isUnassigned || isThisFaculty || opt.value === '0');
            });
            select.value = String(deanId || 0);

            new bootstrap.Modal(document.getElementById('facultyModal')).show();
        }

        <?php if ($reopenModal): ?>
        window.addEventListener('DOMContentLoaded', () => {
            openFacultyModal(
                <?= json_encode($reopenMode) ?>,
                <?= (int) $reopenId ?>,
                <?= json_encode($reopenName) ?>,
                <?= (int) $reopenDeanId ?>
            );
        });
        <?php endif; ?>
    </script>
</body>
</html>
