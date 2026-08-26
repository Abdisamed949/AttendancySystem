<?php
/**
 * Lecturer Check-In / Check-Out report — shared by University Rector
 * (university-wide) / Head of Academic Affairs (university-wide) / Dean
 * (own faculty's lecturers only), lives at the app root not under any one
 * role folder, same pattern as attendance.php/reports.php/notifications.php.
 * Read-only: no write actions live here — checking in/out happens only on
 * the lecturer's own lecturer/checkin.php. Dean's faculty_id is always
 * read from $_SESSION, never trusted from request input.
 *
 * Deliberately raw data only — no automatic "left early" judgement is
 * computed anywhere (confirmed with the project owner before building):
 * Check-In/Check-Out timestamps are shown as-is; the viewing role judges
 * punctuality themselves.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/nav_items.php';
require_once __DIR__ . '/includes/timetable_helpers.php';
require_once __DIR__ . '/includes/semester_helpers.php';
require_once __DIR__ . '/includes/avatar_helpers.php';
require_once __DIR__ . '/vendor/autoload.php';

/**
 * "8:00 AM - 9:30 AM" from the course_offerings row's own scheduled
 * start/end time (the Class Time Table's own values), or "—" when the
 * offering never had a Day/Time slot set at all.
 */
function checkin_scheduled_time_label(array $row): string
{
    if (empty($row['scheduled_start_time']) || empty($row['scheduled_end_time'])) {
        return '—';
    }
    return format_timetable_time($row['scheduled_start_time']) . ' - ' . format_timetable_time($row['scheduled_end_time']);
}

require_role(['university_rector', 'head_academic', 'dean']);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

$conn = db();
$role = current_role();
$currentUser = current_user();

$settings = [];
$settingsResult = $conn->query('SELECT `key`, `value` FROM settings');
if ($settingsResult) {
    while ($row = $settingsResult->fetch_assoc()) {
        $settings[$row['key']] = $row['value'];
    }
}

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
// Lecturer filter options — Dean sees only lecturers whose home
// department belongs to their own faculty; university_rector/head_academic
// see every lecturer university-wide.
// ---------------------------------------------------------------------
if ($role === 'dean') {
    $lecOptStmt = $conn->prepare(
        'SELECT l.id, l.full_name, u.photo_path FROM lecturers l
         JOIN departments d ON d.id = l.department_id
         JOIN users u ON u.id = l.user_id
         WHERE d.faculty_id = ?
         ORDER BY l.full_name'
    );
    $lecOptStmt->bind_param('i', $deanFacultyId);
    $lecOptStmt->execute();
} else {
    $lecOptStmt = $conn->prepare('SELECT l.id, l.full_name, u.photo_path FROM lecturers l JOIN users u ON u.id = l.user_id ORDER BY l.full_name');
    $lecOptStmt->execute();
}
$lecturerOptions = $lecOptStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$lecOptStmt->close();

$filterLecturerId = (int) ($_GET['lecturer_id'] ?? 0);
$filterDateFrom = trim((string) ($_GET['date_from'] ?? ''));
$filterDateTo = trim((string) ($_GET['date_to'] ?? ''));

// ---------------------------------------------------------------------
// Accountability summary — Total Xiiso Check-Ins per Lecturer, so Dean/
// Head of Academic Affairs/University Rector can see at a glance who is
// (and isn't) actually showing up, not just browse a raw timestamp log.
// Uses the exact same lecturer_checkin_eligible_sessions() a lecturer's
// own Check-In page totals itself from (includes/semester_helpers.php),
// so the two views can never disagree on what "N of M sessions" means.
// Scoped to the same $lecturerOptions list already built above (Dean's
// own faculty, or every lecturer for the other two roles) — a lecturer
// with zero current-semester offerings (nothing to be accountable for
// yet) is skipped rather than shown as a confusing 0/0 row.
// ---------------------------------------------------------------------
$lecturerAccountability = [];
foreach ($lecturerOptions as $lecOpt) {
    if ($filterLecturerId > 0 && (int) $lecOpt['id'] !== $filterLecturerId) {
        continue;
    }
    $eligible = lecturer_checkin_eligible_sessions($conn, (int) $lecOpt['id']);
    if (empty($eligible)) {
        continue;
    }
    $checkedIn = count(array_filter($eligible, static fn ($r) => $r['checkin'] !== null));
    $total = count($eligible);
    $lecturerAccountability[] = [
        'lecturer_id' => (int) $lecOpt['id'],
        'lecturer_name' => (string) $lecOpt['full_name'],
        'photo_path' => $lecOpt['photo_path'] ?? null,
        'total' => $total,
        'checked_in' => $checkedIn,
        'pct' => $total > 0 ? round(100 * $checkedIn / $total, 1) : 0.0,
    ];
}
usort($lecturerAccountability, static fn ($a, $b) => $a['pct'] <=> $b['pct']);

