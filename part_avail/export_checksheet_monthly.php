<?php
// export_checksheet_monthly.php
set_time_limit(0);          // [FIX-1] Unlimited — data bulanan bisa butuh waktu jauh lebih dari 120 detik
ini_set('memory_limit', '512M'); // [FIX-1] Naikkan dari 256M — Sheet 2 detail items bisa ribuan rows

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

// ── Format filename: CheckSheet_monthly_YYYY_MM ───────────────────────────────
$dtParts  = explode('-', $bulan);
$filename = 'CheckSheet_monthly_' . implode('_', $dtParts) . '.xlsx';

// ── Ambil semua submission ─────────────────────────────────────────────────────
$stmtSub = $pdo->prepare("
    SELECT id, check_date, department, line, op, machine_name,
           machine_type, category_key, checker, submitted_at
    FROM checksheet_submissions
    WHERE DATE_FORMAT(check_date, '%Y-%m') = ?
    ORDER BY check_date ASC, submitted_at ASC
");
$stmtSub->execute([$bulan]);
$submissions = $stmtSub->fetchAll(PDO::FETCH_ASSOC);

if (empty($submissions)) die("Tidak ada data untuk bulan $bulan.");

// ── BULK FETCH detail untuk Sheet1 (result saja) ──────────────────────────────
$subIds       = array_column($submissions, 'id');
$placeholders = implode(',', array_fill(0, count($subIds), '?'));

$stmtR = $pdo->prepare("
    SELECT submission_id, result
    FROM checksheet_submission_details
    WHERE submission_id IN ($placeholders)
");
$stmtR->execute($subIds);
$allResults = $stmtR->fetchAll(PDO::FETCH_ASSOC);

// Group by submission_id, hitung langsung
$countMap = []; // [id => ['total'=>n,'ok'=>n,'x'=>n,'r'=>n,'ro'=>n]]
foreach ($subIds as $sid) {
    $countMap[$sid] = ['total' => 0, 'ok' => 0, 'x' => 0, 'r' => 0, 'ro' => 0];
}
foreach ($allResults as $d) {
    $sid = $d['submission_id'];
    $countMap[$sid]['total']++;
    if ($d['result'] === 'V')  $countMap[$sid]['ok']++;
    if ($d['result'] === 'X')  $countMap[$sid]['x']++;
    if ($d['result'] === 'R')  $countMap[$sid]['r']++;
    if ($d['result'] === 'RO') $countMap[$sid]['ro']++;
}

// ── BULK FETCH detail lengkap untuk Sheet2 ────────────────────────────────────
$stmtDet = $pdo->prepare("
    SELECT submission_id, no, part, standard, result, note
    FROM checksheet_submission_details
    WHERE submission_id IN ($placeholders)
    ORDER BY submission_id, no
");
$stmtDet->execute($subIds);
$allDetails = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

$detailMap = [];
foreach ($allDetails as $d) {
    $detailMap[$d['submission_id']][] = $d;
}

// ── Konstanta style ───────────────────────────────────────────────────────────
$resultLabel = [
    'V'  => 'OK',
    'X'  => 'Problem',
    'R'  => 'Repair',
    'RO' => 'Repair by Outsider',
    '-'  => 'Tidak Digunakan',
];
$resultColors = [
    'V'  => ['bg' => 'DCFCE7', 'fg' => '15803D'],
    'X'  => ['bg' => 'FEE2E2', 'fg' => 'DC2626'],
    'R'  => ['bg' => 'FEF9C3', 'fg' => 'CA8A04'],
    'RO' => ['bg' => 'EDE9FE', 'fg' => '7C3AED'],
    '-'  => ['bg' => 'F1F5F9', 'fg' => '94A3B8'],
];

// ── Excel ─────────────────────────────────────────────────────────────────────
$spreadsheet = new Spreadsheet();
$spreadsheet->getCalculationEngine()->disableCalculationCache();

// ══════════════════════════════════════════════════════════════════════════════
// SHEET 1: Summary
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

$sheet1->mergeCells('A1:O1');
$sheet1->setCellValue('A1', 'MONTHLY CHECK SHEET REPORT — SUMMARY');
$sheet1->getStyle('A1')->getFont()->setBold(true)->setSize(15);
$sheet1->getStyle('A1')->getAlignment()
    ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
$sheet1->getRowDimension(1)->setRowHeight(45);

$sheet1->mergeCells('A2:O2');
$sheet1->setCellValue('A2', 'Bulan : ' . $bulan);
$sheet1->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet1->getStyle('A2')->getFont()->setSize(11);

$sheet1->fromArray([
    'No',
    'Check Date',
    'Department',
    'Line',
    'OP',
    'Machine Name',
    'Machine Type',
    'Category',
    'Checker',
    'Submitted At',
    'Total Items',
    'OK (V)',
    'Problem (X)',
    'Repair (R)',
    'Repair Outsider (RO)'
], NULL, 'A4');
$sheet1->getStyle('A4:O4')->applyFromArray([
    'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 9],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '198754']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet1->getRowDimension(4)->setRowHeight(18);

$row1        = 5;
$no          = 1;
$grandTotal  = ['items' => 0, 'ok' => 0, 'x' => 0, 'r' => 0, 'ro' => 0];
$prevDate    = '';

// Kumpulkan baris yang perlu coloring problem/repair untuk diterapkan massal
$colorRowsX  = []; // baris dengan problem (X > 0)
$colorRowsR  = []; // baris dengan repair
$colorRowsRO = []; // baris dengan repair outsider
$dataRowsS1  = []; // range baris data (untuk alignment massal)

foreach ($submissions as $sub) {
    $curDate = substr($sub['check_date'], 0, 10);

    if ($curDate !== $prevDate) {
        $sheet1->mergeCells("A{$row1}:O{$row1}");
        $sheet1->setCellValue("A{$row1}", "— " . date('d F Y', strtotime($curDate)) . " —");
        $sheet1->getStyle("A{$row1}:O{$row1}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 9, 'color' => ['rgb' => '64748B'], 'italic' => true],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F1F5F9']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CBD5E1']]],
        ]);
        $sheet1->getRowDimension($row1)->setRowHeight(14);
        $row1++;
        $prevDate = $curDate;
    }

    $c = $countMap[$sub['id']];
    $grandTotal['items'] += $c['total'];
    $grandTotal['ok']    += $c['ok'];
    $grandTotal['x']     += $c['x'];
    $grandTotal['r']     += $c['r'];
    $grandTotal['ro']    += $c['ro'];

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
        $sub['submitted_at'],
        $c['total'],
        $c['ok'],
        $c['x'],
        $c['r'],
        $c['ro'],
    ], NULL, "A{$row1}");

    // Catat baris yang butuh coloring
    if ($c['x']  > 0) $colorRowsX[]  = $row1;
    if ($c['r']  > 0) $colorRowsR[]  = $row1;
    if ($c['ro'] > 0) $colorRowsRO[] = $row1;

    $dataRowsS1[] = $row1;
    // [FIX-WRAP] -1 = auto-height, tinggi baris menyesuaikan konten wrap text
    $sheet1->getRowDimension($row1)->setRowHeight(-1);
    $row1++;
}

// Terapkan alignment data rows Sheet1 massal
if (!empty($dataRowsS1)) {
    $s1DataRange = "A{$dataRowsS1[0]}:O{$dataRowsS1[count($dataRowsS1) - 1]}";
    $sheet1->getStyle($s1DataRange)->getAlignment()
        ->setVertical(Alignment::VERTICAL_CENTER)
        ->setHorizontal(Alignment::HORIZONTAL_CENTER)
        ->setWrapText(true);
    // Kolom teks kiri
    $sheet1->getStyle("C{$dataRowsS1[0]}:F{$dataRowsS1[count($dataRowsS1) - 1]}")
        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
}

// Coloring problem/repair massal
$styleX  = ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEE2E2']], 'font' => ['bold' => true, 'color' => ['rgb' => 'DC2626']]];
$styleR  = ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEF9C3']], 'font' => ['bold' => true, 'color' => ['rgb' => 'CA8A04']]];
$styleRO = ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EDE9FE']], 'font' => ['bold' => true, 'color' => ['rgb' => '7C3AED']]];
foreach ($colorRowsX  as $r) $sheet1->getStyle("M{$r}")->applyFromArray($styleX);
foreach ($colorRowsR  as $r) $sheet1->getStyle("N{$r}")->applyFromArray($styleR);
foreach ($colorRowsRO as $r) $sheet1->getStyle("O{$r}")->applyFromArray($styleRO);

/* Grand Total Row */
$sheet1->mergeCells("A{$row1}:J{$row1}");
$sheet1->setCellValue("A{$row1}", "TOTAL");
$sheet1->fromArray([
    $grandTotal['items'],
    $grandTotal['ok'],
    $grandTotal['x'],
    $grandTotal['r'],
    $grandTotal['ro']
], NULL, "K{$row1}");
$sheet1->getStyle("A{$row1}:O{$row1}")->applyFromArray([
    'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E293B']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet1->getRowDimension($row1)->setRowHeight(18);

// Border seluruh Sheet1 — 1 call
// [FIX-BORDER] E2E8F0 terlalu pucat, hampir tidak terlihat di Excel — diganti 94A3B8
$sheet1->getStyle("A4:O{$row1}")->applyFromArray([
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '94A3B8']]],
]);

