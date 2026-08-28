<?php
// export_checksheet_monthly.php
set_time_limit(0);           // [FIX-1] Unlimited
ini_set('memory_limit', '1536M');
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

// [FIX-6] Rentang tanggal awal & akhir bulan, dipakai untuk filter dengan operator
// perbandingan (>=, <) alih-alih DATE_FORMAT(check_date, '%Y-%m') = ?.
// Membungkus kolom dengan fungsi (DATE_FORMAT) membuat MySQL TIDAK BISA memakai index
// pada check_date sama sekali — jadi full table scan setiap kali. Perbandingan rentang
// biasa (>= awal AND < awal_bulan_berikutnya) bisa memakai index secara normal.
$monthStart = $bulan . '-01';
$monthEnd   = date('Y-m-01', strtotime($monthStart . ' +1 month'));

// ── Ambil semua submission ─────────────────────────────────────────────────────
$stmtSub = $pdo->prepare("
    SELECT id, check_date, department, line, op, machine_name,
           machine_type, category_key, checker, submitted_at
    FROM checksheet_submissions
    WHERE check_date >= ? AND check_date < ?
    ORDER BY check_date ASC, submitted_at ASC
");
$stmtSub->execute([$monthStart, $monthEnd]);
$submissions = $stmtSub->fetchAll(PDO::FETCH_ASSOC);

if (empty($submissions)) die("Tidak ada data untuk bulan $bulan.");

// [FIX-6] $subIds masih dipakai untuk inisialisasi $countMap di bawah (operasi PHP array
// biasa, bukan query) — tapi TIDAK LAGI dipakai untuk membangun klausa IN(...) raksasa.
$subIds = array_column($submissions, 'id');

// ── BULK FETCH detail untuk Sheet1 (result saja) ──────────────────────────────
// [FIX-6] SEBELUMNYA: WHERE submission_id IN (?,?,?,... ribuan placeholder).
// Untuk bulan dengan ribuan submission (mis. 6.322 di Juli 2026), query dengan ribuan
// parameter di klausa IN sangat berat untuk di-parse & di-bind MySQL — ini kemungkinan
// besar penyebab utama loading 5+ menit, BUKAN proses Excel-nya. Diganti JOIN + filter
// rentang tanggal yang sama seperti query submissions di atas, sehingga MySQL cukup
// menjalankan satu JOIN dengan index, bukan mem-parse ribuan placeholder.
$stmtR = $pdo->prepare("
    SELECT d.submission_id, d.result
    FROM checksheet_submission_details d
    INNER JOIN checksheet_submissions s ON s.id = d.submission_id
    WHERE s.check_date >= ? AND s.check_date < ?
");
$stmtR->execute([$monthStart, $monthEnd]);
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
unset($allResults); // [FIX-4] Sudah diringkas ke $countMap, array mentahnya tidak perlu disimpan lagi

// ── BULK FETCH detail lengkap untuk Sheet2 ────────────────────────────────────
// [FIX-6] Sama seperti di atas — JOIN + rentang tanggal, bukan IN(...) ribuan placeholder.
$stmtDet = $pdo->prepare("
    SELECT d.submission_id, d.no, d.part, d.standard, d.result, d.note
    FROM checksheet_submission_details d
    INNER JOIN checksheet_submissions s ON s.id = d.submission_id
    WHERE s.check_date >= ? AND s.check_date < ?
    ORDER BY d.submission_id, d.no
");
$stmtDet->execute([$monthStart, $monthEnd]);
$allDetails = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

$detailMap = [];
foreach ($allDetails as $d) {
    $detailMap[$d['submission_id']][] = $d;
}
unset($allDetails); // [FIX-4] Sudah dikelompokkan ke $detailMap, array flat-nya jadi duplikat & tidak perlu lagi

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

function applyStyleChunked($sheet, int $startRow, int $endRow, string $colStart, string $colEnd, array $style, int $chunkSize = 8000, int $gcEveryNChunks = 4): void
{
    if ($endRow < $startRow) return;
    $chunkNo = 0;
    for ($r = $startRow; $r <= $endRow; $r += $chunkSize) {
        $rEnd = min($r + $chunkSize - 1, $endRow);
        $sheet->getStyle("{$colStart}{$r}:{$colEnd}{$rEnd}")->applyFromArray($style);
        $chunkNo++;
        if ($chunkNo % $gcEveryNChunks === 0) gc_collect_cycles();
    }
}

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

$sheet1->mergeCells('A1:M2');
$sheet1->setCellValue('A1', 'MONTHLY CHECK SHEET REPORT — SUMMARY');
$sheet1->getStyle('A1')->getFont()->setBold(true)->setSize(15);
$sheet1->getStyle('A1')->getAlignment()
    ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
$sheet1->getRowDimension(1)->setRowHeight(25);
$sheet1->getRowDimension(2)->setRowHeight(20);

$sheet1->mergeCells('A3:M4');
$sheet1->setCellValue('A3', 'Bulan : ' . $bulan);
$sheet1->getStyle('A3')->getAlignment()
    ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
$sheet1->getStyle('A3')->getFont()->setSize(11);
$sheet1->getRowDimension(3)->setRowHeight(16);
$sheet1->getRowDimension(4)->setRowHeight(14);

// ── Blok No. Doc / Revisi / Tgl / Halaman ─────────────────────────────────────
$docLabelStyle = ['font' => ['bold' => true, 'size' => 9], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER]];
$docValueStyle = ['font' => ['bold' => false, 'size' => 9], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER]];
$sheet1->setCellValue('N1', 'No. Doc');
$sheet1->setCellValue('O1', 'F-MA-02');
$sheet1->setCellValue('N2', 'Revisi');
$sheet1->setCellValue('O2', '00');
$sheet1->setCellValue('N3', 'Tgl');
$sheet1->setCellValue('O3', '01-06-2026');
$sheet1->setCellValue('N4', 'Halaman');
$sheet1->getStyle('N1:N4')->applyFromArray($docLabelStyle);
$sheet1->getStyle('O1:O4')->applyFromArray($docValueStyle);

$sheet1->getStyle('A1:O4')->applyFromArray([
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => '94A3B8']]],
]);

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
], NULL, 'A5');
$sheet1->getStyle('A5:O5')->applyFromArray([
    'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 9],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '198754']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet1->getRowDimension(5)->setRowHeight(18);

$row1        = 6;
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
    // [FIX-4] Baris setRowHeight(-1) yang lama DIHAPUS — -1 sudah jadi nilai default
    // PhpSpreadsheet untuk row height (artinya "auto"), jadi memanggilnya secara eksplisit
    // di setiap baris cuma memaksa pembuatan objek RowDimension baru tanpa manfaat apa pun,
    // dan untuk ribuan baris itu jadi overhead memori yang sia-sia.
    $row1++;
}

if (!empty($dataRowsS1)) {
    $s1First = $dataRowsS1[0];
    $s1Last  = $dataRowsS1[count($dataRowsS1) - 1];
    $s1DataRange = "A{$s1First}:O{$s1Last}";
    $sheet1->getStyle($s1DataRange)->getAlignment()
        ->setVertical(Alignment::VERTICAL_CENTER)
        ->setHorizontal(Alignment::HORIZONTAL_CENTER);
    // Kolom teks kiri
    $sheet1->getStyle("C{$s1First}:F{$s1Last}")
        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    // wrapText hanya untuk kolom yang berpotensi teks panjang: Department, Line, Machine Name, Machine Type
    $sheet1->getStyle("C{$s1First}:D{$s1Last}")->getAlignment()->setWrapText(true);
    $sheet1->getStyle("F{$s1First}:G{$s1Last}")->getAlignment()->setWrapText(true);
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
$sheet1->getStyle("A5:O{$row1}")->applyFromArray([
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '94A3B8']]],
]);

