<?php
// dashboard_checksheet_painting.php
// Checksheet bulanan untuk Department Produksi - Divisi Painting.
// TERPISAH TOTAL dari dashboard_checksheet.php: tabel sendiri
// (painting_checksheet_units / items / submissions / submission_details),
// gate key sendiri (area = 'painting'), dan siklus submit BULANAN
// (1 submission = 1 bulan, bukan 1 submission per mesin/hari).
require_once __DIR__ . '/config.php';

// ─── Gate akses: wajib unlock via checksheet_gate.php dengan key Painting ──
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['checksheet_unlocked']) || ($_SESSION['checksheet_area'] ?? '') !== 'painting') {
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['error' => 'unauthorized']);
        exit;
    }
    header('Location: checksheet_gate.php?redirect=dashboard_checksheet_painting.php');
    exit;
}

$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// ─── Helper: apakah periode (YYYY-MM) sudah ada submission-nya? ────────────
function paintingPeriodSubmitted(PDO $pdo, string $period): ?array
{
    $stmt = $pdo->prepare("SELECT id, check_date, checker, submitted_at FROM painting_checksheet_submissions WHERE period_month = ? LIMIT 1");
    $stmt->execute([$period]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// ─── AJAX Handlers ────────────────────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    if ($_GET['ajax'] === 'checkers') {
        // Sumber checker sekarang dari tabel khusus `checker_painting` (Mita, Tri, dst.),
        // bukan tabel `checkers` generik lagi. NIK tetap tersimpan di database untuk
        // audit, tapi yang dikirim ke frontend & ditampilkan di dropdown cuma nama saja.
        $rows = $pdo->query("SELECT nama FROM checker_painting WHERE is_active = 1 ORDER BY nama")->fetchAll();
        echo json_encode(array_column($rows, 'nama'));
        exit;
    }

    // Ambil master unit + item, dikelompokkan per unit
    if ($_GET['ajax'] === 'items') {
        $units = $pdo->query("SELECT id, name FROM painting_checksheet_units WHERE is_active = 1 ORDER BY sort_order, id")->fetchAll();
        $items = $pdo->query("SELECT id, unit_id, no, part FROM painting_checksheet_items WHERE is_active = 1 ORDER BY unit_id, sort_order, no")->fetchAll();

        $itemsByUnit = [];
        foreach ($items as $it) {
            $itemsByUnit[$it['unit_id']][] = $it;
        }
        foreach ($units as &$u) {
            $u['items'] = $itemsByUnit[$u['id']] ?? [];
        }
        unset($u);

        echo json_encode($units);
        exit;
    }

    // Cek apakah bulan yang dipilih sudah pernah disubmit
    if ($_GET['ajax'] === 'check_period' && isset($_GET['period'])) {
        $existing = paintingPeriodSubmitted($pdo, $_GET['period']);
        echo json_encode([
            'already_filled' => $existing !== null,
            'detail'         => $existing,
        ]);
        exit;
    }

    echo json_encode(['error' => 'Unknown request']);
    exit;
}

// ─── POST: Submit checksheet painting ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_checksheet_painting'])) {
    header('Content-Type: application/json');

    $checkDate = trim($_POST['check_date'] ?? '');
    $checker   = trim($_POST['checker']    ?? '');
    $itemsJson = $_POST['items']           ?? '[]';

    if (!$checkDate || !$checker) {
        echo json_encode(['success' => false, 'message' => 'Lengkapi tanggal dan checker terlebih dahulu.']);
        exit;
    }

    $ts = strtotime($checkDate);
    if ($ts === false) {
        echo json_encode(['success' => false, 'message' => 'Tanggal tidak valid.']);
        exit;
    }
    $periodMonth = date('Y-m', $ts);

    $items = json_decode($itemsJson, true);
    if (!is_array($items) || empty($items)) {
        echo json_encode(['success' => false, 'message' => 'Tidak ada item checklist yang dikirim.']);
        exit;
    }

    // ─── Cek duplikasi server-side: 1 bulan cuma boleh 1 submission ─────────
    if (paintingPeriodSubmitted($pdo, $periodMonth) !== null) {
        echo json_encode([
            'success'   => false,
            'message'   => "Checksheet Painting untuk bulan {$periodMonth} sudah pernah diisi.",
            'duplicate' => true,
        ]);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "INSERT INTO painting_checksheet_submissions
             (period_month, check_date, checker, ip_address)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$periodMonth, $checkDate, $checker, $_SERVER['REMOTE_ADDR'] ?? null]);
        $submissionId = $pdo->lastInsertId();

        $stmtDetail = $pdo->prepare(
            "INSERT INTO painting_checksheet_submission_details
             (submission_id, item_id, unit_name, no, part, action_status, result, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($items as $item) {
            $actionStatus = ($item['action_status'] ?? 'unchecked') === 'checked' ? 'checked' : 'unchecked';
            $result       = $item['result'] ?? null;
            if (!in_array($result, ['OK', 'NG'], true)) $result = null;

            $stmtDetail->execute([
                $submissionId,
                $item['item_id']   ?? null,
                $item['unit_name'] ?? '',
                $item['no']        ?? 0,
                $item['part']      ?? '',
                $actionStatus,
                $result,
                ($item['note'] ?? '') !== '' ? trim($item['note']) : null,
            ]);
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => "Checksheet Painting bulan {$periodMonth} berhasil disimpan."]);
    } catch (\Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan: ' . $e->getMessage()]);
    }
    exit;
}

