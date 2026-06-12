<?php
// export_history_report.php
set_time_limit(120);
ini_set('memory_limit', '256M');

session_start();
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login_user.php');
    exit;
}

require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

// ── Parameter ──────────────────────────────────────────────────────────────────
$mode    = $_GET['mode']    ?? 'daily';
$tanggal = $_GET['tanggal'] ?? '';
$bulan   = $_GET['bulan']   ?? '';

// ── Validasi + filename ───────────────────────────────────────────────────────
if ($mode === 'daily') {
    if ($tanggal === '') die('Pilih tanggal terlebih dahulu.');
    $whereDate    = "DATE(r.report_date) = " . $pdo->quote($tanggal);
    $periodeLabel = 'Tanggal : ' . $tanggal;
    $dtParts      = explode('-', $tanggal);
    $filename     = 'History_EReport_daily_' . implode('_', $dtParts) . '.xlsx';
} elseif ($mode === 'monthly') {
    if ($bulan === '') die('Pilih bulan terlebih dahulu.');
    $whereDate    = "DATE_FORMAT(r.report_date, '%Y-%m') = " . $pdo->quote($bulan);
    $periodeLabel = 'Bulan : ' . $bulan;
    $dtParts      = explode('-', $bulan);
    $filename     = 'History_EReport_monthly_' . implode('_', $dtParts) . '.xlsx';
} else {
    die('Mode tidak valid.');
}

// ── Query ─────────────────────────────────────────────────────────────────────
$rows = $pdo->query("
    SELECT r.id, r.report_date, r.department, r.line, r.op,
           r.machine_name, r.machine_type, r.repair_start, r.repair_finish,
           r.reported_by, r.pic, r.problem, r.action, r.created_at
    FROM e_reports r
    WHERE {$whereDate}
    ORDER BY r.report_date ASC, r.created_at ASC
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) die("Tidak ada data untuk periode yang dipilih.");

// ── Helper durasi ─────────────────────────────────────────────────────────────
function durasiMenit($start, $finish)
{
    if (empty($start) || empty($finish)) return '—';
    $diff = (new DateTime($start))->diff(new DateTime($finish));
    return ($diff->days * 1440) + ($diff->h * 60) + $diff->i;
}

// ── Shared styles ─────────────────────────────────────────────────────────────
$styleDivider = [
    'font'      => ['bold' => true, 'size' => 9, 'color' => ['rgb' => '64748B'], 'italic' => true],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F1F5F9']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CBD5E1']]],
];
$styleBorder = [
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E2E8F0']]],
];

// Kolom header
$colHeaders = [
    'No',
    'Report Date',
    'Department',
    'Line',
    'OP',
    'Nama Mesin',
    'Tipe Mesin',
    'Repair Start',
    'Repair Finish',
    'Durasi (mnt)',
    'Reported By',
    'PIC / Teknisi',
    'Problem / Alarm',
    'Action / Perbaikan',
    'Submitted At'
];

// ── Excel ─────────────────────────────────────────────────────────────────────
$spreadsheet = new Spreadsheet();
$spreadsheet->getCalculationEngine()->disableCalculationCache();

