<?php

date_default_timezone_set('Asia/Jakarta');

include 'config.php';
session_start();

if (!isset($_SESSION['user_id'], $_SESSION['role']) || !in_array($_SESSION['role'], [ROLE_ADMIN_MAINTENANCE, ROLE_SUPERADMIN], true)) {
    http_response_code(403);
    die('Unauthorized');
}

if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    http_response_code(500);
    die('vendor/autoload.php tidak ditemukan. Jalankan: composer install');
}
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$type      = $_GET['type'] ?? '';
$day       = $_GET['day'] ?? 'all';
$condition = $_GET['condition'] ?? 'alert'; // 'alert' (H-1 s.d H-7) atau 'overdue' (H+0 dst)

if (!in_array($type, ['predictive', 'preventive'], true)) {
    http_response_code(400);
    die('Parameter type tidak valid.');
}
if (!in_array($condition, ['alert', 'overdue'], true)) {
    http_response_code(400);
    die('Parameter condition tidak valid.');
}

$validOverdueDays = ['all', '0', '1', '2', '3', '4', '5', '6', '7', 'over7'];
if ($condition === 'alert') {
    if ($day !== 'all' && !ctype_digit((string)$day)) {
        http_response_code(400);
        die('Parameter day tidak valid.');
    }
    if ($day !== 'all' && ((int)$day < 1 || (int)$day > 7)) {
        http_response_code(400);
        die('Parameter day harus antara 1 sampai 7 (sesuai rentang kategori Alert).');
    }
} else {
    if (!in_array((string)$day, $validOverdueDays, true)) {
        http_response_code(400);
        die('Parameter day tidak valid untuk kondisi Overdue.');
    }
}

// ── Selalu sinkronkan remaining_day dulu, supaya export tidak memuat data basi ──
$table = $type === 'predictive' ? 'schedules' : 'schedules_preventive';
$pdo->exec("UPDATE {$table} SET remaining_day = DATEDIFF(change_date_plan, CURDATE()) WHERE change_date_plan IS NOT NULL");

// ── Query data sesuai filter ──────────────────────────────────────────────────
$params = [];
if ($condition === 'alert') {
    $where = "remaining_day BETWEEN 1 AND 7";
    if ($day !== 'all') {
        $where .= " AND remaining_day = :day";
        $params[':day'] = (int)$day;
    }
} else {
    // Overdue: remaining_day <= 0. Bucket hari: '0' = jatuh tempo hari ini,
    // '1'..'7' = terlambat N hari (remaining_day = -N), 'over7' = terlambat > 7 hari.
    $where = "remaining_day <= 0";
    if ($day !== 'all') {
        if ($day === '0') {
            $where .= " AND remaining_day = 0";
        } elseif ($day === 'over7') {
            $where .= " AND remaining_day < -7";
        } else {
            $where .= " AND remaining_day = :day";
            $params[':day'] = -1 * (int)$day;
        }
    }
}

$stmt = $pdo->prepare("
    SELECT department, line, operation_process, machine_name, process_machine,
           maintenance_point, interval_month, use_date, change_date_plan, remaining_day
    FROM {$table}
    WHERE {$where}
    ORDER BY remaining_day ASC, department ASC, line ASC, machine_name ASC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Susun workbook ────────────────────────────────────────────────────────────
$isPrev         = $type === 'preventive';
$isOverdue      = $condition === 'overdue';
$themeHex       = $isOverdue ? 'B91C1C' : ($isPrev ? '7A1355' : '124DA1'); // merah utk overdue, magenta utk preventive, biru utk predictive
$label          = $isPrev ? 'Preventive' : 'Predictive';
$conditionLabel = $isOverdue ? 'Overdue' : 'Alert';

if ($condition === 'alert') {
    $dayLabel = $day === 'all' ? 'Semua Hari (H-1 s.d H-7)' : ('H-' . (int)$day);
} else {
    if ($day === 'all') {
        $dayLabel = 'Semua (Overdue)';
    } elseif ($day === '0') {
        $dayLabel = 'H+0 (Jatuh Tempo Hari Ini)';
    } elseif ($day === 'over7') {
        $dayLabel = '> H+7 (Terlambat Lebih dari 7 Hari)';
    } else {
        $dayLabel = 'H+' . (int)$day . ' (Terlambat ' . (int)$day . ' Hari)';
    }
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle($conditionLabel . ' ' . $label);

if (file_exists('assets/company_logo.jpg')) {
    $logo = new Drawing();
    $logo->setName('Company Logo');
    $logo->setPath('assets/company_logo.jpg');
    $logo->setHeight(55);
    $logo->setCoordinates('A1');
    $logo->setWorksheet($sheet);
}

// Judul laporan
$sheet->setCellValue('A1', "RENCANA MAINTENANCE {$label} - " . strtoupper($conditionLabel) . " ({$dayLabel})");
$sheet->mergeCells('A1:K1');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
$sheet->getStyle('A1')->getAlignment()
    ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
$sheet->getRowDimension(1)->setRowHeight(45);

$sheet->setCellValue('A2', 'Dicetak: ' . date('d-m-Y H:i') . '  |  Total: ' . count($rows) . ' kegiatan');
$sheet->mergeCells('A2:K2');
$sheet->getStyle('A2')->getFont()->setItalic(true)->setSize(9)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF64748B'));

// Header tabel
$headers = ['No', 'Department', 'Line', 'OP', 'Machine Name', 'Process Machine', 'Maintenance Point', 'Interval (Bulan)', 'Last Change', 'Change Date Plan', $isOverdue ? 'Terlambat (Hari)' : 'Remaining (Hari)'];
$headerRow = 4;
foreach ($headers as $i => $h) {
    $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
    $sheet->setCellValue("{$col}{$headerRow}", $h);
}
$headerRange = "A{$headerRow}:" . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers)) . $headerRow;
$sheet->getStyle($headerRange)->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFFFF'));
$sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF' . $themeHex);
$sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
$sheet->getRowDimension($headerRow)->setRowHeight(22);

