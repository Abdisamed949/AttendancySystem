<?php
/**
 * University Rector's "Export" card — sky-blue card on admin/students.php,
 * admin/lecturers.php and semesters.php lets the Rector download the full
 * Students / Lecturers / Semesters lists as Excel or PDF, university-wide
 * (this role's own scope is already unrestricted "view everywhere" per
 * CLAUDE.md §4, so no faculty filter is applied here — same as every
 * other view this role reaches). university_rector only, matching where
 * this card is actually shown; a direct request from any other role is
 * rejected the same as every other university_rector-only page.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';
require_once __DIR__ . '/../includes/university_logo.php';
require_once __DIR__ . '/../vendor/autoload.php';

require_role(['university_rector']);

use Dompdf\Dompdf;
use Dompdf\Options as DompdfOptions;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

$conn = db();

$type = (string) ($_GET['type'] ?? '');
$format = (string) ($_GET['format'] ?? '');

if (!in_array($type, ['students', 'lecturers', 'semesters'], true) || !in_array($format, ['excel', 'pdf'], true)) {
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
    $res = $conn->query(
        "SELECT s.student_no, s.full_name, ay.label AS academic_year_label, f.name AS faculty_name,
                d.name AS department_name, sem.name AS semester_name, s.shift, u.status
         FROM students s
         JOIN academic_years ay ON ay.id = s.academic_year_id
         JOIN faculties f ON f.id = s.faculty_id
         JOIN departments d ON d.id = s.department_id
         JOIN users u ON u.id = s.user_id
         LEFT JOIN semesters sem ON sem.id = s.semester_id
         ORDER BY f.name, d.name, s.full_name"
    );
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

if ($format === 'excel') {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle($title);

    $sheet->setCellValue('A1', $universityName);
    $sheet->setCellValue('A2', $title . ' — University-wide export');
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
    <h2><?= htmlspecialchars($title) ?> — University-wide export</h2>
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
