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
    // Format: History_EReport_daily_YYYY_MM_DD
    $dtParts  = explode('-', $tanggal); // [YYYY, MM, DD]
    $filename = 'History_EReport_daily_' . implode('_', $dtParts) . '.xlsx';
} elseif ($mode === 'monthly') {
    if ($bulan === '') die('Pilih bulan terlebih dahulu.');
    $whereDate    = "DATE_FORMAT(r.report_date, '%Y-%m') = " . $pdo->quote($bulan);
    $periodeLabel = 'Bulan : ' . $bulan;
    // Format: History_EReport_monthly_YYYY_MM
    $dtParts  = explode('-', $bulan); // [YYYY, MM]
    $filename = 'History_EReport_monthly_' . implode('_', $dtParts) . '.xlsx';
} else {
    die('Mode tidak valid.');
}

// ── Query ─────────────────────────────────────────────────────────────────────
$sql = "
    SELECT
        r.id,
        r.report_date,
        r.department,
        r.line,
        r.op,
        r.machine_name,
        r.machine_type,
        r.repair_start,
        r.repair_finish,
        r.reported_by,
        r.pic,
        r.problem,
        r.action,
        r.created_at
    FROM e_reports r
    WHERE {$whereDate}
    ORDER BY r.report_date ASC, r.created_at ASC
";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) {
    die("Tidak ada data untuk periode yang dipilih.");
}

// ── Helper: hitung durasi ─────────────────────────────────────────────────────
function hitungDurasi($start, $finish)
{
    if (empty($start) || empty($finish)) return '—';
    $diff = (new DateTime($start))->diff(new DateTime($finish));
    return ($diff->days * 1440) + ($diff->h * 60) + $diff->i . ' mnt';
}

// ── Excel ─────────────────────────────────────────────────────────────────────
$spreadsheet = new Spreadsheet();

