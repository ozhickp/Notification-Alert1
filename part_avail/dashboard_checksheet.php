<?php
// dashboard_checksheet.php
require_once __DIR__ . '/config.php';

$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// ─── Helper: resolve category_key dari machine_type + department/line ────────
function resolveCategoryKey(string $machineType, string $dept, string $line): ?string
{
    $type = strtoupper(trim($machineType));
    $dept = strtoupper(trim($dept));
    $line = strtoupper(trim($line));

    if ($type === 'MC')  return 'MC';
    if ($type === 'SPM') return 'SPM';

    if ($type === 'PRODUCTION') {
        $map = [
            'ASSEMBLING'    => 'ASSEMBLING',
            'PAINTING SHOP' => 'PAINTING',
            'PAINTING'      => 'PAINTING',
            'TEST RUNNING'  => 'TEST_RUNNING',
            'PACKING'       => 'PACKING',
        ];
        foreach ($map as $k => $v) {
            if (str_contains($dept, $k)) return $v;
        }
        return null;
    }

    if ($type === 'POWER HOUSE') {
        return str_contains($line, 'BOILER') ? 'BOILER' : 'KOMPRESSOR';
    }

    return null;
}

// ─── AJAX Handlers ────────────────────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    if ($_GET['ajax'] === 'departments') {
        $rows = $pdo->query("SELECT DISTINCT department FROM machine_list ORDER BY department")->fetchAll();
        echo json_encode(array_column($rows, 'department'));
        exit;
    }

    if ($_GET['ajax'] === 'lines' && isset($_GET['department'])) {
        $stmt = $pdo->prepare("SELECT DISTINCT `line` FROM machine_list WHERE department = ? ORDER BY `line`");
        $stmt->execute([$_GET['department']]);
        echo json_encode(array_column($stmt->fetchAll(), 'line'));
        exit;
    }

    if ($_GET['ajax'] === 'ops' && isset($_GET['department'], $_GET['line'])) {
        $stmt = $pdo->prepare("SELECT DISTINCT op FROM machine_list WHERE department = ? AND `line` = ? ORDER BY op");
        $stmt->execute([$_GET['department'], $_GET['line']]);
        $ops = array_column($stmt->fetchAll(), 'op');
        echo json_encode(empty($ops) ? ['-'] : $ops);
        exit;
    }

    // AJAX PERBAIKAN POINT 3: Mengambil list mesin jika dalam 1 OP ada banyak mesin
    if ($_GET['ajax'] === 'machine_list' && isset($_GET['department'], $_GET['line'], $_GET['op'])) {
        $stmt = $pdo->prepare("SELECT machine_name, machine_type FROM machine_list WHERE department = ? AND `line` = ? AND op = ? ORDER BY machine_name");
        $stmt->execute([$_GET['department'], $_GET['line'], $_GET['op']]);
        echo json_encode($stmt->fetchAll());
        exit;
    }

    if ($_GET['ajax'] === 'machine' && isset($_GET['department'], $_GET['line'], $_GET['op'])) {
        $stmt = $pdo->prepare("SELECT machine_name, machine_type FROM machine_list WHERE department = ? AND `line` = ? AND op = ? LIMIT 1");
        $stmt->execute([$_GET['department'], $_GET['line'], $_GET['op']]);
        $row = $stmt->fetch();
        echo json_encode($row ?: ['machine_name' => '', 'machine_type' => '']);
        exit;
    }

    if ($_GET['ajax'] === 'checklist' && isset($_GET['machine_type'])) {
        $dept        = $_GET['department']   ?? '';
        $line        = $_GET['line']         ?? '';
        $op          = $_GET['op']           ?? '';
        $machineName = $_GET['machine_name'] ?? '';
        $key  = resolveCategoryKey($_GET['machine_type'], $dept, $line);

        if (!$key) {
            echo json_encode([]);
            exit;
        }

        // Ambil semua items checklist
        $stmt = $pdo->prepare(
            "SELECT id, no, part, standard, method, action, `interval`
             FROM checksheet_items
             WHERE category_key = ? AND is_active = 1
             ORDER BY no"
        );
        $stmt->execute([$key]);
        $items = $stmt->fetchAll();

        // Untuk interval Weekly / Monthly: cari tanggal check_date terakhir
        // per item_id di mesin / dept / line yang sama
        if (!empty($items)) {
            // Kumpulkan id semua item Weekly / Monthly
            $periodicIds = [];
            foreach ($items as $it) {
                $iv = strtolower(trim($it['interval'] ?? ''));
                if ($iv === 'weekly' || $iv === 'monthly' || $iv === 'montly') {
                    $periodicIds[] = (int)$it['id'];
                }
            }

            $lastDateMap = []; // item_id => 'YYYY-MM-DD' | null
            if (!empty($periodicIds)) {
                // Ambil SEMUA tanggal submission per item_id (bukan hanya terbaru),
                // agar bisa menentukan "periode aktif" dengan benar di PHP.
                // Tanpa ini, submit auto-V di hari berikutnya akan menggeser last_check_date.
                $inList = implode(',', $periodicIds);

                if ($machineName !== '') {
                    $stmtLast = $pdo->prepare("
                        SELECT d.item_id, DATE(s.check_date) AS last_date
                        FROM checksheet_submission_details d
                        JOIN checksheet_submissions s ON s.id = d.submission_id
                        WHERE d.item_id IN ({$inList})
                          AND s.machine_name = ?
                          AND d.result != '-'
                        ORDER BY s.check_date ASC
                    ");
                    $stmtLast->execute([$machineName]);
                } else {
                    $stmtLast = $pdo->prepare("
                        SELECT d.item_id, DATE(s.check_date) AS last_date
                        FROM checksheet_submission_details d
                        JOIN checksheet_submissions s ON s.id = d.submission_id
                        WHERE d.item_id IN ({$inList})
                          AND s.department = ? AND s.line = ? AND s.op = ?
                          AND d.result != '-'
                        ORDER BY s.check_date ASC
                    ");
                    $stmtLast->execute([$dept, $line, $op]);
                }

                // Kumpulkan semua tanggal per item_id (sudah ASC dari query: oldest first)
                $allDatesMap = []; // item_id => ['YYYY-MM-DD', ...] oldest→newest
                foreach ($stmtLast->fetchAll() as $r) {
                    $allDatesMap[(int)$r['item_id']][] = $r['last_date'];
                }

                // Buat interval map per item_id untuk dipakai di bawah
                $itemIntervalMap = [];
                foreach ($items as $it) {
                    $itemIntervalMap[(int)$it['id']] = strtolower(trim($it['interval'] ?? ''));
                }

                $today = new DateTime('today');

                foreach ($allDatesMap as $itemId => $dates) {
                    $iv = $itemIntervalMap[$itemId] ?? '';

                    // Strategi: iterasi dari terbaru ke terlama (reverse ASC = DESC).
                    // Cari tanggal submit PERTAMA (tertua) dalam periode yang masih aktif hari ini.
                    // "Periode aktif" = [firstSubmitDate, firstSubmitDate + 7hari/1bulan).
                    // Jika user submit tanggal 9 Juni (monthly), periode aktif = 9 Juni–9 Juli.
                    // Submit auto-V tanggal 10 Juni masuk dalam periode yang sama →
                    // last_check_date tetap 9 Juni, next tetap 9 Juli.
                    $datesDesc = array_reverse($dates); // newest first
                    $activePeriodStart = null;

                    foreach ($datesDesc as $d) {
                        $dt = new DateTime($d);
                        $next = clone $dt;
                        if ($iv === 'weekly') {
                            $next->modify('+7 days');
                        } elseif ($iv === 'monthly' || $iv === 'montly') {
                            $next->modify('+1 month');
                        } else {
                            break;
                        }

                        if ($today < $next) {
                            // $d masih dalam periode aktif → ini kandidat period start
                            // Terus iterasi ke tanggal lebih lama (mungkin ada yg lebih tua
                            // tapi masih dalam periode yang sama)
                            $activePeriodStart = $dt;
                        } else {
                            // $d sudah expired. Jika kita sudah punya activePeriodStart,
                            // hentikan — $d ini bukan bagian dari periode aktif.
                            // Jika belum punya, lanjut cari yang lebih lama (sudah expired semua).
                            if ($activePeriodStart !== null) {
                                break;
                            }
                            // Semua tanggal expired → simpan yang terbaru sebagai referensi
                            $activePeriodStart = $dt;
                            break;
                        }
                    }

                    if ($activePeriodStart !== null) {
                        $lastDateMap[$itemId] = $activePeriodStart->format('Y-m-d');
                    }
                    // Jika tidak ada data sama sekali, $lastDateMap[$itemId] tidak di-set → null
                }
            }

            // Tambahkan last_check_date ke setiap item
            foreach ($items as &$it) {
                $iv = strtolower(trim($it['interval'] ?? ''));
                if ($iv === 'weekly' || $iv === 'monthly' || $iv === 'montly') {
                    $it['last_check_date'] = $lastDateMap[(int)$it['id']] ?? null;
                } else {
                    $it['last_check_date'] = null; // Daily / lainnya tidak perlu
                }
            }
            unset($it);
        }

        echo json_encode($items);
        exit;
    }

    if ($_GET['ajax'] === 'checkers') {
        $rows = $pdo->query("SELECT name FROM checkers WHERE is_active = 1 ORDER BY name")->fetchAll();
        echo json_encode(array_column($rows, 'name'));
        exit;
    }

    // ─── CEK DUPLIKASI: apakah mesin sudah diisi checksheet hari ini ───────────
    if ($_GET['ajax'] === 'check_duplicate' && isset($_GET['machine_name'], $_GET['date'])) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM checksheet_submissions
             WHERE machine_name = ? AND `line` = ? AND op = ? AND DATE(check_date) = ?"
        );
        $stmt->execute([
            $_GET['machine_name'],
            $_GET['line'] ?? '',
            $_GET['op']   ?? '-',
            $_GET['date'],
        ]);
        $count = (int)$stmt->fetchColumn();
        echo json_encode([
            'already_filled' => $count > 0,
            'count'          => $count,
        ]);
        exit;
    }

    echo json_encode(['error' => 'Unknown request']);
    exit;
}

