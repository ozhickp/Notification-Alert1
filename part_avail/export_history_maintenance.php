<?php
session_start();
include 'config.php';

requireRole([ROLE_ADMIN_MAINTENANCE, ROLE_SUPERADMIN]);

require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

// ── Parameter ──────────────────────────────────────────────────────────────────
$type    = $_GET['type']    ?? 'predictive';   // 'predictive' | 'preventive'
$mode    = $_GET['mode']    ?? 'daily';        // 'daily' | 'monthly'
$tanggal = $_GET['tanggal'] ?? '';
$bulan   = $_GET['bulan']   ?? '';

// ── Validasi mode & periode ────────────────────────────────────────────────────
if ($mode === 'daily') {
    if ($tanggal === '') die('Pilih tanggal terlebih dahulu.');
    $whereDate    = "DATE(h.reported_at) = " . $pdo->quote($tanggal);
    $periodeLabel = 'Tanggal : ' . $tanggal;
    $fileDate     = $tanggal;
} elseif ($mode === 'monthly') {
    if ($bulan === '') die('Pilih bulan terlebih dahulu.');
    $whereDate    = "DATE_FORMAT(h.reported_at, '%Y-%m') = " . $pdo->quote($bulan);
    $periodeLabel = 'Bulan : ' . $bulan;
    $fileDate     = $bulan;
} else {
    die('Mode tidak valid.');
}

// ── Pilih tabel & label ───────────────────────────────────────────────────────
$isPrev = ($type === 'preventive');
$table  = $isPrev ? 'history_preventive' : 'history_maintenance';
$typeLabel  = $isPrev ? 'Preventive' : 'Predictive';
$sheetTitle = "$typeLabel Report";
$filename   = "History_{$typeLabel}_Maintenance_{$fileDate}.xlsx";

// ── Query ──────────────────────────────────────────────────────────────────────
// [FIX-TECHNICIAN] Sebelumnya technician_name diambil dari JOIN ke tabel users
// via h.reported_by (u.username AS technician_name). reported_by cuma FK ke
// users.id (user yang login/submit laporan), bukan nama technician. Nama
// technician sebenarnya ada di kolom h.teknisi (lihat struktur tabel
// history_maintenance / history_preventive) — JOIN ke users dihapus,
// technician diambil langsung dari h.teknisi.
$sql = "
    SELECT
        h.id,
        h.department,
        h.line,
        h.operation_process,
        h.machine_name,
        h.process_machine,
        h.name_unit,
        h.maintenance_point,
        h.change_date_plan,
        h.note,
        h.photo_path,
        h.reported_at,
        h.teknisi AS technician_name
    FROM {$table} h
    WHERE {$whereDate}
    ORDER BY h.reported_at ASC
";

$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Spreadsheet setup ─────────────────────────────────────────────────────────
$spreadsheet = new Spreadsheet();
$sheet       = $spreadsheet->getActiveSheet();
$sheet->setTitle($sheetTitle);

// Kolom: A–L = 12 kolom
$lastCol = 'L';

// Judul dibuat hanya sampai kolom J agar K:L (baris 1-4) bisa dipakai
// untuk blok No. Doc / Revisi / Tgl / Halaman, menyamai format checksheet.
$titleCol = 'J';

// ── LOGO ──────────────────────────────────────────────────────────────────────
$logoPath = 'assets/company_logo.jpg';
if (file_exists($logoPath)) {
    $logo = new Drawing();
    $logo->setName('Company Logo');
    $logo->setPath($logoPath);
    $logo->setHeight(60);
    $logo->setCoordinates('A1');
    $logo->setWorksheet($sheet);
}

// ── Baris 1: Judul ────────────────────────────────────────────────────────────
$sheet->mergeCells("A1:{$titleCol}1");
$sheet->setCellValue('A1', 'HISTORY MAINTENANCE REPORT');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('A1')->getAlignment()
    ->setHorizontal(Alignment::HORIZONTAL_CENTER)
    ->setVertical(Alignment::VERTICAL_CENTER);
$sheet->getRowDimension(1)->setRowHeight(50);

// ── Baris 2: Tipe maintenance ─────────────────────────────────────────────────
$sheet->mergeCells("A2:{$titleCol}2");
$sheet->setCellValue('A2', $typeLabel . ' Maintenance');
$sheet->getStyle('A2')->getFont()->setBold(true)->setSize(11);
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getRowDimension(2)->setRowHeight(18);

// ── Baris 3: Periode ─────────────────────────────────────────────────────────
$sheet->mergeCells("A3:{$titleCol}3");
$sheet->setCellValue('A3', $periodeLabel);
$sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getRowDimension(3)->setRowHeight(16);

// ── Baris 4: Spasi (sekaligus baris "Halaman" untuk blok No. Doc) ─────────────
$sheet->getRowDimension(4)->setRowHeight(16);

// ── Blok No. Doc / Revisi / Tgl / Halaman ─────────────────────────────────────
// Menyamai format yang sudah dipakai di export_checksheet_painting &
// export_checksheet_daily. No. Doc dan Tgl dikosongkan dulu (belum ada nomor
// dokumen resminya) — tinggal isi belakangan.
$docLabelStyle = ['font' => ['bold' => true, 'size' => 9], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER]];
$docValueStyle = ['font' => ['bold' => false, 'size' => 9], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER]];
$sheet->setCellValue('K1', 'No. Doc');
$sheet->setCellValue('L1', ''); // [dikosongkan dulu]
$sheet->setCellValue('K2', 'Revisi');
$sheet->setCellValue('L2', '00');
$sheet->setCellValue('K3', 'Tgl');
$sheet->setCellValue('L3', ''); // [dikosongkan dulu]
$sheet->setCellValue('K4', 'Halaman');
$sheet->getStyle('K1:K4')->applyFromArray($docLabelStyle);
$sheet->getStyle('L1:L4')->applyFromArray($docValueStyle);