// ─────────────────────────────────────────────────────────────────────────────
// MODE DAILY: 1 sheet, 1 blok per record (template lama)
// ─────────────────────────────────────────────────────────────────────────────
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
        ->setHorizontal(Alignment::HORIZONTAL_CENTER)
        ->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->getRowDimension(1)->setRowHeight(45);

    $sheet->mergeCells('A2:O2');
    $sheet->setCellValue('A2', $periodeLabel);
    $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('A2')->getFont()->setSize(11);

    $startRow = 4;

    foreach ($rows as $idx => $r) {
        $infoRow = $startRow;
        $durasi  = hitungDurasi($r['repair_start'], $r['repair_finish']);

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
        $sheet->getStyle("A{$infoRow}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 9, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '334155']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($infoRow)->setRowHeight(18);
        $startRow++;

        $hdrRow  = $startRow;
        $headers = [
            'No',
            'Tanggal',
            'Department',
            'Line',
            'OP',
            'Nama Mesin',
            'Tipe Mesin',
            'Repair Start',
            'Repair Finish',
            'Durasi',
            'Reported By',
            'PIC / Teknisi',
            'Problem / Alarm',
            'Action / Perbaikan',
            'Submitted At'
        ];
        $sheet->fromArray($headers, NULL, "A{$hdrRow}");
        $sheet->getStyle("A{$hdrRow}:O{$hdrRow}")->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 9],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '198754']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($hdrRow)->setRowHeight(16);
        $startRow++;

        $dataRow = [
            $idx + 1,
            $r['report_date']   ? date('d-M-Y', strtotime($r['report_date']))         : '—',
            $r['department']    ?? '—',
            $r['line']          ?? '—',
            $r['op']            ?? '—',
            $r['machine_name']  ?? '—',
            $r['machine_type']  ?? '—',
            $r['repair_start']  ? date('d-M-Y H:i', strtotime($r['repair_start']))   : '—',
            $r['repair_finish'] ? date('d-M-Y H:i', strtotime($r['repair_finish']))  : '—',
            $durasi,
            $r['reported_by']   ?? '—',
            $r['pic']           ?? '—',
            $r['problem']       ?? '—',
            $r['action']        ?? '—',
            $r['created_at']    ? date('d-M-Y H:i', strtotime($r['created_at']))     : '—',
        ];
        $sheet->fromArray($dataRow, NULL, "A{$startRow}");
        $sheet->getStyle("A{$startRow}:O{$startRow}")->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getRowDimension($startRow)->setRowHeight(14);

        foreach (['A', 'E', 'J'] as $col) {
            $sheet->getStyle("{$col}{$startRow}")->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        $sheet->getStyle("A{$infoRow}:O{$startRow}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E2E8F0']]],
        ]);
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

    // ─────────────────────────────────────────────────────────────────────────────
    // MODE MONTHLY: 2 sheet (Summary + Detail), template sama dengan checksheet monthly
    // ─────────────────────────────────────────────────────────────────────────────
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

    $hdrsSummary = [
        'No',
        'Report Date',
        'Department',
        'Line',
        'OP',
        'Nama Mesin',
        'Tipe Mesin',
        'Reported By',
        'PIC / Teknisi',
        'Repair Start',
        'Repair Finish',
        'Durasi (mnt)',
        'Problem / Alarm',
        'Action / Perbaikan',
        'Submitted At'
    ];
    $sheet1->fromArray($hdrsSummary, NULL, 'A4');
    $sheet1->getStyle('A4:O4')->applyFromArray([
        'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 9],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '198754']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet1->getRowDimension(4)->setRowHeight(18);

    $row1     = 5;
    $no1      = 1;
    $prevDate = '';

    foreach ($rows as $r) {
        $curDate = substr($r['report_date'], 0, 10);

        // ── Pemisah tanggal (sama dengan checksheet monthly) ──
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

        $durasi = hitungDurasi($r['repair_start'], $r['repair_finish']);
        // Ambil menit saja untuk kolom durasi (angka) supaya bisa disorting
        $durasiMnt = '—';
        if (!empty($r['repair_start']) && !empty($r['repair_finish'])) {
            $diff      = (new DateTime($r['repair_start']))->diff(new DateTime($r['repair_finish']));
            $durasiMnt = ($diff->days * 1440) + ($diff->h * 60) + $diff->i;
        }

        $sheet1->fromArray([
            $no1++,
            $r['report_date']   ? date('d-M-Y', strtotime($r['report_date']))        : '—',
            $r['department']    ?? '—',
            $r['line']          ?? '—',
            $r['op']            ?? '—',
            $r['machine_name']  ?? '—',
            $r['machine_type']  ?? '—',
            $r['reported_by']   ?? '—',
            $r['pic']           ?? '—',
            $r['repair_start']  ? date('d-M-Y H:i', strtotime($r['repair_start']))  : '—',
            $r['repair_finish'] ? date('d-M-Y H:i', strtotime($r['repair_finish'])) : '—',
            $durasiMnt,
            $r['problem']       ?? '—',
            $r['action']        ?? '—',
            $r['created_at']    ? date('d-M-Y H:i', strtotime($r['created_at']))    : '—',
        ], NULL, "A{$row1}");

        $sheet1->getStyle("A{$row1}:O{$row1}")->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setWrapText(true);
        // Kolom teks rata kiri
        $sheet1->getStyle("C{$row1}:N{$row1}")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet1->getRowDimension($row1)->setRowHeight(15);
        $row1++;
    }

    // Grand Total row
    $sheet1->mergeCells("A{$row1}:O{$row1}");
    $sheet1->setCellValue("A{$row1}", "TOTAL : " . count($rows) . " record");
    $sheet1->getStyle("A{$row1}:O{$row1}")->applyFromArray([
        'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E293B']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet1->getRowDimension($row1)->setRowHeight(18);

    $sheet1->getStyle("A4:O{$row1}")->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E2E8F0']]],
    ]);
    foreach (range('A', 'O') as $col) {
        $sheet1->getColumnDimension($col)->setAutoSize(true);
    }
    $sheet1->freezePane('A5');

    // ══ SHEET 2: Detail (1 baris per record = semua kolom lengkap) ════════════
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

    $hdrs2 = [
        'No',
        'Report Date',
        'Department',
        'Line',
        'OP',
        'Nama Mesin',
        'Tipe Mesin',
        'Reported By',
        'PIC / Teknisi',
        'Repair Start',
        'Repair Finish',
        'Durasi (mnt)',
        'Problem / Alarm',
        'Action / Perbaikan',
        'Submitted At'
    ];
    $sheet2->fromArray($hdrs2, NULL, 'A4');
    $sheet2->getStyle('A4:O4')->applyFromArray([
        'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 9],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4F46E5']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet2->getRowDimension(4)->setRowHeight(18);

    $row2     = 5;
    $no2      = 1;
    $prevDate = '';

    foreach ($rows as $r) {
        $curDate = substr($r['report_date'], 0, 10);

        // ── Pemisah tanggal di Sheet2 juga ──
        if ($curDate !== $prevDate) {
            $sheet2->mergeCells("A{$row2}:O{$row2}");
            $sheet2->setCellValue("A{$row2}", "— " . date('d F Y', strtotime($curDate)) . " —");
            $sheet2->getStyle("A{$row2}:O{$row2}")->applyFromArray([
                'font'      => ['bold' => true, 'size' => 9, 'color' => ['rgb' => '64748B'], 'italic' => true],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F1F5F9']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CBD5E1']]],
            ]);
            $sheet2->getRowDimension($row2)->setRowHeight(14);
            $row2++;
            $prevDate = $curDate;
        }

        $durasiMnt = '—';
        if (!empty($r['repair_start']) && !empty($r['repair_finish'])) {
            $diff      = (new DateTime($r['repair_start']))->diff(new DateTime($r['repair_finish']));
            $durasiMnt = ($diff->days * 1440) + ($diff->h * 60) + $diff->i;
        }

        $sheet2->fromArray([
            $no2++,
            $r['report_date']   ? date('d-M-Y', strtotime($r['report_date']))        : '—',
            $r['department']    ?? '—',
            $r['line']          ?? '—',
            $r['op']            ?? '—',
            $r['machine_name']  ?? '—',
            $r['machine_type']  ?? '—',
            $r['reported_by']   ?? '—',
            $r['pic']           ?? '—',
            $r['repair_start']  ? date('d-M-Y H:i', strtotime($r['repair_start']))  : '—',
            $r['repair_finish'] ? date('d-M-Y H:i', strtotime($r['repair_finish'])) : '—',
            $durasiMnt,
            $r['problem']       ?? '—',
            $r['action']        ?? '—',
            $r['created_at']    ? date('d-M-Y H:i', strtotime($r['created_at']))    : '—',
        ], NULL, "A{$row2}");

        $sheet2->getStyle("A{$row2}:O{$row2}")->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet2->getStyle("C{$row2}:N{$row2}")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet2->getRowDimension($row2)->setRowHeight(14);
        $row2++;
    }

    $sheet2->getStyle("A4:O" . ($row2 - 1))->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E2E8F0']]],
    ]);
    foreach (range('A', 'O') as $col) {
        $sheet2->getColumnDimension($col)->setAutoSize(true);
    }
    $sheet2->freezePane('A5');

    $spreadsheet->setActiveSheetIndex(0);
}

// ── Export ─────────────────────────────────────────────────────────────────────
$writer = new Xlsx($spreadsheet);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"{$filename}\"");
header('Cache-Control: max-age=0');
$writer->save('php://output');
exit;
