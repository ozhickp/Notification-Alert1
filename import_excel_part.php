<?php

/**
 * import_excel.php — Fixed v2
 *
 * Fix utama:
 * 1. Header Excel memiliki trailing spaces → gunakan trim() saat matching
 * 2. Deteksi header diperluas hingga baris 10 (Excel ini headernya di baris 2)
 * 3. COLUMN_MAP menggunakan trim+uppercase matching (bukan exact match)
 * 4. Kolom reminder_request_part, history_change_part, report_content sudah dihapus
 * 5. Hitung part_order_status & part_avail_status saat import
 */

include 'config.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'user') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['excel_file'])) {
    echo json_encode(['status' => 'error', 'message' => 'Tidak ada file yang dikirim']);
    exit;
}

if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    echo json_encode(['status' => 'error', 'message' => 'vendor/autoload.php tidak ditemukan.']);
    exit;
}
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlDate;

$file = $_FILES['excel_file'];
$ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if (!in_array($ext, ['xlsx', 'xls'])) {
    echo json_encode(['status' => 'error', 'message' => 'Format file harus .xlsx atau .xls']);
    exit;
}
if ($file['size'] > 10 * 1024 * 1024) {
    echo json_encode(['status' => 'error', 'message' => 'Ukuran file maksimal 10MB']);
    exit;
}

$tmpPath = sys_get_temp_dir() . '/maint_' . uniqid() . '.' . $ext;
if (!move_uploaded_file($file['tmp_name'], $tmpPath)) {
    echo json_encode(['status' => 'error', 'message' => 'Gagal menyimpan file sementara']);
    exit;
}

// ── COLUMN MAP: key = uppercase-trimmed Excel header, value = DB column ──
// Semua key sudah di-trim uppercase agar cocok walau Excel punya trailing space
const COLUMN_MAP = [
    'DEPARTEMENT'       => 'department',
    'DEPARTMENT'        => 'department',
    'LINE'              => 'line',
    'OPERATION PROCESS' => 'operation_process',
    'MACHINE NAME'      => 'machine_name',
    'PROCESS MACHINE'   => 'process_machine',
    'NAME UNIT'         => 'name_unit',
    'MAINTENANCE POINT' => 'maintenance_point',
    'INTERVAL (MONTH)'  => 'interval_month',
    'USE DATE'          => 'use_date',
    'CHANGE DATE PLAN'  => 'change_date_plan',
    'REMINDER DAY'      => 'reminder_activity',
    'REMINDER DAYS'     => 'reminder_activity',
];

// Baris yang isinya di kolom department = nilai ini → lewati (bukan data)
const SKIP_DEPT_VALUES = ['HARI INI', 'BULAN INI', 'TAHUN INI', 'LEBIH DARI 1 TAHUN'];

// Helper: bersihkan string (hapus trailing/leading spaces)
function cleanStr($v): ?string
{
    if ($v === null) return null;
    $s = trim((string)$v);
    return ($s === '' || strtolower($s) === 'nan') ? null : $s;
}

// Helper: konversi ke YYYY-MM-DD
function toDateStr($v): ?string
{
    if ($v === null) return null;
    if ($v instanceof \DateTimeInterface) return $v->format('Y-m-d');
    if (is_numeric($v) && $v > 1000) {
        try {
            return XlDate::excelToDateTimeObject((float)$v)->format('Y-m-d');
        } catch (\Exception $e) {
        }
    }
    $s = cleanStr($v);
    if ($s === null) return null;
    $s = preg_replace('/\s+\d{1,2}:\d{2}(:\d{2})?$/', '', $s);
    foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y', 'Y-m-d H:i:s', 'd M Y', 'd-M-Y'] as $fmt) {
        $dt = \DateTime::createFromFormat($fmt, $s);
        if ($dt !== false) return $dt->format('Y-m-d');
    }
    $ts = strtotime($s);
    return $ts !== false ? date('Y-m-d', $ts) : null;
}

