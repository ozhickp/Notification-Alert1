<?php
// history_checksheet_painting.php
require_once __DIR__ . '/config.php';

// ─── Gate akses: sama seperti dashboard_checksheet_painting.php ───────────
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['checksheet_unlocked']) || ($_SESSION['checksheet_area'] ?? '') !== 'painting') {
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['error' => 'unauthorized']);
        exit;
    }
    header('Location: checksheet_gate.php?redirect=history_checksheet_painting.php');
    exit;
}

$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// ─── AJAX: daftar submission (list bulan yang sudah diisi) ─────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'list') {
    header('Content-Type: application/json');

    $rows = $pdo->query("
        SELECT s.id, s.period_month, s.check_date, s.checker, s.submitted_at,
               COUNT(d.id) AS total_items,
               SUM(d.action_status = 'checked') AS checked_count,
               SUM(d.result = 'OK') AS ok_count,
               SUM(d.result = 'NG') AS ng_count
        FROM painting_checksheet_submissions s
        LEFT JOIN painting_checksheet_submission_details d ON d.submission_id = s.id
        GROUP BY s.id
        ORDER BY s.period_month DESC
    ")->fetchAll();

    echo json_encode(['rows' => $rows]);
    exit;
}

// ─── AJAX: detail 1 submission ─────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'detail' && isset($_GET['id'])) {
    header('Content-Type: application/json');

    $stmtSub = $pdo->prepare("SELECT * FROM painting_checksheet_submissions WHERE id = ?");
    $stmtSub->execute([(int)$_GET['id']]);
    $sub = $stmtSub->fetch();
    if (!$sub) {
        echo json_encode(['error' => 'not_found']);
        exit;
    }

    $stmtDet = $pdo->prepare("
        SELECT unit_name, no, part, action_status, result, note
        FROM painting_checksheet_submission_details
        WHERE submission_id = ?
        ORDER BY unit_name, no
    ");
    $stmtDet->execute([(int)$_GET['id']]);
    $details = $stmtDet->fetchAll();

    // Kelompokkan per unit sesuai urutan kemunculan
    $grouped = [];
    foreach ($details as $d) {
        $grouped[$d['unit_name']][] = $d;
    }

    echo json_encode(['submission' => $sub, 'grouped' => $grouped]);
    exit;
}

