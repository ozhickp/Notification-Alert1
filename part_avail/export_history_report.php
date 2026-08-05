<?php
// export_history_report.php
set_time_limit(0);          // [FIX-1] Unlimited — monthly mode bisa sangat lama
ini_set('memory_limit', '512M'); // [FIX-1] Naikkan dari 256M

session_start();
include 'config.php';

if (!isset($_SESSION['user_id'], $_SESSION['role']) || !in_array($_SESSION['role'], [ROLE_ADMIN_MAINTENANCE, ROLE_TECHNICIAN, ROLE_ADMIN_CONROD, ROLE_SUPERADMIN], true)) {
    header('Location: login_user.php');
    exit;
}

// admin_conrod bisa melihat & export laporan dari SELURUH tim admin_conrod
// (bukan cuma laporan yang dia buat sendiri) — konsisten dengan tabel di
// history_report.php.
$isConrodOnly    = ($_SESSION['role'] === ROLE_ADMIN_CONROD);
$currentUsername = $_SESSION['username'] ?? '';

require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

// ── Parameter ──────────────────────────────────────────────────────────────────
$mode    = $_GET['mode']    ?? 'daily';
$tanggal = $_GET['tanggal'] ?? '';
$bulan   = $_GET['bulan']   ?? '';
$rangeStart = trim($_GET['start'] ?? '');
$rangeEnd   = trim($_GET['end']   ?? '');
$dept    = trim($_GET['dept']   ?? '');
$source  = trim($_GET['source'] ?? '');

// ── Helper: nama bulan Indonesia — dipakai untuk label periode & tidak
// bergantung ke setlocale() (sering tidak konsisten antar server).
function bulanIndo(int $m): string
{
    $bulan = [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Maret',
        4 => 'April',
        5 => 'Mei',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'Agustus',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember',
    ];
    return $bulan[$m] ?? (string) $m;
}

// ── Validasi + filename ───────────────────────────────────────────────────────
if ($mode === 'daily') {
    if ($tanggal === '') die('Pilih tanggal terlebih dahulu.');
    $dt = DateTime::createFromFormat('Y-m-d', $tanggal);
    if (!$dt) die('Format tanggal tidak valid.');
    $whereDate    = "DATE(r.created_at) = " . $pdo->quote($tanggal);
    // Tanggal Indonesia, mis. "30 Juli 2026"
    $periodeLabel = 'Tanggal : ' . $dt->format('d') . ' ' . bulanIndo((int) $dt->format('n')) . ' ' . $dt->format('Y');
    // Nama file: dd_mm_yyyy
    $filename     = 'History_EReport_daily_' . $dt->format('d_m_Y');
} elseif ($mode === 'monthly') {
    if ($bulan === '') die('Pilih bulan terlebih dahulu.');
    $bulanParts = explode('-', $bulan); // [YYYY, MM]
    if (count($bulanParts) !== 2) die('Format bulan tidak valid.');
    [$tahunNum, $bulanNum] = $bulanParts;
    $whereDate    = "DATE_FORMAT(r.created_at, '%Y-%m') = " . $pdo->quote($bulan);
    // Bulan Indonesia, mis. "Juli 2026"
    $periodeLabel = 'Bulan : ' . bulanIndo((int) $bulanNum) . ' ' . $tahunNum;
    // Nama file: mm_yyyy
    $filename     = 'History_EReport_monthly_' . $bulanNum . '_' . $tahunNum;
} elseif ($mode === 'range') {
    if ($rangeStart === '' || $rangeEnd === '') die('Pilih tanggal awal & akhir terlebih dahulu.');
    $dtStart = DateTime::createFromFormat('Y-m-d', $rangeStart);
    $dtEnd   = DateTime::createFromFormat('Y-m-d', $rangeEnd);
    if (!$dtStart || !$dtEnd) die('Format tanggal rentang tidak valid.');
    if ($dtStart > $dtEnd) die('Tanggal awal tidak boleh lebih besar dari tanggal akhir.');
    $whereDate    = "DATE(r.created_at) BETWEEN " . $pdo->quote($rangeStart) . " AND " . $pdo->quote($rangeEnd);
    // mis. "1 Agustus 2026 – 5 Agustus 2026"
    $periodeLabel = 'Rentang : ' . $dtStart->format('d') . ' ' . bulanIndo((int) $dtStart->format('n')) . ' ' . $dtStart->format('Y')
        . ' – ' . $dtEnd->format('d') . ' ' . bulanIndo((int) $dtEnd->format('n')) . ' ' . $dtEnd->format('Y');
    // Nama file: dd_mm_yyyy_sd_dd_mm_yyyy
    $filename     = 'History_EReport_range_' . $dtStart->format('d_m_Y') . '_sd_' . $dtEnd->format('d_m_Y');
} else {
    die('Mode tidak valid.');
}

