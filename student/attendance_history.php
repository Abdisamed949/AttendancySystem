<?php
/**
 * Attendance History (Student only) — every semester this student has
 * attendance records in, most recent first, each with an overall % and a
 * per-course breakdown. Unlike My Courses (which only shows the current
 * academic year), this reads directly off attendance -> sessions ->
 * semesters, so closed/past semesters show up here too. Resolved via
 * current_user()['id'] -> students.user_id, never from request input.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';
require_once __DIR__ . '/../includes/attendance_helpers.php';
require_once __DIR__ . '/../includes/export_helpers.php';

require_role(['student']);

$conn = db();
$currentUser = current_user();

$settings = [];
$settingsResult = $conn->query('SELECT `key`, `value` FROM settings');
if ($settingsResult) {
    while ($row = $settingsResult->fetch_assoc()) {
        $settings[$row['key']] = $row['value'];
    }
}
$minAttendancePct = (float) ($settings['min_attendance_pct'] ?? 75);

$ownStmt = $conn->prepare('SELECT id, student_no, full_name FROM students WHERE user_id = ?');
$ownStmt->bind_param('i', $currentUser['id']);
$ownStmt->execute();
$ownRow = $ownStmt->get_result()->fetch_assoc();
$ownStmt->close();
$ownStudentId = $ownRow ? (int) $ownRow['id'] : 0;

// ---------------------------------------------------------------------
// One row per semester this student has any attendance in, most recent
// (by start_date) first.
// ---------------------------------------------------------------------
$semesters = [];
if ($ownStudentId > 0) {
    $stmt = $conn->prepare(
        "SELECT se.id, se.name, se.start_date, se.end_date, se.is_current,
                SUM(a.status = 'present') AS present_count,
                SUM(a.status = 'absent') AS absent_count,
                COUNT(a.id) AS total_marks
         FROM attendance a
         JOIN sessions sess ON sess.id = a.session_id AND sess.type = 'regular'
         JOIN semesters se ON se.id = sess.semester_id
         WHERE a.student_id = ?
         GROUP BY se.id, se.name, se.start_date, se.end_date, se.is_current
         ORDER BY se.start_date DESC"
    );
    $stmt->bind_param('i', $ownStudentId);
    $stmt->execute();
    $semesters = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// ---------------------------------------------------------------------
// Per-course breakdown within each semester, grouped in PHP by semester_id.
// ---------------------------------------------------------------------
$coursesBySemester = [];
if ($ownStudentId > 0 && !empty($semesters)) {
    $stmt = $conn->prepare(
        "SELECT se.id AS semester_id, c.code, c.name,
                LEAST(10, SUM(a.status = 'present')) AS present_count,
                LEAST(10, SUM(a.status = 'absent')) AS absent_count,
                COUNT(a.id) AS total_marks
         FROM attendance a
         JOIN sessions sess ON sess.id = a.session_id AND sess.type = 'regular'
         JOIN semesters se ON se.id = sess.semester_id
         JOIN courses c ON c.id = a.course_id
         WHERE a.student_id = ?
         GROUP BY se.id, c.id, c.code, c.name
         ORDER BY c.code"
    );
    $stmt->bind_param('i', $ownStudentId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $coursesBySemester[(int) $row['semester_id']][] = $row;
    }
    $stmt->close();
}

// Course-level score: the SQL above already caps present_count at
// ATTENDANCE_MAX_SCORE (10) and only counts *regular* sessions — this just
// applies the "no marks yet = null (not 0%)" rule on top.
function pct(int $present, int $total): ?float
{
    return $total > 0 ? (float) $present : null;
}

// Semester-level "overall": the average of each of that semester's courses'
// own out-of-10 score (only courses with real marks) — not a pooled ratio,
// since a student taking several courses could otherwise show a score
// above 10. Matches the same "average of capped course scores" semantics
// used on student/dashboard.php.
function semester_average_score(array $courseRows): ?float
{
    $scores = [];
    foreach ($courseRows as $c) {
        if ((int) $c['total_marks'] > 0) {
            $scores[] = min(ATTENDANCE_MAX_SCORE, (int) $c['present_count']);
        }
    }

    return empty($scores) ? null : round(array_sum($scores) / count($scores), 1);
}

// ---------------------------------------------------------------------
// Export (PDF/Excel) — flattens every semester's per-course rows into one
// table, most recent semester first, same data already shown on screen.
// Must run before any HTML output.
// ---------------------------------------------------------------------
$exportFormat = (string) ($_GET['export'] ?? '');
if (($exportFormat === 'excel' || $exportFormat === 'pdf') && $ownRow) {
    $exportColumns = [
        ['key' => 'semester', 'label' => 'Semester'],
        ['key' => 'course', 'label' => 'Course'],
        ['key' => 'present', 'label' => 'Present'],
        ['key' => 'absent', 'label' => 'Absent'],
        ['key' => 'total', 'label' => 'Total Marks'],
        ['key' => 'pct', 'label' => 'Attendance %'],
    ];
    $exportRows = [];
    foreach ($semesters as $sem) {
        $courses = $coursesBySemester[(int) $sem['id']] ?? [];
        foreach ($courses as $c) {
            $cTotal = (int) $c['total_marks'];
            $cPct = pct((int) $c['present_count'], $cTotal);
            $exportRows[] = [
                'semester' => $sem['name'],
                'course' => $c['code'] . ' — ' . $c['name'],
                'present' => (int) $c['present_count'],
                'absent' => (int) $c['absent_count'],
                'total' => $cTotal,
                'pct' => $cPct === null ? '—' : number_format($cPct, 1) . '%',
            ];
        }
    }

    $title = 'Attendance History — ' . $ownRow['full_name'] . ' (' . $ownRow['student_no'] . ')';
    $subtitle = count($semesters) . ' semester' . (count($semesters) === 1 ? '' : 's');
    $filename = 'attendance_history_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $ownRow['student_no']);

    if ($exportFormat === 'excel') {
        stream_table_as_excel($exportColumns, $exportRows, $title, $subtitle, $filename);
    }
    $branding = export_branding($conn);
    stream_table_as_pdf($exportColumns, $exportRows, $title, $subtitle, $filename, $branding['university_name'], $branding['campus_line'], $branding['logo_base64']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance History — ADMAS Attendance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(BASE_URL) ?>/assets/css/app.css" rel="stylesheet">
    <style>
        .attendance-history-wrap {
            max-width: 900px;
            margin: 0 auto;
        }

        .semester-card {
            border-left: 5px solid var(--admas-border);
            transition: box-shadow 0.2s ease, transform 0.2s ease;
        }

        .semester-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 24px var(--admas-shadow);
        }

        .semester-card.semester-current { border-left-color: #16a34a; }
        .semester-card.semester-accent-sky { border-left-color: var(--admas-sky); }
        .semester-card.semester-accent-navy { border-left-color: var(--admas-navy-start); }
        .semester-card.semester-accent-amber { border-left-color: #d97706; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="main-content">
        <?php include __DIR__ . '/../includes/topbar.php'; ?>

        <div class="page-body">
            <div class="attendance-history-wrap">
            <div class="scope-banner">
                <i class="bi bi-shield-check"></i>
                Access scope: Own personal record only
            </div>

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--admas-text);">Attendance History</h4>
                    <p class="text-muted mb-0">Your attendance in every semester you've taken courses in, most recent first.</p>
                </div>
                <?php if (!empty($semesters)): ?>
                    <div class="d-flex gap-2">
                        <a href="?export=excel" class="btn btn-sm text-white" style="background-color: var(--admas-sky); border-color: var(--admas-sky);"><i class="bi bi-file-earmark-excel"></i> Export Excel</a>
                        <a href="?export=pdf" class="btn btn-sm text-white" style="background-color: var(--admas-sky); border-color: var(--admas-sky);"><i class="bi bi-file-earmark-pdf"></i> Export PDF</a>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (empty($semesters)): ?>
                <div class="admas-card p-4 text-center text-muted py-5">
                    No attendance records yet.
                </div>
            <?php endif; ?>

            <?php
            $semAccents = ['semester-accent-sky', 'semester-accent-navy', 'semester-accent-amber'];
            $semIndex = 0;
            ?>
            <?php foreach ($semesters as $sem): ?>
                <?php
                $courses = $coursesBySemester[(int) $sem['id']] ?? [];
                $semPct = semester_average_score($courses);
                $accentClass = $sem['is_current'] ? 'semester-current' : $semAccents[$semIndex % count($semAccents)];
                $semIndex++;
                ?>
                <div class="admas-card semester-card <?= $accentClass ?> p-4 mb-3">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                        <div>
                            <h5 class="fw-bold mb-1" style="color: var(--admas-text);">
                                <?= htmlspecialchars($sem['name']) ?>
                                <?php if ($sem['is_current']): ?>
                                    <span class="badge-pill badge-present ms-2">Current</span>
                                <?php else: ?>
                                    <span class="badge-pill badge-neutral ms-2">Closed</span>
                                <?php endif; ?>
                            </h5>
                            <p class="text-muted small mb-0">
                                <i class="bi bi-calendar3"></i>
                                <?= htmlspecialchars($sem['start_date']) ?> to <?= htmlspecialchars($sem['end_date']) ?>
                            </p>
                        </div>
                        <div class="text-end">
                            <?php if ($semPct === null): ?>
                                <span class="text-muted">No records</span>
                            <?php else: ?>
                                <span class="badge-pill <?= attendance_badge_class($semPct, $minAttendancePct) ?> fs-6">
                                    <?= number_format($semPct, 1) ?>% overall
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table admas-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Course</th>
                                    <th>Present</th>
                                    <th>Absent</th>
                                    <th>Total Marks</th>
                                    <th>Attendance %</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($courses as $c): ?>
                                    <?php
                                    $cTotal = (int) $c['total_marks'];
                                    $cPct = pct((int) $c['present_count'], $cTotal);
                                    ?>
                                    <tr>
                                        <td>
                                            <span class="badge-pill badge-active"><?= htmlspecialchars($c['code']) ?></span>
                                            <span class="ms-1"><?= htmlspecialchars($c['name']) ?></span>
                                        </td>
                                        <td><?= (int) $c['present_count'] ?></td>
                                        <td><?= (int) $c['absent_count'] ?></td>
                                        <td><?= $cTotal ?></td>
                                        <td>
                                            <?php if ($cPct === null): ?>
                                                <span class="text-muted">&mdash;</span>
                                            <?php else: ?>
                                                <span class="badge-pill <?= attendance_badge_class($cPct, $minAttendancePct) ?>"><?= (int) $cPct ?>%</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
