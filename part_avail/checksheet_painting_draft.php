<?php
// checksheet_painting_draft.php
// Daftar draft checksheet Painting yang belum disubmit (per period_month).
// Draft disimpan server-side di tabel `painting_checksheet_drafts` supaya
// bisa dilanjutkan dari device/browser apa pun (halaman ini dipakai bareng-
// bareng lewat 1 key akses, bukan login per-user).
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
    header('Location: checksheet_gate.php?redirect=checksheet_painting_draft.php');
    exit;
}

$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// ─── Helper: label bulan Indonesia dari 'YYYY-MM' ──────────────────────────
function indoMonthLabel(string $periodYm): string
{
    static $bulan = [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Maret',
        4 => 'April',
        5 => 'Mei',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'Agustus',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember',
    ];
    [$y, $m] = array_pad(explode('-', $periodYm), 2, null);
    $m = (int)$m;
    return ($bulan[$m] ?? $periodYm) . ' ' . $y;
}

// ─── AJAX: daftar semua draft yang belum disubmit ──────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'list') {
    header('Content-Type: application/json');

    // NOT EXISTS terhadap submissions sebagai jaga-jaga tambahan — normalnya
    // draft sudah otomatis terhapus begitu bulan tsb disubmit (lihat
    // dashboard_checksheet_painting.php), tapi ini mencegah baris nyasar
    // ikut tampil kalau ada perubahan data manual di database.
    $rows = $pdo->query("
        SELECT d.id, d.period_month, d.check_date, d.checker, d.items_json, d.updated_at
        FROM painting_checksheet_drafts d
        WHERE NOT EXISTS (
            SELECT 1 FROM painting_checksheet_submissions s WHERE s.period_month = d.period_month
        )
        ORDER BY d.period_month DESC
    ")->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $items   = json_decode($r['items_json'] ?? '[]', true);
        $items   = is_array($items) ? $items : [];
        $total   = count($items);
        $checked = 0;
        foreach ($items as $it) {
            if (($it['action_status'] ?? '') === 'checked') $checked++;
        }

        $out[] = [
            'id'           => $r['id'],
            'period_month' => $r['period_month'],
            'period_label' => indoMonthLabel($r['period_month']),
            'check_date'   => $r['check_date'],
            'checker'      => $r['checker'],
            'total_items'  => $total,
            'checked_count' => $checked,
            'updated_at'   => $r['updated_at'],
        ];
    }

    echo json_encode(['rows' => $out]);
    exit;
}

