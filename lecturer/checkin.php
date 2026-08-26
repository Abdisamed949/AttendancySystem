<?php
/**
 * Lecturer Check-In / Check-Out — a lecturer's own arrival/departure log,
 * recorded per (course, Xiiso session) they actually teach, kept in the
 * new `lecturer_checkins` table — a distinct concept from `attendance`
 * (which records STUDENT presence, not the lecturer's own). Scoped to
 * this lecturer's own CURRENT-semester course offerings only (own
 * lecturers.id resolved from current_user()['id'] -> lecturers.user_id,
 * never from request input) — a lecturer can only check in/out of a
 * session they actually hold a current offering for.
 *
 * One row per (lecturer, course, session) — check_in_at is set once and
 * never re-set; check_out_at starts NULL and is filled in on Check Out.
 * No "left early" judgement is made anywhere in this feature — Head of
 * Academic Affairs/Dean/University Rector see the raw timestamps and
 * judge for themselves (see lecturer_checkins.php and
 * admin/lecturer_view.php's own "Check-In/Out History" section).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';
require_once __DIR__ . '/../includes/semester_helpers.php';
require_once __DIR__ . '/../includes/attendance_helpers.php';
require_once __DIR__ . '/../includes/timetable_helpers.php';
require_once __DIR__ . '/../vendor/autoload.php';

require_role(['lecturer']);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * "8:00 AM - 9:30 AM" from this course's own scheduled Class Time Table
 * slot (course_offerings.start_time/end_time) — the officially scheduled
 * time for this Xiiso, shown alongside the actual Check-In/Check-Out
 * timestamps so a lecturer (and anyone reviewing the record later) can see
 * both at a glance. "—" when this offering has no Day/Time slot set yet.
 */
function checkin_scheduled_time_label(array $row): string
{
    if (empty($row['scheduled_start_time']) || empty($row['scheduled_end_time'])) {
        return '—';
    }
    return format_timetable_time($row['scheduled_start_time']) . ' - ' . format_timetable_time($row['scheduled_end_time']);
}

$conn = db();
$currentUser = current_user();

$settings = [];
$settingsResult = $conn->query('SELECT `key`, `value` FROM settings');
if ($settingsResult) {
    while ($row = $settingsResult->fetch_assoc()) {
        $settings[$row['key']] = $row['value'];
    }
}

// ---------------------------------------------------------------------
// Own lecturers.id (never trusted from input)
// ---------------------------------------------------------------------
$lecStmt = $conn->prepare('SELECT id FROM lecturers WHERE user_id = ?');
$lecStmt->bind_param('i', $currentUser['id']);
$lecStmt->execute();
$lecRow = $lecStmt->get_result()->fetch_assoc();
$lecStmt->close();
$lecturerId = $lecRow ? (int) $lecRow['id'] : 0;

$successMessage = '';
$errorMessage = '';
if (isset($_SESSION['flash_success'])) {
    $successMessage = (string) $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (isset($_SESSION['flash_error'])) {
    $errorMessage = (string) $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// lecturer_owns_current_session() now lives in includes/attendance_helpers.php,
// shared with ajax/lecturer_checkin_action.php (the AJAX path both action
// buttons below actually submit through — see assets/js/lecturer_checkin.js).
// This POST handler stays as the no-JS fallback (a form still posts here
// correctly if JS is disabled/blocked).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'check_in') {
        $courseId = (int) ($_POST['course_id'] ?? 0);
        $sessionId = (int) ($_POST['session_id'] ?? 0);

        if (!lecturer_owns_current_session($conn, $lecturerId, $courseId, $sessionId)) {
            $_SESSION['flash_error'] = 'Invalid course/session — you can only check in to a session you currently teach.';
        } else {
            $existsStmt = $conn->prepare('SELECT id FROM lecturer_checkins WHERE lecturer_id = ? AND course_id = ? AND session_id = ?');
            $existsStmt->bind_param('iii', $lecturerId, $courseId, $sessionId);
            $existsStmt->execute();
            $exists = $existsStmt->get_result()->fetch_assoc();
            $existsStmt->close();

            if ($exists) {
                $_SESSION['flash_error'] = 'You have already checked in for this session.';
            } else {
                $insStmt = $conn->prepare('INSERT INTO lecturer_checkins (lecturer_id, course_id, session_id, check_in_at) VALUES (?, ?, ?, NOW())');
                $insStmt->bind_param('iii', $lecturerId, $courseId, $sessionId);
                $insStmt->execute();
                $insStmt->close();
                $_SESSION['flash_success'] = 'Checked in at ' . date('g:i A') . '.';
            }
        }
    } elseif ($action === 'check_out') {
        $checkinId = (int) ($_POST['checkin_id'] ?? 0);

        // Scoped by lecturer_id in the same UPDATE — a crafted checkin_id
        // belonging to another lecturer can never be checked out from here.
        $updStmt = $conn->prepare(
            'UPDATE lecturer_checkins SET check_out_at = NOW()
             WHERE id = ? AND lecturer_id = ? AND check_out_at IS NULL'
        );
        $updStmt->bind_param('ii', $checkinId, $lecturerId);
        $updStmt->execute();
        $affected = $updStmt->affected_rows;
        $updStmt->close();

        if ($affected > 0) {
            $_SESSION['flash_success'] = 'Checked out at ' . date('g:i A') . '.';
        } else {
            $_SESSION['flash_error'] = 'Could not check out — already checked out, or not your session.';
        }
    }

    redirect_to('lecturer/checkin.php');
}

