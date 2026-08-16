<?php
/**
 * User Management (Head of Academic Affairs) — full CRUD (reset password,
 * activate/deactivate) over every Dean / Head of Academic Affairs /
 * Registration Office / Lecturer / Student account, university-wide, PLUS
 * an Assign Role panel scoped to appointing **Dean** and **Registration
 * Office** only (explicitly requested — University Rector stays the
 * project's one overall root authority, so this role is not granted
 * self-appointment into more Head of Academic Affairs accounts, nor
 * University Rector appointment).
 *
 * Deliberately excludes University Rector accounts from every action on
 * this page — both from the "System Users" list and, as a defense-in-depth
 * check, from every POST action's target — per CLAUDE.md §4's "except top
 * management" boundary on this role's User Management grant.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';
require_once __DIR__ . '/../includes/lecturer_accounts.php';

require_role(['head_academic']);

// Roles this page's Assign Role panel may appoint into — deliberately just
// these two (see the file header). admin/users.php's own APPOINTABLE_ROLES
// also includes 'head_academic'; that stays University Rector-only here.
const HEAD_ACADEMIC_APPOINTABLE_ROLES = ['dean', 'registration'];

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
// Role id <-> name lookup
// ---------------------------------------------------------------------
$roleIdByName = [];
$roleResult = $conn->query('SELECT id, name FROM roles');
while ($row = $roleResult->fetch_assoc()) {
    $roleIdByName[$row['name']] = (int) $row['id'];
}
$systemAdminRoleId = $roleIdByName['university_rector'] ?? 0;
$deanRoleId = $roleIdByName['dean'] ?? 0;

// A user is already "elevated" if their role is one of these — the Select
// User dropdown (and the server-side re-check on save) excludes them, same
// definition as admin/users.php so a target can't be double-appointed from
// either page.
$elevatedRoleIds = array_values(array_filter([
    $roleIdByName['university_rector'] ?? 0,
    $roleIdByName['head_academic'] ?? 0,
    $roleIdByName['registration'] ?? 0,
    $roleIdByName['dean'] ?? 0,
]));

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
// Assign Role form state (re-populated on a failed submit)
// ---------------------------------------------------------------------
$assignFormValues = [
    'selected_user_id' => '',
    'appoint_as' => '',
    'faculty_id' => 0,
    'new_full_name' => '',
    'new_email' => '',
];

/**
 * Loads a user's id/role_id, rejecting University Rector targets. Used
 * by reset_password/toggle_status below so neither can be tricked into
 * touching a university_rector account via a forged user_id in the request.
 *
 * @return array{ok:bool,username?:string,status?:string,message?:string}
 */
function load_manageable_user(mysqli $conn, int $userId, int $systemAdminRoleId): array
{
    $stmt = $conn->prepare('SELECT username, status, role_id FROM users WHERE id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return ['ok' => false, 'message' => 'User not found.'];
    }
    if ((int) $row['role_id'] === $systemAdminRoleId) {
        return ['ok' => false, 'message' => 'University Rector accounts cannot be managed from here.'];
    }

    return ['ok' => true, 'username' => (string) $row['username'], 'status' => (string) $row['status']];
}