// ─── Data untuk reminder banner ────────────────────────────────────────────────
$currentPeriod    = date('Y-m');
$currentSubmitted = paintingPeriodSubmitted($pdo, $currentPeriod) !== null;
$dayOfMonth       = (int)date('j');
$daysInMonth      = (int)date('t');
$daysLeft         = $daysInMonth - $dayOfMonth;
// Ambang "urgent": 7 hari terakhir bulan berjalan dan belum diisi
$reminderUrgent   = !$currentSubmitted && $daysLeft <= 7;
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painting Check Sheet — Maintenance Hub</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: #f1f5f9;
        }

        /* ── Sidebar (sama seperti checksheet lama, biar konsisten) ── */
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
            background: linear-gradient(135deg, #0f766e, #0d5c56);
            color: #fff;
            box-shadow: 0 4px 12px rgba(15, 118, 110, .35);
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

        /* ── Topbar ── */
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

        /* ── Form fields ── */
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
            border-color: #0f766e;
            box-shadow: 0 0 0 3px rgba(15, 118, 110, .12);
        }

        select.form-field {
            appearance: none;
            -webkit-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
            padding-right: 30px;
        }

        /* ── Reminder banner ── */
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

        .reminder-banner.urgent {
            background: #fee2e2;
            color: #dc2626;
            border: 1.5px solid #fca5a5;
        }

        /* ── Unit card ── */
        .unit-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            overflow: hidden;
            margin-bottom: 14px;
        }

        .unit-card-header {
            background: linear-gradient(135deg, #0f766e, #0d5c56);
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
            grid-template-columns: 34px 1fr 130px 150px 220px;
            gap: 10px;
            align-items: center;
            padding: 10px 16px;
            border-top: 1px solid #f1f5f9;
        }

        .item-row.header {
            background: #f8fafc;
            font-size: .68rem;
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
            font-size: .82rem;
            color: #1e293b;
            font-weight: 600;
        }

        .action-toggle {
            display: flex;
            align-items: center;
            gap: 7px;
            cursor: pointer;
            font-size: .74rem;
            font-weight: 700;
            color: #64748b;
            user-select: none;
        }

        .action-toggle input {
            width: 16px;
            height: 16px;
            accent-color: #0f766e;
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
            font-size: .72rem;
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
        }

        .note-input:focus {
            border-color: #0f766e;
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
            background: #0f766e;
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
            background: #0d5c56;
        }

        .btn-primary:disabled {
            background: #cbd5e1;
            cursor: not-allowed;
        }

        /* ── Area scroll khusus item checklist (pre-treatment, setting room, dll.) ──
           Tanggal pengecekan / checker / periode di atas, dan progress+submit di
           bawah, SENGAJA tidak ikut ke dalam wrapper ini supaya selalu terlihat
           tanpa perlu ikut discroll. ── */
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
    </style>
</head>

<body>

    <aside id="sidebar" class="collapsed">
        <div class="brand">
            <div class="brand-icon-wrap">
                <div class="w-8 h-8 rounded-lg bg-[#0f766e] flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-spray-can text-white text-xs"></i>
                </div>
                <div class="brand-text">
                    <div class="text-white text-xs font-bold leading-tight">Maintenance Hub</div>
                    <div class="text-slate-500 text-[10px] font-medium">Painting Check Sheet</div>
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
            <a href="dashboard_checksheet_painting.php" onclick="navigateTo(event,'dashboard_checksheet_painting.php')" class="nav-item active" title="Check Sheet">
                <i class="fas fa-clipboard-check"></i>
                <span class="nav-label">Check Sheet</span>
            </a>
            <a href="history_checksheet_painting.php" onclick="navigateTo(event,'history_checksheet_painting.php')" class="nav-item" title="History">
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
            <div class="flex items-center gap-2">
                <div class="w-7 h-7 rounded-lg bg-[#e6f5f3] flex items-center justify-center">
                    <i class="fas fa-spray-can text-[#0f766e] text-xs"></i>
                </div>
                <div>
                    <div class="text-sm font-bold text-slate-800">Painting Monthly Check Sheet</div>
                    <div class="text-[10px] text-slate-400 font-medium">Produksi — Divisi Painting · Pengecekan bulanan</div>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <span class="info-chip"><i class="far fa-calendar text-teal-500"></i> <span id="today-label"></span></span>
                <button onclick="document.getElementById('back-confirm-overlay').style.display='flex'"
                    class="info-chip" style="cursor:pointer;border-color:#fecaca;color:#dc2626;background:#fef2f2;" title="Kembali & kunci halaman">
                    <i class="fas fa-arrow-left"></i> Kembali
                </button>
            </div>
        </div>

        <div class="p-4">

            <?php if ($currentSubmitted): ?>
                <div class="reminder-banner ok">
                    <i class="fas fa-circle-check"></i>
                    Checksheet Painting bulan <?= htmlspecialchars(date('F Y', strtotime($currentPeriod . '-01'))) ?> sudah diisi. Terima kasih!
                </div>
            <?php elseif ($reminderUrgent): ?>
                <div class="reminder-banner urgent">
                    <i class="fas fa-triangle-exclamation"></i>
                    Checksheet Painting bulan <?= htmlspecialchars(date('F Y', strtotime($currentPeriod . '-01'))) ?> <b>belum diisi</b> — tersisa <?= $daysLeft ?> hari lagi sebelum akhir bulan.
                </div>
            <?php else: ?>
                <div class="reminder-banner warn">
                    <i class="fas fa-bell"></i>
                    Checksheet Painting bulan <?= htmlspecialchars(date('F Y', strtotime($currentPeriod . '-01'))) ?> belum diisi. Jangan lupa dikerjakan sebelum akhir bulan.
                </div>
            <?php endif; ?>

            <div class="bg-white border border-slate-200 rounded-2xl p-4 mb-4">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div>
                        <label class="form-label block mb-1.5"><i class="fas fa-calendar-day text-slate-300 mr-1"></i> Tanggal Pengecekan <span class="text-red-400">*</span></label>
                        <input type="date" id="inp-check-date" class="form-field" onchange="onDateChange()">
                    </div>
                    <div>
                        <label class="form-label block mb-1.5"><i class="fas fa-user-check text-slate-300 mr-1"></i> Checker <span class="text-red-400">*</span></label>
                        <select id="sel-checker" class="form-field" onchange="validateForm()">
                            <option value="">— Pilih Checker —</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label block mb-1.5"><i class="fas fa-calendar-week text-slate-300 mr-1"></i> Periode</label>
                        <input type="text" id="inp-period-display" class="form-field" readonly style="cursor:default;background:#f8fafc;color:#64748b;">
                    </div>
                </div>
                <div id="period-warning" class="hidden mt-3 text-xs font-bold text-rose-600 bg-rose-50 border border-rose-200 rounded-xl px-3 py-2">
                    <i class="fas fa-circle-exclamation mr-1"></i> <span id="period-warning-text"></span>
                </div>
            </div>

            <div id="units-container-wrap" class="units-scroll-wrapper">
                <div id="units-container"></div>
            </div>

            <div class="flex items-center justify-between bg-white border border-slate-200 rounded-2xl p-4 mt-2 sticky bottom-4">
                <div class="text-xs font-semibold text-slate-500">
                    <span id="progress-label">0 / 0 item sudah dicek</span>
                </div>
                <button id="btn-submit" class="btn-primary" onclick="submitChecksheet()" disabled>
                    <i class="fas fa-paper-plane"></i> Submit Checksheet
                </button>
            </div>
        </div>
    </div>

    <div id="toast"></div>

    <!-- Konfirmasi tombol Back — kembali ke checksheet_gate & kunci akses area ini -->
    <div id="back-confirm-overlay" style="position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:9998;display:none;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:16px;padding:22px 24px;max-width:360px;width:90%;box-shadow:0 20px 50px rgba(0,0,0,.25);">
            <div class="flex items-center gap-2 mb-2">
                <i class="fas fa-lock text-[#0f766e]"></i>
                <span class="text-sm font-extrabold text-slate-800">Kembali ke Menu Utama?</span>
            </div>
            <p class="text-xs text-slate-500 mb-4 leading-relaxed">
                Halaman Checksheet Painting akan terkunci. Untuk masuk lagi, Anda perlu memasukkan key akses dari halaman Checksheet Gate.
            </p>
            <div class="flex justify-end gap-2">
                <button onclick="document.getElementById('back-confirm-overlay').style.display='none'"
                    class="px-3 py-2 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100">Batal</button>
                <button onclick="window.location.href='checksheet_gate.php?logout=1'"
                    class="px-3 py-2 rounded-xl text-xs font-bold text-white" style="background:#0f766e;">Ya, Kembali &amp; Kunci</button>
            </div>
        </div>
    </div>

    <script>
        let unitsData = [];
        let periodLocked = false;

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

        function showToast(msg, type = 'success') {
            const t = document.getElementById('toast');
            t.textContent = '';
            const icon = document.createElement('i');
            icon.className = type === 'success' ? 'fas fa-circle-check' : 'fas fa-circle-exclamation';
            t.appendChild(icon);
            t.appendChild(document.createTextNode(' ' + msg));
            t.className = 'show ' + type;
            clearTimeout(window.__toastTimer);
            window.__toastTimer = setTimeout(() => {
                t.className = '';
            }, 3500);
        }

        function esc(str) {
            const d = document.createElement('div');
            d.textContent = str ?? '';
            return d.innerHTML;
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
            }

            document.getElementById('today-label').textContent =
                new Date().toLocaleDateString('id-ID', {
                    weekday: 'long',
                    day: 'numeric',
                    month: 'long',
                    year: 'numeric'
                });

            const today = new Date().toISOString().slice(0, 10);
            document.getElementById('inp-check-date').value = today;

            loadCheckers();
            loadItems();
            onDateChange();
        });

        function loadCheckers() {
            fetch('dashboard_checksheet_painting.php?ajax=checkers')
                .then(r => r.json())
                .then(list => {
                    const sel = document.getElementById('sel-checker');
                    list.forEach(name => {
                        const opt = document.createElement('option');
                        opt.value = name;
                        opt.textContent = name;
                        sel.appendChild(opt);
                    });
                });
        }

        function onDateChange() {
            const val = document.getElementById('inp-check-date').value;
            if (!val) return;
            const period = val.slice(0, 7); // YYYY-MM
            const d = new Date(val + 'T00:00:00');
            document.getElementById('inp-period-display').value =
                d.toLocaleDateString('id-ID', {
                    month: 'long',
                    year: 'numeric'
                });

            fetch('dashboard_checksheet_painting.php?ajax=check_period&period=' + encodeURIComponent(period))
                .then(r => r.json())
                .then(res => {
                    const warnBox = document.getElementById('period-warning');
                    const warnText = document.getElementById('period-warning-text');
                    if (res.already_filled) {
                        periodLocked = true;
                        warnText.textContent = `Bulan ${period} sudah pernah disubmit oleh ${res.detail.checker} pada ${res.detail.submitted_at}. Cek History untuk melihat detailnya.`;
                        warnBox.classList.remove('hidden');
                    } else {
                        periodLocked = false;
                        warnBox.classList.add('hidden');
                    }
                    validateForm();
                });
        }

        function loadItems() {
            fetch('dashboard_checksheet_painting.php?ajax=items')
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

                const header = document.createElement('div');
                header.className = 'unit-card-header';
                header.innerHTML = `<i class="fas fa-layer-group"></i> ${esc(unit.name)}`;
                card.appendChild(header);

                const headerRow = document.createElement('div');
                headerRow.className = 'item-row header';
                headerRow.innerHTML = `<div>No</div><div>Part yang Dicek</div><div>Action</div><div>Result</div><div>Keterangan</div>`;
                card.appendChild(headerRow);

                unit.items.forEach(item => {
                    const row = document.createElement('div');
                    row.className = 'item-row';
                    row.dataset.itemId = item.id;
                    row.dataset.unitName = unit.name;
                    row.dataset.no = item.no;
                    row.dataset.part = item.part;

                    row.innerHTML = `
                        <div class="item-no">${item.no}</div>
                        <div class="item-part">${esc(item.part)}</div>
                        <label class="action-toggle">
                            <input type="checkbox" class="chk-action" onchange="onActionToggle(this)">
                            <span class="chk-action-label">Sudah Dicek</span>
                        </label>
                        <div class="result-btns">
                            <button type="button" class="result-btn ok" disabled onclick="setResult(this,'OK')">OK</button>
                            <button type="button" class="result-btn ng" disabled onclick="setResult(this,'NG')">NG</button>
                        </div>
                        <input type="text" class="note-input" placeholder="Catatan (opsional)" disabled>
                    `;
                    card.appendChild(row);
                });

                container.appendChild(card);
            });

            updateProgress();
        }

        function onActionToggle(checkbox) {
            const row = checkbox.closest('.item-row');
            const checked = checkbox.checked;
            row.querySelectorAll('.result-btn').forEach(b => b.disabled = !checked);
            row.querySelector('.note-input').disabled = !checked;
            if (!checked) {
                row.querySelectorAll('.result-btn').forEach(b => b.classList.remove('active'));
                row.dataset.result = '';
            }
            updateProgress();
        }

        function setResult(btn, value) {
            const row = btn.closest('.item-row');
            row.querySelectorAll('.result-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            row.dataset.result = value;
            validateForm();
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

        // ── Validasi menyeluruh: submit HANYA aktif kalau tanggal, checker, DAN
        // seluruh item checklist (Action + Result) sudah terisi lengkap, serta
        // periode yang dipilih belum pernah disubmit sebelumnya. ──
        function validateForm() {
            const btn = document.getElementById('btn-submit');
            const checkDate = document.getElementById('inp-check-date').value;
            const checker = document.getElementById('sel-checker').value;

            if (periodLocked || !checkDate || !checker) {
                btn.disabled = true;
                return;
            }

            const rows = document.querySelectorAll('.item-row:not(.header)');
            if (rows.length === 0) {
                btn.disabled = true;
                return;
            }

            let allComplete = true;
            rows.forEach(row => {
                const isChecked = row.querySelector('.chk-action').checked;
                const result = row.dataset.result || '';
                if (!isChecked || !result) allComplete = false;
            });

            btn.disabled = !allComplete;
        }

        function submitChecksheet() {
            if (periodLocked) return;

            const checkDate = document.getElementById('inp-check-date').value;
            const checker = document.getElementById('sel-checker').value;

            if (!checkDate) {
                showToast('Pilih tanggal pengecekan terlebih dahulu.', 'error');
                return;
            }
            if (!checker) {
                showToast('Pilih checker terlebih dahulu.', 'error');
                return;
            }

            const rows = document.querySelectorAll('.item-row:not(.header)');
            const items = [];
            let incomplete = 0;

            rows.forEach(row => {
                const isChecked = row.querySelector('.chk-action').checked;
                const result = row.dataset.result || null;
                const note = row.querySelector('.note-input').value.trim();

                if (isChecked && !result) incomplete++;

                items.push({
                    item_id: row.dataset.itemId,
                    unit_name: row.dataset.unitName,
                    no: row.dataset.no,
                    part: row.dataset.part,
                    action_status: isChecked ? 'checked' : 'unchecked',
                    result: isChecked ? result : null,
                    note: isChecked ? note : ''
                });
            });

            if (incomplete > 0) {
                showToast(`${incomplete} item ditandai "Sudah Dicek" tapi Result-nya belum dipilih (OK/NG).`, 'error');
                return;
            }

            const uncheckedCount = items.filter(i => i.action_status === 'unchecked').length;
            if (uncheckedCount > 0) {
                if (!confirm(`Masih ada ${uncheckedCount} item yang belum dicek. Tetap submit checksheet bulan ini?`)) return;
            }

            const btn = document.getElementById('btn-submit');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menyimpan...';

            const fd = new FormData();
            fd.append('submit_checksheet_painting', '1');
            fd.append('check_date', checkDate);
            fd.append('checker', checker);
            fd.append('items', JSON.stringify(items));

            fetch('dashboard_checksheet_painting.php', {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        showToast(res.message, 'success');
                        setTimeout(() => window.location.href = 'history_checksheet_painting.php', 1200);
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
    </script>
</body>

</html>