// ── Judul utama sheet — beda untuk admin_conrod (khusus laporan Connecting Rod
// Engine Stop) vs role lain (format E-Report umum, tidak berubah). Untuk mode
// "range" pakai judul generik yang sama untuk semua rentang tanggal.
if ($isConrodOnly) {
    if ($mode === 'daily') {
        $reportTitle = 'DAILY REPORT CONNECTING ROD ENGINE STOP';
    } elseif ($mode === 'monthly') {
        $reportTitle = 'MONTHLY REPORT CONNECTING ROD ENGINE STOP';
    } else {
        $reportTitle = 'REPORT CONNECTING ROD ENGINE STOP (RENTANG TANGGAL)';
    }
} else {
    if ($mode === 'daily') {
        $reportTitle = 'HISTORY E-REPORT MAINTENANCE';
    } elseif ($mode === 'monthly') {
        $reportTitle = 'MONTHLY E-REPORT MAINTENANCE';
    } else {
        $reportTitle = 'HISTORY E-REPORT MAINTENANCE (RENTANG TANGGAL)';
    }
}

// ── Ekspresi SQL: apakah laporan AWAL (root) dari rangkaian baris ini berasal
// dari tim admin_conrod (foreman terisi / source_role admin_conrod, dengan
// fallback ke role user pelapor untuk baris lawas). IDENTIK dengan ekspresi
// yang dipakai di history_report.php, supaya hasil export selalu sinkron
// dengan apa yang tampil di tabel web — dipakai untuk 2 hal: (1) membatasi
// visibilitas admin_conrod ke SELURUH tim (bukan cuma dirinya sendiri), dan
// (2) filter dropdown "sumber laporan" di UI.
$rootConrodExpr = "EXISTS (
    SELECT 1 FROM e_reports rt
    LEFT JOIN users ru ON ru.username = rt.reported_by
    WHERE rt.id = COALESCE(r.parent_id, r.id)
      AND (
            (rt.foreman IS NOT NULL AND rt.foreman <> '')
         OR (rt.source_role IS NOT NULL AND rt.source_role = 'admin_conrod')
         OR (rt.source_role IS NULL AND ru.role = 'admin_conrod')
      )
)";

// admin_conrod: export laporan dari SEMUA admin_conrod (bukan cuma miliknya
// sendiri) beserta seluruh follow-up-nya — tapi TETAP tidak boleh lihat
// laporan/rangkaian yang berasal dari admin_maintenance/technician.
if ($isConrodOnly) {
    $whereDate .= " AND {$rootConrodExpr}";
}

// ── Filter Department & Sumber Laporan — sinkron dengan filter yang aktif di
// tabel history_report.php, supaya file yang terdownload persis sama dengan
// apa yang lagi dilihat user (bukan selalu semua data periode itu).
if ($dept !== '') {
    $whereDate .= " AND r.department = " . $pdo->quote($dept);
    $periodeLabel .= '  |  Dept: ' . $dept;
    $filename      .= '_' . preg_replace('/[^A-Za-z0-9]+/', '', $dept);
}

