<?php
include 'config.php';
session_start();

requireRole([ROLE_ADMIN_MAINTENANCE, ROLE_SUPERADMIN]);

// Ambil nama user yang sedang login
$stmtUser = $pdo->prepare("SELECT username FROM users WHERE id = ?");
$stmtUser->execute([$_SESSION['user_id']]);
$currentUser = $stmtUser->fetch(PDO::FETCH_ASSOC);
$displayName = $currentUser['username'] ?? 'User';

// ── Folder penyimpanan PDF ───────────────────────────────────────────────────
$uploadDir = __DIR__ . '/uploads/flowchart/';
$uploadUrl = 'uploads/flowchart/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// ── AJAX: upload & delete ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    // -- Upload flowchart baru --
    if ($_POST['action'] === 'upload_flowchart') {
        $title       = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($title === '') {
            echo json_encode(['status' => 'error', 'message' => 'Judul wajib diisi']);
            exit;
        }

        if (!isset($_FILES['pdf_file']) || $_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['status' => 'error', 'message' => 'File PDF wajib diupload']);
            exit;
        }

        $file     = $_FILES['pdf_file'];
        $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if ($ext !== 'pdf' || $mimeType !== 'application/pdf') {
            echo json_encode(['status' => 'error', 'message' => 'File harus berformat PDF']);
            exit;
        }

        $maxSize = 20 * 1024 * 1024; // 20 MB
        if ($file['size'] > $maxSize) {
            echo json_encode(['status' => 'error', 'message' => 'Ukuran file maksimal 20MB']);
            exit;
        }

        $storedName = 'fc_' . bin2hex(random_bytes(8)) . '.pdf';
        $destPath   = $uploadDir . $storedName;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            echo json_encode(['status' => 'error', 'message' => 'Gagal menyimpan file ke server']);
            exit;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO flowcharts (title, description, stored_filename, original_filename, file_size, uploaded_by, uploaded_by_name)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $title,
            $description !== '' ? $description : null,
            $storedName,
            $file['name'],
            $file['size'],
            $_SESSION['user_id'] ?? null,
            $displayName,
        ]);

        echo json_encode(['status' => 'success']);
        exit;
    }

    // -- Hapus flowchart --
    if ($_POST['action'] === 'delete_flowchart') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT stored_filename FROM flowcharts WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            echo json_encode(['status' => 'error', 'message' => 'Data tidak ditemukan']);
            exit;
        }

        $filePath = $uploadDir . $row['stored_filename'];
        if (is_file($filePath)) {
            @unlink($filePath);
        }

        $del = $pdo->prepare("DELETE FROM flowcharts WHERE id = ?");
        $del->execute([$id]);

        echo json_encode(['status' => 'success']);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Aksi tidak dikenal']);
    exit;
}

