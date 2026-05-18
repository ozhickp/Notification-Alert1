<?php
include 'config.php';

// ── Active section & sub-tab ───────────────────────────────────────────────────
$activeSection = $_GET['section'] ?? 'schedule';
if (!in_array($activeSection, ['schedule', 'parts', 'history'])) $activeSection = 'schedule';

$activeTab = ($_GET['tab'] ?? 'predictive') === 'preventive' ? 'preventive' : 'predictive';

// ── Today's date ───────────────────────────────────────────────────────────────
$todayStr = date('Y-m-d');

// ══════════════════════════════════════════════════════════════════════════════
//  SCHEDULE DATA (Predictive)
// ══════════════════════════════════════════════════════════════════════════════
$schedules = [];
try {
    $schedules = $pdo->query("
        SELECT s.*,
               COALESCE(p.plant_name, s.department) AS department,
               COALESCE(l.line_name, s.line) AS line
        FROM schedules s
        LEFT JOIN plants p ON p.id = s.department
        LEFT JOIN line l ON l.id = s.line
        ORDER BY s.remaining_day ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Exception $e) {
    error_log('[Monitor] schedules: ' . $e->getMessage());
}

// Today's predictive
$todaySchedArr = array_filter($schedules, fn($r) => ($r['change_date_plan'] ?? '') === $todayStr);
$todayCount = count($todaySchedArr);

// ── SCHEDULE DATA (Preventive) ─────────────────────────────────────────────────
$prevSchedules = [];
try {
    $prevSchedules = $pdo->query("
        SELECT s.*,
               COALESCE(p.plant_name, s.department) AS department,
               COALESCE(l.line_name, s.line) AS line
        FROM schedules_preventive s
        LEFT JOIN plants p ON p.id = s.department
        LEFT JOIN line l ON l.id = s.line
        ORDER BY s.remaining_day ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Exception $e) {
    error_log('[Monitor] schedules_preventive: ' . $e->getMessage());
}

// Today's preventive
$prevTodayArr = array_filter($prevSchedules, fn($r) => ($r['change_date_plan'] ?? '') === $todayStr);
$prevTodayCount = count($prevTodayArr);

// ══════════════════════════════════════════════════════════════════════════════
//  PART AVAILABILITY DATA
// ══════════════════════════════════════════════════════════════════════════════
$parts = [];
try {
    $parts = $pdo->query("SELECT * FROM expenses_part ORDER BY item_code ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Exception $e) {
    error_log('[Monitor] expenses_part: ' . $e->getMessage());
}

function getPartStatusStr(int $actual, int $safety): string
{
    if ($actual === 0)       return 'Zero Stock';
    if ($actual < $safety)   return 'Low Stock';
    if ($actual === $safety) return 'In Stock';
    return                          'Over Stock';
}
function getPartStatusClass(string $status): string
{
    return match ($status) {
        'Zero Stock' => 'badge-zero',
        'Low Stock'  => 'badge-low',
        'In Stock'   => 'badge-in',
        'Over Stock' => 'badge-over',
        default      => 'badge-none',
    };
}

// ══════════════════════════════════════════════════════════════════════════════
//  HISTORY DATA
// ══════════════════════════════════════════════════════════════════════════════
$historiesPred = [];
$historiesPrev = [];
try {
    $historiesPred = $pdo->query("
        SELECT h.id, h.department, h.line, h.machine_name, h.process_machine, h.name_unit,
               h.maintenance_point, h.change_date_plan, h.note, h.reported_at,
               u.username AS technician_name
        FROM history_maintenance h
        LEFT JOIN users u ON u.id = h.reported_by
        ORDER BY h.reported_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Exception $e) {
    error_log('[Monitor] history_maintenance: ' . $e->getMessage());
}

try {
    $historiesPrev = $pdo->query("
        SELECT h.id, h.department, h.line, h.machine_name, h.process_machine, h.name_unit,
               h.maintenance_point, h.change_date_plan, h.note, h.reported_at,
               u.username AS technician_name
        FROM history_preventive h
        LEFT JOIN users u ON u.id = h.reported_by
        ORDER BY h.reported_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Exception $e) {
    error_log('[Monitor] history_preventive: ' . $e->getMessage());
}

// ── Helper ─────────────────────────────────────────────────────────────────────
function remainingClass(int $days): string
{
    if ($days < 0)  return 'text-red-600 font-black';
    if ($days <= 7) return 'text-amber-600 font-black';
    if ($days <= 30) return 'text-orange-500 font-bold';
    return 'text-slate-700 font-semibold';
}
function maintenanceStatusBadge(string $status): string
{
    return match (strtolower($status)) {
        'soon'  => '<span class="badge ms-soon">Soon</span>',
        'done'  => '<span class="badge ms-done">Done</span>',
        default => '<span class="badge badge-none">' . htmlspecialchars($status) . '</span>',
    };
}
function partOrderBadge(string $v): string
{
    return match (strtolower($v)) {
        'open'  => '<span class="badge ps-open">Open</span>',
        'close' => '<span class="badge ps-close">Close</span>',
        default => '<span class="badge badge-none">' . htmlspecialchars($v) . '</span>',
    };
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitor — Maintenance Hub</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #f8fafc;
        }

        /* ── Layout ── */
        #app-layout {
            display: flex;
            min-height: 100vh;
        }

        /* ── Sidebar ── */
        #sidebar {
            width: 220px;
            min-width: 220px;
            background: #fff;
            border-right: 1px solid #e2e8f0;
            box-shadow: 2px 0 12px rgba(0, 0, 0, .04);
            display: flex;
            flex-direction: column;
            transition: width .25s ease, min-width .25s ease;
            overflow: hidden;
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            z-index: 100;
        }

        #sidebar.collapsed {
            width: 56px;
            min-width: 56px;
        }

        #sidebar-logo {
            padding: 1.1rem 1rem;
            display: flex;
            align-items: center;
            gap: .75rem;
            border-bottom: 1px solid #f1f5f9;
            min-height: 64px;
            flex-shrink: 0;
        }

        #sidebar-logo .logo-icon {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, #7c3aed, #a855f7);
            border-radius: .75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            color: #fff;
            font-size: .9rem;
        }

        #sidebar-logo .logo-text {
            font-size: .95rem;
            font-weight: 800;
            color: #1e293b;
            white-space: nowrap;
            overflow: hidden;
            transition: opacity .2s;
        }

        #sidebar.collapsed #sidebar-logo .logo-text {
            opacity: 0;
            pointer-events: none;
        }

        .sidebar-nav {
            flex: 1;
            padding: .75rem .5rem;
            display: flex;
            flex-direction: column;
            gap: .25rem;
            overflow: hidden;
        }

        .sidebar-back {
            display: flex;
            align-items: center;
            gap: .6rem;
            padding: .55rem .65rem;
            border-radius: .75rem;
            font-size: .78rem;
            font-weight: 600;
            color: #7c3aed;
            text-decoration: none;
            transition: background .15s;
            white-space: nowrap;
            overflow: hidden;
        }

        .sidebar-back:hover {
            background: #f5f3ff;
        }

        .sidebar-back .sb-icon {
            width: 28px;
            height: 28px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .8rem;
        }

        .sidebar-back .sb-label {
            overflow: hidden;
            transition: opacity .2s;
        }

        #sidebar.collapsed .sb-label {
            opacity: 0;
        }

        .nav-pill {
            display: flex;
            align-items: center;
            gap: .6rem;
            padding: .6rem .65rem;
            border-radius: .75rem;
            font-size: .82rem;
            font-weight: 700;
            cursor: pointer;
            transition: all .18s;
            border: 2px solid transparent;
            white-space: nowrap;
            overflow: hidden;
            background: none;
            text-align: left;
            width: 100%;
        }

        .nav-pill .np-icon {
            width: 28px;
            height: 28px;
            border-radius: .6rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .8rem;
            flex-shrink: 0;
            background: #f1f5f9;
            color: #64748b;
            transition: background .18s, color .18s;
        }

        .nav-pill .np-label {
            overflow: hidden;
            transition: opacity .2s;
        }

        #sidebar.collapsed .np-label {
            opacity: 0;
        }

        .nav-pill.active-schedule {
            color: #1d4ed8;
            border-color: #bfdbfe;
            background: #eff6ff;
        }

        .nav-pill.active-schedule .np-icon {
            background: #2563eb;
            color: #fff;
        }

        .nav-pill.active-parts {
            color: #15803d;
            border-color: #bbf7d0;
            background: #f0fdf4;
        }

        .nav-pill.active-parts .np-icon {
            background: #16a34a;
            color: #fff;
        }

        .nav-pill.active-history {
            color: #b45309;
            border-color: #fde68a;
            background: #fffbeb;
        }

        .nav-pill.active-history .np-icon {
            background: #d97706;
            color: #fff;
        }

        .nav-pill:not([class*="active"]):hover {
            background: #f8fafc;
        }

        .nav-pill:not([class*="active"]):hover .np-icon {
            background: #e2e8f0;
            color: #475569;
        }

        /* Sidebar toggle btn */
        #sidebarToggle {
            margin: .5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border-radius: .65rem;
            background: #f1f5f9;
            border: none;
            cursor: pointer;
            color: #475569;
            font-size: .85rem;
            transition: background .15s, color .15s;
            flex-shrink: 0;
        }

        #sidebarToggle:hover {
            background: #e2e8f0;
            color: #1e293b;
        }

        #sidebar-footer {
            border-top: 1px solid #f1f5f9;
            padding: .5rem;
            display: flex;
            justify-content: flex-end;
        }

        /* ── Main content ── */
        #main-content {
            margin-left: 220px;
            transition: margin-left .25s ease;
            flex: 1;
            min-width: 0;
            padding: 1.5rem 2rem;
        }

        body.sidebar-collapsed #main-content {
            margin-left: 56px;
        }

        /* scroll status pill */
        #scroll-status {
            position: fixed;
            bottom: 1.2rem;
            right: 1.5rem;
            background: rgba(15, 23, 42, .75);
            color: #fff;
            font-size: .7rem;
            font-weight: 700;
            padding: .35rem .85rem;
            border-radius: 999px;
            z-index: 200;
            display: flex;
            align-items: center;
            gap: .4rem;
            letter-spacing: .04em;
            backdrop-filter: blur(6px);
            opacity: 0;
            transition: opacity .3s;
            pointer-events: none;
        }

        #scroll-status.visible {
            opacity: 1;
        }

        /* ── Badges ── */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: .2rem .72rem;
            border-radius: 9999px;
            font-size: .68rem;
            font-weight: 800;
            border: 1px solid transparent;
            letter-spacing: .05em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .badge-zero {
            background: #fee2e2;
            color: #b91c1c;
            border-color: #fca5a5;
        }

        .badge-low {
            background: #ffedd5;
            color: #c2410c;
            border-color: #fdba74;
        }

        .badge-in {
            background: #d1fae5;
            color: #065f46;
            border-color: #6ee7b7;
        }

        .badge-over {
            background: #ede9fe;
            color: #6d28d9;
            border-color: #c4b5fd;
        }

        .badge-none {
            background: #f1f5f9;
            color: #64748b;
            border-color: #cbd5e1;
        }

        .ms-soon {
            background: #dbeafe;
            color: #1e40af;
            border-color: #93c5fd;
        }

        .ms-done {
            background: #d1fae5;
            color: #065f46;
            border-color: #6ee7b7;
        }

        .ps-open {
            background: #dbeafe;
            color: #1e40af;
            border-color: #93c5fd;
        }

        .ps-close {
            background: #f1f5f9;
            color: #64748b;
            border-color: #cbd5e1;
        }

        /* nav-pill styles defined in sidebar section above */

        /* ── Subtab indicator ── */
        .subtab-indicator {
            position: absolute;
            top: .375rem;
            left: .375rem;
            bottom: .375rem;
            border-radius: .625rem;
            transition: all .3s ease-in-out;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .12);
            pointer-events: none;
        }

        /* ── Table ── */
        .tbl-th {
            padding: .8rem 1rem;
            font-size: .68rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .06em;
            white-space: nowrap;
            color: #fff;
        }

        .tbl-td {
            padding: .7rem 1rem;
            font-size: .8rem;
            color: #334155;
            border-bottom: 1px solid #f1f5f9;
        }

        tr:hover .tbl-td {
            background: #f8fafc;
        }

        tr:last-child .tbl-td {
            border-bottom: none;
        }

        /* ── Today banner ── */
        .today-banner {
            border-radius: 1.25rem;
            padding: .75rem 1.25rem;
            display: flex;
            align-items: center;
            gap: .75rem;
            font-size: .8rem;
            font-weight: 700;
        }

        /* ── Responsive table wrap ── */
        .table-scroll {
            overflow-x: auto;
            overflow-y: auto;
            max-height: 520px;
        }

        /* Schedule tables: fill remaining viewport height */
        #schedPredTab .table-scroll,
        #schedPrevTab .table-scroll {
            max-height: calc(100vh - 280px);
        }

        /* History tables: fill remaining viewport height (same logic as schedule) */
        #histPredTab .table-scroll,
        #histPrevTab .table-scroll {
            max-height: calc(100vh - 280px);
        }

        /* Predictive table: allow horizontal scroll if needed */
        #schedPredTab .table-scroll {
            overflow-x: auto;
        }

        /* Compact badge for tight columns */
        #schedPredTab .badge {
            padding: .15rem .45rem;
            font-size: .58rem;
            letter-spacing: .03em;
        }

        /* Compact columns for schedule */
        .col-compact {
            max-width: 130px;
        }

        .col-narrow {
            max-width: 90px;
        }

        @keyframes fadeUp {
            from {
                opacity: 0;
                transform: translateY(6px);
            }

            to {
                opacity: 1;
                transform: none;
            }
        }

        .section-enter {
            animation: fadeUp .22s ease;
        }
    </style>