// ─── POST: Submit checksheet ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_checksheet'])) {
    header('Content-Type: application/json');

    $dept             = trim($_POST['department']       ?? '');
    $line             = trim($_POST['line']             ?? '');
    $op               = trim($_POST['op']               ?? '-');
    $machineName      = trim($_POST['machine_name']     ?? '');
    $machineType      = trim($_POST['machine_type']     ?? '');
    $checkDate        = trim($_POST['check_date']       ?? '');
    $checker          = trim($_POST['checker']          ?? '');
    $compressorStatus = trim($_POST['compressor_status'] ?? ''); // 'on' | 'off' | ''
    $itemsJson        = $_POST['items']                 ?? '[]';

    if (!$dept || !$line || !$checker || !$checkDate || !$machineName) {
        echo json_encode(['success' => false, 'message' => 'Lengkapi semua field wajib.']);
        exit;
    }

    // ─── Validasi status kompressor jika Power House + Kompressor ────────────
    $isKompressor = (strtoupper(trim($dept)) === 'POWER HOUSE' && str_contains(strtoupper(trim($line)), 'KOMPRESSOR'));
    if ($isKompressor && !in_array($compressorStatus, ['on', 'off'])) {
        echo json_encode(['success' => false, 'message' => 'Pilih status kondisi kompressor (ON/OFF) terlebih dahulu.']);
        exit;
    }

    // ─── Validasi & proses foto ───────────────────────────────────────────────
    if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'Foto kondisi mesin wajib diambil sebelum submit.']);
        exit;
    }

    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($_FILES['photo']['tmp_name']);
    if (!in_array($mime, $allowedMimes)) {
        echo json_encode(['success' => false, 'message' => 'Format foto tidak valid (hanya JPEG/PNG/WebP).']);
        exit;
    }

    $items = json_decode($itemsJson, true);
    // Jika kompressor OFF, item checklist boleh kosong (submit langsung)
    $kompressorOff = ($isKompressor && $compressorStatus === 'off');
    if (!$kompressorOff && (!is_array($items) || empty($items))) {
        echo json_encode(['success' => false, 'message' => 'Tidak ada item checklist.']);
        exit;
    }
    if (!is_array($items)) $items = [];

    // ─── Cek duplikasi server-side ────────────────────────────────────────────
    $stmtDup = $pdo->prepare(
        "SELECT COUNT(*) FROM checksheet_submissions
         WHERE machine_name = ? AND `line` = ? AND op = ? AND DATE(check_date) = ?"
    );
    $stmtDup->execute([$machineName, $line, $op, $checkDate]);
    if ((int)$stmtDup->fetchColumn() > 0) {
        echo json_encode([
            'success'   => false,
            'message'   => "Mesin \"{$machineName}\" di line \"{$line}\" OP \"{$op}\" sudah diisi checksheet pada tanggal ini.",
            'duplicate' => true,
        ]);
        exit;
    }

    // ─── Simpan foto ke server (hanya jika semua validasi lolos) ─────────────
    $uploadDir = __DIR__ . '/uploads/checksheet/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $ext      = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : 'jpg');
    $filename = 'cs_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destPath = $uploadDir . $filename;

    if (!move_uploaded_file($_FILES['photo']['tmp_name'], $destPath)) {
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan foto ke server.']);
        exit;
    }
    $photoPath = 'uploads/checksheet/' . $filename;

    $categoryKey = resolveCategoryKey($machineType, $dept, $line) ?? '';

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "INSERT INTO checksheet_submissions
             (department, `line`, op, machine_name, machine_type, category_key, check_date, checker, photo_path, ip_address, compressor_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$dept, $line, $op, $machineName, $machineType, $categoryKey, $checkDate, $checker, $photoPath, $_SERVER['REMOTE_ADDR'] ?? null, $compressorStatus ?: null]);
        $submissionId = $pdo->lastInsertId();

        $stmtDetail = $pdo->prepare(
            "INSERT INTO checksheet_submission_details
             (submission_id, item_id, no, part, standard, result, note)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($items as $item) {
            $stmtDetail->execute([
                $submissionId,
                $item['id']       ?? null,
                $item['no']       ?? 0,
                $item['part']     ?? '',
                $item['standard'] ?? '',
                $item['result']   ?? '-',
                $item['note']     ?? null,
            ]);
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Check sheet berhasil disimpan.']);
    } catch (\Exception $e) {
        $pdo->rollBack();
        // Hapus foto karena transaksi DB gagal
        if (file_exists($destPath)) unlink($destPath);
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan: ' . $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Check Sheet — Maintenance Hub</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: #f1f5f9;
        }

        /* ── Sidebar ── */
        #sidebar {
            width: 240px;
            min-height: 100vh;
            background: linear-gradient(160deg, #0f172a 0%, #1e293b 100%);
            position: fixed;
            top: 0;
            left: 0;
            display: flex;
            flex-direction: column;
            z-index: 100;
            transition: width .25s ease;
            overflow: hidden;
        }

        #sidebar.collapsed {
            width: 56px;
        }

        #sidebar .brand {
            padding: 14px 14px 12px;
            border-bottom: 1px solid rgba(255, 255, 255, .07);
            display: flex;
            align-items: center;
            justify-content: space-between;
            min-height: 56px;
            transition: padding .25s ease;
        }

        #sidebar.collapsed .brand {
            justify-content: center;
            padding: 14px 0 12px;
        }

        #sidebar .brand-icon-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        #sidebar .brand-text {
            overflow: hidden;
            white-space: nowrap;
            transition: opacity .2s, width .2s;
            opacity: 1;
            width: 140px;
        }

        #sidebar.collapsed .brand-text {
            opacity: 0;
            width: 0;
        }

        #sidebar .menu-label {
            transition: opacity .2s;
        }

        #sidebar.collapsed .menu-label {
            opacity: 0;
        }

        #sidebar .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 19px;
            color: #94a3b8;
            font-size: .82rem;
            font-weight: 600;
            border-radius: 10px;
            margin: 2px 6px;
            transition: all .2s;
            cursor: pointer;
            text-decoration: none;
            white-space: nowrap;
            overflow: hidden;
        }

        #sidebar.collapsed .nav-item {
            justify-content: center;
            padding: 11px 0;
            gap: 0;
        }

        #sidebar .nav-item .nav-label {
            transition: opacity .2s, width .2s;
            opacity: 1;
        }

        #sidebar.collapsed .nav-item .nav-label {
            opacity: 0;
            width: 0;
            overflow: hidden;
        }

        #sidebar .nav-item:hover {
            background: rgba(255, 255, 255, .07);
            color: #e2e8f0;
        }

        #sidebar .nav-item.active {
            background: linear-gradient(135deg, #f43f5e, #e11d48);
            color: #fff;
            box-shadow: 0 4px 12px rgba(244, 63, 94, .35);
        }

        #sidebar .nav-item i {
            width: 18px;
            text-align: center;
            font-size: .9rem;
        }

        /* ── Main area ── */
        #main-content {
            margin-left: 56px;
            min-height: 100vh;
            transition: margin-left .25s ease;
        }

        #main-content.expanded {
            margin-left: 240px;
        }

        #sidebar .version-label {
            transition: opacity .2s;
            white-space: nowrap;
        }

        #sidebar.collapsed .version-label {
            opacity: 0;
        }

        /* ── Sidebar footer toggle ── */
        #sidebar-footer {
            border-top: 1px solid rgba(255, 255, 255, .07);
            padding: .5rem;
            display: flex;
            justify-content: flex-end;
            flex-shrink: 0;
        }

        #sidebar.collapsed #sidebar-footer {
            justify-content: center;
        }

        #sidebarToggle {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: .65rem;
            background: rgba(255, 255, 255, .08);
            border: none;
            cursor: pointer;
            color: #94a3b8;
            font-size: .8rem;
            transition: background .15s, color .15s;
            flex-shrink: 0;
        }

        #sidebarToggle:hover {
            background: rgba(255, 255, 255, .15);
            color: #e2e8f0;
        }

        /* ── Back link in sidebar ── */
        #sidebar .sidebar-back {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 9px 19px;
            color: #94a3b8;
            font-size: .82rem;
            font-weight: 600;
            border-radius: 10px;
            margin: 2px 6px;
            transition: all .2s;
            cursor: pointer;
            text-decoration: none;
            white-space: nowrap;
            overflow: hidden;
        }

        #sidebar .sidebar-back:hover {
            background: rgba(255, 255, 255, .07);
            color: #e2e8f0;
        }

        #sidebar.collapsed .sidebar-back {
            justify-content: center;
            padding: 9px 0;
            gap: 0;
        }

        #sidebar .sidebar-back .sb-label {
            transition: opacity .2s, width .2s;
            opacity: 1;
        }

        #sidebar.collapsed .sidebar-back .sb-label {
            opacity: 0;
            width: 0;
            overflow: hidden;
        }

        #sidebar .sidebar-back i {
            width: 18px;
            text-align: center;
            font-size: .9rem;
            flex-shrink: 0;
        }

        /* ── Topbar ── */
        .topbar {
            background: #fff;
            border-bottom: 1px solid #e2e8f0;
            padding: 0 28px;
            height: 58px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 50;
        }

        /* ── Form fields ── */
        .form-label {
            font-size: .72rem;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: .05em;
        }

        .form-field {
            width: 100%;
            padding: 8px 12px;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            font-size: .82rem;
            color: #1e293b;
            background: #fff;
            outline: none;
            transition: border-color .2s, box-shadow .2s;
            font-family: inherit;
        }

        .form-field:focus {
            border-color: #f43f5e;
            box-shadow: 0 0 0 3px rgba(244, 63, 94, .12);
        }

        .form-field:disabled {
            background: #f8fafc;
            color: #94a3b8;
            cursor: not-allowed;
        }

        .form-field[readonly] {
            background: #f8fafc;
            color: #64748b;
        }

        select.form-field {
            appearance: none;
            -webkit-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
            padding-right: 30px;
        }

        /* ── Check table ── */
        .table-scroll-wrap {
            overflow-x: auto;
            overflow-y: auto;
            flex: 1;
        }

        .check-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .check-table thead th {
            background: #1e293b;
            color: #f1f5f9;
            font-size: .7rem;
            font-weight: 700;
            letter-spacing: .05em;
            text-transform: uppercase;
            padding: 10px 12px;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .check-table thead th:first-child {
            border-radius: 10px 0 0 0;
        }

        .check-table thead th:last-child {
            border-radius: 0 10px 0 0;
        }

        .check-table tbody tr {
            transition: background .15s;
        }

        .check-table tbody tr:nth-child(even) {
            background: #f8fafc;
        }

        .check-table tbody tr:hover {
            background: #eff6ff;
        }

        .check-table tbody td {
            padding: 8px 12px;
            font-size: .77rem;
            color: #334155;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }

        /* ── Result button group ── */
        .result-btn-group {
            display: flex;
            gap: 3px;
            flex-wrap: nowrap;
        }

        .result-btn {
            border: 1.5px solid #e2e8f0;
            border-radius: 7px;
            padding: 4px 7px;
            font-size: .68rem;
            font-weight: 800;
            background: #f8fafc;
            color: #94a3b8;
            cursor: pointer;
            transition: all .15s;
            white-space: nowrap;
            line-height: 1.3;
            letter-spacing: .02em;
        }

        .result-btn:hover {
            border-color: #cbd5e1;
            background: #f1f5f9;
            color: #475569;
        }

        .result-btn.active-v {
            background: #dcfce7;
            color: #15803d;
            border-color: #86efac;
        }

        .result-btn.active-x {
            background: #fee2e2;
            color: #dc2626;
            border-color: #fca5a5;
        }

        .result-btn.active-r {
            background: #fef9c3;
            color: #ca8a04;
            border-color: #fde047;
        }

        .result-btn.active-ro {
            background: #ede9fe;
            color: #7c3aed;
            border-color: #c4b5fd;
        }

        /* Keep old classes for weekly/monthly auto-rows (not rendered as buttons) */
        .result-select {
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            padding: 4px 8px;
            font-size: .72rem;
            font-weight: 700;
            background: white;
            cursor: pointer;
            min-width: 82px;
            transition: all .2s;
            display: none;
            /* hidden – replaced by buttons */
        }

        .note-input {
            margin-top: 4px;
            width: 100%;
            padding: 4px 8px;
            border: 1.5px solid #fca5a5;
            border-radius: 8px;
            font-size: .72rem;
            color: #1e293b;
            background: #fff7f7;
            outline: none;
            font-family: inherit;
            transition: border-color .2s, box-shadow .2s;
        }

        .note-input:focus {
            border-color: #f43f5e;
            box-shadow: 0 0 0 3px rgba(244, 63, 94, .12);
        }

        .note-input.missing {
            border-color: #ef4444;
            background: #fef2f2;
            animation: shake .3s ease;
        }

        @keyframes shake {

            0%,
            100% {
                transform: translateX(0)
            }

            25% {
                transform: translateX(-4px)
            }

            75% {
                transform: translateX(4px)
            }
        }

        /* ── Badges ── */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 2px 8px;
            border-radius: 99px;
            font-size: .64rem;
            font-weight: 700;
            letter-spacing: .04em;
        }

        .badge-daily {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .badge-weekly {
            background: #dcfce7;
            color: #15803d;
        }

        .badge-monthly {
            background: #fef3c7;
            color: #b45309;
        }

        /* ── Empty / Loading ── */
        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 60px 20px;
            color: #94a3b8;
        }

        .empty-state i {
            font-size: 2.6rem;
            margin-bottom: 12px;
            opacity: .35;
        }

        .skeleton {
            background: linear-gradient(90deg, #f0f4f8 25%, #e2e8f0 50%, #f0f4f8 75%);
            background-size: 200% 100%;
            animation: shimmer 1.5s infinite;
            border-radius: 6px;
        }

        @keyframes shimmer {
            0% {
                background-position: 200% 0
            }

            100% {
                background-position: -200% 0
            }
        }

        /* ── Toast ── */
        #toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 9999;
            padding: 12px 20px;
            border-radius: 12px;
            font-size: .82rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transform: translateY(80px);
            opacity: 0;
            transition: all .3s cubic-bezier(.34, 1.56, .64, 1);
            box-shadow: 0 8px 24px rgba(0, 0, 0, .12);
        }

        #toast.show {
            transform: translateY(0);
            opacity: 1;
        }

        #toast.success {
            background: #dcfce7;
            color: #15803d;
            border: 1.5px solid #86efac;
        }

        #toast.error {
            background: #fee2e2;
            color: #dc2626;
            border: 1.5px solid #fca5a5;
        }

        /* ── Fade-in ── */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(10px)
            }

            to {
                opacity: 1;
                transform: translateY(0)
            }
        }

        .fade-in {
            animation: fadeInUp .2s ease forwards;
        }

        /* ── Left panel card ── */
        .left-panel {
            background: #fff;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 12px rgba(0, 0, 0, .05);
            padding: 20px;
            height: calc(100vh - 82px);
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }

        .left-panel::-webkit-scrollbar {
            width: 4px;
        }

        .left-panel::-webkit-scrollbar-track {
            background: transparent;
        }

        .left-panel::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 99px;
        }

        /* ── Right panel ── */
        .right-panel {
            background: #fff;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 12px rgba(0, 0, 0, .05);
            overflow: hidden;
            height: calc(100vh - 82px);
            display: flex;
            flex-direction: column;
        }

        /* ── Section heading ── */
        .section-heading {
            font-size: .68rem;
            font-weight: 800;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: .08em;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .section-heading::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #f1f5f9;
        }

        /* ── Info chip ── */
        .info-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 6px 10px;
            font-size: .75rem;
            color: #475569;
            font-weight: 600;
        }

        .info-chip i {
            color: #94a3b8;
            font-size: .7rem;
        }

        /* ── Foto Strip (inline di bawah tabel) ── */
        #foto-section {
            border-top: 1px solid #e2e8f0;
            padding: 8px 16px;
            background: #f8fafc;
            flex-shrink: 0;
            display: none;
        }

        #foto-strip {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        #foto-strip .foto-label {
            font-size: .68rem;
            font-weight: 800;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: .06em;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        #foto-strip .foto-label i {
            color: #f43f5e;
        }

        #btn-open-camera {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 8px;
            background: #1e293b;
            color: #fff;
            font-size: .72rem;
            font-weight: 700;
            border: none;
            cursor: pointer;
            transition: background .15s;
            white-space: nowrap;
            flex-shrink: 0;
        }

        #btn-open-camera:hover {
            background: #0f172a;
        }

        /* Thumbnail kecil hasil foto */
        #foto-thumb-wrap {
            display: none;
            align-items: center;
            gap: 6px;
            flex-shrink: 0;
        }

        #foto-thumb {
            width: 40px;
            height: 30px;
            object-fit: cover;
            border-radius: 5px;
            border: 1.5px solid #86efac;
            cursor: pointer;
            transition: opacity .15s;
        }

        #foto-thumb:hover {
            opacity: .8;
        }

        #btn-foto-retake {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 9px;
            border-radius: 7px;
            background: transparent;
            border: 1.5px solid #e2e8f0;
            color: #64748b;
            font-size: .68rem;
            font-weight: 700;
            cursor: pointer;
            transition: all .15s;
            white-space: nowrap;
        }

        #btn-foto-retake:hover {
            border-color: #f43f5e;
            color: #f43f5e;
        }

        .foto-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: .67rem;
            font-weight: 700;
            padding: 3px 9px;
            border-radius: 99px;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .foto-status-badge.ok {
            background: #dcfce7;
            color: #15803d;
        }

        .foto-status-badge.required {
            background: #fee2e2;
            color: #dc2626;
        }

        /* ── Camera Modal ── */
        #camera-modal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 500;
            background: rgba(15, 23, 42, .7);
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(3px);
        }

        #camera-modal.open {
            display: flex;
        }

        #camera-modal-box {
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 24px 60px rgba(0, 0, 0, .25);
            width: 480px;
            max-width: calc(100vw - 32px);
            overflow: hidden;
            animation: modalIn .2s ease;
        }

        @keyframes modalIn {
            from {
                opacity: 0;
                transform: scale(.95) translateY(10px);
            }

            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        #camera-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 18px;
            border-bottom: 1px solid #e2e8f0;
        }

        #camera-modal-header .modal-title {
            font-size: .82rem;
            font-weight: 800;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 7px;
        }

        #camera-modal-header .modal-title i {
            color: #f43f5e;
        }

        #btn-modal-close {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            background: #f1f5f9;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #64748b;
            font-size: .75rem;
            transition: background .15s;
        }

        #btn-modal-close:hover {
            background: #fee2e2;
            color: #dc2626;
        }

        #camera-modal-body {
            padding: 14px 18px 16px;
        }

        /* Video live feed */
        #camera-preview-wrap {
            position: relative;
            width: 100%;
            background: #0f172a;
            border-radius: 12px;
            overflow: hidden;
            aspect-ratio: 4/3;
            display: none;
        }

        #camera-video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        #camera-overlay-hint {
            position: absolute;
            bottom: 10px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0, 0, 0, .55);
            color: #fff;
            font-size: .65rem;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 99px;
            white-space: nowrap;
            pointer-events: none;
        }

        /* Foto hasil di modal */
        #photo-result-wrap {
            position: relative;
            display: none;
        }

        #photo-preview {
            width: 100%;
            border-radius: 12px;
            border: 2px solid #86efac;
            object-fit: cover;
            aspect-ratio: 4/3;
            display: block;
        }

        #photo-delete-btn {
            position: absolute;
            top: 8px;
            right: 8px;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: rgba(239, 68, 68, .9);
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: .7rem;
            transition: background .15s;
        }

        #photo-delete-btn:hover {
            background: rgba(220, 38, 38, 1);
        }

        /* Placeholder di modal sebelum kamera dibuka */
        .foto-empty-box {
            width: 100%;
            aspect-ratio: 4/3;
            border: 2px dashed #e2e8f0;
            border-radius: 12px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #cbd5e1;
            background: #f8fafc;
            gap: 8px;
        }

        .foto-empty-box i {
            font-size: 2rem;
        }

        .foto-empty-box p {
            font-size: .72rem;
            font-weight: 600;
            color: #94a3b8;
        }

        /* Modal action buttons */
        #camera-modal-actions {
            display: flex;
            gap: 8px;
            margin-top: 12px;
        }

        #btn-capture {
            flex: 1;
            padding: 8px 0;
            border-radius: 10px;
            background: #f43f5e;
            color: #fff;
            font-size: .78rem;
            font-weight: 700;
            border: none;
            cursor: pointer;
            display: none;
            align-items: center;
            justify-content: center;
            gap: 7px;
            transition: background .15s;
        }

        #btn-capture:hover {
            background: #e11d48;
        }

        #btn-cancel-camera {
            flex: 1;
            padding: 8px 0;
            border-radius: 10px;
            background: transparent;
            color: #94a3b8;
            font-size: .72rem;
            font-weight: 600;
            border: 1.5px solid #e2e8f0;
            cursor: pointer;
            display: none;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: all .15s;
        }

        #btn-cancel-camera:hover {
            border-color: #f43f5e;
            color: #f43f5e;
        }

        #btn-use-photo {
            flex: 1;
            padding: 8px 0;
            border-radius: 10px;
            background: #15803d;
            color: #fff;
            font-size: .78rem;
            font-weight: 700;
            border: none;
            cursor: pointer;
            display: none;
            align-items: center;
            justify-content: center;
            gap: 7px;
            transition: background .15s;
        }

        #btn-use-photo:hover {
            background: #166534;
        }
    </style>
