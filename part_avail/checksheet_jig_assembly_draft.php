<?php

require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['checksheet_unlocked']) || ($_SESSION['checksheet_area'] ?? '') !== 'jig_assembly') {
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['error' => 'unauthorized']);
        exit;
    }
    header('Location: checksheet_gate.php?redirect=checksheet_jig_assembly_draft.php');
    exit;
}

$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// ─── AJAX: daftar draft ─────────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'list') {
    header('Content-Type: application/json');

    $rows = $pdo->query("
        SELECT id, check_date, checker, items_json, updated_at
        FROM jig_assembly_drafts
        ORDER BY check_date DESC
    ")->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $items = json_decode($r['items_json'], true) ?: [];
        $checked = 0;
        foreach ($items as $it) {
            if (($it['action_status'] ?? '') === 'checked') $checked++;
        }
        $out[] = [
            'id'           => $r['id'],
            'check_date'   => $r['check_date'],
            'checker'      => $r['checker'],
            'total_items'  => count($items),
            'checked_items' => $checked,
            'updated_at'   => $r['updated_at'],
        ];
    }

    echo json_encode(['rows' => $out]);
    exit;
}

// ─── AJAX: hapus draft ───────────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'discard' && isset($_GET['date'])) {
    header('Content-Type: application/json');
    $stmt = $pdo->prepare("DELETE FROM jig_assembly_drafts WHERE check_date = ?");
    $stmt->execute([$_GET['date']]);
    echo json_encode(['success' => true]);
    exit;
}

$totalDrafts = (int)$pdo->query("SELECT COUNT(*) FROM jig_assembly_drafts")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jig Assembly Check Sheet — Draft</title>
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

        .summary-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 14px 18px;
        }

        .draft-card {
            background: #fff;
            border: 1.5px solid #fde68a;
            border-radius: 16px;
            padding: 14px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            transition: box-shadow .15s;
        }

        .draft-card:hover {
            box-shadow: 0 4px 14px rgba(0, 0, 0, .06);
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: .68rem;
            font-weight: 800;
            background: #fef9c3;
            color: #a16207;
        }

        .btn-resume {
            background: #e36414;
            color: #fff;
            font-weight: 700;
            font-size: .76rem;
            padding: 8px 16px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
        }

        .btn-resume:hover {
            background: #c4550f;
        }

        .btn-discard {
            background: #fff;
            color: #dc2626;
            font-weight: 700;
            font-size: .76rem;
            padding: 8px 14px;
            border-radius: 10px;
            border: 1.5px solid #fecaca;
            cursor: pointer;
        }

        .btn-discard:hover {
            background: #fef2f2;
        }

        .hidden {
            display: none;
        }

        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 60px 20px;
            color: #94a3b8;
            background: #fff;
            border: 1px dashed #e2e8f0;
            border-radius: 16px;
        }

        .empty-state i {
            font-size: 2.4rem;
            margin-bottom: 12px;
            opacity: .35;
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
            <a href="history_checksheet_jig_assembly.php" onclick="navigateTo(event,'history_checksheet_jig_assembly.php')" class="nav-item" title="History">
                <i class="fas fa-history"></i>
                <span class="nav-label">History</span>
            </a>
            <a href="checksheet_jig_assembly_draft.php" onclick="navigateTo(event,'checksheet_jig_assembly_draft.php')" class="nav-item active" title="Draft">
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
                    <i class="fas fa-pen-to-square text-[#e36414] text-xs"></i>
                </div>
                <div>
                    <div class="text-sm font-bold text-slate-800">Draft Checksheet Jig Assembly</div>
                    <div class="text-[10px] text-slate-400 font-medium">Checksheet yang belum disubmit</div>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <span class="info-chip"><i class="far fa-calendar text-orange-400"></i> <span id="today-label"></span></span>
            </div>
        </div>

        <div class="p-4">

            <div class="summary-card mb-4">
                <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Total Draft Tersimpan</div>
                <div class="text-2xl font-extrabold text-slate-800"><?= $totalDrafts ?></div>
            </div>

            <div class="space-y-3" id="draft-list">
                <div class="text-xs text-slate-400 font-semibold px-2">Memuat data...</div>
            </div>
        </div>
    </div>

    <div id="toast" style="position:fixed;bottom:24px;right:24px;z-index:9999;padding:12px 20px;border-radius:12px;font-size:.82rem;font-weight:600;display:flex;align-items:center;gap:8px;transform:translateY(80px);opacity:0;transition:all .3s cubic-bezier(.34,1.56,.64,1);box-shadow:0 8px 24px rgba(0,0,0,.12);"></div>

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

            document.getElementById('today-label').textContent = new Date().toLocaleDateString('id-ID', {
                weekday: 'long',
                day: 'numeric',
                month: 'long',
                year: 'numeric'
            });
            loadDrafts();
        });

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

        function loadDrafts() {
            fetch('checksheet_jig_assembly_draft.php?ajax=list')
                .then(r => r.json())
                .then(res => renderList(res.rows || []));
        }

        function renderList(rows) {
            const container = document.getElementById('draft-list');
            container.innerHTML = '';

            if (rows.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-pen-to-square"></i>
                        <div class="text-sm font-semibold">Tidak ada draft tersimpan.</div>
                        <div class="text-xs mt-1">Draft otomatis muncul di sini saat Anda mengisi checksheet tapi belum submit.</div>
                    </div>`;
                return;
            }

            rows.forEach(row => {
                const card = document.createElement('div');
                card.className = 'draft-card';
                card.innerHTML = `
                    <div>
                        <div class="text-sm font-extrabold text-slate-800">${esc(dateLabel(row.check_date))}</div>
                        <div class="text-[11px] text-slate-400 font-medium mt-0.5">
                            <i class="fas fa-user mr-1"></i>${row.checker ? esc(row.checker) : '<em>belum pilih checker</em>'} ·
                            <i class="fas fa-clock ml-1 mr-1"></i>Update terakhir ${fmtDateTime(row.updated_at)}
                        </div>
                        <div class="mt-1.5"><span class="badge"><i class="fas fa-list-check"></i> ${row.checked_items} / ${row.total_items} item sudah dicek</span></div>
                    </div>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <a class="btn-resume" href="dashboard_checksheet_jig_assembly.php?date=${row.check_date}&resume=1">
                            <i class="fas fa-play"></i> Lanjutkan
                        </a>
                        <button class="btn-discard" onclick="discardDraft('${row.check_date}')">
                            <i class="fas fa-trash-alt"></i> Hapus
                        </button>
                    </div>
                `;
                container.appendChild(card);
            });
        }

        function discardDraft(date) {
            if (!confirm('Hapus draft tanggal ' + date + '? Tindakan ini tidak bisa dibatalkan.')) return;

            fetch(`checksheet_jig_assembly_draft.php?ajax=discard&date=${date}`)
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        showToast('Draft berhasil dihapus.', 'success');
                        loadDrafts();
                    } else {
                        showToast('Gagal menghapus draft.', 'error');
                    }
                });
        }

        function showToast(msg, type) {
            const t = document.getElementById('toast');
            t.textContent = msg;
            t.style.background = type === 'success' ? '#dcfce7' : '#fee2e2';
            t.style.color = type === 'success' ? '#15803d' : '#dc2626';
            t.style.border = type === 'success' ? '1.5px solid #86efac' : '1.5px solid #fca5a5';
            t.style.transform = 'translateY(0)';
            t.style.opacity = '1';
            setTimeout(() => {
                t.style.transform = 'translateY(80px)';
                t.style.opacity = '0';
            }, 3500);
        }
    </script>
</body>

</html>