// [FIX-2] Ganti setAutoSize(true) ke fixed width
// Sheet1 Summary: kolom cukup dengan lebar tetap, jauh lebih cepat
$fixedWidthsS1 = [
    'A' => 5,   // No
    'B' => 13,  // Check Date
    'C' => 20,  // Department
    'D' => 16,  // Line
    'E' => 8,   // OP
    'F' => 22,  // Machine Name
    'G' => 16,  // Machine Type
    'H' => 14,  // Category
    'I' => 16,  // Checker
    'J' => 18,  // Submitted At
    'K' => 10,  // Total Items
    'L' => 8,   // OK (V)
    'M' => 12,  // Problem (X)
    'N' => 10,  // Repair (R)
    'O' => 18,  // Repair Outsider (RO)
];
foreach ($fixedWidthsS1 as $col => $w) {
    $sheet1->getColumnDimension($col)->setWidth($w);
}
$sheet1->freezePane('A5');

// ══════════════════════════════════════════════════════════════════════════════
// SHEET 2: Detail Items
// ══════════════════════════════════════════════════════════════════════════════
$sheet2 = $spreadsheet->createSheet();
$sheet2->setTitle("Detail Items");

$sheet2->mergeCells('A1:M1');
$sheet2->setCellValue('A1', 'MONTHLY CHECK SHEET REPORT — DETAIL ITEMS');
$sheet2->getStyle('A1')->getFont()->setBold(true)->setSize(15);
$sheet2->getStyle('A1')->getAlignment()
    ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
