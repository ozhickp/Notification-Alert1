<?php
session_start();
require_once __DIR__ . '/config.php';
require 'vendor/autoload.php'; // library sama dengan yang dipakai export_history_report.php

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// Fitur ini khusus untuk admin_conrod menambah banyak "Laporan Awal" sekaligus
// (sama seperti submit satu-satu di dashboard_report.php, tapi lewat Excel) —
// superadmin ikut diberi akses untuk keperluan backup/admin.
requireRole([ROLE_ADMIN_CONROD, ROLE_SUPERADMIN]);
$currentUsername = $_SESSION['username'] ?? 'Unknown';

const IMPORT_DEPARTMENT = 'Connecting Rod'; // sama seperti pembatasan admin_conrod di dashboard_report.php

// ─── Download template Excel ───────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'template') {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Template Report Conrod');

    $headers = ['Line', 'OP', 'Nama Mesin', 'Tanggal Kejadian (YYYY-MM-DD)', 'Jam Kejadian (HH:MM)', 'Shift', 'Foreman', 'Problem'];
    $sheet->fromArray($headers, null, 'A1');
    $sheet->getStyle('A1:H1')->getFont()->setBold(true);
    foreach (range('A', 'H') as $col) {
        $sheet->getColumnDimension($col)->setWidth(22);
    }
    $sheet->getColumnDimension('H')->setWidth(40);

    // Baris contoh — dihapus dulu oleh user sebelum diisi data asli.
    $sheet->fromArray(
        ['Conrod 1', 'OP10', 'Grinding - Koyo Mattison', date('Y-m-d'), '07:30', 'Shift 1', 'Nama Foreman', 'Contoh problem/alarm yang terjadi'],
        null,
        'A2'
    );

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="template_import_report_conrod.xlsx"');
    header('Cache-Control: max-age=0');
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

