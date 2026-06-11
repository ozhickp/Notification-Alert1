<?php
// export_checksheet_daily.php
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

// ─── Query: semua submission beserta detailnya untuk tanggal ini ───────────────
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

// Ambil semua submission di tanggal ini
$stmtSub = $pdo->prepare("
    SELECT * FROM checksheet_submissions
    WHERE DATE(check_date) = ?
    ORDER BY submitted_at ASC
");
$stmtSub->execute([$tanggal]);
$submissions = $stmtSub->fetchAll();

if (empty($submissions)) {
    die("Tidak ada data untuk tanggal $tanggal.");
}

// ─── Excel ────────────────────────────────────────────────────────────────────
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle("Daily Report");

/* LOGO */
if (file_exists('assets/company_logo.jpg')) {
    $logo = new Drawing();
    $logo->setName('Company Logo');
    $logo->setPath('assets/company_logo.jpg');
    $logo->setHeight(55);
    $logo->setCoordinates('A1');
    $logo->setWorksheet($sheet);
}

/* TITLE */
$sheet->mergeCells('A1:L1');
$sheet->setCellValue('A1', 'DAILY CHECK SHEET REPORT');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(15);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
$sheet->getRowDimension(1)->setRowHeight(45);

$sheet->mergeCells('A2:L2');
$sheet->setCellValue('A2', 'Tanggal : ' . $tanggal);
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A2')->getFont()->setSize(11);

/* ── Iterasi setiap submission ── */
$startRow = 4;

foreach ($submissions as $sub) {
    // Ambil detail items untuk submission ini
    $stmtDet = $pdo->prepare("
        SELECT d.no, d.part, d.standard, d.result, d.note,
               i.method, i.action, i.`interval`
        FROM checksheet_submission_details d
        LEFT JOIN checksheet_items i ON i.id = d.item_id
        WHERE d.submission_id = ?
        ORDER BY d.no
    ");
    $stmtDet->execute([$sub['id']]);
    $details = $stmtDet->fetchAll();

    /* — Sub-header: info submission — */
    $infoRow = $startRow;

    // Baris info mesin
    $sheet->mergeCells("A{$infoRow}:M{$infoRow}");
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
    $sheet->setCellValue("A{$infoRow}", $infoText);
    $sheet->getStyle("A{$infoRow}")->applyFromArray([
        'font'      => ['bold' => true, 'size' => 9, 'color' => ['rgb' => 'FFFFFF']],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '334155']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getRowDimension($infoRow)->setRowHeight(18);
    $startRow++;

    /* — Header kolom checklist — */
    $hdrRow = $startRow;
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
        'Submitted At',
    ];
    $sheet->fromArray($headers, NULL, "A{$hdrRow}");
    $sheet->getStyle("A{$hdrRow}:M{$hdrRow}")->applyFromArray([
        'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 9],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '198754']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getRowDimension($hdrRow)->setRowHeight(16);
    $startRow++;

    /* — Isi detail — */
    $resultLabel = [
        'V'  => 'OK',
        'X'  => 'Problem',
        'R'  => 'Repair',
        'RO' => 'Repair by Outsider',
        '-'  => 'Tidak Digunakan',
    ];

    foreach ($details as $det) {
        $dataRow = [
            $det['no'],
            $det['part'],
            $det['standard'],
            $det['method']   ?? '',
            $det['action']   ?? '',
            $det['interval'] ?? '',
            $det['result'],
            $resultLabel[$det['result']] ?? $det['result'],
            $sub['category_key'],
            $sub['id'],
            $sub['check_date'],
            $sub['checker'],
            $sub['submitted_at'],
        ];
        $sheet->fromArray($dataRow, NULL, "A{$startRow}");

        // Warna result
        $resultColors = [
            'V'  => ['bg' => 'DCFCE7', 'fg' => '15803D'],
            'X'  => ['bg' => 'FEE2E2', 'fg' => 'DC2626'],
            'R'  => ['bg' => 'FEF9C3', 'fg' => 'CA8A04'],
            'RO' => ['bg' => 'EDE9FE', 'fg' => '7C3AED'],
            '-'  => ['bg' => 'F1F5F9', 'fg' => '94A3B8'],
        ];
        $rc = $resultColors[$det['result']] ?? ['bg' => 'FFFFFF', 'fg' => '000000'];
        $sheet->getStyle("G{$startRow}")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $rc['bg']]],
            'font' => ['bold' => true, 'color' => ['rgb' => $rc['fg']]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        $sheet->getStyle("A{$startRow}:M{$startRow}")->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getRowDimension($startRow)->setRowHeight(14);
        $startRow++;
    }

    // Baris rangkuman submission
    $okCount  = count(array_filter($details, fn($d) => $d['result'] === 'V'));
    $xCount   = count(array_filter($details, fn($d) => $d['result'] === 'X'));
    $rCount   = count(array_filter($details, fn($d) => in_array($d['result'], ['R', 'RO'])));
    $total    = count($details);

    $sheet->mergeCells("A{$startRow}:F{$startRow}");
    $sheet->setCellValue("A{$startRow}", "Summary: {$total} item — OK: {$okCount}  Problem: {$xCount}  Repair: {$rCount}");
    $sheet->getStyle("A{$startRow}:M{$startRow}")->applyFromArray([
        'font'      => ['bold' => true, 'italic' => true, 'size' => 8, 'color' => ['rgb' => '475569']],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F8FAFC']],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getRowDimension($startRow)->setRowHeight(14);

    // Border untuk blok ini
    $blockEnd = $startRow;
    $blockStart = $infoRow;
    $sheet->getStyle("A{$blockStart}:M{$blockEnd}")->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E2E8F0']]],
    ]);

    $startRow += 2; // jarak antar submission
}

/* ── AUTO SIZE ── */
foreach (range('A', 'M') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Freeze baris header (baris 3 ke atas)
$sheet->freezePane('A4');

/* ── EXPORT ── */
$writer   = new Xlsx($spreadsheet);
$filename = "CheckSheet_Daily_{$tanggal}.xlsx";

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment;filename=\"{$filename}\"");
header('Cache-Control: max-age=0');

$writer->save("php://output");
exit;
