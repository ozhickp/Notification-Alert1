<?php
// history_checksheet_jig_assembly.php
// Pola & struktur JS SENGAJA dibuat semirip mungkin dengan
// history_checksheet_painting.php: list card per submission, modal detail
// dengan inline-edit per item, dan riwayat edit (collapsed by default).
require_once __DIR__ . '/config.php';

// ─── Gate akses ─────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['checksheet_unlocked']) || ($_SESSION['checksheet_area'] ?? '') !== 'jig_assembly') {
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['error' => 'unauthorized']);
        exit;
    }
    header('Location: checksheet_gate.php?redirect=history_checksheet_jig_assembly.php');
    exit;
}

$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function jigAssemblyQuarterLabel(int $quarter): string
{
    $labels = [1 => 'Kuartal 1 (Jan–Mar)', 2 => 'Kuartal 2 (Apr–Jun)', 3 => 'Kuartal 3 (Jul–Sep)', 4 => 'Kuartal 4 (Okt–Des)'];
    return $labels[$quarter] ?? '-';
}

// ─── AJAX: daftar checker (dipakai juga untuk pilih "pengedit") ────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'checkers') {
    header('Content-Type: application/json');
    $rows = $pdo->query("SELECT nama FROM checker_jig_assembly WHERE is_active = 1 ORDER BY nama")->fetchAll();
    echo json_encode(['checkers' => array_column($rows, 'nama')]);
    exit;
}

