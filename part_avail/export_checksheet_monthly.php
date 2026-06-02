<?php
// export_checksheet_monthly.php
session_start();
include 'config.php';
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$bulan = $_GET['bulan'] ?? '';
if ($bulan == '') die("Pilih bulan terlebih dahulu.");

// ─── DB ────────────────────────────────────────────────────────────────────────
$host    = 'localhost';
$db      = 'db_notif_alert';
$user    = 'root';
$pass    = '';
$charset = 'utf8mb4';
$pdo = new PDO(
    "mysql:host=$host;dbname=$db;charset=$charset",
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// Semua submission di bulan ini
$stmtSub = $pdo->prepare("
    SELECT * FROM checksheet_submissions
    WHERE DATE_FORMAT(check_date, '%Y-%m') = ?
    ORDER BY check_date ASC, submitted_at ASC
");
$stmtSub->execute([$bulan]);
$submissions = $stmtSub->fetchAll();

if (empty($submissions)) {
    die("Tidak ada data untuk bulan $bulan.");
}

// ─── Excel ────────────────────────────────────────────────────────────────────
$spreadsheet = new Spreadsheet();

// ══════════════════════════════════════════════════════════════════════════════
// SHEET 1: Summary per submission
// ══════════════════════════════════════════════════════════════════════════════
$sheet1 = $spreadsheet->getActiveSheet();
$sheet1->setTitle("Summary");

if (file_exists('assets/company_logo.jpg')) {
    $logo = new Drawing();
    $logo->setName('Company Logo');
    $logo->setPath('assets/company_logo.jpg');
    $logo->setHeight(55);
    $logo->setCoordinates('A1');
    $logo->setWorksheet($sheet1);
}

/* TITLE */
$sheet1->mergeCells('A1:N1');
$sheet1->setCellValue('A1', 'MONTHLY CHECK SHEET REPORT — SUMMARY');
$sheet1->getStyle('A1')->getFont()->setBold(true)->setSize(15);
$sheet1->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
$sheet1->getRowDimension(1)->setRowHeight(45);

$sheet1->mergeCells('A2:N2');
$sheet1->setCellValue('A2', 'Bulan : ' . $bulan);
$sheet1->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet1->getStyle('A2')->getFont()->setSize(11);

/* HEADER */
$headers = [
    'No',
    'Check Date',
    'Department',
    'Line',
    'OP',
    'Machine Name',
    'Machine Type',
    'Category',
    'Checker',
    'Total Items',
    'OK (V)',
    'Problem (X)',
    'Repair (R)',
    'Repair Outsider (RO)'
];
$sheet1->fromArray($headers, NULL, 'A4');
$sheet1->getStyle('A4:N4')->applyFromArray([
    'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 9],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '198754']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet1->getRowDimension(4)->setRowHeight(18);

$resultLabel = [
    'V'  => 'OK',
    'X'  => 'Problem',
    'R'  => 'Repair',
    'RO' => 'Repair by Outsider',
    '-'  => 'Tidak Digunakan',
];

$row1 = 5;
$no   = 1;
$grandTotal = ['items' => 0, 'ok' => 0, 'x' => 0, 'r' => 0, 'ro' => 0];

foreach ($submissions as $sub) {
    $stmtDet = $pdo->prepare("
        SELECT result FROM checksheet_submission_details WHERE submission_id = ?
    ");
    $stmtDet->execute([$sub['id']]);
    $details = $stmtDet->fetchAll();

    $total = count($details);
    $ok    = count(array_filter($details, fn($d) => $d['result'] === 'V'));
    $xc    = count(array_filter($details, fn($d) => $d['result'] === 'X'));
    $rc    = count(array_filter($details, fn($d) => $d['result'] === 'R'));
    $ro    = count(array_filter($details, fn($d) => $d['result'] === 'RO'));

    $grandTotal['items'] += $total;
    $grandTotal['ok']    += $ok;
    $grandTotal['x']     += $xc;
    $grandTotal['r']     += $rc;
    $grandTotal['ro']    += $ro;

    $sheet1->fromArray([
        $no++,
        $sub['check_date'],
        $sub['department'],
        $sub['line'],
        $sub['op'],
        $sub['machine_name'],
        $sub['machine_type'],
        $sub['category_key'],
        $sub['checker'],
        $total,
        $ok,
        $xc,
        $rc,
        $ro,
    ], NULL, "A{$row1}");

    // Color problem & repair columns
    if ($xc > 0) {
        $sheet1->getStyle("L{$row1}")->applyFromArray(['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEE2E2']], 'font' => ['bold' => true, 'color' => ['rgb' => 'DC2626']]]);
    }
    if ($rc > 0 || $ro > 0) {
        $sheet1->getStyle("M{$row1}")->applyFromArray(['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEF9C3']], 'font' => ['bold' => true, 'color' => ['rgb' => 'CA8A04']]]);
        $sheet1->getStyle("N{$row1}")->applyFromArray(['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EDE9FE']], 'font' => ['bold' => true, 'color' => ['rgb' => '7C3AED']]]);
    }

    $sheet1->getStyle("A{$row1}:N{$row1}")->getAlignment()
        ->setVertical(Alignment::VERTICAL_CENTER)->setHorizontal(Alignment::HORIZONTAL_CENTER)->setWrapText(true);
    $sheet1->getStyle("C{$row1}:F{$row1}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet1->getRowDimension($row1)->setRowHeight(15);
    $row1++;
}

