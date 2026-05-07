<?php

/**
 * import_excel.php — Rewritten v5
 *
 * Mengikuti pola import_excel_part.php yang sudah terbukti bekerja:
 * - ob_start() di paling atas
 * - setReadDataOnly(TRUE) → hindari error ConditionalFormattingRuleExtension
 *   (kita hanya butuh data, bukan style/conditional formatting)
 * - Satu try-catch besar membungkus seluruh proses
 * - Mapping kolom disesuaikan untuk tabel schedules (dashboard_user)
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
function jsonOut(array $data): void
{
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
}

if (!isset($_SESSION['user_id'])) {
    jsonOut(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['excel_file'])) {
    jsonOut(['status' => 'error', 'message' => 'Tidak ada file yang dikirim']);
    exit;
}

if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    jsonOut(['status' => 'error', 'message' => 'vendor/autoload.php tidak ditemukan. Jalankan: composer install']);
    exit;
}
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlDate;

$file = $_FILES['excel_file'];
$ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if (!in_array($ext, ['xlsx', 'xls'])) {
    jsonOut(['status' => 'error', 'message' => 'Format file harus .xlsx atau .xls']);
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
    jsonOut(['status' => 'error', 'message' => $uploadErrors[$file['error']] ?? 'Upload error: ' . $file['error']]);
    exit;
}
if ($file['size'] > 10 * 1024 * 1024) {
    jsonOut(['status' => 'error', 'message' => 'Ukuran file maksimal 10MB']);
    exit;
}

$tmpPath = sys_get_temp_dir() . '/maint_' . uniqid() . '.' . $ext;
if (!move_uploaded_file($file['tmp_name'], $tmpPath)) {
    jsonOut(['status' => 'error', 'message' => 'Gagal menyimpan file sementara']);
    exit;
}

// ── COLUMN MAP: key = uppercase-trimmed header Excel → value = nama DB ──
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
    'REMAINING DAY'      => 'remaining_day',
    'REMINDER ACTIVITY'     => 'reminder_activity',
];

$SKIP_DEPT_VALUES = ['HARI INI', 'BULAN INI', 'TAHUN INI', 'LEBIH DARI 1 TAHUN'];

function cleanStr($v): ?string
{
    if ($v === null) return null;
    $s = trim((string)$v);
    return ($s === '' || strtolower($s) === 'nan') ? null : $s;
}

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
    $reader = IOFactory::createReaderForFile($tmpPath);
    // ★ KUNCI: setReadDataOnly(TRUE) agar PhpSpreadsheet tidak membaca
    //   style/conditional formatting → menghindari error class tidak ditemukan.
    //   Formula EDATE() tetap terbaca karena formula dievaluasi terpisah.
    $reader->setReadDataOnly(true);
    $spreadsheet = $reader->load($tmpPath);
    @unlink($tmpPath);

    // ── Pilih sheet MASTER SCHEDULE ──────────────────────────────────────
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
    $sheet = $targetName
        ? $spreadsheet->getSheetByName($targetName)
        : $spreadsheet->getActiveSheet();

    $highestRow = $sheet->getHighestDataRow();
    $maxColIdx  = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

    // ── Cari baris header (scan 20 baris pertama) ──────────────────────
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
        jsonOut([
            'status'  => 'error',
            'message' => 'Baris header tidak ditemukan. Pastikan ada kolom DEPARTEMENT di sheet Excel. '
                . 'Sheet yang dicari: "' . ($targetName ?? 'aktif') . '". '
                . 'Sheet tersedia: ' . implode(', ', $sheetNames),
        ]);
        exit;
    }

    // ── Prepared statements — INSERT baru & UPDATE yang sudah ada ───────────
    $stmtInsert = $pdo->prepare("
        INSERT INTO schedules
            (department, line, operation_process, machine_name, process_machine,
             name_unit, maintenance_point, interval_month, use_date, change_date_plan,
             reminder_activity, remaining_day, part_order, part_availability, maintenance_status)
        VALUES
            (:department, :line, :operation_process, :machine_name, :process_machine,
             :name_unit, :maintenance_point, :interval_month, :use_date, :change_date_plan,
             :reminder_activity, :remaining_day, :part_order, :part_availability, :maintenance_status)
    ");
    $stmtUpdate = $pdo->prepare("
        UPDATE schedules SET
            department = :department, line = :line,
            operation_process = :operation_process, machine_name = :machine_name,
            process_machine = :process_machine, name_unit = :name_unit,
            maintenance_point = :maintenance_point, interval_month = :interval_month,
            use_date = :use_date, change_date_plan = :change_date_plan,
            reminder_activity = :reminder_activity, remaining_day = :remaining_day,
            part_order = :part_order, part_availability = :part_availability,
            maintenance_status = :maintenance_status
        WHERE id = :id
    ");
    $stmtCheck = $pdo->prepare("
        SELECT id FROM schedules
        WHERE machine_name = ? AND maintenance_point = ? AND operation_process = ?
        LIMIT 1
    ");

    $today   = new DateTime('today');
    $success = 0;
    $updated = 0;
    $skipped = 0;
    $errors  = [];
    $importEmailQueue = []; // Antrian email notifikasi

    // ── Cache lookup: plant_name (lowercase) → id dari tabel plants ──────
    // Dibuat sekali sebelum loop agar tidak query DB per baris
    $plantCache = [];
    try {
        $plantRows = $pdo->query("SELECT id, plant_name FROM plants")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($plantRows as $p) {
            $plantCache[mb_strtolower(trim($p['plant_name']))] = (int)$p['id'];
        }
    } catch (\Exception $e) {
        error_log('[Import] Gagal load tabel plants: ' . $e->getMessage());
    }

    // ── Cache lookup: line_name (lowercase) → id dari tabel line ─────────
    $lineCache = [];
    try {
        $lineRows = $pdo->query("SELECT id, line_name FROM line")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($lineRows as $ln) {
            $lineCache[mb_strtolower(trim($ln['line_name']))] = (int)$ln['id'];
        }
    } catch (\Exception $e) {
        error_log('[Import] Gagal load tabel line: ' . $e->getMessage());
    }

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

        $dept = cleanStr($get('department'));
        if ($dept === null || in_array(mb_strtoupper($dept), $SKIP_DEPT_VALUES)) {
            $skipped++;
            continue;
        }

        // ── Resolve department: nama → id dari tabel plants ──────────────
        $deptKey = mb_strtolower(trim($dept));
        if (isset($plantCache[$deptKey])) {
            $deptId = $plantCache[$deptKey];
        } else {
            $errors[] = "Baris $r: Department '{$dept}' tidak ditemukan di tabel plants — dilewati.";
            $skipped++;
            continue;
        }

        // ── Resolve line: nama → id dari tabel line ──────────────────────
        $lineRaw = cleanStr($get('line'));
        $lineId  = null;
        if ($lineRaw !== null) {
            $lineKey = mb_strtolower(trim($lineRaw));
            if (isset($lineCache[$lineKey])) {
                $lineId = $lineCache[$lineKey];
            } else {
                // Line tidak ditemukan — catat warning tapi tidak skip
                // karena line bisa nullable tergantung struktur DB Anda
                $errors[] = "Baris $r: Line '{$lineRaw}' tidak ditemukan di tabel line — diisi NULL.";
            }
        }

        $maintPoint = cleanStr($get('maintenance_point'));
        if ($maintPoint === null) {
            $skipped++;
            continue;
        }

        // ── CHANGE DATE PLAN — getCalculatedValue() evaluasi =EDATE() ──
        $changeDatePlan = toDateStr($get('change_date_plan'));

        // Fallback: hitung dari use_date + interval_month
        if ($changeDatePlan === null) {
            $ud = toDateStr($get('use_date'));
            $iv = $get('interval_month');
            if ($ud && is_numeric($iv) && $iv > 0) {
                $dt = new DateTime($ud);
                $dt->modify('+' . (int)$iv . ' month');
                $changeDatePlan = $dt->format('Y-m-d');
            }
        }

        // Kolom change_date_plan NOT NULL — skip jika tidak bisa dihitung
        if ($changeDatePlan === null) {
            $errors[] = "Baris $r ({$dept}): change_date_plan tidak dapat dihitung — dilewati.";
            $skipped++;
            continue;
        }

        // ── Hitung remaining_day ────────────────────────────────────────
        $pd           = new DateTime($changeDatePlan);
        $diff         = (int)$today->diff($pd)->days;
        $remainingDay = ($pd >= $today) ? $diff : -$diff;

        $intervalRaw   = $get('interval_month');
        $remRaw        = $get('reminder_activity');
        $remActivity   = is_numeric($remRaw) ? (int)$remRaw : 30;

        // ── Fallback NOT NULL untuk kolom wajib DB ──────────────────────
        $opProcess   = cleanStr($get('operation_process')) ?? '-';
        $machineName = cleanStr($get('machine_name'))
            ?? cleanStr($get('process_machine'))
            ?? $dept;
        $intervalMonth = is_numeric($intervalRaw) ? (int)$intervalRaw : 0;

        // ── Status awal berdasarkan remaining_day ───────────────────────
        // secure (>remActivity)   → close/done
        // reminder, alert, overdue → open/soon  (overdue = terlambat, tetap perlu tindakan)
        $needsAction = (
            $remainingDay <= 0 ||                                    // overdue
            ($remainingDay >= 1 && $remainingDay <= 7) ||            // alert
            ($remActivity > 0 && $remainingDay <= $remActivity)      // reminder
        );
        $partStatus  = $needsAction ? 'open'  : 'close';
        $maintStatus = $needsAction ? 'soon'  : 'done';

        $params = [
            ':department'        => $deptId,
            ':line'              => $lineId,
            ':operation_process' => $opProcess,
            ':machine_name'      => $machineName,
            ':process_machine'   => cleanStr($get('process_machine')),
            ':name_unit'         => cleanStr($get('name_unit')),
            ':maintenance_point' => $maintPoint,
            ':interval_month'    => $intervalMonth,
            ':use_date'          => toDateStr($get('use_date')),
            ':change_date_plan'  => $changeDatePlan,
            ':reminder_activity' => $remActivity,
            ':remaining_day'     => $remainingDay,
            ':part_order'        => $partStatus,
            ':part_availability' => $partStatus,
            ':maintenance_status' => $maintStatus,
        ];

        try {
            // UPSERT: cek apakah sudah ada data dengan kombinasi kunci yang sama
            $stmtCheck->execute([$machineName, $maintPoint, $opProcess]);
            $existingId = $stmtCheck->fetchColumn();

            if ($existingId) {
                // Data sudah ada → UPDATE (timpa data lama dengan data baru)
                $stmtUpdate->execute(array_merge($params, [':id' => (int)$existingId]));
                $newId = (int)$existingId;
                $updated++;
            } else {
                // Belum ada → INSERT baru
                $stmtInsert->execute($params);
                $newId = (int)$pdo->lastInsertId();
                $success++;
            }

            // Tandai untuk notifikasi email jika masuk kategori reminder
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

    // Kirim notifikasi email setelah semua baris selesai diproses
    // Dikelompokkan per kategori (overdue/alert7/threshold) → maks 3 email per import
    if (!empty($importEmailQueue) && function_exists('sendImportAlert')) {
        try {
            sendImportAlert($pdo, $importEmailQueue);
        } catch (\Exception $e) {
            error_log('[Import] Gagal kirim email notifikasi: ' . $e->getMessage());
        }
    }

    $inserted = $success;
    $total    = $inserted + $updated;
    $msg = "{$total} data berhasil diimport dari Excel ({$inserted} baru, {$updated} diperbarui).";
    if ($skipped > 0)    $msg .= " {$skipped} baris dilewati (kosong/summary/tanpa tanggal).";
    if (!empty($errors)) $msg .= ' ' . count($errors) . ' baris gagal.';

    jsonOut([
        'status'   => $total > 0 ? 'success' : 'error',
        'message'  => $msg,
        'imported' => $total,
        'inserted' => $inserted,
        'updated'  => $updated,
        'skipped'  => $skipped,
        'errors'   => array_slice($errors, 0, 10),
        'debug'    => [
            'sheet_used'     => $targetName ?? 'aktif',
            'header_row'     => $headerRowNum,
            'columns_found'  => array_keys($colIndexMap),
            'total_scanned'  => $highestRow - $headerRowNum,
        ],
    ]);
} catch (\Exception $e) {
    @unlink($tmpPath ?? '');
    jsonOut(['status' => 'error', 'message' => 'Gagal memproses file: ' . $e->getMessage()]);
}