// ---------------------------------------------------------------------
// Handle POST actions: assign_role, reset_password, toggle_status
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'assign_role') {
        $selectedUserId = trim((string) ($_POST['selected_user_id'] ?? ''));
        $appointAs = (string) ($_POST['appoint_as'] ?? '');
        $facultyId = (int) ($_POST['faculty_id'] ?? 0);
        $newFullName = trim((string) ($_POST['new_full_name'] ?? ''));
        $newEmail = trim((string) ($_POST['new_email'] ?? ''));
        $isNewUser = $selectedUserId === 'new';

        $assignFormValues = [
            'selected_user_id' => $selectedUserId,
            'appoint_as' => $appointAs,
            'faculty_id' => $facultyId,
            'new_full_name' => $newFullName,
            'new_email' => $newEmail,
        ];

        $validationError = '';
        $targetUserId = 0;

        if (!in_array($appointAs, HEAD_ACADEMIC_APPOINTABLE_ROLES, true)) {
            $validationError = 'Please choose a valid role to appoint.';
        } elseif ($appointAs === 'dean' && $facultyId <= 0) {
            $validationError = 'Please select a Faculty for the Dean appointment.';
        }

        if ($validationError === '' && $isNewUser) {
            if ($newFullName === '') {
                $validationError = 'Full name is required for a new user.';
            } elseif ($newEmail !== '' && !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                $validationError = 'Please enter a valid email address.';
            }

            if ($validationError === '' && $newEmail !== '') {
                $emailCheckStmt = $conn->prepare('SELECT id FROM users WHERE email = ?');
                $emailCheckStmt->bind_param('s', $newEmail);
                $emailCheckStmt->execute();
                if ($emailCheckStmt->get_result()->fetch_assoc()) {
                    $validationError = 'This email address is already used by another account.';
                }
                $emailCheckStmt->close();
            }
        } elseif ($validationError === '') {
            $targetUserId = (int) $selectedUserId;
            if ($targetUserId <= 0) {
                $validationError = 'Please select a user to appoint.';
            } else {
                // Never trust the dropdown alone — re-verify server-side that
                // this user isn't already elevated (and isn't System Admin).
                $userCheckStmt = $conn->prepare('SELECT role_id FROM users WHERE id = ?');
                $userCheckStmt->bind_param('i', $targetUserId);
                $userCheckStmt->execute();
                $userCheckRow = $userCheckStmt->get_result()->fetch_assoc();
                $userCheckStmt->close();

                if (!$userCheckRow) {
                    $validationError = 'Selected user no longer exists.';
                } elseif ((int) $userCheckRow['role_id'] === $systemAdminRoleId) {
                    $validationError = 'University Rector accounts cannot be managed from here.';
                } elseif (in_array((int) $userCheckRow['role_id'], $elevatedRoleIds, true)) {
                    $validationError = 'That user already has an elevated role and cannot be re-appointed from here.';
                }
            }
        }

        if ($validationError === '' && $appointAs === 'dean') {
            $facultyCheckStmt = $conn->prepare('SELECT id FROM faculties WHERE id = ?');
            $facultyCheckStmt->bind_param('i', $facultyId);
            $facultyCheckStmt->execute();
            if (!$facultyCheckStmt->get_result()->fetch_assoc()) {
                $validationError = 'Selected faculty does not exist.';
            }
            $facultyCheckStmt->close();
        }

        if ($validationError === '') {
            $targetRoleId = $roleIdByName[$appointAs];
            $facultyParam = $appointAs === 'dean' ? $facultyId : null;

            $conn->begin_transaction();
            try {
                $generatedUsername = '';
                $generatedPassword = '';

                if ($appointAs === 'dean') {
                    // Release any dean currently assigned to this faculty before appointing the new one.
                    $releaseStmt = $conn->prepare('UPDATE users SET faculty_id = NULL WHERE faculty_id = ? AND role_id = ?');
                    $releaseStmt->bind_param('ii', $facultyId, $deanRoleId);
                    $releaseStmt->execute();
                    $releaseStmt->close();
                }

                if ($isNewUser) {
                    $generatedUsername = generate_admin_username($conn, $newFullName);
                    $generatedPassword = generate_temp_password();
                    $passwordHash = password_hash($generatedPassword, PASSWORD_DEFAULT);
                    $emailParam = $newEmail !== '' ? $newEmail : null;

                    $insertStmt = $conn->prepare(
                        'INSERT INTO users (username, password_hash, full_name, email, role_id, faculty_id, status) VALUES (?, ?, ?, ?, ?, ?, "active")'
                    );
                    $insertStmt->bind_param('ssssii', $generatedUsername, $passwordHash, $newFullName, $emailParam, $targetRoleId, $facultyParam);
                    $insertStmt->execute();
                    $targetUserId = (int) $conn->insert_id;
                    $insertStmt->close();
                } else {
                    $updateStmt = $conn->prepare('UPDATE users SET role_id = ?, faculty_id = ? WHERE id = ?');
                    $updateStmt->bind_param('iii', $targetRoleId, $facultyParam, $targetUserId);
                    $updateStmt->execute();
                    $updateStmt->close();
                }

                if ($appointAs === 'dean') {
                    $facultyUpdateStmt = $conn->prepare('UPDATE faculties SET dean_user_id = ? WHERE id = ?');
                    $facultyUpdateStmt->bind_param('ii', $targetUserId, $facultyId);
                    $facultyUpdateStmt->execute();
                    $facultyUpdateStmt->close();
                }

                $assignedBy = (int) $currentUser['id'];
                $raStmt = $conn->prepare(
                    'INSERT INTO role_assignments (user_id, role_id, faculty_id, assigned_by) VALUES (?, ?, ?, ?)'
                );
                $raStmt->bind_param('iiii', $targetUserId, $targetRoleId, $facultyParam, $assignedBy);
                $raStmt->execute();
                $raStmt->close();

                $conn->commit();

                $roleLabelText = role_label($appointAs);
                $message = 'Appointed successfully as ' . $roleLabelText . '.';
                if ($isNewUser) {
                    $message = 'New user created and appointed as ' . $roleLabelText . '. Username: '
                        . $generatedUsername . ' — Temporary Password: ' . $generatedPassword
                        . ' — share these credentials now; the password will not be shown again.';
                }
                $_SESSION['flash_success'] = $message;
            } catch (Throwable $e) {
                $conn->rollback();
                $_SESSION['flash_error'] = 'Could not save the role assignment. Please try again.';
            }

            redirect_to('head_academic/users.php');
        }

        $errorMessage = $validationError;
    } elseif ($action === 'reset_password') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $target = load_manageable_user($conn, $userId, $systemAdminRoleId);
        if (!$target['ok']) {
            $errorMessage = $target['message'];
        } else {
            $newPassword = generate_temp_password();
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

            $updatePassStmt = $conn->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $updatePassStmt->bind_param('si', $newHash, $userId);
            $updatePassStmt->execute();
            $updatePassStmt->close();

            $_SESSION['flash_success'] = 'Password reset for ' . $target['username']
                . '. New temporary password: ' . $newPassword
                . ' — share this with them now; it will not be shown again.';
            redirect_to('head_academic/users.php');
        }
    } elseif ($action === 'toggle_status') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        if ($userId === (int) $currentUser['id']) {
            $errorMessage = 'You cannot activate or deactivate your own account.';
        } else {
            $target = load_manageable_user($conn, $userId, $systemAdminRoleId);
            if (!$target['ok']) {
                $errorMessage = $target['message'];
            } else {
                $newStatus = $target['status'] === 'active' ? 'inactive' : 'active';
                $updateStmt = $conn->prepare('UPDATE users SET status = ? WHERE id = ?');
                $updateStmt->bind_param('si', $newStatus, $userId);
                $updateStmt->execute();
                $updateStmt->close();

                $_SESSION['flash_success'] = $target['username'] . ' is now ' . $newStatus . '.';
                redirect_to('head_academic/users.php');
            }
        }
    }
}