// ─── AJAX: proses upload ────────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    if (empty($_FILES['excel_file']['tmp_name']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'File Excel tidak ditemukan atau gagal diupload.']);
        exit;
    }

    $ext = strtolower(pathinfo($_FILES['excel_file']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'xls'], true)) {
        echo json_encode(['success' => false, 'message' => 'Format file harus .xlsx atau .xls.']);
        exit;
    }

    try {
        $spreadsheet = IOFactory::load($_FILES['excel_file']['tmp_name']);
    } catch (\Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Gagal membaca file Excel: ' . $e->getMessage()]);
        exit;
    }

    $sheet = $spreadsheet->getActiveSheet();
    $highestRow = $sheet->getHighestDataRow();

    $addedCount = 0;
    $duplicateCount = 0;
    $skippedInvalid = []; // ['row' => n, 'reason' => '...']

    // Cache lookup machine_list supaya tidak query berulang untuk kombinasi yang sama.
    $machineCache = [];

    $insertStmt = $pdo->prepare("
        INSERT INTO e_reports
          (department, `line`, op, shift, machine_name, machine_type, report_date,
           repair_start, repair_finish, duration_minutes, reported_by, foreman, pic, problem, action, status, source_role, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?, NULL, ?, NULL, 'belum selesai', ?, NOW())
    ");

    $dupCheckStmt = $pdo->prepare("
        SELECT id FROM e_reports
        WHERE department = ?
          AND LOWER(TRIM(`line`)) = LOWER(TRIM(?))
          AND LOWER(TRIM(machine_name)) = LOWER(TRIM(?))
          AND DATE(repair_start) = ?
          AND shift = ?
          AND LOWER(TRIM(problem)) = LOWER(TRIM(?))
        LIMIT 1
    ");

    for ($rowNum = 2; $rowNum <= $highestRow; $rowNum++) {
        $line      = trim((string)$sheet->getCell("A{$rowNum}")->getCalculatedValue());
        $op        = trim((string)$sheet->getCell("B{$rowNum}")->getCalculatedValue());
        $machine   = trim((string)$sheet->getCell("C{$rowNum}")->getCalculatedValue());
        $dateCell  = $sheet->getCell("D{$rowNum}");
        $timeCell  = $sheet->getCell("E{$rowNum}");
        $shift     = trim((string)$sheet->getCell("F{$rowNum}")->getCalculatedValue());
        $foreman   = trim((string)$sheet->getCell("G{$rowNum}")->getCalculatedValue());
        $problem   = trim((string)$sheet->getCell("H{$rowNum}")->getCalculatedValue());

        // Lewati baris yang benar-benar kosong (mis. baris kosong di akhir file).
        if ($line === '' && $op === '' && $machine === '' && $shift === '' && $foreman === '' && $problem === '') {
            continue;
        }

        if ($line === '' || $op === '' || $machine === '' || $shift === '' || $foreman === '' || $problem === '') {
            $skippedInvalid[] = ['row' => $rowNum, 'reason' => 'Ada kolom wajib yang kosong (Line/OP/Mesin/Shift/Foreman/Problem).'];
            continue;
        }

        if (!in_array($shift, ['Shift 1', 'Shift 2', 'Shift 3'], true)) {
            $skippedInvalid[] = ['row' => $rowNum, 'reason' => "Shift \"$shift\" tidak valid (harus Shift 1/Shift 2/Shift 3)."];
            continue;
        }

        $dateStr = normalizeExcelDate($dateCell);
        $timeStr = normalizeExcelTime($timeCell);
        if (!$dateStr || !$timeStr) {
            $skippedInvalid[] = ['row' => $rowNum, 'reason' => 'Format Tanggal/Jam Kejadian tidak bisa dibaca.'];
            continue;
        }
        $repairStart = "$dateStr $timeStr:00";

        // Validasi kombinasi Line/OP/Mesin terhadap master data machine_list —
        // sekalian dipakai untuk auto-isi machine_type.
        $machKey = strtolower($line . '|' . $op . '|' . $machine);
        if (!array_key_exists($machKey, $machineCache)) {
            $mstmt = $pdo->prepare("
                SELECT machine_type FROM machine_list
                WHERE department = ? AND LOWER(TRIM(`line`)) = LOWER(TRIM(?)) AND LOWER(TRIM(op)) = LOWER(TRIM(?)) AND LOWER(TRIM(machine_name)) = LOWER(TRIM(?))
                LIMIT 1
            ");
            $mstmt->execute([IMPORT_DEPARTMENT, $line, $op, $machine]);
            $machineCache[$machKey] = $mstmt->fetchColumn(); // false kalau tidak ketemu
        }
        $machineType = $machineCache[$machKey];
        if ($machineType === false) {
            $skippedInvalid[] = ['row' => $rowNum, 'reason' => "Kombinasi Line \"$line\" / OP \"$op\" / Mesin \"$machine\" tidak ditemukan di master data mesin."];
            continue;
        }

        // Cek duplikat: Line + Mesin + Tanggal + Shift + Problem (semua cocok).
        $dupCheckStmt->execute([IMPORT_DEPARTMENT, $line, $machine, $dateStr, $shift, $problem]);
        if ($dupCheckStmt->fetch()) {
            $duplicateCount++;
            continue;
        }

        try {
            $insertStmt->execute([
                IMPORT_DEPARTMENT,
                $line,
                $op,
                $shift,
                $machine,
                $machineType,
                $dateStr,        // report_date — dianggap sama dengan tanggal kejadian
                $repairStart,
                $foreman,
                $currentUsername,
                $problem,
                ROLE_ADMIN_CONROD,
            ]);
            $addedCount++;
        } catch (\Exception $e) {
            $skippedInvalid[] = ['row' => $rowNum, 'reason' => 'Gagal disimpan: ' . $e->getMessage()];
        }
    }

    echo json_encode([
        'success'      => true,
        'added'        => $addedCount,
        'duplicate'    => $duplicateCount,
        'invalid'      => $skippedInvalid,
        'message'      => "$addedCount laporan berhasil ditambahkan, $duplicateCount dilewati karena duplikat" . (count($skippedInvalid) ? ', ' . count($skippedInvalid) . ' baris tidak valid.' : '.'),
    ]);
    exit;
}

/**
 * Normalisasi cell tanggal (bisa berupa serial date Excel atau teks) → 'YYYY-MM-DD'.
 */
function normalizeExcelDate(\PhpOffice\PhpSpreadsheet\Cell\Cell $cell): ?string
{
    $value = $cell->getCalculatedValue();
    if ($value === null || $value === '') return null;

    if (is_numeric($value)) {
        try {
            $dt = ExcelDate::excelToDateTimeObject($value);
            return $dt->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    $ts = strtotime((string)$value);
    return $ts ? date('Y-m-d', $ts) : null;
}

/**
 * Normalisasi cell jam (bisa berupa fraction time Excel atau teks "HH:MM") → 'HH:MM'.
 */
function normalizeExcelTime(\PhpOffice\PhpSpreadsheet\Cell\Cell $cell): ?string
{
    $value = $cell->getCalculatedValue();
    if ($value === null || $value === '') return null;

    if (is_numeric($value)) {
        try {
            $dt = ExcelDate::excelToDateTimeObject($value);
            return $dt->format('H:i');
        } catch (\Throwable $e) {
            return null;
        }
    }

    $str = trim((string)$value);
    if (preg_match('/^(\d{1,2}):(\d{2})/', $str, $m)) {
        return sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
    }
    return null;
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Import Report dari Excel — Conrod</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body {
            background: #f8fafc;
        }

        .card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
        }

        .dropzone {
            border: 2px dashed #cbd5e1;
            border-radius: 12px;
        }

        .dropzone.dragover {
            border-color: #3b82f6;
            background: #eff6ff;
        }

        .toast {
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 12px 18px;
            border-radius: 10px;
            color: #fff;
            font-size: 14px;
            opacity: 0;
            transform: translateY(10px);
            transition: .25s;
            z-index: 999;
            max-width: 360px;
        }

        .toast.show {
            opacity: 1;
            transform: translateY(0);
        }

        .toast.success {
            background: #16a34a;
        }

        .toast.error {
            background: #dc2626;
        }
    </style>
</head>

<body class="p-6">
    <div class="max-w-2xl mx-auto">
        <a href="dashboard_report.php" class="text-slate-400 hover:text-slate-600 text-sm"><i class="fas fa-arrow-left mr-1"></i> Kembali ke E-Report</a>
        <h1 class="text-2xl font-bold text-slate-800 mt-1 mb-1"><i class="fas fa-file-excel text-green-600 mr-2"></i>Import Report dari Excel</h1>
        <p class="text-slate-500 text-sm mb-6">Tambah banyak Laporan Awal Conrod sekaligus. Baris yang datanya sama persis (Line, Mesin, Tanggal, Shift, Problem) dengan laporan yang sudah ada akan otomatis dilewati.</p>

        <div class="card p-6 mb-4">
            <a href="import_excel_conrod.php?ajax=template" class="inline-flex items-center gap-2 text-blue-600 font-semibold text-sm mb-4">
                <i class="fas fa-download"></i> Download Template Excel
            </a>

            <div id="dropzone" class="dropzone p-8 text-center cursor-pointer" onclick="document.getElementById('file-input').click()">
                <i class="fas fa-cloud-arrow-up text-3xl text-slate-300 mb-2"></i>
                <p class="text-slate-500 text-sm" id="dropzone-text">Klik atau seret file .xlsx/.xls ke sini</p>
            </div>
            <input type="file" id="file-input" accept=".xlsx,.xls" class="hidden" onchange="handleFile(this.files)">

            <button id="btn-upload" onclick="uploadFile()" disabled
                class="mt-4 w-full bg-blue-600 hover:bg-blue-700 disabled:bg-slate-300 text-white py-2.5 rounded-lg font-semibold text-sm">
                <i class="fas fa-upload mr-1"></i> Proses Import
            </button>
        </div>

        <div id="result-card" class="card p-6 hidden">
            <h2 class="font-bold text-slate-800 mb-3">Hasil Import</h2>
            <div class="grid grid-cols-2 gap-3 mb-4">
                <div class="bg-green-50 rounded-lg p-3 text-center">
                    <div class="text-2xl font-bold text-green-600" id="result-added">0</div>
                    <div class="text-xs text-green-700">Ditambahkan</div>
                </div>
                <div class="bg-amber-50 rounded-lg p-3 text-center">
                    <div class="text-2xl font-bold text-amber-600" id="result-duplicate">0</div>
                    <div class="text-xs text-amber-700">Dilewati (Duplikat)</div>
                </div>
            </div>
            <div id="invalid-section" class="hidden">
                <p class="text-sm font-semibold text-red-600 mb-2">Baris tidak valid:</p>
                <ul id="invalid-list" class="text-xs text-red-500 space-y-1 list-disc pl-4"></ul>
            </div>
        </div>
    </div>

    <div id="toast" class="toast"></div>

    <script>
        let selectedFile = null;

        function showToast(msg, type = 'success') {
            const t = document.getElementById('toast');
            t.textContent = msg;
            t.className = 'toast show ' + type;
            clearTimeout(t._timer);
            t._timer = setTimeout(() => t.classList.remove('show'), 4000);
        }

        function handleFile(files) {
            if (!files || !files.length) return;
            selectedFile = files[0];
            document.getElementById('dropzone-text').textContent = selectedFile.name;
            document.getElementById('btn-upload').disabled = false;
        }

        const dz = document.getElementById('dropzone');
        dz.addEventListener('dragover', e => {
            e.preventDefault();
            dz.classList.add('dragover');
        });
        dz.addEventListener('dragleave', () => dz.classList.remove('dragover'));
        dz.addEventListener('drop', e => {
            e.preventDefault();
            dz.classList.remove('dragover');
            handleFile(e.dataTransfer.files);
        });

        function uploadFile() {
            if (!selectedFile) return;
            const btn = document.getElementById('btn-upload');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Memproses...';

            const fd = new FormData();
            fd.append('excel_file', selectedFile);

            fetch('import_excel_conrod.php?ajax=upload', {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json())
                .then(res => {
                    if (!res.success) {
                        showToast(res.message || 'Gagal memproses file.', 'error');
                        return;
                    }
                    showToast(res.message, 'success');
                    document.getElementById('result-card').classList.remove('hidden');
                    document.getElementById('result-added').textContent = res.added;
                    document.getElementById('result-duplicate').textContent = res.duplicate;

                    const invalidSection = document.getElementById('invalid-section');
                    const invalidList = document.getElementById('invalid-list');
                    invalidList.innerHTML = '';
                    if (res.invalid && res.invalid.length) {
                        invalidSection.classList.remove('hidden');
                        res.invalid.forEach(item => {
                            const li = document.createElement('li');
                            li.textContent = `Baris ${item.row}: ${item.reason}`;
                            invalidList.appendChild(li);
                        });
                    } else {
                        invalidSection.classList.add('hidden');
                    }
                })
                .catch(() => showToast('Koneksi error.', 'error'))
                .finally(() => {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-upload mr-1"></i> Proses Import';
                });
        }
    </script>
</body>

</html>