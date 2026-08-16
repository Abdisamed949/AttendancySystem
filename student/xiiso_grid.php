<?php
/**
 * My Xiiso Grid (Student only) — a read-only, single-row attendance sheet
 * for one of this student's own courses in one of their own semesters: one
 * column per Xiiso, each showing Present/Absent and its calendar date, so
 * the student can see exactly which day they were marked absent — not just
 * an aggregate %. Linked from student/courses.php's "View Grid" action.
 *
 * Scoped the same way as student/courses.php: course_id must be one of
 * this student's own courses (course_enrollments, additive department
 * fallback, or a guest-offering roster_department_id cross-listing — see
 * the matching comment in student/courses.php) and semester_id must be one
 * this student actually has attendance history in (or their own current
 * semester) — never trusted from the querystring alone, so a tampered URL
 * can't reveal another course/semester's session structure. Only ever this
 * student's own row is queried — no roster.
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

$ownStmt = $conn->prepare('SELECT id, department_id, faculty_id, academic_year_id, student_no, full_name FROM students WHERE user_id = ?');
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

    // Additive, not gated on course_enrollments being empty — a student
    // with even one explicit enrollment row was silently losing every
    // other course their own department offers for free (the same real
    // incident already fixed on student/courses.php/student/dashboard.php;
    // this page had drifted out of sync with that fix).
    if ($ownDepartmentId > 0) {
        $deptCourseStmt = $conn->prepare('SELECT id FROM courses WHERE department_id = ?');
        $deptCourseStmt->bind_param('i', $ownDepartmentId);
        $deptCourseStmt->execute();
        $deptCourseRes = $deptCourseStmt->get_result();
        while ($row = $deptCourseRes->fetch_assoc()) {
            $courseIds[] = (int) $row['id'];
        }
        $deptCourseStmt->close();
        $courseIds = array_values(array_unique($courseIds));
    }

    // Additive third source: a cross-listed/guest-faculty offering whose
    // roster_department_id explicitly names this student's own department
    // (see the Multi-Faculty Course Offerings work) — the course's own
    // catalog home may be a completely different department/faculty, so
    // neither source above would ever surface it. This page was missing
    // this source entirely, which is why "View Grid" failed for a
    // guest-offering course even though student/courses.php's own score
    // (which already had this source) displayed correctly.
    if ($ownDepartmentId > 0) {
        $guestCourseStmt = $conn->prepare('SELECT DISTINCT course_id FROM course_offerings WHERE roster_department_id = ?');
        $guestCourseStmt->bind_param('i', $ownDepartmentId);
        $guestCourseStmt->execute();
        $guestCourseRes = $guestCourseStmt->get_result();
        while ($row = $guestCourseRes->fetch_assoc()) {
            $courseIds[] = (int) $row['course_id'];
        }
        $guestCourseStmt->close();
        $courseIds = array_values(array_unique($courseIds));
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
// Resolved from THIS student's own academic-year cohort's current
// semester first, not just "whichever current semester has the highest
// id" for the whole faculty — see the matching fix/comment in
// student/dashboard.php. Without this, a student with no attendance
// history yet in a course (e.g. their very first click into a brand-new
// course's Grid) could have the wrong semester silently offered here.
$ownCurrentSemester = null;
if ($ownRow) {
    $ownFacultyIdForSemester = (int) $ownRow['faculty_id'];
    $ownAcademicYearIdForSemester = (int) $ownRow['academic_year_id'];
    $curSemStmt = $conn->prepare(
        "SELECT id, name FROM semesters WHERE faculty_id = ? AND academic_year_id = ? AND status = 'current' ORDER BY id DESC LIMIT 1"
    );
    $curSemStmt->bind_param('ii', $ownFacultyIdForSemester, $ownAcademicYearIdForSemester);
    $curSemStmt->execute();
    $ownCurrentSemester = $curSemStmt->get_result()->fetch_assoc();
    $curSemStmt->close();

    if (!$ownCurrentSemester) {
        $ownCurrentSemester = get_current_semester($conn, $ownFacultyIdForSemester);
    }
}
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

    // A semester belongs to exactly one faculty — normally a course from a
    // different faculty can never be validly paired with it, EXCEPT when
    // the course is legitimately cross-listed into this semester via a
    // guest `course_offerings` row (see the Multi-Faculty Course Offerings
    // work) — its own catalog department's faculty stays whatever it was
    // originally, only the offering itself lives under this semester's
    // faculty. Without this exception, a real cross-listed course (e.g.
    // Taxation, cataloged under Business but offered into Informatics via
    // roster_department_id) would always fail this check even though the
    // student legitimately takes it and student/courses.php's own score
    // for it displays correctly.
    if ($courseRow && $semesterRow
        && (int) $courseRow['faculty_id'] !== (int) $semesterRow['faculty_id']
        && !course_offering_exists($conn, $courseId, $semesterId)
    ) {
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
            if ($s['type'] !== 'regular') {
                continue;
            }
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

// Only regular sessions count (Midterm/Final are exams) — a raw capped
// count out of ATTENDANCE_MAX_SCORE, not a ratio; see includes/attendance_helpers.php.
$attendancePct = min(ATTENDANCE_MAX_SCORE, $presentCount);

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
                        <a href="?<?= htmlspecialchars(http_build_query(['course_id' => $courseId, 'semester_id' => $semesterId, 'export' => 'excel'])) ?>" class="btn btn-sm text-white" style="background-color: var(--admas-sky); border-color: var(--admas-sky);"><i class="bi bi-file-earmark-excel"></i> Export Excel</a>
                        <a href="?<?= htmlspecialchars(http_build_query(['course_id' => $courseId, 'semester_id' => $semesterId, 'export' => 'pdf'])) ?>" class="btn btn-sm text-white" style="background-color: var(--admas-sky); border-color: var(--admas-sky);"><i class="bi bi-file-earmark-pdf"></i> Export PDF</a>
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

                <!-- Mobile: a plain vertical list, one card per Xiiso — no
                     horizontal scrolling at all, unlike the wide table below
                     (which stays for tablet/desktop, where all 12 columns
                     fit comfortably). -->
                <div class="d-md-none">
                    <?php foreach ($sessions as $s): ?>
                        <?php
                        $sIsExam = $s['type'] !== 'regular';
                        $status = $marksBySessionId[(int) $s['id']] ?? null;
                        ?>
                        <div class="admas-card p-3 mb-2 d-flex justify-content-between align-items-center">
                            <div>
                                <div class="fw-semibold" style="color: var(--admas-text);"><?= htmlspecialchars($s['label']) ?></div>
                                <div class="text-muted small"><?= $s['date'] ? htmlspecialchars(date('M j, Y', strtotime((string) $s['date']))) : 'No date set' ?></div>
                            </div>
                            <div>
                                <?php if ($sIsExam): ?>
                                    <span class="badge-pill" style="background: var(--admas-border); color: var(--admas-text-muted);">Exam</span>
                                <?php elseif ($status === 'present'): ?>
                                    <span class="badge-pill badge-present">Present</span>
                                <?php elseif ($status === 'absent'): ?>
                                    <span class="badge-pill badge-absent">Absent</span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <div class="admas-card p-3 d-flex justify-content-between align-items-center" style="border: 2px solid var(--admas-sky);">
                        <div class="fw-bold" style="color: var(--admas-text);">Summary</div>
                        <div class="d-flex align-items-center gap-3">
                            <span style="color: var(--admas-text);">P: <?= $presentCount ?></span>
                            <span style="color: var(--admas-text);">A: <?= $absentCount ?></span>
                            <span class="badge-pill <?= attendance_badge_class($attendancePct, $minAttendancePct) ?>"><?= $attendancePct ?>%</span>
                        </div>
                    </div>
                </div>

                <!-- Tablet/desktop: the full 12-Xiiso table. -->
                <div class="admas-card p-4 d-none d-md-block">
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
                                        <?php $sIsExam = $s['type'] !== 'regular'; ?>
                                        <th class="<?= isset($xiisoChunkEndSessionIds[(int) $s['id']]) ? 'col-group-end' : '' ?><?= $sIsExam ? ' col-exam' : '' ?>" <?= $sIsExam ? 'title="Exam — not part of the attendance score"' : '' ?>>
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
                                        <?php
                                        $status = $marksBySessionId[(int) $s['id']] ?? null;
                                        $sIsExam = $s['type'] !== 'regular';
                                        ?>
                                        <td class="p-2<?= isset($xiisoChunkEndSessionIds[(int) $s['id']]) ? ' col-group-end' : '' ?><?= $sIsExam ? ' col-exam' : '' ?>">
                                            <?php if ($sIsExam): ?>
                                                <span class="text-muted">—</span>
                                            <?php elseif ($status === 'present'): ?>
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
                                        <span class="badge-pill <?= attendance_badge_class($attendancePct, $minAttendancePct) ?>"><?= $attendancePct ?>%</span>
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