if ($source === 'conrod' || $source === 'maintenance') {
    // Reuse ekspresi $rootConrodExpr yang sama (didefinisikan sekali di atas)
    // supaya logikanya selalu identik dengan filter visibilitas admin_conrod.
    $whereDate .= $source === 'conrod' ? " AND {$rootConrodExpr}" : " AND NOT {$rootConrodExpr}";
    $periodeLabel .= '  |  Sumber: ' . ($source === 'conrod' ? 'Admin Conrod' : 'Maintenance / Technician');
    $filename      .= '_' . $source;
}

$filename .= '.xlsx';

// ── Query ─────────────────────────────────────────────────────────────────────
$rows = $pdo->query("
    SELECT r.id, r.parent_id, r.report_date, r.department, r.line, r.op, r.shift,
           r.machine_name, r.machine_type, r.repair_start, r.repair_finish,
           r.reported_by, r.pic, r.problem, r.action, r.status, r.created_at,
           r.foreman, r.source_role
    FROM e_reports r
    WHERE {$whereDate}
    ORDER BY r.report_date ASC, r.created_at ASC
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) die("Tidak ada data untuk periode yang dipilih.");

// Map id (database) → nomor urut "No" di sheet, dipakai sama untuk semua mode
// karena urutan baris ($rows) identik antar sheet.
$idToNo = [];
foreach ($rows as $i => $r) {
    $idToNo[$r['id']] = $i + 1;
}
// Reverse map No → id, dipakai untuk hyperlink Chain ID (cari baris root by No)
$noToId = array_flip($idToNo);

// Map id → row lengkap, dipakai untuk menelusuri rantai parent_id ke akar (root)
$rowsById = [];
foreach ($rows as $r) {
    $rowsById[$r['id']] = $r;
}

// ── Helper: telusuri rantai parent_id sampai ke record akar (root), lalu
// kembalikan "No" root tersebut — dipakai sebagai "Chain ID" supaya semua
// record dalam satu rantai follow-up bisa di-filter/sort jadi satu grup,
// terlepas dari posisi tanggal/barisnya di sheet.
function findChainRoot($row, array $rowsById)
{
    $current = $row;
    $guard   = 0; // safety guard, cegah infinite loop kalau data parent_id circular
    while (!empty($current['parent_id']) && isset($rowsById[$current['parent_id']]) && $guard < 50) {
        $current = $rowsById[$current['parent_id']];
        $guard++;
    }
    return $current;
}

function findChainRootNo($row, array $rowsById, array $idToNo)
{
    $root = findChainRoot($row, $rowsById);
    return $idToNo[$root['id']] ?? null;
}

// ── Helper: info sumber laporan (admin_conrod) — dilihat dari record akar
// (root) rantai, bukan dari baris itu sendiri, supaya baris lanjutan
// (follow-up dari admin_maintenance/technician) tetap menampilkan info
// Foreman conrod yang jadi asal laporannya. Sinyal utama: kolom `foreman`
// terisi (sama seperti logika di dashboard_report.php).
function conrodSourceLabel($row, array $rowsById)
{
    $root = findChainRoot($row, $rowsById);
    if (!empty($root['foreman'])) {
        return 'Conrod • Foreman: ' . $root['foreman'];
    }
    return '—';
}

// ── Helper: rujukan "continuation of work from" harus menunjuk ke nomor urut "No" yang
// tampil di sheet, bukan ID mentah di database — supaya konsisten dengan apa
// yang dilihat user dan bisa langsung dicocokkan ke baris di atasnya.
function buildLanjutanLabel($parentId, array $idToNo)
{
    if (!$parentId) return '—';
    return isset($idToNo[$parentId]) ? '#' . $idToNo[$parentId] : '#' . $parentId . ' (luar periode)';
}

// ── Helper durasi ─────────────────────────────────────────────────────────────
function durasiMenit($start, $finish)
{
    if (empty($start) || empty($finish)) return '—';
    $diff = (new DateTime($start))->diff(new DateTime($finish));
    return ($diff->days * 1440) + ($diff->h * 60) + $diff->i;
}

