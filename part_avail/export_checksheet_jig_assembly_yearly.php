<?php
// export_checksheet_jig_assembly_yearly.php
// Report tahunan Checksheet Jig Assembly — 1 sheet ringkasan per kuartal
// (checksheet Jig Assembly diperiksa setiap 3 bulan sekali, bukan bulanan).
set_time_limit(0);
ini_set('memory_limit', '512M');

session_start();
if (empty($_SESSION['checksheet_unlocked']) || ($_SESSION['checksheet_area'] ?? '') !== 'jig_assembly') {
    http_response_code(403);
    die('Akses ditolak. Silakan login melalui Checksheet Jig Assembly terlebih dahulu.');
}

include 'config.php';
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$tahun = $_GET['tahun'] ?? '';
if ($tahun == '' || !preg_match('/^\d{4}$/', $tahun)) {
    die("Pilih tahun terlebih dahulu (format: YYYY).");
}

$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$quarterLabel = [
    1 => 'Kuartal 1 (Jan–Mar)',
    2 => 'Kuartal 2 (Apr–Jun)',
    3 => 'Kuartal 3 (Jul–Sep)',
    4 => 'Kuartal 4 (Okt–Des)',
];

$filename = 'CheckSheet_JigAssembly_Annual_' . $tahun . '.xlsx';

