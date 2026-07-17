<?php

/**
 * import_excel_preventive.php
 *
 * Versi preventive dari import_excel.php — insert ke tabel schedules_preventive.
 * Tidak ada kolom part_order / part_availability di tabel preventive.
 */

// Tangkap SEMUA output liar (PHP warnings, Composer notices, dll)
// ob_end_clean() dipanggil tepat sebelum echo json pertama
ob_start();
@ini_set('display_errors', 0);
@ini_set('log_errors', 1);
error_reporting(0);
set_time_limit(120);

include 'config.php';
if (file_exists(__DIR__ . '/send_reminder.php')) require_once __DIR__ . '/send_reminder.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// Helper: bersihkan semua output liar lalu kirim JSON
function prevJsonOut(array $data): void
{
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
}

requireRoleJson([ROLE_ADMIN_MAINTENANCE, ROLE_SUPERADMIN], 'prevJsonOut');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['excel_file'])) {
    prevJsonOut(['status' => 'error', 'message' => 'Tidak ada file yang dikirim']);
    exit;
}

if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    prevJsonOut(['status' => 'error', 'message' => 'vendor/autoload.php tidak ditemukan. Jalankan: composer install']);
    exit;
}
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlDate;

$file = $_FILES['excel_file'];
$ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if (!in_array($ext, ['xlsx', 'xls'])) {
    prevJsonOut(['status' => 'error', 'message' => 'Format file harus .xlsx atau .xls']);
    exit;
}
if ($file['error'] !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE   => 'File melebihi upload_max_filesize di php.ini',
        UPLOAD_ERR_FORM_SIZE  => 'File melebihi MAX_FILE_SIZE',
        UPLOAD_ERR_PARTIAL    => 'File hanya terupload sebagian',
        UPLOAD_ERR_NO_FILE    => 'Tidak ada file yang diupload',
        UPLOAD_ERR_NO_TMP_DIR => 'Folder temporary tidak ditemukan',
        UPLOAD_ERR_CANT_WRITE => 'Gagal menulis file ke disk',
    ];
    prevJsonOut(['status' => 'error', 'message' => $uploadErrors[$file['error']] ?? 'Upload error: ' . $file['error']]);
    exit;
}
if ($file['size'] > 10 * 1024 * 1024) {
    prevJsonOut(['status' => 'error', 'message' => 'Ukuran file maksimal 10MB']);
    exit;
}

$tmpPath = sys_get_temp_dir() . '/prev_' . uniqid() . '.' . $ext;
if (!move_uploaded_file($file['tmp_name'], $tmpPath)) {
    prevJsonOut(['status' => 'error', 'message' => 'Gagal menyimpan file sementara']);
    exit;
}

// ── COLUMN MAP ───────────────────────────────────────────────────────────────
$COLUMN_MAP = [
    'DEPARTEMENT'       => 'department',
    'DEPARTMENT'        => 'department',
    'LINE'              => 'line',
    'OPERATION PROCESS' => 'operation_process',
    'MACHINE NAME'      => 'machine_name',
    'PROCESS MACHINE'   => 'process_machine',
    'NAME UNIT'         => 'name_unit',
    'MAINTENANCE POINT' => 'maintenance_point',
    'INTERVAL (MONTH)'  => 'interval_month',
    'INTERVAL(MONTH)'   => 'interval_month',
    'USE DATE'          => 'use_date',
    'CHANGE DATE PLAN'  => 'change_date_plan',
    'REMAINING DAY'     => 'remaining_day',
    'REMINDER ACTIVITY' => 'reminder_activity',
];

$SKIP_DEPT_VALUES = ['HARI INI', 'BULAN INI', 'TAHUN INI', 'LEBIH DARI 1 TAHUN'];

function prevCleanStr($v): ?string
{
    if ($v === null) return null;
    $s = trim((string)$v);
    return ($s === '' || strtolower($s) === 'nan') ? null : $s;
}