// When one specific lecturer is selected, also show their own per-course
// breakdown (the same shape lecturer/checkin.php itself shows them).
$selectedLecturerCourseSummary = $filterLecturerId > 0
    ? lecturer_checkin_course_summary($conn, $filterLecturerId)
    : [];

// ---------------------------------------------------------------------
// Records — Dean's own-faculty scoping is enforced here via the same
// department->faculty JOIN, not just in the filter-option list above, so a
// crafted lecturer_id from another faculty cannot be used to see their
// records.
// ---------------------------------------------------------------------
$conditions = [];
$params = [];
$types = '';

if ($role === 'dean') {
    $conditions[] = 'd.faculty_id = ?';
    $params[] = $deanFacultyId;
    $types .= 'i';
}
if ($filterLecturerId > 0) {
    $conditions[] = 'lc.lecturer_id = ?';
    $params[] = $filterLecturerId;
    $types .= 'i';
}
if ($filterDateFrom !== '') {
    $conditions[] = 'DATE(lc.check_in_at) >= ?';
    $params[] = $filterDateFrom;
    $types .= 's';
}
if ($filterDateTo !== '') {
    $conditions[] = 'DATE(lc.check_in_at) <= ?';
    $params[] = $filterDateTo;
    $types .= 's';
}

$whereSql = empty($conditions) ? '1 = 1' : implode(' AND ', $conditions);

$sql = "SELECT lc.id, lc.check_in_at, lc.check_out_at,
               l.full_name AS lecturer_name, l.staff_no, u.photo_path AS lecturer_photo_path,
               c.code AS course_code, c.name AS course_name,
               sess.session_number, sess.type AS session_type,
               f.name AS faculty_name,
               MIN(co.start_time) AS scheduled_start_time, MIN(co.end_time) AS scheduled_end_time
        FROM lecturer_checkins lc
        JOIN lecturers l ON l.id = lc.lecturer_id
        JOIN users u ON u.id = l.user_id
        JOIN departments d ON d.id = l.department_id
        JOIN faculties f ON f.id = d.faculty_id
        JOIN courses c ON c.id = lc.course_id
        JOIN sessions sess ON sess.id = lc.session_id
        LEFT JOIN course_offerings co ON co.course_id = lc.course_id AND co.lecturer_id = lc.lecturer_id AND co.semester_id = sess.semester_id
        WHERE {$whereSql}
        GROUP BY lc.id, lc.check_in_at, lc.check_out_at, l.full_name, l.staff_no, u.photo_path, c.code, c.name, sess.session_number, sess.type, f.name
        ORDER BY lc.check_in_at DESC
        LIMIT 300";
$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

function checkin_session_label(array $row): string
{
    return match ($row['session_type']) {
        'midterm' => 'Midterm',
        'final' => 'Final',
        default => 'Xiiso ' . (int) $row['session_number'],
    };
}

$currentQuery = [
    'lecturer_id' => $filterLecturerId,
    'date_from' => $filterDateFrom,
    'date_to' => $filterDateTo,
];
$exportExcelUrl = BASE_URL . '/lecturer_checkins.php?' . http_build_query($currentQuery + ['export' => 'excel']);

