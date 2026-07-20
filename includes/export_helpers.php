<?php
/**
 * Generic "columns + rows" table export to Excel/PDF, shared by the
 * student-facing export buttons (Attendance History, Xiiso Grid). Mirrors
 * the same column/row shape and PDF template reports.php already uses for
 * the management-role reports, so exported files look consistent across
 * the whole system — kept as its own file rather than added to
 * reports.php so those already-working exports are never touched.
 */
declare(strict_types=1);

/**
 * Streams $rows (keyed the same as $columns' `key`) as an .xlsx download
 * and exits — never returns, so callers don't need their own exit/return.
 *
 * @param array<int, array{key: string, label: string}> $columns
 * @param array<int, array<string, mixed>> $rows
 */
function stream_table_as_excel(array $columns, array $rows, string $title, string $subtitle, string $filename): never
{
    require_once __DIR__ . '/../vendor/autoload.php';

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Attendance');

    $sheet->setCellValue('A1', $title);
    $sheet->setCellValue('A2', $subtitle . '   |   Generated: ' . date('Y-m-d H:i'));
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('A2')->getFont()->setItalic(true)->setSize(9);

    $headerRow = 4;
    $columnLetters = [];
    $colLetter = 'A';
    foreach ($columns as $col) {
        $sheet->setCellValue($colLetter . $headerRow, $col['label']);
        $columnLetters[] = $colLetter;
        $colLetter++;
    }
    $lastCol = end($columnLetters) ?: 'A';
    $sheet->getStyle('A' . $headerRow . ':' . $lastCol . $headerRow)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $sheet->getStyle('A' . $headerRow . ':' . $lastCol . $headerRow)->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('0B1F3A');

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

    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
    exit;
}

/**
 * Streams $rows as a PDF download and exits — never returns. Same header
 * (university name + logo) and table styling as reports.php's PDF export,
 * so a student's exported file matches the look of an admin-generated one.
 *
 * @param array<int, array{key: string, label: string}> $columns
 * @param array<int, array<string, mixed>> $rows
 */
function stream_table_as_pdf(
    array $columns,
    array $rows,
    string $title,
    string $subtitle,
    string $filename,
    string $universityName,
    string $campusLine,
    string $logoBase64
): never {
    require_once __DIR__ . '/../vendor/autoload.php';

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
            <img src="data:image/jpeg;base64,<?= $logoBase64 ?>">
        <?php endif; ?>
        <div class="header-text">
            <div class="uni-name"><?= htmlspecialchars($universityName) ?></div>
            <div class="uni-meta"><?= htmlspecialchars($campusLine) ?></div>
        </div>
    </div>
    <h2><?= htmlspecialchars($title) ?></h2>
    <div class="meta-line">
        <?= htmlspecialchars($subtitle) ?>
        &nbsp;|&nbsp; Generated: <?= htmlspecialchars(date('Y-m-d H:i')) ?>
    </div>
    <table>
        <thead>
            <tr>
                <?php foreach ($columns as $col): ?>
                    <th><?= htmlspecialchars($col['label']) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="<?= count($columns) ?>" style="text-align:center;color:#64748b;">No data.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <?php foreach ($columns as $col): ?>
                            <td><?= htmlspecialchars((string) ($r[$col['key']] ?? '')) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</body>
</html>
    <?php
    $html = (string) ob_get_clean();

    $pdfOptions = new \Dompdf\Options();
    $pdfOptions->set('isRemoteEnabled', false);
    $dompdf = new \Dompdf\Dompdf($pdfOptions);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    $dompdf->stream($filename . '.pdf', ['Attachment' => true]);
    exit;
}

/**
 * Common settings this export needs (university name, campus/contact
 * line, logo as base64) — same lookup reports.php does, factored out so
 * both export call sites don't repeat it.
 */
function export_branding(mysqli $conn): array
{
    $settings = [];
    $settingsResult = $conn->query('SELECT `key`, `value` FROM settings');
    if ($settingsResult) {
        while ($row = $settingsResult->fetch_assoc()) {
            $settings[$row['key']] = $row['value'];
        }
    }

    $logoPath = __DIR__ . '/../logo/logo.jpg';
    $logoBase64 = is_file($logoPath) ? base64_encode((string) file_get_contents($logoPath)) : '';
    $campusLine = trim(($settings['campus'] ?? '') . ' — ' . ($settings['contact_email'] ?? '') . ' — ' . ($settings['contact_phone'] ?? ''), ' —');

    return [
        'university_name' => $settings['university_name'] ?? 'ADMAS University',
        'campus_line' => $campusLine,
        'logo_base64' => $logoBase64,
    ];
}
