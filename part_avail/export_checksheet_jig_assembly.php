<?php
// export_checksheet_jig_assembly.php
// Export 1 periode (1 submission) Checksheet Jig Assembly ke Excel.
// Polanya sengaja dibuat semirip mungkin dengan export_checksheet_painting.php.
set_time_limit(0);
ini_set('memory_limit', '512M');

session_start();
if (empty($_SESSION['checksheet_unlocked']) || ($_SESSION['checksheet_area'] ?? '') !== 'jig_assembly') {
    http_response_code(403);
    die('Akses ditolak. Silakan login melalui Checksheet Jig Assembly terlebih dahulu.');
}

include 'config.php';
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

function jigAssemblyQuarterLabel(int $quarter): string
{
    $labels = [1 => 'Kuartal 1 (Jan–Mar)', 2 => 'Kuartal 2 (Apr–Jun)', 3 => 'Kuartal 3 (Jul–Sep)', 4 => 'Kuartal 4 (Okt–Des)'];
    return $labels[$quarter] ?? '-';
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    die("Pilih periode (submission) terlebih dahulu.");
}

$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// ── Ambil submission ──────────────────────────────────────────────────────────
$stmtSub = $pdo->prepare("SELECT id, check_date, checker, submitted_at FROM jig_assembly_submissions WHERE id = ?");
$stmtSub->execute([$id]);
$sub = $stmtSub->fetch();

if (!$sub) die("Data checksheet Jig Assembly tidak ditemukan.");

$ts          = strtotime($sub['check_date']);
$quarter     = intdiv(((int)date('n', $ts)) - 1, 3) + 1;
$periodLabel = jigAssemblyQuarterLabel($quarter) . ' ' . date('Y', $ts);

$filename = 'CheckSheet_JigAssembly_' . str_replace('-', '_', $sub['check_date']) . '.xlsx';

