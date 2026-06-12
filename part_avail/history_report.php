<?php
// history_report.php
session_start();
require_once __DIR__ . '/config.php';

$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// ─── AJAX: history list ────────────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'history') {
    error_reporting(0);
    @ini_set('display_errors', 0);
    header('Content-Type: application/json');

    $mode   = $_GET['mode']   ?? 'daily';
    $value  = $_GET['value']  ?? '';
    $page   = max(1, (int)($_GET['page']  ?? 1));
    $limit  = max(20, (int)($_GET['limit'] ?? 20));
    $offset = ($page - 1) * $limit;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';

    if ($value === '') {
        echo json_encode(['rows' => [], 'total' => 0]);
        exit;
    }

    if ($mode === 'daily') {
        $where = "WHERE DATE(r.report_date) = ?";
    } else {
        $where = "WHERE DATE_FORMAT(r.report_date, '%Y-%m') = ?";
    }
    $params = [$value];

    // Server-side search: cari di seluruh data, bukan hanya halaman aktif
    if ($search !== '') {
        $where .= " AND (r.machine_name LIKE ? OR r.department LIKE ? OR r.line LIKE ? OR r.op LIKE ? OR r.pic LIKE ? OR r.reported_by LIKE ? OR r.problem LIKE ?)";
        $s = "%{$search}%";
        $params = array_merge($params, [$s, $s, $s, $s, $s, $s, $s]);
    }

    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM e_reports r $where");
    $stmtCount->execute($params);
    $total = (int)$stmtCount->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT r.id, r.report_date, r.department, r.line, r.op,
               r.machine_name, r.machine_type,
               r.repair_start, r.repair_finish,
               r.reported_by, r.pic,
               r.problem, r.action,
               r.created_at
        FROM e_reports r
        $where
        ORDER BY r.created_at DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    echo json_encode(['rows' => $rows, 'total' => $total, 'limit' => $limit]);
    exit;
}

// ─── AJAX: detail single report ────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'detail') {
    header('Content-Type: application/json');
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) {
        echo json_encode(null);
        exit;
    }
    $stmt = $pdo->prepare("SELECT * FROM e_reports WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode($stmt->fetch());
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>History E-Report — Maintenance Hub</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #f1f5f9;
            height: 100vh;
            overflow: hidden;
        }

        /* ── Sidebar ──────────────────────────────────────────────────────────── */
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
            background: linear-gradient(135deg, #fb8b24, #d9721a);
            color: #fff;
            box-shadow: 0 4px 12px rgba(6, 182, 212, .35);
        }

        #sidebar .nav-item i {
            width: 18px;
            text-align: center;
            font-size: .9rem;
        }

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

        /* ── Main ─────────────────────────────────────────────────────────────── */
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

        .table-scroll-container {
            flex: 1 1 0;
            min-height: 0;
            overflow-y: auto;
        }

        /* ── Table ────────────────────────────────────────────────────────────── */
        .hist-table thead th {
            background: linear-gradient(135deg, #fb8b24, #d9721a);
            color: #fff;
            font-size: .68rem;
            font-weight: 700;
            letter-spacing: .05em;
            text-transform: uppercase;
            padding: 10px 14px;
            position: sticky;
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
            background: #fff7ed;
        }

        .hist-table tbody td {
            padding: 9px 14px;
            font-size: .77rem;
            color: #334155;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }

        /* ── Form field (filter bar) ──────────────────────────────────────────── */
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
            border-color: #fb8b24;
            box-shadow: 0 0 0 3px rgba(249, 115, 22, .12);
        }

        /* ── Skeleton ─────────────────────────────────────────────────────────── */
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

        /* ── Pagination ───────────────────────────────────────────────────────── */
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
            border-color: #fb8b24;
            color: #fb8b24;
        }

        .page-btn.active {
            background: #fb8b24;
            border-color: #fb8b24;
            color: #fff;
        }

        .page-btn:disabled {
            opacity: .4;
            cursor: not-allowed;
        }

        /* ── Toast ────────────────────────────────────────────────────────────── */
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

        .fade-in {
            animation: fadeInUp .2s ease forwards;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(6px)
            }

            to {
                opacity: 1;
                transform: translateY(0)
            }
        }

        /* ── Modal ────────────────────────────────────────────────────────────── */
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
            max-width: 760px;
            max-height: 88vh;
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

        /* detail field rows inside modal */
        .detail-row {
            display: grid;
            grid-template-columns: 160px 1fr;
            gap: 6px 12px;
            padding: 8px 0;
            border-bottom: 1px solid #f1f5f9;
            font-size: .8rem;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            font-weight: 700;
            color: #64748b;
            font-size: .7rem;
            text-transform: uppercase;
            letter-spacing: .04em;
            padding-top: 2px;
        }

        .detail-value {
            color: #1e293b;
            white-space: pre-wrap;
            line-height: 1.6;
        }
    </style>