// ---------------------------------------------------------------------
// This lecturer's own current-semester offerings, one row per (course,
// session) whose date has already arrived (today or earlier) — a
// check-in/out list has no reason to show sessions that haven't happened
// yet. Most recent first, so today's sessions sit at the top.
// ---------------------------------------------------------------------
$today = date('Y-m-d');

$offeringsStmt = $conn->prepare(
    "SELECT c.id AS course_id, c.code, c.name, se.id AS semester_id, se.name AS semester_name,
            co.start_time AS scheduled_start_time, co.end_time AS scheduled_end_time
     FROM course_offerings co
     JOIN courses c ON c.id = co.course_id
     JOIN semesters se ON se.id = co.semester_id AND se.status = 'current'
     WHERE co.lecturer_id = ?
     ORDER BY c.code"
);
$offeringsStmt->bind_param('i', $lecturerId);
$offeringsStmt->execute();
$offerings = $offeringsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$offeringsStmt->close();

// This lecturer's entire lecturer_checkins history, fetched once and
// looked up in-memory below (keyed by "courseId-sessionId") instead of
// running one query per (course, session) row inside the loop — a real
// N+1 pattern that would grow with every course/session a lecturer holds.
$checkinsByKey = [];
$allCheckinsStmt = $conn->prepare('SELECT id, course_id, session_id, check_in_at, check_out_at FROM lecturer_checkins WHERE lecturer_id = ?');
$allCheckinsStmt->bind_param('i', $lecturerId);
$allCheckinsStmt->execute();
$allCheckinsResult = $allCheckinsStmt->get_result();
while ($ci = $allCheckinsResult->fetch_assoc()) {
    $checkinsByKey[$ci['course_id'] . '-' . $ci['session_id']] = $ci;
}
$allCheckinsStmt->close();

$rows = [];
$sessionsBySemesterId = [];
foreach ($offerings as $off) {
    $semesterId = (int) $off['semester_id'];
    if (!isset($sessionsBySemesterId[$semesterId])) {
        $sessionsBySemesterId[$semesterId] = get_sessions_for_semester($conn, $semesterId);
    }

    foreach ($sessionsBySemesterId[$semesterId] as $session) {
        if ($session['date'] === null || $session['date'] > $today) {
            continue;
        }

        $checkin = $checkinsByKey[$off['course_id'] . '-' . $session['id']] ?? null;

        $rows[] = [
            'course_id' => (int) $off['course_id'],
            'course_label' => $off['code'] . ' — ' . $off['name'],
            'semester_name' => $off['semester_name'],
            'session_id' => (int) $session['id'],
            'session_label' => $session['label'],
            'session_date' => $session['date'],
            'scheduled_start_time' => $off['scheduled_start_time'],
            'scheduled_end_time' => $off['scheduled_end_time'],
            'checkin' => $checkin,
        ];
    }
}

usort($rows, static fn ($a, $b) => $b['session_date'] <=> $a['session_date']);

// ---------------------------------------------------------------------
// Summary counts for the KPI strip at the top of the page.
// ---------------------------------------------------------------------
$totalSessionsCount = count($rows);
$checkedInCount = 0;
$notCheckedInCount = 0;
foreach ($rows as $r) {
    if ($r['checkin']) {
        $checkedInCount++;
    } else {
        $notCheckedInCount++;
    }
}

