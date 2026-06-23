<?php
// history_checksheet.php
require_once __DIR__ . '/config.php';

$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// ─── AJAX: fetch history data ─────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'history') {
    error_reporting(0);
    @ini_set('display_errors', 0);
    header('Content-Type: application/json');

    $mode   = $_GET['mode']   ?? 'daily';     // daily | monthly
    $value  = $_GET['value']  ?? '';          // YYYY-MM-DD | YYYY-MM
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = max(20, (int)($_GET['limit'] ?? 20)); // Limit dinamis pilihan user
    $offset = ($page - 1) * $limit;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';

    if ($value === '') {
        echo json_encode(['rows' => [], 'total' => 0]);
        exit;
    }

    // Filter tanggal/bulan dasar
    if ($mode === 'daily') {
        $where = "WHERE DATE(s.check_date) = ?";
    } else {
        $where = "WHERE DATE_FORMAT(s.check_date, '%Y-%m') = ?";
    }
    $params = [$value];

    // INTEGRASI SERVER-SIDE SEARCH: Tambah kondisi pencarian global jika parameter search diisi
    if ($search !== '') {
        $where .= " AND (s.machine_name LIKE ? OR s.department LIKE ? OR s.line LIKE ? OR s.op LIKE ? OR s.checker LIKE ?)";
        $searchParam = "%{$search}%";
        $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam, $searchParam]);
    }

    // Hitung total records yang sesuai dengan filter tanggal + keyword search
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM checksheet_submissions s $where");
    $countStmt->execute($params);
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
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    echo json_encode(['rows' => $rows, 'total' => $total, 'limit' => $limit]);
    exit;
}

