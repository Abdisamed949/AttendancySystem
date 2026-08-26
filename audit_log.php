<?php
/**
 * Audit Log — University Rector only. A read-only record of every
 * sensitive/high-blast-radius write action logged via includes/audit_helpers.php's
 * audit_log() (deletes, reset password, bulk actions, settings changes,
 * factory reset, role appointment) across every role in the app.
 *
 * Deliberately does NOT include routine attendance marking — see
 * audit_helpers.php's own header comment for why.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/nav_items.php';
require_once __DIR__ . '/includes/audit_helpers.php';

require_role(['university_rector']);

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
// Filters — Role, Action, User search, Date range — all real SQL WHERE
// conditions via a dynamically built prepared statement, same convention
// already used by admin/students.php / admin/courses.php's own filter bars.
// ---------------------------------------------------------------------
$filterRole = trim((string) ($_GET['role'] ?? ''));
$filterAction = trim((string) ($_GET['action_type'] ?? ''));
$filterUser = trim((string) ($_GET['user'] ?? ''));
$filterFrom = trim((string) ($_GET['from'] ?? ''));
$filterTo = trim((string) ($_GET['to'] ?? ''));

$conditions = [];
$params = [];
$types = '';

if ($filterRole !== '') {
    $conditions[] = 'role = ?';
    $params[] = $filterRole;
    $types .= 's';
}
if ($filterAction !== '') {
    $conditions[] = 'action = ?';
    $params[] = $filterAction;
    $types .= 's';
}
if ($filterUser !== '') {
    $conditions[] = 'username LIKE ?';
    $params[] = '%' . $filterUser . '%';
    $types .= 's';
}
if ($filterFrom !== '') {
    $conditions[] = 'created_at >= ?';
    $params[] = $filterFrom . ' 00:00:00';
    $types .= 's';
}
if ($filterTo !== '') {
    $conditions[] = 'created_at <= ?';
    $params[] = $filterTo . ' 23:59:59';
    $types .= 's';
}

$whereSql = $conditions !== [] ? ' WHERE ' . implode(' AND ', $conditions) : '';

// ---------------------------------------------------------------------
// Pagination
// ---------------------------------------------------------------------
const AUDIT_LOG_PER_PAGE = 30;
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * AUDIT_LOG_PER_PAGE;

$countStmt = $conn->prepare('SELECT COUNT(*) AS c FROM audit_log' . $whereSql);
if ($types !== '') {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$totalRows = (int) ($countStmt->get_result()->fetch_assoc()['c'] ?? 0);
$countStmt->close();
$totalPages = max(1, (int) ceil($totalRows / AUDIT_LOG_PER_PAGE));
$page = min($page, $totalPages);
$offset = ($page - 1) * AUDIT_LOG_PER_PAGE;

$listSql = 'SELECT id, user_id, target_id, username, role, action, target_type, target_label, details, ip_address, created_at
            FROM audit_log' . $whereSql . ' ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?';
$listStmt = $conn->prepare($listSql);
$listParams = $params;
$listParams[] = AUDIT_LOG_PER_PAGE;
$listParams[] = $offset;
$listStmt->bind_param($types . 'ii', ...$listParams);
$listStmt->execute();
$entries = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$listStmt->close();

// Distinct roles/actions actually present, for the two filter dropdowns —
// avoids offering a role/action that has never once been logged.
$distinctRoles = $conn->query('SELECT DISTINCT role FROM audit_log ORDER BY role')->fetch_all(MYSQLI_ASSOC);
$distinctActions = $conn->query('SELECT DISTINCT action FROM audit_log ORDER BY action')->fetch_all(MYSQLI_ASSOC);

function audit_action_badge(string $action): string
{
    $label = AUDIT_ACTION_LABELS[$action] ?? ucwords(str_replace('_', ' ', $action));
    $isDanger = str_contains($action, 'delete') || $action === 'factory_reset';
    $isWarning = str_contains($action, 'reset_password') || $action === 'toggle_status';
    $class = $isDanger ? 'badge-absent' : ($isWarning ? 'badge-warning' : 'badge-present');
    return '<span class="badge-pill ' . $class . '">' . htmlspecialchars($label) . '</span>';
}

$currentQuery = $_GET;
unset($currentQuery['page']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Log — ADMAS Attendance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(BASE_URL) ?>/assets/css/app.css" rel="stylesheet">
</head>
<body>
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="main-content">
        <?php include __DIR__ . '/includes/topbar.php'; ?>

        <div class="page-body">
            <div class="scope-banner">
                <i class="bi bi-shield-check"></i>
                Access scope: Full system — every role's sensitive actions, read only
            </div>

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--admas-text);">Audit Log</h4>
                    <p class="text-muted mb-0">Deletes, password resets, bulk actions, settings changes, factory resets, and role appointments across every role.</p>
                </div>
            </div>

            <div class="admas-card p-4">
                <form method="get" action="<?= htmlspecialchars(BASE_URL) ?>/audit_log.php" class="row g-2 mb-3" id="auditLogFilterForm">
                    <div class="col-sm-6 col-md-2">
                        <select class="form-select form-select-sm" name="role">
                            <option value="">All Roles</option>
                            <?php foreach ($distinctRoles as $r): ?>
                                <option value="<?= htmlspecialchars($r['role']) ?>" <?= $filterRole === $r['role'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(role_label($r['role'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-6 col-md-2">
                        <select class="form-select form-select-sm" name="action_type">
                            <option value="">All Actions</option>
                            <?php foreach ($distinctActions as $a): ?>
                                <option value="<?= htmlspecialchars($a['action']) ?>" <?= $filterAction === $a['action'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(AUDIT_ACTION_LABELS[$a['action']] ?? ucwords(str_replace('_', ' ', $a['action']))) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-6 col-md-2">
                        <input type="text" class="form-control form-control-sm" name="user" placeholder="Username contains..." value="<?= htmlspecialchars($filterUser) ?>" data-live-search>
                    </div>
                    <div class="col-sm-6 col-md-2">
                        <input type="date" class="form-control form-control-sm" name="from" value="<?= htmlspecialchars($filterFrom) ?>" title="From date">
                    </div>
                    <div class="col-sm-6 col-md-2">
                        <input type="date" class="form-control form-control-sm" name="to" value="<?= htmlspecialchars($filterTo) ?>" title="To date">
                    </div>
                    <div class="col-sm-6 col-md-2 d-flex gap-2">
                        <button type="submit" class="btn btn-sm text-white flex-fill" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">Filter</button>
                        <a href="<?= htmlspecialchars(BASE_URL) ?>/audit_log.php" class="btn btn-outline-secondary btn-sm">Clear</a>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table admas-table align-middle">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>User</th>
                                <th>Action</th>
                                <th>Target</th>
                                <th>Details</th>
                                <th>IP Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($entries)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">
                                        <?= $totalRows === 0 && $conditions === [] ? 'No audit entries yet.' : 'No audit entries match the selected filters.' ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($entries as $e): ?>
                                    <tr>
                                        <td class="text-nowrap"><?= htmlspecialchars(date('M j, Y g:i A', strtotime((string) $e['created_at']))) ?></td>
                                        <td>
                                            <div class="fw-semibold" style="color: var(--admas-text);"><?= htmlspecialchars($e['username']) ?></div>
                                            <div class="text-muted small"><?= htmlspecialchars(role_label($e['role'])) ?></div>
                                        </td>
                                        <td><?= audit_action_badge((string) $e['action']) ?></td>
                                        <td>
                                            <?php if ($e['target_type'] !== null): ?>
                                                <span class="text-muted small text-uppercase"><?= htmlspecialchars($e['target_type']) ?></span><br>
                                            <?php endif; ?>
                                            <?= htmlspecialchars((string) ($e['target_label'] ?? '—')) ?>
                                        </td>
                                        <td class="small"><?= htmlspecialchars((string) ($e['details'] ?? '—')) ?></td>
                                        <td class="small text-muted"><?= htmlspecialchars((string) ($e['ip_address'] ?? '—')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <nav aria-label="Audit log pagination">
                        <ul class="pagination pagination-sm mb-0 mt-3">
                            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= htmlspecialchars(BASE_URL . '/audit_log.php?' . http_build_query(array_merge($currentQuery, ['page' => $p]))) ?>"><?= $p ?></a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                    <p class="text-muted small mt-2 mb-0">Showing page <?= $page ?> of <?= $totalPages ?> (<?= number_format($totalRows) ?> total entries)</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
