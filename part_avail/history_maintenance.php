<?php
include 'config.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'user') {
    header('Location: login_user.php');
    exit;
}

// Ambil nama user yang sedang login
$stmtUser = $pdo->prepare("SELECT username FROM users WHERE id = ?");
$stmtUser->execute([$_SESSION['user_id']]);
$currentUser = $stmtUser->fetch(PDO::FETCH_ASSOC);
$displayName = $currentUser['username'] ?? 'User';

// ── Active tab ─────────────────────────────────────────────────────────────────
$activeTab = ($_GET['tab'] ?? 'predictive') === 'preventive' ? 'preventive' : 'predictive';

// ── Filter params ──────────────────────────────────────────────────────────────
$search      = trim($_GET['search']      ?? '');
$filterDep   = trim($_GET['department']  ?? '');
$filterMode  = ($_GET['filter_mode']     ?? 'date') === 'month' ? 'month' : 'date';
$filterDate  = trim($_GET['date']        ?? '');
$filterMonth = trim($_GET['month']       ?? '');

// ── Query helper — shared WHERE builder ───────────────────────────────────────
function buildWhere(string $search, string $filterDep, string $filterMode, string $filterDate, string $filterMonth, string $alias = 'h'): array
{
    $where  = [];
    $params = [];
    if ($search !== '') {
        $where[]  = "({$alias}.machine_name LIKE ? OR {$alias}.maintenance_point LIKE ? OR {$alias}.department LIKE ? OR {$alias}.line LIKE ?)";
        $like     = '%' . $search . '%';
        $params   = array_merge($params, [$like, $like, $like, $like]);
    }
    if ($filterDep !== '') {
        $where[]  = "{$alias}.department = ?";
        $params[] = $filterDep;
    }
    if ($filterMode === 'month' && $filterMonth !== '') {
        $where[]  = "DATE_FORMAT({$alias}.reported_at, '%Y-%m') = ?";
        $params[] = $filterMonth;
    } elseif ($filterMode === 'date' && $filterDate !== '') {
        $where[]  = "DATE({$alias}.reported_at) = ?";
        $params[] = $filterDate;
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    return [$whereSql, $params];
}

// ── Query history_maintenance (Predictive) ─────────────────────────────────────
[$whereSqlPred, $paramsPred] = buildWhere($search, $filterDep, $filterMode, $filterDate, $filterMonth, 'h');
$stmtPred = $pdo->prepare("
    SELECT
        h.id, h.schedule_id, h.department, h.line, h.operation_process,
        h.machine_name, h.process_machine, h.name_unit, h.maintenance_point,
        h.change_date_plan, h.note, h.photo_path, h.reported_by, h.reported_at,
        h.teknisi,
        u.username AS reported_by_name
    FROM history_maintenance h
    LEFT JOIN users u ON u.id = h.reported_by
    {$whereSqlPred}
    ORDER BY h.reported_at DESC
");
$stmtPred->execute($paramsPred);
$historiesPred = $stmtPred->fetchAll(PDO::FETCH_ASSOC);

// ── Query history_preventive (Preventive) ─────────────────────────────────────
$historiesPrev = [];
try {
    [$whereSqlPrev, $paramsPrev] = buildWhere($search, $filterDep, $filterMode, $filterDate, $filterMonth, 'h');
    $stmtPrev = $pdo->prepare("
        SELECT
            h.id, h.schedule_id, h.department, h.line, h.operation_process,
            h.machine_name, h.process_machine, h.name_unit, h.maintenance_point,
            h.change_date_plan, h.note, h.photo_path, h.reported_by, h.reported_at,
            h.teknisi, u.username AS reported_by_name
        FROM history_preventive h
        LEFT JOIN users u ON u.id = h.reported_by
        {$whereSqlPrev}
        ORDER BY h.reported_at DESC
    ");
    $stmtPrev->execute($paramsPrev);
    $historiesPrev = $stmtPrev->fetchAll(PDO::FETCH_ASSOC);
} catch (\Exception $e) {
    error_log('[History] history_preventive table missing: ' . $e->getMessage());
}

// ── Daftar department untuk filter dropdown (gabungan keduanya) ───────────────
$deptListPred = $pdo->query("SELECT DISTINCT department FROM history_maintenance WHERE department != '' ORDER BY department ASC")
    ->fetchAll(PDO::FETCH_COLUMN);
$deptListPrev = [];
try {
    $deptListPrev = $pdo->query("SELECT DISTINCT department FROM history_preventive WHERE department != '' ORDER BY department ASC")
        ->fetchAll(PDO::FETCH_COLUMN);
} catch (\Exception $e) {
}

// ── Detail modal AJAX ─────────────────────────────────────────────────────────
if (isset($_GET['get_detail'])) {
    $table = ($_GET['type'] ?? 'predictive') === 'preventive' ? 'history_preventive' : 'history_maintenance';
    $d = $pdo->prepare("
        SELECT h.*, h.teknisi, u.username AS reported_by_name
        FROM {$table} h
        LEFT JOIN users u ON u.id = h.reported_by
        WHERE h.id = ?
    ");
    $d->execute([(int)$_GET['get_detail']]);
    echo json_encode($d->fetch(PDO::FETCH_ASSOC));
    exit;
}

// ── Export CSV ─────────────────────────────────────────────────────────────────
if (isset($_GET['export'])) {
    $isPrev   = ($_GET['tab'] ?? '') === 'preventive';
    $histories = $isPrev ? $historiesPrev : $historiesPred;
    $typeName  = $isPrev ? 'preventive' : 'predictive';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="history_' . $typeName . '_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM UTF-8
    $out = fopen('php://output', 'w');
    fputcsv($out, ['No', 'Department', 'Line', 'Operation Process', 'Machine Name', 'Process Machine', 'Unit Name', 'Maintenance Point', 'Change Date Plan', 'Note', 'Technician', 'Reported At']);
    foreach ($histories as $i => $h) {
        fputcsv($out, [
            $i + 1,
            $h['department'],
            $h['line'],
            $h['operation_process'],
            $h['machine_name'],
            $h['process_machine'],
            $h['name_unit'],
            $h['maintenance_point'],
            $h['change_date_plan'],
            $h['note'],
            $h['teknisi'] ?? '-',
            $h['reported_at'],
        ]);
    }
    fclose($out);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance History</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #f8fafc;
        }

        .fade-in {
            animation: fadeIn .25s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(6px);
            }

            to {
                opacity: 1;
                transform: none;
            }
        }

        tr.hist-row:hover td {
            background: #f8fafc;
        }
    </style>
</head>

<body class="p-4 lg:p-8 min-h-screen">

    <div class="max-w-7xl mx-auto">

        <!-- HEADER -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
            <div>
                <a href="index.php"
                    class="text-amber-600 font-bold text-sm flex items-center gap-2 mb-2 hover:gap-3 transition-all w-fit">
                    <i class="fas fa-arrow-left"></i> Back to Hub
                </a>
                <h1 class="text-2xl font-extrabold text-slate-900">
                    <i class="fas fa-history text-amber-500 mr-2"></i>Maintenance History
                </h1>
                <p class="text-slate-500 text-sm mt-0.5">Log all completed maintenance reports.</p>
            </div>
            <div class="flex items-center gap-3 flex-wrap">
                <div class="flex items-center gap-2 bg-slate-100 px-4 py-2 rounded-xl">
                    <div class="w-7 h-7 rounded-full bg-amber-500 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-user text-white text-xs"></i>
                    </div>
                    <span class="text-sm font-bold text-slate-700"><?= htmlspecialchars($displayName) ?></span>
                </div>
                <a href="logout_user.php" onclick="return confirm('Apakah Anda yakin ingin keluar?')"
                    class="bg-red-100 hover:bg-red-200 text-red-600 px-5 py-2.5 rounded-xl font-bold transition-all flex items-center gap-2 text-sm">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>

        <!-- NAVBAR TABS — sama persis dengan dashboard_user -->
        <div class="mb-6">
            <div class="relative bg-slate-100 rounded-2xl p-1.5 flex gap-1 shadow-inner">
                <div id="tabIndicator"
                    class="absolute top-1.5 left-1.5 bottom-1.5 rounded-xl transition-all duration-300 ease-in-out shadow-md pointer-events-none"
                    style="background:<?= $activeTab === 'preventive' ? 'linear-gradient(135deg,#ea580c,#f97316)' : 'linear-gradient(135deg,#f59e0b,#d97706)' ?>;width:calc(50% - 4px);transform:<?= $activeTab === 'preventive' ? 'translateX(calc(100% + 4px))' : 'translateX(0)' ?>;">
                </div>
                <button id="tabPredictive" onclick="switchTab('predictive')"
                    class="relative z-10 flex-1 flex items-center justify-center gap-2 py-3.5 px-6 font-bold text-sm rounded-xl transition-all duration-300 <?= $activeTab === 'predictive' ? 'text-white' : 'text-slate-500' ?>">
                    <i class="fas fa-chart-line text-base"></i>
                    <span>Predictive Maintenance</span>
                    <span id="badgePredictive"
                        class="ml-1 text-[10px] font-black px-2 py-0.5 rounded-full <?= $activeTab === 'predictive' ? 'bg-white/20 text-white' : 'bg-slate-300/60 text-slate-600' ?>">
                        <?= count($historiesPred) ?>
                    </span>
                </button>
                <button id="tabPreventive" onclick="switchTab('preventive')"
                    class="relative z-10 flex-1 flex items-center justify-center gap-2 py-3.5 px-6 font-bold text-sm rounded-xl transition-all duration-300 <?= $activeTab === 'preventive' ? 'text-white' : 'text-slate-500' ?>">
                    <i class="fas fa-shield-halved text-base"></i>
                    <span>Preventive Maintenance</span>
                    <span id="badgePreventive"
                        class="ml-1 text-[10px] font-black px-2 py-0.5 rounded-full <?= $activeTab === 'preventive' ? 'bg-white/20 text-white' : 'bg-slate-300/60 text-slate-600' ?>">
                        <?= count($historiesPrev) ?>
                    </span>
                </button>
            </div>
        </div>

        <!-- ACTION BAR (export + count) — berubah per tab -->
        <div class="flex items-center justify-between gap-3 flex-wrap mb-4">
            <div>
                <span id="labelPredictive" class="bg-blue-50 border border-blue-100 text-amber-700 font-bold px-4 py-2 rounded-xl text-sm <?= $activeTab === 'preventive' ? 'hidden' : '' ?>">
                    <?= count($historiesPred) ?> laporan predictive
                </span>
                <span id="labelPreventive" class="bg-teal-50 border border-teal-100 text-orange-700 font-bold px-4 py-2 rounded-xl text-sm <?= $activeTab === 'predictive' ? 'hidden' : '' ?>">
                    <?= count($historiesPrev) ?> laporan preventive
                </span>
            </div>
            <div class="flex gap-2">
                <button onclick="openExportModal()"
                    class="bg-emerald-600 hover:bg-emerald-700 text-white px-5 py-2.5 rounded-xl text-sm font-bold flex items-center gap-2 shadow-lg shadow-emerald-200 transition-all">
                    <i class="fas fa-file-excel"></i> Export Excel
                </button>
            </div>
        </div>

        <!-- FILTER BAR — shared untuk kedua tab -->
        <form method="GET" class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm mb-6 flex flex-wrap gap-3 items-center">
            <input type="hidden" name="tab" id="formTab" value="<?= htmlspecialchars($activeTab) ?>">
            <!-- Search -->
            <div class="relative flex-1 min-w-[200px]">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                    placeholder="Cari mesin, point, department..."
                    class="w-full pl-10 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <!-- Filter Department — predictive -->
            <div id="deptFilterPred" class="<?= $activeTab === 'preventive' ? 'hidden' : '' ?>">
                <select name="department"
                    class="bg-slate-50 border border-slate-200 text-slate-600 text-sm rounded-xl py-2.5 px-3 focus:outline-none focus:ring-2 focus:ring-blue-500 min-w-[180px]">
                    <option value="">Semua Department</option>
                    <?php foreach ($deptListPred as $dep): ?>
                        <option value="<?= htmlspecialchars($dep) ?>" <?= ($filterDep === $dep && $activeTab === 'predictive') ? 'selected' : '' ?>>
                            <?= htmlspecialchars($dep) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Filter Department — preventive -->
            <div id="deptFilterPrev" class="<?= $activeTab === 'predictive' ? 'hidden' : '' ?>">
                <select name="department_prev"
                    class="bg-slate-50 border border-slate-200 text-slate-600 text-sm rounded-xl py-2.5 px-3 focus:outline-none focus:ring-2 focus:ring-teal-500 min-w-[180px]">
                    <option value="">Semua Department</option>
                    <?php foreach ($deptListPrev as $dep): ?>
                        <option value="<?= htmlspecialchars($dep) ?>" <?= ($filterDep === $dep && $activeTab === 'preventive') ? 'selected' : '' ?>>
                            <?= htmlspecialchars($dep) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Filter Tanggal / Bulan (unified) -->
            <input type="hidden" name="filter_mode" id="filterModeInput" value="<?= htmlspecialchars($filterMode) ?>">
            <div class="flex items-center gap-0 bg-slate-100 rounded-xl p-1 text-xs font-bold flex-shrink-0">
                <button type="button" id="toggleDate"
                    onclick="setFilterMode('date')"
                    class="px-3 py-1.5 rounded-lg transition-all <?= $filterMode === 'date' ? 'bg-white shadow text-slate-800' : 'text-slate-500' ?>">
                    <i class="fas fa-calendar-day mr-1"></i>Tanggal
                </button>
                <button type="button" id="toggleMonth"
                    onclick="setFilterMode('month')"
                    class="px-3 py-1.5 rounded-lg transition-all <?= $filterMode === 'month' ? 'bg-white shadow text-slate-800' : 'text-slate-500' ?>">
                    <i class="fas fa-calendar-alt mr-1"></i>Bulan
                </button>
            </div>
            <div id="inputWrapDate" class="<?= $filterMode === 'month' ? 'hidden' : '' ?>">
                <input type="date" name="date" id="inputDate" value="<?= htmlspecialchars($filterDate) ?>"
                    class="bg-slate-50 border border-slate-200 text-slate-600 text-sm rounded-xl py-2.5 px-3 focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div id="inputWrapMonth" class="<?= $filterMode === 'date' ? 'hidden' : '' ?>">
                <input type="month" name="month" id="inputMonth" value="<?= htmlspecialchars($filterMonth) ?>"
                    class="bg-slate-50 border border-slate-200 text-slate-600 text-sm rounded-xl py-2.5 px-3 focus:outline-none focus:ring-2 focus:ring-amber-400">
            </div>
            <!-- Tombol -->
            <button type="submit"
                class="bg-amber-600 hover:bg-amber-700 text-white px-5 py-2.5 rounded-xl text-sm font-bold transition-all">
                <i class="fas fa-filter mr-1"></i> Filter
            </button>
            <?php if ($search || $filterDep || $filterDate || $filterMonth): ?>
                <a href="?tab=<?= $activeTab ?>"
                    class="bg-slate-100 hover:bg-slate-200 text-slate-600 px-4 py-2.5 rounded-xl text-sm font-bold transition-all">
                    <i class="fas fa-times mr-1"></i> Reset
                </a>
            <?php endif; ?>
        </form>

        <!-- ═══════════════════════════════════════════════════════════════
         TAB: PREDICTIVE
    ════════════════════════════════════════════════════════════════ -->
        <div id="predictiveTab" class="<?= $activeTab === 'preventive' ? 'hidden' : '' ?>">
            <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="overflow-x-auto" style="max-height:600px;overflow-y:auto;">
                    <table class="w-full text-left text-sm border-collapse">
                        <thead class="bg-slate-800 text-white" style="background:linear-gradient(135deg, #b45309, #f59e0b);position:sticky;top:0;z-index:10;">
                            <tr>
                                <th class="px-5 py-4 font-semibold text-[11px] uppercase tracking-widest whitespace-nowrap">No</th>
                                <th class="px-5 py-4 font-semibold text-[11px] uppercase tracking-widest whitespace-nowrap">Machine Info</th>
                                <th class="px-5 py-4 font-semibold text-[11px] uppercase tracking-widest whitespace-nowrap">Maintenance Point</th>
                                <th class="px-5 py-4 font-semibold text-[11px] uppercase tracking-widest whitespace-nowrap text-center">Change Date Plan</th>
                                <th class="px-5 py-4 font-semibold text-[11px] uppercase tracking-widest whitespace-nowrap">Note</th>
                                <th class="px-5 py-4 font-semibold text-[11px] uppercase tracking-widest whitespace-nowrap text-center">Reported At</th>
                                <th class="px-5 py-4 font-semibold text-[11px] uppercase tracking-widest whitespace-nowrap text-center">Status</th>
                                <th class="px-5 py-4 font-semibold text-[11px] uppercase tracking-widest whitespace-nowrap text-center">Detail</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (empty($historiesPred)): ?>
                                <tr>
                                    <td colspan="8" class="px-6 py-16 text-center text-slate-400">
                                        <i class="fas fa-inbox text-4xl mb-3 block text-slate-200"></i>
                                        <p class="font-medium">Belum ada history predictive maintenance<?= ($search || $filterDep || $filterDate) ? ' yang sesuai filter.' : '.' ?></p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($historiesPred as $i => $h): ?>
                                    <?php
                                    $techName   = $h['teknisi'] ?? '-';
                                    $reportedBy = $h['reported_by_name'] ?? ('User #' . $h['reported_by']);
                                    $reportedAt = $h['reported_at'] ? date('d M Y H:i', strtotime($h['reported_at'])) : '-';
                                    $planDate   = $h['change_date_plan'] ? date('d M Y', strtotime($h['change_date_plan'])) : '-';
                                    $noteShort  = mb_strlen($h['note'] ?? '') > 60 ? mb_substr($h['note'], 0, 60) . '…' : ($h['note'] ?? '-');
                                    $rowMonth   = $h['reported_at'] ? date('Y-m', strtotime($h['reported_at'])) : '';
                                    ?>
                                    <tr class="hist-row hist-pred-row transition-colors fade-in" data-month="<?= $rowMonth ?>">
                                        <td class="px-5 py-3 text-slate-400 font-mono text-xs"><?= $i + 1 ?></td>
                                        <td class="px-5 py-3">
                                            <div class="font-bold text-slate-800 whitespace-nowrap text-sm"><?= htmlspecialchars($h['machine_name'] ?? '-') ?></div>
                                            <div class="text-xs text-slate-500 mt-0.5"><?= htmlspecialchars($h['department'] ?? '') ?><?= $h['line'] ? ' | ' . htmlspecialchars($h['line']) : '' ?></div>
                                            <?php if ($h['name_unit']): ?><div class="text-xs text-slate-400 italic mt-0.5"><?= htmlspecialchars($h['name_unit']) ?></div><?php endif; ?>
                                        </td>
                                        <td class="px-5 py-3 text-slate-600 text-sm max-w-[180px]"><?= htmlspecialchars($h['maintenance_point'] ?? '-') ?></td>
                                        <td class="px-5 py-3 text-center font-mono text-xs text-slate-500 whitespace-nowrap"><?= $planDate ?></td>
                                        <td class="px-5 py-3 text-slate-600 text-sm max-w-[200px]"><?= htmlspecialchars($noteShort) ?></td>
                                        <td class="px-5 py-3 text-center text-xs text-slate-500 whitespace-nowrap"><?= $reportedAt ?></td>
                                        <td class="px-5 py-3 text-center">
                                            <span class="inline-flex items-center gap-1 bg-emerald-50 text-emerald-700 border border-emerald-100 px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-wide">
                                                <i class="fas fa-check-circle text-[9px]"></i> Done
                                            </span>
                                        </td>
                                        <td class="px-5 py-3 text-center">
                                            <button onclick="showDetail(<?= $h['id'] ?>, 'predictive')"
                                                class="bg-slate-100 hover:bg-amber-50 hover:text-amber-600 text-slate-500 px-3 py-1.5 rounded-lg text-xs font-bold transition-all">
                                                <i class="fas fa-eye mr-1"></i> Detail
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="px-6 py-3 bg-slate-50 border-t border-slate-100 flex items-center justify-between text-xs text-slate-400">
                    <span>Menampilkan <?= count($historiesPred) ?> laporan predictive</span>
                    <?php date_default_timezone_set('Asia/Jakarta'); ?>
                    <span>Last updated: <?= date('d M Y H:i') ?></span>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════
         TAB: PREVENTIVE
    ════════════════════════════════════════════════════════════════ -->
        <div id="preventiveTab" class="<?= $activeTab === 'predictive' ? 'hidden' : '' ?>">
            <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="overflow-x-auto" style="max-height:600px;overflow-y:auto;">
                    <table class="w-full text-left text-sm border-collapse">
                        <thead class="text-white" style="background:linear-gradient(135deg, #c2410c, #f97316);position:sticky;top:0;z-index:10;">
                            <tr>
                                <th class="px-5 py-4 font-semibold text-[11px] uppercase tracking-widest whitespace-nowrap">No</th>
                                <th class="px-5 py-4 font-semibold text-[11px] uppercase tracking-widest whitespace-nowrap">Machine Info</th>
                                <th class="px-5 py-4 font-semibold text-[11px] uppercase tracking-widest whitespace-nowrap">Maintenance Point</th>
                                <th class="px-5 py-4 font-semibold text-[11px] uppercase tracking-widest whitespace-nowrap text-center">Change Date Plan</th>
                                <th class="px-5 py-4 font-semibold text-[11px] uppercase tracking-widest whitespace-nowrap">Note</th>
                                <th class="px-5 py-4 font-semibold text-[11px] uppercase tracking-widest whitespace-nowrap text-center">Reported At</th>
                                <th class="px-5 py-4 font-semibold text-[11px] uppercase tracking-widest whitespace-nowrap text-center">Status</th>
                                <th class="px-5 py-4 font-semibold text-[11px] uppercase tracking-widest whitespace-nowrap text-center">Detail</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (empty($historiesPrev)): ?>
                                <tr>
                                    <td colspan="8" class="px-6 py-16 text-center text-slate-400">
                                        <i class="fas fa-shield-halved text-4xl mb-3 block text-slate-200"></i>
                                        <p class="font-medium">Belum ada history preventive maintenance<?= ($search || $filterDep || $filterDate) ? ' yang sesuai filter.' : '.' ?></p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($historiesPrev as $i => $h): ?>
                                    <?php
                                    $techName   = $h['teknisi'] ?? '-';
                                    $reportedBy = $h['reported_by_name'] ?? ('User #' . $h['reported_by']);
                                    $reportedAt = $h['reported_at'] ? date('d M Y H:i', strtotime($h['reported_at'])) : '-';
                                    $planDate   = $h['change_date_plan'] ? date('d M Y', strtotime($h['change_date_plan'])) : '-';
                                    $noteShort  = mb_strlen($h['note'] ?? '') > 60 ? mb_substr($h['note'], 0, 60) . '…' : ($h['note'] ?? '-');
                                    $rowMonth   = $h['reported_at'] ? date('Y-m', strtotime($h['reported_at'])) : '';
                                    ?>
                                    <tr class="hist-row hist-prev-row transition-colors fade-in" data-month="<?= $rowMonth ?>">
                                        <td class="px-5 py-3 text-slate-400 font-mono text-xs"><?= $i + 1 ?></td>
                                        <td class="px-5 py-3">
                                            <div class="font-bold text-slate-800 whitespace-nowrap text-sm"><?= htmlspecialchars($h['machine_name'] ?? '-') ?></div>
                                            <div class="text-xs text-slate-500 mt-0.5"><?= htmlspecialchars($h['department'] ?? '') ?><?= $h['line'] ? ' | ' . htmlspecialchars($h['line']) : '' ?></div>
                                            <?php if ($h['name_unit']): ?><div class="text-xs text-slate-400 italic mt-0.5"><?= htmlspecialchars($h['name_unit']) ?></div><?php endif; ?>
                                        </td>
                                        <td class="px-5 py-3 text-slate-600 text-sm max-w-[180px]"><?= htmlspecialchars($h['maintenance_point'] ?? '-') ?></td>
                                        <td class="px-5 py-3 text-center font-mono text-xs text-slate-500 whitespace-nowrap"><?= $planDate ?></td>
                                        <td class="px-5 py-3 text-slate-600 text-sm max-w-[200px]"><?= htmlspecialchars($noteShort) ?></td>
                                        <td class="px-5 py-3 text-center text-xs text-slate-500 whitespace-nowrap"><?= $reportedAt ?></td>
                                        <td class="px-5 py-3 text-center">
                                            <span class="inline-flex items-center gap-1 bg-teal-50 text-teal-700 border border-teal-100 px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-wide">
                                                <i class="fas fa-check-circle text-[9px]"></i> Done
                                            </span>
                                        </td>
                                        <td class="px-5 py-3 text-center">
                                            <button onclick="showDetail(<?= $h['id'] ?>, 'preventive')"
                                                class="bg-slate-100 hover:bg-teal-50 hover:text-teal-600 text-slate-500 px-3 py-1.5 rounded-lg text-xs font-bold transition-all">
                                                <i class="fas fa-eye mr-1"></i> Detail
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="px-6 py-3 bg-slate-50 border-t border-slate-100 flex items-center justify-between text-xs text-slate-400">
                    <span>Menampilkan <?= count($historiesPrev) ?> laporan preventive</span>
                    <?php date_default_timezone_set('Asia/Jakarta'); ?>
                    <span>Last updated: <?= date('d M Y H:i') ?></span>
                </div>
            </div>
        </div>

    </div><!-- /max-w-7xl -->

    <!-- ═══════════════════════ MODAL DETAIL ═══════════════════════ -->
    <div id="detailModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 items-center justify-center p-4" style="display:none;">
        <div class="bg-white w-full max-w-xl rounded-3xl shadow-2xl overflow-hidden">
            <div id="detailModalHeader" class="bg-gradient-to-r from-amber-600 to-amber-700 px-7 py-5 flex justify-between items-center">
                <div>
                    <p id="detailModalType" class="text-white/60 text-[10px] font-black uppercase tracking-widest mb-0.5">Predictive Maintenance</p>
                    <h3 class="text-base font-bold text-white"><i class="fas fa-clipboard-check mr-2"></i>Detail Maintenance Report</h3>
                    <p class="text-amber-100 text-xs mt-0.5" id="detailMachine">—</p>
                </div>
                <button onclick="hideDetail()" class="text-white/60 hover:text-white w-8 h-8 flex items-center justify-center rounded-full hover:bg-white/10 transition">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="p-7 space-y-4 max-h-[75vh] overflow-y-auto" id="detailBody">
                <div class="text-center py-8 text-slate-400"><i class="fas fa-spinner fa-spin text-2xl"></i></div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════ MODAL EXPORT EXCEL ═══════════════════ -->
    <div id="exportModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 items-center justify-center p-4" style="display:none;">
        <div class="bg-white w-full max-w-md rounded-3xl shadow-2xl overflow-hidden">

            <!-- Header modal -->
            <div class="bg-gradient-to-r from-emerald-600 to-emerald-700 px-7 py-5 flex justify-between items-center">
                <div>
                    <p class="text-white/60 text-[10px] font-black uppercase tracking-widest mb-0.5">Export Data</p>
                    <h3 class="text-base font-bold text-white"><i class="fas fa-file-excel mr-2"></i>Export History ke Excel</h3>
                </div>
                <button onclick="closeExportModal()" class="text-white/60 hover:text-white w-8 h-8 flex items-center justify-center rounded-full hover:bg-white/10 transition">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <!-- Body modal -->
            <div class="p-7 space-y-5">

                <!-- Pilihan tipe maintenance -->
                <div>
                    <label class="block text-xs font-black text-slate-500 uppercase tracking-wide mb-2">Tipe Maintenance</label>
                    <div class="flex gap-2">
                        <button id="exportTypePred" onclick="setExportType('predictive')"
                            class="flex-1 py-2.5 rounded-xl text-sm font-bold border-2 border-amber-500 bg-amber-500 text-white transition-all">
                            <i class="fas fa-chart-line mr-1"></i> Predictive
                        </button>
                        <button id="exportTypePrev" onclick="setExportType('preventive')"
                            class="flex-1 py-2.5 rounded-xl text-sm font-bold border-2 border-slate-200 bg-white text-slate-500 transition-all">
                            <i class="fas fa-shield-halved mr-1"></i> Preventive
                        </button>
                    </div>
                </div>

                <!-- Pilihan periode (Daily / Monthly) via pill tab -->
                <div>
                    <label class="block text-xs font-black text-slate-500 uppercase tracking-wide mb-2">Periode</label>
                    <div class="flex bg-slate-100 rounded-xl p-1 gap-1">
                        <button id="periodTabDaily" onclick="setPeriodTab('daily')"
                            class="flex-1 py-2 rounded-lg text-xs font-bold transition-all bg-white shadow text-slate-800">
                            Per Hari
                        </button>
                        <button id="periodTabMonthly" onclick="setPeriodTab('monthly')"
                            class="flex-1 py-2 rounded-lg text-xs font-bold transition-all text-slate-500">
                            Per Bulan
                        </button>
                    </div>
                </div>

                <!-- Input tanggal (daily) -->
                <div id="inputDaily">
                    <label class="block text-xs font-black text-slate-500 uppercase tracking-wide mb-2">Pilih Tanggal</label>
                    <input type="date" id="exportTanggal"
                        class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400"
                        value="<?= date('Y-m-d') ?>">
                </div>

                <!-- Input bulan (monthly) -->
                <div id="inputMonthly" class="hidden">
                    <label class="block text-xs font-black text-slate-500 uppercase tracking-wide mb-2">Pilih Bulan</label>
                    <input type="month" id="exportBulan"
                        class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400"
                        value="<?= date('Y-m') ?>">
                </div>

                <!-- Tombol aksi -->
                <div class="flex justify-end gap-3 pt-2">
                    <button onclick="closeExportModal()"
                        class="px-6 py-2.5 rounded-xl text-sm font-bold text-slate-500 hover:bg-slate-100 transition-all">
                        Batal
                    </button>
                    <button onclick="doExport()"
                        class="bg-emerald-600 hover:bg-emerald-700 text-white px-8 py-2.5 rounded-xl text-sm font-bold shadow-lg shadow-emerald-200 transition-all">
                        <i class="fas fa-download mr-1"></i> Export
                    </button>
                </div>

            </div>
        </div>
    </div>

    <script>
        // ── Tab switching ─────────────────────────────────────────────
        const INIT_TAB = '<?= $activeTab ?>';
        let currentTab = INIT_TAB; // track active tab untuk export

        function switchTab(tab) {
            currentTab = tab;
            const isPred = tab === 'predictive';
            document.getElementById('predictiveTab').classList.toggle('hidden', !isPred);
            document.getElementById('preventiveTab').classList.toggle('hidden', isPred);

            // Labels
            document.getElementById('labelPredictive').classList.toggle('hidden', !isPred);
            document.getElementById('labelPreventive').classList.toggle('hidden', isPred);

            // Filter dept dropdowns
            document.getElementById('deptFilterPred').classList.toggle('hidden', !isPred);
            document.getElementById('deptFilterPrev').classList.toggle('hidden', isPred);

            // Form tab hidden input
            document.getElementById('formTab').value = tab;

            // Pill indicator — slide ke posisi tab yang aktif
            const indicator = document.getElementById('tabIndicator');
            indicator.style.transform = isPred ? 'translateX(0)' : 'translateX(calc(100% + 4px))';
            indicator.style.background = isPred ?
                'linear-gradient(135deg,#f59e0b,#d97706)' :
                'linear-gradient(135deg,#ea580c,#f97316)';

            document.getElementById('tabPredictive').style.color = isPred ? '#fff' : '#64748b';
            document.getElementById('tabPreventive').style.color = !isPred ? '#fff' : '#64748b';

            const bp = document.getElementById('badgePredictive');
            const bv = document.getElementById('badgePreventive');
            if (bp) bp.className = isPred ?
                'ml-1 bg-white/20 text-white text-[10px] font-black px-2 py-0.5 rounded-full' :
                'ml-1 bg-slate-300/60 text-slate-600 text-[10px] font-black px-2 py-0.5 rounded-full';
            if (bv) bv.className = !isPred ?
                'ml-1 bg-white/20 text-white text-[10px] font-black px-2 py-0.5 rounded-full' :
                'ml-1 bg-slate-300/60 text-slate-600 text-[10px] font-black px-2 py-0.5 rounded-full';

            const url = new URL(window.location);
            url.searchParams.set('tab', tab);
            window.history.replaceState({}, '', url);
        }

        // ── Export modal ──────────────────────────────────────────────
        let exportType = INIT_TAB; // 'predictive' | 'preventive'
        let exportPeriod = 'daily'; // 'daily' | 'monthly'

        function openExportModal() {
            // Sinkronkan tipe dengan tab yang sedang aktif
            setExportType(currentTab);
            document.getElementById('exportModal').style.display = 'flex';
        }

        function closeExportModal() {
            document.getElementById('exportModal').style.display = 'none';
        }

        document.getElementById('exportModal').addEventListener('click', function(e) {
            if (e.target === this) closeExportModal();
        });

        function setExportType(type) {
            exportType = type;
            const isPred = type === 'predictive';
            document.getElementById('exportTypePred').className =
                'flex-1 py-2.5 rounded-xl text-sm font-bold border-2 transition-all ' +
                (isPred ? 'border-amber-500 bg-amber-500 text-white' : 'border-slate-200 bg-white text-slate-500');
            document.getElementById('exportTypePrev').className =
                'flex-1 py-2.5 rounded-xl text-sm font-bold border-2 transition-all ' +
                (!isPred ? 'border-teal-500 bg-teal-500 text-white' : 'border-slate-200 bg-white text-slate-500');
        }

        function setPeriodTab(period) {
            exportPeriod = period;
            const isDaily = period === 'daily';
            document.getElementById('inputDaily').classList.toggle('hidden', !isDaily);
            document.getElementById('inputMonthly').classList.toggle('hidden', isDaily);
            document.getElementById('periodTabDaily').className =
                'flex-1 py-2 rounded-lg text-xs font-bold transition-all ' +
                (isDaily ? 'bg-white shadow text-slate-800' : 'text-slate-500');
            document.getElementById('periodTabMonthly').className =
                'flex-1 py-2 rounded-lg text-xs font-bold transition-all ' +
                (!isDaily ? 'bg-white shadow text-slate-800' : 'text-slate-500');
        }

        function doExport() {
            let periodParam = '';
            if (exportPeriod === 'daily') {
                const val = document.getElementById('exportTanggal').value;
                if (!val) {
                    alert('Pilih tanggal terlebih dahulu.');
                    return;
                }
                periodParam = '&tanggal=' + encodeURIComponent(val);
            } else {
                const val = document.getElementById('exportBulan').value;
                if (!val) {
                    alert('Pilih bulan terlebih dahulu.');
                    return;
                }
                periodParam = '&bulan=' + encodeURIComponent(val);
            }
            const url = 'export_history_maintenance.php' +
                '?type=' + exportType +
                '&mode=' + exportPeriod +
                periodParam;
            window.location.href = url;
            closeExportModal();
        }

        // ── Detail modal ──────────────────────────────────────────────
        function hideDetail() {
            document.getElementById('detailModal').style.display = 'none';
        }
        document.getElementById('detailModal').addEventListener('click', function(e) {
            if (e.target === this) hideDetail();
        });

        async function showDetail(id, type) {
            document.getElementById('detailModal').style.display = 'flex';
            document.getElementById('detailMachine').textContent = '—';
            document.getElementById('detailBody').innerHTML =
                '<div class="text-center py-8 text-slate-400"><i class="fas fa-spinner fa-spin text-2xl"></i></div>';

            const header = document.getElementById('detailModalHeader');
            const typeLabel = document.getElementById('detailModalType');
            if (type === 'preventive') {
                header.className = 'px-7 py-5 flex justify-between items-center';
                header.style.background = 'linear-gradient(135deg,#ea580c,#f97316)';
                typeLabel.textContent = 'Preventive Maintenance';
            } else {
                header.className = 'bg-gradient-to-r from-amber-600 to-amber-700 px-7 py-5 flex justify-between items-center';
                header.style.background = '';
                typeLabel.textContent = 'Predictive Maintenance';
            }

            const res = await fetch(`?get_detail=${id}&type=${type}`);
            const d = await res.json();
            if (!d) {
                document.getElementById('detailBody').innerHTML = '<p class="text-red-500 text-sm">Data tidak ditemukan.</p>';
                return;
            }

            document.getElementById('detailMachine').textContent =
                (d.machine_name || '') + (d.line ? ' | ' + d.line : '');

            const fmt = v => v ? v : '—';
            const fmtDate = v => v ? new Date(v).toLocaleDateString('id-ID', {
                day: '2-digit',
                month: 'long',
                year: 'numeric'
            }) : '—';
            const fmtDT = v => v ? new Date(v).toLocaleString('id-ID', {
                day: '2-digit',
                month: 'long',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            }) : '—';
            const row = (label, val) => `
            <div class="flex gap-3">
                <span class="text-slate-400 text-xs font-bold uppercase tracking-wide w-36 flex-shrink-0 pt-0.5">${label}</span>
                <span class="text-slate-700 text-sm font-medium flex-1">${val}</span>
            </div>`;

            const badgeColor = type === 'preventive' ?
                'bg-teal-50 text-teal-700 border-teal-100' :
                'bg-emerald-50 text-emerald-700 border-emerald-100';
            const photoHtml = d.photo_path ?
                `<div class="mt-2"><a href="${d.photo_path}" target="_blank" class="inline-block rounded-xl overflow-hidden border border-slate-200 hover:opacity-90 transition"><img src="${d.photo_path}" alt="Foto" class="max-h-48 object-contain w-full"></a></div>` : '';

            document.getElementById('detailBody').innerHTML = `
            <div class="space-y-3">
                ${row('Department', fmt(d.department))}
                ${row('Line', fmt(d.line))}
                ${row('Operation Process', fmt(d.operation_process))}
                ${row('Machine Name', fmt(d.machine_name))}
                ${row('Process Machine', fmt(d.process_machine))}
                ${row('Unit Name', fmt(d.name_unit))}
                ${row('Maintenance Point', fmt(d.maintenance_point))}
                ${row('Change Date Plan', fmtDate(d.change_date_plan))}
                <hr class="border-slate-100">
                ${row('Note / Keterangan', `<span class="whitespace-pre-wrap">${fmt(d.note)}</span>`)}
                ${row('Technician', fmt(d.teknisi || '-'))}
                ${row('Reported By', fmt(d.reported_by_name || ('User #' + d.reported_by)))}
                ${row('Reported At', fmtDT(d.reported_at))}
                ${row('Status', `<span class="${badgeColor} border px-3 py-0.5 rounded-full text-[10px] font-black uppercase">✓ Done</span>`)}
                ${d.photo_path ? row('Foto', `<a href="${d.photo_path}" target="_blank" class="text-amber-600 font-bold text-xs hover:underline"><i class="fas fa-image mr-1"></i>Lihat Foto</a>` + photoHtml) : ''}
            </div>`;
        }

        // ── Filter mode toggle (Tanggal / Bulan) ────────────────────────
        function setFilterMode(mode) {
            document.getElementById('filterModeInput').value = mode;
            const isDate = mode === 'date';
            document.getElementById('inputWrapDate').classList.toggle('hidden', !isDate);
            document.getElementById('inputWrapMonth').classList.toggle('hidden', isDate);
            // Update toggle button styles
            document.getElementById('toggleDate').className =
                'px-3 py-1.5 rounded-lg transition-all ' +
                (isDate ? 'bg-white shadow text-slate-800' : 'text-slate-500');
            document.getElementById('toggleMonth').className =
                'px-3 py-1.5 rounded-lg transition-all ' +
                (!isDate ? 'bg-white shadow text-slate-800' : 'text-slate-500');
            // Clear the unused input to avoid sending stale value
            if (isDate) {
                document.getElementById('inputMonth').value = '';
            } else {
                document.getElementById('inputDate').value = '';
            }
        }
    </script>

</body>

</html>