// ── Helper label status ────────────────────────────────────────────────────────
function statusLabel($status)
{
    return $status === 'selesai' ? 'Selesai' : 'Belum Selesai';
}

// ── Helper total durasi (untuk baris Total) ─────────────────────────────────────
// Hanya menjumlahkan baris yang punya repair_start & repair_finish valid;
// baris dengan durasi '—' (belum selesai / data kosong) diabaikan.
function totalDurasiMenit(array $rows)
{
    $total = 0;
    foreach ($rows as $r) {
        $d = durasiMenit($r['repair_start'], $r['repair_finish']);
        if (is_int($d)) $total += $d;
    }
    return $total;
}

function formatDurasi($menit)
{
    $jam       = intdiv($menit, 60);
    $sisaMenit = $menit % 60;
    return "{$menit} mnt ({$jam}j {$sisaMenit}m)";
}

// ── Helper: pasang hyperlink internal (dalam sheet yang sama) ke sel target,
// supaya user tinggal klik "#N" untuk lompat langsung ke baris asal/root-nya
// tanpa perlu scroll & cari manual.
function setInternalLink($sheet, string $cellCoord, int $targetRow)
{
    $sheetName = $sheet->getTitle();
    $sheet->getCell($cellCoord)->getHyperlink()->setUrl("sheet://'{$sheetName}'!A{$targetRow}");
    $sheet->getStyle($cellCoord)->getFont()->setUnderline(true)->getColor()->setRGB('2563EB');
}

// ── Shared styles ─────────────────────────────────────────────────────────────
$styleDivider = [
    'font'      => ['bold' => true, 'size' => 9, 'color' => ['rgb' => '64748B'], 'italic' => true],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F1F5F9']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CBD5E1']]],
];
$styleBorder = [
    // [FIX-BORDER] E2E8F0 terlalu pucat — hampir tidak terlihat di Excel saat
    // gridlines dimatikan. Diganti ke 94A3B8 (lebih gelap) supaya border benar-benar
    // terlihat jelas, bukan cuma "ada" secara teknis di file XLSX.
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '94A3B8']]],
];

// Kolom header — Detail (19 kolom, lengkap untuk analisis & arsip teknis)
$colHeaders = [
    'No',
    'Report Date',
    'Department',
    'Line',
    'OP',
    'Sumber (Conrod)',
    'machine name',
    'machine type',
    'Shift',
    'Repair Start',
    'Repair Finish',
    'duration (mnt)',
    'Reported By',
    'PIC/Technician',
    'Problem / Alarm',
    'Corrective Action',
    'Chain ID',
    'continuation of work from',
    'Status',
    'Submitted At'
];

// ── Excel ─────────────────────────────────────────────────────────────────────
$spreadsheet = new Spreadsheet();
$spreadsheet->getCalculationEngine()->disableCalculationCache();

// Lebar kolom tetap (dipakai semua sheet) — N & O dilebarkan untuk Problem/Action
// dengan wrap text supaya isian berpoin-poin tetap terbaca rapi ke bawah, bukan
// melebar ke samping seperti hasil autosize.
$colWidths = [
    'A' => 5,   // No
    'B' => 13,  // Report Date
    'C' => 18,  // Department
    'D' => 14,  // Line
    'E' => 8,   // OP
    'F' => 20,  // Sumber (Conrod)
    'G' => 24,  // machine name
    'H' => 16,  // machine type
    'I' => 10,  // Shift
    'J' => 18,  // Repair Start
    'K' => 18,  // Repair Finish
    'L' => 10,  // duration (mnt)
    'M' => 16,  // Reported By
    'N' => 16,  // PIC/Technician
    'O' => 38,  // Problem / Alarm
    'P' => 38,  // Corrective Action
    'Q' => 12,  // Chain ID
    'R' => 14,  // continuation of work from
    'S' => 14,  // Status
    'T' => 18   // Submitted At
];

