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
    $whereDate    = "DATE(r.created_at) = " . $pdo->quote($tanggal);
    $periodeLabel = 'Tanggal : ' . $tanggal;
    $dtParts      = explode('-', $tanggal);
    $filename     = 'History_EReport_daily_' . implode('_', $dtParts) . '.xlsx';
} elseif ($mode === 'monthly') {
    if ($bulan === '') die('Pilih bulan terlebih dahulu.');
    $whereDate    = "DATE_FORMAT(r.created_at, '%Y-%m') = " . $pdo->quote($bulan);
    $periodeLabel = 'Bulan : ' . $bulan;
    $dtParts      = explode('-', $bulan);
    $filename     = 'History_EReport_monthly_' . implode('_', $dtParts) . '.xlsx';
} else {
    die('Mode tidak valid.');
}

// ── Query ─────────────────────────────────────────────────────────────────────
$rows = $pdo->query("
    SELECT r.id, r.parent_id, r.report_date, r.department, r.line, r.op, r.shift,
           r.machine_name, r.machine_type, r.repair_start, r.repair_finish,
           r.reported_by, r.pic, r.problem, r.action, r.status, r.created_at
    FROM e_reports r
    WHERE {$whereDate}
    ORDER BY r.report_date ASC, r.created_at ASC
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) die("Tidak ada data untuk periode yang dipilih.");

// Map id (database) → nomor urut "No" di sheet, dipakai sama untuk semua mode
// karena urutan baris ($rows) identik antar sheet.
$idToNo = [];
foreach ($rows as $i => $r) {
    $idToNo[$r['id']] = $i + 1;
}

// ── Helper: rujukan "Lanjutan Dari" harus menunjuk ke nomor urut "No" yang
// tampil di sheet, bukan ID mentah di database — supaya konsisten dengan apa
// yang dilihat user dan bisa langsung dicocokkan ke baris di atasnya.
function buildLanjutanLabel($parentId, array $idToNo)
{
    if (!$parentId) return '—';
    return isset($idToNo[$parentId]) ? '#' . $idToNo[$parentId] : '#' . $parentId . ' (luar periode)';
}

// ── Helper durasi ─────────────────────────────────────────────────────────────
function durasiMenit($start, $finish)
{
    if (empty($start) || empty($finish)) return '—';
    $diff = (new DateTime($start))->diff(new DateTime($finish));
    return ($diff->days * 1440) + ($diff->h * 60) + $diff->i;
}

// ── Helper label status ────────────────────────────────────────────────────────
function statusLabel($status)
{
    return $status === 'selesai' ? 'Selesai' : 'Belum Selesai';
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
    'Shift',
    'Nama Mesin',
    'Tipe Mesin',
    'Repair Start',
    'Repair Finish',
    'Durasi (mnt)',
    'Reported By',
    'PIC / Teknisi',
    'Problem / Alarm',
    'Action / Perbaikan',
    'Status',
    'Lanjutan Dari',
    'Submitted At'
];

// ── Excel ─────────────────────────────────────────────────────────────────────
$spreadsheet = new Spreadsheet();
$spreadsheet->getCalculationEngine()->disableCalculationCache();

