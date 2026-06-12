<?php
// dashboard_report.php
session_start();
require_once __DIR__ . '/config.php';

$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (!isset($_SESSION['user_id'])) {
    // Jika AJAX, kembalikan JSON error agar JS bisa handle
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'session_expired']);
        exit;
    }
    header('Location: index.php');
    exit;
}
$reportedBy = $_SESSION['username'] ?? 'Unknown';

// ─── AJAX ─────────────────────────────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    if ($_GET['ajax'] === 'technicians') {
        $rows = $pdo->query("SELECT id, name FROM technician WHERE is_active = 1 ORDER BY name")->fetchAll();
        echo json_encode($rows);
        exit;
    }
    if ($_GET['ajax'] === 'departments') {
        $rows = $pdo->query("SELECT DISTINCT department FROM machine_list ORDER BY department")->fetchAll();
        echo json_encode(array_column($rows, 'department'));
        exit;
    }
    if ($_GET['ajax'] === 'lines' && isset($_GET['department'])) {
        $stmt = $pdo->prepare("SELECT DISTINCT `line` FROM machine_list WHERE department = ? ORDER BY `line`");
        $stmt->execute([$_GET['department']]);
        echo json_encode(array_column($stmt->fetchAll(), 'line'));
        exit;
    }
    if ($_GET['ajax'] === 'ops' && isset($_GET['department'], $_GET['line'])) {
        // Power House tidak memiliki OP — langsung kembalikan ['-']
        if (strtoupper(trim($_GET['department'])) === 'POWER HOUSE') {
            echo json_encode(['-']);
            exit;
        }
        $stmt = $pdo->prepare("SELECT DISTINCT op FROM machine_list WHERE department = ? AND `line` = ? ORDER BY op");
        $stmt->execute([$_GET['department'], $_GET['line']]);
        $ops = array_column($stmt->fetchAll(), 'op');
        echo json_encode(empty($ops) ? ['-'] : $ops);
        exit;
    }
    if ($_GET['ajax'] === 'machine_list' && isset($_GET['department'], $_GET['line'], $_GET['op'])) {
        $stmt = $pdo->prepare("SELECT machine_name, machine_type FROM machine_list WHERE department = ? AND `line` = ? AND op = ? ORDER BY machine_name");
        $stmt->execute([$_GET['department'], $_GET['line'], $_GET['op']]);
        echo json_encode($stmt->fetchAll());
        exit;
    }
    echo json_encode(['error' => 'Unknown request']);
    exit;
}