// ═════════════════════════════════════════════════════════════════════════════
// MODE DAILY — 1 sheet, 1 blok per record
// ═════════════════════════════════════════════════════════════════════════════
if ($mode === 'daily') {

    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle("Daily E-Report");

    if (file_exists('assets/company_logo.jpg')) {
        $logo = new Drawing();
        $logo->setName('Company Logo');
        $logo->setPath('assets/company_logo.jpg');
        $logo->setHeight(55);
        $logo->setCoordinates('A1');
        $logo->setWorksheet($sheet);
    }

    $sheet->mergeCells('A1:T1');
    $sheet->setCellValue('A1', $reportTitle);
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(15);
    $sheet->getStyle('A1')->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->getRowDimension(1)->setRowHeight(45);
    $sheet->mergeCells('A2:T2');
    $sheet->setCellValue('A2', $periodeLabel);
    $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('A2')->getFont()->setSize(11);

    $styleInfo = [
        'font'      => ['bold' => true, 'size' => 9, 'color' => ['rgb' => 'FFFFFF']],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '334155']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
    ];
    $styleHdr = [
        'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 9],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '198754']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    ];

    $startRow = 4;
    $idToActualRow = []; // id → actual row Excel tempat data record itu ditulis (khusus sheet ini)
    foreach ($rows as $idx => $r) {
        $infoRow  = $startRow;
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
        $sheet->mergeCells("A{$infoRow}:T{$infoRow}");
        $sheet->setCellValue("A{$infoRow}", $infoText);
        $sheet->getStyle("A{$infoRow}")->applyFromArray($styleInfo);
        $sheet->getRowDimension($infoRow)->setRowHeight(18);
        $startRow++;

        $hdrRow = $startRow;
        $sheet->fromArray($colHeaders, NULL, "A{$hdrRow}");
        $sheet->getStyle("A{$hdrRow}:T{$hdrRow}")->applyFromArray($styleHdr);
        // [FIX-WRAP-HDR] -1 = auto-height, supaya header yang wrap (2+ baris) full terlihat
        $sheet->getRowDimension($hdrRow)->setRowHeight(-1);
        $startRow++;

        $chainRootNo = findChainRootNo($r, $rowsById, $idToNo);

        $sheet->fromArray([
            $idx + 1,
            $r['report_date']   ? date('d-M-Y', strtotime($r['report_date']))        : '—',
            $r['department']    ?? '—',
            $r['line']          ?? '—',
            $r['op']            ?? '—',
            conrodSourceLabel($r, $rowsById),
            $r['machine_name']  ?? '—',
            $r['machine_type']  ?? '—',
            $r['shift']         ?? '—',
            $r['repair_start']  ? date('d-M-Y H:i', strtotime($r['repair_start']))   : '—',
            $r['repair_finish'] ? date('d-M-Y H:i', strtotime($r['repair_finish']))  : '—',
            durasiMenit($r['repair_start'], $r['repair_finish']),
            $r['reported_by']   ?? '—',
            $r['pic']           ?? '—',
            $r['problem']       ?? '—',
            $r['action']        ?? '—',
            $chainRootNo !== null ? '#' . $chainRootNo : '—',
            buildLanjutanLabel($r['parent_id'], $idToNo),
            statusLabel($r['status']),
            $r['created_at']    ? date('d-M-Y H:i', strtotime($r['created_at']))     : '—',
        ], NULL, "A{$startRow}");
        $sheet->getStyle("A{$startRow}:T{$startRow}")->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        // [FIX-WRAP] -1 = auto-height, tinggi baris menyesuaikan konten wrap text
        $sheet->getRowDimension($startRow)->setRowHeight(-1);

        foreach (['A', 'E', 'I', 'L', 'Q', 'R', 'S'] as $col) {
            $sheet->getStyle("{$col}{$startRow}")
                ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        // ── Hyperlink klik-lompat: Chain ID → baris root, continuation of
        // work from → baris parent langsung. Hanya dipasang kalau baris
        // targetnya sudah pernah ditulis di sheet ini (root/parent selalu
        // dibuat lebih dulu secara kronologis daripada follow-up-nya).
        if ($chainRootNo !== null && isset($noToId[$chainRootNo], $idToActualRow[$noToId[$chainRootNo]])) {
            $rootActualRow = $idToActualRow[$noToId[$chainRootNo]];
            if ($rootActualRow !== $startRow) {
                setInternalLink($sheet, "Q{$startRow}", $rootActualRow);
            }
        }
        if (!empty($r['parent_id']) && isset($idToActualRow[$r['parent_id']])) {
            setInternalLink($sheet, "R{$startRow}", $idToActualRow[$r['parent_id']]);
        }
        $idToActualRow[$r['id']] = $startRow;

        $sheet->getStyle("A{$infoRow}:T{$startRow}")->applyFromArray($styleBorder);
        $startRow += 2;
    }

    foreach ($colWidths as $col => $w) {
        $sheet->getColumnDimension($col)->setWidth($w);
    }
    $sheet->freezePane('A4');

    // ═════════════════════════════════════════════════════════════════════════════
    // MODE MONTHLY — 1 sheet, format Detail (18 kolom lengkap) dengan header
    // warna hijau (eks-Summary) + baris Total (record & durasi) di paling bawah.
    // ═════════════════════════════════════════════════════════════════════════════
} else {

    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle($mode === 'monthly' ? "Monthly E-Report" : "Range E-Report");

    if (file_exists('assets/company_logo.jpg')) {
        $logo = new Drawing();
        $logo->setName('Company Logo');
        $logo->setPath('assets/company_logo.jpg');
        $logo->setHeight(55);
        $logo->setCoordinates('A1');
        $logo->setWorksheet($sheet);
    }

    $sheet->mergeCells('A1:T1');
    $sheet->setCellValue('A1', $reportTitle);
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(15);
    $sheet->getStyle('A1')->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->getRowDimension(1)->setRowHeight(45);
    $sheet->mergeCells('A2:T2');
    $sheet->setCellValue('A2', $periodeLabel);
    $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('A2')->getFont()->setSize(11);

    // Header kolom — warna hijau (198754), sama seperti sheet Summary sebelumnya
    $sheet->fromArray($colHeaders, NULL, 'A4');
    $sheet->getStyle('A4:T4')->applyFromArray([
        'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 9],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '198754']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    ]);
    // [FIX-WRAP-HDR] -1 = auto-height, supaya header yang wrap (2+ baris) full terlihat
    $sheet->getRowDimension(4)->setRowHeight(-1);

    $row           = 5;
    $no            = 1;
    $prevDate      = '';
    $dataRows      = [];
    $idToActualRow = []; // id → actual row Excel tempat data record itu ditulis (khusus sheet ini)

    foreach ($rows as $r) {
        $curDate = substr($r['report_date'], 0, 10);

        if ($curDate !== $prevDate) {
            $sheet->mergeCells("A{$row}:T{$row}");
            $sheet->setCellValue("A{$row}", "— " . date('d F Y', strtotime($curDate)) . " —");
            $sheet->getStyle("A{$row}:T{$row}")->applyFromArray($styleDivider);
            $sheet->getRowDimension($row)->setRowHeight(14);
            $row++;
            $prevDate = $curDate;
        }

        $chainRootNo = findChainRootNo($r, $rowsById, $idToNo);

        $sheet->fromArray([
            $no++,
            $r['report_date']   ? date('d-M-Y', strtotime($r['report_date']))        : '—',
            $r['department']    ?? '—',
            $r['line']          ?? '—',
            $r['op']            ?? '—',
            conrodSourceLabel($r, $rowsById),
            $r['machine_name']  ?? '—',
            $r['machine_type']  ?? '—',
            $r['shift']         ?? '—',
            $r['repair_start']  ? date('d-M-Y H:i', strtotime($r['repair_start']))   : '—',
            $r['repair_finish'] ? date('d-M-Y H:i', strtotime($r['repair_finish']))  : '—',
            durasiMenit($r['repair_start'], $r['repair_finish']),
            $r['reported_by']   ?? '—',
            $r['pic']           ?? '—',
            $r['problem']       ?? '—',
            $r['action']        ?? '—',
            $chainRootNo !== null ? '#' . $chainRootNo : '—',
            buildLanjutanLabel($r['parent_id'], $idToNo),
            statusLabel($r['status']),
            $r['created_at']    ? date('d-M-Y H:i', strtotime($r['created_at']))     : '—',
        ], NULL, "A{$row}");

        // ── Hyperlink klik-lompat: Chain ID → baris root, continuation of
        // work from → baris parent langsung. Root/parent selalu sudah
        // ditulis lebih dulu di sheet ini (urutan kronologis).
        if ($chainRootNo !== null && isset($noToId[$chainRootNo], $idToActualRow[$noToId[$chainRootNo]])) {
            $rootActualRow = $idToActualRow[$noToId[$chainRootNo]];
            if ($rootActualRow !== $row) {
                setInternalLink($sheet, "Q{$row}", $rootActualRow);
            }
        }
        if (!empty($r['parent_id']) && isset($idToActualRow[$r['parent_id']])) {
            setInternalLink($sheet, "R{$row}", $idToActualRow[$r['parent_id']]);
        }
        $idToActualRow[$r['id']] = $row;

        $dataRows[] = $row;
        // [FIX-WRAP] -1 = auto-height, tinggi baris menyesuaikan konten wrap text
        $sheet->getRowDimension($row)->setRowHeight(-1);
        $row++;
    }

    // Alignment massal — kolom angka/kode di tengah (No, OP, Shift, Durasi,
    // Chain ID, continuation of work from, Status), sisanya rata kiri
    // (default General) supaya teks panjang tetap enak dibaca dengan wrap text.
    if (!empty($dataRows)) {
        $first = $dataRows[0];
        $last  = $dataRows[count($dataRows) - 1];
        $sheet->getStyle("A{$first}:T{$last}")->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        foreach (['A', 'E', 'I', 'L', 'Q', 'R', 'S'] as $col) {
            $sheet->getStyle("{$col}{$first}:{$col}{$last}")->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
    }

    if ($row > 5) {
        $sheet->getStyle("A4:T" . ($row - 1))->applyFromArray($styleBorder);
    }

    // AutoFilter di header — supaya user bisa filter/sort langsung by Chain ID
    // untuk lihat seluruh rantai follow-up sekaligus, terlepas dari tanggalnya.
    if (!empty($dataRows)) {
        $sheet->setAutoFilter("A4:T" . $dataRows[count($dataRows) - 1]);
    }

    // ── Baris Total : total record + total durasi — style sama seperti baris
    // Total di sheet Summary sebelumnya (dark fill, bold, putih)
    $totalMenit = totalDurasiMenit($rows);
    $sheet->mergeCells("A{$row}:T{$row}");
    $sheet->setCellValue(
        "A{$row}",
        "TOTAL : " . count($rows) . " record   |   Total Durasi : " . formatDurasi($totalMenit)
    );
    $sheet->getStyle("A{$row}:T{$row}")->applyFromArray([
        'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E293B']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getRowDimension($row)->setRowHeight(18);

    foreach ($colWidths as $col => $w) {
        $sheet->getColumnDimension($col)->setWidth($w);
    }
    $sheet->freezePane('A5');
}

// ── Export ─────────────────────────────────────────────────────────────────────
// [FIX-3] Save ke file temp dulu, baru stream ke browser
// Langsung ke php://output berisiko: koneksi browser bisa timeout sebelum
// PhpSpreadsheet selesai generate (terutama monthly mode dengan 2 sheet).
// Content-Length memberi tahu browser ukuran file yang akan datang,
// sehingga download bar muncul dan koneksi tidak dianggap "menggantung".
$writer = new Xlsx($spreadsheet);
$writer->setPreCalculateFormulas(false);

$tmpFile = tempnam(sys_get_temp_dir(), 'ereport_');
$writer->save($tmpFile);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"{$filename}\"");
header('Cache-Control: max-age=0');
header('Content-Length: ' . filesize($tmpFile));

readfile($tmpFile);
unlink($tmpFile);
exit;