// ---------------------------------------------------------------------
// Export Excel — sky-blue/navy branded, same styling convention as
// reports.php's own Excel export, applied to whatever filters are
// currently active (same $records the on-screen table shows) — must run
// before any HTML output.
// ---------------------------------------------------------------------
if (($_GET['export'] ?? '') === 'excel') {
    $universityName = $settings['university_name'] ?? 'ADMAS University';
    $scopeLabel = $role === 'dean' ? $deanFacultyName . ' Faculty only' : 'Full system — all faculties';

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Check-Ins');

    $sheet->setCellValue('A1', $universityName);
    $sheet->setCellValue('A2', 'Lecturer Check-In Report');
    $sheet->setCellValue('A3', 'Scope: ' . $scopeLabel . '   |   Generated: ' . date('Y-m-d H:i'));
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(11);
    $sheet->getStyle('A3')->getFont()->setItalic(true)->setSize(9);

    $columns = $role !== 'dean'
        ? ['Lecturer', 'Staff No', 'Faculty', 'Course', 'Xiiso', 'Scheduled Time', 'Date', 'Check-In', 'Check-Out']
        : ['Lecturer', 'Staff No', 'Course', 'Xiiso', 'Scheduled Time', 'Date', 'Check-In', 'Check-Out'];
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
    foreach ($records as $r) {
        $col = 'A';
        $values = [$r['lecturer_name'], $r['staff_no']];
        if ($role !== 'dean') {
            $values[] = $r['faculty_name'];
        }
        $values[] = $r['course_code'] . ' — ' . $r['course_name'];
        $values[] = checkin_session_label($r);
        $values[] = checkin_scheduled_time_label($r);
        $values[] = date('Y-m-d', strtotime($r['check_in_at']));
        $values[] = date('g:i A', strtotime($r['check_in_at']));
        $values[] = $r['check_out_at'] ? date('g:i A', strtotime($r['check_out_at'])) : 'Not checked out';
        foreach ($values as $value) {
            $sheet->setCellValue($col . $rowIndex, $value);
            $col++;
        }
        $sheet->getStyle('A' . $rowIndex . ':' . $lastCol . $rowIndex)
            ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($rowIndex % 2 === 0 ? 'F3F6FB' : 'FFFFFF');
        $rowIndex++;
    }

    foreach (range('A', $lastCol) as $colDim) {
        $sheet->getColumnDimension($colDim)->setAutoSize(true);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="lecturer_checkin_report.xlsx"');
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
    <title>Lecturer Check-In Report — ADMAS Attendance System</title>
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
                <?php if ($role === 'dean'): ?>
                    Access scope: <?= htmlspecialchars($deanFacultyName) ?> Faculty only
                <?php else: ?>
                    Access scope: Full system — all faculties
                <?php endif; ?>
            </div>

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--admas-text);"><i class="bi bi-door-open-fill" style="color: var(--admas-sky);"></i> Lecturer Check-In Report</h4>
                    <p class="text-muted mb-0">Arrival and departure times lecturers have recorded per class session.</p>
                </div>
            </div>

            <div class="admas-card p-4 mb-3" style="border: 2px solid var(--admas-sky);">
                <form method="get" action="<?= htmlspecialchars(BASE_URL) ?>/lecturer_checkins.php" class="row g-2 mb-0">
                    <div class="col-sm-6 col-md-4">
                        <select class="form-select form-select-sm" name="lecturer_id">
                            <option value="0">All Lecturers</option>
                            <?php foreach ($lecturerOptions as $l): ?>
                                <option value="<?= (int) $l['id'] ?>" <?= $filterLecturerId === (int) $l['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($l['full_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <input type="date" class="form-control form-control-sm" name="date_from" value="<?= htmlspecialchars($filterDateFrom) ?>" placeholder="From">
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <input type="date" class="form-control form-control-sm" name="date_to" value="<?= htmlspecialchars($filterDateTo) ?>" placeholder="To">
                    </div>
                    <div class="col-sm-6 col-md-2">
                        <button type="submit" class="btn btn-sm w-100 text-white" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                            <i class="bi bi-funnel"></i> Filter
                        </button>
                    </div>
                </form>
                <div class="mt-2 text-end">
                    <a href="<?= htmlspecialchars($exportExcelUrl) ?>" class="btn btn-sm text-white" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                        <i class="bi bi-file-earmark-excel"></i> Export Excel
                    </a>
                </div>
            </div>

            <?php if (!empty($lecturerAccountability)): ?>
                <div class="admas-card p-3 mb-3">
                    <h6 class="fw-bold mb-2 small text-uppercase text-muted">Total Xiiso Check-Ins per Lecturer</h6>
                    <p class="text-muted small mb-2">How many class sessions each lecturer has actually checked in for, out of how many were expected so far this semester — lowest attendance first.</p>
                    <div class="table-responsive">
                        <table class="table admas-table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Lecturer</th>
                                    <th>Checked In</th>
                                    <th>Attendance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($lecturerAccountability as $la): ?>
                                    <tr>
                                        <td>
                                            <a href="<?= htmlspecialchars(BASE_URL) ?>/lecturer_checkins.php?lecturer_id=<?= $la['lecturer_id'] ?>" style="color: inherit; text-decoration: none;">
                                                <?php render_person_avatar_cell($la['photo_path'], $la['lecturer_name']); ?>
                                            </a>
                                        </td>
                                        <td><?= $la['checked_in'] ?> / <?= $la['total'] ?> Xiiso</td>
                                        <td style="min-width: 160px;">
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="progress flex-grow-1" style="height: 8px;">
                                                    <div class="progress-bar" role="progressbar" style="width: <?= $la['pct'] ?>%; background-color: <?= $la['pct'] >= 90 ? '#16a34a' : ($la['pct'] >= 70 ? '#d97706' : '#dc2626') ?>;"></div>
                                                </div>
                                                <span class="small text-muted"><?= $la['pct'] ?>%</span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($selectedLecturerCourseSummary)): ?>
                <div class="admas-card p-3 mb-3">
                    <h6 class="fw-bold mb-2 small text-uppercase text-muted">
                        Per-Course Breakdown — <?= htmlspecialchars($lecturerOptions[array_search($filterLecturerId, array_column($lecturerOptions, 'id'))]['full_name'] ?? '') ?>
                    </h6>
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
                                <?php foreach ($selectedLecturerCourseSummary as $cs): ?>
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

            <div class="admas-card p-4" style="border: 2px solid var(--admas-sky);">
                <div class="table-responsive">
                    <table class="table admas-table align-middle">
                        <thead>
                            <tr>
                                <th>Lecturer</th>
                                <?php if ($role !== 'dean'): ?><th>Faculty</th><?php endif; ?>
                                <th>Course</th>
                                <th>Xiiso</th>
                                <th>Scheduled Time</th>
                                <th>Date</th>
                                <th>Check-In</th>
                                <th>Check-Out</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($records)): ?>
                                <tr>
                                    <td colspan="<?= $role !== 'dean' ? 8 : 7 ?>" class="text-center text-muted py-4">No check-in records match the current filters.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($records as $r): ?>
                                    <tr>
                                        <td><?php render_person_avatar_cell($r['lecturer_photo_path'], $r['lecturer_name'], 'Staff No: ' . $r['staff_no']); ?></td>
                                        <?php if ($role !== 'dean'): ?><td><?= htmlspecialchars($r['faculty_name']) ?></td><?php endif; ?>
                                        <td><?= htmlspecialchars($r['course_code'] . ' — ' . $r['course_name']) ?></td>
                                        <td><?= htmlspecialchars(checkin_session_label($r)) ?></td>
                                        <td class="small text-muted"><?= htmlspecialchars(checkin_scheduled_time_label($r)) ?></td>
                                        <td><?= htmlspecialchars(date('M j, Y', strtotime($r['check_in_at']))) ?></td>
                                        <td><?= htmlspecialchars(date('g:i A', strtotime($r['check_in_at']))) ?></td>
                                        <td>
                                            <?php if ($r['check_out_at']): ?>
                                                <?= htmlspecialchars(date('g:i A', strtotime($r['check_out_at']))) ?>
                                            <?php else: ?>
                                                <span class="badge-pill badge-warning">Not checked out</span>
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