// ── Ambil daftar flowchart ───────────────────────────────────────────────────
$flowcharts = $pdo->query("SELECT * FROM flowcharts ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

function formatFileSize(int $bytes): string
{
    if ($bytes >= 1024 * 1024) {
        return round($bytes / (1024 * 1024), 1) . ' MB';
    }
    return round($bytes / 1024, 1) . ' KB';
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flowchart</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #f8fafc;
        }

        .fade-in {
            animation: fadeIn .25s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(6px);
            }

            to {
                opacity: 1;
                transform: none;
            }
        }

        /* ── Sidebar layout (sama seperti halaman lain) ── */
        #app-layout {
            display: flex;
            min-height: 100vh;
        }

        #sidebar {
            width: 220px;
            min-width: 220px;
            background: #fff;
            border-right: 1px solid #e2e8f0;
            box-shadow: 2px 0 12px rgba(0, 0, 0, .04);
            display: flex;
            flex-direction: column;
            transition: width .25s ease, min-width .25s ease;
            overflow: hidden;
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            z-index: 100;
        }

        #sidebar.collapsed {
            width: 56px;
            min-width: 56px;
        }

        #sidebar-logo {
            display: flex;
            align-items: center;
            gap: .65rem;
            padding: .75rem .75rem;
            border-bottom: 1px solid #f1f5f9;
            min-height: 56px;
            transition: padding .25s ease;
        }

        #sidebar.collapsed #sidebar-logo {
            justify-content: center;
            padding: .75rem 0;
        }

        #sidebar-logo .logo-icon {
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, #5f0f40, #7a1a5a);
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: .9rem;
            flex-shrink: 0;
        }

        #sidebar-logo .logo-text {
            font-weight: 800;
            font-size: .95rem;
            color: #1e293b;
            white-space: nowrap;
            overflow: hidden;
            transition: opacity .2s, width .2s;
            opacity: 1;
            width: 140px;
        }

        #sidebar.collapsed #sidebar-logo .logo-text {
            opacity: 0;
            width: 0;
        }

        .sidebar-nav {
            display: flex;
            flex-direction: column;
            gap: .2rem;
            flex: 1;
            padding: .5rem .5rem;
        }

        .sidebar-back {
            display: flex;
            align-items: center;
            gap: .65rem;
            padding: .55rem .65rem;
            border-radius: 10px;
            font-size: .82rem;
            font-weight: 600;
            color: #64748b;
            text-decoration: none;
            transition: background .15s, color .15s;
            white-space: nowrap;
            overflow: hidden;
        }

        .sidebar-back:hover {
            background: #f1f5f9;
            color: #1e293b;
        }

        .sidebar-back .sb-icon {
            width: 28px;
            text-align: center;
            flex-shrink: 0;
        }

        .sidebar-back .sb-label {
            transition: opacity .2s, width .2s;
            opacity: 1;
        }

        #sidebar.collapsed .sidebar-back {
            justify-content: center;
            padding: .55rem 0;
        }

        #sidebar.collapsed .sb-label {
            opacity: 0;
            width: 0;
            overflow: hidden;
        }

        .nav-pill {
            display: flex;
            align-items: center;
            gap: .65rem;
            width: 100%;
            padding: .6rem .65rem;
            border-radius: 10px;
            font-size: .85rem;
            font-weight: 700;
            color: #475569;
            background: none;
            border: none;
            cursor: pointer;
            text-align: left;
            text-decoration: none;
            transition: background .15s, color .15s;
            white-space: nowrap;
            overflow: hidden;
        }

        .nav-pill .np-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .9rem;
            background: #f1f5f9;
            color: #64748b;
            flex-shrink: 0;
            transition: background .15s, color .15s;
        }

        .nav-pill .np-label {
            transition: opacity .2s, width .2s;
            opacity: 1;
        }

        #sidebar.collapsed .np-label {
            opacity: 0;
            width: 0;
            overflow: hidden;
        }

        #sidebar.collapsed .nav-pill {
            justify-content: center;
            padding: .6rem 0;
            gap: 0;
        }

        #sidebar.collapsed .sidebar-nav {
            align-items: center;
        }

        .nav-pill.active-schedule,
        .nav-pill.active-history,
        .nav-pill.active-flowchart {
            background: #f9eef5;
            color: #5f0f40;
        }

        .nav-pill.active-schedule .np-icon,
        .nav-pill.active-history .np-icon,
        .nav-pill.active-flowchart .np-icon {
            background: #5f0f40;
            color: #fff;
        }

        .nav-pill:not([class*="active"]):hover {
            background: #f1f5f9;
            color: #1e293b;
        }

        .nav-pill:not([class*="active"]):hover .np-icon {
            background: #e2e8f0;
            color: #334155;
        }

        #sidebar-footer {
            border-top: 1px solid #f1f5f9;
            padding: .5rem;
            display: flex;
            justify-content: flex-end;
        }

        #sidebar.collapsed #sidebar-footer {
            justify-content: center;
        }

        #sidebarToggle {
            background: none;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            width: 30px;
            height: 30px;
            cursor: pointer;
            color: #94a3b8;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .75rem;
            transition: background .15s, color .15s;
        }

        #sidebarToggle:hover {
            background: #f1f5f9;
            color: #475569;
        }

        #main-content {
            margin-left: 220px;
            transition: margin-left .25s ease;
            min-height: 100vh;
            padding: 1.5rem 2rem;
        }

        body.sidebar-collapsed #main-content {
            margin-left: 56px;
        }

        @media (max-width: 768px) {
            #sidebar {
                display: none;
            }

            #main-content {
                margin-left: 0 !important;
                padding: 1rem;
            }
        }

        /* ── Flowchart card ── */
        .fc-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            overflow: hidden;
            transition: box-shadow .15s, transform .15s;
        }

        .fc-card:hover {
            box-shadow: 0 8px 24px rgba(15, 23, 42, .08);
            transform: translateY(-2px);
        }

        .fc-thumb {
            height: 120px;
            background: linear-gradient(135deg, #fef2f2, #fee2e2);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .fc-thumb i {
            font-size: 2.75rem;
            color: #dc2626;
        }

        /* Drag & drop zone */
        #dropZone {
            border: 2px dashed #cbd5e1;
            border-radius: 14px;
            transition: border-color .15s, background .15s;
        }

        #dropZone.dragover {
            border-color: #5f0f40;
            background: #f9eef5;
        }
    </style>