</head>

<body class="min-h-screen">

    <div id="app-layout">

        <!-- ═══════════════════════ SIDEBAR ═══════════════════════ -->
        <nav id="sidebar">
            <!-- Logo -->
            <div id="sidebar-logo">
                <div class="logo-icon"><i class="fas fa-display"></i></div>
                <span class="logo-text">Monitor</span>
            </div>

            <!-- Nav items -->
            <div class="sidebar-nav">
                <a href="index.php" class="sidebar-back">
                    <span class="sb-icon"><i class="fas fa-arrow-left"></i></span>
                    <span class="sb-label">Back to Hub</span>
                </a>
                <div style="height:1px;background:#f1f5f9;margin:.4rem 0;"></div>
                <button onclick="switchSection('schedule')" id="navSchedule"
                    class="nav-pill <?= $activeSection === 'schedule' ? 'active-schedule' : '' ?>">
                    <span class="np-icon"><i class="fas fa-calendar-check"></i></span>
                    <span class="np-label">Schedule</span>
                </button>
                <button onclick="switchSection('parts')" id="navParts"
                    class="nav-pill <?= $activeSection === 'parts' ? 'active-parts' : '' ?>">
                    <span class="np-icon"><i class="fas fa-boxes-stacked"></i></span>
                    <span class="np-label">Part Availability</span>
                </button>
                <button onclick="switchSection('history')" id="navHistory"
                    class="nav-pill <?= $activeSection === 'history' ? 'active-history' : '' ?>">
                    <span class="np-icon"><i class="fas fa-history"></i></span>
                    <span class="np-label">History</span>
                </button>
            </div>

            <!-- Toggle btn -->
            <div id="sidebar-footer">
                <button id="sidebarToggle" title="Toggle sidebar">
                    <i class="fas fa-chevron-left" id="sidebarToggleIcon"></i>
                </button>
            </div>
        </nav>

        <!-- ═══════════════════════ MAIN ═══════════════════════ -->
        <div id="main-content">

            <!-- Scroll status indicator -->
            <div id="scroll-status">
                <span id="scroll-dot" style="width:7px;height:7px;background:#22c55e;border-radius:50%;display:inline-block;"></span>
                <span id="scroll-label">Auto-scroll aktif</span>
            </div>

            <div class="max-w-[1400px] mx-auto">

                <!-- ═══════════════════════ HEADER ═══════════════════════ -->
                <div class="mb-4">
                    <h1 class="text-2xl font-extrabold text-slate-900">
                        <i class="fas fa-display text-violet-500 mr-2"></i>Monitor
                    </h1>
                </div>

                <!-- ═══════════════════════════════════════════════════════════
         SECTION: SCHEDULE
    ═══════════════════════════════════════════════════════════ -->
                <div id="sectionSchedule" class="section-enter <?= $activeSection !== 'schedule' ? 'hidden' : '' ?>">

                    <!-- Sub-tab (Predictive / Preventive) + Clock -->
                    <div class="mb-5 flex items-center gap-3">
                        <div class="relative bg-slate-100 rounded-2xl p-1.5 flex gap-1 shadow-inner" style="max-width:520px;flex:1;">
                            <div id="schedTabIndicator" class="subtab-indicator"
                                style="background:<?= $activeTab === 'preventive' ? 'linear-gradient(135deg,#4338ca,#6366f1)' : 'linear-gradient(135deg,#2563eb,#1d4ed8)' ?>;width:calc(50% - 4px);transform:<?= $activeTab === 'preventive' ? 'translateX(calc(100% + 4px))' : 'translateX(0)' ?>;"></div>
                            <button id="schedTabPred" onclick="switchSchedTab('predictive')"
                                class="relative z-10 flex-1 flex items-center justify-center gap-2 py-3 px-5 font-bold text-sm rounded-xl transition-all duration-300 <?= $activeTab === 'predictive' ? 'text-white' : 'text-slate-500' ?>">
                                <i class="fas fa-chart-line"></i> Predictive
                                <span class="ml-1 text-[10px] font-black px-2 py-0.5 rounded-full <?= $activeTab === 'predictive' ? 'bg-white/20 text-white' : 'bg-slate-300/60 text-slate-600' ?>"><?= count($schedules) ?></span>
                            </button>
                            <button id="schedTabPrev" onclick="switchSchedTab('preventive')"
                                class="relative z-10 flex-1 flex items-center justify-center gap-2 py-3 px-5 font-bold text-sm rounded-xl transition-all duration-300 <?= $activeTab === 'preventive' ? 'text-white' : 'text-slate-500' ?>">
                                <i class="fas fa-shield-halved"></i> Preventive
                                <span class="ml-1 text-[10px] font-black px-2 py-0.5 rounded-full <?= $activeTab === 'preventive' ? 'bg-white/20 text-white' : 'bg-slate-300/60 text-slate-600' ?>"><?= count($prevSchedules) ?></span>
                            </button>
                        </div>
                        <!-- Digital Clock — Schedule -->
                        <div class="flex flex-col items-end ml-auto flex-shrink-0">
                            <div id="live-clock" class="font-mono font-black text-slate-800 tabular-nums" style="font-size:1.7rem;line-height:1;letter-spacing:-.02em;"></div>
                            <div id="live-date" class="font-semibold text-slate-400 mt-0.5 tracking-wide" style="font-size:.65rem;"></div>
                        </div>
                    </div>

                    <!-- ── PREDICTIVE TAB ── -->
                    <?php
                    $todaySchedArrVal = array_values($todaySchedArr);
                    $predTodayJson = json_encode(array_map(fn($r) => [
                        'machine'  => $r['machine_name'] ?? '-',
                        'point'    => $r['maintenance_point'] ?? '-',
                        'dept'     => $r['department'] ?? '',
                        'line'     => $r['line'] ?? '',
                        'interval' => ($r['interval_month'] ?? 0) . ' mo',
                    ], $todaySchedArrVal), JSON_UNESCAPED_UNICODE);
                    ?>
                    <div id="schedPredTab" class="<?= $activeTab === 'preventive' ? 'hidden' : '' ?>">
                        <!-- ── Status Checkbox Filter — Predictive ── -->
                        <div class="mb-1.5 flex items-center gap-2 bg-white/80 border border-slate-200 rounded-lg px-2.5 py-1" style="font-size:.65rem;">
                            <span class="font-black text-slate-400 uppercase tracking-widest whitespace-nowrap">Filter:</span>
                            <label class="flex items-center gap-1 cursor-pointer select-none">
                                <input type="checkbox" id="predCbOverdue" checked onchange="applyPredFilter()" class="w-3 h-3 accent-red-500">
                                <span class="font-bold text-red-600 whitespace-nowrap">Overdue</span>
                            </label>
                            <label class="flex items-center gap-1 cursor-pointer select-none">
                                <input type="checkbox" id="predCbAlert" checked onchange="applyPredFilter()" class="w-3 h-3 accent-yellow-500">
                                <span class="font-bold text-yellow-600 whitespace-nowrap">Alert</span>
                            </label>
                            <label class="flex items-center gap-1 cursor-pointer select-none">
                                <input type="checkbox" id="predCbReminder" checked onchange="applyPredFilter()" class="w-3 h-3 accent-orange-500">
                                <span class="font-bold text-orange-500 whitespace-nowrap">Reminder</span>
                            </label>
                            <label class="flex items-center gap-1 cursor-pointer select-none">
                                <input type="checkbox" id="predCbSecure" checked onchange="applyPredFilter()" class="w-3 h-3 accent-emerald-500">
                                <span class="font-bold text-emerald-600 whitespace-nowrap">Secure</span>
                            </label>
                            <div class="w-px h-3 bg-slate-200"></div>
                            <button onclick="predCheckAll(true)" class="font-bold text-slate-400 hover:text-blue-600 transition px-1 rounded hover:bg-blue-50">All</button>
                            <button onclick="predCheckAll(false)" class="font-bold text-slate-400 hover:text-red-500 transition px-1 rounded hover:bg-red-50">None</button>
                            <span id="predFilterCount" class="ml-auto font-bold text-slate-300 whitespace-nowrap"></span>
                        </div>
                        <!-- Today Schedule Card — Predictive -->
                        <?php if ($todayCount > 0): ?>
                            <div class="mb-4 rounded-2xl overflow-hidden" style="background:linear-gradient(135deg,#eff6ff,#dbeafe);border:1px solid #bfdbfe;">
                                <!-- Header -->
                                <div class="flex items-center gap-3 px-4 py-3">
                                    <div class="w-8 h-8 bg-blue-600 rounded-lg flex items-center justify-center flex-shrink-0">
                                        <i class="fas fa-calendar-day text-white text-xs"></i>
                                    </div>
                                    <span class="text-blue-800 font-bold text-sm">Today's Schedule</span>
                                    <span class="bg-blue-600 text-white text-[10px] font-black px-2 py-0.5 rounded-full"><?= $todayCount ?></span>
                                    <span class="ml-auto text-blue-400 text-[10px] font-semibold"><?= date('d M Y') ?></span>
                                </div>
                                <!-- List body -->
                                <div class="px-4 pb-3">
                                    <div class="grid gap-2" style="grid-template-columns:repeat(auto-fill,minmax(260px,1fr));">
                                        <?php foreach ($todaySchedArrVal as $i => $td): ?>
                                            <div class="bg-white/80 rounded-xl px-3 py-2.5 flex items-start gap-2.5 border border-blue-100 shadow-sm">
                                                <div class="w-6 h-6 rounded-lg bg-blue-100 flex items-center justify-center flex-shrink-0 mt-0.5">
                                                    <span class="text-blue-600 font-black" style="font-size:.6rem;"><?= $i + 1 ?></span>
                                                </div>
                                                <div class="min-w-0 flex-1">
                                                    <p class="font-black text-blue-900 truncate" style="font-size:.75rem;" title="<?= htmlspecialchars($td['machine_name'] ?? '-') ?>"><?= htmlspecialchars($td['machine_name'] ?? '-') ?></p>
                                                    <p class="text-blue-600 mt-0.5 truncate" style="font-size:.67rem;" title="<?= htmlspecialchars($td['maintenance_point'] ?? '-') ?>"><?= htmlspecialchars($td['maintenance_point'] ?? '-') ?></p>
                                                    <?php if (!empty($td['department']) || !empty($td['line'])): ?>
                                                        <p class="text-blue-400 mt-0.5 truncate" style="font-size:.62rem;">
                                                            <?= htmlspecialchars($td['department'] ?? '') ?><?= !empty($td['line']) ? ' · ' . htmlspecialchars($td['line']) : '' ?>
                                                        </p>
                                                    <?php endif; ?>
                                                </div>
                                                <span class="flex-shrink-0 bg-blue-100 text-blue-700 font-bold px-1.5 py-0.5 rounded" style="font-size:.6rem;"><?= (int)($td['interval_month'] ?? 0) ?>mo</span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="today-banner mb-4" style="background:#f8fafc;border:1px solid #e2e8f0;">
                                <div class="w-9 h-9 bg-slate-200 rounded-xl flex items-center justify-center flex-shrink-0">
                                    <i class="fas fa-calendar-day text-slate-400 text-sm"></i>
                                </div>
                                <span class="text-slate-500">No predictive schedule for today</span>
                            </div>
                        <?php endif; ?>

                        <!-- Table -->
                        <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                            <div class="table-scroll">
                                <table class="w-full text-left border-collapse" style="table-layout:fixed;width:100%;">
                                    <colgroup>
                                        <col style="width:2.2rem;"> <!-- No -->
                                        <col style="width:16%;"> <!-- Machine Info -->
                                        <col style="width:26%;"> <!-- Maintenance Point — lebih lebar agar full -->
                                        <col style="width:9%;"> <!-- Last Change -->
                                        <col style="width:5%;"> <!-- Interval -->
                                        <col style="width:9%;"> <!-- Change Date Plan -->
                                        <col style="width:7%;"> <!-- Remaining -->
                                        <col style="width:8%;"> <!-- Part Order -->
                                        <col style="width:9%;"> <!-- Part Availability -->
                                        <col style="width:8%;"> <!-- Maint. Status -->
                                    </colgroup>
                                    <thead style="background:linear-gradient(135deg, #1e40af, #2563eb);position:sticky;top:0;z-index:10;">
                                        <tr>
                                            <th class="tbl-th px-2 py-2" style="font-size:.6rem;">No</th>
                                            <th class="tbl-th px-2 py-2" style="font-size:.6rem;">Machine</th>
                                            <th class="tbl-th px-2 py-2" style="font-size:.6rem;">Maint. Point</th>
                                            <th class="tbl-th px-2 py-2 text-center" style="font-size:.6rem;">Last Change</th>
                                            <th class="tbl-th px-2 py-2 text-center" style="font-size:.6rem;">Intv.</th>
                                            <th class="tbl-th px-2 py-2 text-center" style="font-size:.6rem;">Plan Date</th>
                                            <th class="tbl-th px-2 py-2 text-center" style="font-size:.6rem;">Rem. (d)</th>
                                            <th class="tbl-th px-2 py-2 text-center" style="font-size:.6rem;">Part Order</th>
                                            <th class="tbl-th px-2 py-2 text-center" style="font-size:.6rem;">Part Avail.</th>
                                            <th class="tbl-th px-2 py-2 text-center" style="font-size:.6rem;">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($schedules)): ?>
                                            <tr>
                                                <td colspan="10" class="tbl-td text-center py-16 text-slate-400">
                                                    <i class="fas fa-calendar-xmark text-4xl block mb-3 text-slate-200"></i>
                                                    <p class="font-semibold">Belum ada data schedule predictive.</p>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($schedules as $i => $row):
                                                $days = (int)$row['remaining_day'];
                                                $daysCls = remainingClass($days);
                                                $useDate = $row['use_date'] ? date('d M Y', strtotime($row['use_date'])) : '-';
                                                $planDate = $row['change_date_plan'] ? date('d M Y', strtotime($row['change_date_plan'])) : '-';
                                                $reminder = (int)($row['reminder_activity'] ?? 30);
                                                if ($days <= 0) $rowStatus = 'overdue';
                                                elseif ($days <= 7) $rowStatus = 'alert';
                                                elseif ($days <= $reminder) $rowStatus = 'reminder';
                                                else $rowStatus = 'secure';
                                            ?>
                                                <tr class="pred-sched-row" data-status="<?= $rowStatus ?>">
                                                    <td class="tbl-td text-slate-400 font-mono px-2 py-2" style="font-size:.68rem;"><?= $i + 1 ?></td>
                                                    <td class="tbl-td px-2 py-2" style="overflow:hidden;">
                                                        <div class="font-bold text-slate-800 leading-tight" style="font-size:.72rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($row['machine_name'] ?? '-') ?>"><?= htmlspecialchars($row['machine_name'] ?? '-') ?></div>
                                                        <?php if (!empty($row['process_machine'])): ?>
                                                            <div class="text-slate-500 mt-0.5" style="font-size:.65rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($row['process_machine']) ?></div>
                                                        <?php endif; ?>
                                                        <div class="text-slate-400 mt-0.5" style="font-size:.62rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                                            <?= htmlspecialchars($row['department'] ?? '') ?><?= !empty($row['line']) ? ' · ' . htmlspecialchars($row['line']) : '' ?>
                                                        </div>
                                                    </td>
                                                    <td class="tbl-td px-2 py-2">
                                                        <span style="font-size:.72rem;color:#334155;display:block;word-break:break-word;white-space:normal;line-height:1.4;" title="<?= htmlspecialchars($row['maintenance_point'] ?? '-') ?>"><?= htmlspecialchars($row['maintenance_point'] ?? '-') ?></span>
                                                        <?php if (!empty($row['name_unit'])): ?>
                                                            <div class="text-slate-400 italic mt-0.5" style="font-size:.62rem;word-break:break-word;white-space:normal;"><?= htmlspecialchars($row['name_unit']) ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="tbl-td text-center text-slate-500 px-1 py-2" style="font-size:.65rem;"><?= $useDate ?></td>
                                                    <td class="tbl-td text-center px-1 py-2">
                                                        <span class="bg-slate-100 text-slate-600 font-bold px-1.5 py-0.5 rounded" style="font-size:.65rem;">
                                                            <?= (int)($row['interval_month'] ?? 0) ?>mo
                                                        </span>
                                                    </td>
                                                    <td class="tbl-td text-center text-slate-600 font-semibold px-1 py-2" style="font-size:.65rem;"><?= $planDate ?></td>
                                                    <td class="tbl-td text-center px-1 py-2 <?= $daysCls ?>" style="font-size:.75rem;"><?= $days ?></td>
                                                    <td class="tbl-td text-center px-1 py-2"><?= partOrderBadge($row['part_order'] ?? 'close') ?></td>
                                                    <td class="tbl-td text-center px-1 py-2"><?= partOrderBadge($row['part_availability'] ?? 'close') ?></td>
                                                    <td class="tbl-td text-center px-1 py-2"><?= maintenanceStatusBadge($row['maintenance_status'] ?? '') ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="px-5 py-3 bg-slate-50 border-t border-slate-100 flex justify-between items-center text-xs text-slate-400">
                                <span><?= count($schedules) ?> jadwal predictive</span>
                                <?php date_default_timezone_set('Asia/Jakarta'); ?>
                                <span>Last updated: <?= date('d M Y H:i') ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- ── PREVENTIVE TAB ── -->
                    <?php
                    $prevTodayArrVal = array_values($prevTodayArr);
                    $prevTodayJson = json_encode(array_map(fn($r) => [
                        'machine'  => $r['machine_name'] ?? '-',
                        'point'    => $r['maintenance_point'] ?? '-',
                        'dept'     => $r['department'] ?? '',
                        'line'     => $r['line'] ?? '',
                        'interval' => ($r['interval_month'] ?? 0) . ' mo',
                    ], $prevTodayArrVal), JSON_UNESCAPED_UNICODE);
                    ?>
                    <div id="schedPrevTab" class="<?= $activeTab === 'predictive' ? 'hidden' : '' ?>">
                        <!-- ── Status Checkbox Filter — Preventive ── -->
                        <div class="mb-1.5 flex items-center gap-2 bg-white/80 border border-slate-200 rounded-lg px-2.5 py-1" style="font-size:.65rem;">
                            <span class="font-black text-slate-400 uppercase tracking-widest whitespace-nowrap">Filter:</span>
                            <label class="flex items-center gap-1 cursor-pointer select-none">
                                <input type="checkbox" id="prevCbOverdue" checked onchange="applyPrevFilter()" class="w-3 h-3 accent-red-500">
                                <span class="font-bold text-red-600 whitespace-nowrap">Overdue</span>
                            </label>
                            <label class="flex items-center gap-1 cursor-pointer select-none">
                                <input type="checkbox" id="prevCbAlert" checked onchange="applyPrevFilter()" class="w-3 h-3 accent-yellow-500">
                                <span class="font-bold text-yellow-600 whitespace-nowrap">Alert</span>
                            </label>
                            <label class="flex items-center gap-1 cursor-pointer select-none">
                                <input type="checkbox" id="prevCbReminder" checked onchange="applyPrevFilter()" class="w-3 h-3 accent-orange-500">
                                <span class="font-bold text-orange-500 whitespace-nowrap">Reminder</span>
                            </label>
                            <label class="flex items-center gap-1 cursor-pointer select-none">
                                <input type="checkbox" id="prevCbSecure" checked onchange="applyPrevFilter()" class="w-3 h-3 accent-emerald-500">
                                <span class="font-bold text-emerald-600 whitespace-nowrap">Secure</span>
                            </label>
                            <div class="w-px h-3 bg-slate-200"></div>
                            <button onclick="prevCheckAll(true)" class="font-bold text-slate-400 hover:text-indigo-600 transition px-1 rounded hover:bg-indigo-50">All</button>
                            <button onclick="prevCheckAll(false)" class="font-bold text-slate-400 hover:text-red-500 transition px-1 rounded hover:bg-red-50">None</button>
                            <span id="prevFilterCount" class="ml-auto font-bold text-slate-300 whitespace-nowrap"></span>
                        </div>
                        <!-- Today Schedule Card — Preventive -->
                        <?php if ($prevTodayCount > 0): ?>
                            <div class="mb-4 rounded-2xl overflow-hidden" style="background:linear-gradient(135deg,#eef2ff,#e0e7ff);border:1px solid #a5b4fc;">
                                <!-- Header -->
                                <div class="flex items-center gap-3 px-4 py-3">
                                    <div class="w-8 h-8 bg-indigo-600 rounded-lg flex items-center justify-center flex-shrink-0">
                                        <i class="fas fa-calendar-day text-white text-xs"></i>
                                    </div>
                                    <span class="text-indigo-800 font-bold text-sm">Today's Schedule</span>
                                    <span class="bg-indigo-600 text-white text-[10px] font-black px-2 py-0.5 rounded-full"><?= $prevTodayCount ?></span>
                                    <span class="ml-auto text-indigo-400 text-[10px] font-semibold"><?= date('d M Y') ?></span>
                                </div>
                                <!-- List body -->
                                <div class="px-4 pb-3">
                                    <div class="grid gap-2" style="grid-template-columns:repeat(auto-fill,minmax(260px,1fr));">
                                        <?php foreach ($prevTodayArrVal as $i => $td): ?>
                                            <div class="bg-white/80 rounded-xl px-3 py-2.5 flex items-start gap-2.5 border border-indigo-100 shadow-sm">
                                                <div class="w-6 h-6 rounded-lg bg-indigo-100 flex items-center justify-center flex-shrink-0 mt-0.5">
                                                    <span class="text-indigo-600 font-black" style="font-size:.6rem;"><?= $i + 1 ?></span>
                                                </div>
                                                <div class="min-w-0 flex-1">
                                                    <p class="font-black text-indigo-900 truncate" style="font-size:.75rem;" title="<?= htmlspecialchars($td['machine_name'] ?? '-') ?>"><?= htmlspecialchars($td['machine_name'] ?? '-') ?></p>
                                                    <p class="text-indigo-600 mt-0.5 truncate" style="font-size:.67rem;" title="<?= htmlspecialchars($td['maintenance_point'] ?? '-') ?>"><?= htmlspecialchars($td['maintenance_point'] ?? '-') ?></p>
                                                    <?php if (!empty($td['department']) || !empty($td['line'])): ?>
                                                        <p class="text-indigo-400 mt-0.5 truncate" style="font-size:.62rem;">
                                                            <?= htmlspecialchars($td['department'] ?? '') ?><?= !empty($td['line']) ? ' · ' . htmlspecialchars($td['line']) : '' ?>
                                                        </p>
                                                    <?php endif; ?>
                                                </div>
                                                <span class="flex-shrink-0 bg-indigo-100 text-indigo-700 font-bold px-1.5 py-0.5 rounded" style="font-size:.6rem;"><?= (int)($td['interval_month'] ?? 0) ?>mo</span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="today-banner mb-4" style="background:#f8fafc;border:1px solid #e2e8f0;">
                                <div class="w-9 h-9 bg-slate-200 rounded-xl flex items-center justify-center flex-shrink-0">
                                    <i class="fas fa-calendar-day text-slate-400 text-sm"></i>
                                </div>
                                <span class="text-slate-500">No preventive schedule for today</span>
                            </div>
                        <?php endif; ?>

                        <!-- Table -->
                        <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                            <div class="table-scroll">
                                <table class="w-full text-left border-collapse" style="min-width:780px;">
                                    <thead style="background:linear-gradient(135deg, #3730a3, #4f46e5);position:sticky;top:0;z-index:10;">
                                        <tr>
                                            <th class="tbl-th" style="width:32px;">No</th>
                                            <th class="tbl-th">Machine Information</th>
                                            <th class="tbl-th">Maintenance Point</th>
                                            <th class="tbl-th text-center">Last Change</th>
                                            <th class="tbl-th text-center">Interval</th>
                                            <th class="tbl-th text-center">Change Date Plan</th>
                                            <th class="tbl-th text-center">Remaining (Day(s))</th>
                                            <th class="tbl-th text-center">Maint. Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($prevSchedules)): ?>
                                            <tr>
                                                <td colspan="8" class="tbl-td text-center py-16 text-slate-400">
                                                    <i class="fas fa-shield-halved text-4xl block mb-3 text-slate-200"></i>
                                                    <p class="font-semibold">Belum ada data schedule preventive.</p>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($prevSchedules as $i => $row):
                                                $days = (int)($row['remaining_day'] ?? 0);
                                                $daysCls = remainingClass($days);
                                                $useDate = !empty($row['use_date']) ? date('d M Y', strtotime($row['use_date'])) : '-';
                                                $planDate = !empty($row['change_date_plan']) ? date('d M Y', strtotime($row['change_date_plan'])) : '-';
                                                $pReminder = (int)($row['reminder_activity'] ?? 30);
                                                if ($days <= 0) $pRowStatus = 'overdue';
                                                elseif ($days <= 7) $pRowStatus = 'alert';
                                                elseif ($days <= $pReminder) $pRowStatus = 'reminder';
                                                else $pRowStatus = 'secure';
                                            ?>
                                                <tr class="prev-sched-row" data-status="<?= $pRowStatus ?>">
                                                    <td class="tbl-td text-slate-400 text-xs font-mono"><?= $i + 1 ?></td>
                                                    <td class="tbl-td" style="min-width:160px;">
                                                        <div class="font-bold text-slate-800 text-sm leading-tight"><?= htmlspecialchars($row['machine_name'] ?? '-') ?></div>
                                                        <?php if (!empty($row['process_machine'])): ?>
                                                            <div class="text-xs text-slate-500 mt-0.5"><?= htmlspecialchars($row['process_machine']) ?></div>
                                                        <?php endif; ?>
                                                        <div class="text-[11px] text-slate-400 mt-0.5">
                                                            <?= htmlspecialchars($row['department'] ?? '') ?><?= !empty($row['line']) ? ' · ' . htmlspecialchars($row['line']) : '' ?>
                                                        </div>
                                                    </td>
                                                    <td class="tbl-td" style="max-width:160px;">
                                                        <span class="text-sm text-slate-700"><?= htmlspecialchars($row['maintenance_point'] ?? '-') ?></span>
                                                        <?php if (!empty($row['name_unit'])): ?>
                                                            <div class="text-xs text-slate-400 italic mt-0.5"><?= htmlspecialchars($row['name_unit']) ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="tbl-td text-center text-xs text-slate-500 whitespace-nowrap"><?= $useDate ?></td>
                                                    <td class="tbl-td text-center">
                                                        <span class="bg-slate-100 text-slate-600 font-bold px-2.5 py-1 rounded-lg text-xs">
                                                            <?= (int)($row['interval_month'] ?? 0) ?> mo
                                                        </span>
                                                    </td>
                                                    <td class="tbl-td text-center text-xs whitespace-nowrap font-semibold text-slate-600"><?= $planDate ?></td>
                                                    <td class="tbl-td text-center <?= $daysCls ?> whitespace-nowrap"><?= $days ?></td>
                                                    <td class="tbl-td text-center"><?= maintenanceStatusBadge($row['maintenance_status'] ?? '') ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="px-5 py-3 bg-slate-50 border-t border-slate-100 flex justify-between items-center text-xs text-slate-400">
                                <span><?= count($prevSchedules) ?> jadwal preventive</span>
                                <?php date_default_timezone_set('Asia/Jakarta'); ?>
                                <span>Last updated: <?= date('d M Y H:i') ?></span>
                            </div>
                        </div>
                    </div>

                </div><!-- /sectionSchedule -->


                <!-- ═══════════════════════════════════════════════════════════
         SECTION: PART AVAILABILITY
    ═══════════════════════════════════════════════════════════ -->
                <div id="sectionParts" class="section-enter <?= $activeSection !== 'parts' ? 'hidden' : '' ?>">
                    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                        <div class="table-scroll">
                            <table class="w-full text-left border-collapse" id="partsTable" style="min-width:700px;">
                                <thead style="background:linear-gradient(135deg,#0f766e,#0d9488);position:sticky;top:0;z-index:10;">
                                    <tr>
                                        <th class="tbl-th" style="width:32px;">No</th>
                                        <th class="tbl-th">Item Code</th>
                                        <th class="tbl-th">Item Description</th>
                                        <th class="tbl-th text-center">Safety Stock</th>
                                        <th class="tbl-th text-center">Actual Stock</th>
                                        <th class="tbl-th text-center">Effective Stock</th>
                                        <th class="tbl-th text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($parts)): ?>
                                        <tr>
                                            <td colspan="7" class="tbl-td text-center py-16 text-slate-400">
                                                <i class="fas fa-box-open text-4xl block mb-3 text-slate-200"></i>
                                                <p class="font-semibold">Belum ada data part.</p>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($parts as $i => $part):
                                            $actual    = (int)$part['actual_stock'];
                                            $safety    = (int)$part['safety_stock'];
                                            $effective = (int)$part['effective_stock'];
                                            $status    = getPartStatusStr($actual, $safety);
                                            $badgeCls  = getPartStatusClass($status);
                                            $actualCls = $actual === 0 ? 'text-red-500' : ($actual < $safety ? 'text-orange-500' : ($actual === $safety ? 'text-emerald-600' : 'text-violet-600'));
                                        ?>
                                            <tr>
                                                <td class="tbl-td text-slate-400 text-xs font-mono"><?= $i + 1 ?></td>
                                                <td class="tbl-td font-mono font-bold text-slate-700 tracking-wide text-sm"><?= htmlspecialchars($part['item_code']) ?></td>
                                                <td class="tbl-td text-slate-700 text-sm font-medium" style="max-width:260px;"><?= htmlspecialchars($part['item_description'] ?? '-') ?></td>
                                                <td class="tbl-td text-center">
                                                    <span class="bg-slate-100 text-slate-600 font-bold px-3 py-1 rounded-lg text-sm"><?= $safety ?></span>
                                                </td>
                                                <td class="tbl-td text-center font-black text-lg <?= $actualCls ?>"><?= $actual ?></td>
                                                <td class="tbl-td text-center font-bold text-sm <?= $effective < 0 ? 'text-red-500' : 'text-slate-600' ?>">
                                                    <?= ($effective >= 0 ? '+' : '') . $effective ?>
                                                </td>
                                                <td class="tbl-td text-center">
                                                    <span class="badge <?= $badgeCls ?>"><?= $status ?></span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="px-5 py-3 bg-slate-50 border-t border-slate-100 flex justify-between items-center text-xs text-slate-400">
                            <span><?= count($parts) ?> parts</span>
                            <?php date_default_timezone_set('Asia/Jakarta'); ?>
                            <span>Last updated: <?= date('d M Y H:i') ?></span>
                        </div>
                    </div>
                </div><!-- /sectionParts -->


                <!-- ═══════════════════════════════════════════════════════════
         SECTION: HISTORY
    ═══════════════════════════════════════════════════════════ -->
                <div id="sectionHistory" class="section-enter <?= $activeSection !== 'history' ? 'hidden' : '' ?>">

                    <!-- Sub-tab + Clock -->
                    <div class="mb-5 flex items-center gap-3">
                        <div class="relative bg-slate-100 rounded-2xl p-1.5 flex gap-1 shadow-inner" style="max-width:520px;flex:1;">
                            <div id="histTabIndicator" class="subtab-indicator"
                                style="background:linear-gradient(135deg,#f59e0b,#d97706);width:calc(50% - 4px);transform:translateX(0);"></div>
                            <button id="histTabPred" onclick="switchHistTab('predictive')"
                                class="relative z-10 flex-1 flex items-center justify-center gap-2 py-3 px-5 font-bold text-sm rounded-xl transition-all duration-300 text-white">
                                <i class="fas fa-chart-line"></i> Predictive
                                <span class="ml-1 text-[10px] font-black px-2 py-0.5 rounded-full bg-white/20 text-white"><?= count($historiesPred) ?></span>
                            </button>
                            <button id="histTabPrev" onclick="switchHistTab('preventive')"
                                class="relative z-10 flex-1 flex items-center justify-center gap-2 py-3 px-5 font-bold text-sm rounded-xl transition-all duration-300 text-slate-500">
                                <i class="fas fa-shield-halved"></i> Preventive
                                <span class="ml-1 text-[10px] font-black px-2 py-0.5 rounded-full bg-slate-300/60 text-slate-600"><?= count($historiesPrev) ?></span>
                            </button>
                        </div>
                        <!-- Digital Clock — History -->
                        <div class="flex flex-col items-end ml-auto flex-shrink-0">
                            <div class="live-clock-mirror font-mono font-black text-slate-800 tabular-nums" style="font-size:1.7rem;line-height:1;letter-spacing:-.02em;"></div>
                            <div class="live-date-mirror font-semibold text-slate-400 mt-0.5 tracking-wide" style="font-size:.65rem;"></div>
                        </div>
                    </div>

                    <!-- ── Month filter bar — History ── -->
                    <div class="mb-1.5 flex items-center gap-2 bg-white/80 border border-slate-200 rounded-lg px-2.5 py-1" style="font-size:.65rem;">
                        <i class="fas fa-calendar-alt text-amber-500" style="font-size:.6rem;"></i>
                        <span class="font-black text-slate-400 uppercase tracking-widest whitespace-nowrap">Bulan:</span>
                        <input type="month" id="histMonthFilter" value=""
                            class="bg-transparent border-0 text-slate-700 font-bold focus:outline-none focus:ring-1 focus:ring-amber-400 rounded px-1 py-0 transition"
                            style="font-size:.65rem;height:1.3rem;"
                            onchange="applyHistMonthFilter()">
                        <button onclick="clearHistMonthFilter()"
                            class="font-bold text-slate-400 hover:text-red-500 transition px-1 rounded hover:bg-red-50">&#10007;</button>
                        <span id="histMonthCount" class="ml-auto font-bold text-slate-300 whitespace-nowrap"></span>
                    </div>

                    <!-- ── History: Predictive table ── -->
                    <div id="histPredTab">
                        <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                            <div class="table-scroll">
                                <table class="w-full text-left border-collapse" style="min-width:760px;">
                                    <thead style="background:linear-gradient(135deg,#b45309,#d97706);position:sticky;top:0;z-index:10;">
                                        <tr>
                                            <th class="tbl-th" style="width:32px;">No</th>
                                            <th class="tbl-th">Machine Info</th>
                                            <th class="tbl-th">Maintenance Point</th>
                                            <th class="tbl-th text-center">Change Date Plan</th>
                                            <th class="tbl-th">Note</th>
                                            <th class="tbl-th text-center">Reported At</th>
                                            <th class="tbl-th text-center">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($historiesPred)): ?>
                                            <tr>
                                                <td colspan="7" class="tbl-td text-center py-16 text-slate-400">
                                                    <i class="fas fa-history text-4xl block mb-3 text-slate-200"></i>
                                                    <p class="font-semibold">Belum ada history predictive.</p>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($historiesPred as $i => $h):
                                                $planDate   = !empty($h['change_date_plan']) ? date('d M Y', strtotime($h['change_date_plan'])) : '-';
                                                $reportedAt = !empty($h['reported_at']) ? date('d M Y H:i', strtotime($h['reported_at'])) : '-';
                                                $noteShort  = mb_strlen($h['note'] ?? '') > 55 ? mb_substr($h['note'], 0, 55) . '…' : ($h['note'] ?? '-');
                                                $rowMonth   = !empty($h['reported_at']) ? date('Y-m', strtotime($h['reported_at'])) : '';
                                            ?>
                                                <tr class="hist-pred-row" data-month="<?= $rowMonth ?>">
                                                    <td class="tbl-td text-slate-400 text-xs font-mono"><?= $i + 1 ?></td>
                                                    <td class="tbl-td" style="min-width:160px;">
                                                        <div class="font-bold text-slate-800 text-sm leading-tight whitespace-nowrap"><?= htmlspecialchars($h['machine_name'] ?? '-') ?></div>
                                                        <div class="text-xs text-slate-500 mt-0.5"><?= htmlspecialchars($h['department'] ?? '') ?><?= !empty($h['line']) ? ' | ' . htmlspecialchars($h['line']) : '' ?></div>
                                                        <?php if (!empty($h['name_unit'])): ?><div class="text-xs text-slate-400 italic mt-0.5"><?= htmlspecialchars($h['name_unit']) ?></div><?php endif; ?>
                                                    </td>
                                                    <td class="tbl-td text-slate-600 text-sm" style="max-width:180px;"><?= htmlspecialchars($h['maintenance_point'] ?? '-') ?></td>
                                                    <td class="tbl-td text-center text-xs text-slate-500 whitespace-nowrap font-mono"><?= $planDate ?></td>
                                                    <td class="tbl-td text-slate-500 text-sm" style="max-width:200px;"><?= htmlspecialchars($noteShort) ?></td>
                                                    <td class="tbl-td text-center text-xs text-slate-500 whitespace-nowrap"><?= $reportedAt ?></td>
                                                    <td class="tbl-td text-center">
                                                        <span class="inline-flex items-center gap-1 bg-emerald-50 text-emerald-700 border border-emerald-100 px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-wide">
                                                            <i class="fas fa-check-circle text-[9px]"></i> Done
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="px-5 py-3 bg-slate-50 border-t border-slate-100 flex justify-between items-center text-xs text-slate-400">
                                <span><?= count($historiesPred) ?> laporan predictive</span>
                                <?php date_default_timezone_set('Asia/Jakarta'); ?>
                                <span>Last updated: <?= date('d M Y H:i') ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- ── History: Preventive table ── -->
                    <div id="histPrevTab" class="hidden">
                        <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                            <div class="table-scroll">
                                <table class="w-full text-left border-collapse" style="min-width:760px;">
                                    <thead style="background:linear-gradient(135deg,#c2410c,#f97316);position:sticky;top:0;z-index:10;">
                                        <tr>
                                            <th class="tbl-th" style="width:32px;">No</th>
                                            <th class="tbl-th">Machine Info</th>
                                            <th class="tbl-th">Maintenance Point</th>
                                            <th class="tbl-th text-center">Change Date Plan</th>
                                            <th class="tbl-th">Note</th>
                                            <th class="tbl-th text-center">Reported At</th>
                                            <th class="tbl-th text-center">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($historiesPrev)): ?>
                                            <tr>
                                                <td colspan="7" class="tbl-td text-center py-16 text-slate-400">
                                                    <i class="fas fa-shield-halved text-4xl block mb-3 text-slate-200"></i>
                                                    <p class="font-semibold">Belum ada history preventive.</p>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($historiesPrev as $i => $h):
                                                $planDate   = !empty($h['change_date_plan']) ? date('d M Y', strtotime($h['change_date_plan'])) : '-';
                                                $reportedAt = !empty($h['reported_at']) ? date('d M Y H:i', strtotime($h['reported_at'])) : '-';
                                                $noteShort  = mb_strlen($h['note'] ?? '') > 55 ? mb_substr($h['note'], 0, 55) . '…' : ($h['note'] ?? '-');
                                                $rowMonth   = !empty($h['reported_at']) ? date('Y-m', strtotime($h['reported_at'])) : '';
                                            ?>
                                                <tr class="hist-prev-row" data-month="<?= $rowMonth ?>">
                                                    <td class="tbl-td text-slate-400 text-xs font-mono"><?= $i + 1 ?></td>
                                                    <td class="tbl-td" style="min-width:160px;">
                                                        <div class="font-bold text-slate-800 text-sm leading-tight whitespace-nowrap"><?= htmlspecialchars($h['machine_name'] ?? '-') ?></div>
                                                        <div class="text-xs text-slate-500 mt-0.5"><?= htmlspecialchars($h['department'] ?? '') ?><?= !empty($h['line']) ? ' | ' . htmlspecialchars($h['line']) : '' ?></div>
                                                        <?php if (!empty($h['name_unit'])): ?><div class="text-xs text-slate-400 italic mt-0.5"><?= htmlspecialchars($h['name_unit']) ?></div><?php endif; ?>
                                                    </td>
                                                    <td class="tbl-td text-slate-600 text-sm" style="max-width:180px;"><?= htmlspecialchars($h['maintenance_point'] ?? '-') ?></td>
                                                    <td class="tbl-td text-center text-xs text-slate-500 whitespace-nowrap font-mono"><?= $planDate ?></td>
                                                    <td class="tbl-td text-slate-500 text-sm" style="max-width:200px;"><?= htmlspecialchars($noteShort) ?></td>
                                                    <td class="tbl-td text-center text-xs text-slate-500 whitespace-nowrap"><?= $reportedAt ?></td>
                                                    <td class="tbl-td text-center">
                                                        <span class="inline-flex items-center gap-1 bg-teal-50 text-teal-700 border border-teal-100 px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-wide">
                                                            <i class="fas fa-check-circle text-[9px]"></i> Done
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="px-5 py-3 bg-slate-50 border-t border-slate-100 flex justify-between items-center text-xs text-slate-400">
                                <span><?= count($historiesPrev) ?> laporan preventive</span>
                                <?php date_default_timezone_set('Asia/Jakarta'); ?>
                                <span>Last updated: <?= date('d M Y H:i') ?></span>
                            </div>
                        </div>
                    </div>

                </div><!-- /sectionHistory -->

            </div><!-- /max-w -->
        </div><!-- /main-content -->
    </div><!-- /app-layout -->

    <script>
        // ══════════════════════════════════════════════════════════════
        //  Sidebar toggle
        // ══════════════════════════════════════════════════════════════
        const sidebar = document.getElementById('sidebar');
        const toggleBtn = document.getElementById('sidebarToggle');
        const toggleIcon = document.getElementById('sidebarToggleIcon');
        let sidebarOpen = true;

        toggleBtn.addEventListener('click', () => {
            sidebarOpen = !sidebarOpen;
            sidebar.classList.toggle('collapsed', !sidebarOpen);
            document.body.classList.toggle('sidebar-collapsed', !sidebarOpen);
            toggleIcon.classList.toggle('fa-chevron-left', sidebarOpen);
            toggleIcon.classList.toggle('fa-chevron-right', !sidebarOpen);
        });

        // ══════════════════════════════════════════════════════════════
        //  Section switching
        // ══════════════════════════════════════════════════════════════
        const SECTION_META = {
            schedule: {
                nav: 'navSchedule',
                el: 'sectionSchedule',
                cls: 'active-schedule'
            },
            parts: {
                nav: 'navParts',
                el: 'sectionParts',
                cls: 'active-parts'
            },
            history: {
                nav: 'navHistory',
                el: 'sectionHistory',
                cls: 'active-history'
            },
        };
        let _activeSection = '<?= $activeSection ?>';

        function switchSection(sec) {
            if (sec === _activeSection) return;
            Object.entries(SECTION_META).forEach(([k, m]) => {
                document.getElementById(m.el).classList.toggle('hidden', k !== sec);
                const btn = document.getElementById(m.nav);
                if (k === sec) {
                    btn.classList.add(m.cls);
                    document.getElementById(m.el).classList.add('section-enter');
                    setTimeout(() => document.getElementById(m.el).classList.remove('section-enter'), 300);
                } else {
                    Object.values(SECTION_META).forEach(mm => btn.classList.remove(mm.cls));
                }
            });
            _activeSection = sec;
            const url = new URL(window.location);
            url.searchParams.set('section', sec);
            window.history.replaceState({}, '', url);
            // Reset scroll state for new section
            resetScrollState();
        }

        // ══════════════════════════════════════════════════════════════
        //  Schedule sub-tab
        // ══════════════════════════════════════════════════════════════
        let _schedTab = '<?= $activeTab ?>';

        function switchSchedTab(tab) {
            const isPred = tab === 'predictive';
            document.getElementById('schedPredTab').classList.toggle('hidden', !isPred);
            document.getElementById('schedPrevTab').classList.toggle('hidden', isPred);

            const ind = document.getElementById('schedTabIndicator');
            ind.style.transform = isPred ? 'translateX(0)' : 'translateX(calc(100% + 4px))';
            ind.style.background = isPred ?
                'linear-gradient(135deg,#2563eb,#1d4ed8)' :
                'linear-gradient(135deg,#4338ca,#6366f1)';

            document.getElementById('schedTabPred').style.color = isPred ? '#fff' : '#64748b';
            document.getElementById('schedTabPrev').style.color = !isPred ? '#fff' : '#64748b';

            const bp = document.getElementById('schedTabPred').querySelector('span');
            const bv = document.getElementById('schedTabPrev').querySelector('span');
            if (bp) bp.className = 'ml-1 text-[10px] font-black px-2 py-0.5 rounded-full ' +
                (isPred ? 'bg-white/20 text-white' : 'bg-slate-300/60 text-slate-600');
            if (bv) bv.className = 'ml-1 text-[10px] font-black px-2 py-0.5 rounded-full ' +
                (!isPred ? 'bg-white/20 text-white' : 'bg-slate-300/60 text-slate-600');

            _schedTab = tab;
        }

        // ══════════════════════════════════════════════════════════════
        //  History sub-tab
        // ══════════════════════════════════════════════════════════════
        let _histTab = 'predictive';

        function switchHistTab(tab) {
            const isPred = tab === 'predictive';
            document.getElementById('histPredTab').classList.toggle('hidden', !isPred);
            document.getElementById('histPrevTab').classList.toggle('hidden', isPred);

            const ind = document.getElementById('histTabIndicator');
            ind.style.transform = isPred ? 'translateX(0)' : 'translateX(calc(100% + 4px))';
            ind.style.background = isPred ?
                'linear-gradient(135deg,#f59e0b,#d97706)' :
                'linear-gradient(135deg,#f97316,#ea580c)';

            document.getElementById('histTabPred').style.color = isPred ? '#fff' : '#64748b';
            document.getElementById('histTabPrev').style.color = !isPred ? '#fff' : '#64748b';

            const bp = document.getElementById('histTabPred').querySelector('span');
            const bv = document.getElementById('histTabPrev').querySelector('span');
            if (bp) bp.className = 'ml-1 text-[10px] font-black px-2 py-0.5 rounded-full ' +
                (isPred ? 'bg-white/20 text-white' : 'bg-slate-300/60 text-slate-600');
            if (bv) bv.className = 'ml-1 text-[10px] font-black px-2 py-0.5 rounded-full ' +
                (!isPred ? 'bg-white/20 text-white' : 'bg-slate-300/60 text-slate-600');

            _histTab = tab;
        }

        // ══════════════════════════════════════════════════════════════
        //  Auto-scroll engine
        //  - Scroll per section, tidak lintas section
        //  - Section dengan 2 tab: scroll down → up → ganti tab → scroll down → up → ... (loop)
        //  - Section tanpa tab (parts): scroll down → up → ... (loop)
        //  - Berhenti saat kursor gerak, resume 2 detik setelah diam
        // ══════════════════════════════════════════════════════════════
        const SCROLL_SPEED = 0.8; // px per frame
        const SCROLL_PAUSE = 2000; // ms jeda di atas/bawah sebelum balik / ganti tab
        const RESUME_DELAY = 2000; // ms tunggu setelah kursor diam

        let scrollRAF = null;
        let scrollPaused = false; // paused karena mouse gerak
        let scrollDir = 1; // 1 = turun, -1 = naik
        let resumeTimer = null;
        let pauseTimer = null;
        let tabPhase = 0; // index tab saat ini (untuk section yang punya tab)

        const statusEl = document.getElementById('scroll-status');
        const statusDot = document.getElementById('scroll-dot');
        const statusLabel = document.getElementById('scroll-label');

        function showStatus(paused) {
            statusEl.classList.add('visible');
            statusDot.style.background = paused ? '#f59e0b' : '#22c55e';
            statusLabel.textContent = paused ? 'Scroll dijeda (kursor aktif)' : 'Auto-scroll aktif';
        }

        function hideStatus() {
            statusEl.classList.remove('visible');
        }

        // Kembalikan elemen scrollable pada view aktif
        function getActiveScrollEl() {
            if (_activeSection === 'schedule') {
                const tabEl = _schedTab === 'predictive' ?
                    document.getElementById('schedPredTab') :
                    document.getElementById('schedPrevTab');
                // Cari .table-scroll di dalam tab aktif
                return tabEl ? tabEl.querySelector('.table-scroll') : null;
            }
            if (_activeSection === 'parts') {
                return document.querySelector('#sectionParts .table-scroll');
            }
            if (_activeSection === 'history') {
                const tabEl = _histTab === 'predictive' ?
                    document.getElementById('histPredTab') :
                    document.getElementById('histPrevTab');
                return tabEl ? tabEl.querySelector('.table-scroll') : null;
            }
            return null;
        }

        // Cek apakah section aktif punya 2 tab
        function sectionHasTabs() {
            return _activeSection === 'schedule' || _activeSection === 'history';
        }

        // Ganti ke tab berikutnya dalam section
        function nextTab() {
            if (_activeSection === 'schedule') {
                const next = _schedTab === 'predictive' ? 'preventive' : 'predictive';
                switchSchedTab(next);
            } else if (_activeSection === 'history') {
                const next = _histTab === 'predictive' ? 'preventive' : 'predictive';
                switchHistTab(next);
            }
            scrollDir = 1; // mulai scroll ke bawah lagi setelah ganti tab
        }

        function resetScrollState() {
            scrollDir = 1;
            tabPhase = 0;
            const el = getActiveScrollEl();
            if (el) el.scrollTop = 0;
        }

        function doScroll() {
            if (scrollPaused) return;

            const el = getActiveScrollEl();
            if (!el) {
                scrollRAF = requestAnimationFrame(doScroll);
                return;
            }

            const atBottom = el.scrollTop + el.clientHeight >= el.scrollHeight - 2;
            const atTop = el.scrollTop <= 0;

            if (scrollDir === 1 && atBottom) {
                // Sampai bawah → jeda lalu naik
                scrollDir = -1;
                clearTimeout(pauseTimer);
                pauseTimer = setTimeout(() => {
                    if (!scrollPaused) scrollRAF = requestAnimationFrame(doScroll);
                }, SCROLL_PAUSE);
                return;
            }

            if (scrollDir === -1 && atTop) {
                // Sampai atas → jeda
                clearTimeout(pauseTimer);
                if (sectionHasTabs()) {
                    // Ganti tab, lalu scroll ke bawah lagi
                    pauseTimer = setTimeout(() => {
                        if (!scrollPaused) {
                            nextTab();
                            scrollRAF = requestAnimationFrame(doScroll);
                        }
                    }, SCROLL_PAUSE);
                } else {
                    // Tidak ada tab → langsung scroll ke bawah lagi
                    pauseTimer = setTimeout(() => {
                        scrollDir = 1;
                        if (!scrollPaused) scrollRAF = requestAnimationFrame(doScroll);
                    }, SCROLL_PAUSE);
                }
                return;
            }

            el.scrollTop += scrollDir * SCROLL_SPEED;
            scrollRAF = requestAnimationFrame(doScroll);
        }

        function pauseScroll() {
            if (scrollPaused) return;
            scrollPaused = true;
            cancelAnimationFrame(scrollRAF);
            clearTimeout(pauseTimer);
            showStatus(true);

            clearTimeout(resumeTimer);
            resumeTimer = setTimeout(() => {
                scrollPaused = false;
                showStatus(false);
                scrollRAF = requestAnimationFrame(doScroll);
                setTimeout(hideStatus, 1500);
            }, RESUME_DELAY);
        }

        // Deteksi gerakan kursor → pause scroll
        document.addEventListener('mousemove', () => {
            pauseScroll();
        });

        // Mulai auto-scroll setelah page load
        window.addEventListener('load', () => {
            showStatus(false);
            setTimeout(hideStatus, 2000);
            scrollRAF = requestAnimationFrame(doScroll);
        });

        // ══════════════════════════════════════════════════════════════
        //  TODAY SCHEDULE — always visible, no toggle needed
        // ══════════════════════════════════════════════════════════════

        // ══════════════════════════════════════════════════════════════
        //  Live Digital Clock
        // ══════════════════════════════════════════════════════════════
        (function() {
            const DAYS = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
            const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

            function tick() {
                const now = new Date();
                const hh = String(now.getHours()).padStart(2, '0');
                const mm = String(now.getMinutes()).padStart(2, '0');
                const ss = String(now.getSeconds()).padStart(2, '0');
                const day = DAYS[now.getDay()];
                const date = now.getDate();
                const mon = MONTHS[now.getMonth()];
                const yr = now.getFullYear();
                const timeStr = hh + ':' + mm + ':' + ss;
                const dateStr = day + ', ' + date + ' ' + mon + ' ' + yr;

                // Schedule tab clock (id)
                const clk = document.getElementById('live-clock');
                const dt = document.getElementById('live-date');
                if (clk) clk.textContent = timeStr;
                if (dt) dt.textContent = dateStr;

                // History tab clock mirrors (class)
                document.querySelectorAll('.live-clock-mirror').forEach(el => el.textContent = timeStr);
                document.querySelectorAll('.live-date-mirror').forEach(el => el.textContent = dateStr);
            }

            tick();
            setInterval(tick, 1000);
        })();

        // ══════════════════════════════════════════════════════════════
        //  SCHEDULE STATUS CHECKBOX FILTER — Predictive
        // ══════════════════════════════════════════════════════════════
        function applyPredFilter() {
            const checked = {
                overdue: document.getElementById('predCbOverdue').checked,
                alert: document.getElementById('predCbAlert').checked,
                reminder: document.getElementById('predCbReminder').checked,
                secure: document.getElementById('predCbSecure').checked,
            };
            let shown = 0,
                total = 0;
            document.querySelectorAll('.pred-sched-row').forEach(tr => {
                total++;
                const vis = checked[tr.dataset.status] !== false && checked[tr.dataset.status];
                tr.style.display = vis ? '' : 'none';
                if (vis) shown++;
            });
            const cnt = document.getElementById('predFilterCount');
            if (cnt) cnt.textContent = shown + ' / ' + total + ' jadwal';
        }

        function predCheckAll(val) {
            ['predCbOverdue', 'predCbAlert', 'predCbReminder', 'predCbSecure'].forEach(id => {
                document.getElementById(id).checked = val;
            });
            applyPredFilter();
        }

        // ══════════════════════════════════════════════════════════════
        //  SCHEDULE STATUS CHECKBOX FILTER — Preventive
        // ══════════════════════════════════════════════════════════════
        function applyPrevFilter() {
            const checked = {
                overdue: document.getElementById('prevCbOverdue').checked,
                alert: document.getElementById('prevCbAlert').checked,
                reminder: document.getElementById('prevCbReminder').checked,
                secure: document.getElementById('prevCbSecure').checked,
            };
            let shown = 0,
                total = 0;
            document.querySelectorAll('.prev-sched-row').forEach(tr => {
                total++;
                const vis = checked[tr.dataset.status] !== false && checked[tr.dataset.status];
                tr.style.display = vis ? '' : 'none';
                if (vis) shown++;
            });
            const cnt = document.getElementById('prevFilterCount');
            if (cnt) cnt.textContent = shown + ' / ' + total + ' jadwal';
        }

        function prevCheckAll(val) {
            ['prevCbOverdue', 'prevCbAlert', 'prevCbReminder', 'prevCbSecure'].forEach(id => {
                document.getElementById(id).checked = val;
            });
            applyPrevFilter();
        }

        // ══════════════════════════════════════════════════════════════
        //  HISTORY MONTH FILTER
        // ══════════════════════════════════════════════════════════════
        function applyHistMonthFilter() {
            const val = document.getElementById('histMonthFilter').value; // 'YYYY-MM' or ''
            let predShown = 0,
                predTotal = 0,
                prevShown = 0,
                prevTotal = 0;

            document.querySelectorAll('.hist-pred-row').forEach(tr => {
                predTotal++;
                const vis = !val || tr.dataset.month === val;
                tr.style.display = vis ? '' : 'none';
                if (vis) predShown++;
            });
            document.querySelectorAll('.hist-prev-row').forEach(tr => {
                prevTotal++;
                const vis = !val || tr.dataset.month === val;
                tr.style.display = vis ? '' : 'none';
                if (vis) prevShown++;
            });

            const cnt = document.getElementById('histMonthCount');
            if (cnt) {
                if (val) {
                    const [yr, mo] = val.split('-');
                    const label = new Date(yr, mo - 1).toLocaleDateString('id-ID', {
                        month: 'long',
                        year: 'numeric'
                    });
                    cnt.textContent = label + ' — ' + predShown + ' pred · ' + prevShown + ' prev';
                } else {
                    cnt.textContent = predTotal + ' pred · ' + prevTotal + ' prev (semua)';
                }
            }
        }

        function clearHistMonthFilter() {
            document.getElementById('histMonthFilter').value = '';
            applyHistMonthFilter();
        }

        // Init filter counts on load
        document.addEventListener('DOMContentLoaded', function() {
            applyPredFilter();
            applyPrevFilter();
            applyHistMonthFilter();
        });
    </script>

</body>

</html>