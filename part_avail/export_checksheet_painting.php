<?php
// export_checksheet_painting.php
set_time_limit(0);
ini_set('memory_limit', '512M');

session_start();
if (empty($_SESSION['checksheet_unlocked']) || ($_SESSION['checksheet_area'] ?? '') !== 'painting') {
    http_response_code(403);
    die('Akses ditolak. Silakan login melalui Checksheet Painting terlebih dahulu.');
}

include 'config.php';
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$bulan = $_GET['bulan'] ?? '';
if ($bulan == '' || !preg_match('/^\d{4}-\d{2}$/', $bulan)) {
    die("Pilih bulan terlebih dahulu (format: YYYY-MM).");
}

$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$filename = 'CheckSheet_Painting_Monthly_' . str_replace('-', '_', $bulan) . '.xlsx';

// ── Ambil submission bulan ini ────────────────────────────────────────────────
$stmtSub = $pdo->prepare("
    SELECT id, period_month, check_date, checker, submitted_at
    FROM painting_checksheet_submissions
    WHERE period_month = ?
    LIMIT 1
");
$stmtSub->execute([$bulan]);
$sub = $stmtSub->fetch();

if (!$sub) die("Tidak ada data checksheet Painting untuk bulan $bulan.");

$stmtDet = $pdo->prepare("
    SELECT unit_name, no, part, action_status, result, note
    FROM painting_checksheet_submission_details
    WHERE submission_id = ?
    ORDER BY id
");
$stmtDet->execute([$sub['id']]);
$details = $stmtDet->fetchAll();

// ── Konstanta style ───────────────────────────────────────────────────────────
$resultColors = [
    'OK' => ['bg' => 'DCFCE7', 'fg' => '15803D'],
    'NG' => ['bg' => 'FEE2E2', 'fg' => 'DC2626'],
];

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle("Painting Monthly");
$spreadsheet->getCalculationEngine()->disableCalculationCache();

if (file_exists('assets/company_logo.jpg')) {
    $logo = new Drawing();
    $logo->setName('Company Logo');
    $logo->setPath('assets/company_logo.jpg');
    $logo->setHeight(55);
    $logo->setCoordinates('A1');
    $logo->setWorksheet($sheet);
}

$sheet->mergeCells('A1:F1');
$sheet->setCellValue('A1', 'PAINTING MONTHLY CHECK SHEET REPORT');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(15);
$sheet->getStyle('A1')->getAlignment()
    ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
$sheet->getRowDimension(1)->setRowHeight(45);

$sheet->mergeCells('A2:F2');
$sheet->setCellValue('A2', 'Bulan : ' . date('F Y', strtotime($bulan . '-01')));
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A2')->getFont()->setSize(11);

$infoRow = 3;
$sheet->mergeCells("A{$infoRow}:F{$infoRow}");
$sheet->setCellValue("A{$infoRow}", sprintf(
    "Checker: %s  |  Tanggal Cek: %s  |  Submitted: %s",
    $sub['checker'],
    $sub['check_date'],
    $sub['submitted_at']
));
$sheet->getStyle("A{$infoRow}")->applyFromArray([
    'font'      => ['bold' => true, 'size' => 9, 'color' => ['rgb' => 'FFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '334155']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet->getRowDimension($infoRow)->setRowHeight(18);

$headerRow = 5;
$sheet->fromArray(['No', 'Unit', 'Part yang Dicek', 'Action', 'Result', 'Keterangan'], NULL, "A{$headerRow}");
$sheet->getStyle("A{$headerRow}:F{$headerRow}")->applyFromArray([
    'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 9],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0F766E']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet->getRowDimension($headerRow)->setRowHeight(18);

$row = $headerRow + 1;
$resultRanges = [];
$prevUnit = '';
$okCount = 0;
$ngCount = 0;
$checkedCount = 0;

foreach ($details as $d) {
    if ($d['unit_name'] !== $prevUnit) {
        $sheet->mergeCells("A{$row}:F{$row}");
        $sheet->setCellValue("A{$row}", $d['unit_name']);
        $sheet->getStyle("A{$row}:F{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => '0F766E']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E6F5F3']],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(15);
        $row++;
        $prevUnit = $d['unit_name'];
    }

    $actionLabel = $d['action_status'] === 'checked' ? 'Checked' : 'Unchecked';
    $sheet->fromArray([
        $d['no'],
        $d['unit_name'],
        $d['part'],
        $actionLabel,
        $d['result'] ?? '-',
        $d['note'] ?? '',
    ], NULL, "A{$row}");

    if ($d['action_status'] === 'checked') $checkedCount++;
    if ($d['result'] === 'OK') $okCount++;
    if ($d['result'] === 'NG') $ngCount++;
    if ($d['result']) $resultRanges[] = ['row' => $row, 'result' => $d['result']];

    $sheet->getRowDimension($row)->setRowHeight(-1);
    $row++;
}

$sheet->getStyle("A" . ($headerRow + 1) . ":F" . ($row - 1))
    ->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);

// Summary row
$totalItems = count($details);
$sheet->mergeCells("A{$row}:F{$row}");
$sheet->setCellValue("A{$row}", "Summary: {$totalItems} item — Checked: {$checkedCount}  OK: {$okCount}  NG: {$ngCount}");
$sheet->getStyle("A{$row}:F{$row}")->applyFromArray([
    'font'      => ['bold' => true, 'italic' => true, 'size' => 8, 'color' => ['rgb' => '475569']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F8FAFC']],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet->getRowDimension($row)->setRowHeight(14);

$sheet->getStyle("A{$headerRow}:F{$row}")->applyFromArray([
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '94A3B8']]],
]);

// Coloring hasil OK/NG massal
$grouped = [];
foreach ($resultRanges as $item) $grouped[$item['result']][] = $item['row'];
foreach ($grouped as $result => $rows) {
    $rc = $resultColors[$result] ?? ['bg' => 'FFFFFF', 'fg' => '000000'];
    $style = [
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $rc['bg']]],
        'font'      => ['bold' => true, 'color' => ['rgb' => $rc['fg']]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ];
    foreach ($rows as $r) $sheet->getStyle("E{$r}")->applyFromArray($style);
}

$fixedWidths = ['A' => 5, 'B' => 24, 'C' => 46, 'D' => 12, 'E' => 10, 'F' => 26];
foreach ($fixedWidths as $col => $w) $sheet->getColumnDimension($col)->setWidth($w);
$sheet->freezePane("A" . ($headerRow + 1));

// ── Export ────────────────────────────────────────────────────────────────────
$writer = new Xlsx($spreadsheet);
$writer->setPreCalculateFormulas(false);

$tmpFile = tempnam(sys_get_temp_dir(), 'cs_painting_');
$writer->save($tmpFile);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"{$filename}\"");
header('Cache-Control: max-age=0');
header('Content-Length: ' . filesize($tmpFile));

readfile($tmpFile);
unlink($tmpFile);
exit;