<?php
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
$mode    = $_GET['mode']    ?? 'daily';   // 'daily' | 'monthly'
$tanggal = $_GET['tanggal'] ?? '';
$bulan   = $_GET['bulan']   ?? '';

// ── Validasi ──────────────────────────────────────────────────────────────────
if ($mode === 'daily') {
    if ($tanggal === '') die('Pilih tanggal terlebih dahulu.');
    $whereDate    = "DATE(r.report_date) = " . $pdo->quote($tanggal);
    $periodeLabel = 'Tanggal : ' . $tanggal;
    $fileDate     = $tanggal;
} elseif ($mode === 'monthly') {
    if ($bulan === '') die('Pilih bulan terlebih dahulu.');
    $whereDate    = "DATE_FORMAT(r.report_date, '%Y-%m') = " . $pdo->quote($bulan);
    $periodeLabel = 'Bulan : ' . $bulan;
    $fileDate     = $bulan;
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
    ORDER BY r.created_at ASC
";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) {
    die("Tidak ada data untuk periode {$fileDate}.");
}

// ── Excel ─────────────────────────────────────────────────────────────────────
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle("E-Report History");

/* LOGO */
if (file_exists('assets/company_logo.jpg')) {
    $logo = new Drawing();
    $logo->setName('Company Logo');
    $logo->setPath('assets/company_logo.jpg');
    $logo->setHeight(55);
    $logo->setCoordinates('A1');
    $logo->setWorksheet($sheet);
}

/* ── TITLE ── */
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

/* ── Iterasi setiap report (mirip daily checksheet: 1 blok per record) ── */
$startRow = 4;

foreach ($rows as $idx => $r) {
    /* — Sub-header: info report — */
    $infoRow = $startRow;

    // Hitung durasi
    $durasi = '—';
    if (!empty($r['repair_start']) && !empty($r['repair_finish'])) {
        $dtStart  = new DateTime($r['repair_start']);
        $dtFinish = new DateTime($r['repair_finish']);
        $diff     = $dtStart->diff($dtFinish);
        $durasi   = ($diff->days * 1440) + ($diff->h * 60) + $diff->i . ' mnt';
    }

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

    /* — Header kolom — */
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
        'Submitted At',
    ];
    $sheet->fromArray($headers, NULL, "A{$hdrRow}");
    $sheet->getStyle("A{$hdrRow}:O{$hdrRow}")->applyFromArray([
        'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 9],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '198754']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getRowDimension($hdrRow)->setRowHeight(16);
    $startRow++;

    /* — Isi data — */
    $dataRow = [
        $idx + 1,
        $r['report_date'] ? date('d-M-Y', strtotime($r['report_date'])) : '—',
        $r['department']  ?? '—',
        $r['line']        ?? '—',
        $r['op']          ?? '—',
        $r['machine_name'] ?? '—',
        $r['machine_type'] ?? '—',
        $r['repair_start']  ? date('d-M-Y H:i', strtotime($r['repair_start']))  : '—',
        $r['repair_finish'] ? date('d-M-Y H:i', strtotime($r['repair_finish'])) : '—',
        $durasi,
        $r['reported_by'] ?? '—',
        $r['pic']         ?? '—',
        $r['problem']     ?? '—',
        $r['action']      ?? '—',
        $r['created_at']  ? date('d-M-Y H:i', strtotime($r['created_at']))  : '—',
    ];
    $sheet->fromArray($dataRow, NULL, "A{$startRow}");
    $sheet->getStyle("A{$startRow}:O{$startRow}")->getAlignment()
        ->setVertical(Alignment::VERTICAL_CENTER)
        ->setWrapText(true);
    $sheet->getRowDimension($startRow)->setRowHeight(14);

    // Center kolom tertentu
    foreach (['A', 'E', 'J'] as $col) {
        $sheet->getStyle("{$col}{$startRow}")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    /* — Border blok ini — */
    $blockEnd = $startRow;
    $sheet->getStyle("A{$infoRow}:O{$blockEnd}")->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E2E8F0']]],
    ]);

    $startRow += 2; // jarak antar record
}

/* ── AUTO SIZE kolom ── */
$colWidths = [
    'A' =>  5,
    'B' => 13,
    'C' => 18,
    'D' => 14,
    'E' =>  8,
    'F' => 24,
    'G' => 16,
    'H' => 18,
    'I' => 18,
    'J' => 10,
    'K' => 16,
    'L' => 16,
    'M' => 38,
    'N' => 38,
    'O' => 18,
];
foreach ($colWidths as $col => $w) {
    $sheet->getColumnDimension($col)->setWidth($w);
}

// Freeze baris header (baris 3 ke atas)
$sheet->freezePane('A4');

/* ── EXPORT ── */
$writer   = new Xlsx($spreadsheet);
$filename = "History_EReport_{$fileDate}.xlsx";

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"{$filename}\"");
header('Cache-Control: max-age=0');

$writer->save('php://output');
exit;