function prevToDateStr($v): ?string
{
    if ($v === null) return null;
    if ($v instanceof \DateTimeInterface) return $v->format('Y-m-d');
    if (is_numeric($v) && $v > 1000) {
        try {
            return XlDate::excelToDateTimeObject((float)$v)->format('Y-m-d');
        } catch (\Exception $e) {
        }
    }
    $s = prevCleanStr($v);
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
    $reader = IOFactory::createReaderForFile($tmpPath);
    $reader->setReadDataOnly(true);
    $spreadsheet = $reader->load($tmpPath);
    @unlink($tmpPath);

    // ── Pilih sheet yang relevan ─────────────────────────────────────────────
    $sheetNames = $spreadsheet->getSheetNames();
    $targetName = null;
    foreach ($sheetNames as $name) {
        if (stripos($name, 'preventive') !== false || stripos($name, 'master') !== false) {
            $targetName = $name;
            break;
        }
    }
    if (!$targetName) {
        foreach ($sheetNames as $name) {
            if (stripos($name, 'schedule') !== false) {
                $targetName = $name;
                break;
            }
        }
    }
    $sheet = $targetName
        ? $spreadsheet->getSheetByName($targetName)
        : $spreadsheet->getActiveSheet();

    $highestRow = $sheet->getHighestDataRow();
    $maxColIdx  = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

    // ── Cari baris header ────────────────────────────────────────────────────
    $headerRowNum = null;
    $colIndexMap  = [];

    for ($r = 1; $r <= min($highestRow, 20); $r++) {
        $hasDept = false;
        $tmpMap  = [];
        for ($c = 1; $c <= $maxColIdx; $c++) {
            $cl  = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
            $raw = $sheet->getCell($cl . $r)->getValue();
            $up  = mb_strtoupper(trim((string)$raw));
            if ($up === 'DEPARTEMENT' || $up === 'DEPARTMENT') $hasDept = true;
            if (isset($COLUMN_MAP[$up]) && !isset($tmpMap[$COLUMN_MAP[$up]])) {
                $tmpMap[$COLUMN_MAP[$up]] = $c;
            }
        }
        if ($hasDept && count($tmpMap) >= 3) {
            $headerRowNum = $r;
            $colIndexMap  = $tmpMap;
            break;
        }
    }

    if (!$headerRowNum) {
        prevJsonOut([
            'status'  => 'error',
            'message' => 'Baris header tidak ditemukan. Pastikan ada kolom DEPARTEMENT di sheet Excel. '
                . 'Sheet yang dicari: "' . ($targetName ?? 'aktif') . '". '
                . 'Sheet tersedia: ' . implode(', ', $sheetNames),
        ]);
        exit;
    }

    // ── Prepared statement — schedules_preventive (tanpa part_order/part_availability) ──
    $stmtInsert = $pdo->prepare("
        INSERT INTO schedules_preventive
            (department, line, operation_process, machine_name, process_machine,
             name_unit, maintenance_point, interval_month, use_date, change_date_plan,
             reminder_activity, remaining_day, maintenance_status)
        VALUES
            (:department, :line, :operation_process, :machine_name, :process_machine,
             :name_unit, :maintenance_point, :interval_month, :use_date, :change_date_plan,
             :reminder_activity, :remaining_day, :maintenance_status)
    ");
    $stmtUpdate = $pdo->prepare("
        UPDATE schedules_preventive SET
            department = :department, line = :line,
            operation_process = :operation_process, machine_name = :machine_name,
            process_machine = :process_machine, name_unit = :name_unit,
            maintenance_point = :maintenance_point, interval_month = :interval_month,
            use_date = :use_date, change_date_plan = :change_date_plan,
            reminder_activity = :reminder_activity, remaining_day = :remaining_day,
            maintenance_status = :maintenance_status
        WHERE id = :id
    ");
    $stmtCheck = $pdo->prepare("
        SELECT id FROM schedules_preventive
        WHERE machine_name = ? AND maintenance_point = ? AND operation_process = ?
        LIMIT 1
    ");

    $today   = new DateTime('today');
    $success = 0;
    $updated = 0;
    $skipped = 0;
    $errors  = [];
    $importEmailQueue = [];

    // NOTE: Department & Line disimpan sebagai NAMA STRING langsung (konsisten dengan
    // machine_list & tabel schedules predictive), BUKAN di-resolve ke ID dari tabel
    // plants/line lama. Resolve ke ID adalah sumber bug "1 | 1" yang muncul di tampilan
    // Preventive — perbaikan ini menghilangkan akar masalah tersebut.

    for ($r = $headerRowNum + 1; $r <= $highestRow; $r++) {

        $get = function (string $dbCol) use ($sheet, $colIndexMap, $r): mixed {
            if (!isset($colIndexMap[$dbCol])) return null;
            $cl = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndexMap[$dbCol]);
            try {
                return $sheet->getCell($cl . $r)->getCalculatedValue();
            } catch (\Exception $e) {
                return $sheet->getCell($cl . $r)->getValue();
            }
        };

        $dept = prevCleanStr($get('department'));
        if ($dept === null || in_array(mb_strtoupper($dept), $SKIP_DEPT_VALUES)) {
            $skipped++;
            continue;
        }

        // Department & Line dipakai langsung sebagai nama string (bukan ID)
        $deptName = $dept;
        $lineRaw  = prevCleanStr($get('line'));
        $lineName = $lineRaw; // boleh null jika kosong di Excel

        $maintPoint = prevCleanStr($get('maintenance_point'));
        if ($maintPoint === null) {
            $skipped++;
            continue;
        }

        $changeDatePlan = prevToDateStr($get('change_date_plan'));

        // Fallback: hitung dari use_date + interval_month
        if ($changeDatePlan === null) {
            $ud = prevToDateStr($get('use_date'));
            $iv = $get('interval_month');
            if ($ud && is_numeric($iv) && $iv > 0) {
                $dt = new DateTime($ud);
                $dt->modify('+' . (int)$iv . ' month');
                $changeDatePlan = $dt->format('Y-m-d');
            }
        }

        if ($changeDatePlan === null) {
            $errors[] = "Baris $r ({$dept}): change_date_plan tidak dapat dihitung — dilewati.";
            $skipped++;
            continue;
        }

        // Hitung remaining_day
        $pd           = new DateTime($changeDatePlan);
        $diff         = (int)$today->diff($pd)->days;
        $remainingDay = ($pd >= $today) ? $diff : -$diff;

        $intervalRaw   = $get('interval_month');
        $remRaw        = $get('reminder_activity');
        $remActivity   = is_numeric($remRaw) ? (int)$remRaw : 30;

        $opProcess     = prevCleanStr($get('operation_process')) ?? '-';
        $machineName   = prevCleanStr($get('machine_name'))
            ?? prevCleanStr($get('process_machine'))
            ?? $dept;
        $intervalMonth = is_numeric($intervalRaw) ? (int)$intervalRaw : 0;

        // Status awal
        $needsAction = (
            $remainingDay <= 0 ||
            ($remainingDay >= 1 && $remainingDay <= 7) ||
            ($remActivity > 0 && $remainingDay <= $remActivity)
        );
        $maintStatus = $needsAction ? 'soon' : 'done';

        $params = [
            ':department'        => $deptName,
            ':line'              => $lineName,
            ':operation_process' => $opProcess,
            ':machine_name'      => $machineName,
            ':process_machine'   => prevCleanStr($get('process_machine')),
            ':name_unit'         => prevCleanStr($get('name_unit')),
            ':maintenance_point' => $maintPoint,
            ':interval_month'    => $intervalMonth,
            ':use_date'          => prevToDateStr($get('use_date')),
            ':change_date_plan'  => $changeDatePlan,
            ':reminder_activity' => $remActivity,
            ':remaining_day'     => $remainingDay,
            ':maintenance_status' => $maintStatus,
        ];

        try {
            // UPSERT: cek apakah sudah ada data dengan kombinasi kunci yang sama
            $stmtCheck->execute([$machineName, $maintPoint, $opProcess]);
            $existingId = $stmtCheck->fetchColumn();

            if ($existingId) {
                // Data sudah ada → UPDATE (timpa data lama)
                $stmtUpdate->execute(array_merge($params, [':id' => (int)$existingId]));
                $newId = (int)$existingId;
                $updated++;
            } else {
                // Belum ada → INSERT baru
                $stmtInsert->execute($params);
                $newId = (int)$pdo->lastInsertId();
                $success++;
            }
            $needsEmail = (
                $remainingDay <= 0 ||
                ($remainingDay >= 1 && $remainingDay <= 7) ||
                ($remActivity > 0 && $remainingDay > 7 && $remainingDay <= $remActivity)
            );
            if ($needsEmail && $newId > 0) {
                $importEmailQueue[] = ['id' => $newId, 'remaining_day' => $remainingDay];
            }
        } catch (\Exception $e) {
            $errors[] = "Baris $r: " . $e->getMessage();
        }
    }

    $inserted = $success;
    $total    = $inserted + $updated;
    $msg = "{$total} data preventive berhasil diimport dari Excel ({$inserted} baru, {$updated} diperbarui).";
    if ($skipped > 0)    $msg .= " {$skipped} baris dilewati (kosong/summary/tanpa tanggal).";
    if (!empty($errors)) $msg .= ' ' . count($errors) . ' baris gagal.';

    // Kirim notifikasi email setelah semua baris selesai diproses
    if (!empty($importEmailQueue) && function_exists('sendPrevImportAlert')) {
        try {
            sendPrevImportAlert($pdo, $importEmailQueue);
        } catch (\Exception $e) {
            error_log('[ImportPreventive] Gagal kirim email notifikasi: ' . $e->getMessage());
        }
    }

    prevJsonOut([
        'status'   => $total > 0 ? 'success' : 'error',
        'message'  => $msg,
        'imported' => $total,
        'inserted' => $inserted,
        'updated'  => $updated,
        'skipped'  => $skipped,
        'errors'   => array_slice($errors, 0, 10),
        'debug'    => [
            'sheet_used'    => $targetName ?? 'aktif',
            'header_row'    => $headerRowNum,
            'columns_found' => array_keys($colIndexMap),
            'total_scanned' => $highestRow - $headerRowNum,
        ],
    ]);
} catch (\Exception $e) {
    @unlink($tmpPath ?? '');
    prevJsonOut(['status' => 'error', 'message' => 'Gagal memproses file: ' . $e->getMessage()]);
}