// ─── Data ringkasan untuk header halaman ───────────────────────────────────
$totalSubmissions = (int)$pdo->query("SELECT COUNT(*) FROM painting_checksheet_submissions")->fetchColumn();
$currentPeriod     = date('Y-m');
$stmtCur           = $pdo->prepare("SELECT id FROM painting_checksheet_submissions WHERE period_month = ?");
$stmtCur->execute([$currentPeriod]);
$currentSubmitted  = (bool)$stmtCur->fetchColumn();
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painting Check Sheet — History</title>
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

        .summary-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 14px 18px;
        }

        .month-card {
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

        .month-card:hover {
            box-shadow: 0 4px 14px rgba(0, 0, 0, .06);
            border-color: #99d8d1;
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
            max-width: 780px;
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
            color: #0f766e;
            text-transform: uppercase;
            letter-spacing: .04em;
            margin: 14px 0 6px;
        }

        .detail-item-row {
            display: grid;
            grid-template-columns: 28px 1fr 90px 90px 1fr;
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
            <a href="dashboard_checksheet_painting.php" onclick="navigateTo(event,'dashboard_checksheet_painting.php')" class="nav-item" title="Check Sheet">
                <i class="fas fa-clipboard-check"></i>
                <span class="nav-label">Check Sheet</span>
            </a>
            <a href="history_checksheet_painting.php" onclick="navigateTo(event,'history_checksheet_painting.php')" class="nav-item active" title="History">
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
                    <i class="fas fa-history text-[#0f766e] text-xs"></i>
                </div>
                <div>
                    <div class="text-sm font-bold text-slate-800">History Checksheet Painting</div>
                    <div class="text-[10px] text-slate-400 font-medium">Rekap pengecekan bulanan Divisi Painting</div>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <a href="export_checksheet_painting.php?bulan=<?= htmlspecialchars($currentPeriod) ?>"
                    class="info-chip hover:bg-slate-100 transition" title="Export bulan berjalan ke Excel">
                    <i class="fas fa-file-excel text-emerald-500"></i> Export
                </a>
                <button onclick="document.getElementById('back-confirm-overlay').style.display='flex'"
                    class="info-chip" style="cursor:pointer;border-color:#fecaca;color:#dc2626;background:#fef2f2;" title="Kembali & kunci halaman">
                    <i class="fas fa-arrow-left"></i> Kembali
                </button>
            </div>
        </div>

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

        <div class="p-4">

            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
                <div class="summary-card">
                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Total Submission</div>
                    <div class="text-2xl font-extrabold text-slate-800"><?= $totalSubmissions ?></div>
                </div>
                <div class="summary-card">
                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Bulan Ini (<?= htmlspecialchars(date('M Y', strtotime($currentPeriod . '-01'))) ?>)</div>
                    <div class="text-2xl font-extrabold <?= $currentSubmitted ? 'text-emerald-600' : 'text-rose-600' ?>">
                        <?= $currentSubmitted ? 'Sudah Diisi' : 'Belum Diisi' ?>
                    </div>
                </div>
                <div class="summary-card flex items-center">
                    <?php if (!$currentSubmitted): ?>
                        <a href="dashboard_checksheet_painting.php" class="text-xs font-bold text-white bg-[#0f766e] hover:bg-[#0d5c56] transition rounded-xl px-4 py-2.5 inline-flex items-center gap-2">
                            <i class="fas fa-plus"></i> Isi Checksheet Bulan Ini
                        </a>
                    <?php else: ?>
                        <span class="text-xs font-semibold text-slate-400"><i class="fas fa-check-circle text-emerald-500 mr-1"></i> Semua terkini.</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="space-y-3" id="month-list">
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
                    <a id="modal-export-link" href="#" class="text-xs font-bold text-emerald-600 hover:text-emerald-700">
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
        });

        function loadList() {
            fetch('history_checksheet_painting.php?ajax=list')
                .then(r => r.json())
                .then(res => renderList(res.rows || []));
        }

        function monthLabel(period) {
            const [y, m] = period.split('-');
            const d = new Date(parseInt(y), parseInt(m) - 1, 1);
            return d.toLocaleDateString('id-ID', {
                month: 'long',
                year: 'numeric'
            });
        }

        function renderList(rows) {
            const container = document.getElementById('month-list');
            container.innerHTML = '';

            if (rows.length === 0) {
                container.innerHTML = '<div class="text-xs text-slate-400 font-semibold px-2">Belum ada data checksheet painting.</div>';
                return;
            }

            rows.forEach(row => {
                const card = document.createElement('div');
                card.className = 'month-card';
                card.onclick = () => openDetailModal(row.id);

                const ngBadge = row.ng_count > 0 ?
                    `<span class="badge ng"><i class="fas fa-triangle-exclamation"></i> ${row.ng_count} NG</span>` :
                    `<span class="badge ok"><i class="fas fa-check"></i> Semua OK</span>`;

                card.innerHTML = `
                    <div>
                        <div class="text-sm font-extrabold text-slate-800">${esc(monthLabel(row.period_month))}</div>
                        <div class="text-[11px] text-slate-400 font-medium mt-0.5">
                            <i class="fas fa-user mr-1"></i>${esc(row.checker)} ·
                            <i class="fas fa-clock ml-1 mr-1"></i>${esc(row.submitted_at)}
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="badge neutral">${row.checked_count}/${row.total_items} item</span>
                        ${ngBadge}
                        <i class="fas fa-chevron-right text-slate-300 ml-2"></i>
                    </div>
                `;
                container.appendChild(card);
            });
        }

        function openDetailModal(id) {
            fetch('history_checksheet_painting.php?ajax=detail&id=' + id)
                .then(r => r.json())
                .then(res => {
                    if (res.error) return;
                    const sub = res.submission;
                    document.getElementById('modal-title').textContent = 'Checksheet Painting — ' + monthLabel(sub.period_month);
                    document.getElementById('modal-subtitle').textContent =
                        `Checker: ${sub.checker} · Tanggal cek: ${sub.check_date} · Submitted: ${sub.submitted_at}`;
                    document.getElementById('modal-export-link').href = 'export_checksheet_painting.php?bulan=' + sub.period_month;

                    const body = document.getElementById('modal-body');
                    body.innerHTML = '';

                    Object.keys(res.grouped).forEach(unitName => {
                        const title = document.createElement('div');
                        title.className = 'detail-unit-title';
                        title.textContent = unitName;
                        body.appendChild(title);

                        const hdr = document.createElement('div');
                        hdr.className = 'detail-item-row';
                        hdr.style.fontWeight = '800';
                        hdr.style.color = '#94a3b8';
                        hdr.style.fontSize = '.68rem';
                        hdr.innerHTML = '<div>No</div><div>Part</div><div>Action</div><div>Result</div><div>Note</div>';
                        body.appendChild(hdr);

                        res.grouped[unitName].forEach(item => {
                            const row = document.createElement('div');
                            row.className = 'detail-item-row';
                            const resultCls = item.result || 'none';
                            row.innerHTML = `
                                <div class="text-slate-400 font-bold">${item.no}</div>
                                <div class="font-semibold text-slate-700">${esc(item.part)}</div>
                                <div>${item.action_status === 'checked' ? '<i class="fas fa-check-circle text-emerald-500"></i> Checked' : '<i class="fas fa-circle text-slate-300"></i> Unchecked'}</div>
                                <div><span class="result-chip ${resultCls}">${item.result || '—'}</span></div>
                                <div class="text-slate-400">${esc(item.note || '—')}</div>
                            `;
                            body.appendChild(row);
                        });
                    });

                    document.getElementById('detail-modal').classList.add('open');
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