// ═════════════════════════════════════════════════════════════════════════════
// MODE DAILY — 1 sheet, 1 blok per record
// ═════════════════════════════════════════════════════════════════════════════
if ($mode === 'daily') {

    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle("Daily E-Report");

    if (file_exists('assets/company_logo.jpg')) {
        $logo = new Drawing();
        $logo->setName('Company Logo');
        $logo->setPath('assets/company_logo.jpg');
        $logo->setHeight(55);
        $logo->setCoordinates('A1');
        $logo->setWorksheet($sheet);
    }

    $sheet->mergeCells('A1:O1');
    $sheet->setCellValue('A1', 'HISTORY E-REPORT MAINTENANCE');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(15);
    $sheet->getStyle('A1')->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->getRowDimension(1)->setRowHeight(45);
    $sheet->mergeCells('A2:O2');
    $sheet->setCellValue('A2', $periodeLabel);
    $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('A2')->getFont()->setSize(11);

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

    $startRow = 4;
    foreach ($rows as $idx => $r) {
        $infoRow  = $startRow;
        $infoText = sprintf(
            "Department: %s  |  Line: %s  |  OP: %s  |  Mesin: %s  |  Type: %s  |  Reported By: %s  |  Submitted: %s",
            $r['department'],
            $r['line'],
            $r['op'],
            $r['machine_name'],
            $r['machine_type'],
            $r['reported_by'],
            $r['created_at']
        );
        $sheet->mergeCells("A{$infoRow}:O{$infoRow}");
        $sheet->setCellValue("A{$infoRow}", $infoText);
        $sheet->getStyle("A{$infoRow}")->applyFromArray($styleInfo);
        $sheet->getRowDimension($infoRow)->setRowHeight(18);
        $startRow++;

        $hdrRow = $startRow;
        $sheet->fromArray($colHeaders, NULL, "A{$hdrRow}");
        $sheet->getStyle("A{$hdrRow}:O{$hdrRow}")->applyFromArray($styleHdr);
        $sheet->getRowDimension($hdrRow)->setRowHeight(16);
        $startRow++;

        $sheet->fromArray([
            $idx + 1,
            $r['report_date']   ? date('d-M-Y', strtotime($r['report_date']))        : '—',
            $r['department']    ?? '—',
            $r['line']          ?? '—',
            $r['op']            ?? '—',
            $r['machine_name']  ?? '—',
            $r['machine_type']  ?? '—',
            $r['repair_start']  ? date('d-M-Y H:i', strtotime($r['repair_start']))   : '—',
            $r['repair_finish'] ? date('d-M-Y H:i', strtotime($r['repair_finish']))  : '—',
            durasiMenit($r['repair_start'], $r['repair_finish']),
            $r['reported_by']   ?? '—',
            $r['pic']           ?? '—',
            $r['problem']       ?? '—',
            $r['action']        ?? '—',
            $r['created_at']    ? date('d-M-Y H:i', strtotime($r['created_at']))     : '—',
        ], NULL, "A{$startRow}");
        $sheet->getStyle("A{$startRow}:O{$startRow}")->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getRowDimension($startRow)->setRowHeight(14);

        foreach (['A', 'E', 'J'] as $col) {
            $sheet->getStyle("{$col}{$startRow}")
                ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        $sheet->getStyle("A{$infoRow}:O{$startRow}")->applyFromArray($styleBorder);
        $startRow += 2;
    }

    $colWidths = [
        'A' => 5,
        'B' => 13,
        'C' => 18,
        'D' => 14,
        'E' => 8,
        'F' => 24,
        'G' => 16,
        'H' => 18,
        'I' => 18,
        'J' => 10,
        'K' => 16,
        'L' => 16,
        'M' => 38,
        'N' => 38,
        'O' => 18
    ];
    foreach ($colWidths as $col => $w) {
        $sheet->getColumnDimension($col)->setWidth($w);
    }
    $sheet->freezePane('A4');

    // ═════════════════════════════════════════════════════════════════════════════
    // MODE MONTHLY — 2 sheet, template identik checksheet_monthly
    // ═════════════════════════════════════════════════════════════════════════════
} else {

    // ══ SHEET 1: Summary ══════════════════════════════════════════════════════
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
    $sheet1->setCellValue('A1', 'MONTHLY E-REPORT MAINTENANCE — SUMMARY');
    $sheet1->getStyle('A1')->getFont()->setBold(true)->setSize(15);
    $sheet1->getStyle('A1')->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    $sheet1->getRowDimension(1)->setRowHeight(45);
    $sheet1->mergeCells('A2:O2');
    $sheet1->setCellValue('A2', $periodeLabel);
    $sheet1->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet1->getStyle('A2')->getFont()->setSize(11);

    $sheet1->fromArray($colHeaders, NULL, 'A4');
    $sheet1->getStyle('A4:O4')->applyFromArray([
        'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 9],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '198754']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet1->getRowDimension(4)->setRowHeight(18);

    $row1       = 5;
    $no1        = 1;
    $prevDate   = '';
    $dataRowsS1 = [];

    foreach ($rows as $r) {
        $curDate = substr($r['report_date'], 0, 10);

        // Pemisah tanggal — identik checksheet monthly
        if ($curDate !== $prevDate) {
            $sheet1->mergeCells("A{$row1}:O{$row1}");
            $sheet1->setCellValue("A{$row1}", "— " . date('d F Y', strtotime($curDate)) . " —");
            $sheet1->getStyle("A{$row1}:O{$row1}")->applyFromArray($styleDivider);
            $sheet1->getRowDimension($row1)->setRowHeight(14);
            $row1++;
            $prevDate = $curDate;
        }

        $sheet1->fromArray([
            $no1++,
            $r['report_date']   ? date('d-M-Y', strtotime($r['report_date']))        : '—',
            $r['department']    ?? '—',
            $r['line']          ?? '—',
            $r['op']            ?? '—',
            $r['machine_name']  ?? '—',
            $r['machine_type']  ?? '—',
            $r['repair_start']  ? date('d-M-Y H:i', strtotime($r['repair_start']))   : '—',
            $r['repair_finish'] ? date('d-M-Y H:i', strtotime($r['repair_finish']))  : '—',
            durasiMenit($r['repair_start'], $r['repair_finish']),
            $r['reported_by']   ?? '—',
            $r['pic']           ?? '—',
            $r['problem']       ?? '—',
            $r['action']        ?? '—',
            $r['created_at']    ? date('d-M-Y H:i', strtotime($r['created_at']))     : '—',
        ], NULL, "A{$row1}");

        $dataRowsS1[] = $row1;
        $sheet1->getRowDimension($row1)->setRowHeight(15);
        $row1++;
    }

    // Alignment massal Sheet1
    if (!empty($dataRowsS1)) {
        $first = $dataRowsS1[0];
        $last  = $dataRowsS1[count($dataRowsS1) - 1];
        $sheet1->getStyle("A{$first}:O{$last}")->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setWrapText(true);
        // Kolom teks (C–N) rata kiri
        $sheet1->getStyle("C{$first}:N{$last}")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT);
    }

    // Grand Total row — identik checksheet monthly
    $sheet1->mergeCells("A{$row1}:O{$row1}");
    $sheet1->setCellValue("A{$row1}", "TOTAL : " . count($rows) . " record");
    $sheet1->getStyle("A{$row1}:O{$row1}")->applyFromArray([
        'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E293B']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet1->getRowDimension($row1)->setRowHeight(18);

    $sheet1->getStyle("A4:O{$row1}")->applyFromArray($styleBorder);
    foreach (range('A', 'O') as $col) {
        $sheet1->getColumnDimension($col)->setAutoSize(true);
    }
    $sheet1->freezePane('A5');

    // ══ SHEET 2: Detail ═══════════════════════════════════════════════════════
    $sheet2 = $spreadsheet->createSheet();
    $sheet2->setTitle("Detail");

    $sheet2->mergeCells('A1:O1');
    $sheet2->setCellValue('A1', 'MONTHLY E-REPORT MAINTENANCE — DETAIL');
    $sheet2->getStyle('A1')->getFont()->setBold(true)->setSize(15);
    $sheet2->getStyle('A1')->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    $sheet2->getRowDimension(1)->setRowHeight(45);
    $sheet2->mergeCells('A2:O2');
    $sheet2->setCellValue('A2', $periodeLabel);
    $sheet2->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $sheet2->fromArray($colHeaders, NULL, 'A4');
    $sheet2->getStyle('A4:O4')->applyFromArray([
        'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 9],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4F46E5']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet2->getRowDimension(4)->setRowHeight(18);

    $row2       = 5;
    $no2        = 1;
    $prevDate   = '';
    $dataRowsS2 = [];

    foreach ($rows as $r) {
        $curDate = substr($r['report_date'], 0, 10);

        if ($curDate !== $prevDate) {
            $sheet2->mergeCells("A{$row2}:O{$row2}");
            $sheet2->setCellValue("A{$row2}", "— " . date('d F Y', strtotime($curDate)) . " —");
            $sheet2->getStyle("A{$row2}:O{$row2}")->applyFromArray($styleDivider);
            $sheet2->getRowDimension($row2)->setRowHeight(14);
            $row2++;
            $prevDate = $curDate;
        }

        $sheet2->fromArray([
            $no2++,
            $r['report_date']   ? date('d-M-Y', strtotime($r['report_date']))        : '—',
            $r['department']    ?? '—',
            $r['line']          ?? '—',
            $r['op']            ?? '—',
            $r['machine_name']  ?? '—',
            $r['machine_type']  ?? '—',
            $r['repair_start']  ? date('d-M-Y H:i', strtotime($r['repair_start']))   : '—',
            $r['repair_finish'] ? date('d-M-Y H:i', strtotime($r['repair_finish']))  : '—',
            durasiMenit($r['repair_start'], $r['repair_finish']),
            $r['reported_by']   ?? '—',
            $r['pic']           ?? '—',
            $r['problem']       ?? '—',
            $r['action']        ?? '—',
            $r['created_at']    ? date('d-M-Y H:i', strtotime($r['created_at']))     : '—',
        ], NULL, "A{$row2}");

        $dataRowsS2[] = $row2;
        $sheet2->getRowDimension($row2)->setRowHeight(14);
        $row2++;
    }

    // Alignment massal Sheet2
    if (!empty($dataRowsS2)) {
        $first = $dataRowsS2[0];
        $last  = $dataRowsS2[count($dataRowsS2) - 1];
        $sheet2->getStyle("A{$first}:O{$last}")->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet2->getStyle("C{$first}:N{$last}")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT);
    }

    if ($row2 > 5) {
        $sheet2->getStyle("A4:O" . ($row2 - 1))->applyFromArray($styleBorder);
    }
    foreach (range('A', 'O') as $col) {
        $sheet2->getColumnDimension($col)->setAutoSize(true);
    }
    $sheet2->freezePane('A5');

    $spreadsheet->setActiveSheetIndex(0);
}

// ── Export ─────────────────────────────────────────────────────────────────────
$writer = new Xlsx($spreadsheet);
$writer->setPreCalculateFormulas(false);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"{$filename}\"");
header('Cache-Control: max-age=0');
$writer->save('php://output');
exit;
