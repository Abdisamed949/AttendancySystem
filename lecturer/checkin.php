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
require_once __DIR__ . '/../vendor/autoload.php';

require_role(['lecturer']);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

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

// ---------------------------------------------------------------------
// A (course_id, session_id) pair is only ever a legitimate check-in/out
// target if it belongs to one of this lecturer's own CURRENT-semester
// offerings — the real security/correctness boundary for both POST
// actions below, not just a display filter.
// ---------------------------------------------------------------------
function lecturer_owns_current_session(mysqli $conn, int $lecturerId, int $courseId, int $sessionId): bool
{
    $stmt = $conn->prepare(
        "SELECT sess.id
         FROM sessions sess
         JOIN semesters se ON se.id = sess.semester_id AND se.status = 'current'
         JOIN course_offerings co ON co.semester_id = se.id AND co.course_id = ? AND co.lecturer_id = ?
         WHERE sess.id = ?"
    );
    $stmt->bind_param('iii', $courseId, $lecturerId, $sessionId);
    $stmt->execute();
    $found = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $found !== null;
}

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
    "SELECT c.id AS course_id, c.code, c.name, se.id AS semester_id, se.name AS semester_name
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

        $checkinStmt = $conn->prepare('SELECT id, check_in_at, check_out_at FROM lecturer_checkins WHERE lecturer_id = ? AND course_id = ? AND session_id = ?');
        $checkinStmt->bind_param('iii', $lecturerId, $off['course_id'], $session['id']);
        $checkinStmt->execute();
        $checkin = $checkinStmt->get_result()->fetch_assoc();
        $checkinStmt->close();

        $rows[] = [
            'course_id' => (int) $off['course_id'],
            'course_label' => $off['code'] . ' — ' . $off['name'],
            'semester_name' => $off['semester_name'],
            'session_id' => (int) $session['id'],
            'session_label' => $session['label'],
            'session_date' => $session['date'],
            'checkin' => $checkin,
        ];
    }
}

usort($rows, static fn ($a, $b) => $b['session_date'] <=> $a['session_date']);

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

    $columns = ['Course', 'Semester', 'Xiiso', 'Date', 'Check-In', 'Check-Out', 'Status'];
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
        $sheet->setCellValue('D' . $rowIndex, date('Y-m-d', strtotime($r['session_date'])));
        $sheet->setCellValue('E' . $rowIndex, $c ? date('g:i A', strtotime($c['check_in_at'])) : '');
        $sheet->setCellValue('F' . $rowIndex, ($c && $c['check_out_at']) ? date('g:i A', strtotime($c['check_out_at'])) : '');
        $status = !$c ? 'Not checked in' : ($c['check_out_at'] ? 'Done' : 'Checked in, not out');
        $sheet->setCellValue('G' . $rowIndex, $status);
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

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--admas-text);"><i class="bi bi-door-open-fill" style="color: var(--admas-sky);"></i> Lecturer Check-In</h4>
                    <p class="text-muted mb-0">Record when you arrive and leave for each class session you teach.</p>
                </div>
                <a href="<?= htmlspecialchars(BASE_URL) ?>/lecturer/checkin.php?export=excel" class="btn btn-sm text-white" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                    <i class="bi bi-file-earmark-excel"></i> Export Excel
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

            <div class="admas-card p-4" style="border: 2px solid var(--admas-sky);">
                <div class="table-responsive">
                    <table class="table admas-table align-middle">
                        <thead>
                            <tr>
                                <th>Course</th>
                                <th>Semester</th>
                                <th>Xiiso</th>
                                <th>Date</th>
                                <th>Check-In</th>
                                <th>Check-Out</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">No sessions yet for your current courses.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($rows as $r): ?>
                                    <?php $c = $r['checkin']; ?>
                                    <tr>
                                        <td class="fw-semibold" style="color: var(--admas-text);"><?= htmlspecialchars($r['course_label']) ?></td>
                                        <td><?= htmlspecialchars($r['semester_name']) ?></td>
                                        <td><?= htmlspecialchars($r['session_label']) ?></td>
                                        <td><?= htmlspecialchars(date('M j, Y', strtotime($r['session_date']))) ?></td>
                                        <td>
                                            <?php if ($c): ?>
                                                <?= htmlspecialchars(date('g:i A', strtotime($c['check_in_at']))) ?>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($c && $c['check_out_at']): ?>
                                                <?= htmlspecialchars(date('g:i A', strtotime($c['check_out_at']))) ?>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!$c): ?>
                                                <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/lecturer/checkin.php" class="d-inline">
                                                    <input type="hidden" name="action" value="check_in">
                                                    <input type="hidden" name="course_id" value="<?= $r['course_id'] ?>">
                                                    <input type="hidden" name="session_id" value="<?= $r['session_id'] ?>">
                                                    <button type="submit" class="btn btn-sm text-white" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                                                        <i class="bi bi-box-arrow-in-right"></i> Check In
                                                    </button>
                                                </form>
                                            <?php elseif (!$c['check_out_at']): ?>
                                                <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/lecturer/checkin.php" class="d-inline">
                                                    <input type="hidden" name="action" value="check_out">
                                                    <input type="hidden" name="checkin_id" value="<?= (int) $c['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                                        <i class="bi bi-box-arrow-right"></i> Check Out
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="badge-pill badge-active">Done</span>
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
</body>
</html>
