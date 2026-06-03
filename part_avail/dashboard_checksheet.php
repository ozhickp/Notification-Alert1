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
        $dept = $_GET['department'] ?? '';
        $line = $_GET['line'] ?? '';
        $key  = resolveCategoryKey($_GET['machine_type'], $dept, $line);

        if (!$key) {
            echo json_encode([]);
            exit;
        }

        $stmt = $pdo->prepare(
            "SELECT id, no, part, standard, method, action, `interval`
             FROM checksheet_items
             WHERE category_key = ? AND is_active = 1
             ORDER BY no"
        );
        $stmt->execute([$key]);
        echo json_encode($stmt->fetchAll());
        exit;
    }

    if ($_GET['ajax'] === 'checkers') {
        $checkers = ['Ridwan', 'Piyan', 'Arief', 'Joko', 'Yudi', 'Subki', 'Nurul', 'Renaldi', 'Hakim', 'Aji', 'Alamsyah', 'Rizki', 'Hendra'];
        echo json_encode($checkers);
        exit;
    }

    echo json_encode(['error' => 'Unknown request']);
    exit;
}

// ─── POST: Submit checksheet ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_checksheet'])) {
    header('Content-Type: application/json');

    $dept        = trim($_POST['department']    ?? '');
    $line        = trim($_POST['line']          ?? '');
    $op          = trim($_POST['op']            ?? '-');
    $machineName = trim($_POST['machine_name']  ?? '');
    $machineType = trim($_POST['machine_type']  ?? '');
    $checkDate   = trim($_POST['check_date']    ?? '');
    $checker     = trim($_POST['checker']       ?? '');
    $itemsJson   = $_POST['items']              ?? '[]';

    if (!$dept || !$line || !$checker || !$checkDate || !$machineName) {
        echo json_encode(['success' => false, 'message' => 'Lengkapi semua field wajib.']);
        exit;
    }

    $items = json_decode($itemsJson, true);
    if (!is_array($items) || empty($items)) {
        echo json_encode(['success' => false, 'message' => 'Tidak ada item checklist.']);
        exit;
    }

    $categoryKey = resolveCategoryKey($machineType, $dept, $line) ?? '';

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "INSERT INTO checksheet_submissions
             (department, `line`, op, machine_name, machine_type, category_key, check_date, checker, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$dept, $line, $op, $machineName, $machineType, $categoryKey, $checkDate, $checker, $_SERVER['REMOTE_ADDR'] ?? null]);
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
        // PERBAIKAN POINT 1: Response diganti tanpa ID
        echo json_encode(['success' => true, 'message' => 'Check sheet berhasil disimpan.']);
    } catch (\Exception $e) {
        $pdo->rollBack();
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

        /* ── Result select ── */
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
        }

        .result-select:focus {
            outline: none;
            border-color: #f43f5e;
            box-shadow: 0 0 0 3px rgba(244, 63, 94, .15);
        }

        .result-select.val-v {
            background: #dcfce7;
            color: #15803d;
            border-color: #86efac;
        }

        .result-select.val-x {
            background: #fee2e2;
            color: #dc2626;
            border-color: #fca5a5;
        }

        .result-select.val-r {
            background: #fef9c3;
            color: #ca8a04;
            border-color: #fde047;
        }

        .result-select.val-ro {
            background: #ede9fe;
            color: #7c3aed;
            border-color: #c4b5fd;
        }

        .result-select.val-dash {
            background: #f1f5f9;
            color: #94a3b8;
            border-color: #e2e8f0;
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
                            <input type="date" id="inp-tanggal" class="form-field" readonly>
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

                        <div class="border-t border-slate-100 px-5 py-3 flex items-center justify-between gap-3 bg-slate-50/60 rounded-b-2xl flex-shrink-0">
                            <div class="text-xs text-slate-400 font-medium">
                                <i class="fas fa-circle-info mr-1"></i>
                                Pastikan semua item telah dicek sebelum submit
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
    <div id="toast"></div>

    <script>
        const BASE = window.location.pathname;
        let currentItems = [];
        let isMachineDropdownActive = false; // Flag kontrol internal

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
            document.getElementById('inp-tanggal').value = today.toISOString().split('T')[0];
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

            // PERBAIKAN LOGIKA POINT 3: Cek daftar semua mesin terlebih dahulu
            fetch(`${BASE}?ajax=machine_list&department=${encodeURIComponent(dept)}&line=${encodeURIComponent(line)}&op=${encodeURIComponent(op)}`)
                .then(r => r.json())
                .then(machines => {
                    const container = document.getElementById('machine-field-container');

                    if (op === '-' && machines.length > 1) {
                        // JIKA KONDISI KHUSUS (Banyak mesin di satu OP bernama '-'): Ubah jadi dropdown select bawaan CSS asli Anda
                        isMachineDropdownActive = true;
                        let selectHtml = `<select id="sel-mesin" class="form-field" onchange="onMachineSelectChange()">
                                            <option value="">— Pilih Mesin —</option>`;
                        machines.forEach(m => {
                            selectHtml += `<option value="${esc(m.machine_name)}" data-type="${esc(m.machine_type)}">${esc(m.machine_name)}</option>`;
                        });
                        selectHtml += `</select>`;
                        container.innerHTML = selectHtml;
                        document.getElementById('inp-type').value = ''; // Kosongkan dulu type sampai dipilih
                    } else {
                        // KONDISI SEPERTI BIASA / NORMAL
                        isMachineDropdownActive = false;
                        container.innerHTML = `<input type="text" id="inp-mesin" class="form-field" placeholder="— Otomatis terisi —" readonly>`;

                        if (machines.length > 0) {
                            document.getElementById('inp-mesin').value = machines[0].machine_name || '';
                            document.getElementById('inp-type').value = machines[0].machine_type || '';

                            // Eksekusi pemanggilan checklist items
                            executeFetchChecklist(machines[0].machine_type, dept, line);
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

            if (!selMachine || !selMachine.value) {
                typeInput.value = '';
                return;
            }

            const activeOption = selMachine.options[selMachine.selectedIndex];
            const machineType = activeOption.getAttribute('data-type') || '';
            typeInput.value = machineType;

            executeFetchChecklist(machineType, dept, line);
        }

        // Fungsi split untuk murni menarik data checklist item dari database
        function executeFetchChecklist(machineType, dept, line) {
            showLoading(true);
            fetch(`${BASE}?ajax=checklist&machine_type=${encodeURIComponent(machineType)}&department=${encodeURIComponent(dept)}&line=${encodeURIComponent(line)}`)
                .then(r => r.json())
                .then(items => {
                    showLoading(false);
                    renderChecklist(items);
                })
                .catch(() => {
                    showLoading(false);
                    showToast('Gagal memuat checklist.', 'error');
                });
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
                setSubmitEnabled(false);
                return;
            }

            items.forEach((item, idx) => {
                const badgeMap = {
                    'Daily': '<span class="badge badge-daily">Daily</span>',
                    'Weekly': '<span class="badge badge-weekly">Weekly</span>',
                    'Monthly': '<span class="badge badge-monthly">Monthly</span>',
                    'Montly': '<span class="badge badge-monthly">Monthly</span>',
                };
                const intervalBadge = badgeMap[item.interval] || `<span class="badge" style="background:#f1f5f9;color:#94a3b8;">${item.interval}</span>`;
                const tr = document.createElement('tr');
                tr.className = 'fade-in';
                tr.style.animationDelay = `${idx * 15}ms`;
                tr.innerHTML = `
                <td class="text-center text-slate-400 font-bold text-xs">${item.no}</td>
                <td class="font-semibold text-slate-700">${item.part}</td>
                <td class="text-slate-500">${item.standard}</td>
                <td class="text-slate-500">${item.method}</td>
                <td class="text-slate-500">${item.action}</td>
                <td class="text-center">${intervalBadge}</td>
                <td class="text-center">
                    <select class="result-select val-dash" onchange="onResultChange(this)" data-idx="${idx}">
                        <option value="-" selected>—</option>
                        <option value="V">V — OK</option>
                        <option value="X">X — Problem</option>
                        <option value="R">R — Repair</option>
                        <option value="RO">RO — Outsider</option>
                    </select>
                </td>`;
                tbody.appendChild(tr);
            });

            table.style.display = 'table';
            empty.style.display = 'none';
            document.getElementById('row-count').textContent = `${items.length} item checklist`;
            catBadge.classList.remove('hidden');
            checkAllResultsFilled();
        }

        function onResultChange(sel) {
            const map = {
                'V': 'val-v',
                'X': 'val-x',
                'R': 'val-r',
                'RO': 'val-ro',
                '-': 'val-dash'
            };
            sel.className = 'result-select ' + (map[sel.value] || 'val-dash');
            checkAllResultsFilled();
        }

        function checkAllResultsFilled() {
            if (!currentItems.length) {
                setSubmitEnabled(false);
                return;
            }
            const selects = document.querySelectorAll('#check-tbody .result-select');
            const allFilled = Array.from(selects).every(s => s.value !== '-');
            setSubmitEnabled(allFilled);
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

            const selects = document.querySelectorAll('#check-tbody .result-select');
            const itemsPayload = currentItems.map((item, idx) => ({
                id: item.id,
                no: item.no,
                part: item.part,
                standard: item.standard,
                result: selects[idx]?.value ?? '-',
                note: null,
            }));

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

        // ── Reset ─────────────────────────────────────────────────────────────────
        function resetForm() {
            document.getElementById('sel-dept').value = '';
            document.getElementById('sel-checker').value = '';
            document.getElementById('inp-tanggal').value = new Date().toISOString().split('T')[0];
            const selLine = document.getElementById('sel-line');
            const selOp = document.getElementById('sel-op');
            selLine.innerHTML = '<option value="">— Pilih Line —</option>';
            selLine.disabled = true;
            selOp.innerHTML = '<option value="">— Pilih OP —</option>';
            selOp.disabled = true;
            clearMachine();
            clearTable();
        }

        // ── Helpers ───────────────────────────────────────────────────────────────
        function clearMachine() {
            isMachineDropdownActive = false;
            document.getElementById('machine-field-container').innerHTML =
                `<input type="text" id="inp-mesin" class="form-field" placeholder="— Otomatis terisi —" readonly>`;
            document.getElementById('inp-type').value = '';
        }

        function clearTable() {
            document.getElementById('check-table').style.display = 'none';
            document.getElementById('empty-state').style.display = 'flex';
            document.getElementById('check-tbody').innerHTML = '';
            document.getElementById('row-count').textContent = '';
            document.getElementById('category-badge').classList.add('hidden');
            currentItems = [];
            setSubmitEnabled(false);
        }

        function showLoading(show) {
            document.getElementById('loading-state').style.display = show ? 'block' : 'none';
            document.getElementById('empty-state').style.display = show ? 'none' : 'flex';
            document.getElementById('check-table').style.display = 'none';
        }

        function showToast(msg, type = 'success') {
            const t = document.getElementById('toast');
            t.textContent = msg;
            t.className = `show ${type}`;
            setTimeout(() => t.classList.remove('show'), 4000);
        }

        function esc(str) {
            const d = document.createElement('div');
            d.textContent = str;
            return d.innerHTML;
        }
    </script>
</body>

</html>