// ─── AJAX: hapus 1 draft ────────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'discard' && isset($_GET['period'])) {
    header('Content-Type: application/json');
    $stmt = $pdo->prepare("DELETE FROM painting_checksheet_drafts WHERE period_month = ?");
    $stmt->execute([$_GET['period']]);
    echo json_encode(['success' => true]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Draft Checksheet Painting — Maintenance Hub</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: #f1f5f9;
        }

        /* ── Sidebar (sama seperti dashboard/history checksheet painting) ── */
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
            background: transparent;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            transition: background .15s, color .15s;
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

        /* ── Kartu draft ── */
        .draft-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 16px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            transition: box-shadow .15s, border-color .15s;
        }

        .draft-card:hover {
            box-shadow: 0 4px 14px rgba(0, 0, 0, .06);
            border-color: #fde047;
        }

        .draft-card-title {
            font-size: .92rem;
            font-weight: 800;
            color: #1e293b;
        }

        .draft-card-meta {
            font-size: .74rem;
            color: #94a3b8;
            font-weight: 600;
            margin-top: 3px;
            display: flex;
            flex-wrap: wrap;
            gap: 4px 12px;
        }

        .progress-track {
            width: 100%;
            height: 6px;
            background: #f1f5f9;
            border-radius: 999px;
            overflow: hidden;
            margin-top: 8px;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #facc15, #eab308);
            border-radius: 999px;
            transition: width .3s;
        }

        .draft-actions {
            display: flex;
            gap: 8px;
            flex-shrink: 0;
        }

        .btn-resume {
            background: #0f766e;
            color: #fff;
            font-weight: 700;
            font-size: .78rem;
            padding: 9px 16px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
            text-decoration: none;
        }

        .btn-resume:hover {
            background: #0d5c56;
        }

        .btn-discard {
            background: #fff;
            color: #dc2626;
            font-weight: 700;
            font-size: .78rem;
            padding: 9px 14px;
            border-radius: 10px;
            border: 1.5px solid #fecaca;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }

        .btn-discard:hover {
            background: #fef2f2;
        }

        .empty-state {
            background: #fff;
            border: 1.5px dashed #e2e8f0;
            border-radius: 16px;
            padding: 40px 20px;
            text-align: center;
            color: #94a3b8;
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
            <a href="history_checksheet_painting.php" onclick="navigateTo(event,'history_checksheet_painting.php')" class="nav-item" title="History">
                <i class="fas fa-history"></i>
                <span class="nav-label">History</span>
            </a>
            <a href="checksheet_painting_draft.php" onclick="navigateTo(event,'checksheet_painting_draft.php')" class="nav-item active" title="Draft">
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
                <div class="w-7 h-7 rounded-lg bg-[#fef9c3] flex items-center justify-center">
                    <i class="fas fa-pen-to-square text-[#a16207] text-xs"></i>
                </div>
                <div>
                    <div class="text-sm font-bold text-slate-800">Draft Checksheet Painting</div>
                    <div class="text-[10px] text-slate-400 font-medium">Progress yang belum disubmit — bisa dilanjutkan kapan saja</div>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <button onclick="document.getElementById('back-confirm-overlay').style.display='flex'"
                    class="info-chip" style="cursor:pointer;border-color:#fecaca;color:#dc2626;background:#fef2f2;" title="Kembali & kunci halaman">
                    <i class="fas fa-arrow-left"></i> Kembali
                </button>
            </div>
        </div>

        <div class="p-4">

            <div class="summary-card mb-4">
                <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Total Draft Belum Disubmit</div>
                <div class="text-2xl font-extrabold text-amber-600" id="draft-count-label">0</div>
            </div>

            <div id="draft-list" class="flex flex-col gap-3"></div>

            <div id="empty-state" class="empty-state hidden">
                <i class="fas fa-inbox text-3xl mb-2"></i>
                <div class="text-sm font-bold text-slate-500">Tidak ada draft tersimpan</div>
                <div class="text-xs mt-1">Draft otomatis muncul di sini saat Anda mulai mengisi checksheet tapi belum submit.</div>
                <a href="dashboard_checksheet_painting.php" class="inline-flex items-center gap-2 mt-4 text-xs font-bold text-white bg-[#0f766e] hover:bg-[#0d5c56] transition rounded-xl px-4 py-2.5">
                    <i class="fas fa-plus"></i> Isi Checksheet
                </a>
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
            loadDrafts();
        });

        function loadDrafts() {
            fetch('checksheet_painting_draft.php?ajax=list')
                .then(r => r.json())
                .then(res => renderDrafts(res.rows || []));
        }

        function fmtDateTime(sqlDatetime) {
            if (!sqlDatetime) return '-';
            const d = new Date(sqlDatetime.replace(' ', 'T'));
            return d.toLocaleString('id-ID', {
                day: 'numeric',
                month: 'short',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        function renderDrafts(rows) {
            const list = document.getElementById('draft-list');
            const empty = document.getElementById('empty-state');
            document.getElementById('draft-count-label').textContent = rows.length;

            list.innerHTML = '';

            if (rows.length === 0) {
                empty.classList.remove('hidden');
                return;
            }
            empty.classList.add('hidden');

            rows.forEach(row => {
                const pct = row.total_items > 0 ? Math.round((row.checked_count / row.total_items) * 100) : 0;
                // Lanjutkan ke tanggal yang tersimpan di draft; kalau belum pernah
                // pilih tanggal, jatuhkan ke tanggal 1 pada periode tsb.
                const resumeDate = row.check_date || (row.period_month + '-01');

                const card = document.createElement('div');
                card.className = 'draft-card';
                card.innerHTML = `
                    <div class="flex-1 min-w-0">
                        <div class="draft-card-title">${esc(row.period_label)}</div>
                        <div class="draft-card-meta">
                            <span><i class="far fa-user mr-1"></i>${row.checker ? esc(row.checker) : 'Checker belum dipilih'}</span>
                            <span><i class="far fa-clock mr-1"></i>Terakhir diubah ${fmtDateTime(row.updated_at)}</span>
                            <span><i class="fas fa-list-check mr-1"></i>${row.checked_count} / ${row.total_items} item</span>
                        </div>
                        <div class="progress-track">
                            <div class="progress-fill" style="width:${pct}%"></div>
                        </div>
                    </div>
                    <div class="draft-actions">
                        <a class="btn-resume" href="dashboard_checksheet_painting.php?date=${encodeURIComponent(resumeDate)}&resume=1">
                            <i class="fas fa-play"></i> Lanjutkan
                        </a>
                        <button type="button" class="btn-discard" onclick="discardDraft('${row.period_month}', this)">
                            <i class="fas fa-trash"></i> Hapus
                        </button>
                    </div>
                `;
                list.appendChild(card);
            });
        }

        function discardDraft(period, btn) {
            if (!confirm(`Hapus draft untuk periode ${period}? Aksi ini tidak bisa dibatalkan.`)) return;
            btn.disabled = true;
            fetch('checksheet_painting_draft.php?ajax=discard&period=' + encodeURIComponent(period))
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        showToast('Draft dihapus.', 'success');
                        loadDrafts();
                    } else {
                        btn.disabled = false;
                        showToast('Gagal menghapus draft.', 'error');
                    }
                })
                .catch(() => {
                    btn.disabled = false;
                    showToast('Gagal menghapus draft (jaringan).', 'error');
                });
        }
    </script>
</body>

</html>