// Isi data
$r = $headerRow + 1;
foreach ($rows as $i => $row) {
    $sheet->setCellValue("A{$r}", $i + 1);
    $sheet->setCellValue("B{$r}", $row['department'] ?? '-');
    $sheet->setCellValue("C{$r}", $row['line'] ?? '-');
    $sheet->setCellValue("D{$r}", $row['operation_process'] ?? '-');
    $sheet->setCellValue("E{$r}", $row['machine_name'] ?? '-');
    $sheet->setCellValue("F{$r}", $row['process_machine'] ?? '-');
    $sheet->setCellValue("G{$r}", $row['maintenance_point'] ?? '-');
    $sheet->setCellValue("H{$r}", $row['interval_month'] ?? '-');
    $sheet->setCellValueExplicit("I{$r}", $row['use_date'] ? formatDate($row['use_date']) : '-', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValueExplicit("J{$r}", $row['change_date_plan'] ? formatDate($row['change_date_plan']) : '-', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValue("K{$r}", $isOverdue ? abs((int)$row['remaining_day']) : (int)$row['remaining_day']);
    $r++;
}

$lastRow = $r - 1;
if ($lastRow >= $headerRow + 1) {
    $dataRange = "A" . ($headerRow + 1) . ":K{$lastRow}";
    $sheet->getStyle($dataRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF94A3B8'));
    $sheet->getStyle("A" . ($headerRow + 1) . ":A{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("D" . ($headerRow + 1) . ":D{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("H" . ($headerRow + 1) . ":H{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("I" . ($headerRow + 1) . ":K{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("K" . ($headerRow + 1) . ":K{$lastRow}")->getFont()->setBold(true);
} else {
    $sheet->setCellValue("A" . ($headerRow + 1), 'Tidak ada kegiatan pada rentang hari yang dipilih.');
    $sheet->mergeCells("A" . ($headerRow + 1) . ":K" . ($headerRow + 1));
    $sheet->getStyle("A" . ($headerRow + 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("A" . ($headerRow + 1))->getFont()->setItalic(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF94A3B8'));
}

// Lebar kolom
$widths = ['A' => 5, 'B' => 18, 'C' => 16, 'D' => 10, 'E' => 22, 'F' => 18, 'G' => 32, 'H' => 14, 'I' => 13, 'J' => 16, 'K' => 14];
foreach ($widths as $col => $w) {
    $sheet->getColumnDimension($col)->setWidth($w);
}
$sheet->getStyle("G" . ($headerRow + 1) . ":G{$lastRow}")->getAlignment()->setWrapText(true);
$sheet->freezePane('A' . ($headerRow + 1));

// ── Output ────────────────────────────────────────────────────────────────────
$fileTag = $isPrev ? 'Preventive' : 'Predictive';
if ($day === 'all') {
    $dayTag = 'Semua';
} elseif ($condition === 'alert') {
    $dayTag = 'H-' . (int)$day;
} elseif ($day === '0') {
    $dayTag = 'H0';
} elseif ($day === 'over7') {
    $dayTag = 'Over7';
} else {
    $dayTag = 'H+' . (int)$day;
}
$filename = "{$conditionLabel}_{$fileTag}_{$dayTag}_" . date('Ymd_His') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