// ─── AJAX: daftar submission (list periode yang sudah diisi) + search ──────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'list') {
    header('Content-Type: application/json');

    $q = trim($_GET['q'] ?? '');

    $rows = $pdo->query("
        SELECT s.id, s.check_date, s.checker, s.submitted_at,
               COUNT(d.id) AS total_items,
               SUM(d.visual_result = 'OK') AS ok_count,
               SUM(d.visual_result = 'NG') AS ng_count
        FROM jig_assembly_submissions s
        LEFT JOIN jig_assembly_submission_details d ON d.submission_id = s.id
        GROUP BY s.id
        ORDER BY s.check_date DESC
    ")->fetchAll();

    foreach ($rows as &$r) {
        $ts      = strtotime($r['check_date']);
        $quarter = intdiv(((int)date('n', $ts)) - 1, 3) + 1;
        $r['period_label'] = jigAssemblyQuarterLabel($quarter) . ' ' . date('Y', $ts);
    }
    unset($r);

    if ($q !== '') {
        $like = '%' . $q . '%';

        // Cocokkan juga isi item (nama mesin/jig, check point, catatan)
        $stmtItem = $pdo->prepare("
            SELECT DISTINCT d.submission_id
            FROM jig_assembly_submission_details d
            JOIN jig_assembly_machines m ON m.id = d.machine_id
            JOIN jig_assembly_checkpoints c ON c.id = d.checkpoint_id
            WHERE m.machine_name LIKE ? OR m.jig_name LIKE ? OR c.check_point LIKE ? OR d.note LIKE ?
        ");
        $stmtItem->execute([$like, $like, $like, $like]);
        $matchIds = array_column($stmtItem->fetchAll(), 'submission_id');

        $qLower = mb_strtolower($q);
        $rows = array_values(array_filter($rows, function ($r) use ($qLower, $matchIds) {
            return str_contains(mb_strtolower($r['checker'] ?? ''), $qLower)
                || str_contains(mb_strtolower($r['period_label']), $qLower)
                || str_contains($r['check_date'], $qLower)
                || in_array($r['id'], $matchIds, true);
        }));
    }

    echo json_encode(['rows' => $rows]);
    exit;
}

// ─── AJAX: detail 1 submission, dikelompokkan per mesin/jig ────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'detail' && isset($_GET['id'])) {
    header('Content-Type: application/json');

    $stmtSub = $pdo->prepare("SELECT * FROM jig_assembly_submissions WHERE id = ?");
    $stmtSub->execute([(int)$_GET['id']]);
    $sub = $stmtSub->fetch();
    if (!$sub) {
        echo json_encode(['error' => 'not_found']);
        exit;
    }

    $stmtDet = $pdo->prepare("
        SELECT d.id, d.checkpoint_id, d.machine_id, d.visual_result, d.actual_diameter, d.note,
               m.no AS machine_no, m.machine_name, m.jig_name,
               c.no AS cp_no, c.check_point, c.is_diameter, c.standard_value
        FROM jig_assembly_submission_details d
        JOIN jig_assembly_machines m ON m.id = d.machine_id
        JOIN jig_assembly_checkpoints c ON c.id = d.checkpoint_id
        WHERE d.submission_id = ?
        ORDER BY m.sort_order, m.id, c.sort_order, c.no
    ");
    $stmtDet->execute([(int)$_GET['id']]);
    $details = $stmtDet->fetchAll();

    $grouped = [];
    foreach ($details as $d) {
        $label = $d['machine_no'] . '. ' . $d['machine_name'] . ' — ' . $d['jig_name'];
        $grouped[$label][] = $d;
    }

    echo json_encode(['submission' => $sub, 'grouped' => $grouped]);
    exit;
}

// ─── AJAX: update hasil pengisian 1 item + catat riwayat edit ──────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'update_detail' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $detailId  = (int)($_POST['detail_id'] ?? 0);
    $editedBy  = trim($_POST['edited_by'] ?? '');
    $newResult = $_POST['visual_result'] ?? '';
    $newActual = trim($_POST['actual_diameter'] ?? '');
    $newNote   = trim($_POST['note'] ?? '');

    $newResult = in_array($newResult, ['OK', 'NG'], true) ? $newResult : null;

    if (!$detailId || $editedBy === '') {
        echo json_encode(['success' => false, 'message' => 'Data tidak lengkap.']);
        exit;
    }

    $stmtChecker = $pdo->prepare("SELECT COUNT(*) FROM checker_jig_assembly WHERE nama = ? AND is_active = 1");
    $stmtChecker->execute([$editedBy]);
    if (!$stmtChecker->fetchColumn()) {
        echo json_encode(['success' => false, 'message' => 'Pilih nama pengedit yang valid.']);
        exit;
    }

    $stmtOld = $pdo->prepare("
        SELECT d.*, m.no AS machine_no, m.machine_name, m.jig_name, c.check_point
        FROM jig_assembly_submission_details d
        JOIN jig_assembly_machines m ON m.id = d.machine_id
        JOIN jig_assembly_checkpoints c ON c.id = d.checkpoint_id
        WHERE d.id = ?
    ");
    $stmtOld->execute([$detailId]);
    $old = $stmtOld->fetch();
    if (!$old) {
        echo json_encode(['success' => false, 'message' => 'Item tidak ditemukan.']);
        exit;
    }

    $machineLabel = $old['machine_no'] . '. ' . $old['machine_name'] . ' — ' . $old['jig_name'];

    $changes = [];
    if ($newResult !== $old['visual_result']) $changes[] = ['visual_result', $old['visual_result'] ?: '-', $newResult ?: '-'];
    if ($newActual !== ($old['actual_diameter'] ?? '')) $changes[] = ['actual_diameter', $old['actual_diameter'] ?: '-', $newActual ?: '-'];
    if ($newNote !== ($old['note'] ?? '')) $changes[] = ['note', $old['note'] ?: '-', $newNote ?: '-'];

    if (empty($changes)) {
        echo json_encode(['success' => true, 'message' => 'Tidak ada perubahan.', 'changed' => false]);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $upd = $pdo->prepare("
            UPDATE jig_assembly_submission_details
            SET visual_result = ?, actual_diameter = ?, note = ?
            WHERE id = ?
        ");
        $upd->execute([$newResult, $newActual !== '' ? $newActual : null, $newNote !== '' ? $newNote : null, $detailId]);

        $log = $pdo->prepare("
            INSERT INTO jig_assembly_edit_log
                (submission_id, detail_id, machine_label, check_point, field_changed, old_value, new_value, edited_by, edited_at)
            VALUES (?,?,?,?,?,?,?,?, NOW())
        ");
        foreach ($changes as [$field, $oldVal, $newVal]) {
            $log->execute([$old['submission_id'], $detailId, $machineLabel, $old['check_point'], $field, $oldVal, $newVal, $editedBy]);
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'changed' => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan perubahan.']);
    }
    exit;
}

// ─── AJAX: riwayat edit untuk 1 submission ──────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'edit_log' && isset($_GET['submission_id'])) {
    header('Content-Type: application/json');

    $stmt = $pdo->prepare("
        SELECT machine_label, check_point, field_changed, old_value, new_value, edited_by, edited_at
        FROM jig_assembly_edit_log
        WHERE submission_id = ?
        ORDER BY edited_at DESC, id DESC
    ");
    $stmt->execute([(int)$_GET['submission_id']]);
    echo json_encode(['logs' => $stmt->fetchAll()]);
    exit;
}

// ─── Helper periode 3 bulanan (anchor April 2026) — sama persis dengan
// dashboard_checksheet_jig_assembly.php, supaya status "periode berjalan"
// di History konsisten dengan dashboard.
function jigAssemblyCurrentPeriod(PDO $pdo, string $today): array
{
    $anchor  = new DateTime('2026-04-01');
    $todayDt = new DateTime($today);

    if ($todayDt < $anchor) {
        $periodStart = clone $anchor;
    } else {
        $diffMonths  = (((int)$todayDt->format('Y')) - (int)$anchor->format('Y')) * 12
            + ((int)$todayDt->format('n') - (int)$anchor->format('n'));
        $periodIndex = intdiv($diffMonths, 3);
        $periodStart = (clone $anchor)->modify('+' . ($periodIndex * 3) . ' months');
    }
    $periodEnd       = (clone $periodStart)->modify('+3 months')->modify('-1 day');
    $nextPeriodStart = (clone $periodStart)->modify('+3 months');

    $stmt = $pdo->prepare(
        "SELECT check_date, checker FROM jig_assembly_submissions
         WHERE check_date BETWEEN ? AND ?
         ORDER BY check_date DESC, id DESC LIMIT 1"
    );
    $stmt->execute([$periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d')]);
    $submission = $stmt->fetch() ?: null;

    return [
        'period_start'      => $periodStart->format('Y-m-d'),
        'period_end'        => $periodEnd->format('Y-m-d'),
        'next_period_start' => $nextPeriodStart->format('Y-m-d'),
        'submission'        => $submission,
        'filled'            => $submission !== null,
    ];
}

// ─── Data ringkasan untuk header halaman ───────────────────────────────────
$totalSubmissions = (int)$pdo->query("SELECT COUNT(*) FROM jig_assembly_submissions")->fetchColumn();
$currentDate      = date('Y-m-d');
$periodInfo       = jigAssemblyCurrentPeriod($pdo, $currentDate);
$periodFilled     = $periodInfo['filled'];

// Daftar submission (periode) untuk dropdown "Export Periode"
$exportableSubmissions = $pdo->query("
    SELECT id, check_date
    FROM jig_assembly_submissions
    ORDER BY check_date DESC, id DESC
")->fetchAll();
foreach ($exportableSubmissions as &$es) {
    $tsEs = strtotime($es['check_date']);
    $qEs  = intdiv(((int)date('n', $tsEs)) - 1, 3) + 1;
    $es['label'] = 'Q' . $qEs . ' ' . date('Y', $tsEs) . ' · ' . date('d M Y', $tsEs);
}
unset($es);
$defaultExportId = $exportableSubmissions[0]['id'] ?? null;

// Tahun-tahun yang punya data, untuk dropdown "Export Tahunan"
$availableYears = $pdo->query("
    SELECT DISTINCT SUBSTRING(check_date, 1, 4) AS y
    FROM jig_assembly_submissions
    ORDER BY y DESC
")->fetchAll(PDO::FETCH_COLUMN);
if (empty($availableYears)) $availableYears = [date('Y')];
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jig Assembly Check Sheet — History</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: #f1f5f9;
        }

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
            background: linear-gradient(135deg, #e36414, #c4550f);
            color: #fff;
            box-shadow: 0 4px 12px rgba(227, 100, 20, .35);
        }

        #sidebar .nav-item i {
            width: 18px;
            text-align: center;
            font-size: .9rem;
        }

        #main-content {
            margin-left: 56px;
            min-height: 100vh;
            transition: margin-left .25s ease;
        }

        #main-content.expanded {
            margin-left: 240px;
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

        .info-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 5px 12px;
            border-radius: 999px;
            font-size: .74rem;
            font-weight: 700;
            color: #475569;
            height: 30px;
            box-sizing: border-box;
            white-space: nowrap;
        }

        /* Grup "dropdown + tombol export" dibuat jadi 1 pill utuh supaya
           tingginya sama persis dengan bulatan .info-chip lain dan tidak
           bikin topbar pecah ke baris kedua. */
        .export-chip-group {
            display: inline-flex;
            align-items: stretch;
            height: 30px;
            box-sizing: border-box;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 999px;
            overflow: hidden;
            flex-shrink: 0;
        }

        .export-chip-group select {
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            border: none;
            background: transparent;
            font-size: .74rem;
            font-weight: 700;
            color: #475569;
            padding: 0 8px 0 14px;
            max-width: 150px;
            height: 100%;
            box-sizing: border-box;
            cursor: pointer;
            outline: none;
            font-family: inherit;
        }

        .export-chip-group a {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 0 12px;
            font-size: .74rem;
            font-weight: 700;
            color: #475569;
            border-left: 1px solid #e2e8f0;
            white-space: nowrap;
            text-decoration: none;
        }

        .export-chip-group a:hover {
            background: #f1f5f9;
        }

        .summary-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 14px 18px;
        }

        .day-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 14px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            transition: box-shadow .15s, border-color .15s;
        }

        .day-card:hover {
            box-shadow: 0 4px 14px rgba(0, 0, 0, .06);
            border-color: #fbceA3;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: .68rem;
            font-weight: 800;
        }

        .badge.ok {
            background: #dcfce7;
            color: #15803d;
        }

        .badge.ng {
            background: #fee2e2;
            color: #dc2626;
        }

        .badge.neutral {
            background: #f1f5f9;
            color: #64748b;
        }

        /* ── Modal ── */
        #detail-modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, .55);
            z-index: 200;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        #detail-modal.open {
            display: flex;
        }

        #detail-modal .modal-box {
            background: #fff;
            border-radius: 20px;
            width: 100%;
            max-width: 820px;
            max-height: 85vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .modal-header {
            padding: 16px 22px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .modal-body {
            padding: 18px 22px;
            overflow-y: auto;
        }

        .detail-unit-title {
            font-size: .76rem;
            font-weight: 800;
            color: #c4550f;
            text-transform: uppercase;
            letter-spacing: .04em;
            margin: 14px 0 6px;
        }

        .detail-item-row {
            display: grid;
            grid-template-columns: 22px 1fr 90px 70px 64px 1fr 24px;
            gap: 10px;
            padding: 6px 0;
            font-size: .78rem;
            border-bottom: 1px solid #f1f5f9;
            align-items: center;
        }

        .result-chip {
            font-size: .68rem;
            font-weight: 800;
            padding: 2px 8px;
            border-radius: 999px;
            text-align: center;
        }

        .result-chip.OK {
            background: #dcfce7;
            color: #15803d;
        }

        .result-chip.NG {
            background: #fee2e2;
            color: #dc2626;
        }

        .result-chip.none {
            background: #f1f5f9;
            color: #94a3b8;
        }

        .edit-btn {
            width: 22px;
            height: 22px;
            border-radius: 6px;
            border: none;
            background: transparent;
            color: #cbd5e1;
            cursor: pointer;
            font-size: .72rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: background .15s, color .15s;
        }

        .edit-btn:hover {
            background: #fdf4ee;
            color: #e36414;
        }

        .edit-row {
            display: grid;
            grid-template-columns: 24px 1fr 1fr auto;
            gap: 8px;
            align-items: center;
            padding: 8px 8px;
            margin: 2px 0 6px;
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 10px;
            font-size: .72rem;
        }

        .edit-row select,
        .edit-row input {
            font-size: .72rem;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 4px 6px;
            width: 100%;
            background: #fff;
        }

        .edit-row-actions {
            display: flex;
            gap: 4px;
        }

        .edit-row-actions button {
            font-size: .68rem;
            font-weight: 800;
            padding: 5px 9px;
            border-radius: 7px;
            border: none;
            cursor: pointer;
        }

        .btn-save-edit {
            background: #e36414;
            color: #fff;
        }

        .btn-save-edit:hover {
            background: #c4550f;
        }

        .btn-cancel-edit {
            background: #e2e8f0;
            color: #475569;
        }

        .btn-cancel-edit:hover {
            background: #cbd5e1;
        }

        .edit-log-toggle {
            margin-top: 16px;
            padding-top: 10px;
            border-top: 1px dashed #e2e8f0;
            font-size: .72rem;
            font-weight: 700;
            color: #64748b;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            user-select: none;
        }

        .edit-log-toggle:hover {
            color: #c4550f;
        }

        .edit-log-list {
            display: none;
            margin-top: 8px;
        }

        .edit-log-list.open {
            display: block;
        }

        .edit-log-item {
            font-size: .68rem;
            color: #64748b;
            padding: 5px 8px;
            border-radius: 7px;
            background: #f8fafc;
            margin-bottom: 4px;
            line-height: 1.5;
        }

        .edit-log-empty {
            font-size: .72rem;
            color: #94a3b8;
            padding: 6px 0;
        }

        .hidden {
            display: none;
        }
    </style>
</head>

<body>

    <aside id="sidebar" class="collapsed">
        <div class="brand">
            <div class="brand-icon-wrap">
                <div class="w-8 h-8 rounded-lg bg-[#e36414] flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-ruler-combined text-white text-xs"></i>
                </div>
                <div class="brand-text">
                    <div class="text-white text-xs font-bold leading-tight">Maintenance Hub</div>
                    <div class="text-slate-500 text-[10px] font-medium">Jig Assembly Check Sheet</div>
                </div>
            </div>
        </div>

        <nav class="mt-4 flex-1">
            <a href="checksheet_gate.php?logout=1" class="sidebar-back" title="Kunci / Ganti Area">
                <i class="fas fa-lock flex-shrink-0"></i>
                <span class="sb-label">Kunci Halaman</span>
            </a>
            <div style="height:1px;background:rgba(255,255,255,.07);margin:.4rem 6px;"></div>
            <div class="px-3 mb-2 menu-label">
                <span class="text-[10px] font-bold text-slate-600 uppercase tracking-widest">Menu</span>
            </div>
            <a href="dashboard_checksheet_jig_assembly.php" onclick="navigateTo(event,'dashboard_checksheet_jig_assembly.php')" class="nav-item" title="Check Sheet">
                <i class="fas fa-clipboard-check"></i>
                <span class="nav-label">Check Sheet</span>
            </a>
            <a href="history_checksheet_jig_assembly.php" onclick="navigateTo(event,'history_checksheet_jig_assembly.php')" class="nav-item active" title="History">
                <i class="fas fa-history"></i>
                <span class="nav-label">History</span>
            </a>
            <a href="checksheet_jig_assembly_draft.php" onclick="navigateTo(event,'checksheet_jig_assembly_draft.php')" class="nav-item" title="Draft">
                <i class="fas fa-pen-to-square"></i>
                <span class="nav-label">Draft</span>
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
                <div class="w-7 h-7 rounded-lg bg-[#fdf4ee] flex items-center justify-center">
                    <i class="fas fa-history text-[#e36414] text-xs"></i>
                </div>
                <div>
                    <div class="text-sm font-bold text-slate-800">History Checksheet Jig Assembly</div>
                    <div class="text-[10px] text-slate-400 font-medium">Rekap pengecekan 3 bulanan — Machine Press 1/2/3</div>
                </div>
            </div>
            <div class="flex items-center gap-2" style="flex-wrap:nowrap;">
                <?php if ($defaultExportId): ?>
                    <div class="export-chip-group">
                        <select id="sel-export-period" title="Pilih periode">
                            <?php foreach ($exportableSubmissions as $es): ?>
                                <option value="<?= (int)$es['id'] ?>" <?= (int)$es['id'] === (int)$defaultExportId ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($es['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <a id="btn-export-period" href="export_checksheet_jig_assembly.php?id=<?= (int)$defaultExportId ?>" title="Export periode terpilih ke Excel">
                            <i class="fas fa-file-excel text-emerald-500"></i> Export Periode
                        </a>
                    </div>
                    <div class="export-chip-group">
                        <select id="sel-export-year" title="Pilih tahun">
                            <?php foreach ($availableYears as $y): ?>
                                <option value="<?= htmlspecialchars($y) ?>"><?= htmlspecialchars($y) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <a id="btn-export-yearly" href="export_checksheet_jig_assembly_yearly.php?tahun=<?= htmlspecialchars($availableYears[0]) ?>" title="Export rekap 1 tahun ke Excel">
                            <i class="fas fa-file-excel text-orange-500"></i> Export Tahunan
                        </a>
                    </div>
                <?php endif; ?>
                <span class="info-chip"><i class="far fa-calendar text-orange-400"></i> <span id="today-label"></span></span>
                <button onclick="document.getElementById('back-confirm-overlay').style.display='flex'"
                    class="info-chip" style="cursor:pointer;border-color:#fecaca;color:#dc2626;background:#fef2f2;" title="Kembali & kunci halaman">
                    <i class="fas fa-arrow-left"></i> Kembali
                </button>
            </div>
        </div>

        <div id="back-confirm-overlay" style="position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:9998;display:none;align-items:center;justify-content:center;">
            <div style="background:#fff;border-radius:16px;padding:22px 24px;max-width:360px;width:90%;box-shadow:0 20px 50px rgba(0,0,0,.25);">
                <div class="flex items-center gap-2 mb-2">
                    <i class="fas fa-lock text-[#e36414]"></i>
                    <span class="text-sm font-extrabold text-slate-800">Kembali ke Menu Utama?</span>
                </div>
                <p class="text-xs text-slate-500 mb-4 leading-relaxed">
                    Halaman Checksheet Jig Assembly akan terkunci. Untuk masuk lagi, Anda perlu memasukkan key akses dari halaman Checksheet Gate.
                </p>
                <div class="flex justify-end gap-2">
                    <button onclick="document.getElementById('back-confirm-overlay').style.display='none'"
                        class="px-3 py-2 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100">Batal</button>
                    <button onclick="window.location.href='checksheet_gate.php?logout=1'"
                        class="px-3 py-2 rounded-xl text-xs font-bold text-white" style="background:#e36414;">Ya, Kembali &amp; Kunci</button>
                </div>
            </div>
        </div>

        <div class="p-4">

            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
                <div class="summary-card">
                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Total Submission</div>
                    <div class="text-2xl font-extrabold text-slate-800"><?= $totalSubmissions ?></div>
                </div>
                <div class="summary-card">
                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">
                        Periode Berjalan (<?= htmlspecialchars(date('d M', strtotime($periodInfo['period_start']))) ?> – <?= htmlspecialchars(date('d M Y', strtotime($periodInfo['period_end']))) ?>)
                    </div>
                    <div class="text-2xl font-extrabold <?= $periodFilled ? 'text-emerald-600' : 'text-rose-600' ?>">
                        <?= $periodFilled ? 'Sudah Diisi' : 'Belum Diisi' ?>
                    </div>
                </div>
                <div class="summary-card flex items-center">
                    <?php if (!$periodFilled): ?>
                        <a href="dashboard_checksheet_jig_assembly.php" class="text-xs font-bold text-white bg-[#e36414] hover:bg-[#c4550f] transition rounded-xl px-4 py-2.5 inline-flex items-center gap-2">
                            <i class="fas fa-plus"></i> Isi Checksheet Periode Ini
                        </a>
                    <?php else: ?>
                        <span class="text-xs font-semibold text-slate-400"><i class="fas fa-check-circle text-emerald-500 mr-1"></i> Semua terkini. Periode berikutnya mulai <?= htmlspecialchars(date('d M Y', strtotime($periodInfo['next_period_start']))) ?>.</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="relative mb-4">
                <i class="fas fa-search absolute text-slate-300 text-xs" style="left:14px;top:50%;transform:translateY(-50%);"></i>
                <input id="search-input" type="text" placeholder="Cari tanggal, checker, mesin/jig, atau catatan..."
                    class="w-full text-xs font-semibold text-slate-700 bg-white border border-slate-200 rounded-xl pl-9 pr-9 py-2.5 focus:outline-none focus:ring-2 focus:ring-orange-100 focus:border-orange-400">
                <button id="search-clear" onclick="clearSearch()"
                    class="hidden absolute text-slate-300 hover:text-slate-500 text-xs" style="right:14px;top:50%;transform:translateY(-50%);">
                    <i class="fas fa-times-circle"></i>
                </button>
            </div>

            <div class="space-y-3" id="day-list">
                <div class="text-xs text-slate-400 font-semibold px-2">Memuat data...</div>
            </div>
        </div>
    </div>

    <!-- Detail Modal -->
    <div id="detail-modal">
        <div class="modal-box">
            <div class="modal-header">
                <div>
                    <div class="text-sm font-extrabold text-slate-800" id="modal-title">Detail Checksheet</div>
                    <div class="text-[11px] text-slate-400 font-medium" id="modal-subtitle"></div>
                </div>
                <div class="flex items-center gap-2">
                    <a id="modal-export-link" href="#" class="text-xs font-bold text-emerald-600 hover:text-emerald-700" title="Export periode ini ke Excel">
                        <i class="fas fa-file-excel"></i>
                    </a>
                    <button onclick="closeDetailModal()" class="w-8 h-8 rounded-lg hover:bg-slate-100 flex items-center justify-center text-slate-400">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            <div class="modal-body" id="modal-body">
                <!-- diisi via JS -->
            </div>
        </div>
    </div>

    <script>
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

        function esc(str) {
            const d = document.createElement('div');
            d.textContent = str ?? '';
            return d.innerHTML;
        }

        let currentSearchQuery = '';
        let currentSubmissionId = null;
        let checkersCache = null;

        document.addEventListener('DOMContentLoaded', () => {
            const sidebar = document.getElementById('sidebar');
            const main = document.getElementById('main-content');
            const icon = document.getElementById('sidebarToggleIcon');
            const state = sessionStorage.getItem('checksheet_sidebar');
            if (state === 'expanded') {
                sidebar.classList.remove('collapsed');
                main.classList.add('expanded');
                icon.className = 'fas fa-chevron-left';
            }
            loadList();
            document.getElementById('today-label').textContent = new Date().toLocaleDateString('id-ID', {
                weekday: 'long',
                day: 'numeric',
                month: 'long',
                year: 'numeric'
            });

            const searchInput = document.getElementById('search-input');
            let debounceTimer;
            searchInput.addEventListener('input', () => {
                clearTimeout(debounceTimer);
                document.getElementById('search-clear').classList.toggle('hidden', searchInput.value.trim() === '');
                debounceTimer = setTimeout(() => {
                    currentSearchQuery = searchInput.value.trim();
                    loadList(currentSearchQuery);
                }, 300);
            });

            const selPeriod = document.getElementById('sel-export-period');
            if (selPeriod) {
                selPeriod.addEventListener('change', function() {
                    document.getElementById('btn-export-period').href =
                        'export_checksheet_jig_assembly.php?id=' + encodeURIComponent(this.value);
                });
            }
            const selYear = document.getElementById('sel-export-year');
            if (selYear) {
                selYear.addEventListener('change', function() {
                    document.getElementById('btn-export-yearly').href =
                        'export_checksheet_jig_assembly_yearly.php?tahun=' + encodeURIComponent(this.value);
                });
            }
        });

        function clearSearch() {
            const searchInput = document.getElementById('search-input');
            searchInput.value = '';
            document.getElementById('search-clear').classList.add('hidden');
            currentSearchQuery = '';
            loadList();
        }

        function loadList(q) {
            const url = 'history_checksheet_jig_assembly.php?ajax=list' + (q ? '&q=' + encodeURIComponent(q) : '');
            fetch(url)
                .then(r => r.json())
                .then(res => renderList(res.rows || [], q));
        }

        function dateLabel(dateStr) {
            const d = new Date(dateStr + 'T00:00:00');
            return d.toLocaleDateString('id-ID', {
                weekday: 'long',
                day: 'numeric',
                month: 'long',
                year: 'numeric'
            });
        }

        function fmtDateTime(str) {
            if (!str) return '-';
            const d = new Date(str.replace(' ', 'T'));
            if (isNaN(d)) return str;
            return d.toLocaleDateString('id-ID', {
                    day: '2-digit',
                    month: 'short',
                    year: 'numeric'
                }) +
                ' ' + d.toLocaleTimeString('id-ID', {
                    hour: '2-digit',
                    minute: '2-digit'
                });
        }

        function renderList(rows, q) {
            const container = document.getElementById('day-list');
            container.innerHTML = '';

            if (rows.length === 0) {
                container.innerHTML = q ?
                    '<div class="text-xs text-slate-400 font-semibold px-2">Tidak ada hasil untuk pencarian "' + esc(q) + '".</div>' :
                    '<div class="text-xs text-slate-400 font-semibold px-2">Belum ada data checksheet jig assembly.</div>';
                return;
            }

            rows.forEach(row => {
                const card = document.createElement('div');
                card.className = 'day-card';
                card.onclick = () => openDetailModal(row.id);

                const ngBadge = row.ng_count > 0 ?
                    `<span class="badge ng"><i class="fas fa-triangle-exclamation"></i> ${row.ng_count} NG</span>` :
                    `<span class="badge ok"><i class="fas fa-check"></i> Semua OK</span>`;

                card.innerHTML = `
                    <div>
                        <div class="text-sm font-extrabold text-slate-800">${esc(dateLabel(row.check_date))}</div>
                        <div class="text-[11px] text-slate-400 font-medium mt-0.5">
                            <i class="fas fa-user mr-1"></i>${esc(row.checker)} ·
                            <i class="fas fa-clock ml-1 mr-1"></i>${fmtDateTime(row.submitted_at)}
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="badge neutral">${row.total_items} item dicek</span>
                        ${ngBadge}
                        <i class="fas fa-chevron-right text-slate-300 ml-2"></i>
                    </div>
                `;
                container.appendChild(card);
            });
        }

        function loadCheckers() {
            if (checkersCache) return Promise.resolve(checkersCache);
            return fetch('history_checksheet_jig_assembly.php?ajax=checkers')
                .then(r => r.json())
                .then(res => {
                    checkersCache = res.checkers || [];
                    return checkersCache;
                });
        }

        function openDetailModal(id) {
            currentSubmissionId = id;
            Promise.all([
                fetch('history_checksheet_jig_assembly.php?ajax=detail&id=' + id).then(r => r.json()),
                loadCheckers()
            ]).then(([res, checkers]) => {
                if (res.error) return;
                const sub = res.submission;
                document.getElementById('modal-title').textContent = 'Checksheet Jig Assembly — ' + dateLabel(sub.check_date);
                document.getElementById('modal-subtitle').textContent =
                    `Checker: ${sub.checker} · Submitted: ${fmtDateTime(sub.submitted_at)}`;
                document.getElementById('modal-export-link').href = 'export_checksheet_jig_assembly.php?id=' + id;

                const body = document.getElementById('modal-body');
                body.innerHTML = '';

                Object.keys(res.grouped).forEach(machineLabel => {
                    const title = document.createElement('div');
                    title.className = 'detail-unit-title';
                    title.textContent = machineLabel;
                    body.appendChild(title);

                    const hdr = document.createElement('div');
                    hdr.className = 'detail-item-row';
                    hdr.style.fontWeight = '800';
                    hdr.style.color = '#94a3b8';
                    hdr.style.fontSize = '.68rem';
                    hdr.innerHTML = '<div>No</div><div>Check Point</div><div>Standard</div><div>Actual</div><div>Result</div><div>Note</div><div></div>';
                    body.appendChild(hdr);

                    res.grouped[machineLabel].forEach(item => {
                        body.appendChild(buildItemRow(item, checkers));
                    });
                });

                const logToggle = document.createElement('div');
                logToggle.className = 'edit-log-toggle';
                logToggle.id = 'edit-log-toggle';
                logToggle.innerHTML = '<i class="fas fa-clock-rotate-left"></i> <span>Riwayat Edit</span> <i class="fas fa-chevron-down" style="font-size:.6rem;"></i>';
                const logList = document.createElement('div');
                logList.className = 'edit-log-list';
                logList.id = 'edit-log-list';
                logToggle.onclick = () => toggleEditLog(id);
                body.appendChild(logToggle);
                body.appendChild(logList);

                document.getElementById('detail-modal').classList.add('open');
            });
        }

        function buildItemRow(item, checkers) {
            const row = document.createElement('div');
            row.className = 'detail-item-row';
            row.id = 'row-' + item.id;
            const resultCls = item.visual_result || 'none';
            const actualText = item.is_diameter == 1 ? (item.actual_diameter || '—') : '—';
            const isVisual = item.is_diameter != 1;
            const standardText = item.standard_value ? esc(item.standard_value) : (isVisual ? 'Visual' : '—');
            row.innerHTML = `
                <div class="text-slate-400 font-bold">${item.cp_no}</div>
                <div class="font-semibold text-slate-700">${esc(item.check_point)}</div>
                <div class="${isVisual ? 'text-slate-400 italic' : 'text-slate-600 font-semibold'}" style="font-size:.74rem;">${standardText}</div>
                <div class="text-slate-500">${esc(actualText)}</div>
                <div><span class="result-chip ${resultCls}">${item.visual_result || '—'}</span></div>
                <div class="text-slate-400">${esc(item.note || '—')}</div>
                <div><button class="edit-btn" title="Edit hasil" onclick="toggleEditRow(${item.id})"><i class="fas fa-pen"></i></button></div>
            `;
            row._item = item;
            row._checkers = checkers;
            return row;
        }

        function toggleEditRow(detailId) {
            const row = document.getElementById('row-' + detailId);
            if (!row) return;

            const existingEdit = document.getElementById('edit-form-' + detailId);
            if (existingEdit) {
                existingEdit.remove();
                return;
            }

            const item = row._item;
            const checkers = row._checkers || [];
            const checkerOptions = checkers.map(c => `<option value="${esc(c)}">${esc(c)}</option>`).join('');

            const form = document.createElement('div');
            form.className = 'edit-row';
            form.id = 'edit-form-' + detailId;
            form.innerHTML = `
                <div></div>
                <div>
                    <select id="edit-result-${detailId}">
                        <option value="" ${!item.visual_result ? 'selected' : ''}>— Tidak ada hasil —</option>
                        <option value="OK" ${item.visual_result === 'OK' ? 'selected' : ''}>OK</option>
                        <option value="NG" ${item.visual_result === 'NG' ? 'selected' : ''}>NG</option>
                    </select>
                    ${item.is_diameter == 1 ? `<input type="text" id="edit-actual-${detailId}" placeholder="Actual (mm)" value="${esc(item.actual_diameter || '')}" style="margin-top:4px;">` : ''}
                </div>
                <div>
                    <input type="text" id="edit-note-${detailId}" placeholder="Catatan" value="${esc(item.note || '')}">
                    <select id="edit-by-${detailId}" style="margin-top:4px;">
                        <option value="">— Pilih pengedit —</option>
                        ${checkerOptions}
                    </select>
                </div>
                <div class="edit-row-actions">
                    <button class="btn-save-edit" onclick="saveEdit(${detailId})">Simpan</button>
                    <button class="btn-cancel-edit" onclick="document.getElementById('edit-form-${detailId}').remove()">Batal</button>
                </div>
            `;
            row.insertAdjacentElement('afterend', form);
        }

        function saveEdit(detailId) {
            const editedBy = document.getElementById('edit-by-' + detailId).value;
            if (!editedBy) {
                alert('Pilih nama pengedit terlebih dahulu.');
                return;
            }

            const actualInput = document.getElementById('edit-actual-' + detailId);

            const fd = new FormData();
            fd.append('detail_id', detailId);
            fd.append('edited_by', editedBy);
            fd.append('visual_result', document.getElementById('edit-result-' + detailId).value);
            fd.append('actual_diameter', actualInput ? actualInput.value : '');
            fd.append('note', document.getElementById('edit-note-' + detailId).value);

            fetch('history_checksheet_jig_assembly.php?ajax=update_detail', {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json())
                .then(res => {
                    if (!res.success) {
                        alert(res.message || 'Gagal menyimpan perubahan.');
                        return;
                    }
                    const openLog = document.getElementById('edit-log-list')?.classList.contains('open');
                    openDetailModal(currentSubmissionId);
                    loadList(currentSearchQuery);
                    if (openLog) setTimeout(() => toggleEditLog(currentSubmissionId, true), 150);
                });
        }

        function toggleEditLog(submissionId, forceOpen) {
            const list = document.getElementById('edit-log-list');
            if (!list) return;

            const willOpen = forceOpen || !list.classList.contains('open');
            if (!willOpen) {
                list.classList.remove('open');
                return;
            }

            list.classList.add('open');
            list.innerHTML = '<div class="edit-log-empty">Memuat riwayat...</div>';

            fetch('history_checksheet_jig_assembly.php?ajax=edit_log&submission_id=' + submissionId)
                .then(r => r.json())
                .then(res => {
                    const logs = res.logs || [];
                    if (logs.length === 0) {
                        list.innerHTML = '<div class="edit-log-empty">Belum ada perubahan yang dicatat.</div>';
                        return;
                    }
                    const fieldLabel = {
                        visual_result: 'Result',
                        actual_diameter: 'Actual',
                        note: 'Catatan'
                    };
                    list.innerHTML = logs.map(l => `
                        <div class="edit-log-item">
                            <b>${esc(l.edited_by)}</b> mengubah ${fieldLabel[l.field_changed] || esc(l.field_changed)}
                            pada <b>${esc(l.check_point)}</b> (${esc(l.machine_label)}):
                            "${esc(l.old_value)}" → "${esc(l.new_value)}"
                            <div style="color:#94a3b8;">${fmtDateTime(l.edited_at)}</div>
                        </div>
                    `).join('');
                });
        }

        function closeDetailModal() {
            document.getElementById('detail-modal').classList.remove('open');
        }

        document.getElementById('detail-modal').addEventListener('click', function(e) {
            if (e.target === this) closeDetailModal();
        });
    </script>
</body>

</html>