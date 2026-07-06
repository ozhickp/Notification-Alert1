<?php

/**
 * monitor_ajax.php — AJAX endpoint for auto-refresh
 * Returns JSON with today's predictive & preventive schedules,
 * full schedule tables, parts, and history.
 *
 * Usage: GET monitor_ajax.php?type=today|schedules|parts|history|all
 */
include 'config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store');

$todayStr = date('Y-m-d');
$type = $_GET['type'] ?? 'all';

// ── Auto-update remaining_day setiap AJAX dipanggil ───────────────────────────
// Ini memastikan nilai remaining_day selalu akurat (DATEDIFF dari CURDATE)
// termasuk saat polling 30 detik — independen dari monitor.php / dashboard_user.php
try {
    $GLOBALS['pdo']->exec("
        UPDATE schedules
        SET remaining_day = DATEDIFF(change_date_plan, CURDATE())
        WHERE change_date_plan IS NOT NULL
    ");
    $GLOBALS['pdo']->exec("
        UPDATE schedules_preventive
        SET remaining_day = DATEDIFF(change_date_plan, CURDATE())
        WHERE change_date_plan IS NOT NULL
    ");
} catch (Exception $e) {
    // Log error tapi jangan hentikan response JSON
    error_log('[monitor_ajax] Gagal update remaining_day: ' . $e->getMessage());
}

function remainingClassStr(int $days, int $reminder = 30): string
{
    if ($days <= 0)         return 'overdue';
    if ($days <= 7)         return 'alert';
    if ($days <= $reminder) return 'reminder';
    return                         'secure';
}

$out = [];

// ── TODAY schedules (predictive + preventive) ──────────────────────────
if ($type === 'today' || $type === 'all') {
    $todayPred = [];
    $todayPrev = [];
    try {
        $rows = $GLOBALS['pdo']->query("
            SELECT s.*,
                   COALESCE(p.plant_name, s.department) AS department,
                   COALESCE(l.line_name, s.line) AS line
            FROM schedules s
            LEFT JOIN plants p ON p.id = s.department
            LEFT JOIN line l ON l.id = s.line
            WHERE s.change_date_plan = '$todayStr'
            ORDER BY s.remaining_day ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $todayPred[] = [
                'machine'  => $r['machine_name'] ?? '-',
                'point'    => $r['maintenance_point'] ?? '-',
                'dept'     => $r['department'] ?? '',
                'line'     => $r['line'] ?? '',
                'op'       => $r['operation_process'] ?? '',
                'interval' => (int)($r['interval_month'] ?? 0),
            ];
        }
    } catch (Exception $e) {
        error_log('[monitor_ajax] today predictive: ' . $e->getMessage());
    }

    try {
        $rows = $GLOBALS['pdo']->query("
            SELECT s.*,
                   COALESCE(p.plant_name, s.department) AS department,
                   COALESCE(l.line_name, s.line) AS line
            FROM schedules_preventive s
            LEFT JOIN plants p ON p.id = s.department
            LEFT JOIN line l ON l.id = s.line
            WHERE s.change_date_plan = '$todayStr'
            ORDER BY s.remaining_day ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $todayPrev[] = [
                'machine'  => $r['machine_name'] ?? '-',
                'point'    => $r['maintenance_point'] ?? '-',
                'dept'     => $r['department'] ?? '',
                'line'     => $r['line'] ?? '',
                'op'       => $r['operation_process'] ?? '',
                'interval' => (int)($r['interval_month'] ?? 0),
            ];
        }
    } catch (Exception $e) {
        error_log('[monitor_ajax] today preventive: ' . $e->getMessage());
    }

    $out['today'] = [
        'date'       => date('d M Y'),
        'predictive' => $todayPred,
        'preventive' => $todayPrev,
    ];
}

// ── FULL SCHEDULES (predictive + preventive) ──────────────────────────
if ($type === 'schedules' || $type === 'all') {
    $schedules = [];
    try {
        $rows = $GLOBALS['pdo']->query("
            SELECT s.*,
                   COALESCE(p.plant_name, s.department) AS department,
                   COALESCE(l.line_name, s.line) AS line
            FROM schedules s
            LEFT JOIN plants p ON p.id = s.department
            LEFT JOIN line l ON l.id = s.line
            ORDER BY s.remaining_day ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $days     = (int)$r['remaining_day'];
            $reminder = (int)($r['reminder_activity'] ?? 30);
            $schedules[] = [
                'machine'      => $r['machine_name'] ?? '-',
                'process'      => $r['process_machine'] ?? '',
                'dept'         => $r['department'] ?? '',
                'line'         => $r['line'] ?? '',
                'op'           => $r['operation_process'] ?? '',
                'point'        => $r['maintenance_point'] ?? '-',
                'unit'         => $r['name_unit'] ?? '',
                'use_date'     => $r['use_date'] ? date('d M Y', strtotime($r['use_date'])) : '-',
                'interval'     => (int)($r['interval_month'] ?? 0),
                'plan_date'     => $r['change_date_plan'] ? date('d M Y', strtotime($r['change_date_plan'])) : '-',
                'plan_date_raw' => $r['change_date_plan'] ? date('Y-m-d', strtotime($r['change_date_plan'])) : '',
                'remaining'    => $days,
                'status_cls'   => remainingClassStr($days, $reminder),
                'part_order'   => $r['part_order'] ?? 'close',
                'part_avail'   => $r['part_availability'] ?? 'close',
                'maint_status' => $r['maintenance_status'] ?? '',
            ];
        }
    } catch (Exception $e) {
        error_log('[monitor_ajax] schedules: ' . $e->getMessage());
    }

    $prevSchedules = [];
    try {
        $rows = $GLOBALS['pdo']->query("
            SELECT s.*,
                   COALESCE(p.plant_name, s.department) AS department,
                   COALESCE(l.line_name, s.line) AS line
            FROM schedules_preventive s
            LEFT JOIN plants p ON p.id = s.department
            LEFT JOIN line l ON l.id = s.line
            ORDER BY s.remaining_day ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $days     = (int)($r['remaining_day'] ?? 0);
            $reminder = (int)($r['reminder_activity'] ?? 30);
            $prevSchedules[] = [
                'machine'      => $r['machine_name'] ?? '-',
                'process'      => $r['process_machine'] ?? '',
                'dept'         => $r['department'] ?? '',
                'line'         => $r['line'] ?? '',
                'op'           => $r['operation_process'] ?? '',
                'point'        => $r['maintenance_point'] ?? '-',
                'unit'         => $r['name_unit'] ?? '',
                'use_date'     => !empty($r['use_date']) ? date('d M Y', strtotime($r['use_date'])) : '-',
                'interval'     => (int)($r['interval_month'] ?? 0),
                'plan_date'     => !empty($r['change_date_plan']) ? date('d M Y', strtotime($r['change_date_plan'])) : '-',
                'plan_date_raw' => !empty($r['change_date_plan']) ? date('Y-m-d', strtotime($r['change_date_plan'])) : '',
                'remaining'    => $days,
                'status_cls'   => remainingClassStr($days, $reminder),
                'maint_status' => $r['maintenance_status'] ?? '',
            ];
        }
    } catch (Exception $e) {
        error_log('[monitor_ajax] schedules_preventive: ' . $e->getMessage());
    }

    $out['schedules']     = $schedules;
    $out['prevSchedules'] = $prevSchedules;
}

// ── PARTS ──────────────────────────────────────────────────────────────
if ($type === 'parts' || $type === 'all') {
    $parts = [];
    try {
        $rows = $GLOBALS['pdo']->query("SELECT * FROM expenses_part ORDER BY item_code ASC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $p) {
            $actual  = (int)$p['actual_stock'];
            $safety  = (int)$p['safety_stock'];
            $eff     = (int)$p['effective_stock'];
            if ($actual === 0)           $status = 'Zero Stock';
            elseif ($actual < $safety)   $status = 'Low Stock';
            elseif ($actual === $safety) $status = 'In Stock';
            else                         $status = 'Over Stock';
            $parts[] = [
                'code'        => $p['item_code'],
                'description' => $p['item_description'] ?? '-',
                'safety'      => $safety,
                'actual'      => $actual,
                'effective'   => $eff,
                'status'      => $status,
            ];
        }
    } catch (Exception $e) {
        error_log('[monitor_ajax] expenses_part: ' . $e->getMessage());
    }
    $out['parts'] = $parts;
}

$out['refreshed_at'] = date('d M Y H:i:s');
echo json_encode($out, JSON_UNESCAPED_UNICODE);