$stmtDet = $pdo->prepare("
    SELECT d.id, d.visual_result, d.actual_diameter, d.note,
           m.no AS machine_no, m.machine_name, m.jig_name,
           c.no AS cp_no, c.check_point, c.is_diameter, c.standard_value
    FROM jig_assembly_submission_details d
    JOIN jig_assembly_machines m ON m.id = d.machine_id
    JOIN jig_assembly_checkpoints c ON c.id = d.checkpoint_id
    WHERE d.submission_id = ?
    ORDER BY m.sort_order, m.id, c.sort_order, c.no
");
$stmtDet->execute([$sub['id']]);
$details = $stmtDet->fetchAll();

// Item mana saja yang pernah diedit (untuk kolom penanda "Diedit")
$stmtEdited = $pdo->prepare("
    SELECT DISTINCT detail_id
    FROM jig_assembly_edit_log
    WHERE submission_id = ?
");
$stmtEdited->execute([$sub['id']]);
$editedDetailIds = array_flip($stmtEdited->fetchAll(PDO::FETCH_COLUMN));

// ── Konstanta style ───────────────────────────────────────────────────────────
$resultColors = [
    'OK' => ['bg' => 'DCFCE7', 'fg' => '15803D'],
    'NG' => ['bg' => 'FEE2E2', 'fg' => 'DC2626'],
];

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle("Jig Assembly");
$spreadsheet->getCalculationEngine()->disableCalculationCache();

if (file_exists('assets/company_logo.jpg')) {
    $logo = new Drawing();
    $logo->setName('Company Logo');
    $logo->setPath('assets/company_logo.jpg');
    $logo->setHeight(55);
    $logo->setCoordinates('A1');
    $logo->setWorksheet($sheet);
}

$sheet->mergeCells('A1:H1');
$sheet->setCellValue('A1', 'JIG ASSEMBLY CHECK SHEET REPORT');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(15);
$sheet->getStyle('A1')->getAlignment()
    ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
$sheet->getRowDimension(1)->setRowHeight(45);

$sheet->mergeCells('A2:H2');
$sheet->setCellValue('A2', 'Periode : ' . $periodLabel);
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A2')->getFont()->setSize(11);

$infoRow = 3;
$sheet->mergeCells("A{$infoRow}:H{$infoRow}");
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
$sheet->fromArray(['No', 'Machine / Jig', 'Check Point', 'Standard', 'Actual', 'Result', 'Keterangan', 'Diedit'], NULL, "A{$headerRow}");
$sheet->getStyle("A{$headerRow}:H{$headerRow}")->applyFromArray([
    'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 9],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E36414']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet->getRowDimension($headerRow)->setRowHeight(18);

$row = $headerRow + 1;
$resultRanges = [];
$prevMachine = '';
$okCount = 0;
$ngCount = 0;

foreach ($details as $d) {
    $machineLabel = $d['machine_no'] . '. ' . $d['machine_name'] . ' — ' . $d['jig_name'];

    if ($machineLabel !== $prevMachine) {
        $sheet->mergeCells("A{$row}:H{$row}");
        $sheet->setCellValue("A{$row}", $machineLabel);
        $sheet->getStyle("A{$row}:H{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => 'B65108']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FCEEE1']],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(15);
        $row++;
        $prevMachine = $machineLabel;
    }

    $standard = $d['is_diameter'] ? ($d['standard_value'] !== null ? $d['standard_value'] : '-') : '-';
    $actual   = $d['is_diameter'] ? ($d['actual_diameter'] ?? '-') : '-';
    $wasEdited = isset($editedDetailIds[$d['id']]);

    $sheet->fromArray([
        $d['cp_no'],
        $machineLabel,
        $d['check_point'],
        $standard,
        $actual,
        $d['visual_result'] ?? '-',
        $d['note'] ?? '',
        $wasEdited ? 'Ya' : '-',
    ], NULL, "A{$row}");

    if ($wasEdited) {
        $sheet->getStyle("H{$row}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 8, 'color' => ['rgb' => 'B45309']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEF3C7']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
    }

    if ($d['visual_result'] === 'OK') $okCount++;
    if ($d['visual_result'] === 'NG') $ngCount++;
    if ($d['visual_result']) $resultRanges[] = ['row' => $row, 'result' => $d['visual_result']];

    $sheet->getRowDimension($row)->setRowHeight(-1);
    $row++;
}

$sheet->getStyle("A" . ($headerRow + 1) . ":H" . ($row - 1))
    ->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);

// Summary row
$totalItems = count($details);
$sheet->mergeCells("A{$row}:H{$row}");
$sheet->setCellValue("A{$row}", "Summary: {$totalItems} item — OK: {$okCount}  NG: {$ngCount}");
$sheet->getStyle("A{$row}:H{$row}")->applyFromArray([
    'font'      => ['bold' => true, 'italic' => true, 'size' => 8, 'color' => ['rgb' => '475569']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F8FAFC']],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet->getRowDimension($row)->setRowHeight(14);

$sheet->getStyle("A{$headerRow}:H{$row}")->applyFromArray([
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
    foreach ($rows as $r) $sheet->getStyle("F{$r}")->applyFromArray($style);
}

$fixedWidths = ['A' => 5, 'B' => 30, 'C' => 34, 'D' => 12, 'E' => 12, 'F' => 10, 'G' => 26, 'H' => 9];
foreach ($fixedWidths as $col => $w) $sheet->getColumnDimension($col)->setWidth($w);
$sheet->freezePane("A" . ($headerRow + 1));

// ── Export ────────────────────────────────────────────────────────────────────
$writer = new Xlsx($spreadsheet);
$writer->setPreCalculateFormulas(false);

$tmpFile = tempnam(sys_get_temp_dir(), 'cs_jig_');
$writer->save($tmpFile);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"{$filename}\"");
header('Cache-Control: max-age=0');
header('Content-Length: ' . filesize($tmpFile));

readfile($tmpFile);
unlink($tmpFile);
exit;
