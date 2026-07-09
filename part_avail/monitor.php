<?php
include 'config.php';

// ── Active section & sub-tab ───────────────────────────────────────────────────
$activeSection = $_GET['section'] ?? 'schedule';
if (!in_array($activeSection, ['schedule', 'calendar', 'parts'])) $activeSection = 'schedule';

$activeTab = ($_GET['tab'] ?? 'predictive') === 'preventive' ? 'preventive' : 'predictive';

// ── Today's date ───────────────────────────────────────────────────────────────
$todayStr = date('Y-m-d');
// ── Auto-update remaining_day setiap monitor dibuka ──────────────────────────
try {
    $pdo->exec("
        UPDATE schedules
        SET remaining_day = DATEDIFF(change_date_plan, CURDATE())
        WHERE change_date_plan IS NOT NULL
    ");
    $pdo->exec("
        UPDATE schedules_preventive
        SET remaining_day = DATEDIFF(change_date_plan, CURDATE())
        WHERE change_date_plan IS NOT NULL
    ");
} catch (\Exception $e) {
    error_log('[Monitor] Gagal update remaining_day: ' . $e->getMessage());
}


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
//  CALENDAR & CATEGORY DATA — gabungan predictive + preventive
//  Dipakai oleh menu sidebar baru: "Kalender & Kategori"
// ══════════════════════════════════════════════════════════════════════════════
function classifyScheduleStatus(int $days, int $reminder = 30): string
{
    if ($days <= 0)         return 'overdue';
    if ($days <= 7)         return 'alert';
    if ($days <= $reminder) return 'reminder';
    return                          'secure';
}

$calendarItems = [];
foreach ($schedules as $row) {
    $days     = (int)($row['remaining_day'] ?? 0);
    $reminder = (int)($row['reminder_activity'] ?? 30);
    $calendarItems[] = [
        'type'         => 'predictive',
        'typeLabel'    => 'Predictive',
        'machine'      => $row['machine_name'] ?? '-',
        'process'      => $row['process_machine'] ?? '',
        'unit'         => $row['name_unit'] ?? '',
        'dept'         => $row['department'] ?? '',
        'line'         => $row['line'] ?? '',
        'op'           => $row['operation_process'] ?? '',
        'point'        => $row['maintenance_point'] ?? '-',
        'planDate'     => !empty($row['change_date_plan']) ? date('Y-m-d', strtotime($row['change_date_plan'])) : '',
        'planDateDisp' => !empty($row['change_date_plan']) ? date('d M Y', strtotime($row['change_date_plan'])) : '-',
        'remaining'    => $days,
        'status'       => classifyScheduleStatus($days, $reminder),
        'interval'     => (int)($row['interval_month'] ?? 0),
    ];
}
foreach ($prevSchedules as $row) {
    $days     = (int)($row['remaining_day'] ?? 0);
    $reminder = (int)($row['reminder_activity'] ?? 30);
    $calendarItems[] = [
        'type'         => 'preventive',
        'typeLabel'    => 'Preventive',
        'machine'      => $row['machine_name'] ?? '-',
        'process'      => $row['process_machine'] ?? '',
        'unit'         => $row['name_unit'] ?? '',
        'dept'         => $row['department'] ?? '',
        'line'         => $row['line'] ?? '',
        'op'           => $row['operation_process'] ?? '',
        'point'        => $row['maintenance_point'] ?? '-',
        'planDate'     => !empty($row['change_date_plan']) ? date('Y-m-d', strtotime($row['change_date_plan'])) : '',
        'planDateDisp' => !empty($row['change_date_plan']) ? date('d M Y', strtotime($row['change_date_plan'])) : '-',
        'remaining'    => $days,
        'status'       => classifyScheduleStatus($days, $reminder),
        'interval'     => (int)($row['interval_month'] ?? 0),
    ];
}

$calCntOverdue  = count(array_filter($calendarItems, fn($r) => $r['status'] === 'overdue'));
$calCntAlert    = count(array_filter($calendarItems, fn($r) => $r['status'] === 'alert'));
$calCntReminder = count(array_filter($calendarItems, fn($r) => $r['status'] === 'reminder'));
$calCntSecure   = count(array_filter($calendarItems, fn($r) => $r['status'] === 'secure'));

// Jumlah mesin unik (distinct) per status — dipakai untuk info "X mesin, Y jadwal" di tiap card
function calMachineCount(array $items, string $status): int
{
    $machines = array_unique(array_map(
        fn($r) => $r['machine'],
        array_filter($items, fn($r) => $r['status'] === $status)
    ));
    return count($machines);
}
$calMachOverdue  = calMachineCount($calendarItems, 'overdue');
$calMachAlert    = calMachineCount($calendarItems, 'alert');
$calMachReminder = calMachineCount($calendarItems, 'reminder');
$calMachSecure   = calMachineCount($calendarItems, 'secure');

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
function remainingClass(int $days, int $reminder = 30): string
{
    if ($days <= 0)           return 'text-red-600 font-black';    // overdue (termasuk hari-H = 0)
    if ($days <= 7)           return 'text-amber-600 font-black';  // alert
    if ($days <= $reminder)   return 'text-orange-500 font-bold';  // reminder (dinamis per baris)
    return                           'text-slate-700 font-semibold'; // secure
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
            background: linear-gradient(135deg, #0f4c5c, #1a6b80);
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
            color: #0f4c5c;
            text-decoration: none;
            transition: background .15s;
            white-space: nowrap;
            overflow: hidden;
        }

        .sidebar-back:hover {
            background: #e8f4f8;
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
            width: 0;
            overflow: hidden;
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
            width: 0;
            overflow: hidden;
        }

        /* ── Collapsed: center icons perfectly ── */
        #sidebar.collapsed .nav-pill {
            justify-content: center;
            padding: .6rem 0;
            gap: 0;
        }

        #sidebar.collapsed .sidebar-back {
            justify-content: center;
            padding: .55rem 0;
            gap: 0;
        }

        #sidebar.collapsed .sidebar-nav {
            align-items: center;
        }

        #sidebar.collapsed #sidebar-footer {
            justify-content: center;
        }

        .nav-pill.active-schedule {
            color: #0d3d4a;
            border-color: #a8d3dc;
            background: #e8f4f8;
        }

        .nav-pill.active-schedule .np-icon {
            background: #0f4c5c;
            color: #fff;
        }

        .nav-pill.active-parts {
            color: #0f4c5c;
            border-color: #a8d3dc;
            background: #e8f4f8;
        }

        .nav-pill.active-parts .np-icon {
            background: #0f4c5c;
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

        .nav-pill.active-calendar {
            color: #5b21b6;
            border-color: #ddd6fe;
            background: #f5f3ff;
        }

        .nav-pill.active-calendar .np-icon {
            background: #6d28d9;
            color: #fff;
        }

        /* ── Calendar & Category section ── */
        .cal-stat-card {
            cursor: pointer;
        }

        .cal-stat-card.is-active {
            box-shadow: 0 0 0 2px rgba(109, 40, 217, .55);
            transform: translateY(-1px);
        }

        .cal-day {
            aspect-ratio: 1/1;
            border-radius: .6rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 2px;
            font-size: .72rem;
            font-weight: 700;
            color: #475569;
            cursor: default;
            position: relative;
            background: #f8fafc;
        }

        .cal-day.cal-empty {
            background: transparent;
        }

        .cal-day.cal-clickable {
            cursor: pointer;
        }

        .cal-day.cal-clickable:not(.cal-has-sched):hover {
            background: #ede9fe;
            color: #6d28d9;
        }

        .cal-day.cal-has-sched {
            cursor: pointer;
            color: #fff;
        }

        .cal-day.cal-has-sched:hover {
            filter: brightness(1.08);
        }

        .cal-day.cal-status-overdue {
            background: linear-gradient(135deg, #ef4444, #dc2626);
        }

        .cal-day.cal-status-alert {
            background: linear-gradient(135deg, #eab308, #ca8a04);
        }

        .cal-day.cal-status-reminder {
            background: linear-gradient(135deg, #f97316, #ea580c);
        }

        .cal-day.cal-status-secure {
            background: linear-gradient(135deg, #22c55e, #16a34a);
        }

        .cal-day.cal-today {
            box-shadow: 0 0 0 2px #6d28d9;
        }

        .cal-day.cal-selected {
            box-shadow: 0 0 0 3px #6d28d9;
        }

        .cal-day .cal-day-dot {
            width: 4px;
            height: 4px;
            border-radius: 50%;
            background: rgba(255, 255, 255, .85);
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
            background: #d4eaf0;
            color: #0a2e38;
            border-color: #93c5fd;
        }

        .ms-done {
            background: #d1fae5;
            color: #065f46;
            border-color: #6ee7b7;
        }

        .ps-open {
            background: #d4eaf0;
            color: #0a2e38;
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

        /* Schedule tables: pagination already caps rows shown per page
           (jumlahnya dihitung otomatis agar tab+tabel+footer pas 1 layar
           penuh — lihat computePageRows()), sehingga tidak perlu scroll
           vertikal internal — biarkan menyesuaikan tinggi halaman aktif. */
        #schedPredTab .table-scroll,
        #schedPrevTab .table-scroll {
            max-height: none;
            overflow-y: visible;
        }

        /* ── Transisi perpindahan halaman tabel (pagination) ──
           tbody di-wipe (clip-path) setiap kali isi baris berganti (ganti
           halaman, ganti tab, filter, maupun refresh AJAX). Properti
           transition sebenarnya di-set inline lewat JS (renderSchedPage)
           supaya bisa "instan tutup → animasi buka", tapi dideklarasikan
           di sini juga sebagai fallback/dokumentasi. */
        #schedPredTab table tbody,
        #schedPrevTab table tbody {
            transition: clip-path .35s ease;
        }

        /* History tables: fill remaining viewport height (same logic as schedule) */
        #histPredTab .table-scroll,
        #histPrevTab .table-scroll {
            max-height: calc(100vh - 280px);
        }

        /* ── Today's Schedule cards: capped at ~1 screen, independent scroll per card ── */
        .today-scroll {
            overflow-y: auto;
            overflow-x: hidden;
            max-height: calc(100vh - 350px);
        }

        .today-scroll::-webkit-scrollbar {
            width: 5px;
        }

        .today-scroll::-webkit-scrollbar-thumb {
            background: rgba(15, 76, 92, .25);
            border-radius: 10px;
        }

        /* 2 items per row inside each Today's Schedule card */
        .today-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: .375rem;
        }

        /* Predictive/Preventive columns must size independently — the empty one
           should NOT stretch to match the taller one */
        #todayScheduleSection .grid {
            align-items: start;
        }

        /* Predictive table: allow horizontal scroll if needed */
        #schedPredTab .table-scroll {
            overflow-x: auto;
        }

        /* Compact badge for tight columns */
        #schedPredTab .badge,
        #schedPrevTab .badge {
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
        <nav id="sidebar" class="collapsed">
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
                <button onclick="switchSection('calendar')" id="navCalendar"
                    class="nav-pill <?= $activeSection === 'calendar' ? 'active-calendar' : '' ?>">
                    <span class="np-icon"><i class="fas fa-calendar-days"></i></span>
                    <span class="np-label">Kalender & Kategori</span>
                </button>
                <button onclick="switchSection('parts')" id="navParts"
                    class="nav-pill <?= $activeSection === 'parts' ? 'active-parts' : '' ?>">
                    <span class="np-icon"><i class="fas fa-boxes-stacked"></i></span>
                    <span class="np-label">Part Availability</span>
                </button>
            </div>

            <!-- Toggle btn -->
            <div id="sidebar-footer">
                <button id="sidebarToggle" title="Toggle sidebar">
                    <i class="fas fa-chevron-right" id="sidebarToggleIcon"></i>
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

                <!-- ═══════════════════════════════════════════════════════════
         SECTION: SCHEDULE
    ═══════════════════════════════════════════════════════════ -->
                <div id="sectionSchedule" class="section-enter <?= $activeSection !== 'schedule' ? 'hidden' : '' ?>">

                    <!-- ══════════════════════════════════════════════════
                         TODAY'S SCHEDULE — Unified (Predictive + Preventive)
                         Displayed above the tabs, auto-refreshed via AJAX
                    ══════════════════════════════════════════════════ -->
                    <div id="todayScheduleSection" class="mb-5">
                        <!-- Header bar -->
                        <div class="flex items-center gap-3 mb-3">
                            <!-- Icon + Title -->
                            <div class="w-9 h-9 bg-gradient-to-br from-[#0f4c5c] to-[#1a6b80] rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm">
                                <i class="fas fa-calendar-day text-white text-sm"></i>
                            </div>
                            <h2 class="font-extrabold text-slate-800 text-base leading-tight">Today's Schedule</h2>
                            <!-- Date label -->
                            <p class="text-slate-400 font-semibold" style="font-size:.65rem;" id="todayDateLabel"><?= date('d M Y') ?></p>
                            <!-- Clock — sejajar header -->
                            <div class="ml-auto flex items-center gap-3 flex-shrink-0">
                                <div class="text-right">
                                    <div id="live-clock" class="font-mono font-black text-slate-800 tabular-nums" style="font-size:1.5rem;line-height:1;letter-spacing:-.02em;"></div>
                                    <div id="live-date" class="font-semibold text-slate-400 tracking-wide" style="font-size:.6rem;"></div>
                                </div>
                                <!-- AJAX live indicator -->
                                <div class="flex items-center gap-1.5 bg-slate-100 rounded-full px-2.5 py-1">
                                    <span id="ajaxDot" class="w-2 h-2 rounded-full bg-emerald-400 inline-block" title="Auto-refresh aktif"></span>
                                    <span id="ajaxLabel" class="text-slate-500 font-bold" style="font-size:.62rem;">Live</span>
                                </div>
                            </div>
                        </div>

                        <!-- Two-column layout: Predictive (left) | Preventive (right) -->
                        <div class="grid gap-4" style="grid-template-columns:1fr 1fr;align-items:start;">

                            <!-- ── Predictive Column ── -->
                            <div class="rounded-2xl overflow-hidden" style="background:linear-gradient(135deg,#e8f4f8,#d4eaf0);border:1px solid #a8d3dc;">
                                <div class="flex items-center gap-2 px-4 py-2.5">
                                    <i class="fas fa-chart-line text-[#0f4c5c] text-xs"></i>
                                    <span class="text-[#0a2e38] font-bold text-sm">Predictive</span>
                                    <span id="todayPredCount" class="bg-[#0f4c5c] text-white text-[10px] font-black px-2 py-0.5 rounded-full"><?= $todayCount ?></span>
                                </div>
                                <div class="px-3 pb-3 today-scroll" id="todayPredList">
                                    <?php if ($todayCount > 0): ?>
                                        <div class="today-grid">
                                            <?php foreach ($todaySchedArr as $i => $td): ?>
                                                <div class="bg-white/80 rounded-xl px-3 py-2 flex items-start gap-2 border border-[#a8d3dc]100 shadow-sm">
                                                    <div class="w-5 h-5 rounded-md bg-[#d4eaf0] flex items-center justify-center flex-shrink-0 mt-0.5">
                                                        <span class="text-[#0f4c5c] font-black" style="font-size:.55rem;"><?= $i + 1 ?></span>
                                                    </div>
                                                    <div class="min-w-0 flex-1">
                                                        <p class="font-black text-[#072028] truncate" style="font-size:.72rem;" title="<?= htmlspecialchars($td['machine_name'] ?? '-') ?>"><?= htmlspecialchars($td['machine_name'] ?? '-') ?><?= !empty($td['operation_process']) ? ' · ' . htmlspecialchars($td['operation_process']) : '' ?></p>
                                                        <p class="text-[#0f4c5c] mt-0.5" style="font-size:.63rem;line-height:1.4;"><?= htmlspecialchars($td['maintenance_point'] ?? '-') ?></p>
                                                        <?php if (!empty($td['department'])): ?>
                                                            <p class="text-[#3d8fa3] mt-0.5 truncate" style="font-size:.58rem;"><?= htmlspecialchars($td['department']) ?><?= !empty($td['line']) ? ' · ' . $td['line'] : '' ?></p>
                                                        <?php endif; ?>
                                                    </div>
                                                    <span class="flex-shrink-0 bg-[#d4eaf0] text-[#0d3d4a] font-bold px-1.5 py-0.5 rounded" style="font-size:.58rem;"><?= (int)($td['interval_month'] ?? 0) ?>mo</span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <!-- <div class="flex items-center gap-2 py-2 px-1">
                                            <i class="fas fa-check-circle text-[#5aaec4] text-xs"></i>
                                            <span class="text-[#3d8fa3] font-semibold" style="font-size:.7rem;">No predictive schedule for today</span>
                                        </div> -->
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- ── Preventive Column ── -->
                            <div class="rounded-2xl overflow-hidden" style="background:linear-gradient(135deg,#e8f4f8,#d4eaf0);border:1px solid #a8d3dc;">
                                <div class="flex items-center gap-2 px-4 py-2.5">
                                    <i class="fas fa-shield-halved text-[#0f4c5c] text-xs"></i>
                                    <span class="text-indigo-800 font-bold text-sm">Preventive</span>
                                    <span id="todayPrevCount" class="bg-[#0d3d4a] text-white text-[10px] font-black px-2 py-0.5 rounded-full"><?= $prevTodayCount ?></span>
                                </div>
                                <div class="px-3 pb-3 today-scroll" id="todayPrevList">
                                    <?php if ($prevTodayCount > 0): ?>
                                        <div class="today-grid">
                                            <?php foreach ($prevTodayArr as $i => $td): ?>
                                                <div class="bg-white/80 rounded-xl px-3 py-2 flex items-start gap-2 border border-indigo-100 shadow-sm">
                                                    <div class="w-5 h-5 rounded-md bg-[#d4eaf0] flex items-center justify-center flex-shrink-0 mt-0.5">
                                                        <span class="text-[#0f4c5c] font-black" style="font-size:.55rem;"><?= $i + 1 ?></span>
                                                    </div>
                                                    <div class="min-w-0 flex-1">
                                                        <p class="font-black text-indigo-900 truncate" style="font-size:.72rem;" title="<?= htmlspecialchars($td['machine_name'] ?? '-') ?>"><?= htmlspecialchars($td['machine_name'] ?? '-') ?><?= !empty($td['operation_process']) ? ' · ' . htmlspecialchars($td['operation_process']) : '' ?></p>
                                                        <p class="text-[#0f4c5c] mt-0.5" style="font-size:.63rem;line-height:1.4;"><?= htmlspecialchars($td['maintenance_point'] ?? '-') ?></p>
                                                        <?php if (!empty($td['department'])): ?>
                                                            <p class="text-[#3d8fa3] mt-0.5 truncate" style="font-size:.58rem;"><?= htmlspecialchars($td['department']) ?><?= !empty($td['line']) ? ' · ' . $td['line'] : '' ?></p>
                                                        <?php endif; ?>
                                                    </div>
                                                    <span class="flex-shrink-0 bg-[#d4eaf0] text-[#0d3d4a] font-bold px-1.5 py-0.5 rounded" style="font-size:.58rem;"><?= (int)($td['interval_month'] ?? 0) ?>mo</span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <!-- <div class="flex items-center gap-2 py-2 px-1">
                                            <i class="fas fa-check-circle text-indigo-300 text-xs"></i>
                                            <span class="text-[#3d8fa3] font-semibold" style="font-size:.7rem;">No preventive schedule for today</span>
                                        </div> -->
                                    <?php endif; ?>
                                </div>
                            </div>

                        </div><!-- /grid -->
                    </div><!-- /todayScheduleSection -->


                    <!-- Sub-tab (Predictive / Preventive) + Clock -->
                    <div id="schedSubtabBar" class="mb-3 flex items-center gap-3">
                        <div class="relative bg-slate-100 rounded-2xl p-1.5 flex gap-1 shadow-inner" style="max-width:520px;flex:1;">
                            <div id="schedTabIndicator" class="subtab-indicator"
                                style="background:<?= $activeTab === 'preventive' ? 'linear-gradient(135deg,#0a2e38,#1a6b80)' : 'linear-gradient(135deg,#0f4c5c,#0d3d4a)' ?>;width:calc(50% - 4px);transform:<?= $activeTab === 'preventive' ? 'translateX(calc(100% + 4px))' : 'translateX(0)' ?>;"></div>
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
                    </div>

                    <!-- ── PREDICTIVE TAB ── -->
                    <?php $todaySchedArrVal = array_values($todaySchedArr); ?>
                    <div id="schedPredTab" class="<?= $activeTab === 'preventive' ? 'hidden' : '' ?>">
                        <!-- ── Status Checkbox Filter — Predictive ── -->
                        <div id="predFilterBar" class="mb-1 flex items-center gap-2 bg-white/80 border border-slate-200 rounded-lg px-2.5 py-0.5" style="font-size:.65rem;">
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
                            <button onclick="predCheckAll(true)" class="font-bold text-slate-400 hover:text-[#0f4c5c] transition px-1 rounded hover:bg-[#e8f4f8]">All</button>
                            <button onclick="predCheckAll(false)" class="font-bold text-slate-400 hover:text-red-500 transition px-1 rounded hover:bg-red-50">None</button>
                            <span id="predFilterCount" class="ml-auto font-bold text-slate-300 whitespace-nowrap"></span>
                        </div>
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
                                    <thead style="background:linear-gradient(135deg, #0a2e38, #0f4c5c);position:sticky;top:0;z-index:10;">
                                        <tr>
                                            <th class="tbl-th px-2 py-1.5" style="font-size:.6rem;">No</th>
                                            <th class="tbl-th px-2 py-1.5" style="font-size:.6rem;">Machine</th>
                                            <th class="tbl-th px-2 py-1.5" style="font-size:.6rem;">Maint. Point</th>
                                            <th class="tbl-th px-2 py-1.5 text-center" style="font-size:.6rem;">Last Change</th>
                                            <th class="tbl-th px-2 py-1.5 text-center" style="font-size:.6rem;">Intv.</th>
                                            <th class="tbl-th px-2 py-1.5 text-center" style="font-size:.6rem;">Plan Date</th>
                                            <th class="tbl-th px-2 py-1.5 text-center" style="font-size:.6rem;">Rem. (d)</th>
                                            <th class="tbl-th px-2 py-1.5 text-center" style="font-size:.6rem;">Part Order</th>
                                            <th class="tbl-th px-2 py-1.5 text-center" style="font-size:.6rem;">Part Avail.</th>
                                            <th class="tbl-th px-2 py-1.5 text-center" style="font-size:.6rem;">Status</th>
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
                                                $reminder = (int)($row['reminder_activity'] ?? 30);
                                                $daysCls = remainingClass($days, $reminder);
                                                $useDate = $row['use_date'] ? date('d M Y', strtotime($row['use_date'])) : '-';
                                                $planDate = $row['change_date_plan'] ? date('d M Y', strtotime($row['change_date_plan'])) : '-';
                                                if ($days <= 0) $rowStatus = 'overdue';
                                                elseif ($days <= 7) $rowStatus = 'alert';
                                                elseif ($days <= $reminder) $rowStatus = 'reminder';
                                                else $rowStatus = 'secure';
                                            ?>
                                                <tr class="pred-sched-row" data-status="<?= $rowStatus ?>">
                                                    <td class="tbl-td text-slate-400 font-mono px-2 py-1.5" style="font-size:.68rem;"><?= $i + 1 ?></td>
                                                    <td class="tbl-td px-2 py-1.5" style="overflow:hidden;">
                                                        <div class="font-bold text-slate-800 leading-tight" style="font-size:.72rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($row['machine_name'] ?? '-') ?>"><?= htmlspecialchars($row['machine_name'] ?? '-') ?></div>
                                                        <?php if (!empty($row['process_machine'])): ?>
                                                            <div class="text-slate-500 mt-0.5" style="font-size:.65rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($row['process_machine']) ?></div>
                                                        <?php endif; ?>
                                                        <div class="text-slate-400 mt-0.5" style="font-size:.62rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                                            <?= htmlspecialchars($row['department'] ?? '') ?><?= !empty($row['line']) ? ' · ' . htmlspecialchars($row['line']) : '' ?><?= !empty($row['operation_process']) ? ' · OP ' . htmlspecialchars($row['operation_process']) : '' ?>
                                                        </div>
                                                    </td>
                                                    <td class="tbl-td px-2 py-1.5">
                                                        <span style="font-size:.72rem;color:#334155;display:block;word-break:break-word;white-space:normal;line-height:1.4;" title="<?= htmlspecialchars($row['maintenance_point'] ?? '-') ?>"><?= htmlspecialchars($row['maintenance_point'] ?? '-') ?></span>
                                                        <?php if (!empty($row['name_unit'])): ?>
                                                            <div class="text-slate-400 italic mt-0.5" style="font-size:.62rem;word-break:break-word;white-space:normal;"><?= htmlspecialchars($row['name_unit']) ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="tbl-td text-center text-slate-500 px-1 py-1.5" style="font-size:.65rem;"><?= $useDate ?></td>
                                                    <td class="tbl-td text-center px-1 py-1.5">
                                                        <span class="bg-slate-100 text-slate-600 font-bold px-1.5 py-0.5 rounded" style="font-size:.65rem;">
                                                            <?= (int)($row['interval_month'] ?? 0) ?>mo
                                                        </span>
                                                    </td>
                                                    <td class="tbl-td text-center text-slate-600 font-semibold px-1 py-1.5" style="font-size:.65rem;"><?= $planDate ?></td>
                                                    <td class="tbl-td text-center px-1 py-1.5 <?= $daysCls ?>" style="font-size:.75rem;"><?= $days ?></td>
                                                    <td class="tbl-td text-center px-1 py-1.5"><?= partOrderBadge($row['part_order'] ?? 'close') ?></td>
                                                    <td class="tbl-td text-center px-1 py-1.5"><?= partOrderBadge($row['part_availability'] ?? 'close') ?></td>
                                                    <td class="tbl-td text-center px-1 py-1.5"><?= maintenanceStatusBadge($row['maintenance_status'] ?? '') ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div id="predFooterBar" class="px-5 py-2 bg-slate-50 border-t border-slate-100 flex justify-between items-center text-xs text-slate-400 gap-3">
                                <span><?= count($schedules) ?> jadwal predictive</span>
                                <div class="flex items-center gap-2">
                                    <button id="predPagePrev" onclick="predGoTo(-1)" class="w-6 h-6 rounded-lg bg-white border border-slate-200 text-slate-500 hover:bg-slate-100 disabled:opacity-30 disabled:cursor-not-allowed flex items-center justify-center" title="Halaman sebelumnya">
                                        <i class="fas fa-chevron-left" style="font-size:.6rem;"></i>
                                    </button>
                                    <span id="predPageLabel" class="font-bold text-slate-500 whitespace-nowrap" style="font-size:.68rem;">Halaman 1 / 1</span>
                                    <button id="predPageNext" onclick="predGoTo(1)" class="w-6 h-6 rounded-lg bg-white border border-slate-200 text-slate-500 hover:bg-slate-100 disabled:opacity-30 disabled:cursor-not-allowed flex items-center justify-center" title="Halaman berikutnya">
                                        <i class="fas fa-chevron-right" style="font-size:.6rem;"></i>
                                    </button>
                                </div>
                                <?php date_default_timezone_set('Asia/Jakarta'); ?>
                                <span>Last updated: <?= date('d M Y H:i') ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- ── PREVENTIVE TAB ── -->
                    <?php $prevTodayArrVal = array_values($prevTodayArr); ?>
                    <div id="schedPrevTab" class="<?= $activeTab === 'predictive' ? 'hidden' : '' ?>">
                        <!-- ── Status Checkbox Filter — Preventive ── -->
                        <div id="prevFilterBar" class="mb-1 flex items-center gap-2 bg-white/80 border border-slate-200 rounded-lg px-2.5 py-0.5" style="font-size:.65rem;">
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
                            <button onclick="prevCheckAll(true)" class="font-bold text-slate-400 hover:text-[#0f4c5c] transition px-1 rounded hover:bg-indigo-50">All</button>
                            <button onclick="prevCheckAll(false)" class="font-bold text-slate-400 hover:text-red-500 transition px-1 rounded hover:bg-red-50">None</button>
                            <span id="prevFilterCount" class="ml-auto font-bold text-slate-300 whitespace-nowrap"></span>
                        </div>
                        <!-- Table -->
                        <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                            <div class="table-scroll">
                                <table class="w-full text-left border-collapse" style="min-width:780px;">
                                    <thead style="background:linear-gradient(135deg, #0a2e38, #1a6b80);position:sticky;top:0;z-index:10;">
                                        <tr>
                                            <th class="tbl-th px-2 py-1.5" style="width:32px;font-size:.6rem;">No</th>
                                            <th class="tbl-th px-2 py-1.5" style="font-size:.6rem;">Machine Information</th>
                                            <th class="tbl-th px-2 py-1.5" style="font-size:.6rem;">Maintenance Point</th>
                                            <th class="tbl-th px-2 py-1.5 text-center" style="font-size:.6rem;">Last Change</th>
                                            <th class="tbl-th px-2 py-1.5 text-center" style="font-size:.6rem;">Interval</th>
                                            <th class="tbl-th px-2 py-1.5 text-center" style="font-size:.6rem;">Change Date Plan</th>
                                            <th class="tbl-th px-2 py-1.5 text-center" style="font-size:.6rem;">Remaining (Day(s))</th>
                                            <th class="tbl-th px-2 py-1.5 text-center" style="font-size:.6rem;">Maint. Status</th>
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
                                                $pReminder = (int)($row['reminder_activity'] ?? 30);
                                                $daysCls = remainingClass($days, $pReminder);
                                                $useDate = !empty($row['use_date']) ? date('d M Y', strtotime($row['use_date'])) : '-';
                                                $planDate = !empty($row['change_date_plan']) ? date('d M Y', strtotime($row['change_date_plan'])) : '-';
                                                if ($days <= 0) $pRowStatus = 'overdue';
                                                elseif ($days <= 7) $pRowStatus = 'alert';
                                                elseif ($days <= $pReminder) $pRowStatus = 'reminder';
                                                else $pRowStatus = 'secure';
                                            ?>
                                                <tr class="prev-sched-row" data-status="<?= $pRowStatus ?>">
                                                    <td class="tbl-td text-slate-400 font-mono px-2 py-1.5" style="font-size:.68rem;"><?= $i + 1 ?></td>
                                                    <td class="tbl-td px-2 py-1.5" style="min-width:160px;">
                                                        <div class="font-bold text-slate-800 leading-tight" style="font-size:.72rem;"><?= htmlspecialchars($row['machine_name'] ?? '-') ?></div>
                                                        <?php if (!empty($row['process_machine'])): ?>
                                                            <div class="text-slate-500 mt-0.5" style="font-size:.65rem;"><?= htmlspecialchars($row['process_machine']) ?></div>
                                                        <?php endif; ?>
                                                        <div class="text-slate-400 mt-0.5" style="font-size:.62rem;">
                                                            <?= htmlspecialchars($row['department'] ?? '') ?><?= !empty($row['line']) ? ' · ' . htmlspecialchars($row['line']) : '' ?><?= !empty($row['operation_process']) ? ' · OP ' . htmlspecialchars($row['operation_process']) : '' ?>
                                                        </div>
                                                    </td>
                                                    <td class="tbl-td px-2 py-1.5" style="min-width:160px;">
                                                        <span style="font-size:.72rem;color:#334155;display:block;word-break:break-word;white-space:normal;line-height:1.4;"><?= htmlspecialchars($row['maintenance_point'] ?? '-') ?></span>
                                                        <?php if (!empty($row['name_unit'])): ?>
                                                            <div class="text-slate-400 italic mt-0.5" style="font-size:.62rem;"><?= htmlspecialchars($row['name_unit']) ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="tbl-td text-center text-slate-500 px-1 py-1.5 whitespace-nowrap" style="font-size:.65rem;"><?= $useDate ?></td>
                                                    <td class="tbl-td text-center px-1 py-1.5">
                                                        <span class="bg-slate-100 text-slate-600 font-bold px-1.5 py-0.5 rounded" style="font-size:.65rem;">
                                                            <?= (int)($row['interval_month'] ?? 0) ?> mo
                                                        </span>
                                                    </td>
                                                    <td class="tbl-td text-center text-slate-600 font-semibold px-1 py-1.5 whitespace-nowrap" style="font-size:.65rem;"><?= $planDate ?></td>
                                                    <td class="tbl-td text-center px-1 py-1.5 whitespace-nowrap <?= $daysCls ?>" style="font-size:.75rem;"><?= $days ?></td>
                                                    <td class="tbl-td text-center px-1 py-1.5"><?= maintenanceStatusBadge($row['maintenance_status'] ?? '') ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div id="prevFooterBar" class="px-5 py-2 bg-slate-50 border-t border-slate-100 flex justify-between items-center text-xs text-slate-400 gap-3">
                                <span><?= count($prevSchedules) ?> jadwal preventive</span>
                                <div class="flex items-center gap-2">
                                    <button id="prevPagePrev" onclick="prevGoTo(-1)" class="w-6 h-6 rounded-lg bg-white border border-slate-200 text-slate-500 hover:bg-slate-100 disabled:opacity-30 disabled:cursor-not-allowed flex items-center justify-center" title="Halaman sebelumnya">
                                        <i class="fas fa-chevron-left" style="font-size:.6rem;"></i>
                                    </button>
                                    <span id="prevPageLabel" class="font-bold text-slate-500 whitespace-nowrap" style="font-size:.68rem;">Halaman 1 / 1</span>
                                    <button id="prevPageNext" onclick="prevGoTo(1)" class="w-6 h-6 rounded-lg bg-white border border-slate-200 text-slate-500 hover:bg-slate-100 disabled:opacity-30 disabled:cursor-not-allowed flex items-center justify-center" title="Halaman berikutnya">
                                        <i class="fas fa-chevron-right" style="font-size:.6rem;"></i>
                                    </button>
                                </div>
                                <?php date_default_timezone_set('Asia/Jakarta'); ?>
                                <span>Last updated: <?= date('d M Y H:i') ?></span>
                            </div>
                        </div>
                    </div>

                </div><!-- /sectionSchedule -->


                <!-- ═══════════════════════════════════════════════════════════
         SECTION: CALENDAR & CATEGORY
         Kartu kategori (Overdue/Alert/Reminder/Secure) + kalender bulanan
         dengan penanda tanggal yang ada jadwal, + daftar hasil filter.
    ═══════════════════════════════════════════════════════════ -->
                <div id="sectionCalendar" class="section-enter <?= $activeSection !== 'calendar' ? 'hidden' : '' ?>">

                    <!-- Header -->
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-9 h-9 bg-gradient-to-br from-[#6d28d9] to-[#8b5cf6] rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm">
                            <i class="fas fa-calendar-days text-white text-sm"></i>
                        </div>
                        <h2 class="font-extrabold text-slate-800 text-base leading-tight">Kalender &amp; Kategori Jadwal</h2>
                        <p class="text-slate-400 font-semibold" style="font-size:.65rem;">Predictive + Preventive</p>
                        <div class="ml-auto flex items-center gap-3 flex-shrink-0">
                            <span class="live-clock-mirror font-mono font-black text-slate-800 tabular-nums" style="font-size:1.1rem;line-height:1;letter-spacing:-.02em;"></span>
                        </div>
                    </div>

                    <!-- Category stat cards -->
                    <div class="grid grid-cols-4 gap-3 mb-5">
                        <button onclick="filterCalByStatus('overdue')" id="calCardOverdue"
                            class="cal-stat-card text-left rounded-2xl border px-4 py-3 transition"
                            style="background:#fee2e2;border-color:#fca5a5;">
                            <p class="font-bold text-red-700 flex items-center gap-1" style="font-size:.72rem;">
                                <i class="fas fa-triangle-exclamation" style="font-size:.65rem;"></i> Overdue
                            </p>
                            <p class="text-3xl font-black" id="calCntOverdue" style="color:#b91c1c;"><?= $calCntOverdue ?></p>
                            <p class="font-semibold text-red-700" id="calMachOverdue" style="font-size:.62rem;opacity:.75;"><?= $calMachOverdue ?> mesin, <?= $calCntOverdue ?> jadwal</p>
                        </button>
                        <button onclick="filterCalByStatus('alert')" id="calCardAlert"
                            class="cal-stat-card text-left rounded-2xl border px-4 py-3 transition"
                            style="background:#fef9c3;border-color:#fde047;">
                            <p class="font-bold text-yellow-800 flex items-center gap-1" style="font-size:.72rem;">
                                <i class="fas fa-bell" style="font-size:.65rem;"></i> Alert (≤7 hari)
                            </p>
                            <p class="text-3xl font-black" id="calCntAlert" style="color:#854d0e;"><?= $calCntAlert ?></p>
                            <p class="font-semibold text-yellow-800" id="calMachAlert" style="font-size:.62rem;opacity:.75;"><?= $calMachAlert ?> mesin, <?= $calCntAlert ?> jadwal</p>
                        </button>
                        <button onclick="filterCalByStatus('reminder')" id="calCardReminder"
                            class="cal-stat-card text-left rounded-2xl border px-4 py-3 transition"
                            style="background:#ffedd5;border-color:#fdba74;">
                            <p class="font-bold text-orange-800 flex items-center gap-1" style="font-size:.72rem;">
                                <i class="fas fa-clock" style="font-size:.65rem;"></i> Reminder
                            </p>
                            <p class="text-3xl font-black" id="calCntReminder" style="color:#9a3412;"><?= $calCntReminder ?></p>
                            <p class="font-semibold text-orange-800" id="calMachReminder" style="font-size:.62rem;opacity:.75;"><?= $calMachReminder ?> mesin, <?= $calCntReminder ?> jadwal</p>
                        </button>
                        <button onclick="filterCalByStatus('secure')" id="calCardSecure"
                            class="cal-stat-card text-left rounded-2xl border px-4 py-3 transition"
                            style="background:#dcfce7;border-color:#86efac;">
                            <p class="font-bold text-emerald-800 flex items-center gap-1" style="font-size:.72rem;">
                                <i class="fas fa-circle-check" style="font-size:.65rem;"></i> Secure
                            </p>
                            <p class="text-3xl font-black" id="calCntSecure" style="color:#15803d;"><?= $calCntSecure ?></p>
                            <p class="font-semibold text-emerald-800" id="calMachSecure" style="font-size:.62rem;opacity:.75;"><?= $calMachSecure ?> mesin, <?= $calCntSecure ?> jadwal</p>
                        </button>
                    </div>

                    <!-- Calendar + List two-column layout -->
                    <div class="grid gap-4" style="grid-template-columns:400px 1fr;align-items:start;">

                        <!-- ── Calendar ── -->
                        <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                            <div class="px-4 py-3 border-b border-slate-100 flex items-center justify-between">
                                <button onclick="calChangeMonth(-1)" class="w-7 h-7 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-500 flex items-center justify-center">
                                    <i class="fas fa-chevron-left" style="font-size:.65rem;"></i>
                                </button>
                                <span id="calMonthLabel" class="font-bold text-slate-800" style="font-size:.85rem;"></span>
                                <button onclick="calChangeMonth(1)" class="w-7 h-7 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-500 flex items-center justify-center">
                                    <i class="fas fa-chevron-right" style="font-size:.65rem;"></i>
                                </button>
                            </div>
                            <div class="px-4 pt-3 pb-1 grid grid-cols-7 gap-1 text-center">
                                <?php foreach (['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'] as $d): ?>
                                    <span class="text-slate-400 font-bold" style="font-size:.62rem;"><?= $d ?></span>
                                <?php endforeach; ?>
                            </div>
                            <div id="calGrid" class="px-4 pb-4 grid grid-cols-7 gap-1"></div>
                            <div class="px-4 pb-4 flex items-center flex-wrap gap-3" style="font-size:.6rem;">
                                <span class="flex items-center gap-1 text-slate-400 font-semibold"><span style="width:8px;height:8px;border-radius:2px;background:#ef4444;display:inline-block;"></span> Overdue</span>
                                <span class="flex items-center gap-1 text-slate-400 font-semibold"><span style="width:8px;height:8px;border-radius:2px;background:#eab308;display:inline-block;"></span> Alert</span>
                                <span class="flex items-center gap-1 text-slate-400 font-semibold"><span style="width:8px;height:8px;border-radius:2px;background:#f97316;display:inline-block;"></span> Reminder</span>
                                <span class="flex items-center gap-1 text-slate-400 font-semibold"><span style="width:8px;height:8px;border-radius:2px;background:#22c55e;display:inline-block;"></span> Aman</span>
                            </div>
                        </div>

                        <!-- ── Filtered list ── -->
                        <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                            <div class="px-5 py-3 border-b border-slate-100 flex items-center gap-2">
                                <div class="min-w-0">
                                    <div id="calListTitle" class="text-sm font-bold text-slate-800">Semua Jadwal</div>
                                    <div id="calListSubtitle" class="text-[10px] text-slate-400 font-medium">Klik kategori atau tanggal untuk memfilter</div>
                                </div>
                                <button onclick="calResetFilter()" id="calResetBtn" class="ml-auto hidden bg-slate-100 hover:bg-slate-200 text-slate-500 font-bold px-3 py-1.5 rounded-lg" style="font-size:.65rem;">
                                    <i class="fas fa-xmark mr-1"></i>Reset Filter
                                </button>
                            </div>
                            <div id="calListBody" class="table-scroll" style="max-height:520px;overflow-y:auto;">
                                <div class="p-4 grid gap-2" id="calListGrid"></div>
                            </div>
                            <div class="px-5 py-2 bg-slate-50 border-t border-slate-100 flex justify-between items-center text-xs text-slate-400">
                                <span id="calListCount"><?= count($calendarItems) ?> jadwal</span>
                                <?php date_default_timezone_set('Asia/Jakarta'); ?>
                                <span>Last updated: <?= date('d M Y H:i') ?></span>
                            </div>
                        </div>

                    </div>
                </div><!-- /sectionCalendar -->


                <!-- ═══════════════════════════════════════════════════════════
         SECTION: PART AVAILABILITY
    ═══════════════════════════════════════════════════════════ -->
                <div id="sectionParts" class="section-enter <?= $activeSection !== 'parts' ? 'hidden' : '' ?>">
                    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                        <div class="px-5 py-3 border-b border-slate-100 flex items-center gap-2">
                            <div class="w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0" style="background:#e8f4f8;">
                                <i class="fas fa-boxes-stacked text-xs" style="color:#0f4c5c;"></i>
                            </div>
                            <div>
                                <div class="text-sm font-bold text-slate-800">Part Inventory</div>
                                <div class="text-[10px] text-slate-400 font-medium">Status stok spare part</div>
                            </div>
                        </div>
                        <div class="table-scroll">
                            <table class="w-full text-left border-collapse" id="partsTable" style="min-width:700px;">
                                <thead style="background:linear-gradient(135deg,#0f4c5c,#1a6b80);position:sticky;top:0;z-index:10;">
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

        (function() {
            const icon = document.getElementById('sidebarToggleIcon');

            function applyState(collapsed) {
                sidebar.classList.toggle('collapsed', collapsed);
                document.body.classList.toggle('sidebar-collapsed', collapsed);
                icon.className = collapsed ? 'fas fa-chevron-right' : 'fas fa-chevron-left';
            }

            // Init: baca sessionStorage, default = collapsed
            const saved = sessionStorage.getItem('monitor_sidebar');
            applyState(saved !== 'expanded');

            document.getElementById('sidebarToggle').addEventListener('click', () => {
                const isCollapsed = !sidebar.classList.contains('collapsed');
                applyState(isCollapsed);
                sessionStorage.setItem('monitor_sidebar', isCollapsed ? 'collapsed' : 'expanded');
            });
        })();

        // ══════════════════════════════════════════════════════════════
        //  Section switching
        // ══════════════════════════════════════════════════════════════
        const SECTION_META = {
            schedule: {
                nav: 'navSchedule',
                el: 'sectionSchedule',
                cls: 'active-schedule'
            },
            calendar: {
                nav: 'navCalendar',
                el: 'sectionCalendar',
                cls: 'active-calendar'
            },
            parts: {
                nav: 'navParts',
                el: 'sectionParts',
                cls: 'active-parts'
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
                'linear-gradient(135deg,#0f4c5c,#0d3d4a)' :
                'linear-gradient(135deg,#0a2e38,#1a6b80)';

            document.getElementById('schedTabPred').style.color = isPred ? '#fff' : '#64748b';
            document.getElementById('schedTabPrev').style.color = !isPred ? '#fff' : '#64748b';

            const bp = document.getElementById('schedTabPred').querySelector('span');
            const bv = document.getElementById('schedTabPrev').querySelector('span');
            if (bp) bp.className = 'ml-1 text-[10px] font-black px-2 py-0.5 rounded-full ' +
                (isPred ? 'bg-white/20 text-white' : 'bg-slate-300/60 text-slate-600');
            if (bv) bv.className = 'ml-1 text-[10px] font-black px-2 py-0.5 rounded-full ' +
                (!isPred ? 'bg-white/20 text-white' : 'bg-slate-300/60 text-slate-600');

            _schedTab = tab;

            // Tinggi baris & filter bar predictive/preventive sedikit
            // berbeda, jadi hitung ulang PAGE_ROWS untuk tab yang baru
            // aktif supaya tabel tetap pas mengisi 1 layar penuh.
            requestAnimationFrame(() => {
                computePageRows();
                renderSchedPage(isPred ? 'pred' : 'prev');
            });
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
        const SCROLL_SPEED = 2; // px per frame
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
            if (_activeSection === 'parts') {
                return document.querySelector('#sectionParts .table-scroll');
            }
            return null;
        }

        // Cek apakah section aktif punya 2 tab
        function sectionHasTabs() {
            return _activeSection === 'schedule';
        }

        // Ganti ke tab berikutnya dalam section
        function nextTab() {
            if (_activeSection === 'schedule') {
                const next = _schedTab === 'predictive' ? 'preventive' : 'predictive';
                switchSchedTab(next);
            }
            scrollDir = 1;
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
        //  Each card (predictive & preventive) auto-scrolls independently,
        //  same bounce logic as the table engine above (down → pause → up →
        //  pause → down → ...), and pauses together with it whenever the
        //  cursor moves (shared `scrollPaused` flag).
        // ══════════════════════════════════════════════════════════════
        function makeTodayScroller(elId) {
            let dir = 1; // 1 = turun, -1 = naik
            let raf = null;
            let pauseT = null;

            function step() {
                const el = document.getElementById(elId);

                if (scrollPaused || !el || el.scrollHeight <= el.clientHeight + 2) {
                    raf = requestAnimationFrame(step);
                    return;
                }

                const atBottom = el.scrollTop + el.clientHeight >= el.scrollHeight - 2;
                const atTop = el.scrollTop <= 0;

                if (dir === 1 && atBottom) {
                    dir = -1;
                    clearTimeout(pauseT);
                    pauseT = setTimeout(() => {
                        raf = requestAnimationFrame(step);
                    }, SCROLL_PAUSE);
                    return;
                }
                if (dir === -1 && atTop) {
                    clearTimeout(pauseT);
                    pauseT = setTimeout(() => {
                        dir = 1;
                        raf = requestAnimationFrame(step);
                    }, SCROLL_PAUSE);
                    return;
                }

                el.scrollTop += dir * SCROLL_SPEED;
                raf = requestAnimationFrame(step);
            }

            return {
                start() {
                    raf = requestAnimationFrame(step);
                },
                stop() {
                    cancelAnimationFrame(raf);
                    clearTimeout(pauseT);
                }
            };
        }

        const todayPredScroller = makeTodayScroller('todayPredList');
        const todayPrevScroller = makeTodayScroller('todayPrevList');

        window.addEventListener('load', () => {
            todayPredScroller.start();
            todayPrevScroller.start();
        });

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
        //  SCHEDULE TABLE — client-side pagination + status filter
        //  (menggantikan scroll manual; mekanisme auto-advance-nya
        //   mengikuti pola yang sama seperti engine scroll di atas:
        //   idle → lanjut ke halaman berikutnya, sampai habis → ganti tab
        //   predictive/preventive, lalu ulang dari halaman 1. Mouse gerak
        //   → pause, otomatis lanjut lagi setelah idle (`scrollPaused`
        //   flag yang sama dipakai supaya pause-nya serentak)
        // ══════════════════════════════════════════════════════════════
        let PAGE_ROWS = 10; // fallback awal — dihitung ulang otomatis di computePageRows()

        // Hitung berapa baris muat supaya sub-tab + filter + tabel + footer
        // pas mengisi 1 layar penuh (100vh). Today's Schedule di atasnya
        // SENGAJA tidak dihitung — anggap sudah discroll ke bawah dan
        // section tab/tabel ini berdiri sendiri sebagai "layar" tersendiri.
        // Karena itu perhitungannya pakai offsetHeight tiap elemen "chrome"
        // (bukan getBoundingClientRect relatif viewport), supaya hasilnya
        // tidak berubah-ubah tergantung posisi scroll saat ini.
        function computePageRows() {
            // Ambil baris dari tab yang sedang TERLIHAT saja — kalau ambil
            // dari tab yang lagi disembunyikan (display:none), tingginya
            // akan terbaca 0 dan hasil perhitungan jadi salah.
            const isPred = _schedTab === 'predictive';
            const activeSel = isPred ? '.pred-sched-row' : '.prev-sched-row';
            let row = document.querySelector(activeSel);
            if (!row) row = document.querySelector('.pred-sched-row, .prev-sched-row');
            if (!row) return;

            const rowHeight = row.getBoundingClientRect().height;
            if (!rowHeight || rowHeight < 10) return; // belum ter-render / tersembunyi, biarkan fallback

            const table = row.closest('table');
            const thead = table ? table.querySelector('thead') : null;
            const subtabBar = document.getElementById('schedSubtabBar');
            const filterBar = document.getElementById(isPred ? 'predFilterBar' : 'prevFilterBar');
            const footerBar = document.getElementById(isPred ? 'predFooterBar' : 'prevFooterBar');

            // Total tinggi elemen "tetap" di sekitar baris data (bukan Today's
            // Schedule) — sub-tab bar (+ margin-bottom mb-3=12px), filter bar
            // (+ margin-bottom mb-1=4px), header tabel, footer, dan sedikit
            // jarak aman (10px) di akhir.
            const chrome =
                (subtabBar ? subtabBar.offsetHeight + 12 : 0) +
                (filterBar ? filterBar.offsetHeight + 4 : 0) +
                (thead ? thead.offsetHeight : 0) +
                (footerBar ? footerBar.offsetHeight : 0) +
                10;

            const available = window.innerHeight - chrome;
            PAGE_ROWS = Math.max(3, Math.floor(available / rowHeight));
        }
        const PAGE_SECONDS = 3; // detik tiap halaman sebelum auto-advance

        let predPage = 1;
        let prevPage = 1;

        function getEligibleRows(rowSel, cb) {
            const checked = {
                overdue: document.getElementById(cb.overdue).checked,
                alert: document.getElementById(cb.alert).checked,
                reminder: document.getElementById(cb.reminder).checked,
                secure: document.getElementById(cb.secure).checked,
            };
            return Array.from(document.querySelectorAll(rowSel)).filter(tr => checked[tr.dataset.status]);
        }

        // Render halaman aktif untuk 'pred' atau 'prev'. Mengembalikan { page, totalPages }
        function renderSchedPage(kind) {
            const isPred = kind === 'pred';
            const rowSel = isPred ? '.pred-sched-row' : '.prev-sched-row';
            const cb = isPred ? {
                overdue: 'predCbOverdue',
                alert: 'predCbAlert',
                reminder: 'predCbReminder',
                secure: 'predCbSecure'
            } : {
                overdue: 'prevCbOverdue',
                alert: 'prevCbAlert',
                reminder: 'prevCbReminder',
                secure: 'prevCbSecure'
            };

            const allRows = document.querySelectorAll(rowSel);
            const eligible = getEligibleRows(rowSel, cb);
            const totalPages = Math.max(1, Math.ceil(eligible.length / PAGE_ROWS));

            let page = isPred ? predPage : prevPage;
            if (page > totalPages) page = totalPages;
            if (page < 1) page = 1;
            if (isPred) predPage = page;
            else prevPage = page;

            const start = (page - 1) * PAGE_ROWS;
            const visibleSet = new Set(eligible.slice(start, start + PAGE_ROWS));

            // ── Transisi wipe saat baris berganti ──
            // tbody di-clip instan (tanpa transisi) sebelum baris ditukar,
            // lalu di-wipe dari kiri ke kanan di frame berikutnya. Hasilnya:
            // efek "menyapu" tiap kali pindah halaman/tab/filter.
            const tbody = (allRows[0] || document.querySelector(rowSel))?.closest('tbody') ||
                document.querySelector(isPred ? '#schedPredTab table tbody' : '#schedPrevTab table tbody');
            if (tbody) {
                tbody.style.transition = 'none';
                tbody.style.clipPath = 'inset(0 100% 0 0)'; // tertutup penuh dari kanan
            }

            allRows.forEach(tr => {
                tr.style.display = visibleSet.has(tr) ? '' : 'none';
            });

            if (tbody) {
                requestAnimationFrame(() => {
                    tbody.style.transition = 'clip-path .35s ease';
                    tbody.style.clipPath = 'inset(0 0 0 0)'; // sapuan terbuka penuh
                });
            }

            const cnt = document.getElementById(isPred ? 'predFilterCount' : 'prevFilterCount');
            if (cnt) cnt.textContent = eligible.length + ' / ' + allRows.length + ' jadwal';

            const label = document.getElementById(isPred ? 'predPageLabel' : 'prevPageLabel');
            if (label) label.textContent = 'Halaman ' + page + ' / ' + totalPages;

            const prevBtn = document.getElementById(isPred ? 'predPagePrev' : 'prevPagePrev');
            const nextBtn = document.getElementById(isPred ? 'predPageNext' : 'prevPageNext');
            if (prevBtn) prevBtn.disabled = page <= 1;
            if (nextBtn) nextBtn.disabled = page >= totalPages;

            return {
                page,
                totalPages
            };
        }

        function applyPredFilter() {
            predPage = 1;
            renderSchedPage('pred');
        }

        function predCheckAll(val) {
            ['predCbOverdue', 'predCbAlert', 'predCbReminder', 'predCbSecure'].forEach(id => {
                document.getElementById(id).checked = val;
            });
            applyPredFilter();
        }

        function applyPrevFilter() {
            prevPage = 1;
            renderSchedPage('prev');
        }

        function prevCheckAll(val) {
            ['prevCbOverdue', 'prevCbAlert', 'prevCbReminder', 'prevCbSecure'].forEach(id => {
                document.getElementById(id).checked = val;
            });
            applyPrevFilter();
        }

        // Navigasi manual (tombol Prev/Next). Reset jam auto-advance supaya
        // halaman yang baru dibuka sempat kebaca dulu sebelum lanjut sendiri.
        function predGoTo(delta) {
            predPage += delta;
            renderSchedPage('pred');
            schedTickLeft = PAGE_SECONDS;
        }

        function prevGoTo(delta) {
            prevPage += delta;
            renderSchedPage('prev');
            schedTickLeft = PAGE_SECONDS;
        }

        // ── Auto-advance engine (jalan tiap 1 detik, hanya aktif saat
        //    section Schedule sedang ditampilkan) ──────────────────────
        let schedTickLeft = PAGE_SECONDS;

        setInterval(() => {
            if (_activeSection !== 'schedule' || scrollPaused) return;

            schedTickLeft--;
            if (schedTickLeft > 0) return;
            schedTickLeft = PAGE_SECONDS;

            const kind = _schedTab === 'predictive' ? 'pred' : 'prev';
            const {
                page,
                totalPages
            } = renderSchedPage(kind);

            if (page < totalPages) {
                // Masih ada halaman berikutnya di tab ini
                if (kind === 'pred') predPage++;
                else prevPage++;
                renderSchedPage(kind);
            } else {
                // Sudah halaman terakhir → ganti tab, mulai dari halaman 1 lagi
                if (kind === 'pred') prevPage = 1;
                else predPage = 1;
                switchSchedTab(kind === 'pred' ? 'preventive' : 'predictive');
                renderSchedPage(kind === 'pred' ? 'prev' : 'pred');
            }
        }, 1000);

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

        // ══════════════════════════════════════════════════════════════
        //  CALENDAR & CATEGORY — data gabungan predictive + preventive,
        //  dipakai untuk kalender bulanan + daftar terfilter di menu
        //  "Kalender & Kategori". Data ini di-refresh ulang setiap polling
        //  AJAX (lihat blok <script> AJAX AUTO-REFRESH di bagian bawah),
        //  jadi status & warna tanggal selalu ikut berubah tanpa reload.
        // ══════════════════════════════════════════════════════════════
        let CAL_ITEMS = <?= json_encode($calendarItems, JSON_UNESCAPED_UNICODE) ?>;
        const CAL_STATUS_LABEL = {
            overdue: 'Overdue',
            alert: 'Alert',
            reminder: 'Reminder',
            secure: 'Aman'
        };
        const CAL_STATUS_PRIORITY = {
            overdue: 3,
            alert: 2,
            reminder: 1,
            secure: 0
        };
        const CAL_MONTHS_ID = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

        // Peta tanggal ('YYYY-MM-DD') → status paling "genting" pada tanggal itu
        function calBuildDateStatus(items) {
            const map = {};
            items.forEach(it => {
                if (!it.planDate) return;
                const cur = map[it.planDate];
                if (!cur || CAL_STATUS_PRIORITY[it.status] > CAL_STATUS_PRIORITY[cur]) {
                    map[it.planDate] = it.status;
                }
            });
            return map;
        }
        let CAL_DATE_STATUS = calBuildDateStatus(CAL_ITEMS);

        const today_ = new Date();
        let calYear = today_.getFullYear();
        let calMonth = today_.getMonth(); // 0-based
        let calFilter = null; // {kind:'status'|'date', value:'...'}

        function calPad(n) {
            return String(n).padStart(2, '0');
        }

        function calChangeMonth(delta) {
            calMonth += delta;
            if (calMonth < 0) {
                calMonth = 11;
                calYear--;
            } else if (calMonth > 11) {
                calMonth = 0;
                calYear++;
            }
            renderCalGrid();
        }

        function renderCalGrid() {
            const label = document.getElementById('calMonthLabel');
            if (label) label.textContent = CAL_MONTHS_ID[calMonth] + ' ' + calYear;

            const grid = document.getElementById('calGrid');
            if (!grid) return;

            const firstDow = new Date(calYear, calMonth, 1).getDay(); // 0=Min
            const daysInMonth = new Date(calYear, calMonth + 1, 0).getDate();
            const todayKey = today_.getFullYear() + '-' + calPad(today_.getMonth() + 1) + '-' + calPad(today_.getDate());

            let html = '';
            for (let i = 0; i < firstDow; i++) {
                html += '<div class="cal-day cal-empty"></div>';
            }
            for (let d = 1; d <= daysInMonth; d++) {
                const dateKey = calYear + '-' + calPad(calMonth + 1) + '-' + calPad(d);
                const status = CAL_DATE_STATUS[dateKey];
                const hasSched = !!status;
                const isToday = dateKey === todayKey;
                const isSelected = calFilter && calFilter.kind === 'date' && calFilter.value === dateKey;
                const cls = ['cal-day', 'cal-clickable'];
                if (hasSched) {
                    cls.push('cal-has-sched');
                    cls.push('cal-status-' + status);
                }
                if (isToday) cls.push('cal-today');
                if (isSelected) cls.push('cal-selected');
                const title = hasSched ? (CAL_STATUS_LABEL[status] + ' — klik untuk lihat daftar') : 'Tidak ada pekerjaan — klik untuk lihat detail';
                html += `<div class="${cls.join(' ')}" onclick="filterCalByDate('${dateKey}')" title="${title}">
                    <span>${d}</span>
                    ${hasSched ? '<span class="cal-day-dot"></span>' : ''}
                </div>`;
            }
            grid.innerHTML = html;
        }

        function calUpdateCardHighlight() {
            ['overdue', 'alert', 'reminder', 'secure'].forEach(s => {
                const el = document.getElementById('calCard' + s.charAt(0).toUpperCase() + s.slice(1));
                if (!el) return;
                const active = calFilter && calFilter.kind === 'status' && calFilter.value === s;
                el.classList.toggle('is-active', !!active);
            });
        }

        function calEsc(str) {
            return String(str ?? '').replace(/[&<>"']/g, c => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            } [c]));
        }

        const CAL_STATUS_COLOR = {
            overdue: '#ef4444',
            alert: '#eab308',
            reminder: '#f97316',
            secure: '#22c55e'
        };

        // Kartu "daun" — 1 baris kegiatan (maintenance point) di dalam grup mesin.
        // Info dept/line/OP/mesin TIDAK diulang lagi di sini karena sudah
        // tampil di header grup di atasnya — supaya tidak berulang dan
        // tetap mudah dibaca.
        function calLeafCard(it) {
            const color = CAL_STATUS_COLOR[it.status] || '#94a3b8';
            const typeBadge = it.type === 'predictive' ?
                '<span class="bg-[#e8f4f8] text-[#0f4c5c] font-bold px-1.5 py-0.5 rounded" style="font-size:.56rem;">Predictive</span>' :
                '<span class="bg-indigo-50 text-indigo-700 font-bold px-1.5 py-0.5 rounded" style="font-size:.56rem;">Preventive</span>';
            return `<div class="flex items-start gap-2 bg-white rounded-lg px-2.5 py-2 border border-slate-100">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-1.5 flex-wrap mb-0.5">${typeBadge}${it.process ? `<span class="text-slate-400" style="font-size:.6rem;">${calEsc(it.process)}</span>` : ''}</div>
                    <p class="text-slate-600 font-medium" style="font-size:.7rem;line-height:1.4;">${calEsc(it.point)}</p>
                </div>
                <div class="flex-shrink-0 text-right">
                    <span class="inline-block px-2 py-0.5 rounded-full font-black uppercase tracking-wide" style="font-size:.56rem;background:${color}22;color:${color};">${CAL_STATUS_LABEL[it.status]}</span>
                    <p class="text-slate-400 font-semibold mt-1" style="font-size:.6rem;">${it.planDateDisp}</p>
                    <p class="text-slate-300 font-semibold" style="font-size:.56rem;">H${it.remaining >= 0 ? '-' : '+'}${Math.abs(it.remaining)}</p>
                </div>
            </div>`;
        }

        // Susun data menjadi struktur bertingkat: Department → Line → Operation
        // Process → Nama Mesin, persis pola pengelompokan yang sudah dipakai
        // di fitur Report Massal Preventive (dashboard_user.php) — supaya
        // datanya gampang dibaca orang awam: langsung kelihatan lokasinya
        // dulu (dept/line), baru OP-nya, baru mesin & aktivitasnya.
        function calBuildGroups(items) {
            const groups = {};
            items.forEach(it => {
                const dept = it.dept || 'Tanpa Departemen';
                const line = it.line || 'Tanpa Line';
                const op = it.op || 'Tanpa OP';
                const machine = it.machine || 'Tanpa Nama Mesin';
                (groups[dept] ??= {});
                (groups[dept][line] ??= {});
                (groups[dept][line][op] ??= {});
                (groups[dept][line][op][machine] ??= []).push(it);
            });
            return groups;
        }

        function renderGroupedCalList(items, emptyMessage) {
            if (items.length === 0) {
                return `<div class="text-center py-12 text-slate-400">
                    <i class="fas fa-calendar-xmark text-4xl block mb-3 text-slate-200"></i>
                    <p class="font-semibold text-sm">${emptyMessage || 'Tidak ada jadwal untuk filter ini.'}</p>
                </div>`;
            }

            const groups = calBuildGroups(items);
            let html = '';
            Object.keys(groups).sort().forEach(dept => {
                html += `<div class="mb-4">
                    <div class="flex items-center gap-1.5 mb-2">
                        <i class="fas fa-building text-[#6d28d9] text-xs"></i>
                        <span class="text-xs font-black text-[#6d28d9] uppercase tracking-widest">${calEsc(dept)}</span>
                    </div>`;
                Object.keys(groups[dept]).sort().forEach(line => {
                    html += `<div class="ml-3 pl-3 border-l-2 border-[#ede9fe] mb-3">
                        <div class="flex items-center gap-1.5 mb-2">
                            <i class="fas fa-industry text-slate-400 text-[11px]"></i>
                            <span class="text-[11px] font-bold text-slate-500 uppercase tracking-wide">${calEsc(line)}</span>
                        </div>`;
                    Object.keys(groups[dept][line]).sort().forEach(op => {
                        const machines = groups[dept][line][op];
                        const opCount = Object.values(machines).reduce((a, arr) => a + arr.length, 0);
                        html += `<details class="ml-1 mb-2 group border border-slate-200 rounded-xl overflow-hidden">
                            <summary class="flex items-center gap-2 cursor-pointer select-none list-none px-3 py-2.5 bg-[#f5f3ff] hover:bg-[#ede9fe] transition">
                                <i class="fas fa-chevron-right text-[#6d28d9] text-[10px] transition-transform group-open:rotate-90"></i>
                                <span class="flex-1 min-w-0 text-sm font-black text-[#6d28d9] leading-tight truncate">OP ${calEsc(op)}</span>
                                <span class="flex-shrink-0 bg-[#6d28d9] text-white text-[10px] font-black px-2 py-1 rounded-full">${opCount} jadwal</span>
                            </summary>
                            <div class="space-y-2 p-3 bg-white">
                                ${Object.keys(machines).sort().map(machine => `
                                    <div class="rounded-lg border border-slate-100 overflow-hidden">
                                        <div class="px-3 py-1.5 bg-slate-50 flex items-center gap-1.5">
                                            <i class="fas fa-gear text-slate-400 text-[10px]"></i>
                                            <span class="text-xs font-bold text-slate-600">${calEsc(machine)}</span>
                                        </div>
                                        <div class="p-2 space-y-1.5">
                                            ${machines[machine].map(it => calLeafCard(it)).join('')}
                                        </div>
                                    </div>
                                `).join('')}
                            </div>
                        </details>`;
                    });
                    html += `</div>`;
                });
                html += `</div>`;
            });
            return html;
        }

        function renderCalList() {
            let items = CAL_ITEMS;
            const titleEl = document.getElementById('calListTitle');
            const subEl = document.getElementById('calListSubtitle');
            const resetBtn = document.getElementById('calResetBtn');
            let emptyMessage = 'Tidak ada jadwal untuk filter ini.';

            if (calFilter && calFilter.kind === 'status') {
                items = CAL_ITEMS.filter(it => it.status === calFilter.value);
                titleEl.textContent = 'Kategori: ' + CAL_STATUS_LABEL[calFilter.value];
                subEl.textContent = items.length + ' jadwal masuk kategori ini — dikelompokkan per Dept › Line › OP › Mesin';
                resetBtn.classList.remove('hidden');
            } else if (calFilter && calFilter.kind === 'date') {
                items = CAL_ITEMS.filter(it => it.planDate === calFilter.value);
                const [y, m, d] = calFilter.value.split('-');
                const dispDateLong = new Date(y, m - 1, d).toLocaleDateString('id-ID', {
                    day: 'numeric',
                    month: 'long',
                    year: 'numeric'
                });
                const dispDateShort = calPad(d) + '-' + calPad(m) + '-' + y;
                titleEl.textContent = 'Tanggal: ' + dispDateLong;
                subEl.textContent = items.length + ' jadwal pada tanggal ini — dikelompokkan per Dept › Line › OP › Mesin';
                emptyMessage = 'Tidak ada pekerjaan di tanggal ' + dispDateShort;
                resetBtn.classList.remove('hidden');
            } else {
                items = CAL_ITEMS;
                titleEl.textContent = 'Semua Jadwal';
                subEl.textContent = 'Klik kategori atau tanggal untuk memfilter — dikelompokkan per Dept › Line › OP › Mesin';
                resetBtn.classList.add('hidden');
            }

            // Urutkan supaya yang paling genting & paling dekat tampil duluan
            // di dalam masing-masing grup mesin.
            items = [...items].sort((a, b) => a.remaining - b.remaining);

            const listGrid = document.getElementById('calListGrid');
            listGrid.innerHTML = renderGroupedCalList(items, emptyMessage);

            const countEl = document.getElementById('calListCount');
            if (countEl) countEl.textContent = items.length + ' / ' + CAL_ITEMS.length + ' jadwal';

            calUpdateCardHighlight();
        }

        function filterCalByStatus(status) {
            if (calFilter && calFilter.kind === 'status' && calFilter.value === status) {
                calFilter = null; // toggle off kalau diklik ulang
            } else {
                calFilter = {
                    kind: 'status',
                    value: status
                };
            }
            renderCalList();
        }

        function filterCalByDate(dateKey) {
            if (calFilter && calFilter.kind === 'date' && calFilter.value === dateKey) {
                calFilter = null;
            } else {
                calFilter = {
                    kind: 'date',
                    value: dateKey
                };
            }
            renderCalGrid();
            renderCalList();
        }

        function calResetFilter() {
            calFilter = null;
            renderCalGrid();
            renderCalList();
        }

        // Init filter counts on load
        document.addEventListener('DOMContentLoaded', function() {
            computePageRows();
            applyPredFilter();
            applyPrevFilter();
            applyHistMonthFilter();
            renderCalGrid();
            renderCalList();
        });

        // Hitung ulang baris/halaman saat ukuran layar berubah (debounced)
        let resizeRowsTimer = null;
        window.addEventListener('resize', () => {
            clearTimeout(resizeRowsTimer);
            resizeRowsTimer = setTimeout(() => {
                computePageRows();
                renderSchedPage('pred');
                renderSchedPage('prev');
            }, 300);
        });
    </script>


    <script>
        // ══════════════════════════════════════════════════════════════
        //  AJAX AUTO-REFRESH — polls monitor_ajax.php every 30 seconds
        //  Updates: Today's Schedule (both columns), table row data
        //           and "Last updated" timestamps
        // ══════════════════════════════════════════════════════════════
        (function() {
            const POLL_INTERVAL = 30000; // 30 seconds
            const AJAX_URL = 'monitor_ajax.php';

            const ajaxDot = document.getElementById('ajaxDot');
            const ajaxLabel = document.getElementById('ajaxLabel');

            // ── Utility helpers ──────────────────────────────────────
            function setDot(state) { // 'ok' | 'loading' | 'error'
                if (!ajaxDot) return;
                const map = {
                    ok: '#22c55e',
                    loading: '#f59e0b',
                    error: '#ef4444'
                };
                ajaxDot.style.background = map[state] || map.ok;
                if (ajaxLabel) ajaxLabel.textContent = state === 'loading' ? 'Refreshing…' : state === 'error' ? 'Retry…' : 'Live';
            }

            function remainingCls(val) {
                // returns Tailwind colour classes matching PHP remainingClass()
                if (val <= 0) return 'text-red-600 font-black';
                if (val <= 7) return 'text-amber-600 font-black';
                if (val <= 30) return 'text-orange-500 font-bold'; // simplified (no per-row reminder)
                return 'text-slate-700 font-semibold';
            }

            function badgePO(v) {
                return v && v.toLowerCase() === 'open' ?
                    '<span class="badge ps-open">Open</span>' :
                    '<span class="badge ps-close">Close</span>';
            }

            function badgeMS(v) {
                const lv = (v || '').toLowerCase();
                if (lv === 'soon') return '<span class="badge ms-soon">Soon</span>';
                if (lv === 'done') return '<span class="badge ms-done">Done</span>';
                return '<span class="badge badge-none">' + v + '</span>';
            }

            // ── Build a today card item ────────────────────────────────
            function makeTodayItem(item, idx, color) {
                const sub = [item.dept, item.line ? '· ' + item.line : ''].filter(Boolean).join(' ');
                return `<div class="bg-white/80 rounded-xl px-3 py-2 flex items-start gap-2 border border-${color}-100 shadow-sm">
                <div class="w-5 h-5 rounded-md bg-${color}-100 flex items-center justify-center flex-shrink-0 mt-0.5">
                    <span class="text-${color}-600 font-black" style="font-size:.55rem;">${idx + 1}</span>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="font-black text-${color}-900 truncate" style="font-size:.72rem;">${item.machine}${item.op ? ' · ' + item.op : ''}</p>
                    <p class="text-${color}-600 mt-0.5" style="font-size:.63rem;line-height:1.4;">${item.point}</p>
                    ${sub ? `<p class="text-${color}-400 mt-0.5 truncate" style="font-size:.58rem;">${sub}</p>` : ''}
                </div>
                <span class="flex-shrink-0 bg-${color}-100 text-${color}-700 font-bold px-1.5 py-0.5 rounded" style="font-size:.58rem;">${item.interval}mo</span>
            </div>`;
            }

            // ── Render Today's Schedule ────────────────────────────────
            function renderToday(data) {
                const today = data.today;
                if (!today) return;

                // Predictive column
                const predList = document.getElementById('todayPredList');
                const predCount = document.getElementById('todayPredCount');
                if (predList) {
                    if (today.predictive.length > 0) {
                        predList.innerHTML = '<div class="today-grid">' +
                            today.predictive.map((it, i) => makeTodayItem(it, i, 'blue')).join('') +
                            '</div>';
                    } else {
                        // predList.innerHTML = '<div class="flex items-center gap-2 py-2 px-1"><i class="fas fa-check-circle text-[#5aaec4] text-xs"></i><span class="text-[#3d8fa3] font-semibold" style="font-size:.7rem;">No predictive schedule for today</span></div>';
                    }
                }
                if (predCount) predCount.textContent = today.predictive.length;

                // Preventive column
                const prevList = document.getElementById('todayPrevList');
                const prevCount = document.getElementById('todayPrevCount');
                if (prevList) {
                    if (today.preventive.length > 0) {
                        prevList.innerHTML = '<div class="today-grid">' +
                            today.preventive.map((it, i) => makeTodayItem(it, i, 'indigo')).join('') +
                            '</div>';
                    } else {
                        //prevList.innerHTML = '<div class="flex items-center gap-2 py-2 px-1"><i class="fas fa-check-circle text-indigo-300 text-xs"></i><span class="text-[#3d8fa3] font-semibold" style="font-size:.7rem;">No preventive schedule for today</span></div>';
                    }
                }
                if (prevCount) prevCount.textContent = today.preventive.length;

                // Date label
                const dl = document.getElementById('todayDateLabel');
                if (dl && today.date) dl.textContent = today.date;
            }

            // ── Render Predictive schedule rows ───────────────────────
            function renderPredSchedule(rows) {
                const tbody = document.querySelector('#schedPredTab table tbody');
                if (!tbody || !rows) return;
                if (rows.length === 0) {
                    tbody.innerHTML = `<tr><td colspan="10" class="tbl-td text-center py-16 text-slate-400">
                    <i class="fas fa-calendar-xmark text-4xl block mb-3 text-slate-200"></i>
                    <p class="font-semibold">Belum ada data schedule predictive.</p></td></tr>`;
                    return;
                }
                tbody.innerHTML = rows.map((r, i) => {
                    const cls = remainingCls(r.remaining);
                    return `<tr class="pred-sched-row" data-status="${r.status_cls}">
                    <td class="tbl-td text-slate-400 font-mono px-2 py-1.5" style="font-size:.68rem;">${i + 1}</td>
                    <td class="tbl-td px-2 py-1.5" style="overflow:hidden;">
                        <div class="font-bold text-slate-800 leading-tight" style="font-size:.72rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${r.machine}">${r.machine}</div>
                        ${r.process ? `<div class="text-slate-500 mt-0.5" style="font-size:.65rem;">${r.process}</div>` : ''}
                        <div class="text-slate-400 mt-0.5" style="font-size:.62rem;">${r.dept}${r.line ? ' · ' + r.line : ''}${r.op ? ' · OP ' + r.op : ''}</div>
                    </td>
                    <td class="tbl-td px-2 py-1.5">
                        <span style="font-size:.72rem;color:#334155;display:block;word-break:break-word;white-space:normal;line-height:1.4;">${r.point}</span>
                        ${r.unit ? `<div class="text-slate-400 italic mt-0.5" style="font-size:.62rem;">${r.unit}</div>` : ''}
                    </td>
                    <td class="tbl-td text-center text-slate-500 px-1 py-1.5" style="font-size:.65rem;">${r.use_date}</td>
                    <td class="tbl-td text-center px-1 py-1.5"><span class="bg-slate-100 text-slate-600 font-bold px-1.5 py-0.5 rounded" style="font-size:.65rem;">${r.interval}mo</span></td>
                    <td class="tbl-td text-center text-slate-600 font-semibold px-1 py-1.5" style="font-size:.65rem;">${r.plan_date}</td>
                    <td class="tbl-td text-center px-1 py-1.5 ${cls}" style="font-size:.75rem;">${r.remaining}</td>
                    <td class="tbl-td text-center px-1 py-1.5">${badgePO(r.part_order)}</td>
                    <td class="tbl-td text-center px-1 py-1.5">${badgePO(r.part_avail)}</td>
                    <td class="tbl-td text-center px-1 py-1.5">${badgeMS(r.maint_status)}</td>
                </tr>`;
                }).join('');
                renderSchedPage('pred'); // pertahankan halaman aktif, tidak reset ke 1
                // Update count badge in tab
                const badge = document.querySelector('#schedTabPred span');
                if (badge) badge.textContent = rows.length;
                // Update footer
                const footer = document.querySelector('#schedPredTab .bg-slate-50 span:first-child');
                if (footer) footer.textContent = rows.length + ' jadwal predictive';
            }

            // ── Render Preventive schedule rows ───────────────────────
            function renderPrevSchedule(rows) {
                const tbody = document.querySelector('#schedPrevTab table tbody');
                if (!tbody || !rows) return;
                if (rows.length === 0) {
                    tbody.innerHTML = `<tr><td colspan="8" class="tbl-td text-center py-16 text-slate-400">
                    <i class="fas fa-shield-halved text-4xl block mb-3 text-slate-200"></i>
                    <p class="font-semibold">Belum ada data schedule preventive.</p></td></tr>`;
                    return;
                }
                tbody.innerHTML = rows.map((r, i) => {
                    const cls = remainingCls(r.remaining);
                    return `<tr class="prev-sched-row" data-status="${r.status_cls}">
                    <td class="tbl-td text-slate-400 font-mono px-2 py-1.5" style="font-size:.68rem;">${i + 1}</td>
                    <td class="tbl-td px-2 py-1.5" style="min-width:160px;">
                        <div class="font-bold text-slate-800 leading-tight" style="font-size:.72rem;">${r.machine}</div>
                        ${r.process ? `<div class="text-slate-500 mt-0.5" style="font-size:.65rem;">${r.process}</div>` : ''}
                        <div class="text-slate-400 mt-0.5" style="font-size:.62rem;">${r.dept}${r.line ? ' · ' + r.line : ''}${r.op ? ' · OP ' + r.op : ''}</div>
                    </td>
                    <td class="tbl-td px-2 py-1.5" style="min-width:160px;">
                        <span style="font-size:.72rem;color:#334155;display:block;word-break:break-word;white-space:normal;line-height:1.4;">${r.point}</span>
                        ${r.unit ? `<div class="text-slate-400 italic mt-0.5" style="font-size:.62rem;">${r.unit}</div>` : ''}
                    </td>
                    <td class="tbl-td text-center text-slate-500 px-1 py-1.5 whitespace-nowrap" style="font-size:.65rem;">${r.use_date}</td>
                    <td class="tbl-td text-center px-1 py-1.5"><span class="bg-slate-100 text-slate-600 font-bold px-1.5 py-0.5 rounded" style="font-size:.65rem;">${r.interval} mo</span></td>
                    <td class="tbl-td text-center text-slate-600 font-semibold px-1 py-1.5 whitespace-nowrap" style="font-size:.65rem;">${r.plan_date}</td>
                    <td class="tbl-td text-center px-1 py-1.5 whitespace-nowrap ${cls}" style="font-size:.75rem;">${r.remaining}</td>
                    <td class="tbl-td text-center px-1 py-1.5">${badgeMS(r.maint_status)}</td>
                </tr>`;
                }).join('');
                renderSchedPage('prev'); // pertahankan halaman aktif, tidak reset ke 1
                // Update count badge in tab
                const badge = document.querySelector('#schedTabPrev span');
                if (badge) badge.textContent = rows.length;
                const footer = document.querySelector('#schedPrevTab .bg-slate-50 span:first-child');
                if (footer) footer.textContent = rows.length + ' jadwal preventive';
            }

            // ── Render Parts table ─────────────────────────────────────
            function renderParts(rows) {
                const tbody = document.querySelector('#sectionParts table tbody');
                if (!tbody || !rows) return;
                const STATUS_CLS = {
                    'Zero Stock': 'badge-zero',
                    'Low Stock': 'badge-low',
                    'In Stock': 'badge-in',
                    'Over Stock': 'badge-over',
                };
                const ACTUAL_CLS = (actual, safety) => {
                    if (actual === 0) return 'text-red-500';
                    if (actual < safety) return 'text-orange-500';
                    if (actual === safety) return 'text-emerald-600';
                    return 'text-violet-600';
                };
                if (rows.length === 0) {
                    tbody.innerHTML = `<tr><td colspan="7" class="tbl-td text-center py-16 text-slate-400">
                    <i class="fas fa-box-open text-4xl block mb-3 text-slate-200"></i>
                    <p class="font-semibold">Belum ada data part.</p></td></tr>`;
                    return;
                }
                tbody.innerHTML = rows.map((p, i) => {
                    const badgeCls = STATUS_CLS[p.status] || 'badge-none';
                    const aCls = ACTUAL_CLS(p.actual, p.safety);
                    const effSign = p.effective >= 0 ? '+' : '';
                    const effCls = p.effective < 0 ? 'text-red-500' : 'text-slate-600';
                    return `<tr>
                    <td class="tbl-td text-slate-400 text-xs font-mono">${i + 1}</td>
                    <td class="tbl-td font-mono font-bold text-slate-700 tracking-wide text-sm">${p.code}</td>
                    <td class="tbl-td text-slate-700 text-sm font-medium" style="max-width:260px;">${p.description}</td>
                    <td class="tbl-td text-center"><span class="bg-slate-100 text-slate-600 font-bold px-3 py-1 rounded-lg text-sm">${p.safety}</span></td>
                    <td class="tbl-td text-center font-black text-lg ${aCls}">${p.actual}</td>
                    <td class="tbl-td text-center font-bold text-sm ${effCls}">${effSign}${p.effective}</td>
                    <td class="tbl-td text-center"><span class="badge ${badgeCls}">${p.status}</span></td>
                </tr>`;
                }).join('');
                // Update footer
                const footer = document.querySelector('#sectionParts .bg-slate-50 span:first-child');
                if (footer) footer.textContent = rows.length + ' parts';
            }

            // ── Update "Last updated" timestamps ──────────────────────
            function updateTimestamps(ts) {
                document.querySelectorAll('.bg-slate-50 span:last-child').forEach(el => {
                    if (el.textContent.startsWith('Last updated:')) el.textContent = 'Last updated: ' + ts;
                });
            }

            // ── Rebuild Calendar & Category data (live, no reload needed) ─
            function buildCalItemsFromAjax(schedules, prevSchedules) {
                const items = [];
                (schedules || []).forEach(r => {
                    items.push({
                        type: 'predictive',
                        typeLabel: 'Predictive',
                        machine: r.machine,
                        process: r.process,
                        unit: r.unit,
                        dept: r.dept,
                        line: r.line,
                        op: r.op,
                        point: r.point,
                        planDate: r.plan_date_raw || '',
                        planDateDisp: r.plan_date,
                        remaining: r.remaining,
                        status: r.status_cls,
                        interval: r.interval,
                    });
                });
                (prevSchedules || []).forEach(r => {
                    items.push({
                        type: 'preventive',
                        typeLabel: 'Preventive',
                        machine: r.machine,
                        process: r.process,
                        unit: r.unit,
                        dept: r.dept,
                        line: r.line,
                        op: r.op,
                        point: r.point,
                        planDate: r.plan_date_raw || '',
                        planDateDisp: r.plan_date,
                        remaining: r.remaining,
                        status: r.status_cls,
                        interval: r.interval,
                    });
                });
                return items;
            }

            function updateCalStatCards(items) {
                const counts = {
                    overdue: 0,
                    alert: 0,
                    reminder: 0,
                    secure: 0
                };
                // Set per status untuk menghitung mesin unik (distinct)
                const machineSets = {
                    overdue: new Set(),
                    alert: new Set(),
                    reminder: new Set(),
                    secure: new Set()
                };
                items.forEach(it => {
                    if (counts[it.status] !== undefined) {
                        counts[it.status]++;
                        machineSets[it.status].add(it.machine);
                    }
                });
                const setText = (id, val) => {
                    const el = document.getElementById(id);
                    if (el) el.textContent = val;
                };
                setText('calCntOverdue', counts.overdue);
                setText('calCntAlert', counts.alert);
                setText('calCntReminder', counts.reminder);
                setText('calCntSecure', counts.secure);

                setText('calMachOverdue', machineSets.overdue.size + ' mesin, ' + counts.overdue + ' jadwal');
                setText('calMachAlert', machineSets.alert.size + ' mesin, ' + counts.alert + ' jadwal');
                setText('calMachReminder', machineSets.reminder.size + ' mesin, ' + counts.reminder + ' jadwal');
                setText('calMachSecure', machineSets.secure.size + ' mesin, ' + counts.secure + ' jadwal');
            }

            function renderCalendarLive(data) {
                if (!data.schedules || !data.prevSchedules) return;
                // CAL_ITEMS/CAL_DATE_STATUS dideklarasikan di blok <script>
                // "CALENDAR & CATEGORY" sebelumnya — di-refresh di sini supaya
                // status (overdue/alert/reminder/secure) & warna tanggal ikut
                // berubah otomatis tiap polling, tanpa perlu reload halaman.
                CAL_ITEMS = buildCalItemsFromAjax(data.schedules, data.prevSchedules);
                CAL_DATE_STATUS = calBuildDateStatus(CAL_ITEMS);
                updateCalStatCards(CAL_ITEMS);
                renderCalGrid();
                renderCalList();
            }

            // ── Main poll function ─────────────────────────────────────
            async function poll() {
                setDot('loading');
                try {
                    const res = await fetch(AJAX_URL + '?type=all&_=' + Date.now());
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    const data = await res.json();

                    renderToday(data);
                    renderPredSchedule(data.schedules);
                    renderPrevSchedule(data.prevSchedules);
                    renderParts(data.parts);
                    renderCalendarLive(data);
                    if (data.refreshed_at) updateTimestamps(data.refreshed_at);

                    setDot('ok');
                } catch (e) {
                    console.warn('[AJAX] poll error:', e);
                    setDot('error');
                }
            }

            // Start polling after initial load
            window.addEventListener('load', () => {
                setTimeout(poll, 5000); // first check 5 s after load
                setInterval(poll, POLL_INTERVAL);
            });
        })();
    </script>
</body>

</html>