// [FIX-2] Ganti setAutoSize(true) ke fixed width
// Sheet1 Summary: kolom cukup dengan lebar tetap, jauh lebih cepat
$sigRow = $row1 + 2;
$sheet1->mergeCells("A{$sigRow}:G{$sigRow}");
$sheet1->setCellValue("A{$sigRow}", 'Checked By,');
$sheet1->mergeCells("I{$sigRow}:O{$sigRow}");
$sheet1->setCellValue("I{$sigRow}", 'Approved By,');
$sheet1->getStyle("A{$sigRow}:O{$sigRow}")->applyFromArray([
    'font'      => ['bold' => true, 'size' => 10],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);
for ($i = 0; $i <= 3; $i++) $sheet1->getRowDimension($sigRow + $i)->setRowHeight(18);

$lineRow = $sigRow + 4;
$sheet1->mergeCells("A{$lineRow}:G{$lineRow}");
$sheet1->setCellValue("A{$lineRow}", '( ______________________ )');
$sheet1->mergeCells("I{$lineRow}:O{$lineRow}");
$sheet1->setCellValue("I{$lineRow}", '( ______________________ )');
$sheet1->getStyle("A{$lineRow}:O{$lineRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$captionRow = $lineRow + 1;
$sheet1->mergeCells("A{$captionRow}:G{$captionRow}");
$sheet1->setCellValue("A{$captionRow}", 'Checker / Nama & Tanggal');
$sheet1->mergeCells("I{$captionRow}:O{$captionRow}");
$sheet1->setCellValue("I{$captionRow}", 'Supervisor / Nama & Tanggal');
$sheet1->getStyle("A{$captionRow}:O{$captionRow}")->applyFromArray([
    'font'      => ['italic' => true, 'size' => 8],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);

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
$sheet1->freezePane('A6');

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
    // [FIX-4] Border header digabung di sini juga, supaya tidak perlu lagi ada pemanggilan
    // border terpisah yang mencakup seluruh sheet di akhir (lihat catatan [FIX-4] di bawah).
    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '94A3B8']]],
]);
$sheet2->getRowDimension(4)->setRowHeight(18);

