<?php
/**
 * My Xiiso Grid (Student only) — a read-only, single-row attendance sheet
 * for one of this student's own courses in one of their own semesters: one
 * column per Xiiso, each showing Present/Absent and its calendar date, so
 * the student can see exactly which day they were marked absent — not just
 * an aggregate %. Linked from student/courses.php's "View Grid" action.
 *
 * Scoped the same way as student/courses.php: course_id must be one of
 * this student's own courses (course_enrollments, or department fallback)
 * and semester_id must be one this student actually has attendance history
 * in (or their own current semester) — never trusted from the querystring
 * alone, so a tampered URL can't reveal another course/semester's session
 * structure. Only ever this student's own row is queried — no roster.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';
require_once __DIR__ . '/../includes/attendance_helpers.php';
require_once __DIR__ . '/../includes/semester_helpers.php';
require_once __DIR__ . '/../includes/export_helpers.php';

require_role(['student']);

$conn = db();
$currentUser = current_user();

$ownStmt = $conn->prepare('SELECT id, department_id, faculty_id, student_no, full_name FROM students WHERE user_id = ?');
$ownStmt->bind_param('i', $currentUser['id']);
$ownStmt->execute();
$ownRow = $ownStmt->get_result()->fetch_assoc();
$ownStmt->close();
$ownStudentId = $ownRow ? (int) $ownRow['id'] : 0;
$ownDepartmentId = $ownRow ? (int) $ownRow['department_id'] : 0;

// ---------------------------------------------------------------------
// Same course-discovery rule as student/courses.php, so "my own courses"
// can never drift between the two pages.
// ---------------------------------------------------------------------
$courseIds = [];
if ($ownStudentId > 0) {
    $enrollStmt = $conn->prepare('SELECT course_id FROM course_enrollments WHERE student_id = ?');
    $enrollStmt->bind_param('i', $ownStudentId);
    $enrollStmt->execute();
    $enrollRes = $enrollStmt->get_result();
    while ($row = $enrollRes->fetch_assoc()) {
        $courseIds[] = (int) $row['course_id'];
    }
    $enrollStmt->close();

    if (empty($courseIds) && $ownDepartmentId > 0) {
        $deptCourseStmt = $conn->prepare('SELECT id FROM courses WHERE department_id = ?');
        $deptCourseStmt->bind_param('i', $ownDepartmentId);
        $deptCourseStmt->execute();
        $deptCourseRes = $deptCourseStmt->get_result();
        while ($row = $deptCourseRes->fetch_assoc()) {
            $courseIds[] = (int) $row['id'];
        }
        $deptCourseStmt->close();
    }
}

// Every semester this student has attendance history in, same rule as
// student/courses.php, plus their own current semester as a fallback.
$semesterOptionIds = [];
if ($ownStudentId > 0) {
    $stmt = $conn->prepare(
        'SELECT DISTINCT se.id
         FROM attendance a
         JOIN sessions sess ON sess.id = a.session_id
         JOIN semesters se ON se.id = sess.semester_id
         WHERE a.student_id = ?'
    );
    $stmt->bind_param('i', $ownStudentId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $semesterOptionIds[] = (int) $row['id'];
    }
    $stmt->close();
}
$ownCurrentSemester = $ownRow ? get_current_semester($conn, (int) $ownRow['faculty_id']) : null;
if ($ownCurrentSemester !== null) {
    $semesterOptionIds[] = (int) $ownCurrentSemester['id'];
}

$courseId = (int) ($_GET['course_id'] ?? 0);
$semesterId = (int) ($_GET['semester_id'] ?? 0);
$isValid = $ownStudentId > 0 && in_array($courseId, $courseIds, true) && in_array($semesterId, $semesterOptionIds, true);

$courseRow = null;
$semesterRow = null;
$sessions = [];
$marksBySessionId = [];
$presentCount = 0;
$absentCount = 0;
$totalMarks = 0;

if ($isValid) {
    $courseStmt = $conn->prepare(
        'SELECT c.id, c.code, c.name, d.name AS department_name, f.name AS faculty_name, d.faculty_id
         FROM courses c
         JOIN departments d ON d.id = c.department_id
         JOIN faculties f ON f.id = d.faculty_id
         WHERE c.id = ?'
    );
    $courseStmt->bind_param('i', $courseId);
    $courseStmt->execute();
    $courseRow = $courseStmt->get_result()->fetch_assoc();
    $courseStmt->close();

    $semStmt = $conn->prepare('SELECT id, name, academic_year_id, faculty_id FROM semesters WHERE id = ?');
    $semStmt->bind_param('i', $semesterId);
    $semStmt->execute();
    $semesterRow = $semStmt->get_result()->fetch_assoc();
    $semStmt->close();

    // A semester belongs to exactly one faculty — a course from a
    // different faculty can never be validly paired with it, even if both
    // ids individually belong to this student (e.g. via two different
    // course_enrollments across faculties).
    if ($courseRow && $semesterRow && (int) $courseRow['faculty_id'] !== (int) $semesterRow['faculty_id']) {
        $courseRow = null;
    }

    if ($courseRow && $semesterRow) {
        $sessions = get_sessions_for_semester($conn, $semesterId);

        $marksStmt = $conn->prepare('SELECT session_id, status FROM attendance WHERE student_id = ? AND course_id = ?');
        $marksStmt->bind_param('ii', $ownStudentId, $courseId);
        $marksStmt->execute();
        $marksRes = $marksStmt->get_result();
        while ($row = $marksRes->fetch_assoc()) {
            $marksBySessionId[(int) $row['session_id']] = $row['status'];
        }
        $marksStmt->close();

        foreach ($sessions as $s) {
            $status = $marksBySessionId[(int) $s['id']] ?? null;
            if ($status !== null) {
                $totalMarks++;
                if ($status === 'present') {
                    $presentCount++;
                } elseif ($status === 'absent') {
                    $absentCount++;
                }
            }
        }
    } else {
        $isValid = false;
    }
}

$attendancePct = $totalMarks > 0 ? round(100 * $presentCount / $totalMarks, 1) : null;

$settings = [];
$settingsResult = $conn->query('SELECT `key`, `value` FROM settings');
if ($settingsResult) {
    while ($row = $settingsResult->fetch_assoc()) {
        $settings[$row['key']] = $row['value'];
    }
}
$minAttendancePct = (float) ($settings['min_attendance_pct'] ?? 75);

// ---------------------------------------------------------------------
// Export (PDF/Excel) — one row per Xiiso, same data already shown on
// screen. Must run before any HTML output.
// ---------------------------------------------------------------------
$exportFormat = (string) ($_GET['export'] ?? '');
if (($exportFormat === 'excel' || $exportFormat === 'pdf') && $isValid) {
    $exportColumns = [
        ['key' => 'xiiso', 'label' => 'Xiiso'],
        ['key' => 'date', 'label' => 'Date'],
        ['key' => 'status', 'label' => 'Status'],
    ];
    $exportRows = [];
    foreach ($sessions as $s) {
        $status = $marksBySessionId[(int) $s['id']] ?? null;
        $exportRows[] = [
            'xiiso' => $s['label'],
            'date' => $s['date'] ? date('Y-m-d', strtotime((string) $s['date'])) : '—',
            'status' => $status === null ? '—' : ucfirst($status),
        ];
    }

    $title = $courseRow['code'] . ' — ' . $courseRow['name'] . ' (' . $semesterRow['name'] . ')';
    $subtitle = $ownRow['full_name'] . ' (' . $ownRow['student_no'] . ') — Present: ' . $presentCount . ', Absent: ' . $absentCount;
    $filename = preg_replace('/[^A-Za-z0-9_-]/', '_', $courseRow['code'] . '_' . $semesterRow['name'] . '_' . $ownRow['student_no']);

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
    <title>My Xiiso Grid — ADMAS Attendance System</title>
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
                Access scope: Own personal record only
            </div>

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--admas-text);">My Xiiso Grid</h4>
                    <p class="text-muted mb-0">Every Xiiso for this course, with its date — see exactly which day you were absent.</p>
                </div>
                <div class="d-flex gap-2">
                    <?php if ($isValid): ?>
                        <a href="?<?= htmlspecialchars(http_build_query(['course_id' => $courseId, 'semester_id' => $semesterId, 'export' => 'excel'])) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-earmark-excel"></i> Export Excel</a>
                        <a href="?<?= htmlspecialchars(http_build_query(['course_id' => $courseId, 'semester_id' => $semesterId, 'export' => 'pdf'])) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-earmark-pdf"></i> Export PDF</a>
                    <?php endif; ?>
                    <a href="<?= htmlspecialchars(BASE_URL) ?>/student/courses.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-arrow-left"></i> Back to My Courses
                    </a>
                </div>
            </div>

            <?php if (!$isValid): ?>
                <div class="admas-card p-4 text-center text-muted py-5">
                    That course/semester isn't available to view.
                    <a href="<?= htmlspecialchars(BASE_URL) ?>/student/courses.php">Go back to My Courses</a>.
                </div>
            <?php else: ?>
                <?= render_scope_breadcrumb([
                    $courseRow['code'] . ' — ' . $courseRow['name'],
                    $courseRow['department_name'],
                    $courseRow['faculty_name'],
                    $semesterRow['name'],
                ]) ?>

                <div class="admas-card p-4 mb-3">
                    <h6 class="fw-bold mb-0" style="color: var(--admas-text);">
                        <?= htmlspecialchars($courseRow['code'] . ' — ' . $courseRow['name']) ?>
                        <span class="text-muted fw-normal">(<?= htmlspecialchars($semesterRow['name']) ?>)</span>
                    </h6>
                </div>

                <div class="admas-card p-4">
                    <div class="table-responsive">
                        <?php
                        $xiisoBandChunks = build_xiiso_chunks($sessions);
                        $xiisoChunkEndSessionIds = [];
                        foreach ($xiisoBandChunks as $chunk) {
                            if (!empty($chunk['session_ids'])) {
                                $xiisoChunkEndSessionIds[end($chunk['session_ids'])] = true;
                            }
                        }
                        ?>
                        <table class="table admas-table align-middle text-center mb-0">
                            <thead>
                                <tr>
                                    <th></th>
                                    <?php foreach ($xiisoBandChunks as $chunk): ?>
                                        <th class="grid-month-band col-group-end" colspan="<?= (int) $chunk['span'] ?>"><?= htmlspecialchars($chunk['label']) ?></th>
                                    <?php endforeach; ?>
                                    <th colspan="3"></th>
                                </tr>
                                <tr>
                                    <th class="col-group-end col-summary text-start">Full Name</th>
                                    <?php foreach ($sessions as $s): ?>
                                        <th class="<?= isset($xiisoChunkEndSessionIds[(int) $s['id']]) ? 'col-group-end' : '' ?>">
                                            <?= htmlspecialchars($s['label']) ?>
                                            <div class="text-muted small fw-normal">
                                                <?= $s['date'] ? htmlspecialchars(date('M j', strtotime((string) $s['date']))) : '—' ?>
                                            </div>
                                        </th>
                                    <?php endforeach; ?>
                                    <th class="col-group-end col-summary">P</th>
                                    <th class="col-group-end col-summary">A</th>
                                    <th class="col-summary">%</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="col-group-end col-summary fw-semibold text-start" style="color: var(--admas-text);">
                                        <?= htmlspecialchars($ownRow['full_name']) ?>
                                        <div class="text-muted small fw-normal"><?= htmlspecialchars($ownRow['student_no']) ?></div>
                                    </td>
                                    <?php foreach ($sessions as $s): ?>
                                        <?php $status = $marksBySessionId[(int) $s['id']] ?? null; ?>
                                        <td class="p-2<?= isset($xiisoChunkEndSessionIds[(int) $s['id']]) ? ' col-group-end' : '' ?>">
                                            <?php if ($status === 'present'): ?>
                                                <span class="badge-pill badge-present">Present</span>
                                            <?php elseif ($status === 'absent'): ?>
                                                <span class="badge-pill badge-absent">Absent</span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="col-group-end col-summary fw-semibold"><?= $presentCount ?></td>
                                    <td class="col-group-end col-summary fw-semibold"><?= $absentCount ?></td>
                                    <td class="col-summary">
                                        <?php if ($attendancePct !== null): ?>
                                            <span class="badge-pill <?= attendance_badge_class($attendancePct, $minAttendancePct) ?>"><?= number_format($attendancePct, 1) ?>%</span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
