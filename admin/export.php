<?php
/**
 * University Rector's "Export" card — sky-blue card on admin/students.php,
 * admin/lecturers.php and semesters.php lets the Rector download the full
 * Students / Lecturers / Semesters lists as Excel or PDF, university-wide
 * (this role's own scope is already unrestricted "view everywhere" per
 * CLAUDE.md §4, so no faculty filter is applied here — same as every
 * other view this role reaches).
 *
 * Head of Academic Affairs, Dean, and Registration Office also reach this
 * file for `type=students` only, from their own "Export Students" button
 * on admin/students.php — that button additionally supports selecting
 * specific students first (select all or individually, via checkboxes)
 * and POSTs their ids here as `ids[]`; when present, the query below is
 * narrowed to just those students instead of the whole list. GET requests
 * with no `ids[]` (the University Rector's own plain links, and a Head of
 * Academic Affairs/Dean/Registration export with nothing checked) export
 * everyone the requesting role can see — university-wide for
 * Rector/Head of Academic Affairs/Registration, own-faculty only for Dean
 * (never trusted from the request — see the $deanFacultyId scoping below).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';
require_once __DIR__ . '/../includes/university_logo.php';
require_once __DIR__ . '/../vendor/autoload.php';

require_role(['university_rector', 'head_academic', 'dean', 'registration']);

$currentRole = current_role();
$exportType = (string) ($_GET['type'] ?? '');
// Head of Academic Affairs already has full cross-faculty VIEW/CRUD access
// to Lecturers/Departments/Faculties/Users (its own pages), so it may also
// export those — Dean and Registration stay restricted to Students only,
// matching where their own export buttons are actually shown.
$typesAllowedForHeadAcademic = ['students', 'lecturers', 'departments', 'faculties', 'users'];
$isPermitted = $currentRole === 'university_rector'
    || ($currentRole === 'head_academic' && in_array($exportType, $typesAllowedForHeadAcademic, true))
    || (in_array($currentRole, ['dean', 'registration'], true) && $exportType === 'students');
if (!$isPermitted) {
    http_response_code(403);
    exit('Not permitted.');
}

// A Dean's export is always scoped to their own faculty — never trusted
// from the request — same lock used everywhere else Dean reaches
// admin/students.php.
$deanFacultyId = 0;
if ($currentRole === 'dean') {
    $deanFacultyId = (int) ($_SESSION['faculty_id'] ?? 0);
}

// Selected student ids (Head of Academic Affairs'/Dean's own checkbox
// selection) — only meaningful for type=students; ignored entirely
// otherwise. Always re-validated as plain positive integers, never trusted
// further than that.
$selectedIds = array_values(array_unique(array_filter(
    array_map('intval', (array) ($_POST['ids'] ?? [])),
    static fn ($id) => $id > 0
)));

use Dompdf\Dompdf;
use Dompdf\Options as DompdfOptions;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

$conn = db();

$type = (string) ($_GET['type'] ?? '');
$format = (string) ($_GET['format'] ?? '');

if (!in_array($type, ['students', 'lecturers', 'semesters', 'departments', 'faculties', 'users'], true) || !in_array($format, ['excel', 'pdf'], true)) {
    http_response_code(400);
    exit('Invalid export request.');
}

const EXPORT_SHIFT_LABELS = [
    'morning' => 'Morning Shift',
    'afternoon' => 'Afternoon Shift',
    'weekend' => 'Weekend',
];

if ($type === 'students') {
    $title = 'Students';
    $columns = [
        ['key' => 'student_no', 'label' => 'Student No'],
        ['key' => 'full_name', 'label' => 'Full Name'],
        ['key' => 'academic_year_label', 'label' => 'Academic Year'],
        ['key' => 'faculty_name', 'label' => 'Faculty'],
        ['key' => 'department_name', 'label' => 'Department'],
        ['key' => 'semester_name', 'label' => 'Semester'],
        ['key' => 'shift_label', 'label' => 'Shift'],
        ['key' => 'status', 'label' => 'Status'],
    ];
    $studentsSql = "SELECT s.student_no, s.full_name, ay.label AS academic_year_label, f.name AS faculty_name,
                d.name AS department_name, sem.name AS semester_name, s.shift, u.status
         FROM students s
         JOIN academic_years ay ON ay.id = s.academic_year_id
         JOIN faculties f ON f.id = s.faculty_id
         JOIN departments d ON d.id = s.department_id
         JOIN users u ON u.id = s.user_id
         LEFT JOIN semesters sem ON sem.id = s.semester_id";
    $studentsWhere = [];
    $studentsParams = [];
    $studentsTypes = '';
    if ($currentRole === 'dean') {
        $studentsWhere[] = 's.faculty_id = ?';
        $studentsParams[] = $deanFacultyId;
        $studentsTypes .= 'i';
    }
    if (!empty($selectedIds)) {
        $studentsWhere[] = 's.id IN (' . implode(',', array_fill(0, count($selectedIds), '?')) . ')';
        $studentsParams = array_merge($studentsParams, $selectedIds);
        $studentsTypes .= str_repeat('i', count($selectedIds));
    }
    if ($studentsWhere !== []) {
        $studentsSql .= ' WHERE ' . implode(' AND ', $studentsWhere);
    }
    $studentsSql .= ' ORDER BY f.name, d.name, s.full_name';

    if ($studentsTypes !== '') {
        $studentsStmt = $conn->prepare($studentsSql);
        $studentsStmt->bind_param($studentsTypes, ...$studentsParams);
        $studentsStmt->execute();
        $res = $studentsStmt->get_result();
    } else {
        $res = $conn->query($studentsSql);
    }
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $r['semester_name'] = $r['semester_name'] ?: 'Not set';
        $r['shift_label'] = EXPORT_SHIFT_LABELS[$r['shift']] ?? $r['shift'];
        $r['status'] = ucfirst((string) $r['status']);
        $rows[] = $r;
    }
} elseif ($type === 'lecturers') {
    $title = 'Lecturers';
    $columns = [
        ['key' => 'staff_no', 'label' => 'Staff No'],
        ['key' => 'full_name', 'label' => 'Full Name'],
        ['key' => 'department_name', 'label' => 'Department'],
        ['key' => 'faculty_name', 'label' => 'Faculty'],
        ['key' => 'email', 'label' => 'Email'],
        ['key' => 'status', 'label' => 'Status'],
    ];
    $res = $conn->query(
        "SELECT l.staff_no, l.full_name, d.name AS department_name, f.name AS faculty_name, u.email, u.status
         FROM lecturers l
         JOIN departments d ON d.id = l.department_id
         JOIN faculties f ON f.id = d.faculty_id
         JOIN users u ON u.id = l.user_id
         ORDER BY f.name, d.name, l.full_name"
    );
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $r['email'] = $r['email'] ?: '—';
        $r['status'] = ucfirst((string) $r['status']);
        $rows[] = $r;
    }
} elseif ($type === 'departments') {
    $title = 'Departments';
    $columns = [
        ['key' => 'name', 'label' => 'Department'],
        ['key' => 'code', 'label' => 'Code'],
        ['key' => 'faculty_name', 'label' => 'Faculty'],
        ['key' => 'student_count', 'label' => 'Students'],
        ['key' => 'lecturer_count', 'label' => 'Lecturers'],
        ['key' => 'course_count', 'label' => 'Courses'],
    ];
    $res = $conn->query(
        "SELECT d.name, d.code, f.name AS faculty_name,
                (SELECT COUNT(*) FROM students s WHERE s.department_id = d.id) AS student_count,
                (SELECT COUNT(*) FROM lecturers l WHERE l.department_id = d.id) AS lecturer_count,
                (SELECT COUNT(*) FROM courses c WHERE c.department_id = d.id) AS course_count
         FROM departments d
         JOIN faculties f ON f.id = d.faculty_id
         ORDER BY f.name, d.name"
    );
    $rows = $res->fetch_all(MYSQLI_ASSOC);
} elseif ($type === 'faculties') {
    $title = 'Faculties';
    $columns = [
        ['key' => 'name', 'label' => 'Faculty'],
        ['key' => 'code', 'label' => 'Code'],
        ['key' => 'department_count', 'label' => 'Departments'],
        ['key' => 'student_count', 'label' => 'Students'],
        ['key' => 'dean_name', 'label' => 'Dean'],
    ];
    $res = $conn->query(
        "SELECT f.name, f.code,
                (SELECT COUNT(*) FROM departments d WHERE d.faculty_id = f.id) AS department_count,
                (SELECT COUNT(*) FROM students s WHERE s.faculty_id = f.id) AS student_count,
                du.full_name AS dean_name
         FROM faculties f
         LEFT JOIN users du ON du.id = f.dean_user_id
         ORDER BY f.name"
    );
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $r['dean_name'] = $r['dean_name'] ?: '—';
        $rows[] = $r;
    }
} elseif ($type === 'users') {
    $title = 'System Users';
    $columns = [
        ['key' => 'username', 'label' => 'Username'],
        ['key' => 'full_name', 'label' => 'Full Name'],
        ['key' => 'role_label', 'label' => 'Role'],
        ['key' => 'faculty_name', 'label' => 'Faculty'],
        ['key' => 'status', 'label' => 'Status'],
        ['key' => 'last_login_label', 'label' => 'Last Login'],
    ];
    // Head of Academic Affairs never manages University Rector accounts
    // (see head_academic/users.php's own load_manageable_user() boundary)
    // — excluded here too, so an export from that role can't surface them.
    $usersWhere = $currentRole === 'head_academic' ? "WHERE r.name != 'university_rector'" : '';
    $res = $conn->query(
        "SELECT u.username, u.full_name, r.name AS role_name, f.name AS faculty_name, u.status, u.last_login_at
         FROM users u
         JOIN roles r ON r.id = u.role_id
         LEFT JOIN faculties f ON f.id = u.faculty_id
         {$usersWhere}
         ORDER BY r.name, u.full_name"
    );
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $r['role_label'] = role_label((string) $r['role_name']);
        $r['faculty_name'] = $r['faculty_name'] ?: '—';
        $r['status'] = ucfirst((string) $r['status']);
        $r['last_login_label'] = $r['last_login_at'] ? date('Y-m-d H:i', strtotime((string) $r['last_login_at'])) : 'Never';
        $rows[] = $r;
    }
} else {
    $title = 'Semesters';
    $columns = [
        ['key' => 'faculty_name', 'label' => 'Faculty'],
        ['key' => 'name', 'label' => 'Semester'],
        ['key' => 'academic_year_label', 'label' => 'Academic Year'],
        ['key' => 'status_label', 'label' => 'Status'],
        ['key' => 'start_date', 'label' => 'Start Date'],
        ['key' => 'end_date', 'label' => 'End Date'],
    ];
    $res = $conn->query(
        "SELECT COALESCE(f.name, 'Unassigned') AS faculty_name, sem.name, ay.label AS academic_year_label,
                sem.status, sem.start_date, sem.end_date
         FROM semesters sem
         LEFT JOIN faculties f ON f.id = sem.faculty_id
         JOIN academic_years ay ON ay.id = sem.academic_year_id
         ORDER BY faculty_name, ay.label DESC, sem.name"
    );
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $r['status_label'] = ucfirst((string) $r['status']);
        $r['start_date'] = $r['start_date'] ?: '—';
        $r['end_date'] = $r['end_date'] ?: '—';
        $rows[] = $r;
    }
}

$settings = [];
$settingsResult = $conn->query('SELECT `key`, `value` FROM settings');
if ($settingsResult) {
    while ($row = $settingsResult->fetch_assoc()) {
        $settings[$row['key']] = $row['value'];
    }
}
$universityName = $settings['university_name'] ?? 'ADMAS University';
$campusLine = trim(($settings['campus'] ?? '') . ' — ' . ($settings['contact_email'] ?? '') . ' — ' . ($settings['contact_phone'] ?? ''), ' —');
$filename = 'admas_' . $type . '_' . date('Ymd_His');
$scopeLine = !empty($selectedIds)
    ? (count($rows) . ' selected student' . (count($rows) === 1 ? '' : 's'))
    : ($currentRole === 'dean' ? 'Own faculty export' : 'University-wide export');

if ($format === 'excel') {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle($title);

    $sheet->setCellValue('A1', $universityName);
    $sheet->setCellValue('A2', $title . ' — ' . $scopeLine);
    $sheet->setCellValue('A3', 'Generated: ' . date('Y-m-d H:i'));
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(11);
    $sheet->getStyle('A3')->getFont()->setItalic(true)->setSize(9);

    $headerRow = 5;
    $colLetter = 'A';
    $columnLetters = [];
    foreach ($columns as $col) {
        $sheet->setCellValue($colLetter . $headerRow, $col['label']);
        $columnLetters[] = $colLetter;
        $colLetter++;
    }
    $lastCol = end($columnLetters) ?: 'A';
    $sheet->getStyle('A' . $headerRow . ':' . $lastCol . $headerRow)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $sheet->getStyle('A' . $headerRow . ':' . $lastCol . $headerRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0EA5E9');

    $rowIndex = $headerRow + 1;
    foreach ($rows as $r) {
        $colLetter = 'A';
        foreach ($columns as $col) {
            $sheet->setCellValue($colLetter . $rowIndex, $r[$col['key']] ?? '');
            $colLetter++;
        }
        $rowIndex++;
    }

    foreach ($columnLetters as $cl) {
        $sheet->getColumnDimension($cl)->setAutoSize(true);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
    header('Cache-Control: max-age=0');
    (new XlsxWriter($spreadsheet))->save('php://output');
    exit;
}

// PDF export
$logoPath = __DIR__ . '/../' . get_university_logo_relative_path($settings);
$logoBase64 = is_file($logoPath) ? base64_encode((string) file_get_contents($logoPath)) : '';

ob_start();
?>
<html>
<head>
<meta charset="UTF-8">
<style>
    body { font-family: Helvetica, Arial, sans-serif; color: #0b1f3a; font-size: 11px; }
    .header { width: 100%; border-bottom: 2px solid #0ea5e9; padding-bottom: 8px; margin-bottom: 10px; overflow: hidden; }
    .header img { float: left; width: 56px; height: 56px; }
    .header-text { margin-left: 68px; }
    .uni-name { font-size: 16px; font-weight: bold; }
    .uni-meta { font-size: 9px; color: #64748b; }
    h2 { font-size: 13px; margin: 6px 0 2px; }
    .meta-line { font-size: 9px; color: #475569; margin-bottom: 10px; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #0b1f3a; color: #fff; text-transform: uppercase; font-size: 8px; padding: 6px; text-align: left; }
    td { padding: 5px 6px; border-bottom: 1px solid #e2e8f0; font-size: 10px; }
</style>
</head>
<body>
    <div class="header">
        <?php if ($logoBase64 !== ''): ?>
            <img src="data:image/jpeg;base64,<?= $logoBase64 ?>" width="56" height="56">
        <?php endif; ?>
        <div class="header-text">
            <div class="uni-name"><?= htmlspecialchars($universityName) ?></div>
            <div class="uni-meta"><?= htmlspecialchars($campusLine) ?></div>
        </div>
    </div>
    <h2><?= htmlspecialchars($title) ?> — <?= htmlspecialchars($scopeLine) ?></h2>
    <div class="meta-line">Generated: <?= htmlspecialchars(date('Y-m-d H:i')) ?></div>
    <table>
        <thead>
            <tr>
                <?php foreach ($columns as $col): ?>
                    <th><?= htmlspecialchars($col['label']) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <?php foreach ($columns as $col): ?>
                        <td><?= htmlspecialchars((string) ($r[$col['key']] ?? '')) ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>
<?php
$html = ob_get_clean();

$pdfOptions = new DompdfOptions();
$pdfOptions->set('isRemoteEnabled', false);
$dompdf = new Dompdf($pdfOptions);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
$dompdf->stream($filename . '.pdf', ['Attachment' => true]);