</head>

<body>
    <aside id="sidebar" class="collapsed">
        <div class="brand">
            <div class="brand-icon-wrap">
                <div style="width:32px;height:32px;background:linear-gradient(135deg,#fb8b24,#d9721a);border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas fa-history" style="color:#fff;font-size:.9rem;"></i>
                </div>
                <div class="brand-text">
                    <div style="font-size:.85rem;font-weight:800;color:#f1f5f9;line-height:1.2;">E-Report</div>
                    <div style="font-size:.65rem;color:#64748b;font-weight:600;">Maintenance Hub</div>
                </div>
            </div>
        </div>

        <nav class="mt-4 flex-1">
            <a href="index.php" class="sidebar-back" title="Back to Hub">
                <i class="fas fa-arrow-left flex-shrink-0"></i>
                <span class="sb-label">Back to Hub</span>
            </a>
            <div style="height:1px;background:rgba(255,255,255,.07);margin:.4rem 6px;"></div>
            <div class="px-3 mb-2 menu-label">
                <span class="text-[10px] font-bold text-slate-600 uppercase tracking-widest">Menu</span>
            </div>
            <a href="dashboard_report.php" class="nav-item" title="E-Report">
                <i class="fas fa-file-medical-alt"></i>
                <span class="nav-label">E-Report</span>
            </a>
            <a href="history_report.php" class="nav-item active" title="History Report">
                <i class="fas fa-history"></i>
                <span class="nav-label">History Report</span>
            </a>
        </nav>

        <div id="sidebar-footer">
            <button id="sidebarToggle" onclick="toggleSidebar()" title="Toggle Sidebar">
                <i class="fas fa-chevron-right" id="sidebarToggleIcon"></i>
            </button>
        </div>
    </aside>

    <div id="main-content">

        <div class="topbar">
            <div class="flex items-center gap-2">
                <div class="w-7 h-7 rounded-lg flex items-center justify-center" style="background:#fff7ed;">
                    <i class="fas fa-history text-xs" style="color:#fb8b24;"></i>
                </div>
                <div>
                    <div class="text-sm font-bold text-slate-800">History E-Report</div>
                    <div class="text-[10px] text-slate-400 font-medium">Riwayat laporan kerusakan & perbaikan mesin</div>
                </div>
            </div>
        </div>

        <div class="p-6 space-y-4 flex-1 flex flex-col min-h-0">

            <!-- ── Filter bar ── -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 flex-shrink-0">
                <div class="flex flex-wrap gap-3 items-end">

                    <div>
                        <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-1.5">Mode</label>
                        <div class="flex rounded-xl border border-slate-200 overflow-hidden">
                            <button id="btn-mode-daily" onclick="setMode('daily')"
                                class="px-4 py-2 text-xs font-bold transition-all bg-[#fb8b24] text-white">
                                <i class="fas fa-calendar-day mr-1.5"></i>Harian
                            </button>
                            <button id="btn-mode-monthly" onclick="setMode('monthly')"
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
                        class="px-5 py-2.5 rounded-xl text-white text-sm font-bold transition-all flex items-center gap-2 shadow-sm"
                        style="background:#fb8b24;">
                        <i class="fas fa-search text-xs"></i> Cari
                    </button>

                    <div class="flex-1"></div>

                    <button onclick="exportData()"
                        class="px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold transition-all flex items-center gap-2 shadow-sm">
                        <i class="fas fa-file-excel"></i> Export Excel
                    </button>
                </div>
            </div>

            <!-- ── Table card ── -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden flex-1 flex flex-col min-h-0">

                <div class="px-4 py-2.5 border-b border-slate-100 flex items-center gap-2 flex-shrink-0">
                    <!-- Search -->
                    <div class="relative" style="max-width:280px;min-width:200px;">
                        <i class="fas fa-search absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none" style="font-size:10px;"></i>
                        <input type="text" id="inp-search"
                            placeholder="Cari mesin, dept, line, PIC…"
                            onkeydown="if(event.key === 'Enter') loadHistory(1)"
                            class="form-field pl-7 pr-7 text-xs w-full"
                            style="height:32px;padding-top:0;padding-bottom:0;">
                        <button id="btn-clear-search" onclick="clearSearch()" style="display:none;"
                            class="absolute right-2 top-1/2 -translate-y-1/2 w-4 h-4 rounded-full bg-slate-200 hover:bg-slate-300 flex items-center justify-center transition-all">
                            <i class="fas fa-times text-slate-500" style="font-size:8px;"></i>
                        </button>
                    </div>

                    <div class="flex items-center gap-1.5">
                        <span class="text-[11px] text-slate-400 font-medium">Show:</span>
                        <select id="inp-limit" onchange="changeLimit()" class="form-field text-xs cursor-pointer bg-slate-50" style="height:32px; padding-top:0; padding-bottom:0; min-width:70px;">
                            <option value="20" selected>20</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                            <option value="200">200</option>
                            <option value="500">500</option>
                        </select>
                    </div>

                    <div class="flex-1"></div>
                    <span id="result-label" class="text-[11px] text-slate-400 font-medium"></span>
                    <span id="search-label" style="display:none;" class="text-[11px] font-bold px-2 py-0.5 rounded-full" style="background:#fff7ed;color:#fb8b24;"></span>
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
                                <th class="text-center">OP</th>
                                <th>Mesin</th>
                                <th>Repair Start</th>
                                <th>Repair Finish</th>
                                <th>Submitted At</th>
                                <th class="text-center w-16">Detail</th>
                            </tr>
                        </thead>
                        <tbody id="hist-tbody"></tbody>
                    </table>

                    <div id="hist-empty" class="flex flex-col items-center justify-center py-16 text-slate-400">
                        <i class="fas fa-folder-open text-5xl mb-3 opacity-30"></i>
                        <p class="font-bold text-sm">Pilih tanggal / bulan lalu klik Cari</p>
                        <p class="text-xs mt-1">Data history E-Report akan tampil di sini</p>
                    </div>

                    <div id="hist-loading" style="display:none;" class="p-5 space-y-3">
                        <?php for ($i = 0; $i < 6; $i++): ?>
                            <div class="flex gap-3 items-center">
                                <div class="skeleton w-6 h-5"></div>
                                <div class="skeleton w-20 h-5"></div>
                                <div class="skeleton flex-1 h-5"></div>
                                <div class="skeleton w-24 h-5"></div>
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

    <!-- ── Modal Detail ── -->
    <div id="modal-overlay" onclick="closeModal(event)">
        <div id="modal-box">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between flex-shrink-0">
                <div>
                    <div class="text-sm font-bold text-slate-800" id="modal-title">Detail E-Report</div>
                    <div class="text-[11px] text-slate-400 font-medium mt-0.5" id="modal-subtitle"></div>
                </div>
                <button onclick="closeModal()" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 flex items-center justify-center transition-all">
                    <i class="fas fa-times text-slate-500 text-xs"></i>
                </button>
            </div>

            <div class="overflow-auto flex-1 px-6 py-4" id="modal-body">
                <div id="modal-loading" class="space-y-3">
                    <?php for ($i = 0; $i < 6; $i++): ?>
                        <div class="flex gap-3">
                            <div class="skeleton w-32 h-4"></div>
                            <div class="skeleton flex-1 h-4"></div>
                        </div>
                    <?php endfor; ?>
                </div>
                <div id="modal-content" style="display:none;"></div>
            </div>

            <div class="px-6 py-3.5 border-t border-slate-100 bg-slate-50 rounded-b-2xl flex items-center justify-between flex-shrink-0">
                <span id="modal-meta" class="text-[11px] text-slate-400 font-medium"></span>
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
        let searchTimeout = null;

        // ── Sidebar ───────────────────────────────────────────────────────────────────
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const main = document.getElementById('main-content');
            const icon = document.getElementById('sidebarToggleIcon');
            const isCollapsed = sidebar.classList.toggle('collapsed');
            main.classList.toggle('expanded', !isCollapsed);
            icon.className = isCollapsed ? 'fas fa-chevron-right' : 'fas fa-chevron-left';
            sessionStorage.setItem('report_sidebar', isCollapsed ? 'collapsed' : 'expanded');
        }

        document.addEventListener('DOMContentLoaded', () => {
            const sidebar = document.getElementById('sidebar');
            const main = document.getElementById('main-content');
            const icon = document.getElementById('sidebarToggleIcon');
            const state = sessionStorage.getItem('report_sidebar');
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

            // Debounce search: ketik → tunggu 400ms → fetch server
            document.getElementById('inp-search').addEventListener('input', function() {
                clearTimeout(searchTimeout);
                const q = this.value.trim();
                document.getElementById('btn-clear-search').style.display = q ? 'flex' : 'none';
                searchTimeout = setTimeout(() => loadHistory(1), 400);
            });
        });

        // ── Mode ──────────────────────────────────────────────────────────────────────
        function setMode(mode) {
            currentMode = mode;
            const daily = document.getElementById('btn-mode-daily');
            const month = document.getElementById('btn-mode-monthly');
            const inp = document.getElementById('inp-date');
            const lbl = document.getElementById('picker-label');
            const activeClass = 'px-4 py-2 text-xs font-bold transition-all bg-[#fb8b24] text-white';
            const inactiveClass = 'px-4 py-2 text-xs font-bold transition-all bg-white text-slate-500 hover:bg-slate-50';
            if (mode === 'daily') {
                daily.className = activeClass;
                month.className = inactiveClass;
                inp.type = 'date';
                lbl.textContent = 'Tanggal';
                inp.value = new Date().toISOString().split('T')[0];
            } else {
                daily.className = inactiveClass;
                month.className = activeClass;
                inp.type = 'month';
                lbl.textContent = 'Bulan';
                inp.value = new Date().toISOString().slice(0, 7);
            }
        }

        // ── Change limit ──────────────────────────────────────────────────────────────
        function changeLimit() {
            limitPerPage = parseInt(document.getElementById('inp-limit').value) || 20;
            loadHistory(1);
        }

        // ── Load history (server-side search + pagination) ────────────────────────────
        function loadHistory(page = 1) {
            const value = document.getElementById('inp-date').value;
            const searchQuery = document.getElementById('inp-search').value.trim();
            const limitSelect = document.getElementById('inp-limit').value;

            if (!value) {
                showToast('Pilih tanggal / bulan terlebih dahulu.', 'error');
                return;
            }
            currentPage = page;
            limitPerPage = parseInt(limitSelect) || 20;

            document.getElementById('hist-table').style.display = 'none';
            document.getElementById('hist-empty').style.display = 'none';
            document.getElementById('hist-loading').style.display = 'block';
            document.getElementById('pagination').classList.add('hidden');
            document.getElementById('result-label').textContent = '';

            fetch(`history_report.php?ajax=history&mode=${currentMode}&value=${encodeURIComponent(value)}&page=${page}&limit=${limitPerPage}&search=${encodeURIComponent(searchQuery)}`)
                .then(r => r.json())
                .then(data => {
                    document.getElementById('hist-loading').style.display = 'none';
                    totalRecords = data.total;

                    if (!data.rows || data.rows.length === 0) {
                        document.getElementById('hist-empty').style.display = 'flex';
                        if (searchQuery !== '') {
                            document.getElementById('hist-empty').innerHTML = `
                            <i class="fas fa-search-minus text-5xl mb-3 opacity-30"></i>
                            <p class="font-bold text-sm">Tidak ada data yang cocok dengan "${esc(searchQuery)}"</p>
                            <p class="text-xs mt-1">Coba kata kunci pencarian yang lain</p>`;
                        } else {
                            document.getElementById('hist-empty').innerHTML = `
                            <i class="fas fa-inbox text-5xl mb-3 opacity-30"></i>
                            <p class="font-bold text-sm">Tidak ada data untuk periode ini</p>
                            <p class="text-xs mt-1">Coba pilih tanggal / bulan yang lain</p>`;
                        }
                        document.getElementById('result-label').textContent = '0 laporan ditemukan';
                        return;
                    }

                    renderTable(data.rows, page);
                    renderPagination(data.total, page, limitPerPage);
                    document.getElementById('result-label').textContent = `Total ${data.total} laporan`;

                    const searchLabel = document.getElementById('search-label');
                    if (searchQuery) {
                        searchLabel.style.display = 'inline-flex';
                        searchLabel.style.background = '#fff7ed';
                        searchLabel.style.color = '#fb8b24';
                        searchLabel.textContent = 'Filtered';
                    } else {
                        searchLabel.style.display = 'none';
                    }
                })
                .catch(() => {
                    document.getElementById('hist-loading').style.display = 'none';
                    document.getElementById('hist-empty').style.display = 'flex';
                    showToast('Gagal memuat data.', 'error');
                });
        }

        // ── Render table ──────────────────────────────────────────────────────────────
        function renderTable(rows, page) {
            const tbody = document.getElementById('hist-tbody');
            tbody.innerHTML = '';

            rows.forEach((row, idx) => {
                const no = (page - 1) * limitPerPage + idx + 1;
                const tr = document.createElement('tr');
                tr.className = 'fade-in';
                tr.style.animationDelay = `${idx * 15}ms`;

                const startFmt = row.repair_start ? row.repair_start.slice(0, 16) : '—';
                const finishFmt = row.repair_finish ? row.repair_finish.slice(0, 16) : '<span class="text-slate-300">—</span>';
                const submittedFmt = row.created_at ? row.created_at.slice(0, 16) : '—';

                tr.innerHTML = `
        <td class="text-center text-slate-400 font-bold text-xs">${no}</td>
        <td class="font-semibold text-slate-700 whitespace-nowrap">${row.report_date?.slice(0,10) ?? '—'}</td>
        <td class="text-slate-600 max-w-[120px] truncate" title="${esc(row.department)}">${esc(row.department)}</td>
        <td class="text-slate-600">${esc(row.line)}</td>
        <td class="text-center text-slate-500">${row.op || '—'}</td>
        <td class="font-medium text-slate-700 max-w-[150px] truncate" title="${esc(row.machine_name)}">${esc(row.machine_name)}</td>
        <td class="text-xs text-slate-600 whitespace-nowrap">${startFmt}</td>
        <td class="text-xs text-slate-600 whitespace-nowrap">${finishFmt}</td>
        <td class="text-xs text-slate-500 whitespace-nowrap">${submittedFmt}</td>
        <td class="text-center">
            <button onclick="openDetail(${row.id})"
                class="w-8 h-8 rounded-lg bg-slate-100 transition-all inline-flex items-center justify-center"
                onmouseenter="this.style.background='#fb8b24';this.querySelector('i').style.color='#fff'"
                onmouseleave="this.style.background='';this.querySelector('i').style.color=''">
                <i class="fas fa-eye text-xs text-slate-500"></i>
            </button>
        </td>`;

                tbody.appendChild(tr);
            });

            document.getElementById('hist-table').style.display = 'table';
            document.getElementById('showing-label').textContent =
                `Menampilkan ${(page-1)*limitPerPage+1}–${Math.min(page*limitPerPage, totalRecords)} dari ${totalRecords}`;
        }

        // ── Search helpers ────────────────────────────────────────────────────────────
        function clearSearch() {
            document.getElementById('inp-search').value = '';
            document.getElementById('btn-clear-search').style.display = 'none';
            loadHistory(1);
        }

        // ── Export Excel ──────────────────────────────────────────────────────────────
        function exportData() {
            const value = document.getElementById('inp-date').value;
            if (!value) {
                alert('Pilih tanggal / bulan terlebih dahulu, lalu klik Cari.');
                return;
            }
            const file = currentMode === 'daily' ?
                `export_history_report.php?mode=daily&tanggal=${encodeURIComponent(value)}` :
                `export_history_report.php?mode=monthly&bulan=${encodeURIComponent(value)}`;
            window.open(file, '_blank');
        }

        // ── Pagination (windowing seperti history_checksheet) ─────────────────────────
        function renderPagination(total, currentPg, limit) {
            const totalPages = Math.ceil(total / limit);
            const paginEl = document.getElementById('pagination');
            const btns = document.getElementById('page-btns');
            const info = document.getElementById('page-info');

            if (totalPages <= 1) {
                paginEl.classList.add('hidden');
                return;
            }
            paginEl.classList.remove('hidden');

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

            let startPage = Math.max(1, currentPg - 2);
            let endPage = Math.min(totalPages, currentPg + 2);

            if (startPage > 1) {
                const firstBtn = document.createElement('button');
                firstBtn.className = 'page-btn';
                firstBtn.textContent = '1';
                firstBtn.onclick = () => loadHistory(1);
                btns.appendChild(firstBtn);
                if (startPage > 2) {
                    const dots = document.createElement('span');
                    dots.className = 'px-1 text-slate-400 text-xs self-center';
                    dots.textContent = '...';
                    btns.appendChild(dots);
                }
            }

            for (let p = startPage; p <= endPage; p++) {
                const btn = document.createElement('button');
                btn.className = 'page-btn' + (p === currentPg ? ' active' : '');
                btn.textContent = p;
                btn.onclick = ((pg) => () => loadHistory(pg))(p);
                btns.appendChild(btn);
            }

            if (endPage < totalPages) {
                if (endPage < totalPages - 1) {
                    const dots = document.createElement('span');
                    dots.className = 'px-1 text-slate-400 text-xs self-center';
                    dots.textContent = '...';
                    btns.appendChild(dots);
                }
                const lastBtn = document.createElement('button');
                lastBtn.className = 'page-btn';
                lastBtn.textContent = totalPages;
                lastBtn.onclick = () => loadHistory(totalPages);
                btns.appendChild(lastBtn);
            }

            const next = document.createElement('button');
            next.className = 'page-btn';
            next.innerHTML = '<i class="fas fa-chevron-right text-xs"></i>';
            next.disabled = currentPg === totalPages;
            next.onclick = () => loadHistory(currentPg + 1);
            btns.appendChild(next);
        }

        // ── Modal detail ──────────────────────────────────────────────────────────────
        function openDetail(id) {
            document.getElementById('modal-overlay').classList.add('open');
            document.getElementById('modal-loading').style.display = 'block';
            document.getElementById('modal-content').style.display = 'none';
            document.getElementById('modal-title').textContent = `Detail E-Report #${id}`;
            document.getElementById('modal-subtitle').textContent = '';
            document.getElementById('modal-meta').textContent = '';

            fetch(`history_report.php?ajax=detail&id=${id}`)
                .then(r => r.json())
                .then(r => {
                    if (!r) {
                        showToast('Data tidak ditemukan.', 'error');
                        closeModal();
                        return;
                    }
                    document.getElementById('modal-loading').style.display = 'none';
                    document.getElementById('modal-subtitle').textContent =
                        `${r.department} — ${r.line} | OP: ${r.op||'—'} | ${r.machine_name}`;

                    const startFmt = r.repair_start ? r.repair_start.slice(0, 16) : '—';
                    const finishFmt = r.repair_finish ? r.repair_finish.slice(0, 16) : '—';

                    // Format durasi dari menit → HH:MM:00
                    let durationFmt = '—';
                    if (r.duration_minutes !== null && r.duration_minutes !== undefined && r.duration_minutes !== '') {
                        const dm = parseInt(r.duration_minutes);
                        if (!isNaN(dm) && dm > 0) {
                            const hh = String(Math.floor(dm / 60)).padStart(2, '0');
                            const mm = String(dm % 60).padStart(2, '0');
                            durationFmt = `${hh}:${mm}:00`;
                        }
                    }

                    const fields = [
                        ['Tanggal Laporan', r.report_date?.slice(0, 10) ?? '—'],
                        ['Department', r.department],
                        ['Line', r.line],
                        ['OP', r.op || '—'],
                        ['Nama Mesin', r.machine_name],
                        ['Machine Type', r.machine_type || '—'],
                        ['Repair Start', startFmt],
                        ['Repair Finish', finishFmt],
                        ['Durasi Perbaikan', durationFmt],
                        ['Reported By', r.reported_by],
                        ['PIC / Teknisi', r.pic],
                        ['Problem / Alarm', r.problem],
                        ['Action / Perbaikan', r.action],
                        ['Submitted At', r.created_at?.slice(0, 16) ?? '—'],
                    ];

                    const content = document.getElementById('modal-content');
                    content.innerHTML = fields.map(([label, val]) => `
                <div class="detail-row">
                    <div class="detail-label">${label}</div>
                    <div class="detail-value">${esc(String(val ?? '—'))}</div>
                </div>`).join('');
                    content.style.display = 'block';
                    document.getElementById('modal-meta').textContent = '';
                })
                .catch(() => {
                    showToast('Gagal memuat detail.', 'error');
                    closeModal();
                });
        }

        function closeModal(e) {
            if (e && e.target !== document.getElementById('modal-overlay')) return;
            document.getElementById('modal-overlay').classList.remove('open');
        }

        // ── Toast ─────────────────────────────────────────────────────────────────────
        function showToast(msg, type = 'success') {
            const t = document.getElementById('toast');
            t.className = type;
            t.innerHTML = `<i class="fas fa-${type==='success'?'check-circle':'exclamation-circle'}"></i> ${msg}`;
            t.classList.add('show');
            clearTimeout(t._timer);
            t._timer = setTimeout(() => t.classList.remove('show'), 3500);
        }

        function esc(str) {
            const d = document.createElement('div');
            d.textContent = str || '';
            return d.innerHTML;
        }
    </script>
</body>

</html>