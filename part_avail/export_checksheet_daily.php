<?php
// export_checksheet_daily.php
set_time_limit(120);
ini_set('memory_limit', '256M');

session_start();
include 'config.php';
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$tanggal = $_GET['tanggal'] ?? '';
if ($tanggal == '') die("Pilih tanggal terlebih dahulu.");

// ── Format filename: CheckSheet_daily_YYYY_MM_DD ──────────────────────────────
$dtParts  = explode('-', $tanggal);
$filename = 'CheckSheet_daily_' . implode('_', $dtParts) . '.xlsx';

// ── Ambil semua submission ────────────────────────────────────────────────────
$stmtSub = $pdo->prepare("
    SELECT id, department, line, op, machine_name, machine_type,
           category_key, checker, submitted_at, check_date
    FROM checksheet_submissions
    WHERE DATE(check_date) = ?
    ORDER BY submitted_at ASC
");
$stmtSub->execute([$tanggal]);
$submissions = $stmtSub->fetchAll(PDO::FETCH_ASSOC);

if (empty($submissions)) die("Tidak ada data untuk tanggal $tanggal.");

// ── BULK FETCH semua detail sekaligus (anti N+1) ──────────────────────────────
$subIds         = array_column($submissions, 'id');
$placeholders   = implode(',', array_fill(0, count($subIds), '?'));

$stmtDet = $pdo->prepare("
    SELECT d.submission_id, d.no, d.part, d.standard, d.result, d.note,
           i.method, i.action AS act, i.`interval`
    FROM checksheet_submission_details d
    LEFT JOIN checksheet_items i ON i.id = d.item_id
    WHERE d.submission_id IN ($placeholders)
    ORDER BY d.submission_id, d.no
");
$stmtDet->execute($subIds);
$allDetails = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

// Group by submission_id
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

// Style yang di-share antar baris biasa (tidak perlu repeat per baris)
$styleInfo = [
    'font'      => ['bold' => true, 'size' => 9, 'color' => ['rgb' => 'FFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '334155']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
];
$styleHdr = [
    'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 9],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '198754']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
];
$styleSummary = [
    'font'      => ['bold' => true, 'italic' => true, 'size' => 8, 'color' => ['rgb' => '475569']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F8FAFC']],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
];
$styleBorder = [
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E2E8F0']]],
];

// ── Excel ─────────────────────────────────────────────────────────────────────
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle("Daily Report");

// Nonaktifkan kalkulasi otomatis selama pengisian (percepat proses)
$spreadsheet->getCalculationEngine()->disableCalculationCache();
$sheet->setSelectedCell('A1');

if (file_exists('assets/company_logo.jpg')) {
    $logo = new Drawing();
    $logo->setName('Company Logo');
    $logo->setPath('assets/company_logo.jpg');
    $logo->setHeight(55);
    $logo->setCoordinates('A1');
    $logo->setWorksheet($sheet);
}

$sheet->mergeCells('A1:M1');
$sheet->setCellValue('A1', 'DAILY CHECK SHEET REPORT');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(15);
$sheet->getStyle('A1')->getAlignment()
    ->setHorizontal(Alignment::HORIZONTAL_CENTER)
    ->setVertical(Alignment::VERTICAL_CENTER);
$sheet->getRowDimension(1)->setRowHeight(45);

$sheet->mergeCells('A2:M2');
$sheet->setCellValue('A2', 'Tanggal : ' . $tanggal);
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A2')->getFont()->setSize(11);

$headers = [
    'No',
    'Part to be Checked',
    'Standard',
    'Checking Method',
    'Action',
    'Interval',
    'Result',
    'Keterangan',
    'Category',
    'Submission ID',
    'Check Date',
    'Checker',
    'Submitted At'
];

$startRow = 4;

// Kumpulkan range result untuk coloring massal di akhir
$resultRanges = []; // ['range' => 'G5', 'result' => 'V']

foreach ($submissions as $sub) {
    $details  = $detailMap[$sub['id']] ?? [];
    $infoRow  = $startRow;

    // Info bar
    $infoText = sprintf(
        "Department: %s  |  Line: %s  |  OP: %s  |  Mesin: %s  |  Type: %s  |  Checker: %s  |  Submitted: %s",
        $sub['department'],
        $sub['line'],
        $sub['op'],
        $sub['machine_name'],
        $sub['machine_type'],
        $sub['checker'],
        $sub['submitted_at']
    );
    $sheet->mergeCells("A{$infoRow}:M{$infoRow}");
    $sheet->setCellValue("A{$infoRow}", $infoText);
    $sheet->getStyle("A{$infoRow}")->applyFromArray($styleInfo);
    $sheet->getRowDimension($infoRow)->setRowHeight(18);
    $startRow++;

    // Header kolom
    $hdrRow = $startRow;
    $sheet->fromArray($headers, NULL, "A{$hdrRow}");
    $sheet->getStyle("A{$hdrRow}:M{$hdrRow}")->applyFromArray($styleHdr);
    $sheet->getRowDimension($hdrRow)->setRowHeight(16);
    $startRow++;

    // Data rows — isi semua cell dulu, style belakangan
    $dataStartRow = $startRow;
    foreach ($details as $det) {
        $sheet->fromArray([
            $det['no'],
            $det['part'],
            $det['standard'],
            $det['method']   ?? '',
            $det['act']      ?? '',
            $det['interval'] ?? '',
            $det['result'],
            $resultLabel[$det['result']] ?? $det['result'],
            $sub['category_key'],
            $sub['id'],
            $sub['check_date'],
            $sub['checker'],
            $sub['submitted_at'],
        ], NULL, "A{$startRow}");

        // Catat range result untuk coloring massal nanti
        $resultRanges[] = ['row' => $startRow, 'result' => $det['result']];

        $sheet->getRowDimension($startRow)->setRowHeight(14);
        $startRow++;
    }

    // Style alignment untuk semua data rows sekaligus (bukan per baris)
    if ($startRow > $dataStartRow) {
        $sheet->getStyle("A{$dataStartRow}:M" . ($startRow - 1))
            ->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    }

    // Summary row
    $okCount = count(array_filter($details, fn($d) => $d['result'] === 'V'));
    $xCount  = count(array_filter($details, fn($d) => $d['result'] === 'X'));
    $rCount  = count(array_filter($details, fn($d) => in_array($d['result'], ['R', 'RO'])));
    $total   = count($details);

    $sheet->mergeCells("A{$startRow}:F{$startRow}");
    $sheet->setCellValue("A{$startRow}", "Summary: {$total} item — OK: {$okCount}  Problem: {$xCount}  Repair: {$rCount}");
    $sheet->getStyle("A{$startRow}:M{$startRow}")->applyFromArray($styleSummary);
    $sheet->getRowDimension($startRow)->setRowHeight(14);

    // Border seluruh blok sekaligus
    $sheet->getStyle("A{$infoRow}:M{$startRow}")->applyFromArray($styleBorder);

    $startRow += 2;
}

// ── Apply warna result secara massal per grup ─────────────────────────────────
// Group rows by result value untuk minimalkan applyFromArray call
$groupedResult = [];
foreach ($resultRanges as $item) {
    $groupedResult[$item['result']][] = $item['row'];
}
foreach ($groupedResult as $result => $rows) {
    $rc = $resultColors[$result] ?? ['bg' => 'FFFFFF', 'fg' => '000000'];
    $styleResult = [
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $rc['bg']]],
        'font'      => ['bold' => true, 'color' => ['rgb' => $rc['fg']]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ];
    // Terapkan ke setiap row dalam grup ini
    foreach ($rows as $r) {
        $sheet->getStyle("G{$r}")->applyFromArray($styleResult);
    }
}

// AutoSize kolom
foreach (range('A', 'M') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}
$sheet->freezePane('A4');

// ── Export ────────────────────────────────────────────────────────────────────
$writer = new Xlsx($spreadsheet);
$writer->setPreCalculateFormulas(false); // skip formula recalc → lebih cepat

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"{$filename}\"");
header('Cache-Control: max-age=0');
$writer->save('php://output');
exit;