// ─── POST: Submit ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_report'])) {
    header('Content-Type: application/json');

    $dept            = trim($_POST['department']      ?? '');
    $line            = trim($_POST['line']            ?? '');
    $op              = trim($_POST['op']              ?? '-');
    $machineName     = trim($_POST['machine_name']    ?? '');
    $machineType     = trim($_POST['machine_type']    ?? '');
    $reportDate      = trim($_POST['report_date']     ?? '');
    $startDate       = trim($_POST['start_date']      ?? '');
    $startTime       = trim($_POST['start_time']      ?? '');
    $finishDate      = trim($_POST['finish_date']     ?? '');
    $finishTime      = trim($_POST['finish_time']     ?? '');
    $pic             = trim($_POST['pic']             ?? '');
    $problem         = trim($_POST['problem']         ?? '');
    $action          = trim($_POST['action']          ?? '');
    $reportedBy      = trim($_POST['reported_by']     ?? '');
    $durationMinutes = isset($_POST['duration_minutes']) && $_POST['duration_minutes'] !== ''
        ? (int)$_POST['duration_minutes'] : null;

    if (!$dept || !$line || !$machineName || !$startTime || !$pic || !$problem || !$action) {
        echo json_encode(['success' => false, 'message' => 'Lengkapi semua field wajib.']);
        exit;
    }

    $startDatetime  = $startDate . ' ' . $startTime . ':00';
    $finishDatetime = ($finishDate && $finishTime) ? $finishDate . ' ' . $finishTime . ':00' : null;

    try {
        $stmt = $pdo->prepare("
            INSERT INTO e_reports
              (department, `line`, op, machine_name, machine_type, report_date,
               repair_start, repair_finish, duration_minutes, reported_by, pic, problem, action, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $dept,
            $line,
            $op,
            $machineName,
            $machineType,
            $reportDate,
            $startDatetime,
            $finishDatetime,
            $durationMinutes,
            $reportedBy,
            $pic,
            $problem,
            $action,
        ]);
        echo json_encode(['success' => true, 'message' => 'E-Report berhasil disimpan.']);
    } catch (\Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan: ' . $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-Report — Maintenance Hub</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #f1f5f9;
        }

        /* ── Sidebar ─────────────────────────────────────────────────────────── */
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

        /* ── Main ────────────────────────────────────────────────────────────── */
        #main-content {
            margin-left: 56px;
            min-height: 100vh;
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
        }

        .info-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 5px 11px;
            font-size: .75rem;
            font-weight: 600;
            color: #475569;
        }

        /* ── Form ────────────────────────────────────────────────────────────── */
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
            border-color: #fb8b24;
            box-shadow: 0 0 0 3px rgba(249, 115, 22, .12);
        }

        .form-field[readonly],
        .form-field:disabled {
            background: #f8fafc;
            color: #64748b;
        }

        .form-field:disabled {
            cursor: not-allowed;
        }

        select.form-field {
            appearance: none;
            -webkit-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
            padding-right: 30px;
        }

        textarea.form-field {
            resize: vertical;
        }

        .section-heading {
            font-size: .7rem;
            font-weight: 800;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: .08em;
            padding: 10px 16px 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .left-panel {
            background: #fff;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            overflow-y: auto;
            padding: 0 0 16px;
            flex-shrink: 0;
        }

        .right-panel {
            background: #fff;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .btn-submit {
            width: 100%;
            padding: 10px;
            border-radius: 12px;
            border: none;
            background: #e2e8f0;
            color: #94a3b8;
            font-size: .85rem;
            font-weight: 800;
            cursor: not-allowed;
            transition: background .2s, color .2s, transform .1s, box-shadow .2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-family: inherit;
        }

        .btn-submit.ready {
            background: linear-gradient(135deg, #fb8b24, #d9721a);
            color: #fff;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(251, 139, 36, .35);
        }

        .btn-submit.ready:hover {
            opacity: .92;
        }

        .btn-submit.ready:active {
            transform: scale(.98);
        }

        /* ── Toast ───────────────────────────────────────────────────────────── */
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
    </style>
</head>

<body>
    <aside id="sidebar" class="collapsed">
        <div class="brand">
            <div class="brand-icon-wrap">
                <div style="width:32px;height:32px;background:linear-gradient(135deg,#fb8b24,#d9721a);border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas fa-file-medical-alt" style="color:#fff;font-size:.9rem;"></i>
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
            <a href="dashboard_report.php" class="nav-item active" title="E-Report">
                <i class="fas fa-file-medical-alt"></i>
                <span class="nav-label">E-Report</span>
            </a>
            <a href="history_report.php" class="nav-item" title="History Report">
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
                    <i class="fas fa-file-medical-alt text-xs" style="color:#fb8b24;"></i>
                </div>
                <div>
                    <div class="text-sm font-bold text-slate-800">E-Report</div>
                    <div class="text-[10px] text-slate-400 font-medium">Input laporan kerusakan & perbaikan mesin</div>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <span class="info-chip"><i class="far fa-calendar" style="color:#fb8b24;"></i> <span id="today-label"></span></span>
                <div class="flex items-center gap-2 bg-slate-100 px-3 py-1.5 rounded-xl">
                    <div class="w-6 h-6 rounded-full flex items-center justify-center flex-shrink-0" style="background:#fb8b24;">
                        <i class="fas fa-user text-white" style="font-size:.65rem;"></i>
                    </div>
                    <span class="text-sm font-bold text-slate-700"><?= htmlspecialchars($reportedBy) ?></span>
                </div>
                <a href="logout_user.php" onclick="return confirm('Apakah Anda yakin ingin keluar?')"
                    class="bg-red-100 hover:bg-red-200 text-red-600 px-4 py-1.5 rounded-xl font-bold transition-all flex items-center gap-2 text-sm">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>

        <div class="p-4" style="height:calc(100vh - 58px);overflow:hidden;">
            <div class="flex gap-4 h-full">

                <!-- ═══ LEFT PANEL ═══ -->
                <div class="left-panel" style="width:272px;">
                    <div class="section-heading"><i class="fas fa-sliders-h text-[#fb8b24]"></i> Identitas Mesin</div>
                    <div class="space-y-3 px-4">

                        <div>
                            <label class="form-label block mb-1.5">
                                <i class="fas fa-building text-slate-300 mr-1"></i> Department <span class="text-red-400">*</span>
                            </label>
                            <select id="sel-dept" class="form-field" onchange="loadLines()">
                                <option value="">— Pilih Department —</option>
                            </select>
                        </div>

                        <div>
                            <label class="form-label block mb-1.5">
                                <i class="fas fa-calendar-day text-slate-300 mr-1"></i> Tanggal
                                <span class="text-slate-300 font-normal normal-case text-[10px]">(otomatis)</span>
                            </label>
                            <input type="hidden" id="inp-tanggal">
                            <input type="text" id="inp-tanggal-display" class="form-field" readonly style="cursor:default;" tabindex="-1">
                        </div>

                        <div style="height:1px;background:#f1f5f9;"></div>

                        <div>
                            <label class="form-label block mb-1.5">
                                <i class="fas fa-layer-group text-slate-300 mr-1"></i> Line <span class="text-red-400">*</span>
                            </label>
                            <select id="sel-line" class="form-field" onchange="loadOps()" disabled>
                                <option value="">— Pilih Line —</option>
                            </select>
                        </div>

                        <div>
                            <label class="form-label block mb-1.5">
                                <i class="fas fa-cog text-slate-300 mr-1"></i> OP
                            </label>
                            <select id="sel-op" class="form-field" onchange="loadMachines()" disabled>
                                <option value="">— Pilih OP —</option>
                            </select>
                        </div>

                        <div>
                            <label class="form-label block mb-1.5">
                                <i class="fas fa-tag text-slate-300 mr-1"></i> Machine Type
                                <span class="text-slate-300 font-normal normal-case text-[10px]">(otomatis)</span>
                            </label>
                            <input type="text" id="inp-type" class="form-field" placeholder="Terisi otomatis" readonly>
                        </div>

                        <div>
                            <label class="form-label block mb-1.5">
                                <i class="fas fa-industry text-slate-300 mr-1"></i> Nama Mesin <span class="text-red-400">*</span>
                            </label>
                            <div id="machine-field-container">
                                <input type="text" id="inp-mesin" class="form-field" placeholder="— Otomatis terisi —" readonly>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- ═══ RIGHT PANEL ═══ -->
                <div class="flex-1 min-w-0">
                    <div class="right-panel h-full">

                        <div class="px-5 py-3 border-b border-slate-100 flex items-center gap-2.5 flex-shrink-0">
                            <div class="w-7 h-7 rounded-lg flex items-center justify-center" style="background:#1e293b;">
                                <i class="fas fa-file-medical-alt text-white text-xs"></i>
                            </div>
                            <div>
                                <div class="text-sm font-bold text-slate-800">Form E-Report</div>
                                <div class="text-[11px] text-slate-400 font-medium">Isi detail kerusakan dan tindakan perbaikan</div>
                            </div>
                        </div>

                        <div class="flex-1 overflow-y-auto p-5">
                            <div class="grid grid-cols-3 gap-x-5 gap-y-4 max-w-3xl">

                                <!-- Repair Start -->
                                <div class="col-span-2">
                                    <label class="form-label block mb-1.5">
                                        <i class="fas fa-play-circle text-slate-300 mr-1"></i> Repair Start <span class="text-red-400">*</span>
                                    </label>
                                    <div class="flex gap-2">
                                        <input type="date" id="inp-start-date" class="form-field" style="flex:1;" oninput="calcDuration()">
                                        <input type="time" id="inp-start-time" class="form-field" style="flex:1;" oninput="calcDuration(); checkAllFieldsFilled();">
                                    </div>
                                    <div class="text-[10px] text-slate-400 mt-1">Tanggal & jam diisi manual</div>
                                </div>

                                <!-- Repair Finish -->
                                <div class="col-span-2">
                                    <label class="form-label block mb-1.5">
                                        <i class="fas fa-stop-circle text-slate-300 mr-1"></i> Repair Finish
                                    </label>
                                    <div class="flex gap-2">
                                        <input type="date" id="inp-finish-date" class="form-field" style="flex:1;" oninput="calcDuration()">
                                        <input type="time" id="inp-finish-time" class="form-field" style="flex:1;" oninput="calcDuration()">
                                    </div>
                                    <div class="text-[10px] text-slate-400 mt-1">Tanggal & jam diisi manual (opsional)</div>
                                </div>

                                <!-- Durasi + Reported By + PIC — 3 kolom dalam 1 baris -->
                                <div>
                                    <label class="form-label block mb-1.5">
                                        <i class="fas fa-clock text-slate-300 mr-1"></i> Durasi
                                        <span class="text-slate-300 font-normal normal-case text-[10px]">(otomatis)</span>
                                    </label>
                                    <input type="text" id="inp-duration-display" class="form-field" readonly
                                        placeholder="—" style="cursor:default;font-variant-numeric:tabular-nums;">
                                    <input type="hidden" id="inp-duration-minutes">
                                    <div class="text-[10px] text-slate-400 mt-1">Dari Start &amp; Finish</div>
                                </div>

                                <!-- Reported By -->
                                <div>
                                    <label class="form-label block mb-1.5">
                                        <i class="fas fa-user text-slate-300 mr-1"></i> Reported By
                                        <span class="text-slate-300 font-normal normal-case text-[10px]">(otomatis)</span>
                                    </label>
                                    <input type="text" id="inp-reported-by" class="form-field"
                                        value="<?= htmlspecialchars($reportedBy) ?>" readonly>
                                </div>

                                <!-- PIC / Technician -->
                                <div>
                                    <label class="form-label block mb-1.5">
                                        <i class="fas fa-user-cog text-slate-300 mr-1"></i> PIC / Technician <span class="text-red-400">*</span>
                                    </label>
                                    <select id="inp-pic" class="form-field">
                                        <option value="">— Pilih Teknisi —</option>
                                    </select>
                                </div>

                                <!-- Problem / Alarm — full width -->
                                <div class="col-span-2">
                                    <label class="form-label block mb-1.5">
                                        <i class="fas fa-exclamation-triangle text-slate-300 mr-1"></i> Problem / Alarm <span class="text-red-400">*</span>
                                    </label>
                                    <textarea id="inp-problem" class="form-field"
                                        placeholder="Deskripsi masalah atau kode alarm yang muncul..."
                                        style="min-height:80px;"></textarea>
                                </div>

                                <!-- Action — full width, bigger -->
                                <div class="col-span-2">
                                    <label class="form-label block mb-1.5">
                                        <i class="fas fa-tools text-slate-300 mr-1"></i> Action / Perbaikan <span class="text-red-400">*</span>
                                    </label>
                                    <textarea id="inp-action" class="form-field"
                                        placeholder="Jelaskan langkah-langkah perbaikan yang dilakukan secara detail. Sertakan part yang diganti, settingan yang diubah, hasil pengujian, dan catatan penting lainnya..."
                                        style="min-height:130px;"></textarea>
                                </div>

                            </div>
                        </div>

                        <!-- Submit -->
                        <div class="px-5 py-4 border-t border-slate-100 flex-shrink-0">
                            <button class="btn-submit" id="btn-submit" disabled>
                                <i class="fas fa-paper-plane"></i> Submit E-Report
                            </button>
                        </div>

                    </div>
                </div>

            </div>
        </div>
    </div>

    <div id="toast"></div>

    <script>
        const BASE = '<?= basename(__FILE__) ?>';

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

        (function() {
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
        })();

        // ── Date init ─────────────────────────────────────────────────────────────────
        const today = new Date();
        const todayISO = today.toISOString().split('T')[0];
        const todayFmt = today.toLocaleDateString('id-ID', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric'
        });

        document.getElementById('inp-tanggal').value = todayISO;
        document.getElementById('inp-tanggal-display').value = todayFmt;
        document.getElementById('today-label').textContent = today.toLocaleDateString('id-ID', {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });

        // ── Helper: get current machine name (adaptive: text input or select dropdown) ──
        function isMachineDropdownActive() {
            return !!document.getElementById('sel-mesin-dropdown');
        }

        function getCurrentMachineName() {
            if (isMachineDropdownActive()) {
                return document.getElementById('sel-mesin-dropdown')?.value || '';
            }
            return document.getElementById('inp-mesin')?.value || '';
        }

        // ── Helper: is Power House? ───────────────────────────────────────────────────
        function isPowerHouse() {
            return document.getElementById('sel-dept').value.toUpperCase() === 'POWER HOUSE';
        }

        // ── Duration calculator ───────────────────────────────────────────────────────
        function calcDuration() {
            const startDate = document.getElementById('inp-start-date').value;
            const startTime = document.getElementById('inp-start-time').value;
            const finishDate = document.getElementById('inp-finish-date').value;
            const finishTime = document.getElementById('inp-finish-time').value;

            const dispEl = document.getElementById('inp-duration-display');
            const minEl = document.getElementById('inp-duration-minutes');

            if (!startDate || !startTime || !finishDate || !finishTime) {
                dispEl.value = '';
                minEl.value = '';
                return;
            }

            const start = new Date(`${startDate}T${startTime}`);
            const finish = new Date(`${finishDate}T${finishTime}`);
            const diffMs = finish - start;

            if (diffMs <= 0) {
                dispEl.value = 'Finish harus setelah Start';
                minEl.value = '';
                return;
            }

            const totalSec = Math.round(diffMs / 1000);
            const totalMin = Math.round(diffMs / 60000);
            const hh = String(Math.floor(totalMin / 60)).padStart(2, '0');
            const mm = String(totalMin % 60).padStart(2, '0');
            dispEl.value = `${hh}:${mm}:00`;
            minEl.value = totalMin; // simpan dalam menit
        }

        // ── Submit button state ───────────────────────────────────────────────────────
        function checkAllFieldsFilled() {
            const dept = document.getElementById('sel-dept').value;
            const line = document.getElementById('sel-line').value;
            const machine = getCurrentMachineName();
            const startDate = document.getElementById('inp-start-date').value;
            const startTime = document.getElementById('inp-start-time').value;
            const pic = document.getElementById('inp-pic').value;
            const problem = document.getElementById('inp-problem').value.trim();
            const action = document.getElementById('inp-action').value.trim();

            const ready = !!(dept && line && machine && startDate && startTime && pic && problem && action);
            setSubmitEnabled(ready);
        }

        function setSubmitEnabled(enabled) {
            const btn = document.getElementById('btn-submit');
            btn.disabled = !enabled;
            if (enabled) {
                btn.classList.add('ready');
                btn.onclick = submitReport;
            } else {
                btn.classList.remove('ready');
                btn.onclick = null;
            }
        }

        // Attach listeners ke semua field wajib (kecuali dropdown cascade yang punya handler sendiri)
        document.addEventListener('DOMContentLoaded', () => {
            ['inp-start-date', 'inp-start-time', 'inp-problem', 'inp-action'].forEach(id => {
                document.getElementById(id).addEventListener('input', checkAllFieldsFilled);
            });
            document.getElementById('inp-pic').addEventListener('change', checkAllFieldsFilled);
        });

        // ── Technicians ───────────────────────────────────────────────────────────────
        fetch(`${BASE}?ajax=technicians`)
            .then(r => r.json())
            .then(data => {
                const sel = document.getElementById('inp-pic');
                data.forEach(t => {
                    const o = document.createElement('option');
                    o.value = t.name;
                    o.textContent = t.name;
                    sel.appendChild(o);
                });
            })
            .catch(e => console.error('Fetch technicians failed:', e));

        // ── Department ────────────────────────────────────────────────────────────────
        fetch(`${BASE}?ajax=departments`)
            .then(r => r.json())
            .then(data => {
                if (data.error) {
                    console.error('AJAX error:', data.error);
                    return;
                }
                const sel = document.getElementById('sel-dept');
                data.forEach(d => {
                    const o = document.createElement('option');
                    o.value = o.textContent = d;
                    sel.appendChild(o);
                });
            })
            .catch(e => console.error('Fetch departments failed:', e));

        function loadLines() {
            const dept = document.getElementById('sel-dept').value;
            const selLine = document.getElementById('sel-line');
            const selOp = document.getElementById('sel-op');
            selLine.innerHTML = '<option value="">— Pilih Line —</option>';
            selOp.innerHTML = '<option value="">— Pilih OP —</option>';
            selLine.disabled = selOp.disabled = true;
            clearMachineField();
            document.getElementById('inp-type').value = '';
            checkAllFieldsFilled();
            if (!dept) return;
            fetch(`${BASE}?ajax=lines&department=${encodeURIComponent(dept)}`)
                .then(r => r.json())
                .then(data => {
                    data.forEach(l => {
                        const o = document.createElement('option');
                        o.value = o.textContent = l;
                        selLine.appendChild(o);
                    });
                    selLine.disabled = false;
                });
        }

        function loadOps() {
            const dept = document.getElementById('sel-dept').value;
            const line = document.getElementById('sel-line').value;
            const selOp = document.getElementById('sel-op');
            selOp.innerHTML = '<option value="">— Pilih OP —</option>';
            selOp.disabled = true;
            clearMachineField();
            document.getElementById('inp-type').value = '';
            checkAllFieldsFilled();
            if (!line) return;
            fetch(`${BASE}?ajax=ops&department=${encodeURIComponent(dept)}&line=${encodeURIComponent(line)}`)
                .then(r => r.json())
                .then(data => {
                    // Power House: server mengembalikan ['-'], langsung skip ke load mesin
                    if (isPowerHouse() && data.length === 1 && data[0] === '-') {
                        const o = document.createElement('option');
                        o.value = o.textContent = '-';
                        selOp.appendChild(o);
                        selOp.disabled = false;
                        selOp.value = '-';
                        // Langsung load mesin tanpa user pilih OP
                        loadMachines();
                        return;
                    }
                    data.forEach(op => {
                        const o = document.createElement('option');
                        o.value = o.textContent = op;
                        selOp.appendChild(o);
                    });
                    selOp.disabled = false;
                    // Jika hanya 1 OP, auto-select dan langsung load mesin
                    if (data.length === 1) {
                        selOp.value = data[0];
                        loadMachines();
                    }
                });
        }

        function loadMachines() {
            const dept = document.getElementById('sel-dept').value;
            const line = document.getElementById('sel-line').value;
            const op = document.getElementById('sel-op').value;
            clearMachineField();
            document.getElementById('inp-type').value = '';
            checkAllFieldsFilled();
            if (!op) return;
            fetch(`${BASE}?ajax=machine_list&department=${encodeURIComponent(dept)}&line=${encodeURIComponent(line)}&op=${encodeURIComponent(op)}`)
                .then(r => r.json())
                .then(data => {
                    const container = document.getElementById('machine-field-container');

                    if (data.length > 1) {
                        // Lebih dari 1 mesin → tampilkan dropdown
                        let html = `<select id="sel-mesin-dropdown" class="form-field" onchange="onMachineDropdownChange()">
                                        <option value="">— Pilih Mesin —</option>`;
                        data.forEach(m => {
                            html += `<option value="${escHtml(m.machine_name)}" data-type="${escHtml(m.machine_type)}">${escHtml(m.machine_name)}</option>`;
                        });
                        html += `</select>`;
                        container.innerHTML = html;
                        document.getElementById('inp-type').value = '';
                    } else if (data.length === 1) {
                        // Tepat 1 mesin → isi otomatis, tidak perlu dropdown
                        container.innerHTML = `<input type="text" id="inp-mesin" class="form-field" value="${escHtml(data[0].machine_name)}" readonly>`;
                        document.getElementById('inp-type').value = data[0].machine_type || '';
                    } else {
                        // Tidak ada mesin
                        container.innerHTML = `<input type="text" id="inp-mesin" class="form-field" placeholder="— Tidak ditemukan —" readonly>`;
                    }
                    checkAllFieldsFilled();
                });
        }

        function onMachineDropdownChange() {
            const sel = document.getElementById('sel-mesin-dropdown');
            const opt = sel.options[sel.selectedIndex];
            document.getElementById('inp-type').value = opt?.dataset?.type ?? '';
            checkAllFieldsFilled();
        }

        function clearMachineField() {
            const container = document.getElementById('machine-field-container');
            container.innerHTML = `<input type="text" id="inp-mesin" class="form-field" placeholder="— Otomatis terisi —" readonly>`;
            document.getElementById('inp-type').value = '';
        }

        // ── Submit ────────────────────────────────────────────────────────────────────
        function submitReport() {
            const dept = document.getElementById('sel-dept').value;
            const line = document.getElementById('sel-line').value;
            const op = document.getElementById('sel-op').value || '-';
            const machine = getCurrentMachineName();
            const type = document.getElementById('inp-type').value;
            const startDate = document.getElementById('inp-start-date').value;
            const startTime = document.getElementById('inp-start-time').value;
            const finishDate = document.getElementById('inp-finish-date').value;
            const finishTime = document.getElementById('inp-finish-time').value;
            const pic = document.getElementById('inp-pic').value;
            const problem = document.getElementById('inp-problem').value.trim();
            const action = document.getElementById('inp-action').value.trim();
            const reported = document.getElementById('inp-reported-by').value;

            const btn = document.getElementById('btn-submit');
            btn.disabled = true;
            btn.classList.remove('ready');
            btn.onclick = null;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menyimpan...';

            const fd = new FormData();
            fd.append('submit_report', '1');
            fd.append('department', dept);
            fd.append('line', line);
            fd.append('op', op);
            fd.append('machine_name', machine);
            fd.append('machine_type', type);
            fd.append('report_date', todayISO);
            fd.append('start_date', startDate);
            fd.append('start_time', startTime);
            fd.append('finish_date', finishDate);
            fd.append('finish_time', finishTime);
            fd.append('reported_by', reported);
            fd.append('pic', pic);
            fd.append('problem', problem);
            fd.append('action', action);
            fd.append('duration_minutes', document.getElementById('inp-duration-minutes').value);

            fetch(BASE, {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showToast('E-Report berhasil disimpan!', 'success');
                        resetForm();
                    } else {
                        showToast(data.message || 'Gagal menyimpan.', 'error');
                    }
                })
                .catch(() => showToast('Koneksi error.', 'error'))
                .finally(() => {
                    btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit E-Report';
                    checkAllFieldsFilled();
                });
        }

        function resetForm() {
            document.getElementById('sel-dept').value = '';
            const selLine = document.getElementById('sel-line');
            const selOp = document.getElementById('sel-op');
            selLine.innerHTML = '<option value="">— Pilih Line —</option>';
            selOp.innerHTML = '<option value="">— Pilih OP —</option>';
            selLine.disabled = selOp.disabled = true;
            clearMachineField();
            document.getElementById('inp-type').value = '';
            document.getElementById('inp-start-date').value = '';
            document.getElementById('inp-start-time').value = '';
            document.getElementById('inp-finish-date').value = '';
            document.getElementById('inp-finish-time').value = '';
            document.getElementById('inp-duration-display').value = '';
            document.getElementById('inp-duration-minutes').value = '';
            document.getElementById('inp-pic').value = '';
            document.getElementById('inp-problem').value = '';
            document.getElementById('inp-action').value = '';
            checkAllFieldsFilled();
        }

        function escHtml(str) {
            const d = document.createElement('div');
            d.textContent = str;
            return d.innerHTML;
        }

        function showToast(msg, type = 'success') {
            const t = document.getElementById('toast');
            t.className = type;
            t.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i> ${msg}`;
            t.classList.add('show');
            clearTimeout(t._timer);
            t._timer = setTimeout(() => t.classList.remove('show'), 3500);
        }
    </script>
</body>

</html>