// ── Ambil semua submission tahun ini, dikelompokkan per kuartal kalender ────
// (mengikuti konvensi yang sudah dipakai di history_checksheet_jig_assembly.php:
// jigAssemblyQuarterLabel() berbasis kuartal kalender Jan–Mar/Apr–Jun/dst.)
$stmtSub = $pdo->prepare("
    SELECT s.id, s.check_date, s.checker, s.submitted_at,
           COUNT(d.id) AS total_items,
           SUM(d.visual_result = 'OK') AS ok_count,
           SUM(d.visual_result = 'NG') AS ng_count
    FROM jig_assembly_submissions s
    LEFT JOIN jig_assembly_submission_details d ON d.submission_id = s.id
    WHERE s.check_date LIKE ?
    GROUP BY s.id
    ORDER BY s.check_date ASC
");
$stmtSub->execute([$tahun . '-%']);
$submissions = $stmtSub->fetchAll();

// Kalau lebih dari 1 submission dalam kuartal yang sama, pakai yang terakhir
// untuk ringkasan (submission awal tetap ada di History untuk detail).
$byQuarter = [];
$countByQuarter = [];
foreach ($submissions as $s) {
    $m = (int)substr($s['check_date'], 5, 2);
    $q = intdiv($m - 1, 3) + 1;
    $byQuarter[$q] = $s; // paling akhir menang (urutan ASC)
    $countByQuarter[$q] = ($countByQuarter[$q] ?? 0) + 1;
}

// Jumlah edit & ringkasan catatan per submission tahun ini
$editCounts   = [];
$notesSummary = [];
if (!empty($submissions)) {
    $ids = array_column($submissions, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmtEditCount = $pdo->prepare("
        SELECT submission_id, COUNT(*) AS cnt
        FROM jig_assembly_edit_log
        WHERE submission_id IN ($placeholders)
        GROUP BY submission_id
    ");
    $stmtEditCount->execute($ids);
    foreach ($stmtEditCount->fetchAll() as $r) {
        $editCounts[$r['submission_id']] = (int)$r['cnt'];
    }

    $stmtNotes = $pdo->prepare("
        SELECT d.submission_id,
               GROUP_CONCAT(CONCAT(c.check_point, ': ', d.note) SEPARATOR '; ') AS notes
        FROM jig_assembly_submission_details d
        JOIN jig_assembly_checkpoints c ON c.id = d.checkpoint_id
        WHERE d.submission_id IN ($placeholders) AND d.note IS NOT NULL AND d.note <> ''
        GROUP BY d.submission_id
    ");
    $stmtNotes->execute($ids);
    foreach ($stmtNotes->fetchAll() as $r) {
        $notesSummary[$r['submission_id']] = $r['notes'];
    }
}

// ── Style setup ────────────────────────────────────────────────────────────
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle("Jig Assembly Annual $tahun");
$spreadsheet->getCalculationEngine()->disableCalculationCache();

if (file_exists('assets/company_logo.jpg')) {
    $logo = new Drawing();
    $logo->setName('Company Logo');
    $logo->setPath('assets/company_logo.jpg');
    $logo->setHeight(55);
    $logo->setCoordinates('A1');
    $logo->setWorksheet($sheet);
}

$sheet->mergeCells('A1:K1');
$sheet->setCellValue('A1', 'JIG ASSEMBLY ANNUAL CHECK SHEET REPORT');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(15);
$sheet->getStyle('A1')->getAlignment()
    ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
$sheet->getRowDimension(1)->setRowHeight(45);

$sheet->mergeCells('A2:K2');
$sheet->setCellValue('A2', 'Tahun : ' . $tahun . '  (pengecekan setiap 3 bulan sekali)');
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A2')->getFont()->setSize(11);

$headerRow = 4;
$sheet->fromArray(
    ['No', 'Periode', 'Checker', 'Tanggal Cek', 'Submitted', 'Total Item', 'OK', 'NG', 'Compliance', 'Keterangan', 'Edited'],
    NULL,
    "A{$headerRow}"
);
$sheet->getStyle("A{$headerRow}:K{$headerRow}")->applyFromArray([
    'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 9],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E36414']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet->getRowDimension($headerRow)->setRowHeight(18);

$row = $headerRow + 1;
$no = 1;
$totalItemsAll = $totalOkAll = $totalNgAll = 0;
$quartersFilled = 0;

for ($q = 1; $q <= 4; $q++) {
    $s = $byQuarter[$q] ?? null;

    $sheet->setCellValue("A{$row}", $no);
    $qLabel = $quarterLabel[$q] . ' ' . $tahun;
    if (($countByQuarter[$q] ?? 0) > 1) {
        $qLabel .= ' (' . $countByQuarter[$q] . 'x diisi)';
    }
    $sheet->setCellValue("B{$row}", $qLabel);

    if ($s) {
        $totalItems = (int)$s['total_items'];
        $okCount    = (int)$s['ok_count'];
        $ngCount    = (int)$s['ng_count'];
        $compliance = ($okCount + $ngCount) > 0 ? round($okCount / ($okCount + $ngCount) * 100, 1) . '%' : '-';

        $sheet->setCellValue("C{$row}", $s['checker']);
        $sheet->setCellValue("D{$row}", $s['check_date']);
        $sheet->setCellValue("E{$row}", $s['submitted_at']);
        $sheet->setCellValue("F{$row}", $totalItems);
        $sheet->setCellValue("G{$row}", $okCount);
        $sheet->setCellValue("H{$row}", $ngCount);
        $sheet->setCellValue("I{$row}", $compliance);

        $noteText = $notesSummary[$s['id']] ?? '';
        $sheet->setCellValue("J{$row}", $noteText !== '' ? $noteText : '-');

        $editCnt = $editCounts[$s['id']] ?? 0;
        $sheet->setCellValue("K{$row}", $editCnt > 0 ? "Ya ({$editCnt})" : '-');
        if ($editCnt > 0) {
            $sheet->getStyle("K{$row}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 8, 'color' => ['rgb' => 'B45309']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEF3C7']],
            ]);
        }

        if ($ngCount > 0) {
            $sheet->getStyle("H{$row}")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'DC2626']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEE2E2']],
            ]);
        } elseif ($okCount > 0) {
            $sheet->getStyle("G{$row}")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => '15803D']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DCFCE7']],
            ]);
        }

        $totalItemsAll += $totalItems;
        $totalOkAll    += $okCount;
        $totalNgAll    += $ngCount;
        $quartersFilled++;
    } else {
        $sheet->mergeCells("C{$row}:K{$row}");
        $sheet->setCellValue("C{$row}", 'Belum diisi');
        $sheet->getStyle("C{$row}:K{$row}")->getFont()->setItalic(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('94A3B8'));
    }

    $sheet->getStyle("A{$row}:K{$row}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("F{$row}:I{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("J{$row}")->getAlignment()->setWrapText(true);
    $sheet->getStyle("K{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getRowDimension($row)->setRowHeight(16);

    $row++;
    $no++;
}

// Summary row
$complianceAll = ($totalOkAll + $totalNgAll) > 0 ? round($totalOkAll / ($totalOkAll + $totalNgAll) * 100, 1) . '%' : '-';
$sheet->mergeCells("A{$row}:E{$row}");
$sheet->setCellValue("A{$row}", "TOTAL ({$quartersFilled} kuartal terisi dari 4)");
$sheet->setCellValue("F{$row}", $totalItemsAll);
$sheet->setCellValue("G{$row}", $totalOkAll);
$sheet->setCellValue("H{$row}", $totalNgAll);
$sheet->setCellValue("I{$row}", $complianceAll);
$sheet->setCellValue("J{$row}", '-');
$sheet->setCellValue("K{$row}", array_sum($editCounts) > 0 ? 'Ya (' . array_sum($editCounts) . ')' : '-');

$sheet->getStyle("A{$row}:K{$row}")->applyFromArray([
    'font'      => ['bold' => true, 'size' => 9, 'color' => ['rgb' => 'FFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '334155']],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet->getStyle("F{$row}:I{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle("K{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getRowDimension($row)->setRowHeight(18);

$sheet->getStyle("A{$headerRow}:K{$row}")->applyFromArray([
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '94A3B8']]],
]);

$fixedWidths = ['A' => 5, 'B' => 24, 'C' => 16, 'D' => 13, 'E' => 17, 'F' => 10, 'G' => 7, 'H' => 7, 'I' => 11, 'J' => 30, 'K' => 11];
foreach ($fixedWidths as $col => $w) $sheet->getColumnDimension($col)->setWidth($w);
$sheet->freezePane("A" . ($headerRow + 1));

// ── Export ────────────────────────────────────────────────────────────────────
$writer = new Xlsx($spreadsheet);
$writer->setPreCalculateFormulas(false);

$tmpFile = tempnam(sys_get_temp_dir(), 'cs_jig_annual_');
$writer->save($tmpFile);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"{$filename}\"");
header('Cache-Control: max-age=0');
header('Content-Length: ' . filesize($tmpFile));

readfile($tmpFile);
unlink($tmpFile);
exit;
