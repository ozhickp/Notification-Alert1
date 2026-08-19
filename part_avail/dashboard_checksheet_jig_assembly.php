<?php
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
    header('Location: checksheet_gate.php?redirect=dashboard_checksheet_jig_assembly.php');
    exit;
}

$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function jigAssemblyDateSubmitted(PDO $pdo, string $date): ?array
{
    $stmt = $pdo->prepare("SELECT id, checker, submitted_at FROM jig_assembly_submissions WHERE check_date = ? LIMIT 1");
    $stmt->execute([$date]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function jigAssemblyDraftGet(PDO $pdo, string $date): ?array
{
    $stmt = $pdo->prepare("SELECT checker, items_json, updated_at FROM jig_assembly_drafts WHERE check_date = ? LIMIT 1");
    $stmt->execute([$date]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function jigAssemblyDraftSave(PDO $pdo, string $date, ?string $checker, string $itemsJson): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO jig_assembly_drafts (check_date, checker, items_json)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE
            checker    = VALUES(checker),
            items_json = VALUES(items_json),
            updated_at = CURRENT_TIMESTAMP"
    );
    $stmt->execute([$date, $checker ?: null, $itemsJson]);
}

function jigAssemblyDraftDelete(PDO $pdo, string $date): void
{
    $stmt = $pdo->prepare("DELETE FROM jig_assembly_drafts WHERE check_date = ?");
    $stmt->execute([$date]);
}

// Checksheet Jig Assembly diperiksa setiap 3 bulan sekali, dengan periode
// TETAP (bukan hitung mundur dari submission terakhir) yang dimulai dari
// April 2026: Apr–Jun 2026, Jul–Sep 2026, Okt–Des 2026, Jan–Mar 2027, dst.
// Fungsi ini menentukan periode berjalan (berdasarkan tanggal hari ini) dan
// apakah periode tersebut sudah ada submission-nya.
function jigAssemblyCurrentPeriod(PDO $pdo, string $today): array
{
    $anchor  = new DateTime('2026-04-01');
    $todayDt = new DateTime($today);

    if ($todayDt < $anchor) {
        // Sebelum program dimulai: anggap periode pertama yang berlaku.
        $periodStart = clone $anchor;
    } else {
        $diffMonths  = (((int)$todayDt->format('Y')) - (int)$anchor->format('Y')) * 12
            + ((int)$todayDt->format('n') - (int)$anchor->format('n'));
        $periodIndex = intdiv($diffMonths, 3);
        $periodStart = (clone $anchor)->modify('+' . ($periodIndex * 3) . ' months'); // kelipatan 3 bulan, bukan periodIndex bulan
    }
    $periodEnd       = (clone $periodStart)->modify('+3 months')->modify('-1 day');
    $nextPeriodStart = (clone $periodStart)->modify('+3 months');

    $stmt = $pdo->prepare(
        "SELECT check_date, checker FROM jig_assembly_submissions
         WHERE check_date BETWEEN ? AND ?
         ORDER BY check_date DESC, id DESC LIMIT 1"
    );
    $stmt->execute([$periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d')]);
    $submission = $stmt->fetch() ?: null; // fetch() returns false (bukan null) kalau kosong

    return [
        'period_start'      => $periodStart->format('Y-m-d'),
        'period_end'        => $periodEnd->format('Y-m-d'),
        'next_period_start' => $nextPeriodStart->format('Y-m-d'),
        'submission'        => $submission,
        'filled'            => $submission !== null,
    ];
}

// ─── AJAX ────────────────────────────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    if ($_GET['ajax'] === 'checkers') {
        $rows = $pdo->query("SELECT nama FROM checker_jig_assembly WHERE is_active = 1 ORDER BY nama")->fetchAll();
        echo json_encode(array_column($rows, 'nama'));
        exit;
    }

    // Daftar mesin + jig + check point, dikelompokkan per mesin (mirip 'items' di Painting)
    if ($_GET['ajax'] === 'items') {
        $machines    = $pdo->query("SELECT id, no, machine_name, jig_name FROM jig_assembly_machines WHERE is_active = 1 ORDER BY sort_order, id")->fetchAll();
        $checkpoints = $pdo->query("SELECT id, machine_id, no, check_point, is_diameter, standard_value FROM jig_assembly_checkpoints WHERE is_active = 1 ORDER BY machine_id, sort_order, no")->fetchAll();

        $cpByMachine = [];
        foreach ($checkpoints as $cp) $cpByMachine[$cp['machine_id']][] = $cp;

        $units = [];
        foreach ($machines as $m) {
            $units[] = [
                'id'          => $m['id'],
                'name'        => $m['no'] . '. ' . $m['machine_name'] . ' — ' . $m['jig_name'],
                'machine_id'  => $m['id'],
                'checkpoints' => $cpByMachine[$m['id']] ?? [],
            ];
        }
        echo json_encode($units);
        exit;
    }

    if ($_GET['ajax'] === 'check_date' && isset($_GET['date'])) {
        $existing = jigAssemblyDateSubmitted($pdo, $_GET['date']);
        echo json_encode(['already_filled' => $existing !== null, 'detail' => $existing]);
        exit;
    }

    if ($_GET['ajax'] === 'get_draft' && isset($_GET['date'])) {
        $draft = jigAssemblyDraftGet($pdo, $_GET['date']);
        echo json_encode(['draft' => $draft]);
        exit;
    }

    if ($_GET['ajax'] === 'save_draft' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $date      = trim($_POST['check_date'] ?? '');
        $checker   = trim($_POST['checker']    ?? '');
        $itemsJson = $_POST['items']           ?? '[]';

        if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            echo json_encode(['success' => false, 'message' => 'Tanggal tidak valid.']);
            exit;
        }
        if ($date < date('Y-m-d')) {
            echo json_encode(['success' => false, 'message' => 'Tanggal pengecekan tidak boleh mundur (backdate). Gunakan tanggal hari ini atau setelahnya.']);
            exit;
        }
        if (jigAssemblyDateSubmitted($pdo, $date) !== null) {
            echo json_encode(['success' => false, 'already_submitted' => true, 'message' => 'Tanggal ini sudah disubmit.']);
            exit;
        }

        try {
            jigAssemblyDraftSave($pdo, $date, $checker ?: null, $itemsJson);
            echo json_encode(['success' => true, 'saved_at' => date('c')]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal menyimpan draft: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($_GET['ajax'] === 'discard_draft' && isset($_GET['date'])) {
        jigAssemblyDraftDelete($pdo, $_GET['date']);
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['error' => 'Unknown request']);
    exit;
}

// ─── POST: Submit checksheet ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_checksheet_jig_assembly'])) {
    header('Content-Type: application/json');

    $checkDate = trim($_POST['check_date'] ?? '');
    $checker   = trim($_POST['checker']    ?? '');
    $itemsJson = $_POST['items']           ?? '[]';

    if (!$checkDate || !$checker) {
        echo json_encode(['success' => false, 'message' => 'Lengkapi tanggal dan checker terlebih dahulu.']);
        exit;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkDate) || strtotime($checkDate) === false) {
        echo json_encode(['success' => false, 'message' => 'Tanggal tidak valid.']);
        exit;
    }
    if ($checkDate < date('Y-m-d')) {
        echo json_encode(['success' => false, 'message' => 'Tanggal pengecekan tidak boleh mundur (backdate). Checksheet hanya bisa disubmit untuk tanggal hari ini atau setelahnya.']);
        exit;
    }

    $items = json_decode($itemsJson, true);
    if (!is_array($items) || empty($items)) {
        echo json_encode(['success' => false, 'message' => 'Tidak ada item checklist yang dikirim.']);
        exit;
    }

    if (jigAssemblyDateSubmitted($pdo, $checkDate) !== null) {
        echo json_encode([
            'success'   => false,
            'message'   => "Checksheet Jig Assembly untuk tanggal {$checkDate} sudah pernah diisi.",
            'duplicate' => true,
        ]);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("INSERT INTO jig_assembly_submissions (check_date, checker, ip_address) VALUES (?, ?, ?)");
        $stmt->execute([$checkDate, $checker, $_SERVER['REMOTE_ADDR'] ?? null]);
        $submissionId = $pdo->lastInsertId();

        $stmtDetail = $pdo->prepare(
            "INSERT INTO jig_assembly_submission_details
             (submission_id, checkpoint_id, machine_id, visual_result, actual_diameter, note)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        foreach ($items as $item) {
            if (($item['action_status'] ?? '') !== 'checked') continue;

            $result = $item['result'] ?? null;
            if (!in_array($result, ['OK', 'NG'], true)) $result = null;
            $actual = trim($item['actual_diameter'] ?? '');

            $stmtDetail->execute([
                $submissionId,
                $item['checkpoint_id'] ?? null,
                $item['machine_id']    ?? null,
                $result,
                $actual !== '' ? $actual : null,
                ($item['note'] ?? '') !== '' ? trim($item['note']) : null,
            ]);
        }

        $pdo->commit();
        jigAssemblyDraftDelete($pdo, $checkDate);

        echo json_encode(['success' => true, 'message' => "Checksheet Jig Assembly tanggal {$checkDate} berhasil disimpan."]);
    } catch (\Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan: ' . $e->getMessage()]);
    }
    exit;
}

$currentDate = date('Y-m-d');

// Status periode 3 bulanan tetap, dimulai April 2026.
$periodInfo   = jigAssemblyCurrentPeriod($pdo, $currentDate);
$periodFilled = $periodInfo['filled'];

// ─── Tanggal awal opsional dari link "Lanjutkan" di halaman Draft ──────────
$initialDate = null;
if (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) && strtotime($_GET['date']) !== false) {
    $initialDate = $_GET['date'];
}
$autoResumeDraft = $initialDate !== null && isset($_GET['resume']) && $_GET['resume'] === '1';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jig Assembly Check Sheet — Maintenance Hub</title>
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
        }

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
            border-color: #e36414;
            box-shadow: 0 0 0 3px rgba(227, 100, 20, .12);
        }

        select.form-field {
            appearance: none;
            -webkit-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
            padding-right: 30px;
        }

        .reminder-banner {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 16px;
            border-radius: 12px;
            font-size: .78rem;
            font-weight: 700;
            margin-bottom: 14px;
        }

        .reminder-banner.ok {
            background: #dcfce7;
            color: #15803d;
            border: 1.5px solid #86efac;
        }

        .reminder-banner.warn {
            background: #fef9c3;
            color: #a16207;
            border: 1.5px solid #fde047;
        }

        .unit-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            overflow: hidden;
            margin-bottom: 14px;
        }

        .unit-card-header {
            background: linear-gradient(135deg, #e36414, #c4550f);
            color: #fff;
            padding: 10px 16px;
            font-size: .82rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .item-row {
            display: grid;
            grid-template-columns: 28px 1fr 110px 110px 120px 140px 170px;
            gap: 10px;
            align-items: center;
            padding: 10px 16px;
            border-top: 1px solid #f1f5f9;
        }

        .item-row.header {
            background: #f8fafc;
            font-size: .66rem;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: .04em;
            border-top: none;
        }

        .item-no {
            font-weight: 700;
            color: #94a3b8;
            font-size: .78rem;
        }

        .item-part {
            font-size: .8rem;
            color: #1e293b;
            font-weight: 600;
        }

        .standard-cell {
            font-size: .76rem;
            font-weight: 700;
            color: #475569;
        }

        .standard-cell.visual {
            color: #94a3b8;
            font-weight: 600;
            font-style: italic;
        }

        .cp-diameter-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: .6rem;
            font-weight: 700;
            color: #b45309;
            background: #fef3c7;
            border-radius: 99px;
            padding: 2px 7px;
            margin-top: 3px;
        }

        .standard-val {
            display: block;
            font-size: .66rem;
            color: #94a3b8;
            margin-top: 2px;
        }

        .actual-input {
            width: 100%;
            padding: 6px 8px;
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            font-size: .74rem;
            outline: none;
            font-family: inherit;
        }

        .actual-input:focus {
            border-color: #e36414;
        }

        .actual-input:disabled {
            background: #f8fafc;
            color: #cbd5e1;
        }

        .action-toggle {
            display: flex;
            align-items: center;
            gap: 7px;
            cursor: pointer;
            font-size: .72rem;
            font-weight: 700;
            color: #64748b;
            user-select: none;
        }

        .action-toggle input {
            width: 16px;
            height: 16px;
            accent-color: #e36414;
            cursor: pointer;
        }

        .result-btns {
            display: flex;
            gap: 6px;
        }

        .result-btn {
            flex: 1;
            padding: 5px 0;
            border-radius: 8px;
            border: 1.5px solid #e2e8f0;
            background: #fff;
            font-size: .7rem;
            font-weight: 800;
            cursor: pointer;
            transition: all .15s;
            color: #94a3b8;
        }

        .result-btn:disabled {
            opacity: .4;
            cursor: not-allowed;
        }

        .result-btn.ok.active {
            background: #dcfce7;
            border-color: #86efac;
            color: #15803d;
        }

        .result-btn.ng.active {
            background: #fee2e2;
            border-color: #fca5a5;
            color: #dc2626;
        }

        .note-input {
            width: 100%;
            padding: 6px 10px;
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            font-size: .76rem;
            outline: none;
            font-family: inherit;
        }

        .note-input:focus {
            border-color: #e36414;
        }

        .note-input:disabled {
            background: #f8fafc;
            color: #cbd5e1;
        }

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

        .btn-primary {
            background: #e36414;
            color: #fff;
            font-weight: 700;
            font-size: .84rem;
            padding: 10px 22px;
            border-radius: 12px;
            border: none;
            cursor: pointer;
            transition: background .15s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary:hover {
            background: #c4550f;
        }

        .btn-primary:disabled {
            background: #cbd5e1;
            cursor: not-allowed;
        }

        .btn-secondary {
            background: #fff;
            color: #e36414;
            font-weight: 700;
            font-size: .84rem;
            padding: 10px 18px;
            border-radius: 12px;
            border: 1.5px solid #fed7aa;
            cursor: pointer;
            transition: background .15s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-secondary:hover {
            background: #fff7ed;
        }

        .draft-banner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 10px 14px;
            border-radius: 12px;
            font-size: .78rem;
            font-weight: 700;
            margin-top: 12px;
            background: #fef9c3;
            color: #a16207;
            border: 1.5px solid #fde047;
        }

        .draft-banner-actions {
            display: flex;
            gap: 8px;
            flex-shrink: 0;
        }

        .draft-banner-actions button {
            font-size: .72rem;
            font-weight: 700;
            padding: 6px 12px;
            border-radius: 8px;
            cursor: pointer;
            border: none;
        }

        .draft-btn-resume {
            background: #e36414;
            color: #fff;
        }

        .draft-btn-discard {
            background: #fff;
            color: #dc2626;
            border: 1.5px solid #fecaca !important;
        }

        #draft-status {
            font-weight: 600;
        }

        .units-scroll-wrapper {
            max-height: calc(100vh - 400px);
            min-height: 240px;
            overflow-y: auto;
            padding-right: 6px;
            margin-bottom: 10px;
        }

        .units-scroll-wrapper::-webkit-scrollbar {
            width: 8px;
        }

        .units-scroll-wrapper::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 8px;
        }

        .units-scroll-wrapper::-webkit-scrollbar-track {
            background: transparent;
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
            <a href="dashboard_checksheet_jig_assembly.php" class="nav-item active" title="Check Sheet">
                <i class="fas fa-clipboard-check"></i>
                <span class="nav-label">Check Sheet</span>
            </a>
            <a href="history_checksheet_jig_assembly.php" class="nav-item" title="History">
                <i class="fas fa-history"></i>
                <span class="nav-label">History</span>
            </a>
            <a href="checksheet_jig_assembly_draft.php" class="nav-item" title="Draft">
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
                    <i class="fas fa-ruler-combined text-[#e36414] text-xs"></i>
                </div>
                <div>
                    <div class="text-sm font-bold text-slate-800">Jig Assembly Check Sheet</div>
                    <div class="text-[10px] text-slate-400 font-medium">30 item jig — Machine Press 1/2/3, Divisi Manufacturing · setiap 3 bulan</div>
                </div>
            </div>
            <div class="flex items-center gap-3">
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

            <?php if ($periodFilled): ?>
                <div class="reminder-banner ok" id="reminder-ok-banner">
                    <i class="fas fa-circle-check"></i>
                    Checksheet Jig Assembly untuk periode <?= htmlspecialchars(date('d M Y', strtotime($periodInfo['period_start']))) ?> – <?= htmlspecialchars(date('d M Y', strtotime($periodInfo['period_end']))) ?> sudah diisi (terakhir: <?= htmlspecialchars(date('d M Y', strtotime($periodInfo['submission']['check_date']))) ?> oleh <?= htmlspecialchars($periodInfo['submission']['checker']) ?>). Periode berikutnya mulai <?= htmlspecialchars(date('d M Y', strtotime($periodInfo['next_period_start']))) ?>.
                </div>
            <?php else: ?>
                <div class="reminder-banner warn">
                    <i class="fas fa-bell"></i>
                    Checksheet Jig Assembly untuk periode <?= htmlspecialchars(date('d M Y', strtotime($periodInfo['period_start']))) ?> – <?= htmlspecialchars(date('d M Y', strtotime($periodInfo['period_end']))) ?> belum diisi. Pengecekan jig ini dilakukan setiap 3 bulan sekali — pastikan diisi sebelum periode ini berakhir.
                </div>
            <?php endif; ?>

            <div class="bg-white border border-slate-200 rounded-2xl p-4 mb-4">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div>
                        <label class="form-label block mb-1.5"><i class="fas fa-calendar-day text-slate-300 mr-1"></i> Tanggal Pengecekan <span class="text-red-400">*</span></label>
                        <input type="date" id="inp-check-date" class="form-field" min="<?= htmlspecialchars(date('Y-m-d')) ?>" onchange="onDateChange()">
                    </div>
                    <div>
                        <label class="form-label block mb-1.5"><i class="fas fa-user-check text-slate-300 mr-1"></i> Checker <span class="text-red-400">*</span></label>
                        <select id="sel-checker" class="form-field" onchange="validateForm(); scheduleDraftAutosave()">
                            <option value="">— Pilih Checker —</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label block mb-1.5"><i class="fas fa-search text-slate-300 mr-1"></i> Cari Mesin / Jig</label>
                        <input type="text" id="inp-search" class="form-field" placeholder="mis. TF65, Press 2..." oninput="filterUnits()">
                    </div>
                </div>
                <div id="duplicate-warning" class="hidden mt-3 text-xs font-bold text-rose-600 bg-rose-50 border border-rose-200 rounded-xl px-3 py-2">
                    <i class="fas fa-circle-exclamation mr-1"></i> <span id="duplicate-warning-text"></span>
                </div>
                <div id="draft-banner" class="draft-banner hidden">
                    <span><i class="fas fa-clock-rotate-left mr-1"></i> <span id="draft-banner-text"></span></span>
                    <span class="draft-banner-actions">
                        <button type="button" class="draft-btn-resume" onclick="resumeDraft()">Lanjutkan Draft</button>
                        <button type="button" class="draft-btn-discard" onclick="discardDraft()">Hapus Draft</button>
                    </span>
                </div>
            </div>

            <div id="units-container-wrap" class="units-scroll-wrapper">
                <div id="units-container"></div>
            </div>

            <div class="flex items-center justify-between bg-white border border-slate-200 rounded-2xl p-4 mt-2 sticky bottom-4">
                <div class="text-xs font-semibold text-slate-500">
                    <span id="progress-label">0 / 0 item sudah dicek</span>
                    <span id="draft-status" class="ml-2 text-slate-400"></span>
                </div>
                <div class="flex items-center gap-2">
                    <button id="btn-save-draft" type="button" class="btn-secondary" onclick="saveDraft(true)">
                        <i class="fas fa-floppy-disk"></i> Simpan Draft
                    </button>
                    <button id="btn-submit" class="btn-primary" onclick="submitChecksheet()" disabled>
                        <i class="fas fa-paper-plane"></i> Submit Checksheet
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div id="toast"></div>

    <script>
        let unitsData = [];
        let periodLocked = false;
        let pendingDraft = null;

        document.addEventListener('DOMContentLoaded', () => {
            document.getElementById('today-label').textContent = new Date().toLocaleDateString('id-ID', {
                weekday: 'long',
                day: 'numeric',
                month: 'long',
                year: 'numeric'
            });
            loadCheckers();
            loadItems();

            const initialDate = <?= json_encode($initialDate) ?>;
            const autoResume = <?= json_encode($autoResumeDraft) ?>;

            const dateInput = document.getElementById('inp-check-date');
            dateInput.value = initialDate || new Date().toISOString().slice(0, 10);
            onDateChange(autoResume);
        });

        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('collapsed');
            document.getElementById('main-content').classList.toggle('expanded');
        }

        function esc(str) {
            const d = document.createElement('div');
            d.textContent = str;
            return d.innerHTML;
        }

        function loadCheckers() {
            fetch('dashboard_checksheet_jig_assembly.php?ajax=checkers')
                .then(r => r.json())
                .then(names => {
                    const sel = document.getElementById('sel-checker');
                    names.forEach(n => {
                        const opt = document.createElement('option');
                        opt.value = n;
                        opt.textContent = n;
                        sel.appendChild(opt);
                    });
                });
        }

        function loadItems() {
            fetch('dashboard_checksheet_jig_assembly.php?ajax=items')
                .then(r => r.json())
                .then(units => {
                    unitsData = units;
                    renderUnits();
                });
        }

        function renderUnits() {
            const container = document.getElementById('units-container');
            container.innerHTML = '';

            unitsData.forEach(unit => {
                const card = document.createElement('div');
                card.className = 'unit-card';
                card.dataset.unitName = unit.name;

                const header = document.createElement('div');
                header.className = 'unit-card-header';
                header.innerHTML = `<i class="fas fa-cog"></i> ${esc(unit.name)}`;
                card.appendChild(header);

                const headerRow = document.createElement('div');
                headerRow.className = 'item-row header';
                headerRow.innerHTML = `<div>No</div><div>Check Point</div><div>Standard</div><div>Actual (mm)</div><div>Action</div><div>Result</div><div>Keterangan</div>`;
                card.appendChild(headerRow);

                unit.checkpoints.forEach(cp => {
                    const row = document.createElement('div');
                    row.className = 'item-row';
                    row.dataset.checkpointId = cp.id;
                    row.dataset.machineId = unit.machine_id;

                    const isVisual = cp.is_diameter != 1;
                    const standardText = cp.standard_value ? esc(cp.standard_value) : (isVisual ? 'Visual' : '—');
                    const standardCell = `<span class="standard-cell ${isVisual ? 'visual' : ''}">${standardText}</span>`;

                    const actualCell = cp.is_diameter == 1 ?
                        `<input type="text" class="actual-input" placeholder="Aktual" disabled oninput="scheduleDraftAutosave()">` :
                        `<span class="text-slate-300 text-xs">—</span>`;

                    row.innerHTML = `
                        <div class="item-no">${cp.no}</div>
                        <div>
                            <div class="item-part">${esc(cp.check_point)}</div>
                            ${cp.is_diameter == 1 ? `<span class="cp-diameter-badge"><i class="fas fa-ruler"></i> Diameter</span>` : ''}
                        </div>
                        <div>${standardCell}</div>
                        <div>${actualCell}</div>
                        <label class="action-toggle">
                            <input type="checkbox" class="chk-action" onchange="onActionToggle(this)">
                            <span>Sudah Dicek</span>
                        </label>
                        <div class="result-btns">
                            <button type="button" class="result-btn ok" disabled onclick="setResult(this,'OK')">OK</button>
                            <button type="button" class="result-btn ng" disabled onclick="setResult(this,'NG')">NG</button>
                        </div>
                        <input type="text" class="note-input" placeholder="Catatan (opsional)" disabled oninput="scheduleDraftAutosave()">
                    `;
                    card.appendChild(row);
                });

                container.appendChild(card);
            });

            updateProgress();
            if (pendingDraft) applyDraftItems(pendingDraft);
        }

        function filterUnits() {
            const q = document.getElementById('inp-search').value.trim().toLowerCase();
            document.querySelectorAll('.unit-card').forEach(card => {
                card.style.display = !q || card.dataset.unitName.toLowerCase().includes(q) ? '' : 'none';
            });
        }

        function onActionToggle(checkbox) {
            const row = checkbox.closest('.item-row');
            const checked = checkbox.checked;
            row.querySelectorAll('.result-btn').forEach(b => b.disabled = !checked);
            const actualInput = row.querySelector('.actual-input');
            if (actualInput) actualInput.disabled = !checked;
            row.querySelector('.note-input').disabled = !checked;
            if (!checked) {
                row.querySelectorAll('.result-btn').forEach(b => b.classList.remove('active'));
                row.dataset.result = '';
            }
            updateProgress();
            scheduleDraftAutosave();
        }

        function setResult(btn, value) {
            const row = btn.closest('.item-row');
            row.querySelectorAll('.result-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            row.dataset.result = value;
            validateForm();
            scheduleDraftAutosave();
        }

        function updateProgress() {
            const rows = document.querySelectorAll('.item-row:not(.header)');
            const total = rows.length;
            let checked = 0;
            rows.forEach(r => {
                if (r.querySelector('.chk-action').checked) checked++;
            });
            document.getElementById('progress-label').textContent = `${checked} / ${total} item sudah dicek`;
            validateForm();
        }

        function validateForm() {
            const btn = document.getElementById('btn-submit');
            const date = document.getElementById('inp-check-date').value;
            const checker = document.getElementById('sel-checker').value;

            if (periodLocked || !date || !checker) {
                btn.disabled = true;
                return;
            }

            const rows = document.querySelectorAll('.item-row:not(.header)');
            if (rows.length === 0) {
                btn.disabled = true;
                return;
            }

            let ready = true;
            let anyChecked = false;
            rows.forEach(row => {
                const checked = row.querySelector('.chk-action').checked;
                if (checked) {
                    anyChecked = true;
                    if (!row.dataset.result) ready = false;
                }
            });

            btn.disabled = !(ready && anyChecked);
        }

        function collectFormItems() {
            const rows = document.querySelectorAll('.item-row:not(.header)');
            const items = [];
            rows.forEach(row => {
                const checked = row.querySelector('.chk-action').checked;
                const actualInput = row.querySelector('.actual-input');
                items.push({
                    checkpoint_id: row.dataset.checkpointId,
                    machine_id: row.dataset.machineId,
                    action_status: checked ? 'checked' : '',
                    result: row.dataset.result || '',
                    actual_diameter: actualInput ? actualInput.value.trim() : '',
                    note: row.querySelector('.note-input').value.trim(),
                });
            });
            return items;
        }

        function onDateChange(autoResume) {
            const date = document.getElementById('inp-check-date').value;
            if (!date) return;

            document.getElementById('duplicate-warning').classList.add('hidden');
            document.getElementById('draft-banner').classList.add('hidden');
            periodLocked = false;
            pendingDraft = null;

            fetch(`dashboard_checksheet_jig_assembly.php?ajax=check_date&date=${date}`)
                .then(r => r.json())
                .then(res => {
                    if (res.already_filled) {
                        periodLocked = true;
                        const w = document.getElementById('duplicate-warning');
                        w.classList.remove('hidden');
                        document.getElementById('duplicate-warning-text').textContent =
                            `Tanggal ini sudah disubmit oleh ${res.detail.checker}.`;
                    } else {
                        checkDraft(date, autoResume);
                    }
                    validateForm();
                });
        }

        function checkDraft(date, autoResume) {
            fetch(`dashboard_checksheet_jig_assembly.php?ajax=get_draft&date=${date}`)
                .then(r => r.json())
                .then(res => {
                    if (!res.draft) return;
                    pendingDraft = res.draft;
                    const banner = document.getElementById('draft-banner');
                    banner.classList.remove('hidden');
                    document.getElementById('draft-banner-text').textContent =
                        `Draft tersimpan (update terakhir: ${new Date(res.draft.updated_at).toLocaleString('id-ID')})`;
                    if (autoResume) resumeDraft();
                });
        }

        function resumeDraft() {
            if (!pendingDraft) return;
            applyDraftItems(pendingDraft);
            document.getElementById('draft-banner').classList.add('hidden');
        }

        function applyDraftItems(draft) {
            if (draft.checker) document.getElementById('sel-checker').value = draft.checker;

            let items = [];
            try {
                items = JSON.parse(draft.items_json);
            } catch (e) {}

            items.forEach(it => {
                const row = document.querySelector(`.item-row[data-checkpoint-id="${it.checkpoint_id}"]`);
                if (!row) return;

                if (it.action_status === 'checked') {
                    const chk = row.querySelector('.chk-action');
                    chk.checked = true;
                    onActionToggle(chk);
                }
                if (it.result) {
                    const btn = row.querySelector(`.result-btn.${it.result.toLowerCase()}`);
                    if (btn) setResult(btn, it.result);
                }
                const actualInput = row.querySelector('.actual-input');
                if (actualInput && it.actual_diameter) actualInput.value = it.actual_diameter;
                if (it.note) row.querySelector('.note-input').value = it.note;
            });

            updateProgress();
        }

        let draftSaveTimer = null;

        function scheduleDraftAutosave() {
            clearTimeout(draftSaveTimer);
            draftSaveTimer = setTimeout(() => saveDraft(false), 1500);
        }

        function saveDraft(manual) {
            if (periodLocked) return;
            const date = document.getElementById('inp-check-date').value;
            const checker = document.getElementById('sel-checker').value;
            if (!date) return;

            const btn = document.getElementById('btn-save-draft');
            if (manual) btn.disabled = true;

            const fd = new FormData();
            fd.append('check_date', date);
            fd.append('checker', checker);
            fd.append('items', JSON.stringify(collectFormItems()));

            fetch('dashboard_checksheet_jig_assembly.php?ajax=save_draft', {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json())
                .then(res => {
                    if (manual) btn.disabled = false;
                    const statusEl = document.getElementById('draft-status');
                    if (res.success) {
                        statusEl.textContent = `· draft tersimpan ${new Date().toLocaleTimeString('id-ID')}`;
                        if (manual) showToast('Draft berhasil disimpan.', 'success');
                    } else {
                        if (manual) showToast(res.message || 'Gagal menyimpan draft.', 'error');
                        else statusEl.textContent = '';
                    }
                })
                .catch(() => {
                    if (manual) {
                        btn.disabled = false;
                        showToast('Gagal menyimpan draft (jaringan).', 'error');
                    }
                });
        }

        setInterval(() => saveDraft(false), 30000);

        window.addEventListener('beforeunload', () => {
            if (periodLocked) return;
            const date = document.getElementById('inp-check-date').value;
            if (!date) return;
            const checker = document.getElementById('sel-checker').value;
            const items = collectFormItems();
            const hasProgress = checker || items.some(i => i.action_status === 'checked');
            if (!hasProgress) return;

            const fd = new FormData();
            fd.append('check_date', date);
            fd.append('checker', checker);
            fd.append('items', JSON.stringify(items));
            navigator.sendBeacon('dashboard_checksheet_jig_assembly.php?ajax=save_draft', fd);
        });

        function discardDraft() {
            const date = document.getElementById('inp-check-date').value;
            if (!date) return;
            if (!confirm('Hapus draft tersimpan untuk tanggal ini?')) return;

            fetch(`dashboard_checksheet_jig_assembly.php?ajax=discard_draft&date=${date}`)
                .then(r => r.json())
                .then(() => {
                    document.getElementById('draft-banner').classList.add('hidden');
                    document.getElementById('draft-status').textContent = 'Draft dihapus.';
                    pendingDraft = null;
                    renderUnits();
                });
        }

        function submitChecksheet() {
            if (periodLocked) return;

            const date = document.getElementById('inp-check-date').value;
            const checker = document.getElementById('sel-checker').value;

            if (!date) {
                showToast('Pilih tanggal pengecekan terlebih dahulu.', 'error');
                return;
            }
            if (!checker) {
                showToast('Pilih checker terlebih dahulu.', 'error');
                return;
            }

            const items = collectFormItems();
            const checkedItems = items.filter(it => it.action_status === 'checked');
            if (checkedItems.length === 0) {
                showToast('Belum ada item yang dicek.', 'error');
                return;
            }
            const incomplete = checkedItems.filter(it => !it.result).length;
            if (incomplete > 0) {
                showToast(`${incomplete} item sudah dicentang "Sudah Dicek" tapi belum diisi OK/NG.`, 'error');
                return;
            }

            const btn = document.getElementById('btn-submit');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menyimpan...';

            const fd = new FormData();
            fd.append('submit_checksheet_jig_assembly', '1');
            fd.append('check_date', date);
            fd.append('checker', checker);
            fd.append('items', JSON.stringify(items));

            fetch('dashboard_checksheet_jig_assembly.php', {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        clearTimeout(draftSaveTimer);
                        showToast(res.message, 'success');
                        setTimeout(() => window.location.href = 'history_checksheet_jig_assembly.php', 1200);
                    } else {
                        showToast(res.message || 'Gagal menyimpan.', 'error');
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Checksheet';
                        if (res.duplicate) onDateChange();
                    }
                })
                .catch(() => {
                    showToast('Terjadi kesalahan jaringan.', 'error');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Checksheet';
                });
        }

        function showToast(msg, type = 'success') {
            const t = document.getElementById('toast');
            t.textContent = msg;
            t.className = `show ${type}`;
            setTimeout(() => t.classList.remove('show'), 4000);
        }
    </script>
</body>

</html>