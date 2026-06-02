<?php
// history_checksheet.php
$host    = 'localhost';
$db      = 'db_notif_alert';
$user    = 'root';
$pass    = '';
$charset = 'utf8mb4';

$dsn     = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die('DB Error: ' . $e->getMessage());
}

// ─── AJAX: fetch history data ─────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'history') {
    header('Content-Type: application/json');

    $mode  = $_GET['mode']  ?? 'daily';     // daily | monthly
    $value = $_GET['value'] ?? '';          // YYYY-MM-DD | YYYY-MM
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;

    if ($value === '') {
        echo json_encode(['rows' => [], 'total' => 0]);
        exit;
    }

    if ($mode === 'daily') {
        $where = "WHERE DATE(s.check_date) = ?";
    } else {
        $where = "WHERE DATE_FORMAT(s.check_date, '%Y-%m') = ?";
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM checksheet_submissions s $where");
    $countStmt->execute([$value]);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT s.id, s.check_date, s.department, s.line, s.op,
               s.machine_name, s.machine_type, s.category_key,
               s.checker, s.submitted_at,
               COUNT(d.id) AS total_items,
               SUM(d.result = 'V')  AS ok_count,
               SUM(d.result = 'X')  AS problem_count,
               SUM(d.result = 'R')  AS repair_count,
               SUM(d.result = 'RO') AS outsider_count,
               SUM(d.result = '-')  AS na_count
        FROM checksheet_submissions s
        LEFT JOIN checksheet_submission_details d ON d.submission_id = s.id
        $where
        GROUP BY s.id
        ORDER BY s.submitted_at DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute([$value]);
    $rows = $stmt->fetchAll();

    echo json_encode(['rows' => $rows, 'total' => $total, 'limit' => $limit]);
    exit;
}