// ---------------------------------------------------------------------
// Total Xiiso Check-Ins by Course — same $rows this page already built,
// rolled up per course, so it's always in agreement with the row-level
// table below and the KPI strip above (never a second, independently
// computed source of truth). This is what makes the lecturer's own
// check-in record something concrete and countable per course, instead
// of just one flat overall number.
// ---------------------------------------------------------------------
$courseSummary = [];
foreach ($rows as $r) {
    $cid = $r['course_id'];
    if (!isset($courseSummary[$cid])) {
        $courseSummary[$cid] = ['course_label' => $r['course_label'], 'total' => 0, 'checked_in' => 0];
    }
    $courseSummary[$cid]['total']++;
    if ($r['checkin']) {
        $courseSummary[$cid]['checked_in']++;
    }
}
foreach ($courseSummary as &$cs) {
    $cs['pct'] = $cs['total'] > 0 ? round(100 * $cs['checked_in'] / $cs['total'], 1) : 0.0;
}
unset($cs);
$courseSummary = array_values($courseSummary);

// ---------------------------------------------------------------------
// Export Excel — sky-blue/navy branded, same styling convention as
// reports.php's own Excel export (university name, navy header row with
// white bold text, sky-blue accent border) — must run before any HTML
// output.
// ---------------------------------------------------------------------
if (($_GET['export'] ?? '') === 'excel') {
    $universityName = $settings['university_name'] ?? 'ADMAS University';

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Check-In');

    $sheet->setCellValue('A1', $universityName);
    $sheet->setCellValue('A2', 'Lecturer Check-In Log');
    $sheet->setCellValue('A3', ((string) ($currentUser['full_name'] ?? '')) . '   |   Generated: ' . date('Y-m-d H:i'));
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(11);
    $sheet->getStyle('A3')->getFont()->setItalic(true)->setSize(9);

    $columns = ['Course', 'Semester', 'Xiiso', 'Scheduled Time', 'Date', 'Check-In', 'Check-Out', 'Status'];
    $headerRow = 5;
    $colLetter = 'A';
    foreach ($columns as $col) {
        $sheet->setCellValue($colLetter . $headerRow, $col);
        $colLetter++;
    }
    $lastCol = chr(ord('A') + count($columns) - 1);
    $sheet->getStyle('A' . $headerRow . ':' . $lastCol . $headerRow)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $sheet->getStyle('A' . $headerRow . ':' . $lastCol . $headerRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0B1F3A');

    $rowIndex = $headerRow + 1;
    foreach ($rows as $r) {
        $c = $r['checkin'];
        $sheet->setCellValue('A' . $rowIndex, $r['course_label']);
        $sheet->setCellValue('B' . $rowIndex, $r['semester_name']);
        $sheet->setCellValue('C' . $rowIndex, $r['session_label']);
        $sheet->setCellValue('D' . $rowIndex, checkin_scheduled_time_label($r));
        $sheet->setCellValue('E' . $rowIndex, date('Y-m-d', strtotime($r['session_date'])));
        $sheet->setCellValue('F' . $rowIndex, $c ? date('g:i A', strtotime($c['check_in_at'])) : '');
        $sheet->setCellValue('G' . $rowIndex, ($c && $c['check_out_at']) ? date('g:i A', strtotime($c['check_out_at'])) : '');
        $status = !$c ? 'Not checked in' : ($c['check_out_at'] ? 'Done' : 'Checked in, not out');
        $sheet->setCellValue('H' . $rowIndex, $status);
        $sheet->getStyle('A' . $rowIndex . ':' . $lastCol . $rowIndex)
            ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($rowIndex % 2 === 0 ? 'F3F6FB' : 'FFFFFF');
        $rowIndex++;
    }

    foreach (range('A', $lastCol) as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="lecturer_checkin_log.xlsx"');
    header('Cache-Control: max-age=0');

    (new XlsxWriter($spreadsheet))->save('php://output');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lecturer Check-In — ADMAS Attendance System</title>
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
                Access scope: Your own check-in/out record only
            </div>

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--admas-text);"><i class="bi bi-door-open-fill" style="color: var(--admas-sky);"></i> Lecturer Check-In</h4>
                    <p class="text-muted mb-0">Record when you arrive and leave for each class session you teach.</p>
                </div>
                <a href="<?= htmlspecialchars(BASE_URL) ?>/lecturer/checkin.php?export=excel" class="btn btn-sm text-white" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                    <i class="bi bi-file-earmark-excel"></i> Export Excel
                </a>
            </div>

            <div id="checkinFlash">
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
            </div>

            <!-- Summary strip -->
            <div class="row g-3 mb-3">
                <div class="col-sm-4">
                    <div class="admas-card kpi-card accent-sky h-100">
                        <div class="kpi-icon bg-sky"><i class="bi bi-calendar2-week"></i></div>
                        <div>
                            <div class="kpi-value"><?= number_format($totalSessionsCount) ?></div>
                            <div class="kpi-label">Sessions So Far</div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="admas-card kpi-card accent-green h-100">
                        <div class="kpi-icon bg-green"><i class="bi bi-check-circle-fill"></i></div>
                        <div>
                            <div class="kpi-value" id="checkinKpiCheckedIn"><?= number_format($checkedInCount) ?></div>
                            <div class="kpi-label">Checked In</div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="admas-card kpi-card accent-amber h-100">
                        <div class="kpi-icon bg-amber"><i class="bi bi-exclamation-circle-fill"></i></div>
                        <div>
                            <div class="kpi-value" id="checkinKpiPending"><?= number_format($notCheckedInCount) ?></div>
                            <div class="kpi-label">Not Checked In Yet</div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!empty($courseSummary)): ?>
                <div class="admas-card p-3 mb-3">
                    <h6 class="fw-bold mb-2 small text-uppercase text-muted">Total Xiiso Check-Ins by Course</h6>
                    <div class="table-responsive">
                        <table class="table admas-table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Course</th>
                                    <th>Checked In</th>
                                    <th>Attendance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($courseSummary as $cs): ?>
                                    <tr>
                                        <td class="fw-semibold" style="color: var(--admas-text);"><?= htmlspecialchars($cs['course_label']) ?></td>
                                        <td><?= $cs['checked_in'] ?> / <?= $cs['total'] ?> Xiiso</td>
                                        <td style="min-width: 160px;">
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="progress flex-grow-1" style="height: 8px;">
                                                    <div class="progress-bar" role="progressbar" style="width: <?= $cs['pct'] ?>%; background-color: <?= $cs['pct'] >= 90 ? '#16a34a' : ($cs['pct'] >= 70 ? '#d97706' : '#dc2626') ?>;"></div>
                                                </div>
                                                <span class="small text-muted"><?= $cs['pct'] ?>%</span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <div class="admas-card p-4">
                <div class="table-responsive">
                    <table class="table admas-table align-middle mb-0" id="checkinTable">
                        <thead>
                            <tr>
                                <th>Course</th>
                                <th>Semester</th>
                                <th>Xiiso</th>
                                <th>Scheduled Time</th>
                                <th>Date</th>
                                <th>Check-In</th>
                                <th>Check-Out</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-4">No sessions yet for your current courses.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($rows as $r): ?>
                                    <?php $c = $r['checkin']; ?>
                                    <tr data-course-id="<?= (int) $r['course_id'] ?>" data-session-id="<?= (int) $r['session_id'] ?>">
                                        <td class="fw-semibold" style="color: var(--admas-text);"><?= htmlspecialchars($r['course_label']) ?></td>
                                        <td><?= htmlspecialchars($r['semester_name']) ?></td>
                                        <td><?= htmlspecialchars($r['session_label']) ?></td>
                                        <td class="small text-muted"><?= htmlspecialchars(checkin_scheduled_time_label($r)) ?></td>
                                        <td><?= htmlspecialchars(date('M j, Y', strtotime($r['session_date']))) ?></td>
                                        <td class="checkin-cell-in">
                                            <?php if ($c): ?>
                                                <span class="badge-pill badge-active"><i class="bi bi-box-arrow-in-right"></i> <?= htmlspecialchars(date('g:i A', strtotime($c['check_in_at']))) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="checkin-cell-out">
                                            <?php if ($c && $c['check_out_at']): ?>
                                                <span class="badge-pill badge-neutral"><i class="bi bi-box-arrow-right"></i> <?= htmlspecialchars(date('g:i A', strtotime($c['check_out_at']))) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="checkin-cell-action">
                                            <?php if (!$c): ?>
                                                <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/lecturer/checkin.php" class="d-inline checkin-action-form" data-action="check_in">
                                                    <input type="hidden" name="action" value="check_in">
                                                    <input type="hidden" name="course_id" value="<?= $r['course_id'] ?>">
                                                    <input type="hidden" name="session_id" value="<?= $r['session_id'] ?>">
                                                    <button type="submit" class="btn btn-sm text-white rounded-pill px-3" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                                                        <i class="bi bi-box-arrow-in-right"></i> Check In
                                                    </button>
                                                </form>
                                            <?php elseif (!$c['check_out_at']): ?>
                                                <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/lecturer/checkin.php" class="d-inline checkin-action-form" data-action="check_out">
                                                    <input type="hidden" name="action" value="check_out">
                                                    <input type="hidden" name="checkin_id" value="<?= (int) $c['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3">
                                                        <i class="bi bi-box-arrow-right"></i> Check Out
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="badge-pill badge-active"><i class="bi bi-check2-circle"></i> Done</span>
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
    <script>window.ADMAS_BASE_URL = <?= json_encode(BASE_URL, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
    <script src="<?= htmlspecialchars(BASE_URL) ?>/assets/js/lecturer_checkin.js"></script>
</body>
</html>
