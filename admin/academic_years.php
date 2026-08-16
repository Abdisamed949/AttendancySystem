<?php
/**
 * Academic Year Management (University Rector only).
 *
 * Moved out of admin/settings.php into its own CRUD page — previously an
 * admin had to go into Settings just to add a new academic year label
 * before it would show up in the Semester dropdown on semesters.php, which
 * was an awkward detour for something used constantly. This page is the
 * single source of truth for academic_years now; admin/settings.php's own
 * "Academic Year Settings" card was removed and replaced with a link here.
 *
 * "Current" is NOT managed here — that concept moved to a per-faculty,
 * per-semester flag on semesters.php a while ago (see CLAUDE.md's
 * "semesters.php: Manual Semester Creation" entry); an academic year row
 * is just a label used when creating a semester.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';

require_role(['university_rector']);

$conn = db();
$isReadOnly = (current_role() === 'university_rector');

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

// State used to re-open the modal with the user's input if validation fails.
$reopenModal = false;
$reopenMode = 'create';
$reopenId = 0;
$reopenLabel = '';

// ---------------------------------------------------------------------
// Handle POST actions: create, update, delete
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($isReadOnly) {
        $_SESSION['flash_error'] = 'Access scope: View only — this role cannot modify records.';
        redirect_to('admin/academic_years.php');
    }

    if ($action === 'create' || $action === 'update') {
        $academicYearId = $action === 'update' ? (int) ($_POST['academic_year_id'] ?? 0) : 0;
        $label = trim((string) ($_POST['label'] ?? ''));

        $validationError = '';
        if ($label === '') {
            $validationError = 'Academic Year label is required.';
        } elseif (mb_strlen($label) > 20) {
            $validationError = 'Academic Year label must be 20 characters or fewer.';
        } elseif ($action === 'update' && $academicYearId <= 0) {
            $validationError = 'Invalid academic year selected for editing.';
        }

        if ($validationError === '') {
            $dupStmt = $conn->prepare('SELECT id FROM academic_years WHERE label = ? AND id != ?');
            $dupStmt->bind_param('si', $label, $academicYearId);
            $dupStmt->execute();
            if ($dupStmt->get_result()->fetch_assoc()) {
                $validationError = 'This Academic Year already exists.';
            }
            $dupStmt->close();
        }

        if ($validationError === '') {
            if ($action === 'create') {
                $insertStmt = $conn->prepare('INSERT INTO academic_years (label, is_current) VALUES (?, 0)');
                $insertStmt->bind_param('s', $label);
                $insertStmt->execute();
                $insertStmt->close();
                $_SESSION['flash_success'] = 'Academic Year "' . $label . '" added successfully.';
            } else {
                $updateStmt = $conn->prepare('UPDATE academic_years SET label = ? WHERE id = ?');
                $updateStmt->bind_param('si', $label, $academicYearId);
                $updateStmt->execute();
                $updateStmt->close();
                $_SESSION['flash_success'] = 'Academic Year updated successfully.';
            }

            redirect_to('admin/academic_years.php');
        }

        $errorMessage = $validationError;
        $reopenModal = true;
        $reopenMode = $action === 'update' ? 'edit' : 'create';
        $reopenId = $academicYearId;
        $reopenLabel = $label;
    } elseif ($action === 'delete') {
        $academicYearId = (int) ($_POST['academic_year_id'] ?? 0);

        $blockers = [];
        foreach ([
            'semesters' => 'semester',
            'students' => 'student',
            'attendance' => 'attendance record',
        ] as $table => $blockerLabel) {
            $countStmt = $conn->prepare("SELECT COUNT(*) AS c FROM {$table} WHERE academic_year_id = ?");
            $countStmt->bind_param('i', $academicYearId);
            $countStmt->execute();
            $count = (int) ($countStmt->get_result()->fetch_assoc()['c'] ?? 0);
            $countStmt->close();
            if ($count > 0) {
                $blockers[] = $count . ' ' . $blockerLabel . ($count === 1 ? '' : 's');
            }
        }

        if (!empty($blockers)) {
            $errorMessage = 'Cannot delete this academic year: it still has ' . implode(', ', $blockers) . ' linked to it.';
        } else {
            $deleteStmt = $conn->prepare('DELETE FROM academic_years WHERE id = ?');
            $deleteStmt->bind_param('i', $academicYearId);
            $deleteStmt->execute();
            $deleteStmt->close();

            $_SESSION['flash_success'] = 'Academic Year deleted successfully.';
            redirect_to('admin/academic_years.php');
        }
    }
}

// ---------------------------------------------------------------------
// Data for rendering
// ---------------------------------------------------------------------
$academicYears = $conn->query(
    "SELECT ay.id, ay.label,
            (SELECT COUNT(*) FROM semesters s WHERE s.academic_year_id = ay.id) AS semester_count,
            (SELECT COUNT(*) FROM students st WHERE st.academic_year_id = ay.id) AS student_count
     FROM academic_years ay
     ORDER BY ay.label DESC"
)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Year Management — ADMAS Attendance System</title>
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
                Access scope: Full system — view only (oversight)
            </div>

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--admas-text);">Academic Year Management</h4>
                    <p class="text-muted mb-0">Add, rename, or remove the academic year labels used when creating semesters.</p>
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

            <div class="admas-card p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold mb-0" style="color: var(--admas-text);">All Academic Years</h6>
                    <?php if (!$isReadOnly): ?>
                    <button type="button" class="btn btn-primary btn-sm" style="background-color: var(--admas-sky); border-color: var(--admas-sky);"
                            onclick='openAcademicYearModal("create", 0, "")'>
                        <i class="bi bi-plus-lg"></i> Add Academic Year
                    </button>
                    <?php endif; ?>
                </div>

                <div class="table-responsive">
                    <table class="table admas-table align-middle">
                        <thead>
                            <tr>
                                <th>Label</th>
                                <th>Semesters</th>
                                <th>Students</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($academicYears)): ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">No academic years have been created yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($academicYears as $ay): ?>
                                    <tr>
                                        <td class="fw-semibold" style="color: var(--admas-text);"><?= htmlspecialchars($ay['label']) ?></td>
                                        <td><?= number_format((int) $ay['semester_count']) ?></td>
                                        <td><?= number_format((int) $ay['student_count']) ?></td>
                                        <td>
                                            <?php if ($isReadOnly): ?>
                                                <span class="text-muted">&mdash;</span>
                                            <?php else: ?>
                                            <button type="button" class="btn-icon text-sky" title="Edit"
                                                    onclick='openAcademicYearModal("edit", <?= (int) $ay['id'] ?>, <?= json_encode($ay['label'], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/academic_years.php" style="display:inline;"
                                                  onsubmit="return confirm('Delete this academic year? This cannot be undone.');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="academic_year_id" value="<?= (int) $ay['id'] ?>">
                                                <button type="submit" class="btn-icon text-danger" title="Delete">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
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
    </div>

    <!-- Add / Edit Academic Year modal (shared) -->
    <?php if (!$isReadOnly): ?>
    <div class="modal fade" id="academicYearModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content" style="border-radius: 14px;">
                <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/academic_years.php">
                    <div class="modal-header">
                        <h5 class="modal-title" id="academicYearModalLabel">Add Academic Year</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" id="academicYearFormAction" value="create">
                        <input type="hidden" name="academic_year_id" id="academicYearFormId" value="0">

                        <div class="mb-1">
                            <label for="academicYearLabelInput" class="form-label">Label</label>
                            <input type="text" class="form-control" id="academicYearLabelInput" name="label" required maxlength="20" placeholder="e.g. 2026/2027">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">Save Academic Year</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php if (!$isReadOnly): ?>
    <script>
        function openAcademicYearModal(mode, academicYearId, label) {
            document.getElementById('academicYearModalLabel').textContent = mode === 'edit' ? 'Edit Academic Year' : 'Add Academic Year';
            document.getElementById('academicYearFormAction').value = mode === 'edit' ? 'update' : 'create';
            document.getElementById('academicYearFormId').value = academicYearId || 0;
            document.getElementById('academicYearLabelInput').value = label || '';

            new bootstrap.Modal(document.getElementById('academicYearModal')).show();
        }

        <?php if ($reopenModal): ?>
        window.addEventListener('DOMContentLoaded', () => {
            openAcademicYearModal(
                <?= json_encode($reopenMode) ?>,
                <?= (int) $reopenId ?>,
                <?= json_encode($reopenLabel) ?>
            );
        });
        <?php endif; ?>
    </script>
    <?php endif; ?>
</body>
</html>