$sheet2->getRowDimension(1)->setRowHeight(45);

$sheet2->mergeCells('A2:M2');
$sheet2->setCellValue('A2', 'Bulan : ' . $bulan);
$sheet2->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet2->fromArray([
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
    'Keterangan',
    'Submitted At'
], NULL, 'A4');
$sheet2->getStyle('A4:M4')->applyFromArray([
    'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 9],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4F46E5']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet2->getRowDimension(4)->setRowHeight(18);

$row2      = 5;
$no2       = 1;
$prevDate  = '';
// Kumpulkan result rows untuk coloring massal
$resultRowsS2 = []; // ['row' => n, 'result' => 'V']

foreach ($submissions as $sub) {
    $items = $detailMap[$sub['id']] ?? [];
    if (empty($items)) continue;

    // Pemisah tanggal di Sheet2
    $curDate = substr($sub['check_date'], 0, 10);
    if ($curDate !== $prevDate) {
        $sheet2->mergeCells("A{$row2}:M{$row2}");
        $sheet2->setCellValue("A{$row2}", "— " . date('d F Y', strtotime($curDate)) . " —");
        $sheet2->getStyle("A{$row2}:M{$row2}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 9, 'color' => ['rgb' => '64748B'], 'italic' => true],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F1F5F9']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CBD5E1']]],
        ]);
        $sheet2->getRowDimension($row2)->setRowHeight(14);
        $row2++;
        $prevDate = $curDate;
    }

    $blockStart = $row2;
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
            $sub['submitted_at'],
        ], NULL, "A{$row2}");

        $resultRowsS2[] = ['row' => $row2, 'result' => $item['result']];
        // [FIX-WRAP] -1 = auto-height, tinggi baris menyesuaikan konten wrap text
        $sheet2->getRowDimension($row2)->setRowHeight(-1);
        $row2++;
    }

    // Alignment untuk blok submission ini sekaligus
    $sheet2->getStyle("A{$blockStart}:M" . ($row2 - 1))
        ->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
}