</head>

<body>

    <aside id="sidebar" class="collapsed">
        <div class="brand">
            <div class="brand-icon-wrap">
                <div class="w-8 h-8 rounded-lg bg-rose-500 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-tools text-white text-xs"></i>
                </div>
                <div class="brand-text">
                    <div class="text-white text-xs font-bold leading-tight">Maintenance Hub</div>
                    <div class="text-slate-500 text-[10px] font-medium">Check Sheet System</div>
                </div>
            </div>
        </div>

        <nav class="mt-4 flex-1">
            <a href="index.php" class="sidebar-back" title="Kembali ke Menu Utama">
                <i class="fas fa-arrow-left flex-shrink-0"></i>
                <span class="sb-label">Back to Hub</span>
            </a>
            <div style="height:1px;background:rgba(255,255,255,.07);margin:.4rem 6px;"></div>
            <div class="px-3 mb-2 menu-label">
                <span class="text-[10px] font-bold text-slate-600 uppercase tracking-widest">Menu</span>
            </div>
            <a href="dashboard_checksheet.php" onclick="navigateTo(event,'dashboard_checksheet.php')" class="nav-item active" title="Check Sheet">
                <i class="fas fa-clipboard-check"></i>
                <span class="nav-label">Check Sheet</span>
            </a>
            <a href="history_checksheet.php" onclick="navigateTo(event,'history_checksheet.php')" class="nav-item" title="History">
                <i class="fas fa-history"></i>
                <span class="nav-label">History</span>
            </a>
        </nav>

        <div id="sidebar-footer">
            <button id="sidebarToggle" onclick="toggleSidebar()" title="Toggle Sidebar">
                <i class="fas fa-chevron-left" id="sidebarToggleIcon"></i>
            </button>
        </div>
    </aside>

    <div id="main-content">

        <div class="topbar">
            <div class="flex items-center gap-2">
                <div class="w-7 h-7 rounded-lg bg-rose-50 flex items-center justify-center">
                    <i class="fas fa-clipboard-check text-rose-500 text-xs"></i>
                </div>
                <div>
                    <div class="text-sm font-bold text-slate-800">Daily Check Sheet</div>
                    <div class="text-[10px] text-slate-400 font-medium">Input & submit hasil pengecekan harian</div>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <span class="info-chip"><i class="far fa-calendar text-rose-400"></i> <span id="today-label"></span></span>
            </div>
        </div>

        <div class="p-4" style="height: calc(100vh - 58px); overflow: hidden;">
            <div class="flex gap-4 h-full">

                <div class="left-panel w-68 flex-shrink-0" style="width: 272px;">
                    <div class="section-heading"><i class="fas fa-sliders-h text-rose-400"></i> Form Input</div>

                    <div class="space-y-3">
                        <div>
                            <label class="form-label block mb-1.5">
                                <i class="fas fa-building text-slate-300 mr-1"></i> Department <span class="text-red-400">*</span>
                            </label>
                            <select id="sel-dept" class="form-field" onchange="loadLines()">
                                <option value="">— Pilih Department —</option>
                            </select>
                        </div>

                        <div>
                            <label class="form-label block mb-1.5">
                                <i class="fas fa-calendar-day text-slate-300 mr-1"></i> Tanggal
                                <span class="text-slate-300 font-normal normal-case text-[10px]">(otomatis)</span>
                            </label>
                            <input type="hidden" id="inp-tanggal">
                            <input type="text" id="inp-tanggal-display" class="form-field" readonly
                                style="cursor:default;" tabindex="-1">
                        </div>

                        <div>
                            <label class="form-label block mb-1.5">
                                <i class="fas fa-user-check text-slate-300 mr-1"></i> Checker <span class="text-red-400">*</span>
                            </label>
                            <select id="sel-checker" class="form-field">
                                <option value="">— Pilih Checker —</option>
                            </select>
                        </div>

                        <div class="border-t border-slate-100 pt-1"></div>

                        <div>
                            <label class="form-label block mb-1.5">
                                <i class="fas fa-layer-group text-slate-300 mr-1"></i> Line <span class="text-red-400">*</span>
                            </label>
                            <select id="sel-line" class="form-field" onchange="loadOps()" disabled>
                                <option value="">— Pilih Line —</option>
                            </select>
                        </div>

                        <div>
                            <label class="form-label block mb-1.5">
                                <i class="fas fa-cog text-slate-300 mr-1"></i> OP
                            </label>
                            <select id="sel-op" class="form-field" onchange="loadMachine()" disabled>
                                <option value="">— Pilih OP —</option>
                            </select>
                        </div>

                        <div>
                            <label class="form-label block mb-1.5">
                                <i class="fas fa-tag text-slate-300 mr-1"></i> Machine Type
                                <span class="text-slate-300 font-normal normal-case text-[10px]">(otomatis)</span>
                            </label>
                            <input type="text" id="inp-type" class="form-field" placeholder="Terisi otomatis" readonly>
                        </div>

                        <div>
                            <label class="form-label block mb-1.5">
                                <i class="fas fa-industry text-slate-300 mr-1"></i> Nama Mesin
                            </label>
                            <div id="machine-field-container">
                                <input type="text" id="inp-mesin" class="form-field" placeholder="— Otomatis terisi —" readonly>
                            </div>
                        </div>

                        <!-- Kompressor ON/OFF: muncul setelah mesin terisi, hanya untuk Power House + Kompressor -->
                        <div id="compressor-status-wrap" style="display:none;">
                            <label class="form-label block mb-1.5">
                                <i class="fas fa-power-off text-slate-300 mr-1"></i> Kondisi Compressor <span class="text-red-400">*</span>
                            </label>
                            <div style="display:flex;gap:8px;">
                                <button type="button" id="btn-comp-on"
                                    onclick="setCompressorStatus('on')"
                                    style="flex:1;padding:8px 0;border-radius:10px;border:2px solid #e2e8f0;background:#f8fafc;color:#64748b;font-size:.78rem;font-weight:800;cursor:pointer;transition:all .15s;display:flex;align-items:center;justify-content:center;gap:6px;">
                                    <i class="fas fa-circle" style="color:#22c55e;font-size:.6rem;"></i> ON
                                </button>
                                <button type="button" id="btn-comp-off"
                                    onclick="setCompressorStatus('off')"
                                    style="flex:1;padding:8px 0;border-radius:10px;border:2px solid #e2e8f0;background:#f8fafc;color:#64748b;font-size:.78rem;font-weight:800;cursor:pointer;transition:all .15s;display:flex;align-items:center;justify-content:center;gap:6px;">
                                    <i class="fas fa-circle" style="color:#ef4444;font-size:.6rem;"></i> OFF
                                </button>
                            </div>
                            <input type="hidden" id="inp-compressor-status" value="">
                        </div>
                    </div>

                </div>

                <div class="flex-1 min-w-0">
                    <div class="right-panel">

                        <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between flex-shrink-0">
                            <div class="flex items-center gap-2.5">
                                <div class="w-7 h-7 rounded-lg bg-slate-800 flex items-center justify-center">
                                    <i class="fas fa-list-check text-white text-xs"></i>
                                </div>
                                <div>
                                    <div class="text-sm font-bold text-slate-800">Checklist Items</div>
                                    <div id="row-count" class="text-[11px] text-slate-400 font-medium"></div>
                                </div>
                            </div>
                            <div id="category-badge" class="hidden">
                                <span class="px-3 py-1 rounded-full text-[11px] font-bold bg-rose-50 text-rose-600 border border-rose-100"></span>
                            </div>
                        </div>

                        <div class="px-5 py-2.5 border-b border-slate-100 bg-slate-50/60 flex-shrink-0">
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5">
                                <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Keterangan:</span>
                                <div class="flex flex-wrap gap-1.5">
                                    <span class="badge badge-daily">V = OK</span>
                                    <span class="badge" style="background:#fee2e2;color:#dc2626;">X = Problem</span>
                                    <span class="badge badge-monthly">R = Repair</span>
                                    <span class="badge" style="background:#ede9fe;color:#7c3aed;">RO = Outsider</span>
                                    <span class="badge" style="background:#f1f5f9;color:#94a3b8;">– = N/A</span>
                                </div>
                                <div class="flex flex-wrap gap-1.5 ml-2">
                                    <span class="badge badge-daily">Daily</span>
                                    <span class="badge badge-weekly">Weekly</span>
                                    <span class="badge badge-monthly">Monthly</span>
                                </div>
                            </div>
                        </div>

                        <div class="table-scroll-wrap">
                            <table class="check-table" id="check-table" style="display:none;">
                                <thead>
                                    <tr>
                                        <th class="text-center w-10">No</th>
                                        <th>Part to be Checked</th>
                                        <th>Standard</th>
                                        <th>Checking Method</th>
                                        <th>Action</th>
                                        <th class="text-center">Interval</th>
                                        <th class="text-center w-28">Result</th>
                                    </tr>
                                </thead>
                                <tbody id="check-tbody"></tbody>
                            </table>

                            <div id="empty-state" class="empty-state">
                                <i class="fas fa-clipboard-list"></i>
                                <p class="font-bold text-sm">Pilih Department, Checker, Line, dan OP</p>
                                <p class="text-xs mt-1">Checklist akan muncul otomatis setelah memilih mesin</p>
                            </div>

                            <!-- Banner: kompressor OFF -->
                            <div id="kompressor-off-banner" style="display:none;" class="empty-state">
                                <div style="background:#fef3c7;border:2px solid #fcd34d;border-radius:16px;padding:32px 40px;text-align:center;max-width:420px;">
                                    <i class="fas fa-power-off" style="font-size:2.4rem;color:#f59e0b;margin-bottom:12px;display:block;"></i>
                                    <p class="font-bold text-sm" style="color:#92400e;">Kompressor dalam kondisi OFF</p>
                                    <p class="text-xs mt-2" style="color:#78350f;">Checklist tidak diperlukan. Ambil foto kondisi mesin lalu submit.</p>
                                </div>
                            </div>

                            <!-- Banner: mesin sudah diisi hari ini -->
                            <div id="duplicate-warning" style="display:none;" class="empty-state">
                                <div style="background:#fff7ed;border:2px solid #fb923c;border-radius:16px;padding:32px 40px;text-align:center;max-width:420px;">
                                    <i class="fas fa-ban" style="font-size:2.4rem;color:#f97316;margin-bottom:12px;display:block;"></i>
                                    <p class="font-bold text-sm" style="color:#c2410c;" id="dup-warning-title">Checksheet Sudah Diisi</p>
                                    <p class="text-xs mt-2" style="color:#7c3aed;" id="dup-warning-desc"></p>
                                </div>
                            </div>

                            <div id="loading-state" style="display:none;" class="p-5">
                                <div class="space-y-3">
                                    <?php for ($i = 0; $i < 8; $i++): ?>
                                        <div class="flex gap-3 items-center">
                                            <div class="skeleton w-6 h-5"></div>
                                            <div class="skeleton flex-1 h-5"></div>
                                            <div class="skeleton w-24 h-5"></div>
                                            <div class="skeleton w-20 h-5"></div>
                                            <div class="skeleton w-20 h-5"></div>
                                            <div class="skeleton w-16 h-5"></div>
                                            <div class="skeleton w-20 h-5"></div>
                                        </div>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        </div>

                        <!-- ─── FOTO STRIP (inline kompak di bawah tabel) ─── -->
                        <div id="foto-section">
                            <div id="foto-strip">
                                <span class="foto-label">
                                    <i class="fas fa-camera"></i> Take Machine Photo <span style="color:#f43f5e;margin-left:1px;">*</span>
                                </span>
                                <button id="btn-open-camera" type="button" onclick="openCameraModal()">
                                    <i class="fas fa-camera"></i> Open Camera
                                </button>
                                <!-- Thumbnail + retake (muncul setelah ada foto) -->
                                <div id="foto-thumb-wrap">
                                    <img id="foto-thumb" src="" alt="thumb" onclick="openPhotoPreviewModal()" title="Klik untuk lihat foto">
                                    <button id="btn-foto-retake" type="button" onclick="openCameraModal()">
                                        <i class="fas fa-redo"></i> Retake
                                    </button>
                                </div>
                                <span id="foto-status-badge" class="foto-status-badge required" style="margin-left:auto;">
                                    <i class="fas fa-exclamation-circle"></i> Photo required
                                </span>
                            </div>
                        </div>

                        <div class="border-t border-slate-100 px-5 py-3 flex items-center justify-between gap-3 bg-slate-50/60 rounded-b-2xl flex-shrink-0">
                            <div class="text-xs text-slate-400 font-medium">
                                <i class="fas fa-circle-info mr-1"></i>
                                Pastikan semua item dicek &amp; foto mesin sudah diambil
                            </div>
                            <div class="flex items-center gap-2.5">
                                <button onclick="resetForm()"
                                    class="px-5 py-2 rounded-xl bg-white border border-slate-200 hover:bg-slate-50 text-slate-600 text-sm font-bold transition-all flex items-center gap-2 shadow-sm">
                                    <i class="fas fa-rotate-left text-xs"></i> Reset
                                </button>
                                <button id="btn-submit" onclick="submitForm()" disabled
                                    class="px-6 py-2 rounded-xl bg-slate-200 text-slate-400 text-sm font-bold cursor-not-allowed transition-all flex items-center gap-2">
                                    <i class="fas fa-paper-plane text-xs"></i> Submit
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- ─── CAMERA MODAL ─────────────────────────────────────────────── -->
    <div id="camera-modal">
        <div id="camera-modal-box">
            <div id="camera-modal-header">
                <div class="modal-title">
                    <i class="fas fa-camera"></i> Take Machine Photo
                </div>
                <button id="btn-modal-close" type="button" onclick="closeCameraModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div id="camera-modal-body">
                <!-- Placeholder sebelum kamera dibuka -->
                <div id="foto-empty-box" class="foto-empty-box">
                    <i class="fas fa-image"></i>
                    <p>Belum ada foto</p>
                </div>
                <!-- Live kamera -->
                <div id="camera-preview-wrap">
                    <video id="camera-video" playsinline autoplay muted></video>
                    <div id="camera-overlay-hint">Arahkan ke mesin lalu tekan Ambil Foto</div>
                </div>
                <!-- Hasil foto di modal -->
                <div id="photo-result-wrap">
                    <img id="photo-preview" src="" alt="Foto Mesin">
                    <button id="photo-delete-btn" type="button" onclick="deleteCapturedPhoto()" title="Hapus foto">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <!-- Canvas tersembunyi -->
                <canvas id="photo-canvas" style="display:none;"></canvas>
                <!-- Action buttons -->
                <div id="camera-modal-actions">
                    <button id="btn-capture" type="button" onclick="capturePhoto()">
                        <i class="fas fa-circle"></i> Ambil Foto
                    </button>
                    <button id="btn-cancel-camera" type="button" onclick="cancelCamera()">
                        <i class="fas fa-times"></i> Batalkan
                    </button>
                    <button id="btn-use-photo" type="button" onclick="closeCameraModal()">
                        <i class="fas fa-check"></i> Gunakan Foto
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ─── PHOTO PREVIEW MODAL (klik thumbnail) ──────────────────────── -->
    <div id="photo-preview-modal" style="display:none; position:fixed; inset:0; z-index:600; background:rgba(15,23,42,.85); align-items:center; justify-content:center; backdrop-filter:blur(4px);">
        <div style="position:relative; max-width:560px; width:calc(100vw - 32px);">
            <img id="photo-preview-large" src="" alt="Preview" style="width:100%; border-radius:14px; box-shadow:0 24px 60px rgba(0,0,0,.4);">
            <button onclick="closePhotoPreviewModal()" style="position:absolute; top:-12px; right:-12px; width:32px; height:32px; border-radius:50%; background:#fff; border:none; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:.8rem; color:#1e293b; box-shadow:0 4px 12px rgba(0,0,0,.2);">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </div>

    <div id="toast"></div>

    <script>
        const BASE = window.location.pathname;
        let currentItems = [];
        let isMachineDropdownActive = false; // Flag kontrol internal
        let capturedPhotoBlob = null; // Blob foto hasil capture dari kamera
        let cameraStream = null; // MediaStream aktif

        // ── Sidebar ───────────────────────────────────────────────────────────────
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const main = document.getElementById('main-content');
            const icon = document.getElementById('sidebarToggleIcon');
            const isCollapsed = sidebar.classList.toggle('collapsed');
            main.classList.toggle('expanded', !isCollapsed);
            icon.className = isCollapsed ? 'fas fa-chevron-right' : 'fas fa-chevron-left';
            sessionStorage.setItem('checksheet_sidebar', isCollapsed ? 'collapsed' : 'expanded');
        }

        function navigateTo(e, url) {
            e.preventDefault();
            sessionStorage.setItem('checksheet_sidebar',
                document.getElementById('sidebar').classList.contains('collapsed') ? 'collapsed' : 'expanded');
            window.location.href = url;
        }

        // ── Init ──────────────────────────────────────────────────────────────────
        document.addEventListener('DOMContentLoaded', () => {
            const sidebar = document.getElementById('sidebar');
            const main = document.getElementById('main-content');
            const icon = document.getElementById('sidebarToggleIcon');
            const state = sessionStorage.getItem('checksheet_sidebar');
            if (state === 'expanded') {
                sidebar.classList.remove('collapsed');
                main.classList.add('expanded');
                icon.className = 'fas fa-chevron-left';
            } else {
                sidebar.classList.add('collapsed');
                main.classList.remove('expanded');
                icon.className = 'fas fa-chevron-right';
            }

            const today = new Date();
            const todayISO = today.toISOString().split('T')[0];
            document.getElementById('inp-tanggal').value = todayISO;
            const todayFormatted = today.toLocaleDateString('id-ID', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric'
            });
            document.getElementById('inp-tanggal-display').value = todayFormatted;
            document.getElementById('today-label').textContent = today.toLocaleDateString('id-ID', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });

            fetch(`${BASE}?ajax=departments`)
                .then(r => r.json())
                .then(data => {
                    const sel = document.getElementById('sel-dept');
                    data.forEach(d => sel.insertAdjacentHTML('beforeend', `<option value="${esc(d)}">${esc(d)}</option>`));
                })
                .catch(() => showToast('Gagal memuat data department.', 'error'));

            fetch(`${BASE}?ajax=checkers`)
                .then(r => r.json())
                .then(data => {
                    const sel = document.getElementById('sel-checker');
                    data.forEach(c => sel.insertAdjacentHTML('beforeend', `<option value="${esc(c)}">${esc(c)}</option>`));
                });
        });

        // ── Cascade ───────────────────────────────────────────────────────────────
        function loadLines() {
            const dept = document.getElementById('sel-dept').value;
            const selLine = document.getElementById('sel-line');
            const selOp = document.getElementById('sel-op');
            selLine.innerHTML = '<option value="">— Pilih Line —</option>';
            selLine.disabled = true;
            selOp.innerHTML = '<option value="">— Pilih OP —</option>';
            selOp.disabled = true;
            clearMachine();
            clearTable();
            if (!dept) return;
            fetch(`${BASE}?ajax=lines&department=${encodeURIComponent(dept)}`)
                .then(r => r.json())
                .then(data => {
                    data.forEach(l => selLine.insertAdjacentHTML('beforeend', `<option value="${esc(l)}">${esc(l)}</option>`));
                    selLine.disabled = false;
                });
        }

        function loadOps() {
            const dept = document.getElementById('sel-dept').value;
            const line = document.getElementById('sel-line').value;
            const selOp = document.getElementById('sel-op');
            selOp.innerHTML = '<option value="">— Pilih OP —</option>';
            selOp.disabled = true;
            clearMachine();
            clearTable();
            if (!dept || !line) return;
            fetch(`${BASE}?ajax=ops&department=${encodeURIComponent(dept)}&line=${encodeURIComponent(line)}`)
                .then(r => r.json())
                .then(data => {
                    data.forEach(op => selOp.insertAdjacentHTML('beforeend', `<option value="${esc(op)}">${esc(op)}</option>`));
                    selOp.disabled = false;
                    if (data.length === 1) {
                        selOp.value = data[0];
                        loadMachine();
                    }
                });
        }

        function loadMachine() {
            const dept = document.getElementById('sel-dept').value;
            const line = document.getElementById('sel-line').value;
            const op = document.getElementById('sel-op').value;
            clearMachine();
            clearTable();
            if (!dept || !line || !op) return;

            fetch(`${BASE}?ajax=machine_list&department=${encodeURIComponent(dept)}&line=${encodeURIComponent(line)}&op=${encodeURIComponent(op)}`)
                .then(r => r.json())
                .then(machines => {
                    const container = document.getElementById('machine-field-container');

                    if (op === '-' && machines.length > 1) {
                        isMachineDropdownActive = true;
                        let selectHtml = `<select id="sel-mesin" class="form-field" onchange="onMachineSelectChange()">
                                            <option value="">— Pilih Mesin —</option>`;
                        machines.forEach(m => {
                            selectHtml += `<option value="${esc(m.machine_name)}" data-type="${esc(m.machine_type)}">${esc(m.machine_name)}</option>`;
                        });
                        selectHtml += `</select>`;
                        container.innerHTML = selectHtml;
                        document.getElementById('inp-type').value = '';
                        // Untuk kompressor, tampilkan ON/OFF wrap setelah dropdown mesin muncul
                        // (checklist baru muncul setelah user pilih mesin DAN pilih ON/OFF)
                        checkCompressorStatus();
                    } else {
                        isMachineDropdownActive = false;
                        container.innerHTML = `<input type="text" id="inp-mesin" class="form-field" placeholder="— Otomatis terisi —" readonly>`;

                        if (machines.length > 0) {
                            document.getElementById('inp-mesin').value = machines[0].machine_name || '';
                            document.getElementById('inp-type').value = machines[0].machine_type || '';

                            // Jika kompressor: tampilkan ON/OFF, jangan load checklist dulu
                            if (isKompressorLine()) {
                                checkCompressorStatus();
                            } else {
                                executeFetchChecklist(machines[0].machine_type, dept, line);
                            }
                        }
                    }
                });
        }

        // Dipanggil saat user memilih mesin dari select dropdown (khusus kondisi OP = '-')
        function onMachineSelectChange() {
            const selMachine = document.getElementById('sel-mesin');
            const typeInput = document.getElementById('inp-type');
            const dept = document.getElementById('sel-dept').value;
            const line = document.getElementById('sel-line').value;
            clearTable();
            resetCompressorStatus();

            if (!selMachine || !selMachine.value) {
                typeInput.value = '';
                checkCompressorStatus(); // sembunyikan jika belum ada mesin
                return;
            }

            const activeOption = selMachine.options[selMachine.selectedIndex];
            const machineType = activeOption.getAttribute('data-type') || '';
            typeInput.value = machineType;

            // Jika kompressor: tampilkan ON/OFF dulu, jangan load checklist
            if (isKompressorLine()) {
                checkCompressorStatus();
            } else {
                executeFetchChecklist(machineType, dept, line);
            }
        }

        // Fungsi split untuk murni menarik data checklist item dari database
        function executeFetchChecklist(machineType, dept, line) {
            showLoading(true);
            const op = document.getElementById('sel-op')?.value || '';
            // Ambil machine_name tergantung mode (dropdown atau text input)
            let machineName = '';
            if (isMachineDropdownActive) {
                machineName = document.getElementById('sel-mesin')?.value || '';
            } else {
                machineName = document.getElementById('inp-mesin')?.value || '';
            }
            const date = document.getElementById('inp-tanggal').value;

            // Cek duplikasi terlebih dahulu sebelum render checklist
            fetch(`${BASE}?ajax=check_duplicate&machine_name=${encodeURIComponent(machineName)}&line=${encodeURIComponent(line)}&op=${encodeURIComponent(op)}&date=${encodeURIComponent(date)}`)
                .then(r => r.json())
                .then(dupData => {
                    if (dupData.already_filled) {
                        showLoading(false);
                        showDuplicateWarning(machineName, date);
                        return;
                    }
                    // Tidak duplikat — lanjut ambil checklist
                    const url = `${BASE}?ajax=checklist` +
                        `&machine_type=${encodeURIComponent(machineType)}` +
                        `&department=${encodeURIComponent(dept)}` +
                        `&line=${encodeURIComponent(line)}` +
                        `&op=${encodeURIComponent(op)}` +
                        `&machine_name=${encodeURIComponent(machineName)}`;
                    return fetch(url)
                        .then(r => r.json())
                        .then(items => {
                            showLoading(false);
                            renderChecklist(items);
                        });
                })
                .catch(() => {
                    showLoading(false);
                    showToast('Gagal memuat checklist.', 'error');
                });
        }

        // Tampilkan warning mesin sudah diisi hari ini
        function showDuplicateWarning(machineName, date) {
            document.getElementById('check-table').style.display = 'none';
            document.getElementById('empty-state').style.display = 'none';
            document.getElementById('loading-state').style.display = 'none';
            const warn = document.getElementById('duplicate-warning');
            warn.style.display = 'flex';
            document.getElementById('dup-warning-title').textContent =
                `Checksheet Sudah Diisi Hari Ini`;
            document.getElementById('dup-warning-desc').textContent =
                `Mesin "${machineName}" sudah diisi checksheet pada ${date}. Satu mesin hanya boleh diisi satu kali per hari.`;
            document.getElementById('row-count').textContent = '';
            document.getElementById('category-badge').classList.add('hidden');
            currentItems = [];
            setSubmitEnabled(false);
        }

        // ── Render checklist ──────────────────────────────────────────────────────
        function renderChecklist(items) {
            const tbody = document.getElementById('check-tbody');
            const table = document.getElementById('check-table');
            const empty = document.getElementById('empty-state');
            const catBadge = document.getElementById('category-badge');
            tbody.innerHTML = '';
            currentItems = items;

            if (!items || items.length === 0) {
                table.style.display = 'none';
                empty.style.display = 'flex';
                document.getElementById('row-count').textContent = '';
                catBadge.classList.add('hidden');
                document.getElementById('foto-section').style.display = 'none';
                setSubmitEnabled(false);
                return;
            }

            // Tanggal input form (hari ini)
            const checkDateVal = document.getElementById('inp-tanggal').value; // 'YYYY-MM-DD'
            const todayDate = checkDateVal ? new Date(checkDateVal + 'T00:00:00') : new Date();
            todayDate.setHours(0, 0, 0, 0);

            const fmt = d => d.toLocaleDateString('id-ID', {
                day: '2-digit',
                month: 'short',
                year: 'numeric'
            });

            items.forEach((item, idx) => {
                const badgeMap = {
                    'Daily': '<span class="badge badge-daily">Daily</span>',
                    'Weekly': '<span class="badge badge-weekly">Weekly</span>',
                    'Monthly': '<span class="badge badge-monthly">Monthly</span>',
                    'Montly': '<span class="badge badge-monthly">Monthly</span>',
                };
                const intervalNorm = (item.interval || '').trim();
                const intervalBadge = badgeMap[intervalNorm] || `<span class="badge" style="background:#f1f5f9;color:#94a3b8;">${esc(item.interval)}</span>`;

                const tr = document.createElement('tr');
                tr.className = 'fade-in';
                tr.style.animationDelay = `${idx * 15}ms`;

                const isWeekly = intervalNorm === 'Weekly';
                const isMonthly = intervalNorm === 'Monthly' || intervalNorm === 'Montly';

                let resultCell = '';

                if (isWeekly || isMonthly) {
                    // last_check_date dari server (null = belum pernah dicek)
                    const lastDateStr = item.last_check_date || null; // 'YYYY-MM-DD' or null

                    if (!lastDateStr) {
                        // Belum pernah dicek → tampilkan button group agar bisa diisi pertama kali
                        resultCell = `
                            <div class="result-cell-wrap">
                                <div class="result-btn-group" data-item-idx="${idx}">
                                    <button type="button" class="result-btn" data-val="V" onclick="onResultBtnClick(this)">V OK</button>
                                    <button type="button" class="result-btn" data-val="X" onclick="onResultBtnClick(this)">X Prob</button>
                                    <button type="button" class="result-btn" data-val="R" onclick="onResultBtnClick(this)">R Rep</button>
                                    <button type="button" class="result-btn" data-val="RO" onclick="onResultBtnClick(this)">RO Out</button>
                                </div>
                                <input type="hidden" class="result-select" value="-">
                            </div>`;
                    } else {
                        // Hitung next_due berdasarkan last_check_date
                        const lastDate = new Date(lastDateStr + 'T00:00:00');
                        const nextDue = new Date(lastDate);
                        if (isWeekly) {
                            nextDue.setDate(nextDue.getDate() + 7);
                        } else {
                            nextDue.setMonth(nextDue.getMonth() + 1);
                        }
                        nextDue.setHours(0, 0, 0, 0);

                        if (todayDate >= nextDue) {
                            // Sudah waktunya → button group untuk diisi ulang
                            resultCell = `
                                <div class="result-cell-wrap">
                                    <div class="result-btn-group" data-item-idx="${idx}">
                                        <button type="button" class="result-btn" data-val="V" onclick="onResultBtnClick(this)">V OK</button>
                                        <button type="button" class="result-btn" data-val="X" onclick="onResultBtnClick(this)">X Prob</button>
                                        <button type="button" class="result-btn" data-val="R" onclick="onResultBtnClick(this)">R Rep</button>
                                        <button type="button" class="result-btn" data-val="RO" onclick="onResultBtnClick(this)">RO Out</button>
                                    </div>
                                    <input type="hidden" class="result-select" value="-">
                                </div>`;
                        } else {
                            // Belum waktunya → tampilkan Last / Next, auto V
                            resultCell = `
                                <div style="min-width:160px;">
                                    <div style="font-size:.68rem;line-height:1.7;">
                                        <div><span class="font-bold text-slate-500">Last:</span> <span class="text-emerald-700 font-semibold">${fmt(lastDate)}</span></div>
                                        <div><span class="font-bold text-slate-500">Next:</span> <span class="text-blue-700 font-semibold">${fmt(nextDue)}</span></div>
                                    </div>
                                </div>`;
                            item._autoResult = 'V'; // otomatis disimpan sebagai OK
                        }
                    }
                } else {
                    resultCell = `
                        <div class="result-cell-wrap">
                            <div class="result-btn-group" data-item-idx="${idx}">
                                <button type="button" class="result-btn" data-val="V" onclick="onResultBtnClick(this)">V OK</button>
                                <button type="button" class="result-btn" data-val="X" onclick="onResultBtnClick(this)">X Prob</button>
                                <button type="button" class="result-btn" data-val="R" onclick="onResultBtnClick(this)">R Rep</button>
                                <button type="button" class="result-btn" data-val="RO" onclick="onResultBtnClick(this)">RO Out</button>
                            </div>
                            <input type="hidden" class="result-select" value="-">
                        </div>`;
                }

                tr.innerHTML = `
                <td class="text-center text-slate-400 font-bold text-xs">${item.no}</td>
                <td class="font-semibold text-slate-700">${esc(item.part)}</td>
                <td class="text-slate-500">${esc(item.standard)}</td>
                <td class="text-slate-500">${esc(item.method)}</td>
                <td class="text-slate-500">${esc(item.action)}</td>
                <td class="text-center">${intervalBadge}</td>
                <td class="text-center">${resultCell}</td>`;
                tbody.appendChild(tr);
            });

            table.style.display = 'table';
            empty.style.display = 'none';
            document.getElementById('row-count').textContent = `${items.length} item checklist`;
            catBadge.classList.remove('hidden');
            document.getElementById('foto-section').style.display = 'block';
            checkAllResultsFilled();
        }

        function onResultBtnClick(btn) {
            const group = btn.closest('.result-btn-group');
            const wrap = btn.closest('.result-cell-wrap');
            const hidden = wrap ? wrap.querySelector('.result-select') : null;
            const val = btn.getAttribute('data-val');

            // Toggle: jika sudah aktif → deaktifkan (kembali ke -)
            const wasActive = btn.classList.contains('active-v') ||
                btn.classList.contains('active-x') ||
                btn.classList.contains('active-r') ||
                btn.classList.contains('active-ro');

            // Reset semua button di group ini
            group.querySelectorAll('.result-btn').forEach(b => {
                b.classList.remove('active-v', 'active-x', 'active-r', 'active-ro');
            });

            let newVal = '-';
            if (!wasActive) {
                const classMap = {
                    V: 'active-v',
                    X: 'active-x',
                    R: 'active-r',
                    RO: 'active-ro'
                };
                btn.classList.add(classMap[val] || '');
                newVal = val;
            }

            if (hidden) hidden.value = newVal;

            // Tampilkan note input jika result bukan V / -
            if (!wrap) {
                checkAllResultsFilled();
                return;
            }
            let noteEl = wrap.querySelector('.note-input');
            const needsNote = newVal !== '-' && newVal !== 'V';

            if (needsNote) {
                if (!noteEl) {
                    noteEl = document.createElement('input');
                    noteEl.type = 'text';
                    noteEl.className = 'note-input';
                    noteEl.placeholder = 'Tulis keterangan... (wajib)';
                    noteEl.addEventListener('input', checkAllResultsFilled);
                    wrap.appendChild(noteEl);
                }
                noteEl.focus();
            } else {
                if (noteEl) noteEl.remove();
            }

            checkAllResultsFilled();
        }

        function onResultChange(sel) {
            // Legacy – tidak dipakai untuk baris baru, tapi dipertahankan untuk kompatibilitas
            checkAllResultsFilled();
        }

        function checkAllResultsFilled() {
            const kompressorOff = isKompressorLine() &&
                document.getElementById('inp-compressor-status').value === 'off';

            // Foto wajib sudah di-capture (tersimpan di memori browser)
            const fotoReady = capturedPhotoBlob !== null;

            if (kompressorOff) {
                // Kompressor OFF: hanya butuh foto, tidak perlu checklist
                setSubmitEnabled(fotoReady);
                return;
            }

            if (!currentItems.length) {
                setSubmitEnabled(false);
                return;
            }
            // Hanya item yang perlu diisi user (tidak punya _autoResult)
            const itemsNeedingInput = currentItems.filter(i => !i._autoResult);

            // Ambil semua hidden .result-select yang ada di DOM
            const hiddens = Array.from(document.querySelectorAll('#check-tbody .result-select'));

            // Jumlah hidden di DOM harus sama dengan item yang butuh input
            if (hiddens.length !== itemsNeedingInput.length) {
                // DOM belum sinkron, belum bisa submit
                setSubmitEnabled(false);
                return;
            }

            const allFilled = hiddens.every(h => h.value !== '-');
            const allNotesFilled = hiddens.every(h => {
                if (h.value === '-' || h.value === 'V') return true;
                const noteEl = h.closest('.result-cell-wrap')?.querySelector('.note-input');
                return noteEl && noteEl.value.trim() !== '';
            });

            // Jika Power House Kompressor tapi statusnya ON → wajib semua item diisi
            if (isKompressorLine() && document.getElementById('inp-compressor-status').value === '') {
                setSubmitEnabled(false);
                return;
            }

            setSubmitEnabled(allFilled && allNotesFilled && fotoReady);
        }

        function setSubmitEnabled(enabled) {
            const btn = document.getElementById('btn-submit');
            btn.disabled = !enabled;
            btn.className = enabled ?
                'px-6 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-sm font-bold transition-all flex items-center gap-2 shadow-sm cursor-pointer' :
                'px-6 py-2 rounded-xl bg-slate-200 text-slate-400 text-sm font-bold cursor-not-allowed transition-all flex items-center gap-2';
        }

        function submitForm() {
            const dept = document.getElementById('sel-dept').value;
            const line = document.getElementById('sel-line').value;
            const op = document.getElementById('sel-op').value;
            const type = document.getElementById('inp-type').value;
            const checker = document.getElementById('sel-checker').value;
            const tanggal = document.getElementById('inp-tanggal').value;

            // Tarik value nama mesin secara adaptif mengikuti status dropdown
            let mesin = '';
            if (isMachineDropdownActive) {
                mesin = document.getElementById('sel-mesin')?.value || '';
            } else {
                mesin = document.getElementById('inp-mesin')?.value || '';
            }

            if (!dept || !line || !checker || !tanggal || !mesin) {
                showToast('Lengkapi Department, Line, Checker, Tanggal, dan Mesin.', 'error');
                return;
            }

            const hiddens = document.querySelectorAll('#check-tbody .result-select');
            // Map hidden input index only for items WITHOUT _autoResult (weekly/monthly)
            let hiddenIdx = 0;
            const itemsPayload = currentItems.map((item) => {
                let result;
                if (item._autoResult) {
                    result = item._autoResult; // 'V' for weekly/monthly
                } else {
                    result = hiddens[hiddenIdx]?.value ?? '-';
                    hiddenIdx++;
                }
                // Ambil note dari input — pakai hiddens[hiddenIdx-1] karena sudah diincrement
                let note = null;
                if (!item._autoResult) {
                    const hid = hiddens[hiddenIdx - 1];
                    const noteEl = hid?.closest('.result-cell-wrap')?.querySelector('.note-input');
                    note = noteEl ? noteEl.value.trim() || null : null;
                }
                return {
                    id: item.id,
                    no: item.no,
                    part: item.part,
                    standard: item.standard,
                    result: result,
                    note: note,
                };
            });

            const btn = document.getElementById('btn-submit');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin text-xs"></i> Menyimpan...';
            btn.disabled = true;

            const fd = new FormData();
            fd.append('submit_checksheet', '1');
            fd.append('department', dept);
            fd.append('line', line);
            fd.append('op', op);
            fd.append('machine_name', mesin);
            fd.append('machine_type', type);
            fd.append('check_date', tanggal);
            fd.append('checker', checker);
            fd.append('items', JSON.stringify(itemsPayload));
            fd.append('compressor_status', document.getElementById('inp-compressor-status')?.value || '');
            // Kirim foto bersamaan dengan submit — seperti form_maintenance.php
            fd.append('photo', capturedPhotoBlob, 'checksheet_photo.jpg');

            fetch(BASE, {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        // PERBAIKAN POINT 1: Menampilkan pesan sukses tanpa ID & auto reset form
                        showToast(`✓ ${res.message}`, 'success');
                        resetForm();
                    } else {
                        showToast('✗ ' + res.message, 'error');
                    }
                })
                .catch(() => showToast('Koneksi gagal. Coba lagi.', 'error'))
                .finally(() => {
                    btn.innerHTML = '<i class="fas fa-paper-plane text-xs"></i> Submit';
                    checkAllResultsFilled();
                });
        }

        // ── Kompressor ON/OFF ─────────────────────────────────────────────────────
        function isKompressorLine() {
            const dept = document.getElementById('sel-dept').value;
            const line = document.getElementById('sel-line').value;
            return dept.toUpperCase() === 'POWER HOUSE' && line.toUpperCase().includes('KOMPRESSOR');
        }

        function checkCompressorStatus() {
            const wrap = document.getElementById('compressor-status-wrap');
            // Cek apakah mesin sudah terisi
            const mesinVal = isMachineDropdownActive ?
                (document.getElementById('sel-mesin')?.value || '') :
                (document.getElementById('inp-mesin')?.value || '');
            if (isKompressorLine() && mesinVal) {
                wrap.style.display = 'block';
            } else {
                wrap.style.display = 'none';
                resetCompressorStatus();
            }
        }

        function resetCompressorStatus() {
            document.getElementById('inp-compressor-status').value = '';
            document.getElementById('btn-comp-on').style.background = '#f8fafc';
            document.getElementById('btn-comp-on').style.borderColor = '#e2e8f0';
            document.getElementById('btn-comp-on').style.color = '#64748b';
            document.getElementById('btn-comp-off').style.background = '#f8fafc';
            document.getElementById('btn-comp-off').style.borderColor = '#e2e8f0';
            document.getElementById('btn-comp-off').style.color = '#64748b';
        }

        function setCompressorStatus(val) {
            document.getElementById('inp-compressor-status').value = val;
            const onBtn = document.getElementById('btn-comp-on');
            const offBtn = document.getElementById('btn-comp-off');
            if (val === 'on') {
                onBtn.style.background = '#dcfce7';
                onBtn.style.borderColor = '#86efac';
                onBtn.style.color = '#15803d';
                offBtn.style.background = '#f8fafc';
                offBtn.style.borderColor = '#e2e8f0';
                offBtn.style.color = '#64748b';
                // ON → load checklist seperti biasa
                clearTable();
                const dept = document.getElementById('sel-dept').value;
                const line = document.getElementById('sel-line').value;
                const type = document.getElementById('inp-type').value;
                if (type) executeFetchChecklist(type, dept, line);
            } else {
                offBtn.style.background = '#fee2e2';
                offBtn.style.borderColor = '#fca5a5';
                offBtn.style.color = '#dc2626';
                onBtn.style.background = '#f8fafc';
                onBtn.style.borderColor = '#e2e8f0';
                onBtn.style.color = '#64748b';
                // OFF → sembunyikan tabel, tampilkan banner, aktifkan submit setelah foto
                clearTableKeepFoto();
                checkAllResultsFilled();
            }
        }

        // clearTable tapi foto strip tetap muncul (untuk submit kompressor OFF)
        function clearTableKeepFoto() {
            document.getElementById('check-table').style.display = 'none';
            document.getElementById('empty-state').style.display = 'none';
            document.getElementById('duplicate-warning').style.display = 'none';
            document.getElementById('check-tbody').innerHTML = '';
            document.getElementById('row-count').textContent = 'Kompressor OFF — submit langsung';
            document.getElementById('category-badge').classList.add('hidden');
            document.getElementById('foto-section').style.display = 'block';
            currentItems = [];
            // Tampilkan pesan kompressor off
            const offBanner = document.getElementById('kompressor-off-banner');
            if (offBanner) offBanner.style.display = 'flex';
        }


        function resetForm() {
            document.getElementById('sel-dept').value = '';
            document.getElementById('sel-checker').value = '';
            const resetToday = new Date();
            document.getElementById('inp-tanggal').value = resetToday.toISOString().split('T')[0];
            document.getElementById('inp-tanggal-display').value = resetToday.toLocaleDateString('id-ID', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric'
            });
            const selLine = document.getElementById('sel-line');
            const selOp = document.getElementById('sel-op');
            selLine.innerHTML = '<option value="">— Pilih Line —</option>';
            selLine.disabled = true;
            selOp.innerHTML = '<option value="">— Pilih OP —</option>';
            selOp.disabled = true;
            clearMachine();
            clearTable();
            resetPhotoState();
            resetCompressorStatus();
            document.getElementById('compressor-status-wrap').style.display = 'none';
        }

        // ── Helpers ───────────────────────────────────────────────────────────────
        function clearMachine() {
            isMachineDropdownActive = false;
            document.getElementById('machine-field-container').innerHTML =
                `<input type="text" id="inp-mesin" class="form-field" placeholder="— Otomatis terisi —" readonly>`;
            document.getElementById('inp-type').value = '';
            // Sembunyikan dan reset kompressor wrap
            document.getElementById('compressor-status-wrap').style.display = 'none';
            resetCompressorStatus();
        }

        function clearTable() {
            document.getElementById('check-table').style.display = 'none';
            document.getElementById('empty-state').style.display = 'flex';
            document.getElementById('duplicate-warning').style.display = 'none';
            const offBanner = document.getElementById('kompressor-off-banner');
            if (offBanner) offBanner.style.display = 'none';
            document.getElementById('check-tbody').innerHTML = '';
            document.getElementById('row-count').textContent = '';
            document.getElementById('category-badge').classList.add('hidden');
            document.getElementById('foto-section').style.display = 'none';
            currentItems = [];
            setSubmitEnabled(false);
        }

        function showLoading(show) {
            document.getElementById('loading-state').style.display = show ? 'block' : 'none';
            document.getElementById('empty-state').style.display = show ? 'none' : 'flex';
            document.getElementById('check-table').style.display = 'none';
            document.getElementById('duplicate-warning').style.display = 'none';
        }

        function showToast(msg, type = 'success') {
            const t = document.getElementById('toast');
            t.textContent = msg;
            t.className = `show ${type}`;
            setTimeout(() => t.classList.remove('show'), 4000);
        }

        // ── Kamera & Foto (Modal) ─────────────────────────────────────────────────
        function openCameraModal() {
            document.getElementById('camera-modal').classList.add('open');
            openCamera();
        }

        function closeCameraModal() {
            // Hentikan stream jika masih aktif
            stopCameraStream();
            document.getElementById('camera-modal').classList.remove('open');
            // Kembalikan state modal ke sesuai kondisi (ada foto / tidak)
            if (capturedPhotoBlob) {
                document.getElementById('foto-empty-box').style.display = 'none';
                document.getElementById('camera-preview-wrap').style.display = 'none';
                document.getElementById('photo-result-wrap').style.display = 'block';
                document.getElementById('btn-capture').style.display = 'none';
                document.getElementById('btn-cancel-camera').style.display = 'none';
                document.getElementById('btn-use-photo').style.display = 'none';
                document.getElementById('btn-open-camera').innerHTML = '<i class="fas fa-redo"></i> Retake Photo';
            } else {
                document.getElementById('foto-empty-box').style.display = 'flex';
                document.getElementById('camera-preview-wrap').style.display = 'none';
                document.getElementById('photo-result-wrap').style.display = 'none';
                document.getElementById('btn-capture').style.display = 'none';
                document.getElementById('btn-cancel-camera').style.display = 'none';
                document.getElementById('btn-use-photo').style.display = 'none';
            }
        }

        function openCamera() {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                showToast('Browser tidak mendukung akses kamera.', 'error');
                return;
            }
            const constraints = {
                video: {
                    facingMode: {
                        ideal: 'environment'
                    },
                    width: {
                        ideal: 1280
                    },
                    height: {
                        ideal: 960
                    }
                },
                audio: false
            };
            navigator.mediaDevices.getUserMedia(constraints)
                .then(stream => {
                    cameraStream = stream;
                    const video = document.getElementById('camera-video');
                    video.srcObject = stream;
                    document.getElementById('foto-empty-box').style.display = 'none';
                    document.getElementById('photo-result-wrap').style.display = 'none';
                    document.getElementById('camera-preview-wrap').style.display = 'block';
                    document.getElementById('btn-capture').style.display = 'flex';
                    document.getElementById('btn-cancel-camera').style.display = 'flex';
                    document.getElementById('btn-use-photo').style.display = 'none';
                    document.getElementById('btn-open-camera').style.display = 'none';
                })
                .catch(err => {
                    console.error('Camera error:', err);
                    showToast('Tidak bisa mengakses kamera. Pastikan izin kamera sudah diberikan.', 'error');
                });
        }

        function capturePhoto() {
            const video = document.getElementById('camera-video');
            const canvas = document.getElementById('photo-canvas');
            canvas.width = video.videoWidth || 1280;
            canvas.height = video.videoHeight || 960;
            canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
            stopCameraStream();

            canvas.toBlob(blob => {
                capturedPhotoBlob = blob;
                const url = URL.createObjectURL(blob);

                // Update gambar di modal
                const preview = document.getElementById('photo-preview');
                preview.src = url;

                // Update thumbnail di strip
                const thumb = document.getElementById('foto-thumb');
                thumb.src = url;

                // Update large preview
                document.getElementById('photo-preview-large').src = url;

                document.getElementById('camera-preview-wrap').style.display = 'none';
                document.getElementById('photo-result-wrap').style.display = 'block';
                document.getElementById('btn-capture').style.display = 'none';
                document.getElementById('btn-cancel-camera').style.display = 'none';
                document.getElementById('btn-use-photo').style.display = 'flex';
                document.getElementById('btn-open-camera').style.display = 'inline-flex';
                document.getElementById('btn-open-camera').innerHTML = '<i class="fas fa-redo"></i> Retake Photo';

                // Tampilkan thumbnail di strip
                document.getElementById('foto-thumb-wrap').style.display = 'flex';

                // Update badge
                const statusBadge = document.getElementById('foto-status-badge');
                statusBadge.className = 'foto-status-badge ok';
                statusBadge.innerHTML = '<i class="fas fa-check-circle"></i> Photo ready';

                checkAllResultsFilled();
            }, 'image/jpeg', 0.88);
        }

        function cancelCamera() {
            stopCameraStream();
            document.getElementById('camera-preview-wrap').style.display = 'none';
            document.getElementById('btn-capture').style.display = 'none';
            document.getElementById('btn-cancel-camera').style.display = 'none';
            if (capturedPhotoBlob) {
                document.getElementById('photo-result-wrap').style.display = 'block';
                document.getElementById('btn-use-photo').style.display = 'flex';
            } else {
                document.getElementById('foto-empty-box').style.display = 'flex';
                document.getElementById('btn-use-photo').style.display = 'none';
            }
            document.getElementById('btn-open-camera').style.display = 'inline-flex';
        }

        function deleteCapturedPhoto() {
            capturedPhotoBlob = null;
            const preview = document.getElementById('photo-preview');
            if (preview.src.startsWith('blob:')) URL.revokeObjectURL(preview.src);
            preview.src = '';
            document.getElementById('photo-result-wrap').style.display = 'none';
            document.getElementById('foto-empty-box').style.display = 'flex';
            document.getElementById('btn-use-photo').style.display = 'none';
            document.getElementById('btn-open-camera').style.display = 'inline-flex';
            document.getElementById('btn-open-camera').innerHTML = '<i class="fas fa-camera"></i> Open Camera';
            // Sembunyikan thumbnail
            document.getElementById('foto-thumb-wrap').style.display = 'none';
            document.getElementById('foto-thumb').src = '';
            // Update badge
            const statusBadge = document.getElementById('foto-status-badge');
            statusBadge.className = 'foto-status-badge required';
            statusBadge.innerHTML = '<i class="fas fa-exclamation-circle"></i> Photo required';
            checkAllResultsFilled();
        }

        function stopCameraStream() {
            if (cameraStream) {
                cameraStream.getTracks().forEach(t => t.stop());
                cameraStream = null;
            }
            const video = document.getElementById('camera-video');
            video.srcObject = null;
        }

        function resetPhotoState() {
            stopCameraStream();
            capturedPhotoBlob = null;
            const preview = document.getElementById('photo-preview');
            if (preview.src && preview.src.startsWith('blob:')) URL.revokeObjectURL(preview.src);
            preview.src = '';
            document.getElementById('photo-result-wrap').style.display = 'none';
            document.getElementById('camera-preview-wrap').style.display = 'none';
            document.getElementById('foto-empty-box').style.display = 'flex';
            document.getElementById('btn-capture').style.display = 'none';
            document.getElementById('btn-cancel-camera').style.display = 'none';
            document.getElementById('btn-use-photo').style.display = 'none';
            document.getElementById('btn-open-camera').style.display = 'inline-flex';
            document.getElementById('btn-open-camera').innerHTML = '<i class="fas fa-camera"></i> Open Camera';
            document.getElementById('foto-thumb-wrap').style.display = 'none';
            document.getElementById('foto-thumb').src = '';
            const statusBadge = document.getElementById('foto-status-badge');
            statusBadge.className = 'foto-status-badge required';
            statusBadge.innerHTML = '<i class="fas fa-exclamation-circle"></i> Photo required';
            // Tutup modal jika terbuka
            document.getElementById('camera-modal').classList.remove('open');
        }

        function openPhotoPreviewModal() {
            if (!capturedPhotoBlob) return;
            document.getElementById('photo-preview-modal').style.display = 'flex';
        }

        function closePhotoPreviewModal() {
            document.getElementById('photo-preview-modal').style.display = 'none';
        }

        // Tutup modal preview jika klik di luar gambar
        document.getElementById('photo-preview-modal').addEventListener('click', function(e) {
            if (e.target === this) closePhotoPreviewModal();
        });

        // Tutup camera modal jika klik backdrop
        document.getElementById('camera-modal').addEventListener('click', function(e) {
            if (e.target === this) closeCameraModal();
        });

        function esc(str) {
            const d = document.createElement('div');
            d.textContent = str;
            return d.innerHTML;
        }
    </script>
</body>

</html>