// ─── AJAX: fetch detail items for a submission ────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'detail') {
    header('Content-Type: application/json');
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) {
        echo json_encode([]);
        exit;
    }
    $stmt = $pdo->prepare("
        SELECT no, part, standard, result, note
        FROM checksheet_submission_details
        WHERE submission_id = ?
        ORDER BY no
    ");
    $stmt->execute([$id]);
    echo json_encode($stmt->fetchAll());
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>History Check Sheet — Maintenance Hub</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: #f1f5f9;
            height: 100vh;
            overflow: hidden;
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

        /* ── Sidebar back link ── */
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

        /* ── Main area ── */
        #main-content {
            margin-left: 56px;
            height: 100vh;
            display: flex;
            flex-direction: column;
            transition: margin-left .25s ease;
        }

        #main-content.expanded {
            margin-left: 240px;
        }

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
            flex-shrink: 0;
        }

        /* ── PERBAIKAN: Container Tabel dengan Batas Tinggi & Scroll Internal ── */
        .table-scroll-container {
            height: calc(100vh - 215px);
            overflow-y: auto;
        }

        /* ── Table ── */
        .hist-table thead th {
            background: #1e293b;
            color: #f1f5f9;
            font-size: .68rem;
            font-weight: 700;
            letter-spacing: .05em;
            text-transform: uppercase;
            padding: 10px 14px;
            position: sticky;
            /* Sticky head agar judul kolom tidak ikut ter-scroll */
            top: 0;
            z-index: 10;
        }

        .hist-table thead th:first-child {
            border-radius: 10px 0 0 0;
        }

        .hist-table thead th:last-child {
            border-radius: 0 10px 0 0;
        }

        .hist-table tbody tr {
            transition: background .15s;
            cursor: pointer;
        }

        .hist-table tbody tr:nth-child(even) {
            background: #f8fafc;
        }

        .hist-table tbody tr:hover {
            background: #eff6ff;
        }

        .hist-table tbody td {
            padding: 9px 14px;
            font-size: .77rem;
            color: #334155;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }

        /* ── Result pills ── */
        .pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 22px;
            height: 22px;
            border-radius: 6px;
            font-size: .65rem;
            font-weight: 800;
            padding: 0 5px;
        }

        .pill-v {
            background: #dcfce7;
            color: #15803d;
        }

        .pill-x {
            background: #fee2e2;
            color: #dc2626;
        }

        .pill-r {
            background: #fef9c3;
            color: #ca8a04;
        }

        .pill-ro {
            background: #ede9fe;
            color: #7c3aed;
        }

        .pill-na {
            background: #f1f5f9;
            color: #94a3b8;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 2px 8px;
            border-radius: 99px;
            font-size: .64rem;
            font-weight: 700;
            letter-spacing: .04em;
        }

        .badge-v {
            background: #dcfce7;
            color: #15803d;
        }

        .badge-x {
            background: #fee2e2;
            color: #dc2626;
        }

        .badge-r {
            background: #fef9c3;
            color: #ca8a04;
        }

        .badge-ro {
            background: #ede9fe;
            color: #7c3aed;
        }

        .badge-na {
            background: #f1f5f9;
            color: #94a3b8;
        }

        /* ── Form fields ── */
        .form-field {
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

        /* ── Modal ── */
        #modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, .55);
            backdrop-filter: blur(3px);
            z-index: 200;
            align-items: center;
            justify-content: center;
        }

        #modal-overlay.open {
            display: flex;
        }

        #modal-box {
            background: #fff;
            border-radius: 18px;
            width: 90%;
            max-width: 780px;
            max-height: 85vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 24px 60px rgba(0, 0, 0, .25);
            animation: popIn .25s cubic-bezier(.34, 1.56, .64, 1);
        }

        @keyframes popIn {
            from {
                opacity: 0;
                transform: scale(.92)
            }

            to {
                opacity: 1;
                transform: scale(1)
            }
        }

        .modal-table thead th {
            background: #f8fafc;
            font-size: .68rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: .04em;
            padding: 8px 12px;
            border-bottom: 2px solid #e2e8f0;
            position: sticky;
            top: 0;
        }

        .modal-table tbody td {
            padding: 7px 12px;
            font-size: .77rem;
            color: #334155;
            border-bottom: 1px solid #f1f5f9;
        }

        /* ── Skeleton ── */
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

        /* ── Pagination ── */
        .page-btn {
            min-width: 34px;
            height: 34px;
            border-radius: 8px;
            font-size: .78rem;
            font-weight: 700;
            border: 1.5px solid #e2e8f0;
            background: #fff;
            color: #475569;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all .2s;
        }

        .page-btn:hover:not(:disabled) {
            border-color: #f43f5e;
            color: #f43f5e;
        }

        .page-btn.active {
            background: #f43f5e;
            border-color: #f43f5e;
            color: #fff;
        }

        .page-btn:disabled {
            opacity: .4;
            cursor: not-allowed;
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

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(8px)
            }

            to {
                opacity: 1;
                transform: translateY(0)
            }
        }

        .fade-in {
            animation: fadeInUp .2s ease forwards;
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
            <a href="dashboard_checksheet.php" onclick="navigateTo(event,'dashboard_checksheet.php')" class="nav-item" title="Check Sheet">
                <i class="fas fa-clipboard-check"></i>
                <span class="nav-label">Check Sheet</span>
            </a>
            <a href="history_checksheet.php" onclick="navigateTo(event,'history_checksheet.php')" class="nav-item active" title="History">
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
            <div class="flex items-center gap-3">
                <div class="w-7 h-7 rounded-lg bg-rose-50 flex items-center justify-center">
                    <i class="fas fa-history text-rose-500 text-xs"></i>
                </div>
                <div>
                    <div class="text-sm font-bold text-slate-800">History Check Sheet</div>
                    <div class="text-[10px] text-slate-400 font-medium">Riwayat & export hasil pengecekan harian / bulanan</div>
                </div>
            </div>
        </div>

        <div class="p-6 space-y-4 flex-1 flex flex-col min-h-0">

            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 flex-shrink-0">
                <div class="flex flex-wrap gap-3 items-end">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-1.5">Mode</label>
                        <div class="flex rounded-xl border border-slate-200 overflow-hidden">
                            <button id="btn-mode-daily"
                                onclick="setMode('daily')"
                                class="px-4 py-2 text-xs font-bold transition-all bg-rose-600 text-white">
                                <i class="fas fa-calendar-day mr-1.5"></i>Harian
                            </button>
                            <button id="btn-mode-monthly"
                                onclick="setMode('monthly')"
                                class="px-4 py-2 text-xs font-bold transition-all bg-white text-slate-500 hover:bg-slate-50">
                                <i class="fas fa-calendar-alt mr-1.5"></i>Bulanan
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-1.5">
                            <span id="picker-label">Tanggal</span>
                        </label>
                        <input type="date" id="inp-date" class="form-field" style="min-width:170px;">
                    </div>

                    <button onclick="loadHistory(1)"
                        class="px-5 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-sm font-bold transition-all flex items-center gap-2 shadow-sm">
                        <i class="fas fa-search text-xs"></i> Cari
                    </button>

                    <div class="flex-1"></div>

                    <div class="flex gap-2">
                        <button onclick="exportData()"
                            class="px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold transition-all flex items-center gap-2 shadow-sm">
                            <i class="fas fa-file-excel"></i> Export Excel
                        </button>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden flex-1 flex flex-col min-h-0">
                <div class="px-5 py-3.5 border-b border-slate-100 flex items-center justify-between flex-shrink-0">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-table text-slate-400 text-sm"></i>
                        <span class="text-sm font-bold text-slate-700">Riwayat Submission</span>
                        <span id="result-label" class="text-[11px] text-slate-400 font-medium ml-1"></span>
                    </div>
                    <span id="showing-label" class="text-[11px] text-slate-400 font-medium"></span>
                </div>

                <div class="table-scroll-container flex-1 min-h-0">
                    <table class="hist-table w-full" id="hist-table" style="display:none;">
                        <thead>
                            <tr>
                                <th class="text-center w-10">No</th>
                                <th>Tanggal</th>
                                <th>Department</th>
                                <th>Line</th>
                                <th>OP</th>
                                <th>Mesin</th>
                                <th>Checker</th>
                                <th class="text-center">Category</th>
                                <th class="text-center">Hasil</th>
                                <th class="text-center">Submitted At</th>
                                <th class="text-center w-16">Detail</th>
                            </tr>
                        </thead>
                        <tbody id="hist-tbody"></tbody>
                    </table>

                    <div id="hist-empty" class="flex flex-col items-center justify-center py-16 text-slate-400">
                        <i class="fas fa-folder-open text-5xl mb-3 opacity-30"></i>
                        <p class="font-bold text-sm">Pilih tanggal / bulan lalu klik Cari</p>
                        <p class="text-xs mt-1">Data history checksheet akan tampil di sini</p>
                    </div>

                    <div id="hist-loading" style="display:none;" class="p-5 space-y-3">
                        <?php for ($i = 0; $i < 6; $i++): ?>
                            <div class="flex gap-3 items-center">
                                <div class="skeleton w-6 h-5"></div>
                                <div class="skeleton w-20 h-5"></div>
                                <div class="skeleton flex-1 h-5"></div>
                                <div class="skeleton w-16 h-5"></div>
                                <div class="skeleton w-24 h-5"></div>
                                <div class="skeleton w-20 h-5"></div>
                                <div class="skeleton w-20 h-5"></div>
                                <div class="skeleton w-28 h-5"></div>
                                <div class="skeleton w-16 h-5"></div>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <div id="pagination" class="hidden border-t border-slate-100 px-5 py-3 flex items-center justify-between flex-shrink-0 bg-white">
                    <span id="page-info" class="text-xs text-slate-400 font-medium"></span>
                    <div id="page-btns" class="flex gap-1.5"></div>
                </div>
            </div>
        </div>
    </div>

    <div id="modal-overlay" onclick="closeModal(event)">
        <div id="modal-box">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                <div>
                    <div class="text-sm font-bold text-slate-800" id="modal-title">Detail Check Sheet</div>
                    <div class="text-[11px] text-slate-400 font-medium mt-0.5" id="modal-subtitle"></div>
                </div>
                <button onclick="closeModal()" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 flex items-center justify-center transition-all">
                    <i class="fas fa-times text-slate-500 text-xs"></i>
                </button>
            </div>
            <div class="overflow-auto flex-1 p-0">
                <table class="modal-table w-full">
                    <thead>
                        <tr>
                            <th class="text-center w-10">No</th>
                            <th>Part to be Checked</th>
                            <th>Standard</th>
                            <th class="text-center w-24">Result</th>
                            <th>Note</th>
                        </tr>
                    </thead>
                    <tbody id="modal-tbody"></tbody>
                </table>
                <div id="modal-loading" class="p-6 space-y-3">
                    <?php for ($i = 0; $i < 5; $i++): ?>
                        <div class="flex gap-3">
                            <div class="skeleton w-6 h-4"></div>
                            <div class="skeleton flex-1 h-4"></div>
                            <div class="skeleton w-24 h-4"></div>
                            <div class="skeleton w-16 h-4"></div>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>
            <div class="px-6 py-3.5 border-t border-slate-100 bg-slate-50 rounded-b-2xl flex items-center justify-between">
                <span id="modal-summary" class="text-[11px] text-slate-500 font-medium"></span>
                <button onclick="closeModal()" class="px-4 py-2 rounded-xl bg-slate-200 hover:bg-slate-300 text-slate-700 text-xs font-bold transition-all">
                    Tutup
                </button>
            </div>
        </div>
    </div>

    <div id="toast"></div>

    <script>
        let currentMode = 'daily';
        let currentPage = 1;
        let totalRecords = 0;
        let limitPerPage = 20;

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
            document.getElementById('inp-date').value = new Date().toISOString().split('T')[0];
        });

        // ── Mode toggle ───────────────────────────────────────────────────────────
        function setMode(mode) {
            currentMode = mode;
            const daily = document.getElementById('btn-mode-daily');
            const month = document.getElementById('btn-mode-monthly');
            const inp = document.getElementById('inp-date');
            const label = document.getElementById('picker-label');

            if (mode === 'daily') {
                daily.className = 'px-4 py-2 text-xs font-bold transition-all bg-rose-600 text-white';
                month.className = 'px-4 py-2 text-xs font-bold transition-all bg-white text-slate-500 hover:bg-slate-50';
                inp.type = 'date';
                label.textContent = 'Tanggal';
                inp.value = new Date().toISOString().split('T')[0];
            } else {
                daily.className = 'px-4 py-2 text-xs font-bold transition-all bg-white text-slate-500 hover:bg-slate-50';
                month.className = 'px-4 py-2 text-xs font-bold transition-all bg-rose-600 text-white';
                inp.type = 'month';
                label.textContent = 'Bulan';
                inp.value = new Date().toISOString().slice(0, 7);
            }
        }

        // ── Load history ──────────────────────────────────────────────────────────
        function loadHistory(page = 1) {
            const value = document.getElementById('inp-date').value;
            if (!value) {
                showToast('Pilih tanggal / bulan terlebih dahulu.', 'error');
                return;
            }
            currentPage = page;

            document.getElementById('hist-table').style.display = 'none';
            document.getElementById('hist-empty').style.display = 'none';
            document.getElementById('hist-loading').style.display = 'block';
            document.getElementById('pagination').classList.add('hidden');

            fetch(`history_checksheet.php?ajax=history&mode=${currentMode}&value=${encodeURIComponent(value)}&page=${page}`)
                .then(r => r.json())
                .then(data => {
                    document.getElementById('hist-loading').style.display = 'none';
                    totalRecords = data.total;
                    limitPerPage = data.limit;

                    if (!data.rows || data.rows.length === 0) {
                        document.getElementById('hist-empty').style.display = 'flex';
                        document.getElementById('hist-empty').innerHTML = `
                        <i class="fas fa-inbox text-5xl mb-3 opacity-30"></i>
                        <p class="font-bold text-sm">Tidak ada data untuk periode ini</p>
                        <p class="text-xs mt-1">Coba pilih tanggal / bulan yang lain</p>`;
                        return;
                    }

                    renderTable(data.rows, page);
                    renderPagination(data.total, page, data.limit);
                })
                .catch(() => {
                    document.getElementById('hist-loading').style.display = 'none';
                    document.getElementById('hist-empty').style.display = 'flex';
                    showToast('Gagal memuat data.', 'error');
                });
        }

        // ── Render table ──────────────────────────────────────────────────────────
        function renderTable(rows, page) {
            const tbody = document.getElementById('hist-tbody');
            tbody.innerHTML = '';

            rows.forEach((row, idx) => {
                const no = (page - 1) * limitPerPage + idx + 1;
                const catColors = {
                    'MC': 'bg-blue-100 text-blue-700',
                    'SPM': 'bg-purple-100 text-purple-700',
                    'ASSEMBLING': 'bg-teal-100 text-teal-700',
                    'PAINTING': 'bg-pink-100 text-pink-700',
                    'TEST_RUNNING': 'bg-orange-100 text-orange-700',
                    'PACKING': 'bg-green-100 text-green-700',
                    'BOILER': 'bg-red-100 text-red-700',
                    'KOMPRESSOR': 'bg-cyan-100 text-cyan-700',
                };
                const catCls = catColors[row.category_key] || 'bg-slate-100 text-slate-600';

                const tr = document.createElement('tr');
                tr.className = 'fade-in';
                tr.style.animationDelay = `${idx * 15}ms`;
                tr.innerHTML = `
                <td class="text-center text-slate-400 font-bold text-xs">${no}</td>
                <td class="font-semibold text-slate-700">${row.check_date}</td>
                <td class="text-slate-600">${row.department}</td>
                <td class="text-slate-600">${row.line}</td>
                <td class="text-slate-500 text-center">${row.op || '-'}</td>
                <td class="font-medium text-slate-700 max-w-[150px] truncate" title="${row.machine_name}">${row.machine_name}</td>
                <td>${row.checker}</td>
                <td class="text-center"><span class="px-2 py-0.5 rounded-lg text-[10px] font-bold ${catCls}">${row.category_key}</span></td>
                <td class="text-center">
                    <div class="flex items-center justify-center gap-1 flex-wrap">
                        <span class="pill pill-v" title="OK (V)">${row.ok_count}</span>
                        <span class="pill pill-x" title="Problem (X)">${row.problem_count}</span>
                        <span class="pill pill-r" title="Repair (R)">${row.repair_count}</span>
                        <span class="pill pill-ro" title="Outsider (RO)">${row.outsider_count}</span>
                        <span class="pill pill-na" title="N/A">${row.na_count}</span>
                    </div>
                </td>
                <td class="text-slate-400 text-xs text-center">${row.submitted_at?.slice(0,16) ?? '-'}</td>
                <td class="text-center">
                    <button onclick="openDetail(${row.id}, '${esc(row.department)}', '${esc(row.line)}', '${esc(row.op)}', '${esc(row.machine_name)}', '${esc(row.checker)}', '${row.check_date}')"
                        class="w-8 h-8 rounded-lg bg-slate-100 hover:bg-rose-100 hover:text-rose-600 text-slate-500 transition-all inline-flex items-center justify-center">
                        <i class="fas fa-eye text-xs"></i>
                    </button>
                </td>`;
                tbody.appendChild(tr);
            });

            document.getElementById('hist-table').style.display = 'table';
            document.getElementById('result-label').textContent = ` ${totalRecords} submission ditemukan`;
        }

        // ── Pagination ────────────────────────────────────────────────────────────
        function renderPagination(total, currentPg, limit) {
            const totalPages = Math.ceil(total / limit);
            const pg = document.getElementById('pagination');
            const btns = document.getElementById('page-btns');
            const info = document.getElementById('page-info');

            if (totalPages <= 1) {
                pg.classList.add('hidden');
                return;
            }
            pg.classList.remove('hidden');

            const from = (currentPg - 1) * limit + 1;
            const to = Math.min(currentPg * limit, total);
            info.textContent = `Menampilkan ${from}–${to} dari ${total} data`;

            btns.innerHTML = '';
            const prev = document.createElement('button');
            prev.className = 'page-btn';
            prev.innerHTML = '<i class="fas fa-chevron-left text-xs"></i>';
            prev.disabled = currentPg === 1;
            prev.onclick = () => loadHistory(currentPg - 1);
            btns.appendChild(prev);

            for (let p = Math.max(1, currentPg - 2); p <= Math.min(totalPages, currentPg + 2); p++) {
                const btn = document.createElement('button');
                btn.className = 'page-btn' + (p === currentPg ? ' active' : '');
                btn.textContent = p;
                btn.onclick = ((pg) => () => loadHistory(pg))(p);
                btns.appendChild(btn);
            }

            const next = document.createElement('button');
            next.className = 'page-btn';
            next.innerHTML = '<i class="fas fa-chevron-right text-xs"></i>';
            next.disabled = currentPg === totalPages;
            next.onclick = () => loadHistory(currentPg + 1);
            btns.appendChild(next);
        }

        // ── Modal detail ──────────────────────────────────────────────────────────
        // PERBAIKAN: Menambahkan parameter op & machine ke dalam fungsi openDetail
        function openDetail(id, dept, line, op, machine, checker, date) {
            document.getElementById('modal-overlay').classList.add('open');
            document.getElementById('modal-title').textContent = `Detail Submission #${id}`;

            // PERBAIKAN: Menambahkan OP dan Nama Mesin di baris deskripsi subtitle modal
            document.getElementById('modal-subtitle').textContent = `${dept} — ${line} (OP: ${op || '-'}) | Mesin: ${machine} | Checker: ${checker} | ${date}`;

            document.getElementById('modal-tbody').innerHTML = '';
            document.getElementById('modal-loading').style.display = 'block';
            document.getElementById('modal-summary').textContent = '';

            fetch(`history_checksheet.php?ajax=detail&id=${id}`)
                .then(r => r.json())
                .then(items => {
                    document.getElementById('modal-loading').style.display = 'none';
                    const tbody = document.getElementById('modal-tbody');
                    const resultMap = {
                        'V': '<span class="badge badge-v">V — OK</span>',
                        'X': '<span class="badge badge-x">X — Problem</span>',
                        'R': '<span class="badge badge-r">R — Repair</span>',
                        'RO': '<span class="badge badge-ro">RO — Outsider</span>',
                        '-': '<span class="badge badge-na">— N/A</span>',
                    };
                    items.forEach(item => {
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                        <td class="text-center text-slate-400 font-bold text-xs">${item.no}</td>
                        <td class="font-medium text-slate-700">${item.part}</td>
                        <td class="text-slate-500 text-xs">${item.standard}</td>
                        <td class="text-center">${resultMap[item.result] || item.result}</td>
                        <td class="text-slate-400 text-xs italic">${item.note || '—'}</td>`;
                        tbody.appendChild(tr);
                    });
                    const ok = items.filter(i => i.result === 'V').length;
                    const pr = items.filter(i => i.result === 'X').length;
                    document.getElementById('modal-summary').textContent =
                        `${items.length} item total — ${ok} OK, ${pr} Problem`;
                });
        }

        function closeModal(e) {
            if (!e || e.target === document.getElementById('modal-overlay')) {
                document.getElementById('modal-overlay').classList.remove('open');
            }
        }

        // ── Export ────────────────────────────────────────────────────────────────
        function exportData() {
            const value = document.getElementById('inp-date').value;
            if (!value) {
                showToast('Pilih tanggal / bulan terlebih dahulu.', 'error');
                return;
            }
            const file = currentMode === 'daily' ?
                `export_checksheet_daily.php?tanggal=${encodeURIComponent(value)}` :
                `export_checksheet_monthly.php?bulan=${encodeURIComponent(value)}`;
            window.open(file, '_blank');
        }

        // ── Helpers ───────────────────────────────────────────────────────────────
        function showToast(msg, type = 'success') {
            const t = document.getElementById('toast');
            t.textContent = msg;
            t.className = `show ${type}`;
            setTimeout(() => t.classList.remove('show'), 3500);
        }

        function esc(str) {
            const d = document.createElement('div');
            d.textContent = str || '';
            return d.innerHTML;
        }
    </script>
</body>

</html>