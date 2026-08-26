<?php
/**
 * Read-only student detail page for University Rector's supervisory/
 * oversight role, Head of Academic Affairs' "View Students information"
 * access (university-wide), and Dean's own faculty-scoped Viewer access
 * (own faculty only — a crafted student_id belonging to another faculty
 * is rejected server-side below, not just hidden from the list). Reachable
 * only via the "View Profile" link on admin/students.php's Actions column
 * for these three roles.
 *
 * Course discovery + attendance scoring below is a direct adaptation of
 * student/courses.php's own logic (course_enrollments first, department
 * fallback second, guest-offering roster_department_id third, the
 * "real evidence" co.id IS NOT NULL OR a.id IS NOT NULL filter, and the
 * capped out-of-10 attendance score) — student_id is supplied directly
 * from the querystring instead of resolved from current_user(), since this
 * page is looking at someone else's record, not the viewer's own.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';
require_once __DIR__ . '/../includes/attendance_helpers.php';
require_once __DIR__ . '/../includes/semester_helpers.php';

require_role(['university_rector', 'head_academic', 'dean']);

$conn = db();
$currentUser = current_user();
$role = current_role();

$deanFacultyId = 0;
if ($role === 'dean') {
    $deanFacultyId = (int) ($_SESSION['faculty_id'] ?? 0);
}

// ---------------------------------------------------------------------
// University settings (drives the sky-blue top strip + threshold)
// ---------------------------------------------------------------------
$settings = [];
$settingsResult = $conn->query('SELECT `key`, `value` FROM settings');
if ($settingsResult) {
    while ($row = $settingsResult->fetch_assoc()) {
        $settings[$row['key']] = $row['value'];
    }
}
$minAttendancePct = (float) ($settings['min_attendance_pct'] ?? 7.5);

const VIEW_SHIFT_LABELS = [
    'morning' => 'Morning Shift',
    'afternoon' => 'Afternoon Shift',
    'weekend' => 'Weekend',
];

// ---------------------------------------------------------------------
// The student this page is scoped to.
// ---------------------------------------------------------------------
$studentId = (int) ($_GET['student_id'] ?? 0);

$studentStmt = $conn->prepare(
    'SELECT s.id, s.student_no, s.full_name, s.first_name, s.father_name, s.grandfather_name,
            s.shift, s.department_id, s.faculty_id, s.semester_id, s.academic_year_id,
            s.mother_name, s.sex, s.birth_date, s.street_address, s.phone,
            s.emergency_contact_name, s.emergency_contact_phone, s.nationality, s.enrollment_date,
            s.certificate_type, s.school_roll_number, s.degree, s.program, s.class_year,
            ay.label AS academic_year_label, f.name AS faculty_name, d.name AS department_name,
            sem.name AS semester_name, u.email, u.status AS user_status, u.photo_path
     FROM students s
     JOIN academic_years ay ON ay.id = s.academic_year_id
     JOIN faculties f ON f.id = s.faculty_id
     JOIN departments d ON d.id = s.department_id
     JOIN users u ON u.id = s.user_id
     LEFT JOIN semesters sem ON sem.id = s.semester_id
     WHERE s.id = ?'
);
$studentStmt->bind_param('i', $studentId);
$studentStmt->execute();
$student = $studentStmt->get_result()->fetch_assoc();
$studentStmt->close();

if (!$student) {
    $_SESSION['flash_error'] = 'Student not found.';
    redirect_to('admin/students.php');
}

// A Dean may only ever view their own faculty's students — never trusted
// from the student_id alone, re-checked here against the session's own
// faculty_id (same pattern as every other Dean-scoped page in this app).
if ($role === 'dean' && (int) $student['faculty_id'] !== $deanFacultyId) {
    $_SESSION['flash_error'] = 'Student not found.';
    redirect_to('admin/students.php');
}

$ownStudentId = (int) $student['id'];
$ownDepartmentId = (int) $student['department_id'];
$ownFacultyId = (int) $student['faculty_id'];
$ownShift = (string) $student['shift'];
$ownAcademicYearId = (int) $student['academic_year_id'];

// ---------------------------------------------------------------------
// Semester picker — one box per "Semester 1".."Semester {total_semesters}"
// for this student's own faculty, same shape as student/courses.php's own.
// ---------------------------------------------------------------------
$facultyTotalSemesters = 0;
if ($ownFacultyId > 0) {
    $facStmt = $conn->prepare('SELECT total_semesters FROM faculties WHERE id = ?');
    $facStmt->bind_param('i', $ownFacultyId);
    $facStmt->execute();
    $facultyTotalSemesters = (int) ($facStmt->get_result()->fetch_assoc()['total_semesters'] ?? 0);
    $facStmt->close();
}

// Scoped to this student's own academic year, not just their faculty — see
// the matching comment in student/courses.php's identical picker. A
// faculty may run several concurrently-current semesters that share a name
// across different academic-year cohorts; a same-named semester that only
// exists for a different cohort's year has nothing to do with this student
// and must render as "not created yet" rather than a clickable box into
// another cohort's data.
$semestersByName = [];
if ($ownFacultyId > 0 && $ownAcademicYearId > 0) {
    $semStmt = $conn->prepare(
        'SELECT s.id, s.name, s.status, ay.label AS academic_year_label
         FROM semesters s
         JOIN academic_years ay ON ay.id = s.academic_year_id
         WHERE s.faculty_id = ? AND s.academic_year_id = ? AND s.hidden_from_picker = 0
         ORDER BY s.id ASC'
    );
    $semStmt->bind_param('ii', $ownFacultyId, $ownAcademicYearId);
    $semStmt->execute();
    $semRes = $semStmt->get_result();
    while ($row = $semRes->fetch_assoc()) {
        $semestersByName[$row['name']][] = $row;
    }
    $semStmt->close();
}

$semesterBoxes = [];
foreach (semester_name_options_for_faculty($facultyTotalSemesters) as $semName) {
    $matches = $semestersByName[$semName] ?? [];
    if (empty($matches)) {
        $semesterBoxes[] = ['name' => $semName, 'semester_id' => 0, 'status' => null];
    } elseif (count($matches) === 1) {
        $semesterBoxes[] = [
            'name' => $semName,
            'semester_id' => (int) $matches[0]['id'],
            'status' => $matches[0]['status'],
        ];
    } else {
        foreach ($matches as $match) {
            $semesterBoxes[] = [
                'name' => $semName . ' (' . $match['academic_year_label'] . ')',
                'semester_id' => (int) $match['id'],
                'status' => $match['status'],
            ];
        }
    }
}

$ownSemesterId = (int) ($student['semester_id'] ?? 0);

$filterSemesterId = (int) ($_GET['semester_id'] ?? 0);
$createdSemesterIds = array_filter(array_column($semesterBoxes, 'semester_id'));
if (!in_array($filterSemesterId, $createdSemesterIds, true)) {
    $filterSemesterId = 0;
    // Prefer this student's own actual assigned semester first — see the
    // matching comment in student/courses.php's identical picker for why
    // "whichever box is current, in ascending Semester-number order" is
    // not a reliable stand-in for this once a faculty can have more than
    // one concurrently-current semester.
    if ($ownSemesterId > 0 && in_array($ownSemesterId, $createdSemesterIds, true)) {
        $filterSemesterId = $ownSemesterId;
    } else {
        foreach ($semesterBoxes as $box) {
            if ($box['semester_id'] > 0 && $box['status'] === 'current') {
                $filterSemesterId = $box['semester_id'];
                break;
            }
        }
    }
    if ($filterSemesterId === 0) {
        foreach (array_reverse($semesterBoxes) as $box) {
            if ($box['semester_id'] > 0) {
                $filterSemesterId = $box['semester_id'];
                break;
            }
        }
    }
}

// See the matching comment in student/courses.php's identical picker — a
// faculty can have more than one semester marked "current" at once, but
// only the one relevant to THIS student should ever be labeled that way
// here, to avoid the confusing "two boxes both say (current)" look.
$myCurrentSemesterId = $ownSemesterId > 0 ? $ownSemesterId : $filterSemesterId;

// ---------------------------------------------------------------------
// Course discovery — same three-source pattern as student/courses.php.
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

    // Department fallback — ADDITIVE, not "only when course_enrollments is
    // completely empty" (see the matching comment/fix in
    // student/courses.php, the original incident this addresses: a student
    // with even one explicit course_enrollments row was silently losing
    // every other course their own department offers for free).
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

    if ($ownDepartmentId > 0) {
        $guestCourseStmt = $conn->prepare(
            'SELECT DISTINCT course_id FROM course_offerings WHERE roster_department_id = ?'
        );
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

// ---------------------------------------------------------------------
// Course details + this student's own attendance score in each, scoped to
// the selected semester — same query shape as student/courses.php.
// ---------------------------------------------------------------------
function chat_view_initials(string $fullName): string
{
    $initials = '';
    foreach (preg_split('/\s+/', trim($fullName)) as $part) {
        if ($part !== '') {
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
        }
    }
    return mb_substr($initials, 0, 2) ?: '?';
}

$courses = [];
if (!empty($courseIds) && $filterSemesterId > 0) {
    $placeholders = implode(',', array_fill(0, count($courseIds), '?'));
    $sql = "SELECT c.id, c.code, c.name, l.full_name AS lecturer_name,
                   LEAST(10, SUM(a.status = 'present')) AS present_count,
                   COUNT(a.id) AS total_marks
            FROM courses c
            LEFT JOIN course_offerings co ON co.id = (
                SELECT co2.id FROM course_offerings co2
                WHERE co2.course_id = c.id AND co2.semester_id = ? AND (co2.shift = ? OR co2.shift = 'any')
                ORDER BY (co2.shift = ?) DESC
                LIMIT 1
            )
            LEFT JOIN lecturers l ON l.id = co.lecturer_id
            LEFT JOIN attendance a ON a.course_id = c.id AND a.student_id = ?
                AND a.session_id IN (SELECT id FROM sessions WHERE semester_id = ? AND type = 'regular')
            WHERE c.id IN ({$placeholders})
                AND (co.id IS NOT NULL OR a.id IS NOT NULL)
            GROUP BY c.id, c.code, c.name, l.full_name
            ORDER BY c.code";
    $stmt = $conn->prepare($sql);
    $types = 'issii' . str_repeat('i', count($courseIds));
    $params = array_merge([$filterSemesterId, $ownShift, $ownShift, $ownStudentId, $filterSemesterId], $courseIds);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $courses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student — <?= htmlspecialchars($student['full_name']) ?> — ADMAS Attendance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(BASE_URL) ?>/assets/css/app.css" rel="stylesheet">
</head>
<body>
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="main-content">
        <?php include __DIR__ . '/../includes/topbar.php'; ?>

        <div class="page-body view-profile-page">
            <div class="scope-banner">
                <i class="bi bi-shield-check"></i>
                <?php if ($role === 'dean'): ?>
                    Access scope: Own faculty only — view only
                <?php else: ?>
                    Access scope: Full system — view only (oversight)
                <?php endif; ?>
            </div>

            <div class="mb-3">
                <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/students.php" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Students
                </a>
            </div>

            <div class="profile-hero">
                <?php if (!empty($student['photo_path'])): ?>
                    <img class="profile-hero-photo" src="<?= htmlspecialchars(BASE_URL) ?>/uploads/profile_photos/<?= htmlspecialchars((string) $student['photo_path']) ?>" alt="">
                <?php else: ?>
                    <div class="profile-hero-initials"><?= htmlspecialchars(chat_view_initials((string) $student['full_name'])) ?></div>
                <?php endif; ?>
                <div class="profile-hero-body">
                    <p class="profile-hero-name"><?= htmlspecialchars($student['full_name']) ?></p>
                    <p class="profile-hero-meta"><i class="bi bi-person-vcard"></i> Student No: <?= htmlspecialchars($student['student_no']) ?></p>
                    <p class="profile-hero-meta"><i class="bi bi-mortarboard"></i> <?= htmlspecialchars($student['faculty_name']) ?> · <?= htmlspecialchars($student['department_name']) ?></p>
                    <span class="profile-hero-badge"><i class="bi bi-shield-check"></i> Student</span>
                </div>
            </div>

            <div class="admas-card p-4 mb-4">
                <div class="section-heading"><i class="bi bi-person-lines-fill"></i> Profile Information</div>
                <div class="row g-3">
                    <div class="col-sm-6 col-lg-3">
                        <div class="info-tile">
                            <div class="info-tile-label">Student No</div>
                            <div class="info-tile-value"><?= htmlspecialchars($student['student_no']) ?></div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="info-tile">
                            <div class="info-tile-label">Full Name</div>
                            <div class="info-tile-value"><?= htmlspecialchars($student['full_name']) ?></div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="info-tile">
                            <div class="info-tile-label">Email</div>
                            <div class="info-tile-value"><?= htmlspecialchars($student['email'] ?? '—') ?></div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="info-tile">
                            <div class="info-tile-label">Status</div>
                            <div>
                                <?php if ($student['user_status'] === 'active'): ?>
                                    <span class="badge-pill badge-active">Active</span>
                                <?php else: ?>
                                    <span class="badge-pill badge-inactive">Inactive</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="info-tile">
                            <div class="info-tile-label">Academic Year</div>
                            <div class="info-tile-value"><?= htmlspecialchars($student['academic_year_label']) ?></div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="info-tile">
                            <div class="info-tile-label">Faculty</div>
                            <div class="info-tile-value"><?= htmlspecialchars($student['faculty_name']) ?></div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="info-tile">
                            <div class="info-tile-label">Department</div>
                            <div class="info-tile-value"><?= htmlspecialchars($student['department_name']) ?></div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="info-tile">
                            <div class="info-tile-label">Semester</div>
                            <div class="info-tile-value">
                                <?= $student['semester_name'] ? htmlspecialchars($student['semester_name']) : '<span class="text-muted fst-italic">Not set</span>' ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="info-tile">
                            <div class="info-tile-label">Shift</div>
                            <div class="info-tile-value"><?= htmlspecialchars(VIEW_SHIFT_LABELS[$student['shift']] ?? $student['shift']) ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3 split-row">
                <div class="col-lg-6">
                    <div class="admas-card p-4 h-100">
                        <div class="section-heading accent-navy"><i class="bi bi-clipboard2-data"></i> Registration Details</div>

                        <div class="reg-group">
                            <div class="reg-group-title">Personal</div>
                            <div class="row row-cols-2 g-3">
                                <div><div class="reg-field-label">Mother's Name</div><div class="reg-field-value"><?= htmlspecialchars($student['mother_name'] ?: '—') ?></div></div>
                                <div><div class="reg-field-label">Sex</div><div class="reg-field-value"><?= htmlspecialchars($student['sex'] ? ucfirst((string) $student['sex']) : '—') ?></div></div>
                                <div><div class="reg-field-label">Birth Date</div><div class="reg-field-value"><?= htmlspecialchars($student['birth_date'] ?: '—') ?></div></div>
                                <div><div class="reg-field-label">Nationality</div><div class="reg-field-value"><?= htmlspecialchars($student['nationality'] ?: '—') ?></div></div>
                            </div>
                        </div>

                        <div class="reg-group">
                            <div class="reg-group-title">Contact</div>
                            <div class="row row-cols-2 g-3">
                                <div><div class="reg-field-label">Street Address</div><div class="reg-field-value"><?= htmlspecialchars($student['street_address'] ?: '—') ?></div></div>
                                <div><div class="reg-field-label">Phone</div><div class="reg-field-value"><?= htmlspecialchars($student['phone'] ?: '—') ?></div></div>
                                <div><div class="reg-field-label">Emergency Contact</div><div class="reg-field-value"><?= htmlspecialchars($student['emergency_contact_name'] ?: '—') ?></div></div>
                                <div><div class="reg-field-label">Emergency Phone</div><div class="reg-field-value"><?= htmlspecialchars($student['emergency_contact_phone'] ?: '—') ?></div></div>
                            </div>
                        </div>

                        <div class="reg-group">
                            <div class="reg-group-title">Enrollment</div>
                            <div class="row row-cols-2 g-3">
                                <div><div class="reg-field-label">Enrollment Date</div><div class="reg-field-value"><?= htmlspecialchars($student['enrollment_date'] ?: '—') ?></div></div>
                                <div><div class="reg-field-label">Certificate Type</div><div class="reg-field-value"><?= htmlspecialchars($student['certificate_type'] ?: '—') ?></div></div>
                                <div><div class="reg-field-label">School Roll Number</div><div class="reg-field-value"><?= htmlspecialchars($student['school_roll_number'] ?: '—') ?></div></div>
                                <div><div class="reg-field-label">Degree</div><div class="reg-field-value"><?= htmlspecialchars($student['degree'] ?: '—') ?></div></div>
                                <div><div class="reg-field-label">Program</div><div class="reg-field-value"><?= htmlspecialchars($student['program'] ?: '—') ?></div></div>
                                <div><div class="reg-field-label">Class Year</div><div class="reg-field-value"><?= htmlspecialchars($student['class_year'] ?: '—') ?></div></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="admas-card p-4 h-100">
                        <div class="section-heading accent-amber"><i class="bi bi-journal-check"></i> Courses &amp; Attendance</div>

                        <?php if (!empty($semesterBoxes)): ?>
                            <div class="mb-3">
                                <div class="semester-picker-label">Semester</div>
                                <div class="semester-picker">
                                    <?php foreach ($semesterBoxes as $box): ?>
                                        <?php if ($box['semester_id'] > 0): ?>
                                            <?php $isCurrent = $box['status'] === 'current' && $box['semester_id'] === $myCurrentSemesterId; ?>
                                            <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/student_view.php?student_id=<?= (int) $studentId ?>&semester_id=<?= $box['semester_id'] ?>"
                                               class="sem-box <?= $box['semester_id'] === $filterSemesterId ? 'sem-box-active' : '' ?>"
                                               title="<?= $isCurrent ? 'Current semester' : '' ?>">
                                                <?php if ($isCurrent): ?><span class="sem-box-dot"></span><?php endif; ?>
                                                <?= htmlspecialchars($box['name']) ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="sem-box-disabled" title="Not created yet">
                                                <?= htmlspecialchars($box['name']) ?>
                                            </span>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (empty($courses)): ?>
                            <p class="text-center text-muted py-4 mb-0">No courses recorded for this semester.</p>
                        <?php else: ?>
                            <div>
                                <?php foreach ($courses as $c): ?>
                                    <?php
                                    $totalMarks = (int) $c['total_marks'];
                                    $pct = $totalMarks > 0 ? min(ATTENDANCE_MAX_SCORE, (int) $c['present_count']) : null;
                                    ?>
                                    <div class="course-row">
                                        <span class="badge-pill badge-active course-code"><?= htmlspecialchars($c['code']) ?></span>
                                        <div class="course-main">
                                            <div class="course-name"><?= htmlspecialchars($c['name']) ?></div>
                                            <div class="course-lecturer">
                                                <?= $c['lecturer_name'] ? htmlspecialchars($c['lecturer_name']) : 'Unassigned' ?>
                                            </div>
                                        </div>
                                        <?php if ($pct === null): ?>
                                            <span class="badge-pill course-score-muted">No records yet</span>
                                        <?php else: ?>
                                            <span class="badge-pill course-score <?= attendance_badge_class($pct, $minAttendancePct) ?>"><?= $pct ?> / 10</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