</head>

<body class="min-h-screen" style="background:#f8fafc;">

    <div id="app-layout">

        <!-- ═══════════════════ SIDEBAR ═══════════════════ -->
        <nav id="sidebar" class="collapsed">
            <div id="sidebar-logo">
                <div class="logo-icon"><i class="fas fa-sitemap"></i></div>
                <span class="logo-text">Schedule</span>
            </div>
            <div class="sidebar-nav">
                <a href="index.php" class="sidebar-back">
                    <span class="sb-icon"><i class="fas fa-arrow-left"></i></span>
                    <span class="sb-label">Back to Hub</span>
                </a>
                <div style="height:1px;background:#f1f5f9;margin:.4rem 0;"></div>
                <a href="dashboard_user.php" class="nav-pill">
                    <span class="np-icon"><i class="fas fa-calendar-check"></i></span>
                    <span class="np-label">Schedule</span>
                </a>
                <a href="history_maintenance.php" class="nav-pill">
                    <span class="np-icon"><i class="fas fa-history"></i></span>
                    <span class="np-label">History</span>
                </a>
                <a href="flowchart.php" class="nav-pill active-flowchart">
                    <span class="np-icon"><i class="fas fa-sitemap"></i></span>
                    <span class="np-label">Flowchart</span>
                </a>
            </div>
            <div id="sidebar-footer">
                <button id="sidebarToggle" title="Toggle sidebar">
                    <i class="fas fa-chevron-right" id="sidebarToggleIcon"></i>
                </button>
            </div>
        </nav>

        <!-- ═══════════════════ MAIN CONTENT ═══════════════════ -->
        <div id="main-content">
            <div class="max-w-[1400px] mx-auto">

                <!-- HEADER -->
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
                    <div>
                        <h1 class="text-2xl font-bold text-slate-800 tracking-tight">Flowchart</h1>
                        <p class="text-slate-500 mt-1 text-sm">Diagram alur proses maintenance (PDF)</p>
                    </div>
                    <div class="flex items-center gap-3 flex-wrap">
                        <div class="flex items-center gap-2.5 bg-white border border-slate-200 px-3.5 py-2 rounded-xl shadow-sm">
                            <div class="w-7 h-7 rounded-lg bg-[#5f0f40] flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-user text-white text-xs"></i>
                            </div>
                            <span class="text-sm font-semibold text-slate-700"><?= htmlspecialchars($displayName) ?></span>
                        </div>
                        <button onclick="showUploadModal()"
                            class="bg-[#5f0f40] hover:bg-[#4a0c32] text-white px-5 py-2.5 rounded-xl font-bold transition-all flex items-center gap-2 text-sm shadow-sm">
                            <i class="fas fa-upload"></i> Upload Flowchart
                        </button>
                        <a href="logout_user.php" onclick="return confirm('Apakah Anda yakin ingin keluar?')"
                            class="bg-white hover:bg-red-50 border border-slate-200 hover:border-red-200 text-slate-500 hover:text-red-600 px-4 py-2 rounded-xl font-semibold transition-all flex items-center gap-2 text-sm shadow-sm">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>

                <!-- GRID -->
                <?php if (empty($flowcharts)): ?>
                    <div class="bg-white border border-dashed border-slate-300 rounded-2xl py-16 text-center">
                        <i class="fas fa-sitemap text-4xl text-slate-300 mb-3"></i>
                        <p class="text-slate-500 font-semibold">Belum ada flowchart yang diupload</p>
                        <p class="text-slate-400 text-sm mt-1">Klik "Upload Flowchart" untuk menambahkan file PDF pertama</p>
                    </div>
                <?php else: ?>
                    <div id="fcGrid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                        <?php foreach ($flowcharts as $fc): ?>
                            <div class="fc-card fade-in" id="fcCard<?= (int)$fc['id'] ?>">
                                <div class="fc-thumb">
                                    <i class="fas fa-file-pdf"></i>
                                </div>
                                <div class="p-4">
                                    <h3 class="font-bold text-slate-800 text-sm leading-snug line-clamp-2" title="<?= htmlspecialchars($fc['title']) ?>">
                                        <?= htmlspecialchars($fc['title']) ?>
                                    </h3>
                                    <?php if (!empty($fc['description'])): ?>
                                        <p class="text-slate-500 text-xs mt-1 line-clamp-2"><?= htmlspecialchars($fc['description']) ?></p>
                                    <?php endif; ?>
                                    <div class="flex items-center gap-1.5 text-[11px] text-slate-400 mt-2.5">
                                        <i class="fas fa-user-circle"></i>
                                        <span><?= htmlspecialchars($fc['uploaded_by_name'] ?? '-') ?></span>
                                        <span>&middot;</span>
                                        <span><?= date('d M Y', strtotime($fc['created_at'])) ?></span>
                                        <span>&middot;</span>
                                        <span><?= formatFileSize((int)$fc['file_size']) ?></span>
                                    </div>
                                    <div class="flex items-center gap-2 mt-3.5 pt-3 border-t border-slate-100">
                                        <button onclick="viewFlowchart('<?= $uploadUrl . htmlspecialchars($fc['stored_filename']) ?>', '<?= htmlspecialchars(addslashes($fc['title'])) ?>')"
                                            class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 py-2 rounded-lg text-xs font-bold transition flex items-center justify-center gap-1.5">
                                            <i class="fas fa-eye"></i> Lihat
                                        </button>
                                        <a href="<?= $uploadUrl . htmlspecialchars($fc['stored_filename']) ?>" download="<?= htmlspecialchars($fc['original_filename']) ?>"
                                            class="bg-slate-100 hover:bg-slate-200 text-slate-700 w-9 h-9 rounded-lg text-xs transition flex items-center justify-center" title="Unduh">
                                            <i class="fas fa-download"></i>
                                        </a>
                                        <button onclick="deleteFlowchart(<?= (int)$fc['id'] ?>)"
                                            class="bg-red-50 hover:bg-red-100 text-red-500 w-9 h-9 rounded-lg text-xs transition flex items-center justify-center" title="Hapus">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            </div>
        </div><!-- /main-content -->
    </div><!-- /app-layout -->

    <!-- ═══════════════════ MODAL: Upload Flowchart ═══════════════════ -->
    <div id="uploadModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm items-center justify-center z-50 p-4" style="display:none;">
        <div class="bg-white w-full max-w-md rounded-2xl shadow-2xl overflow-hidden">
            <div class="bg-[#5f0f40] px-5 py-4 flex justify-between items-center">
                <h3 class="text-xl font-bold text-white"><i class="fas fa-sitemap mr-2"></i>Upload Flowchart</h3>
                <button onclick="hideModal('uploadModal')" class="text-slate-200 hover:text-white transition"><i class="fas fa-times text-xl"></i></button>
            </div>
            <form id="uploadForm" class="p-5 space-y-3" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload_flowchart">

                <div>
                    <label class="block text-xs font-black text-slate-500 uppercase mb-1">Judul <span class="text-red-500">*</span></label>
                    <input type="text" name="title" required
                        placeholder="Contoh: Flowchart Preventive Maintenance"
                        class="w-full border border-slate-200 rounded-xl px-3 py-2 focus:ring-4 focus:ring-slate-100 outline-none transition text-sm">
                </div>

                <div>
                    <label class="block text-xs font-black text-slate-500 uppercase mb-1">Deskripsi (opsional)</label>
                    <textarea name="description" rows="2"
                        placeholder="Catatan singkat mengenai flowchart ini..."
                        class="w-full border border-slate-200 rounded-xl px-3 py-2 focus:ring-4 focus:ring-slate-100 outline-none transition text-sm resize-none"></textarea>
                </div>

                <div>
                    <label class="block text-xs font-black text-slate-500 uppercase mb-1">File PDF <span class="text-red-500">*</span></label>
                    <div id="dropZone" class="p-5 text-center cursor-pointer" onclick="document.getElementById('pdfFileInput').click()">
                        <i class="fas fa-cloud-upload-alt text-2xl text-slate-400 mb-2"></i>
                        <p class="text-sm text-slate-500 font-semibold" id="dropZoneText">Klik atau seret file PDF ke sini</p>
                        <p class="text-xs text-slate-400 mt-1">Maks. 20MB, format .pdf</p>
                    </div>
                    <input type="file" id="pdfFileInput" name="pdf_file" accept="application/pdf" class="hidden" required>
                </div>

                <div class="flex justify-end gap-2 pt-2 border-t border-slate-100">
                    <button type="button" onclick="hideModal('uploadModal')"
                        class="px-6 py-2 font-bold text-slate-500 hover:bg-slate-100 rounded-xl transition text-sm">Batal</button>
                    <button type="submit" id="uploadSubmitBtn"
                        class="bg-[#5f0f40] hover:bg-[#4a0c32] text-white px-8 py-2 rounded-xl font-black shadow-lg transition-all text-sm">Upload</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ═══════════════════ MODAL: View Flowchart ═══════════════════ -->
    <div id="viewModal" class="fixed inset-0 bg-slate-900/70 backdrop-blur-sm items-center justify-center z-50 p-4" style="display:none;">
        <div class="bg-white w-full max-w-4xl h-[88vh] rounded-2xl shadow-2xl overflow-hidden flex flex-col">
            <div class="bg-slate-800 px-5 py-3.5 flex justify-between items-center flex-shrink-0">
                <h3 class="text-base font-bold text-white truncate pr-4" id="viewModalTitle"><i class="fas fa-file-pdf mr-2 text-red-400"></i></h3>
                <button onclick="hideModal('viewModal')" class="text-slate-300 hover:text-white transition flex-shrink-0"><i class="fas fa-times text-xl"></i></button>
            </div>
            <iframe id="viewModalFrame" src="" class="flex-1 w-full" style="border:none;"></iframe>
        </div>
    </div>

    <script>
        // ── Sidebar toggle ──
        (function() {
            const sidebar = document.getElementById('sidebar');
            const icon = document.getElementById('sidebarToggleIcon');

            function applyState(collapsed) {
                sidebar.classList.toggle('collapsed', collapsed);
                document.body.classList.toggle('sidebar-collapsed', collapsed);
                icon.className = collapsed ? 'fas fa-chevron-right' : 'fas fa-chevron-left';
            }

            const saved = sessionStorage.getItem('schedule_sidebar');
            applyState(saved !== 'expanded');

            document.getElementById('sidebarToggle').addEventListener('click', () => {
                const isCollapsed = !sidebar.classList.contains('collapsed');
                applyState(isCollapsed);
                sessionStorage.setItem('schedule_sidebar', isCollapsed ? 'collapsed' : 'expanded');
            });
        })();

        function showModal(id) {
            document.getElementById(id).style.display = 'flex';
        }

        function hideModal(id) {
            document.getElementById(id).style.display = 'none';
            if (id === 'viewModal') {
                document.getElementById('viewModalFrame').src = '';
            }
        }

        function showUploadModal() {
            document.getElementById('uploadForm').reset();
            document.getElementById('dropZoneText').textContent = 'Klik atau seret file PDF ke sini';
            showModal('uploadModal');
        }

        function viewFlowchart(url, title) {
            document.getElementById('viewModalTitle').innerHTML = '<i class="fas fa-file-pdf mr-2 text-red-400"></i>' + title;
            document.getElementById('viewModalFrame').src = url;
            showModal('viewModal');
        }

        // ── Drag & drop file ──
        const dropZone = document.getElementById('dropZone');
        const pdfInput = document.getElementById('pdfFileInput');

        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('dragover');
        });
        dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('dragover');
            if (e.dataTransfer.files.length) {
                pdfInput.files = e.dataTransfer.files;
                updateDropZoneText();
            }
        });
        pdfInput.addEventListener('change', updateDropZoneText);

        function updateDropZoneText() {
            const f = pdfInput.files[0];
            document.getElementById('dropZoneText').textContent = f ? f.name : 'Klik atau seret file PDF ke sini';
        }

        // ── Submit upload ──
        document.getElementById('uploadForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const btn = document.getElementById('uploadSubmitBtn');
            btn.disabled = true;
            btn.textContent = 'Mengupload...';

            try {
                const res = await fetch('', {
                    method: 'POST',
                    body: new FormData(this)
                });
                const data = await res.json();

                if (data.status === 'success') {
                    location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Gagal mengupload file'));
                    btn.disabled = false;
                    btn.textContent = 'Upload';
                }
            } catch (err) {
                alert('Terjadi kesalahan saat mengupload file');
                btn.disabled = false;
                btn.textContent = 'Upload';
            }
        });

        // ── Hapus flowchart ──
        async function deleteFlowchart(id) {
            if (!confirm('Hapus flowchart ini? Tindakan tidak dapat dibatalkan.')) return;

            const formData = new FormData();
            formData.append('action', 'delete_flowchart');
            formData.append('id', id);

            const res = await fetch('', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();

            if (data.status === 'success') {
                const card = document.getElementById('fcCard' + id);
                if (card) card.remove();
            } else {
                alert('Error: ' + (data.message || 'Gagal menghapus data'));
            }
        }
    </script>

</body>

</html>