// Lebar kolom tetap (dipakai semua sheet) — N & O dilebarkan untuk Problem/Action
// dengan wrap text supaya isian berpoin-poin tetap terbaca rapi ke bawah, bukan
// melebar ke samping seperti hasil autosize.
$colWidths = [
    'A' => 5,   // No
    'B' => 13,  // Report Date
    'C' => 18,  // Department
    'D' => 14,  // Line
    'E' => 8,   // OP
    'F' => 10,  // Shift
    'G' => 24,  // Nama Mesin
    'H' => 16,  // Tipe Mesin
    'I' => 18,  // Repair Start
    'J' => 18,  // Repair Finish
    'K' => 10,  // Durasi (mnt)
    'L' => 16,  // Reported By
    'M' => 16,  // PIC / Teknisi
    'N' => 38,  // Problem / Alarm
    'O' => 38,  // Action / Perbaikan
    'P' => 14,  // Status
    'Q' => 14,  // Lanjutan Dari
    'R' => 18   // Submitted At
];

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

    $sheet->mergeCells('A1:R1');
    $sheet->setCellValue('A1', 'HISTORY E-REPORT MAINTENANCE');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(15);
    $sheet->getStyle('A1')->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->getRowDimension(1)->setRowHeight(45);
    $sheet->mergeCells('A2:R2');
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
        $sheet->mergeCells("A{$infoRow}:R{$infoRow}");
        $sheet->setCellValue("A{$infoRow}", $infoText);
        $sheet->getStyle("A{$infoRow}")->applyFromArray($styleInfo);
        $sheet->getRowDimension($infoRow)->setRowHeight(18);
        $startRow++;

        $hdrRow = $startRow;
        $sheet->fromArray($colHeaders, NULL, "A{$hdrRow}");
        $sheet->getStyle("A{$hdrRow}:R{$hdrRow}")->applyFromArray($styleHdr);
        $sheet->getRowDimension($hdrRow)->setRowHeight(16);
        $startRow++;

        $sheet->fromArray([
            $idx + 1,
            $r['report_date']   ? date('d-M-Y', strtotime($r['report_date']))        : '—',
            $r['department']    ?? '—',
            $r['line']          ?? '—',
            $r['op']            ?? '—',
            $r['shift']         ?? '—',
            $r['machine_name']  ?? '—',
            $r['machine_type']  ?? '—',
            $r['repair_start']  ? date('d-M-Y H:i', strtotime($r['repair_start']))   : '—',
            $r['repair_finish'] ? date('d-M-Y H:i', strtotime($r['repair_finish']))  : '—',
            durasiMenit($r['repair_start'], $r['repair_finish']),
            $r['reported_by']   ?? '—',
            $r['pic']           ?? '—',
            $r['problem']       ?? '—',
            $r['action']        ?? '—',
            statusLabel($r['status']),
            buildLanjutanLabel($r['parent_id'], $idToNo),
            $r['created_at']    ? date('d-M-Y H:i', strtotime($r['created_at']))     : '—',
        ], NULL, "A{$startRow}");
        $sheet->getStyle("A{$startRow}:R{$startRow}")->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getRowDimension($startRow)->setRowHeight(14);

        foreach (['A', 'E', 'F', 'K', 'P', 'Q'] as $col) {
            $sheet->getStyle("{$col}{$startRow}")
                ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        $sheet->getStyle("A{$infoRow}:R{$startRow}")->applyFromArray($styleBorder);
        $startRow += 2;
    }

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

    $sheet1->mergeCells('A1:R1');
    $sheet1->setCellValue('A1', 'MONTHLY E-REPORT MAINTENANCE — SUMMARY');
    $sheet1->getStyle('A1')->getFont()->setBold(true)->setSize(15);
    $sheet1->getStyle('A1')->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    $sheet1->getRowDimension(1)->setRowHeight(45);
    $sheet1->mergeCells('A2:R2');
    $sheet1->setCellValue('A2', $periodeLabel);
    $sheet1->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet1->getStyle('A2')->getFont()->setSize(11);

    $sheet1->fromArray($colHeaders, NULL, 'A4');
    $sheet1->getStyle('A4:R4')->applyFromArray([
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
            $sheet1->mergeCells("A{$row1}:R{$row1}");
            $sheet1->setCellValue("A{$row1}", "— " . date('d F Y', strtotime($curDate)) . " —");
            $sheet1->getStyle("A{$row1}:R{$row1}")->applyFromArray($styleDivider);
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
            $r['shift']         ?? '—',
            $r['machine_name']  ?? '—',
            $r['machine_type']  ?? '—',
            $r['repair_start']  ? date('d-M-Y H:i', strtotime($r['repair_start']))   : '—',
            $r['repair_finish'] ? date('d-M-Y H:i', strtotime($r['repair_finish']))  : '—',
            durasiMenit($r['repair_start'], $r['repair_finish']),
            $r['reported_by']   ?? '—',
            $r['pic']           ?? '—',
            $r['problem']       ?? '—',
            $r['action']        ?? '—',
            statusLabel($r['status']),
            buildLanjutanLabel($r['parent_id'], $idToNo),
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
        $sheet1->getStyle("A{$first}:R{$last}")->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setWrapText(true);
        // Kolom teks (C–O) rata kiri
        $sheet1->getStyle("C{$first}:O{$last}")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT);
    }

    // Grand Total row — identik checksheet monthly
    $sheet1->mergeCells("A{$row1}:R{$row1}");
    $sheet1->setCellValue("A{$row1}", "TOTAL : " . count($rows) . " record");
    $sheet1->getStyle("A{$row1}:R{$row1}")->applyFromArray([
        'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E293B']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet1->getRowDimension($row1)->setRowHeight(18);

    $sheet1->getStyle("A4:R{$row1}")->applyFromArray($styleBorder);
    foreach ($colWidths as $col => $w) {
        $sheet1->getColumnDimension($col)->setWidth($w);
    }
    $sheet1->freezePane('A5');

    // ══ SHEET 2: Detail ═══════════════════════════════════════════════════════
    $sheet2 = $spreadsheet->createSheet();
    $sheet2->setTitle("Detail");

    $sheet2->mergeCells('A1:R1');
    $sheet2->setCellValue('A1', 'MONTHLY E-REPORT MAINTENANCE — DETAIL');
    $sheet2->getStyle('A1')->getFont()->setBold(true)->setSize(15);
    $sheet2->getStyle('A1')->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    $sheet2->getRowDimension(1)->setRowHeight(45);
    $sheet2->mergeCells('A2:R2');
    $sheet2->setCellValue('A2', $periodeLabel);
    $sheet2->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $sheet2->fromArray($colHeaders, NULL, 'A4');
    $sheet2->getStyle('A4:R4')->applyFromArray([
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
            $sheet2->mergeCells("A{$row2}:R{$row2}");
            $sheet2->setCellValue("A{$row2}", "— " . date('d F Y', strtotime($curDate)) . " —");
            $sheet2->getStyle("A{$row2}:R{$row2}")->applyFromArray($styleDivider);
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
            $r['shift']         ?? '—',
            $r['machine_name']  ?? '—',
            $r['machine_type']  ?? '—',
            $r['repair_start']  ? date('d-M-Y H:i', strtotime($r['repair_start']))   : '—',
            $r['repair_finish'] ? date('d-M-Y H:i', strtotime($r['repair_finish']))  : '—',
            durasiMenit($r['repair_start'], $r['repair_finish']),
            $r['reported_by']   ?? '—',
            $r['pic']           ?? '—',
            $r['problem']       ?? '—',
            $r['action']        ?? '—',
            statusLabel($r['status']),
            buildLanjutanLabel($r['parent_id'], $idToNo),
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
        $sheet2->getStyle("A{$first}:R{$last}")->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet2->getStyle("C{$first}:O{$last}")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT);
    }

    if ($row2 > 5) {
        $sheet2->getStyle("A4:R" . ($row2 - 1))->applyFromArray($styleBorder);
    }
    foreach ($colWidths as $col => $w) {
        $sheet2->getColumnDimension($col)->setWidth($w);
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