// ---------------------------------------------------------------------
// Data for rendering
// ---------------------------------------------------------------------
$faculties = $conn->query('SELECT id, name FROM faculties ORDER BY name')->fetch_all(MYSQLI_ASSOC);

$assignableUsers = [];
if (!empty($elevatedRoleIds)) {
    $placeholders = implode(',', array_fill(0, count($elevatedRoleIds), '?'));
    $types = str_repeat('i', count($elevatedRoleIds));
    $stmt = $conn->prepare(
        "SELECT u.id, u.username, u.full_name, r.name AS role_name
         FROM users u
         JOIN roles r ON r.id = u.role_id
         WHERE u.role_id NOT IN ({$placeholders})
         ORDER BY u.full_name"
    );
    $stmt->bind_param($types, ...$elevatedRoleIds);
    $stmt->execute();
    $assignableUsers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Every account except University Rector.
$allUsers = $conn->query(
    "SELECT u.id, u.username, u.full_name, u.status, u.last_login_at,
            r.name AS role_name, f.name AS faculty_name
     FROM users u
     JOIN roles r ON r.id = u.role_id
     LEFT JOIN faculties f ON f.id = u.faculty_id
     WHERE r.name != 'university_rector'
     ORDER BY CASE r.name
                WHEN 'head_academic' THEN 1
                WHEN 'registration' THEN 2
                WHEN 'dean' THEN 3
                WHEN 'lecturer' THEN 4
                WHEN 'student' THEN 5
                ELSE 6
              END,
              u.full_name"
)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management — ADMAS Attendance System</title>
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
                Access scope: All faculties — every account except University Rector. Appointment limited to Dean and Registration Office.
            </div>

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--admas-text);">User Management</h4>
                    <p class="text-muted mb-0">Appoint Dean/Registration Office and manage every non-admin login account.</p>
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

            <!-- Assign Role panel (Dean / Registration Office only) -->
            <div class="admas-card p-4 mb-3">
                <h6 class="fw-bold mb-3" style="color: var(--admas-text);">Assign Role</h6>
                <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/head_academic/users.php">
                    <input type="hidden" name="action" value="assign_role">

                    <div class="mb-3">
                        <label for="selectedUserSelect" class="form-label">Select User</label>
                        <select class="form-select" id="selectedUserSelect" name="selected_user_id" onchange="toggleNewUserFields()" required>
                            <option value="">Choose a user…</option>
                            <option value="new" <?= $assignFormValues['selected_user_id'] === 'new' ? 'selected' : '' ?>>+ Create New User</option>
                            <?php foreach ($assignableUsers as $u): ?>
                                <option value="<?= (int) $u['id'] ?>" <?= $assignFormValues['selected_user_id'] === (string) $u['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($u['full_name'] . ' (' . $u['username'] . ') — currently ' . role_label($u['role_name'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Only users who are not already Dean/Head of Academic Affairs/Registration Office/University Rector are listed.</div>
                    </div>

                    <div id="newUserFields" class="border rounded p-3 mb-3" style="display:none; background: var(--admas-bg);">
                        <div class="mb-3">
                            <label for="newFullNameInput" class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="newFullNameInput" name="new_full_name" maxlength="150"
                                   value="<?= htmlspecialchars($assignFormValues['new_full_name']) ?>">
                        </div>
                        <div class="mb-0">
                            <label for="newEmailInput" class="form-label">Email</label>
                            <input type="email" class="form-control" id="newEmailInput" name="new_email" maxlength="150"
                                   value="<?= htmlspecialchars($assignFormValues['new_email']) ?>">
                            <div class="form-text">A username and temporary password will be generated automatically.</div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="appointAsSelect" class="form-label">Appoint As</label>
                        <select class="form-select" id="appointAsSelect" name="appoint_as" onchange="toggleFacultyField()" required>
                            <option value="">Choose a role…</option>
                            <?php foreach (HEAD_ACADEMIC_APPOINTABLE_ROLES as $roleKey): ?>
                                <option value="<?= htmlspecialchars($roleKey) ?>" <?= $assignFormValues['appoint_as'] === $roleKey ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(role_label($roleKey)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="facultyFieldWrap" class="mb-3" style="display:none;">
                        <label for="facultySelect" class="form-label">Faculty</label>
                        <select class="form-select" id="facultySelect" name="faculty_id">
                            <option value="0">Select faculty</option>
                            <?php foreach ($faculties as $f): ?>
                                <option value="<?= (int) $f['id'] ?>" <?= $assignFormValues['faculty_id'] === (int) $f['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($f['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Required only when appointing a Dean.</div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                        <i class="bi bi-person-check"></i> Save Appointment
                    </button>
                </form>
            </div>

            <div class="admas-card p-4">
                <h6 class="fw-bold mb-3" style="color: var(--admas-text);">System Users</h6>

                <div class="table-responsive">
                    <table class="table admas-table align-middle">
                        <thead>
                            <tr>
                                <th>Username</th>
                                <th>Full Name</th>
                                <th>Role</th>
                                <th>Faculty</th>
                                <th>Last Login</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($allUsers)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">No users exist yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($allUsers as $u): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($u['username']) ?></td>
                                        <td class="fw-semibold" style="color: var(--admas-text);"><?= htmlspecialchars($u['full_name']) ?></td>
                                        <td><?= htmlspecialchars(role_label($u['role_name'])) ?></td>
                                        <td>
                                            <?php if ($u['faculty_name']): ?>
                                                <?= htmlspecialchars($u['faculty_name']) ?>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= $u['last_login_at'] ? htmlspecialchars(date('M j, Y g:i A', strtotime((string) $u['last_login_at']))) : '<span class="text-muted fst-italic">Never</span>' ?>
                                        </td>
                                        <td>
                                            <?php if ($u['status'] === 'active'): ?>
                                                <span class="badge-pill badge-active">Active</span>
                                            <?php else: ?>
                                                <span class="badge-pill badge-inactive">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/head_academic/users.php" style="display:inline;"
                                                  onsubmit="return confirm('Reset the password for <?= htmlspecialchars(addslashes($u['username'])) ?>? A new temporary password will be generated.');">
                                                <input type="hidden" name="action" value="reset_password">
                                                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                                <button type="submit" class="btn-icon" title="Reset Password">
                                                    <i class="bi bi-key"></i>
                                                </button>
                                            </form>
                                            <?php if ((int) $u['id'] !== (int) $currentUser['id']): ?>
                                                <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/head_academic/users.php" style="display:inline;"
                                                      onsubmit="return confirm('<?= $u['status'] === 'active' ? 'Deactivate' : 'Activate' ?> <?= htmlspecialchars(addslashes($u['username'])) ?>?');">
                                                    <input type="hidden" name="action" value="toggle_status">
                                                    <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                                    <button type="submit" class="btn-icon" title="<?= $u['status'] === 'active' ? 'Deactivate' : 'Activate' ?>">
                                                        <i class="bi <?= $u['status'] === 'active' ? 'bi-person-x' : 'bi-person-check' ?>"></i>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleNewUserFields() {
            const sel = document.getElementById('selectedUserSelect').value;
            const isNew = sel === 'new';
            document.getElementById('newUserFields').style.display = isNew ? '' : 'none';
            document.getElementById('newFullNameInput').required = isNew;
        }

        function toggleFacultyField() {
            const appointAs = document.getElementById('appointAsSelect').value;
            const isDean = appointAs === 'dean';
            document.getElementById('facultyFieldWrap').style.display = isDean ? '' : 'none';
            document.getElementById('facultySelect').required = isDean;
        }

        window.addEventListener('DOMContentLoaded', () => {
            toggleNewUserFields();
            toggleFacultyField();
        });
    </script>
</body>
</html>