try {
    // readDataOnly=false agar formula EDATE() dievaluasi
    $reader = IOFactory::createReaderForFile($tmpPath);
    $reader->setReadDataOnly(false);
    $spreadsheet = $reader->load($tmpPath);
    @unlink($tmpPath);

    // ── Pilih sheet MASTER SCHEDULE ──
    $sheetNames = $spreadsheet->getSheetNames();
    $targetName = null;
    foreach ($sheetNames as $name) {
        if (stripos($name, 'master') !== false) {
            $targetName = $name;
            break;
        }
    }
    if (!$targetName) {
        foreach ($sheetNames as $name) {
            if (stripos($name, 'schedule') !== false || stripos($name, 'prediktive') !== false) {
                $targetName = $name;
                break;
            }
        }
    }
    $sheet = $targetName ? $spreadsheet->getSheetByName($targetName) : $spreadsheet->getActiveSheet();

    $highestRow = $sheet->getHighestDataRow();
    $maxColIdx  = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

    // ── Cari baris header (diperluas ke 15 baris pertama) ──
    $headerRowNum = null;
    $colIndexMap  = [];

    for ($r = 1; $r <= min($highestRow, 15); $r++) {
        $hasDept = false;
        $tmpMap  = [];
        for ($c = 1; $c <= $maxColIdx; $c++) {
            $cl  = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
            // PENTING: trim() cell value sebelum uppercase matching
            $raw = $sheet->getCell($cl . $r)->getValue();
            $up  = mb_strtoupper(trim((string)$raw));
            // Cek apakah cocok dengan COLUMN_MAP (sudah trim di key maupun value)
            if ($up === 'DEPARTEMENT' || $up === 'DEPARTMENT') $hasDept = true;
            if (isset(COLUMN_MAP[$up]) && !isset($tmpMap[COLUMN_MAP[$up]])) {
                $tmpMap[COLUMN_MAP[$up]] = $c;
            }
        }
        if ($hasDept && count($tmpMap) >= 3) {
            $headerRowNum = $r;
            $colIndexMap  = $tmpMap;
            break;
        }
    }

    if (!$headerRowNum) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'Baris header tidak ditemukan. Pastikan ada kolom DEPARTEMENT di sheet Excel. '
                . 'Sheet yang dicari: "' . ($targetName ?? 'aktif') . '"',
        ]);
        exit;
    }

    // ── Prepared statement (tanpa kolom yang sudah dihapus) ──
    $stmtInsert = $pdo->prepare("
        INSERT INTO schedules
            (department, line, operation_process, machine_name, process_machine,
             name_unit, maintenance_point, interval_month, use_date, change_date_plan,
             reminder_activity, remaining_day, part_order_status, part_avail_status)
        VALUES
            (:department, :line, :operation_process, :machine_name, :process_machine,
             :name_unit, :maintenance_point, :interval_month, :use_date, :change_date_plan,
             :reminder_activity, :remaining_day, :part_order_status, :part_avail_status)
    ");

    $today   = new DateTime('today');
    $success = 0;
    $skipped = 0;
    $errors = [];

    for ($r = $headerRowNum + 1; $r <= $highestRow; $r++) {

        // Ambil calculated value per kolom
        $get = function (string $dbCol) use ($sheet, $colIndexMap, $r): mixed {
            if (!isset($colIndexMap[$dbCol])) return null;
            $cl = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndexMap[$dbCol]);
            try {
                return $sheet->getCell($cl . $r)->getCalculatedValue();
            } catch (\Exception $e) {
                return $sheet->getCell($cl . $r)->getValue();
            }
        };

        $dept = cleanStr($get('department'));
        // Lewati baris kosong atau baris summary (HARI INI, dst.)
        if ($dept === null || in_array(mb_strtoupper($dept), SKIP_DEPT_VALUES)) {
            $skipped++;
            continue;
        }

        $maintPoint = cleanStr($get('maintenance_point'));
        if ($maintPoint === null) {
            $skipped++;
            continue;
        }

        // CHANGE DATE PLAN — getCalculatedValue() mengevaluasi =EDATE()
        $cdpRaw         = $get('change_date_plan');
        $changeDatePlan = toDateStr($cdpRaw);

        // Fallback: hitung dari USE DATE + INTERVAL jika formula gagal
        if ($changeDatePlan === null) {
            $ud = toDateStr($get('use_date'));
            $iv = $get('interval_month');
            if ($ud && is_numeric($iv) && $iv > 0) {
                $dt = new DateTime($ud);
                $dt->modify('+' . (int)$iv . ' month');
                $changeDatePlan = $dt->format('Y-m-d');
            }
        }

        // Hitung remaining_day
        $remainingDay = null;
        if ($changeDatePlan) {
            $pd   = new DateTime($changeDatePlan);
            $diff = (int)$today->diff($pd)->days;
            $remainingDay = ($pd >= $today) ? $diff : -$diff;
        }

        $intervalRaw = $get('interval_month');
        $remRaw      = $get('reminder_activity');
        $remActivity = is_numeric($remRaw) ? (int)$remRaw : 30;

        // Tentukan part_order_status & part_avail_status awal
        $partStatus = ($remainingDay !== null && $remainingDay > 0 && $remainingDay <= $remActivity)
            ? 'open' : 'close';

        try {
            $stmtInsert->execute([
                ':department'        => $dept,
                ':line'              => cleanStr($get('line')),
                ':operation_process' => cleanStr($get('operation_process')),
                ':machine_name'      => cleanStr($get('machine_name')),
                ':process_machine'   => cleanStr($get('process_machine')),
                ':name_unit'         => cleanStr($get('name_unit')),
                ':maintenance_point' => $maintPoint,
                ':interval_month'    => is_numeric($intervalRaw) ? (int)$intervalRaw : null,
                ':use_date'          => toDateStr($get('use_date')),
                ':change_date_plan'  => $changeDatePlan,
                ':reminder_activity' => $remActivity,
                ':remaining_day'     => $remainingDay,
                ':part_order_status' => $partStatus,
                ':part_avail_status' => $partStatus,
            ]);
            $success++;
        } catch (\Exception $e) {
            $errors[] = "Baris $r: " . $e->getMessage();
        }
    }

    $msg = "{$success} data berhasil diimport dari Excel.";
    if ($skipped > 0)    $msg .= " {$skipped} baris dilewati (kosong/summary).";
    if (!empty($errors)) $msg .= ' ' . count($errors) . ' baris gagal.';

    echo json_encode([
        'status'   => $success > 0 ? 'success' : 'error',
        'message'  => $msg,
        'imported' => $success,
        'skipped'  => $skipped,
        'errors'   => array_slice($errors, 0, 10),
        'debug'    => [
            'sheet_used'  => $targetName ?? 'aktif',
            'header_row'  => $headerRowNum,
            'columns_found' => array_keys($colIndexMap),
        ],
    ]);
} catch (\Exception $e) {
    @unlink($tmpPath ?? '');
    echo json_encode(['status' => 'error', 'message' => 'Gagal memproses file: ' . $e->getMessage()]);
}