$row2      = 5;
$no2       = 1;
$prevDate  = '';
// Kumpulkan result rows untuk coloring massal
$resultRowsS2 = []; // ['row' => n, 'result' => 'V']

// [FIX-4] Kumpulkan range baris DATA yang bersambung (tanpa baris pemisah tanggal di antaranya)
// supaya alignment+border bisa diterapkan sekaligus per-segmen (bertahap/chunked), bukan satu
// pemanggilan applyFromArray per submission (bisa ribuan kali) atau satu pemanggilan raksasa
// untuk seluruh sheet (bisa puluhan ribu baris — inilah yang bikin OOM di Style\Supervisor.php).
$dataSegments = []; // [[startRow, endRow], ...]
$segStart     = null;
$segEnd       = null;

foreach ($submissions as $sub) {
    $items = $detailMap[$sub['id']] ?? [];
    if (empty($items)) continue;

    // Pemisah tanggal di Sheet2
    $curDate = substr($sub['check_date'], 0, 10);
    if ($curDate !== $prevDate) {
        // Tutup segmen data yang sedang berjalan sebelum baris pemisah disisipkan
        if ($segStart !== null) {
            $dataSegments[] = [$segStart, $segEnd];
            $segStart = null;
        }
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
        // [FIX-4] setRowHeight(-1) yang lama DIHAPUS — -1 sudah jadi default PhpSpreadsheet,
        // memanggilnya eksplisit di setiap baris cuma memaksa dibuatnya objek RowDimension baru
        // tanpa manfaat, dan untuk puluhan ribu baris itu jadi overhead memori yang besar.
        $row2++;
    }

    $segEnd = $row2 - 1;
    if ($segStart === null) $segStart = $blockStart;
}
if ($segStart !== null) $dataSegments[] = [$segStart, $segEnd];

// [FIX-4] Terapkan alignment + border sekaligus per-segmen data, secara bertahap (chunked).
// Ini MENGGANTI dua operasi lama yang terpisah (alignment per-block saat loop, DAN satu
// pemanggilan border untuk seluruh sheet setelah loop) — sekarang jadi satu jalur saja,
// dan tidak pernah memproses range yang lebih besar dari $chunkSize baris dalam satu waktu.
//
// [FIX-5] wrapText SEBELUMNYA diterapkan ke SEMUA 13 kolom (A:M) — untuk ~87.000 baris/bulan
// itu ~1,1 juta sel diproses cuma untuk wrap yang sebenarnya cuma relevan di 2 kolom (Part to
// be Checked & Keterangan). Sekarang wrapText dipisah, hanya kolom I dan L, sedangkan
// vertical-center + border tetap jalan di seluruh A:M seperti biasa.
$borderStyle = ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '94A3B8']];
foreach ($dataSegments as [$segS, $segE]) {
    applyStyleChunked($sheet2, $segS, $segE, 'A', 'M', [
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        'borders'   => ['allBorders' => $borderStyle],
    ]);
    applyStyleChunked($sheet2, $segS, $segE, 'I', 'I', ['alignment' => ['wrapText' => true]]);
    applyStyleChunked($sheet2, $segS, $segE, 'L', 'L', ['alignment' => ['wrapText' => true]]);
}

// Coloring result — digabung jadi range baris yang BERSAMBUNG per grup hasil, supaya
// ribuan/puluhan-ribu baris dengan hasil sama (misalnya mayoritas "OK") cukup satu
// pemanggilan applyFromArray per rangkaian, bukan satu pemanggilan per baris.
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
    $rangeStart = $rows[0];
    $rangeEnd   = $rows[0];
    for ($i = 1, $n = count($rows); $i < $n; $i++) {
        $r = $rows[$i];
        if ($r === $rangeEnd + 1) {
            $rangeEnd = $r;
            continue;
        }
        $sheet2->getStyle("K{$rangeStart}:K{$rangeEnd}")->applyFromArray($styleResult);
        $rangeStart = $r;
        $rangeEnd   = $r;
    }
    $sheet2->getStyle("K{$rangeStart}:K{$rangeEnd}")->applyFromArray($styleResult);
    gc_collect_cycles();
}
unset($resultRowsS2, $groupedResult); // [FIX-4] sudah tidak dipakai lagi, lepas dari memori

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