/* Grand Total Row */
$sheet1->mergeCells("A{$row1}:I{$row1}");
$sheet1->setCellValue("A{$row1}", "TOTAL");
$sheet1->fromArray([$grandTotal['items'], $grandTotal['ok'], $grandTotal['x'], $grandTotal['r'], $grandTotal['ro']], NULL, "J{$row1}");
$sheet1->getStyle("A{$row1}:N{$row1}")->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E293B']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet1->getRowDimension($row1)->setRowHeight(18);

/* Border & autosize sheet1 */
$sheet1->getStyle("A4:N" . $row1)->applyFromArray([
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E2E8F0']]],
]);
foreach (range('A', 'N') as $col) {
    $sheet1->getColumnDimension($col)->setAutoSize(true);
}
$sheet1->freezePane('A5');

// ══════════════════════════════════════════════════════════════════════════════
// SHEET 2: Detail semua item
// ══════════════════════════════════════════════════════════════════════════════
$sheet2 = $spreadsheet->createSheet();
$sheet2->setTitle("Detail Items");

/* TITLE */
$sheet2->mergeCells('A1:L1');
$sheet2->setCellValue('A1', 'MONTHLY CHECK SHEET REPORT — DETAIL ITEMS');
$sheet2->getStyle('A1')->getFont()->setBold(true)->setSize(15);
$sheet2->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
$sheet2->getRowDimension(1)->setRowHeight(45);

$sheet2->mergeCells('A2:L2');
$sheet2->setCellValue('A2', 'Bulan : ' . $bulan);
$sheet2->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

/* HEADER */
$hdrs2 = [
    'No',
    'Check Date',
    'Department',
    'Line',
    'Mesin',
    'Checker',
    'Category',
    'Item No',
    'Part to be Checked',
    'Standard',
    'Result',
    'Keterangan'
];
$sheet2->fromArray($hdrs2, NULL, 'A4');
$sheet2->getStyle('A4:L4')->applyFromArray([
    'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 9],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4F46E5']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet2->getRowDimension(4)->setRowHeight(18);

$row2   = 5;
$no2    = 1;
$resultColors = [
    'V'  => ['bg' => 'DCFCE7', 'fg' => '15803D'],
    'X'  => ['bg' => 'FEE2E2', 'fg' => 'DC2626'],
    'R'  => ['bg' => 'FEF9C3', 'fg' => 'CA8A04'],
    'RO' => ['bg' => 'EDE9FE', 'fg' => '7C3AED'],
    '-'  => ['bg' => 'F1F5F9', 'fg' => '94A3B8'],
];

foreach ($submissions as $sub) {
    $stmtDet2 = $pdo->prepare("
        SELECT no, part, standard, result, note
        FROM checksheet_submission_details
        WHERE submission_id = ?
        ORDER BY no
    ");
    $stmtDet2->execute([$sub['id']]);
    $items = $stmtDet2->fetchAll();

    foreach ($items as $item) {
        $sheet2->fromArray([
            $no2++,
            $sub['check_date'],
            $sub['department'],
            $sub['line'],
            $sub['machine_name'],
            $sub['checker'],
            $sub['category_key'],
            $item['no'],
            $item['part'],
            $item['standard'],
            $item['result'],
            $resultLabel[$item['result']] ?? $item['result'],
        ], NULL, "A{$row2}");

        $rc = $resultColors[$item['result']] ?? ['bg' => 'FFFFFF', 'fg' => '000000'];
        $sheet2->getStyle("K{$row2}")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $rc['bg']]],
            'font' => ['bold' => true, 'color' => ['rgb' => $rc['fg']]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        $sheet2->getStyle("A{$row2}:L{$row2}")->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet2->getRowDimension($row2)->setRowHeight(14);
        $row2++;
    }
}

$sheet2->getStyle("A4:L" . ($row2 - 1))->applyFromArray([
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E2E8F0']]],
]);
foreach (range('A', 'L') as $col) {
    $sheet2->getColumnDimension($col)->setAutoSize(true);
}
$sheet2->freezePane('A5');

// Aktifkan sheet pertama
$spreadsheet->setActiveSheetIndex(0);

/* ── EXPORT ── */
$writer   = new Xlsx($spreadsheet);
$filename = "CheckSheet_Monthly_{$bulan}.xlsx";

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment;filename=\"{$filename}\"");
header('Cache-Control: max-age=0');

$writer->save("php://output");
exit;