// ─── AJAX: fetch checker summary ──────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'checker_summary') {
    error_reporting(0);
    @ini_set('display_errors', 0);
    header('Content-Type: application/json');

    $mode  = $_GET['mode']  ?? 'daily';
    $value = $_GET['value'] ?? '';

    if ($value === '') {
        echo json_encode([]);
        exit;
    }

    if ($mode === 'daily') {
        $where = "WHERE DATE(s.check_date) = ?";
    } else {
        $where = "WHERE DATE_FORMAT(s.check_date, '%Y-%m') = ?";
    }

    try {
        $stmt = $pdo->prepare("
        SELECT
            s.checker,
            COUNT(DISTINCT s.id)             AS total_submissions,
            COUNT(DISTINCT s.machine_name)   AS total_machines,
            COUNT(DISTINCT s.department)     AS total_departments,
            SUM(d.result = 'V')              AS ok_count,
            SUM(d.result = 'X')              AS problem_count,
            SUM(d.result = 'R')              AS repair_count,
            SUM(d.result = 'RO')             AS outsider_count,
            SUM(d.result = '-')              AS na_count,
            COUNT(d.id)                      AS total_items,
            GROUP_CONCAT(DISTINCT s.department ORDER BY s.department SEPARATOR ', ') AS departments,
            GROUP_CONCAT(DISTINCT s.machine_name ORDER BY s.machine_name SEPARATOR ', ') AS machines
        FROM checksheet_submissions s
        LEFT JOIN checksheet_submission_details d ON d.submission_id = s.id
        $where
        GROUP BY s.checker
        ORDER BY total_submissions DESC
    ");
        $stmt->execute([$value]);
        $rows = $stmt->fetchAll();

        // Untuk setiap checker, ambil daftar submission lengkap (mesin + waktu)
        $result = [];
        foreach ($rows as $row) {
            $stmtSubs = $pdo->prepare("
            SELECT s.machine_name, s.department, s.line, s.op, s.category_key, s.submitted_at
            FROM checksheet_submissions s
            $where
            AND s.checker = ?
            ORDER BY s.submitted_at ASC
        ");
            $stmtSubs->execute([$value, $row['checker']]);
            $row['submission_list'] = $stmtSubs->fetchAll();
            $result[] = $row;
        }

        echo json_encode($result);
    } catch (\Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ─── AJAX: fetch detail items for a submission ────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'detail') {
    error_reporting(0);
    @ini_set('display_errors', 0);
    header('Content-Type: application/json');
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) {
        echo json_encode([]);
        exit;
    }
    // Ambil interval dari checksheet_items via LEFT JOIN
    $stmt = $pdo->prepare("
        SELECT d.no, d.part, d.standard, d.result, d.note,
               d.item_id,
               COALESCE(ci.`interval`, 'Daily') AS `interval`
        FROM checksheet_submission_details d
        LEFT JOIN checksheet_items ci ON ci.id = d.item_id
        WHERE d.submission_id = ?
        ORDER BY d.no
    ");
    $stmt->execute([$id]);
    $items = $stmt->fetchAll();

    // Ambil check_date, machine_name, dept, line, op, photo_path dari submission ini
    $subStmt = $pdo->prepare("SELECT DATE(check_date) AS check_date, photo_path,
                                      machine_name, department, `line`, op
                               FROM checksheet_submissions WHERE id = ?");
    $subStmt->execute([$id]);
    $sub = $subStmt->fetch();

    // Hitung last_check_date yang benar untuk item periodic (weekly/monthly):
    $periodicItemIds = [];
    foreach ($items as $it) {
        $iv = strtolower(trim($it['interval'] ?? ''));
        if (($iv === 'weekly' || $iv === 'monthly' || $iv === 'montly') && $it['item_id']) {
            $periodicItemIds[] = (int)$it['item_id'];
        }
    }

    $lastCheckDateMap = []; // item_id => 'YYYY-MM-DD'
    if (!empty($periodicItemIds) && $sub) {
        $inList = implode(',', $periodicItemIds);
        $machineName = $sub['machine_name'] ?? '';

        if ($machineName !== '') {
            $stmtH = $pdo->prepare("
                SELECT d2.item_id, DATE(s2.check_date) AS last_date
                FROM checksheet_submission_details d2
                JOIN checksheet_submissions s2 ON s2.id = d2.submission_id
                WHERE d2.item_id IN ({$inList})
                  AND s2.machine_name = ?
                  AND d2.result != '-'
                ORDER BY s2.check_date ASC
            ");
            $stmtH->execute([$machineName]);
        } else {
            $stmtH = $pdo->prepare("
                SELECT d2.item_id, DATE(s2.check_date) AS last_date
                FROM checksheet_submission_details d2
                JOIN checksheet_submissions s2 ON s2.id = d2.submission_id
                WHERE d2.item_id IN ({$inList})
                  AND s2.department = ? AND s2.`line` = ? AND s2.op = ?
                  AND d2.result != '-'
                ORDER BY s2.check_date ASC
            ");
            $stmtH->execute([$sub['department'], $sub['line'], $sub['op']]);
        }

        $allDatesH = [];
        foreach ($stmtH->fetchAll() as $r) {
            $allDatesH[(int)$r['item_id']][] = $r['last_date']; // ASC: oldest first
        }

        // Gunakan check_date submission ini sebagai "today" referensi untuk history
        $refDate = new DateTime($sub['check_date']);

        foreach ($allDatesH as $itemId => $dates) {
            // Cari interval untuk item ini
            $iv = '';
            foreach ($items as $it) {
                if ((int)$it['item_id'] === $itemId) {
                    $iv = strtolower(trim($it['interval'] ?? ''));
                    break;
                }
            }

            // Cari tanggal tertua yang periodenya mencakup check_date submission ini
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
                if ($refDate < $next) {
                    $activePeriodStart = $dt;
                } else {
                    if ($activePeriodStart !== null) break;
                    $activePeriodStart = $dt;
                    break;
                }
            }
            if ($activePeriodStart !== null) {
                $lastCheckDateMap[$itemId] = $activePeriodStart->format('Y-m-d');
            }
        }
    }

    // Tambahkan last_check_date ke setiap item
    foreach ($items as &$it) {
        $iv = strtolower(trim($it['interval'] ?? ''));
        if (($iv === 'weekly' || $iv === 'monthly' || $iv === 'montly') && $it['item_id']) {
            $it['last_check_date'] = $lastCheckDateMap[(int)$it['item_id']] ?? null;
        } else {
            $it['last_check_date'] = null;
        }
        unset($it['item_id']); // tidak perlu dikirim ke frontend
    }
    unset($it);

    echo json_encode([
        'items'      => $items,
        'check_date' => $sub['check_date'] ?? null,
        'photo_path' => $sub['photo_path'] ?? null,
    ]);
    exit;
}

// ─── AJAX: completion rate per department / line ──────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'completion_rate') {
    error_reporting(0);
    @ini_set('display_errors', 0);
    header('Content-Type: application/json');

    $mode  = $_GET['mode']  ?? 'daily';
    $value = $_GET['value'] ?? '';

    if ($value === '') {
        echo json_encode(['departments' => []]);
        exit;
    }

    if ($mode === 'daily') {
        $whereSub = "WHERE DATE(s.check_date) = ?";
    } else {
        $whereSub = "WHERE DATE_FORMAT(s.check_date, '%Y-%m') = ?";
    }

    try {
        // 1. Ambil semua kombinasi dept+line yang ada di machine_list
        $stmtML = $pdo->query("
            SELECT department, `line`, COUNT(DISTINCT machine_name) AS total_machines
            FROM machine_list
            GROUP BY department, `line`
            ORDER BY department, `line`
        ");
        $allLines = $stmtML->fetchAll();

        // 2. Ambil submission yang sudah diisi sesuai filter tanggal
        $stmtFilled = $pdo->prepare("
            SELECT s.department, s.line,
                   COUNT(DISTINCT s.machine_name) AS filled_machines,
                   GROUP_CONCAT(DISTINCT s.machine_name ORDER BY s.machine_name SEPARATOR '||') AS filled_list
            FROM checksheet_submissions s
            {$whereSub}
            GROUP BY s.department, s.line
        ");
        $stmtFilled->execute([$value]);
        $filledMap = [];
        foreach ($stmtFilled->fetchAll() as $r) {
            $filledMap[$r['department'] . '|||' . $r['line']] = $r;
        }

        // 3. Gabungkan: per dept kumpulkan semua line-nya
        $depts = [];
        foreach ($allLines as $row) {
            $dept = $row['department'];
            $line = $row['line'];
            $total = (int)$row['total_machines'];
            $key   = $dept . '|||' . $line;
            $filled = (int)($filledMap[$key]['filled_machines'] ?? 0);
            $filledList = isset($filledMap[$key]) ? explode('||', $filledMap[$key]['filled_list']) : [];

            // Ambil semua nama mesin + op di line ini
            $stmtMachines = $pdo->prepare("SELECT DISTINCT machine_name, op FROM machine_list WHERE department = ? AND `line` = ? ORDER BY CASE WHEN op = '' OR op IS NULL THEN 1 ELSE 0 END, CAST(REGEXP_REPLACE(op, '[^0-9]', '') AS UNSIGNED), op, machine_name");
            $stmtMachines->execute([$dept, $line]);
            $allMachines = $stmtMachines->fetchAll();

            if (!isset($depts[$dept])) {
                $depts[$dept] = ['name' => $dept, 'lines' => [], 'total' => 0, 'filled' => 0];
            }
            $depts[$dept]['lines'][] = [
                'line'         => $line,
                'total'        => $total,
                'filled'       => $filled,
                'pct'          => $total > 0 ? round($filled / $total * 100) : 0,
                'all_machines' => $allMachines,
                'filled_list'  => $filledList,
            ];
            $depts[$dept]['total']  += $total;
            $depts[$dept]['filled'] += $filled;
        }

        // Hitung pct dept level
        foreach ($depts as &$d) {
            $d['pct'] = $d['total'] > 0 ? round($d['filled'] / $d['total'] * 100) : 0;
        }

        echo json_encode(['departments' => array_values($depts)]);
    } catch (\Exception $e) {
        echo json_encode(['error' => $e->getMessage(), 'departments' => []]);
    }
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
            background: linear-gradient(135deg, #e36414, #c4550f);
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

        /* ── Container Tabel: flex-1 agar isi sisa tinggi, scroll internal ── */
        .table-scroll-container {
            flex: 1 1 0;
            min-height: 0;
            overflow-y: auto;
        }

        /* ── Table ── */
        .hist-table thead th {
            background: linear-gradient(135deg, #e36414, #c4550f);
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
            border-color: #e36414;
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

        .modal-table-th {
            background: #f8fafc;
            font-size: .68rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: .04em;
            padding: 9px 12px;
            border-bottom: 2px solid #e2e8f0;
            position: sticky;
            top: 0;
            z-index: 10;
            white-space: nowrap;
        }

        .progress-bar-wrap {
            background: #f1f5f9;
            border-radius: 99px;
            height: 7px;
            overflow: hidden;
            min-width: 80px;
        }

        .progress-bar-fill {
            height: 100%;
            border-radius: 99px;
            transition: width .4s ease;
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

        /* [FIX-SEPARATOR] Pemisah antar baris checker di modal "Ringkasan Per
           Checker" — supaya saat ada 2+ user yang mengisi checksheet, jelas
           batas antara data user 1, user 2, dst. Tabel ini (#checker-summary-table)
           dirender via JS dengan style inline, bukan class .modal-table, jadi
           border antar baris diberi lewat class .checker-row di sini. */
        .checker-row td {
            border-bottom: 1px solid #e2e8f0;
        }

        .checker-row:last-child td {
            border-bottom: none;
        }

        /* ── Foto di modal detail ── */
        #modal-photo-section {
            display: none;
            padding: 10px 16px 12px;
            border-top: 1px solid #f1f5f9;
            background: #fafbfc;
            flex-shrink: 0;
        }

        #modal-photo-section .photo-label {
            font-size: .67rem;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: .06em;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        #modal-photo-section .photo-label i {
            color: #e36414;
        }

        #modal-photo-thumb-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        #modal-photo-thumb {
            width: 72px;
            height: 54px;
            object-fit: cover;
            border-radius: 8px;
            border: 2px solid #e2e8f0;
            cursor: pointer;
            transition: opacity .15s, border-color .15s;
            flex-shrink: 0;
        }

        #modal-photo-thumb:hover {
            opacity: .85;
            border-color: #e36414;
        }

        #modal-photo-open-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 8px;
            background: #1e293b;
            color: #fff;
            font-size: .7rem;
            font-weight: 700;
            border: none;
            cursor: pointer;
            text-decoration: none;
            transition: background .15s;
        }

        #modal-photo-open-btn:hover {
            background: #0f172a;
        }

        /* Large photo lightbox */
        #photo-lightbox {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 600;
            background: rgba(15, 23, 42, .88);
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(4px);
        }

        #photo-lightbox.open {
            display: flex;
        }

        #photo-lightbox img {
            max-width: min(680px, calc(100vw - 32px));
            max-height: calc(100vh - 48px);
            border-radius: 14px;
            box-shadow: 0 24px 60px rgba(0, 0, 0, .45);
            object-fit: contain;
        }

        #photo-lightbox-close {
            position: absolute;
            top: 16px;
            right: 16px;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: rgba(255, 255, 255, .15);
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: .85rem;
            transition: background .15s;
        }

        #photo-lightbox-close:hover {
            background: rgba(255, 255, 255, .28);
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
            border-color: #e36414;
            color: #e36414;
        }

        .page-btn.active {
            background: #e36414;
            border-color: #e36414;
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

        /* ── Completion Rate Tab ── */
        .tab-btn {
            padding: 7px 16px;
            font-size: .75rem;
            font-weight: 700;
            border-radius: 9px;
            cursor: pointer;
            transition: all .15s;
            border: none;
            background: transparent;
            color: #64748b;
        }

        .tab-btn.active {
            background: #e36414;
            color: #fff;
            box-shadow: 0 2px 8px rgba(244, 63, 94, .25);
        }

        .tab-btn:not(.active):hover {
            background: #f1f5f9;
            color: #334155;
        }

        .dept-card {
            background: #fff;
            border: 1.5px solid #e2e8f0;
            border-radius: 14px;
            overflow: hidden;
            transition: box-shadow .2s;
        }

        .dept-card:hover {
            box-shadow: 0 4px 16px rgba(0, 0, 0, .07);
        }

        .dept-header {
            padding: 14px 18px;
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            user-select: none;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }

        .dept-header:hover {
            background: #f1f5f9;
        }

        .dept-body {
            display: none;
            padding: 12px 16px;
        }

        .dept-body.open {
            display: block;
        }

        .pct-bar-wrap {
            flex: 1;
            height: 8px;
            background: #e2e8f0;
            border-radius: 99px;
            overflow: hidden;
        }

        .pct-bar-fill {
            height: 100%;
            border-radius: 99px;
            transition: width .4s ease;
        }

        .line-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 10px;
            border-radius: 9px;
            margin-bottom: 4px;
            background: #f8fafc;
            border: 1px solid #f1f5f9;
        }

        .line-row:hover {
            background: #f1f5f9;
        }

        .machine-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin-top: 6px;
            margin-left: 4px;
        }

        .machine-chip {
            font-size: .62rem;
            font-weight: 600;
            padding: 2px 7px;
            border-radius: 5px;
            white-space: nowrap;
        }

        .machine-chip.filled {
            background: #dcfce7;
            color: #15803d;
        }

        .machine-chip.unfilled {
            background: #fee2e2;
            color: #dc2626;
        }
    </style>
