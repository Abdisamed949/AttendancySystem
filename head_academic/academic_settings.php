<?php
/**
 * Academic Settings (Head of Academic Affairs only) — Academic Year
 * management (add / set current) and the minimum attendance threshold,
 * per CLAUDE.md §4 "Set Academic Year & minimum attendance threshold".
 * Reads/writes the same `settings` rows as admin/settings.php's "Academic
 * Year Settings" card, but deliberately omits University Information and
 * the default Faculty/Department scope — those stay university_rector-only.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';
require_once __DIR__ . '/../includes/attendance_helpers.php';

require_role(['head_academic']);

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
// on a failed submit of that specific form, so the other stays untouched)
// ---------------------------------------------------------------------
$addYearFormValues = ['label' => ''];
$thresholdFormValues = [
    'min_attendance_pct' => (string) ($settings['min_attendance_pct'] ?? '75'),
];
// ---------------------------------------------------------------------
// Handle POST actions
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add_academic_year') {
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
            redirect_to('head_academic/academic_settings.php');
        }

        $errorMessage = $validationError;
    } elseif ($action === 'save_threshold') {
        $minAttendancePctInput = trim((string) ($_POST['min_attendance_pct'] ?? ''));
        $thresholdFormValues = ['min_attendance_pct' => $minAttendancePctInput];

        $validationError = '';
        if (!is_numeric($minAttendancePctInput) || (float) $minAttendancePctInput < 0 || (float) $minAttendancePctInput > 10) {
            $validationError = 'Minimum Attendance must be a number between 0 and 10 (out of the 10 regular Xiiso sessions).';
        }

        if ($validationError === '') {
            $minPctFormatted = (string) round((float) $minAttendancePctInput, 2);
            save_setting($conn, 'min_attendance_pct', $minPctFormatted);

            $_SESSION['flash_success'] = 'Minimum attendance threshold updated successfully.';
            redirect_to('head_academic/academic_settings.php');
        }

        $errorMessage = $validationError;
    } elseif ($action === 'save_semesters_per_year') {
        $facultyId = (int) ($_POST['faculty_id'] ?? 0);
        $semestersPerYearInput = (int) ($_POST['semesters_per_year'] ?? 0);

        $validationError = '';
        if ($facultyId <= 0) {
            $validationError = 'Invalid faculty.';
        } elseif ($semestersPerYearInput < 1 || $semestersPerYearInput > 6) {
            $validationError = 'Semesters per Year must be between 1 and 6.';
        }

        if ($validationError === '') {
            $facultyCheckStmt = $conn->prepare('SELECT id, name FROM faculties WHERE id = ?');
            $facultyCheckStmt->bind_param('i', $facultyId);
            $facultyCheckStmt->execute();
            $facultyRow = $facultyCheckStmt->get_result()->fetch_assoc();
            $facultyCheckStmt->close();

            if (!$facultyRow) {
                $validationError = 'Faculty not found.';
            } else {
                $updateStmt = $conn->prepare('UPDATE faculties SET semesters_per_year = ? WHERE id = ?');
                $updateStmt->bind_param('ii', $semestersPerYearInput, $facultyId);
                $updateStmt->execute();
                $updateStmt->close();

                $_SESSION['flash_success'] = 'Semesters per Year updated for ' . $facultyRow['name'] . '.';
                redirect_to('head_academic/academic_settings.php');
            }
        }

        $errorMessage = $validationError;
    }
}

// ---------------------------------------------------------------------
// Data for rendering
// ---------------------------------------------------------------------
$academicYears = $conn->query('SELECT id, label, is_current FROM academic_years ORDER BY label DESC')->fetch_all(MYSQLI_ASSOC);
$faculties = $conn->query('SELECT id, name, semesters_per_year FROM faculties ORDER BY name')->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Settings — ADMAS Attendance System</title>
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
                Access scope: All faculties (cross-faculty)
            </div>

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--admas-text);">Academic Settings</h4>
                    <p class="text-muted mb-0">Manage the Academic Year and the minimum attendance threshold.</p>
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

            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="admas-card p-4 h-100">
                        <h6 class="small text-uppercase text-muted mb-2">Academic Years</h6>
                        <p class="text-muted small">
                            "Current" is now set per faculty, per semester, on the
                            <a href="<?= htmlspecialchars(BASE_URL) ?>/semesters.php">Semesters</a> page — an academic
                            year here is just a label used when creating a semester.
                        </p>
                        <div class="table-responsive mb-3">
                            <table class="table admas-table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Label</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($academicYears)): ?>
                                        <tr>
                                            <td class="text-center text-muted py-3">No academic years exist yet.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($academicYears as $ay): ?>
                                            <tr>
                                                <td class="fw-semibold" style="color: var(--admas-text);"><?= htmlspecialchars($ay['label']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <h6 class="small text-uppercase text-muted mb-2">Add New Academic Year</h6>
                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/head_academic/academic_settings.php" class="d-flex gap-2">
                            <input type="hidden" name="action" value="add_academic_year">
                            <input type="text" class="form-control" name="label" maxlength="20" placeholder="e.g. 2026" required
                                   value="<?= htmlspecialchars($addYearFormValues['label']) ?>">
                            <button type="submit" class="btn btn-primary text-nowrap" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                                <i class="bi bi-plus-lg"></i> Add
                            </button>
                        </form>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="admas-card p-4 h-100">
                        <h6 class="small text-uppercase text-muted mb-2">Minimum Attendance Threshold</h6>
                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/head_academic/academic_settings.php">
                            <input type="hidden" name="action" value="save_threshold">

                            <div class="mb-3">
                                <label for="minAttendancePctInput" class="form-label">Minimum Attendance (out of 10)</label>
                                <input type="number" class="form-control" id="minAttendancePctInput" name="min_attendance_pct"
                                       min="0" max="10" step="0.1" required
                                       value="<?= htmlspecialchars($thresholdFormValues['min_attendance_pct']) ?>">
                                <div class="form-text">Out of 10 — each Present regular Xiiso session is worth 1 point (Midterm/Final don't count). Students below this score are surfaced in Notifications / Alerts.</div>
                            </div>

                            <button type="submit" class="btn btn-primary w-100" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                                <i class="bi bi-save"></i> Save Threshold
                            </button>
                        </form>
                    </div>
                </div>

                <div class="col-lg-12">
                    <div class="admas-card p-4">
                        <h6 class="small text-uppercase text-muted mb-2">Semesters per Year — by Faculty</h6>
                        <p class="text-muted small">
                            How many semesters each faculty's curriculum runs per year (e.g. 3 for most
                            faculties, 2 for a faculty like Health Sciences) — drives the "Year X" shown on
                            the <a href="<?= htmlspecialchars(BASE_URL) ?>/semesters.php">Semesters</a> page
                            and how <a href="<?= htmlspecialchars(BASE_URL) ?>/semesters.php">Generate Next
                            Semester</a> numbers new semesters for that faculty.
                        </p>
                        <div class="table-responsive">
                            <table class="table admas-table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Faculty</th>
                                        <th style="width: 260px;">Semesters per Year</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($faculties)): ?>
                                        <tr>
                                            <td colspan="2" class="text-center text-muted py-3">No faculties exist yet.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($faculties as $f): ?>
                                            <tr>
                                                <td class="fw-semibold" style="color: var(--admas-text);"><?= htmlspecialchars($f['name']) ?></td>
                                                <td>
                                                    <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/head_academic/academic_settings.php" class="d-flex gap-2">
                                                        <input type="hidden" name="action" value="save_semesters_per_year">
                                                        <input type="hidden" name="faculty_id" value="<?= (int) $f['id'] ?>">
                                                        <input type="number" class="form-control form-control-sm" name="semesters_per_year"
                                                               min="1" max="6" required style="max-width: 100px;"
                                                               value="<?= (int) $f['semesters_per_year'] ?>">
                                                        <button type="submit" class="btn btn-sm text-white" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                                                            <i class="bi bi-save"></i> Save
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
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
