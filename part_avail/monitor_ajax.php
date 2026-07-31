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

/**
 * Resolve department/line yang masih tersimpan sebagai ID FK lama (angka)
 * menjadi nama string, TANPA menebak nilai numerik yang legal (mis. line
 * "4" di department CONNECTING ROD) sebagai ID lama. Sumber kanonik adalah
 * machine_list; lookup langsung ke plants/line HANYA dipakai sebagai upaya
 * terakhir. Logic ini harus tetap identik dengan dashboard_user.php &
 * monitor.php supaya tampilan department/line konsisten di semua halaman —
 * termasuk saat auto-refresh AJAX setiap 30 detik.
 */
function resolveDeptLineFallback(PDO $pdo, array $rows): array
{
    $needsLookup = array_filter($rows, function ($r) {
        return (isset($r['department']) && $r['department'] !== '' && ctype_digit((string)$r['department']))
            || (isset($r['line']) && $r['line'] !== '' && ctype_digit((string)$r['line']));
    });
    if (empty($needsLookup)) {
        return $rows;
    }

    $stmtPlantById = $pdo->prepare("SELECT plant_name FROM plants WHERE id = ? LIMIT 1");
    $stmtLineById  = $pdo->prepare("SELECT line_name FROM `line` WHERE id = ? LIMIT 1");
    $stmtMlExact = $pdo->prepare(
        "SELECT 1 FROM machine_list
         WHERE machine_name = ? AND op = ? AND department = ? AND `line` = ?
         LIMIT 1"
    );
    $stmtMlByDept = $pdo->prepare(
        "SELECT department, `line`, machine_name, op
         FROM machine_list
         WHERE machine_name = ? AND op = ? AND department = ?
         LIMIT 1"
    );
    $stmtMl = $pdo->prepare(
        "SELECT department, `line`, machine_name, op
         FROM machine_list
         WHERE machine_name = ? AND op = ?
         LIMIT 1"
    );

    foreach ($rows as &$row) {
        $deptIsId = isset($row['department']) && $row['department'] !== '' && ctype_digit((string)$row['department']);
        $lineIsId = isset($row['line']) && $row['line'] !== '' && ctype_digit((string)$row['line']);
        if (!$deptIsId && !$lineIsId) {
            continue;
        }
        $deptWasOriginallyId = $deptIsId;

        // 0) Kalau department (bukan ID) + line saat ini sudah persis cocok
        //    di machine_list, data ini SUDAH BENAR — jangan disentuh.
        if (!$deptIsId) {
            $stmtMlExact->execute([
                $row['machine_name'] ?? '',
                $row['operation_process'] ?? '',
                $row['department'] ?? '',
                $row['line'] ?? '',
            ]);
            $isAlreadyValid = (bool)$stmtMlExact->fetchColumn();
            $stmtMlExact->closeCursor();
            if ($isAlreadyValid) {
                continue;
            }
        }

        // 1) Utamakan pencocokan ke machine_list (sumber kanonik)
        if (!$deptIsId) {
            $stmtMlByDept->execute([$row['machine_name'] ?? '', $row['operation_process'] ?? '', $row['department'] ?? '']);
            $mlRow = $stmtMlByDept->fetch(PDO::FETCH_ASSOC);
            $stmtMlByDept->closeCursor();
        } else {
            $stmtMl->execute([$row['machine_name'] ?? '', $row['operation_process'] ?? '']);
            $mlRow = $stmtMl->fetch(PDO::FETCH_ASSOC);
            $stmtMl->closeCursor();
        }
        if ($mlRow) {
            if ($deptIsId) {
                $row['department'] = $mlRow['department'];
                $deptIsId = false;
            }
            if ($lineIsId) {
                $row['line'] = $mlRow['line'];
                $lineIsId = false;
            }
        }

        // 2) Kalau machine_list tidak ketemu, baru coba lookup ID FK lama
        if ($deptIsId) {
            $stmtPlantById->execute([(int)$row['department']]);
            $plantName = $stmtPlantById->fetchColumn();
            $stmtPlantById->closeCursor();
            if ($plantName !== false && $plantName !== null && $plantName !== '') {
                $row['department'] = $plantName;
            }
        }
        // Fallback ID lama HANYA kalau department juga ASLINYA berupa ID.
        if ($lineIsId && $deptWasOriginallyId) {
            $stmtLineById->execute([(int)$row['line']]);
            $lineName = $stmtLineById->fetchColumn();
            $stmtLineById->closeCursor();
            if ($lineName !== false && $lineName !== null && $lineName !== '') {
                $row['line'] = $lineName;
            }
        }
    }
    unset($row);

    return $rows;
}

$out = [];

// ── TODAY schedules (predictive + preventive) ──────────────────────────
if ($type === 'today' || $type === 'all') {
    $todayPred = [];
    $todayPrev = [];
    try {
        $rows = $GLOBALS['pdo']->query("
            SELECT s.*,
                   s.department AS department,
                   s.line AS line
            FROM schedules s
            WHERE s.change_date_plan = '$todayStr'
            ORDER BY s.remaining_day ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        $rows = resolveDeptLineFallback($GLOBALS['pdo'], $rows);
        foreach ($rows as $r) {
            $todayPred[] = [
                'machine'  => $r['machine_name'] ?? '-',
                'point'    => $r['maintenance_point'] ?? '-',
                'dept'     => $r['department'] ?? '',
                'line'     => $r['line'] ?? '',
                'op'       => $r['operation_process'] ?? '',
                'interval' => (int)($r['interval_month'] ?? 0),
                'qty'      => isset($r['part_qty_needed']) && $r['part_qty_needed'] !== null ? (int)$r['part_qty_needed'] : null,
            ];
        }
    } catch (Exception $e) {
        error_log('[monitor_ajax] today predictive: ' . $e->getMessage());
    }

    try {
        $rows = $GLOBALS['pdo']->query("
            SELECT s.*,
                   s.department AS department,
                   s.line AS line
            FROM schedules_preventive s
            WHERE s.change_date_plan = '$todayStr'
            ORDER BY s.remaining_day ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        $rows = resolveDeptLineFallback($GLOBALS['pdo'], $rows);
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
                   s.department AS department,
                   s.line AS line
            FROM schedules s
            ORDER BY s.remaining_day ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        $rows = resolveDeptLineFallback($GLOBALS['pdo'], $rows);
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
                'part_qty'     => isset($r['part_qty_needed']) && $r['part_qty_needed'] !== null ? (int)$r['part_qty_needed'] : null,
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
                   s.department AS department,
                   s.line AS line
            FROM schedules_preventive s
            ORDER BY s.remaining_day ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        $rows = resolveDeptLineFallback($GLOBALS['pdo'], $rows);
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