</head>

<body>

    <aside id="sidebar" class="collapsed">
        <div class="brand">
            <div class="brand-icon-wrap">
                <div class="w-8 h-8 rounded-lg bg-[#e36414] flex items-center justify-center flex-shrink-0">
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
                <div class="w-7 h-7 rounded-lg bg-[#fdf4ee] flex items-center justify-center">
                    <i class="fas fa-history text-[#e36414] text-xs"></i>
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
                                class="px-4 py-2 text-xs font-bold transition-all bg-[#c4550f] text-white">
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
                        class="px-5 py-2.5 rounded-xl bg-[#c4550f] hover:bg-[#a8420b] text-white text-sm font-bold transition-all flex items-center gap-2 shadow-sm">
                        <i class="fas fa-search text-xs"></i> Cari
                    </button>

                    <div class="flex-1"></div>

                    <div class="flex gap-2">
                        <button onclick="openCheckerSummary()"
                            class="px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold transition-all flex items-center gap-2 shadow-sm">
                            <i class="fas fa-user-check"></i> View Checker
                        </button>
                        <button onclick="exportData()"
                            class="px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold transition-all flex items-center gap-2 shadow-sm">
                            <i class="fas fa-file-excel"></i> Export Excel
                        </button>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden flex-1 flex flex-col min-h-0">
                <div class="px-4 py-2.5 border-b border-slate-100 flex items-center gap-2 flex-shrink-0">
                    <div class="flex gap-1 bg-slate-100 rounded-xl p-1">
                        <button class="tab-btn active" id="tab-btn-history" onclick="switchTab('history')">
                            <i class="fas fa-table mr-1.5"></i>Riwayat Submission
                        </button>
                        <button class="tab-btn" id="tab-btn-completion" onclick="switchTab('completion')">
                            <i class="fas fa-chart-pie mr-1.5"></i>Completion Rate
                        </button>
                    </div>

                    <div class="relative flex-1 tab-history-only" style="max-width:280px;">
                        <i class="fas fa-search absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none" style="font-size:10px;"></i>
                        <input type="text" id="inp-search"
                            placeholder="Cari mesin, dept, line, checker…"
                            onkeydown="if(event.key === 'Enter') handleSearchClick()"
                            class="form-field pl-7 pr-7 text-xs"
                            style="height:32px;padding-top:0;padding-bottom:0;">
                        <button id="btn-clear-search" onclick="clearSearch()" style="display:none;"
                            class="absolute right-2 top-1/2 -translate-y-1/2 w-4 h-4 rounded-full bg-slate-200 hover:bg-slate-300 flex items-center justify-center transition-all">
                            <i class="fas fa-times text-slate-500" style="font-size:8px;"></i>
                        </button>
                    </div>

                    <div class="flex items-center gap-1.5 tab-history-only ml-2">
                        <span class="text-[11px] text-slate-400 font-medium">Show:</span>
                        <select id="inp-limit" onchange="changeLimit()" class="form-field text-xs cursor-pointer bg-slate-50" style="height:32px; padding-top:0; padding-bottom:0; min-width:70px;">
                            <option value="20" selected>20</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                            <option value="200">200</option>
                            <option value="500">500</option>
                        </select>
                    </div>

                    <div class="flex-1 tab-history-only"></div>
                    <span id="result-label" class="text-[11px] text-slate-400 font-medium tab-history-only"></span>
                    <span id="search-label" style="display:none;" class="text-[11px] font-bold px-2 py-0.5 rounded-full bg-[#fde8d5] text-[#e36414] tab-history-only"></span>
                    <span id="showing-label" class="text-[11px] text-slate-400 font-medium flex-shrink-0 tab-history-only"></span>
                </div>

                <div id="tab-history" class="flex flex-col flex-1 min-h-0">
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

                <div id="tab-completion" class="flex flex-col flex-1 min-h-0" style="display:none;">
                    <div class="flex-1 overflow-y-auto p-5" id="completion-body">
                        <div id="completion-empty" class="flex flex-col items-center justify-center py-16 text-slate-400">
                            <i class="fas fa-chart-pie text-5xl mb-3 opacity-30"></i>
                            <p class="font-bold text-sm">Pilih tanggal / bulan lalu klik Cari</p>
                            <p class="text-xs mt-1">Data completion rate akan tampil di sini</p>
                        </div>
                        <div id="completion-loading" style="display:none;" class="space-y-3">
                            <?php for ($i = 0; $i < 4; $i++): ?>
                                <div class="skeleton h-14 rounded-xl w-full"></div>
                            <?php endfor; ?>
                        </div>
                        <div id="completion-content" style="display:none;">
                            <div id="completion-summary" class="mb-5 p-4 rounded-xl bg-slate-50 border border-slate-200 flex flex-wrap gap-4 items-center"></div>
                            <div id="completion-dept-list" class="space-y-3"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="checker-modal-overlay" onclick="closeCheckerModal(event)"
        style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);backdrop-filter:blur(3px);z-index:200;align-items:center;justify-content:center;">
        <div id="checker-modal-box"
            style="background:#fff;border-radius:18px;width:92%;max-width:860px;max-height:88vh;display:flex;flex-direction:column;box-shadow:0 24px 60px rgba(0,0,0,.25);animation:popIn .25s cubic-bezier(.34,1.56,.64,1);">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between flex-shrink-0">
                <div>
                    <div class="text-sm font-bold text-slate-800 flex items-center gap-2">
                        <i class="fas fa-user-check text-indigo-500"></i>
                        Ringkasan Per Checker
                    </div>
                    <div class="text-[11px] text-slate-400 font-medium mt-0.5" id="checker-modal-subtitle"></div>
                </div>
                <button onclick="closeCheckerModal()" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 flex items-center justify-center transition-all">
                    <i class="fas fa-times text-slate-500 text-xs"></i>
                </button>
            </div>

            <div class="px-5 py-3 border-b border-slate-100 flex-shrink-0">
                <div class="relative">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs pointer-events-none"></i>
                    <input type="text" id="checker-search" placeholder="Cari nama checker…"
                        oninput="filterCheckerTable()"
                        class="form-field pl-8 w-full" style="max-width:100%;">
                </div>
            </div>

            <div class="overflow-auto flex-1" style="min-height:0;">
                <table class="w-full" id="checker-summary-table" style="display:none;">
                    <thead>
                        <tr>
                            <th class="modal-table-th text-center w-8">No</th>
                            <th class="modal-table-th">Checker / Operator</th>
                            <th class="modal-table-th text-center">Checksheet Diisi</th>
                            <th class="modal-table-th text-center">Mesin Dicek</th>
                            <th class="modal-table-th text-center">Dept</th>
                            <th class="modal-table-th">Detail Mesin yang Dicek</th>
                            <th class="modal-table-th text-center">Hasil (V/X/R/RO/-)</th>
                            <th class="modal-table-th text-center">Completion</th>
                        </tr>
                    </thead>
                    <tbody id="checker-summary-tbody"></tbody>
                </table>

                <div id="checker-modal-loading" style="display:none;" class="p-6 space-y-3">
                    <?php for ($i = 0; $i < 5; $i++): ?>
                        <div class="flex gap-3 items-center">
                            <div class="skeleton w-6 h-5"></div>
                            <div class="skeleton w-32 h-5"></div>
                            <div class="skeleton w-16 h-5"></div>
                            <div class="skeleton w-16 h-5"></div>
                            <div class="skeleton w-20 h-5"></div>
                            <div class="skeleton flex-1 h-5"></div>
                            <div class="skeleton w-20 h-5"></div>
                        </div>
                    <?php endfor; ?>
                </div>

                <div id="checker-modal-empty" style="display:none;" class="flex flex-col items-center justify-center py-14 text-slate-400">
                    <i class="fas fa-user-slash text-4xl mb-3 opacity-30"></i>
                    <p class="font-bold text-sm">Tidak ada data checker</p>
                    <p class="text-xs mt-1">Pilih periode yang memiliki data</p>
                </div>
            </div>

            <div class="px-6 py-3.5 border-t border-slate-100 bg-slate-50 rounded-b-2xl flex items-center justify-between flex-shrink-0">
                <span id="checker-modal-summary" class="text-[11px] text-slate-500 font-medium"></span>
                <button onclick="closeCheckerModal()" class="px-4 py-2 rounded-xl bg-slate-200 hover:bg-slate-300 text-slate-700 text-xs font-bold transition-all">
                    Tutup
                </button>
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

            <div id="modal-photo-section">
                <div class="photo-label"><i class="fas fa-camera"></i> Foto Kondisi Mesin</div>
                <div id="modal-photo-thumb-wrap">
                    <img id="modal-photo-thumb" src="" alt="Foto Mesin" onclick="openPhotoLightbox()">
                    <div>
                        <div style="font-size:.7rem;color:#475569;font-weight:600;margin-bottom:4px;">Klik gambar untuk memperbesar</div>
                        <a id="modal-photo-open-btn" href="#" target="_blank">
                            <i class="fas fa-external-link-alt"></i> Open Photo
                        </a>
                    </div>
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

    <div id="photo-lightbox" onclick="if(event.target===this)closePhotoLightbox()">
        <button id="photo-lightbox-close" onclick="closePhotoLightbox()"><i class="fas fa-times"></i></button>
        <img id="photo-lightbox-img" src="" alt="Foto Mesin">
    </div>

    <div id="toast"></div>

    <script>
        let currentMode = 'daily';
        let currentPage = 1;
        let totalRecords = 0;
        let limitPerPage = 20;
        let searchTimeout = null;

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

            // MODIFIKASI: Input real-time search listener dengan Debounce agar tidak lag
            document.getElementById('inp-search').addEventListener('input', function() {
                clearTimeout(searchTimeout);
                const query = this.value.trim();
                document.getElementById('btn-clear-search').style.display = query ? 'flex' : 'none';

                searchTimeout = setTimeout(() => {
                    loadHistory(1); // Reset kembali ke halaman 1 saat mengetik kata kunci
                }, 400);
            });
        });

        // ── Mode toggle ───────────────────────────────────────────────────────────
        function setMode(mode) {
            currentMode = mode;
            const daily = document.getElementById('btn-mode-daily');
            const month = document.getElementById('btn-mode-monthly');
            const inp = document.getElementById('inp-date');
            const label = document.getElementById('picker-label');

            if (mode === 'daily') {
                daily.className = 'px-4 py-2 text-xs font-bold transition-all bg-[#c4550f] text-white';
                month.className = 'px-4 py-2 text-xs font-bold transition-all bg-white text-slate-500 hover:bg-slate-50';
                inp.type = 'date';
                label.textContent = 'Tanggal';
                inp.value = new Date().toISOString().split('T')[0];
            } else {
                daily.className = 'px-4 py-2 text-xs font-bold transition-all bg-white text-slate-500 hover:bg-slate-50';
                month.className = 'px-4 py-2 text-xs font-bold transition-all bg-[#c4550f] text-white';
                inp.type = 'month';
                label.textContent = 'Bulan';
                inp.value = new Date().toISOString().slice(0, 7);
            }
        }

        // ── Change Limit ──────────────────────────────────────────────────────────
        function changeLimit() {
            limitPerPage = parseInt(document.getElementById('inp-limit').value) || 20;
            loadHistory(1);
        }

        function handleSearchClick() {
            loadHistory(1);
        }

        // ── Load history ──────────────────────────────────────────────────────────
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

            // MODIFIKASI: Menambahkan parameter &search dan &limit ke endpoint AJAX backend
            fetch(`history_checksheet.php?ajax=history&mode=${currentMode}&value=${encodeURIComponent(value)}&page=${page}&limit=${limitPerPage}&search=${encodeURIComponent(searchQuery)}`)
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
                        document.getElementById('result-label').textContent = `0 submission ditemukan`;
                        return;
                    }

                    renderTable(data.rows, page);
                    renderPagination(data.total, page, limitPerPage);
                })
                .catch(() => {
                    document.getElementById('hist-loading').style.display = 'none';
                    document.getElementById('hist-empty').style.display = 'flex';
                    showToast('Gagal memuat data.', 'error');
                });

            loadCompletionRate();
        }

        // ── Render table ──────────────────────────────────────────────────────────
        function renderTable(rows, page) {
            const tbody = document.getElementById('hist-tbody');
            tbody.innerHTML = '';
            const searchQuery = document.getElementById('inp-search').value.trim();
            const searchLabel = document.getElementById('search-label');

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
                        class="w-8 h-8 rounded-lg bg-slate-100 hover:bg-[#fde8d5] hover:text-[#e36414] text-slate-500 transition-all inline-flex items-center justify-center">
                        <i class="fas fa-eye text-xs"></i>
                    </button>
                </td>`;
                tbody.appendChild(tr);
            });

            document.getElementById('hist-table').style.display = 'table';
            document.getElementById('result-label').textContent = `Total ${totalRecords} submission`;

            if (searchQuery) {
                searchLabel.style.display = 'inline-flex';
                searchLabel.textContent = `Filtered`;
            } else {
                searchLabel.style.display = 'none';
            }
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

            // Logic windowing agar tombol page tidak overflow jika page sangat banyak (misal totalPages > 20)
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

        // ── Modal detail ──
        function openDetail(id, dept, line, op, machine, checker, date) {
            document.getElementById('modal-overlay').classList.add('open');
            document.getElementById('modal-title').textContent = `Detail Submission #${id}`;
            document.getElementById('modal-subtitle').textContent = `${dept} — ${line} (OP: ${op || '-'}) | Mesin: ${machine} | Checker: ${checker} | ${date}`;

            document.getElementById('modal-tbody').innerHTML = '';
            document.getElementById('modal-loading').style.display = 'block';
            document.getElementById('modal-summary').textContent = '';
            document.getElementById('modal-photo-section').style.display = 'none';

            fetch(`history_checksheet.php?ajax=detail&id=${id}`)
                .then(r => r.json())
                .then(data => {
                    document.getElementById('modal-loading').style.display = 'none';
                    const tbody = document.getElementById('modal-tbody');
                    const items = Array.isArray(data) ? data : (data.items || []);
                    const photoPath = Array.isArray(data) ? null : (data.photo_path || null);

                    const resultMap = {
                        'V': '<span class="badge badge-v">V — OK</span>',
                        'X': '<span class="badge badge-x">X — Problem</span>',
                        'R': '<span class="badge badge-r">R — Repair</span>',
                        'RO': '<span class="badge badge-ro">RO — Outsider</span>',
                        '-': '<span class="badge badge-na">— N/A</span>',
                    };
                    const fmtDate = d => d.toLocaleDateString('id-ID', {
                        day: '2-digit',
                        month: 'short',
                        year: 'numeric'
                    });

                    items.forEach(item => {
                        const iv = (item.interval || 'Daily').trim();
                        const isWeekly = iv === 'Weekly';
                        const isMonthly = iv === 'Monthly' || iv === 'Montly';
                        const isPeriodic = isWeekly || isMonthly;

                        const ivBadge = isWeekly ?
                            '<span style="background:#fef9c3;color:#92400e;font-size:.6rem;font-weight:700;padding:1px 5px;border-radius:4px;margin-left:4px;">Weekly</span>' :
                            isMonthly ?
                            '<span style="background:#ede9fe;color:#5b21b6;font-size:.6rem;font-weight:700;padding:1px 5px;border-radius:4px;margin-left:4px;">Monthly</span>' :
                            '';

                        let resultCell;
                        if (isPeriodic && item.result !== '-' && item.last_check_date) {
                            const lastDate = new Date(item.last_check_date + 'T00:00:00');
                            const nextDue = new Date(lastDate);
                            if (isWeekly) nextDue.setDate(nextDue.getDate() + 7);
                            else nextDue.setMonth(nextDue.getMonth() + 1);
                            resultCell = `<div style="font-size:.68rem;line-height:1.9;">
                                <div><span style="color:#64748b;font-weight:600;">Last:</span> <span style="color:#047857;font-weight:700;">${fmtDate(lastDate)}</span></div>
                                <div><span style="color:#64748b;font-weight:600;">Next:</span> <span style="color:#1d4ed8;font-weight:700;">${fmtDate(nextDue)}</span></div>
                            </div>`;
                        } else {
                            resultCell = resultMap[item.result] || item.result;
                        }

                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                        <td class="text-center text-slate-400 font-bold text-xs">${item.no}</td>
                        <td class="font-medium text-slate-700">${item.part}${ivBadge}</td>
                        <td class="text-slate-500 text-xs">${item.standard}</td>
                        <td class="text-center">${resultCell}</td>
                        <td class="text-xs">${item.note ? `<span style="color:#b45309;font-style:italic;">${item.note}</span>` : '<span class="text-slate-300">—</span>'}</td>`;
                        tbody.appendChild(tr);
                    });

                    const ok = items.filter(i => {
                        const iv = (i.interval || '').trim();
                        return iv !== 'Weekly' && iv !== 'Monthly' && iv !== 'Montly' && i.result === 'V';
                    }).length;
                    const pr = items.filter(i => i.result === 'X').length;
                    document.getElementById('modal-summary').textContent =
                        `${items.length} item total — ${ok} OK, ${pr} Problem`;

                    if (photoPath) {
                        const thumb = document.getElementById('modal-photo-thumb');
                        const openBtn = document.getElementById('modal-photo-open-btn');
                        thumb.src = photoPath;
                        document.getElementById('photo-lightbox-img').src = photoPath;
                        openBtn.href = photoPath;
                        document.getElementById('modal-photo-section').style.display = 'block';
                    }
                });
        }

        function closeModal(e) {
            if (!e || e.target === document.getElementById('modal-overlay')) {
                document.getElementById('modal-overlay').classList.remove('open');
            }
        }

        function openPhotoLightbox() {
            document.getElementById('photo-lightbox').classList.add('open');
        }

        function closePhotoLightbox() {
            document.getElementById('photo-lightbox').classList.remove('open');
        }

        // ── Export ──
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

        // ── Helpers ──
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

        // ── Checker Summary Modal ──
        function openCheckerSummary() {
            const value = document.getElementById('inp-date').value;
            if (!value) {
                showToast('Pilih tanggal / bulan terlebih dahulu, lalu klik Cari.', 'error');
                return;
            }

            const overlay = document.getElementById('checker-modal-overlay');
            overlay.style.display = 'flex';
            document.getElementById('checker-summary-tbody').innerHTML = '';
            document.getElementById('checker-summary-table').style.display = 'none';
            document.getElementById('checker-modal-loading').style.display = 'block';
            document.getElementById('checker-modal-empty').style.display = 'none';
            document.getElementById('checker-modal-summary').textContent = '';
            document.getElementById('checker-search').value = '';

            const modeLabel = currentMode === 'daily' ? `Tanggal: ${value}` : `Bulan: ${value}`;
            document.getElementById('checker-modal-subtitle').textContent = modeLabel;

            fetch(`history_checksheet.php?ajax=checker_summary&mode=${currentMode}&value=${encodeURIComponent(value)}`)
                .then(r => r.json())
                .then(rows => {
                    document.getElementById('checker-modal-loading').style.display = 'none';

                    if (!Array.isArray(rows) || rows.length === 0) {
                        document.getElementById('checker-modal-empty').style.display = 'flex';
                        return;
                    }

                    renderCheckerTable(rows);
                })
                .catch(() => {
                    document.getElementById('checker-modal-loading').style.display = 'none';
                    document.getElementById('checker-modal-empty').style.display = 'flex';
                    showToast('Gagal memuat ringkasan checker.', 'error');
                });
        }

        function renderCheckerTable(rows) {
            const tbody = document.getElementById('checker-summary-tbody');
            tbody.innerHTML = '';

            rows.forEach((row, idx) => {
                try {
                    const total = parseInt(row.total_items) || 1;
                    const ok = parseInt(row.ok_count) || 0;
                    const pct = Math.round((ok / total) * 100);

                    let barColor = '#22c55e';
                    if (pct < 60) barColor = '#ef4444';
                    else if (pct < 85) barColor = '#f59e0b';

                    const subs = row.submission_list || [];
                    const machineListHtml = subs.length > 0 ?
                        subs.map((s, i) => {
                            const jam = s.submitted_at ? s.submitted_at.slice(11, 16) : '--:--';
                            const catColors = {
                                'MC': '#dbeafe|#1d4ed8',
                                'SPM': '#ede9fe|#7c3aed',
                                'ASSEMBLING': '#ccfbf1|#0f766e',
                                'PAINTING': '#fce7f3|#be185d',
                                'TEST_RUNNING': '#ffedd5|#c2410c',
                                'PACKING': '#dcfce7|#15803d',
                                'BOILER': '#fee2e2|#dc2626',
                                'KOMPRESSOR': '#cffafe|#0e7490',
                            };
                            const [bg, fc] = (catColors[s.category_key] || '#f1f5f9|#64748b').split('|');
                            return `<div style="display:flex;align-items:center;gap:5px;margin-bottom:3px;">
                            <span style="font-size:.6rem;font-weight:700;padding:1px 5px;border-radius:4px;background:${bg};color:${fc};flex-shrink:0;">${s.category_key || '-'}</span>
                            <span style="font-size:.72rem;font-weight:600;color:#1e293b;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:180px;" title="${s.machine_name}">${s.machine_name}</span>
                            <span style="font-size:.62rem;color:#94a3b8;flex-shrink:0;">${jam}</span>
                        </div>`;
                        }).join('') :
                        '<span style="font-size:.7rem;color:#94a3b8;">—</span>';

                    const tr = document.createElement('tr');
                    tr.className = 'fade-in checker-row';
                    tr.style.animationDelay = `${idx * 20}ms`;
                    tr.innerHTML = `
                    <td style="padding:10px 12px;text-align:center;font-size:.72rem;color:#94a3b8;font-weight:700;">${idx + 1}</td>
                    <td style="padding:10px 12px;">
                        <div style="display:flex;align-items:center;gap:9px;">
                            <div style="width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#6366f1,#818cf8);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <span style="color:#fff;font-size:.65rem;font-weight:800;">${(row.checker || '?').charAt(0).toUpperCase()}</span>
                            </div>
                            <div>
                                <div style="font-size:.82rem;font-weight:700;color:#1e293b;">${row.checker}</div>
                                <div style="font-size:.65rem;color:#94a3b8;margin-top:1px;">${row.departments || '—'}</div>
                            </div>
                        </div>
                    </td>
                    <td style="padding:10px 12px;text-align:center;">
                        <div style="display:flex;flex-direction:column;align-items:center;gap:2px;">
                            <span style="display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:34px;border-radius:10px;background:#eff6ff;color:#2563eb;font-size:.9rem;font-weight:800;">${row.total_submissions}</span>
                            <span style="font-size:.6rem;color:#94a3b8;font-weight:600;">checksheet</span>
                        </div>
                    </td>
                    <td style="padding:10px 12px;text-align:center;">
                        <div style="display:flex;flex-direction:column;align-items:center;gap:2px;">
                            <span style="display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:34px;border-radius:10px;background:#f0fdf4;color:#16a34a;font-size:.9rem;font-weight:800;">${row.total_machines}</span>
                            <span style="font-size:.6rem;color:#94a3b8;font-weight:600;">mesin</span>
                        </div>
                    </td>
                    <td style="padding:10px 12px;text-align:center;">
                        <span style="display:inline-flex;align-items:center;justify-content:center;min-width:28px;height:28px;border-radius:8px;background:#faf5ff;color:#7c3aed;font-size:.8rem;font-weight:700;">${row.total_departments}</span>
                    </td>
                    <td style="padding:10px 14px;min-width:220px;max-width:280px;">
                        ${machineListHtml}
                    </td>
                    <td style="padding:10px 12px;text-align:center;">
                        <div style="display:flex;align-items:center;justify-content:center;gap:4px;flex-wrap:wrap;">
                            <span class="pill pill-v" title="OK (V)">${parseInt(row.ok_count)||0}</span>
                            <span class="pill pill-x" title="Problem (X)">${parseInt(row.problem_count)||0}</span>
                            <span class="pill pill-r" title="Repair (R)">${parseInt(row.repair_count)||0}</span>
                            <span class="pill pill-ro" title="Outsider (RO)">${parseInt(row.outsider_count)||0}</span>
                            <span class="pill pill-na" title="N/A">${parseInt(row.na_count)||0}</span>
                        </div>
                    </td>
                    <td style="padding:10px 16px;">
                        <div style="display:flex;align-items:center;gap:8px;">
                            <div class="progress-bar-wrap" style="flex:1;">
                                <div class="progress-bar-fill" style="width:${pct}%;background:${barColor};"></div>
                            </div>
                            <span style="font-size:.72rem;font-weight:800;color:${barColor};min-width:34px;text-align:right;">${pct}%</span>
                        </div>
                    </td>`;
                    tbody.appendChild(tr);
                } catch (e) {
                    console.error('renderCheckerTable row error:', e, row);
                }
            });

            document.getElementById('checker-summary-table').style.display = 'table';

            const totalCheckers = rows.length;
            const totalSheets = rows.reduce((s, r) => s + parseInt(r.total_submissions), 0);
            const totalMachines = rows.reduce((s, r) => s + parseInt(r.total_machines), 0);
            document.getElementById('checker-modal-summary').textContent =
                `${totalCheckers} checker — ${totalSheets} total submission — ${totalMachines} total mesin dicek`;
        }

        function filterCheckerTable() {
            const q = document.getElementById('checker-search').value.trim().toLowerCase();
            document.querySelectorAll('.checker-row').forEach(tr => {
                tr.style.display = !q || tr.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        }

        // MODIFIKASI: Mengosongkan sisa fungsi filter lama yang membuat konflik pencarian client-side
        function filterTable() {
            // Logika pencarian dialihkan sepenuhnya ke sisi server (Server-Side Search via loadHistory)
        }

        function clearSearch() {
            document.getElementById('inp-search').value = '';
            document.getElementById('btn-clear-search').style.display = 'none';
            loadHistory(1);
        }

        // ── Tab switching ──
        let currentTab = 'history';

        function switchTab(tab) {
            currentTab = tab;
            document.getElementById('tab-history').style.display = tab === 'history' ? 'flex' : 'none';
            document.getElementById('tab-completion').style.display = tab === 'completion' ? 'flex' : 'none';
            document.getElementById('tab-btn-history').classList.toggle('active', tab === 'history');
            document.getElementById('tab-btn-completion').classList.toggle('active', tab === 'completion');
            document.querySelectorAll('.tab-history-only').forEach(el => {
                el.style.display = tab === 'history' ? '' : 'none';
            });
        }

        // ── Load Completion Rate ──
        function loadCompletionRate() {
            const value = document.getElementById('inp-date').value;
            if (!value) return;

            document.getElementById('completion-empty').style.display = 'none';
            document.getElementById('completion-loading').style.display = 'block';
            document.getElementById('completion-content').style.display = 'none';

            fetch(`history_checksheet.php?ajax=completion_rate&mode=${currentMode}&value=${encodeURIComponent(value)}`)
                .then(r => r.json())
                .then(data => {
                    document.getElementById('completion-loading').style.display = 'none';
                    if (!data.departments || data.departments.length === 0) {
                        document.getElementById('completion-empty').style.display = 'flex';
                        return;
                    }
                    renderCompletion(data.departments, value, currentMode);
                })
                .catch(() => {
                    document.getElementById('completion-loading').style.display = 'none';
                    document.getElementById('completion-empty').style.display = 'flex';
                });
        }

        function pctColor(pct) {
            if (pct >= 90) return '#22c55e';
            if (pct >= 60) return '#f59e0b';
            return '#ef4444';
        }

        function renderLineHtml(line) {
            const lc = pctColor(line.pct);
            const filledSet = new Set(line.filled_list);
            const machineChips = line.all_machines.map(function(m) {
                const name = m.machine_name;
                const op = (m.op && m.op !== '-') ? m.op : '';
                const label = op ? (esc(op) + ' - ' + esc(name)) : esc(name);
                const isFilled = filledSet.has(name);
                const cls = isFilled ? 'filled' : 'unfilled';
                const title = isFilled ? 'Sudah diisi' : 'Belum diisi';
                return '<span class="machine-chip ' + cls + '" title="' + title + '">' + label + '</span>';
            }).join('');
            return '<div style="margin-bottom:10px;">' +
                '<div class="line-row">' +
                '<div style="width:28px;height:28px;border-radius:8px;background:' + lc + '22;display:flex;align-items:center;justify-content:center;flex-shrink:0;">' +
                '<i class="fas fa-stream" style="color:' + lc + ';font-size:.7rem;"></i>' +
                '</div>' +
                '<div style="flex:1;min-width:0;">' +
                '<div style="font-size:.77rem;font-weight:700;color:#1e293b;">' + esc(line.line) + '</div>' +
                '<div style="font-size:.65rem;color:#64748b;">' + line.filled + '/' + line.total + ' mesin</div>' +
                '</div>' +
                '<div style="display:flex;align-items:center;gap:8px;flex-shrink:0;">' +
                '<div class="pct-bar-wrap" style="width:80px;">' +
                '<div class="pct-bar-fill" style="width:' + line.pct + '%;background:' + lc + ';"></div>' +
                '</div>' +
                '<span style="font-size:.77rem;font-weight:800;color:' + lc + ';min-width:32px;text-align:right;">' + line.pct + '%</span>' +
                '</div>' +
                '</div>' +
                '<div class="machine-chips">' + machineChips + '</div>' +
                '</div>';
        }

        function renderCompletion(depts, value, mode) {
            const bulanId = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
            let labelTanggal = value;
            if (mode === 'daily') {
                const d = new Date(value + 'T00:00:00');
                labelTanggal = d.getDate() + ' ' + bulanId[d.getMonth()] + ' ' + d.getFullYear();
            } else {
                const d = new Date(value + '-01T00:00:00');
                labelTanggal = bulanId[d.getMonth()] + ' ' + d.getFullYear();
            }
            const totalAll = depts.reduce((s, d) => s + d.total, 0);
            const filledAll = depts.reduce((s, d) => s + d.filled, 0);
            const pctAll = totalAll > 0 ? Math.round(filledAll / totalAll * 100) : 0;
            const summaryEl = document.getElementById('completion-summary');
            summaryEl.innerHTML = `
                <div style="display:flex;align-items:center;gap:10px;flex:1;min-width:200px;">
                    <div style="font-size:2rem;font-weight:900;color:${pctColor(pctAll)};">${pctAll}%</div>
                    <div>
                        <div style="font-size:.82rem;font-weight:700;color:#1e293b;">Overall Completion</div>
                        <div style="font-size:.72rem;color:#64748b;white-space:nowrap;">${filledAll} dari ${totalAll} mesin sudah diisi</div>
                        <div style="font-size:.72rem;color:#64748b;margin-top:1px;">${labelTanggal}</div>
                    </div>
                </div>
                <div style="flex:2;min-width:200px;">
                    <div class="pct-bar-wrap" style="height:12px;">
                        <div class="pct-bar-fill" style="width:${pctAll}%;background:${pctColor(pctAll)};"></div>
                    </div>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <span style="font-size:.72rem;font-weight:700;background:#dcfce7;color:#15803d;padding:3px 10px;border-radius:8px;">${filledAll} sudah diisi</span>
                    <span style="font-size:.72rem;font-weight:700;background:#fee2e2;color:#dc2626;padding:3px 10px;border-radius:8px;">${totalAll - filledAll} belum diisi</span>
                </div>`;

            const listEl = document.getElementById('completion-dept-list');
            listEl.innerHTML = '';

            depts.forEach((dept, di) => {
                const card = document.createElement('div');
                card.className = 'dept-card fade-in';
                card.style.animationDelay = `${di * 30}ms`;

                const deptColor = pctColor(dept.pct);
                card.innerHTML = `
                    <div class="dept-header" onclick="toggleDept(this)">
                        <div style="width:36px;height:36px;border-radius:10px;background:${deptColor}22;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="fas fa-industry" style="color:${deptColor};font-size:.85rem;"></i>
                        </div>
                        <div style="flex:1;min-width:0;">
                            <div style="font-size:.82rem;font-weight:800;color:#1e293b;">${esc(dept.name)}</div>
                            <div style="font-size:.68rem;color:#64748b;margin-top:1px;">${dept.lines.length} line · ${dept.filled}/${dept.total} mesin</div>
                        </div>
                        <div style="display:flex;align-items:center;gap:10px;flex-shrink:0;">
                            <div class="pct-bar-wrap" style="width:100px;">
                                <div class="pct-bar-fill" style="width:${dept.pct}%;background:${deptColor};"></div>
                            </div>
                            <span style="font-size:.82rem;font-weight:900;color:${deptColor};min-width:36px;text-align:right;">${dept.pct}%</span>
                            <i class="fas fa-chevron-down" style="font-size:.7rem;color:#94a3b8;transition:transform .2s;"></i>
                        </div>
                    </div>
                    <div class="dept-body">
                        ${dept.lines.map(line => renderLineHtml(line)).join('')}
                    </div>`;
                listEl.appendChild(card);
            });

            document.getElementById('completion-content').style.display = 'block';
        }

        function toggleDept(header) {
            const body = header.nextElementSibling;
            const icon = header.querySelector('.fa-chevron-down');
            body.classList.toggle('open');
            if (icon) icon.style.transform = body.classList.contains('open') ? 'rotate(180deg)' : '';
        }

        function closeCheckerModal(e) {
            if (!e || e.target === document.getElementById('checker-modal-overlay')) {
                document.getElementById('checker-modal-overlay').style.display = 'none';
            }
        }
    </script>
</body>

</html>