// Coloring result massal per grup
$groupedResult = [];
foreach ($resultRowsS2 as $item) {
    $groupedResult[$item['result']][] = $item['row'];
}
foreach ($groupedResult as $result => $rows) {
    $rc = $resultColors[$result] ?? ['bg' => 'FFFFFF', 'fg' => '000000'];
    $styleResult = [
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $rc['bg']]],
        'font'      => ['bold' => true, 'color' => ['rgb' => $rc['fg']]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ];
    foreach ($rows as $r) {
        $sheet2->getStyle("K{$r}")->applyFromArray($styleResult);
    }
}

// Border seluruh Sheet2 — 1 call
// [FIX-BORDER] E2E8F0 terlalu pucat, hampir tidak terlihat di Excel — diganti 94A3B8
if ($row2 > 5) {
    $sheet2->getStyle("A4:M" . ($row2 - 1))->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '94A3B8']]],
    ]);
}
// [FIX-2] Ganti setAutoSize(true) ke fixed width (Sheet2 Detail — bisa ribuan rows)
// autoSize di Sheet2 adalah bottleneck terbesar karena jumlah row jauh lebih banyak
$fixedWidthsS2 = [
    'A' => 5,   // No
    'B' => 13,  // Check Date
    'C' => 20,  // Department
    'D' => 16,  // Line
    'E' => 22,  // Mesin
    'F' => 16,  // Checker
    'G' => 14,  // Category
    'H' => 8,   // Item No
    'I' => 28,  // Part to be Checked
    'J' => 22,  // Standard
    'K' => 12,  // Result
    'L' => 16,  // Keterangan
    'M' => 18,  // Submitted At
];
foreach ($fixedWidthsS2 as $col => $w) {
    $sheet2->getColumnDimension($col)->setWidth($w);
}
$sheet2->freezePane('A5');

$spreadsheet->setActiveSheetIndex(0); // pastikan yang dibuka pertama adalah Summary

// ── Export ─────────────────────────────────────────────────────────────────────
// [FIX-3] Save ke file temp dulu, baru stream ke browser
// Langsung ke php://output berisiko: koneksi browser bisa timeout sebelum
// PhpSpreadsheet selesai generate (terutama data bulanan yang besar).
// Content-Length memberi tahu browser ukuran file — tidak dianggap "menggantung".
$writer = new Xlsx($spreadsheet);
$writer->setPreCalculateFormulas(false);

$tmpFile = tempnam(sys_get_temp_dir(), 'cs_monthly_');
$writer->save($tmpFile);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"{$filename}\"");
header('Cache-Control: max-age=0');
header('Content-Length: ' . filesize($tmpFile));

readfile($tmpFile);
unlink($tmpFile);
exit;