$sheet->getStyle("A1:{$lastCol}4")->applyFromArray([
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => '94A3B8']]],
]);

// ── Baris 5: Header kolom ─────────────────────────────────────────────────────
$headers = [
    'No',
    'Department',
    'Line',
    'Operation Process',
    'Machine Name',
    'Process Machine',
    'Unit Name',
    'Maintenance Point',
    'Change Date Plan',
    'Note',
    'Technician',
    'Reported At',
];

$sheet->fromArray($headers, null, 'A5');
$sheet->getRowDimension(5)->setRowHeight(22);

// Warna header: amber untuk predictive, teal untuk preventive
$headerColor = $isPrev ? '0F766E' : 'B45309';

$sheet->getStyle("A5:{$lastCol}5")->applyFromArray([
    'font' => [
        'bold'  => true,
        'color' => ['rgb' => 'FFFFFF'],
    ],
    'fill' => [
        'fillType'   => Fill::FILL_SOLID,
        'startColor' => ['rgb' => $headerColor],
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical'   => Alignment::VERTICAL_CENTER,
        'wrapText'   => true,
    ],
]);

// ── Baris data ────────────────────────────────────────────────────────────────
$row = 6;
$no  = 1;

foreach ($rows as $data) {

    $sheet->getRowDimension($row)->setRowHeight(20);

    $changeDatePlan = $data['change_date_plan']
        ? date('d M Y', strtotime($data['change_date_plan']))
        : '-';

    $reportedAt = $data['reported_at']
        ? date('d M Y H:i', strtotime($data['reported_at']))
        : '-';

    // [FIX-TECHNICIAN] technician_name sekarang berasal langsung dari
    // h.teknisi (lihat query di atas), bukan dari JOIN users.reported_by.
    $techName = $data['technician_name'] ?: '-';

    $sheet->fromArray([
        $no++,
        $data['department']       ?? '-',
        $data['line']             ?? '-',
        $data['operation_process'] ?? '-',
        $data['machine_name']     ?? '-',
        $data['process_machine']  ?? '-',
        $data['name_unit']        ?? '-',
        $data['maintenance_point'] ?? '-',
        $changeDatePlan,
        $data['note']             ?? '-',
        $techName,
        $reportedAt,
    ], null, "A$row");

    $row++;
}

// ── Auto-size semua kolom ─────────────────────────────────────────────────────
foreach (range('A', 'L') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Kolom Maintenance Point & Note sedikit lebih lebar & wrap
$sheet->getColumnDimension('H')->setWidth(35);
$sheet->getColumnDimension('J')->setWidth(35);

// ── Style area data (header + baris) ─────────────────────────────────────────
if ($row > 6) {
    $sheet->getStyle("A5:{$lastCol}" . ($row - 1))->applyFromArray([
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical'   => Alignment::VERTICAL_CENTER,
            'wrapText'   => true,
        ],
        'borders' => [
            'allBorders' => ['borderStyle' => Border::BORDER_THIN],
        ],
    ]);

    // Kolom note & maintenance point rata kiri agar lebih mudah dibaca
    $sheet->getStyle("H6:{$lastCol}" . ($row - 1))
        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
}

// ── Blok Tanda Tangan (Checked By / Approved By) ──────────────────────────────
// Menyamai format yang sudah dipakai di export_checksheet_painting &
// export_checksheet_daily.
$sigRow = $row + 2;
$sheet->mergeCells("A{$sigRow}:F{$sigRow}");
$sheet->setCellValue("A{$sigRow}", 'Checked By,');
$sheet->mergeCells("G{$sigRow}:{$lastCol}{$sigRow}");
$sheet->setCellValue("G{$sigRow}", 'Approved By,');
$sheet->getStyle("A{$sigRow}:{$lastCol}{$sigRow}")->applyFromArray([
    'font'      => ['bold' => true, 'size' => 10],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);
for ($i = 0; $i <= 3; $i++) $sheet->getRowDimension($sigRow + $i)->setRowHeight(18);

$lineRow = $sigRow + 4;
$sheet->mergeCells("A{$lineRow}:F{$lineRow}");
$sheet->setCellValue("A{$lineRow}", '( ______________________ )');
$sheet->mergeCells("G{$lineRow}:{$lastCol}{$lineRow}");
$sheet->setCellValue("G{$lineRow}", '( ______________________ )');
$sheet->getStyle("A{$lineRow}:{$lastCol}{$lineRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$captionRow = $lineRow + 1;
$sheet->mergeCells("A{$captionRow}:F{$captionRow}");
$sheet->setCellValue("A{$captionRow}", 'Checker / Nama & Tanggal');
$sheet->mergeCells("G{$captionRow}:{$lastCol}{$captionRow}");
$sheet->setCellValue("G{$captionRow}", 'Supervisor / Nama & Tanggal');
$sheet->getStyle("A{$captionRow}:{$lastCol}{$captionRow}")->applyFromArray([
    'font'      => ['italic' => true, 'size' => 8],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);

// ── Freeze header ─────────────────────────────────────────────────────────────
$sheet->freezePane('A6');

// ── Output ────────────────────────────────────────────────────────────────────
$writer = new Xlsx($spreadsheet);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"$filename\"");
header('Cache-Control: max-age=0');
$writer->save('php://output');
exit;
