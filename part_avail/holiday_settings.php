<?php
session_start();
require_once __DIR__ . '/config.php';

$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$pdo->exec("
    CREATE TABLE IF NOT EXISTS holiday_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        holiday_date DATE NOT NULL,
        department VARCHAR(100) DEFAULT NULL,
        line VARCHAR(100) DEFAULT NULL,
        shifts VARCHAR(50) DEFAULT NULL,
        description VARCHAR(255) DEFAULT NULL,
        batch_id VARCHAR(36) DEFAULT NULL,
        created_by VARCHAR(100) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_holiday_date (holiday_date),
        KEY idx_dept_line (department, line),
        KEY idx_batch_id (batch_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

try {
    $pdo->exec("ALTER TABLE holiday_settings ADD COLUMN batch_id VARCHAR(36) DEFAULT NULL AFTER description");
} catch (\Throwable $e) {
}

requireRole([ROLE_ADMIN_MAINTENANCE, ROLE_ADMIN_CONROD, ROLE_SUPERADMIN]);
$currentUsername = $_SESSION['username'] ?? 'Unknown';
$role = $_SESSION['role'] ?? '';

$isConrodOnly = ($role === ROLE_ADMIN_CONROD);
const LOCKED_CONROD_DEPARTMENT = 'Connecting Rod';

if (isset($_GET['ajax']) && $_GET['ajax'] === 'departments') {
    header('Content-Type: application/json');
    $stmt = $pdo->query("SELECT DISTINCT department FROM machine_list ORDER BY department ASC");
    echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
    exit;
}
if (isset($_GET['ajax']) && $_GET['ajax'] === 'lines') {
    header('Content-Type: application/json');
    $dept = trim($_GET['dept'] ?? '');
    if ($dept === '') {
        echo json_encode([]);
        exit;
    }
    $stmt = $pdo->prepare("SELECT DISTINCT line FROM machine_list WHERE department = ? ORDER BY line ASC");
    $stmt->execute([$dept]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'list') {
    header('Content-Type: application/json');
    $stmt = $pdo->query("SELECT * FROM holiday_settings ORDER BY holiday_date ASC, id ASC");
    $rows = $stmt->fetchAll();

    $grouped = []; // batch_id (atau 'single-<id>') → entri gabungan
    foreach ($rows as $row) {
        $groupKey = $row['batch_id'] ?: ('single-' . $row['id']);
        if (!isset($grouped[$groupKey])) {
            $grouped[$groupKey] = [
                'id'            => $row['id'], // dipakai untuk single-date delete
                'batch_id'      => $row['batch_id'],
                'start_date'    => $row['holiday_date'],
                'end_date'      => $row['holiday_date'],
                'department'    => $row['department'],
                'line'          => $row['line'],
                'description'   => $row['description'],
                'created_by'    => $row['created_by'],
                'boundary_shifts' => $row['shifts'], // shift di tanggal awal (representatif)
                'day_count'     => 0,
            ];
        }
        $grouped[$groupKey]['end_date'] = $row['holiday_date']; // baris terakhir = tanggal akhir (data terurut ASC)
        $grouped[$groupKey]['day_count']++;
    }

    $result = array_values($grouped);
    usort($result, fn($a, $b) => strcmp($b['start_date'], $a['start_date']));

    echo json_encode($result);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $isRange     = ($_POST['mode'] ?? 'single') === 'range';
    $startDate   = trim($_POST['start_date'] ?? '');
    $endDate     = trim($_POST['end_date'] ?? '');
    $department  = trim($_POST['department'] ?? '');
    $line        = trim($_POST['line'] ?? '');
    $shifts      = isset($_POST['shifts']) && is_array($_POST['shifts']) ? $_POST['shifts'] : [];
    $description = trim($_POST['description'] ?? '');

    if ($isConrodOnly) {
        $department = LOCKED_CONROD_DEPARTMENT;
    }

    if (!$isRange) {
        $endDate = $startDate; // mode tanggal tunggal: awal = akhir
    }

    if (!$startDate || !strtotime($startDate) || !$endDate || !strtotime($endDate)) {
        echo json_encode(['success' => false, 'message' => 'Tanggal libur wajib diisi dan valid.']);
        exit;
    }
    if (strtotime($endDate) < strtotime($startDate)) {
        echo json_encode(['success' => false, 'message' => 'Tanggal selesai tidak boleh lebih awal dari tanggal mulai.']);
        exit;
    }

    $totalDays = (int) round((strtotime($endDate) - strtotime($startDate)) / 86400) + 1;
    if ($totalDays > 366) {
        echo json_encode(['success' => false, 'message' => 'Rentang tanggal terlalu panjang (maksimal 366 hari).']);
        exit;
    }

    $validShifts = array_values(array_intersect($shifts, ['Shift 1', 'Shift 2', 'Shift 3']));
    $boundaryShiftsToStore = (count($validShifts) === 3 || count($validShifts) === 0) ? null : implode(',', $validShifts);
    $batchId = ($totalDays > 1) ? bin2hex(random_bytes(16)) : null;

    $stmt = $pdo->prepare("
        INSERT INTO holiday_settings (holiday_date, department, line, shifts, description, batch_id, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    try {
        $pdo->beginTransaction();
        $cursor = strtotime($startDate);
        $endTs  = strtotime($endDate);
        while ($cursor <= $endTs) {
            $curDateStr = date('Y-m-d', $cursor);
            $isBoundary = ($curDateStr === $startDate || $curDateStr === $endDate);
            $shiftsToStore = $isBoundary ? $boundaryShiftsToStore : null;

            $stmt->execute([
                $curDateStr,
                $department !== '' ? $department : null,
                $line !== '' ? $line : null,
                $shiftsToStore,
                $description !== '' ? $description : null,
                $batchId,
                $currentUsername,
            ]);
            $cursor = strtotime('+1 day', $cursor);
        }
        $pdo->commit();

        $msg = $isRange
            ? "Hari libur $startDate s/d $endDate berhasil ditambahkan ($totalDays hari)."
            : 'Hari libur berhasil ditambahkan.';
        echo json_encode(['success' => true, 'message' => $msg]);
    } catch (\Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan: ' . $e->getMessage()]);
    }
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $batchId = trim($_POST['batch_id'] ?? '');
    $id      = (int)($_POST['id'] ?? 0);

    if ($batchId !== '') {
        if ($isConrodOnly) {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM holiday_settings WHERE batch_id = ? AND (department IS NULL OR department <> ?)");
            $chk->execute([$batchId, LOCKED_CONROD_DEPARTMENT]);
            if ((int)$chk->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => 'Anda hanya bisa menghapus hari libur untuk department Connecting Rod.']);
                exit;
            }
        }
        $stmt = $pdo->prepare("DELETE FROM holiday_settings WHERE batch_id = ?");
        $stmt->execute([$batchId]);
        echo json_encode(['success' => true, 'message' => 'Rentang hari libur dihapus (' . $stmt->rowCount() . ' hari).']);
        exit;
    }

    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'ID tidak valid.']);
        exit;
    }
    if ($isConrodOnly) {
        $chk = $pdo->prepare("SELECT department FROM holiday_settings WHERE id = ?");
        $chk->execute([$id]);
        if ($chk->fetchColumn() !== LOCKED_CONROD_DEPARTMENT) {
            echo json_encode(['success' => false, 'message' => 'Anda hanya bisa menghapus hari libur untuk department Connecting Rod.']);
            exit;
        }
    }
    $stmt = $pdo->prepare("DELETE FROM holiday_settings WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(['success' => true, 'message' => 'Hari libur dihapus.']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Pengaturan Hari Libur — Maintenance Hub</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body {
            background: #f8fafc;
        }

        .card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
        }

        .chip {
            display: inline-flex;
            align-items: center;
            padding: 2px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
        }

        .chip-all {
            background: #fee2e2;
            color: #b91c1c;
        }

        .chip-shift {
            background: #dbeafe;
            color: #1d4ed8;
            margin-right: 4px;
        }

        .toast {
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 12px 18px;
            border-radius: 10px;
            color: #fff;
            font-size: 14px;
            opacity: 0;
            transform: translateY(10px);
            transition: .25s;
            z-index: 999;
        }

        .toast.show {
            opacity: 1;
            transform: translateY(0);
        }

        .toast.success {
            background: #16a34a;
        }

        .toast.error {
            background: #dc2626;
        }
    </style>
</head>

<body class="p-6">
    <div class="max-w-5xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <div>
                <a href="dashboard_report.php" class="text-slate-400 hover:text-slate-600 text-sm"><i class="fas fa-arrow-left mr-1"></i> Kembali ke E-Reports</a>
                <h1 class="text-2xl font-bold text-slate-800 mt-1"><i class="fas fa-calendar-xmark text-red-500 mr-2"></i>Pengaturan Hari Libur</h1>
                <p class="text-slate-500 text-sm mt-1">Downtime yang jatuh di tanggal/shift yang ditandai libur di sini tidak akan dihitung sebagai downtime mesin (hanya "Waktu Produktif" yang dihitung).</p>
            </div>
            <button onclick="openAddModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-semibold">
                <i class="fas fa-plus mr-1"></i> Tambah Hari Libur
            </button>
        </div>

        <div class="card p-0 overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-500 text-xs uppercase">
                    <tr>
                        <th class="text-left px-4 py-3">Tanggal</th>
                        <th class="text-left px-4 py-3">Department</th>
                        <th class="text-left px-4 py-3">Line</th>
                        <th class="text-left px-4 py-3">Shift Libur</th>
                        <th class="text-left px-4 py-3">Keterangan</th>
                        <th class="text-left px-4 py-3">Dibuat Oleh</th>
                        <th class="text-right px-4 py-3">Aksi</th>
                    </tr>
                </thead>
                <tbody id="holiday-tbody"></tbody>
            </table>
            <div id="empty-state" class="hidden text-center text-slate-400 py-10">Belum ada hari libur yang diatur.</div>
        </div>
    </div>

    <!-- Modal Tambah -->
    <div id="modal-overlay" class="fixed inset-0 bg-black/40 hidden items-center justify-center z-50" onclick="if(event.target===this) closeAddModal()">
        <div class="bg-white rounded-xl p-6 w-full max-w-md">
            <h2 class="text-lg font-bold text-slate-800 mb-4">Tambah Hari Libur</h2>
            <div class="space-y-3">
                <div>
                    <label class="text-xs font-semibold text-slate-500">Jenis</label>
                    <div class="flex gap-2 mt-1">
                        <button type="button" id="mode-btn-single" onclick="setMode('single')"
                            class="flex-1 border rounded-lg py-2 text-sm font-semibold">Satu Hari</button>
                        <button type="button" id="mode-btn-range" onclick="setMode('range')"
                            class="flex-1 border rounded-lg py-2 text-sm font-semibold">Rentang Tanggal</button>
                    </div>
                </div>

                <div id="single-date-field">
                    <label class="text-xs font-semibold text-slate-500">Tanggal *</label>
                    <input type="date" id="f-date" class="w-full border rounded-lg px-3 py-2 mt-1">
                </div>

                <div id="range-date-fields" class="hidden grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs font-semibold text-slate-500">Tanggal Mulai *</label>
                        <input type="date" id="f-start-date" class="w-full border rounded-lg px-3 py-2 mt-1">
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-slate-500">Tanggal Selesai *</label>
                        <input type="date" id="f-end-date" class="w-full border rounded-lg px-3 py-2 mt-1">
                    </div>
                    <p class="col-span-2 text-xs text-slate-400 -mt-1">
                        Shift yang dicentang di bawah berlaku untuk tanggal <b>mulai</b> & <b>selesai</b>.
                        Tanggal di antaranya otomatis libur <b>sehari penuh</b> (semua shift).
                    </p>
                </div>

                <?php if ($isConrodOnly): ?>
                    <div>
                        <label class="text-xs font-semibold text-slate-500">Department</label>
                        <input type="text" value="Connecting Rod" disabled class="w-full border rounded-lg px-3 py-2 mt-1 bg-slate-100 text-slate-500">
                    </div>
                <?php else: ?>
                    <div>
                        <label class="text-xs font-semibold text-slate-500">Department <span class="font-normal">(kosongkan = semua department)</span></label>
                        <select id="f-department" class="w-full border rounded-lg px-3 py-2 mt-1" onchange="loadLinesForDept()">
                            <option value="">— Semua Department —</option>
                        </select>
                    </div>
                <?php endif; ?>
                <div>
                    <label class="text-xs font-semibold text-slate-500">Line <span class="font-normal">(kosongkan = semua line)</span></label>
                    <select id="f-line" class="w-full border rounded-lg px-3 py-2 mt-1">
                        <option value="">— Semua Line —</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-semibold text-slate-500" id="f-shift-label">Shift yang Libur <span class="font-normal">(kosongkan semua = libur sehari penuh)</span></label>
                    <div class="flex gap-4 mt-2">
                        <label class="flex items-center gap-1 text-sm"><input type="checkbox" class="f-shift" value="Shift 1"> Shift 1</label>
                        <label class="flex items-center gap-1 text-sm"><input type="checkbox" class="f-shift" value="Shift 2"> Shift 2</label>
                        <label class="flex items-center gap-1 text-sm"><input type="checkbox" class="f-shift" value="Shift 3"> Shift 3</label>
                    </div>
                    <p class="text-[11px] text-slate-400 mt-1">Shift 3 dimulai malam tanggal yang dipilih dan berlanjut sampai pagi hari berikutnya.</p>
                </div>
                <div>
                    <label class="text-xs font-semibold text-slate-500">Keterangan</label>
                    <input type="text" id="f-description" placeholder="mis. Idul Fitri, Cuti Bersama" class="w-full border rounded-lg px-3 py-2 mt-1">
                </div>
            </div>
            <div class="flex justify-end gap-2 mt-5">
                <button onclick="closeAddModal()" class="px-4 py-2 rounded-lg text-sm text-slate-500 hover:bg-slate-100">Batal</button>
                <button onclick="submitHoliday()" id="btn-save" class="px-4 py-2 rounded-lg text-sm bg-blue-600 hover:bg-blue-700 text-white font-semibold">Simpan</button>
            </div>
        </div>
    </div>

    <div id="toast" class="toast"></div>

    <script>
        const IS_CONROD_ONLY = <?= $isConrodOnly ? 'true' : 'false' ?>;
        const LOCKED_DEPARTMENT = '<?= LOCKED_CONROD_DEPARTMENT ?>';

        function showToast(msg, type = 'success') {
            const t = document.getElementById('toast');
            t.textContent = msg;
            t.className = 'toast show ' + type;
            clearTimeout(t._timer);
            t._timer = setTimeout(() => t.classList.remove('show'), 3000);
        }

        function esc(str) {
            const d = document.createElement('div');
            d.textContent = str ?? '';
            return d.innerHTML;
        }

        function fmtDate(d) {
            if (!d) return '—';
            const dt = new Date(d + 'T00:00:00');
            return dt.toLocaleDateString('id-ID', {
                day: '2-digit',
                month: 'short',
                year: 'numeric'
            });
        }

        function loadHolidays() {
            fetch('holiday_settings.php?ajax=list')
                .then(r => r.json())
                .then(entries => {
                    const tbody = document.getElementById('holiday-tbody');
                    const empty = document.getElementById('empty-state');
                    tbody.innerHTML = '';
                    empty.classList.toggle('hidden', entries.length > 0);
                    entries.forEach(entry => {
                        const isRange = entry.day_count > 1;
                        const dateLabel = isRange ?
                            `${fmtDate(entry.start_date)} – ${fmtDate(entry.end_date)} <span class="text-xs text-slate-400 font-normal">(${entry.day_count} hari)</span>` :
                            fmtDate(entry.start_date);

                        let shiftHtml = '<span class="chip chip-all">Sehari Penuh</span>';
                        if (entry.boundary_shifts) {
                            shiftHtml = entry.boundary_shifts.split(',').map(s => `<span class="chip chip-shift">${esc(s.trim())}</span>`).join('');
                        }
                        if (isRange) {
                            shiftHtml += `<div class="text-[11px] text-slate-400 mt-1">Awal/Akhir seperti di atas — hari di antaranya sehari penuh</div>`;
                        }

                        const deleteArg = entry.batch_id ? `null, '${entry.batch_id}'` : `${entry.id}, null`;
                        const canDelete = !IS_CONROD_ONLY || entry.department === LOCKED_DEPARTMENT;
                        const deleteBtn = canDelete ?
                            `<button onclick="deleteHoliday(${deleteArg})" class="text-red-500 hover:text-red-700"><i class="fas fa-trash"></i></button>` :
                            `<span class="text-slate-300" title="Bukan department Connecting Rod"><i class="fas fa-lock"></i></span>`;

                        tbody.insertAdjacentHTML('beforeend', `
                            <tr class="border-t align-top">
                                <td class="px-4 py-3 font-semibold text-slate-700">${dateLabel}</td>
                                <td class="px-4 py-3">${esc(entry.department) || '<span class="text-slate-400">Semua</span>'}</td>
                                <td class="px-4 py-3">${esc(entry.line) || '<span class="text-slate-400">Semua</span>'}</td>
                                <td class="px-4 py-3">${shiftHtml}</td>
                                <td class="px-4 py-3 text-slate-600">${esc(entry.description) || '—'}</td>
                                <td class="px-4 py-3 text-slate-400">${esc(entry.created_by) || '—'}</td>
                                <td class="px-4 py-3 text-right">
                                    ${deleteBtn}
                                </td>
                            </tr>
                        `);
                    });
                });
        }

        function deleteHoliday(id, batchId) {
            const msg = batchId ? 'Hapus seluruh rentang hari libur ini?' : 'Hapus hari libur ini?';
            if (!confirm(msg)) return;
            const fd = new FormData();
            if (batchId) fd.append('batch_id', batchId);
            else fd.append('id', id);
            fetch('holiday_settings.php?ajax=delete', {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json())
                .then(res => {
                    showToast(res.message, res.success ? 'success' : 'error');
                    if (res.success) loadHolidays();
                });
        }

        function loadDepartments() {
            if (IS_CONROD_ONLY) {
                loadLinesForDept(); // langsung load line untuk department yang dikunci
                return;
            }
            fetch('holiday_settings.php?ajax=departments')
                .then(r => r.json())
                .then(list => {
                    const sel = document.getElementById('f-department');
                    list.forEach(d => sel.insertAdjacentHTML('beforeend', `<option value="${esc(d)}">${esc(d)}</option>`));
                });
        }

        function loadLinesForDept() {
            const dept = IS_CONROD_ONLY ? LOCKED_DEPARTMENT : document.getElementById('f-department').value;
            const sel = document.getElementById('f-line');
            sel.innerHTML = '<option value="">— Semua Line —</option>';
            if (!dept) return;
            fetch('holiday_settings.php?ajax=lines&dept=' + encodeURIComponent(dept))
                .then(r => r.json())
                .then(list => {
                    list.forEach(l => sel.insertAdjacentHTML('beforeend', `<option value="${esc(l)}">${esc(l)}</option>`));
                });
        }

        let currentMode = 'single';

        function setMode(mode) {
            currentMode = mode;
            document.getElementById('single-date-field').classList.toggle('hidden', mode !== 'single');
            document.getElementById('range-date-fields').classList.toggle('hidden', mode !== 'range');
            document.getElementById('f-shift-label').innerHTML = mode === 'range' ?
                'Shift Libur di Tanggal Mulai & Selesai <span class="font-normal">(kosongkan semua = sehari penuh)</span>' :
                'Shift yang Libur <span class="font-normal">(kosongkan semua = libur sehari penuh)</span>';

            const activeCls = ['bg-blue-600', 'text-white', 'border-blue-600'];
            const inactiveCls = ['text-slate-500'];
            const btnSingle = document.getElementById('mode-btn-single');
            const btnRange = document.getElementById('mode-btn-range');
            btnSingle.classList.remove(...activeCls, ...inactiveCls);
            btnRange.classList.remove(...activeCls, ...inactiveCls);
            (mode === 'single' ? btnSingle : btnRange).classList.add(...activeCls);
            (mode === 'single' ? btnRange : btnSingle).classList.add(...inactiveCls);
        }

        function openAddModal() {
            document.getElementById('f-date').value = '';
            document.getElementById('f-start-date').value = '';
            document.getElementById('f-end-date').value = '';
            if (!IS_CONROD_ONLY) document.getElementById('f-department').value = '';
            document.getElementById('f-line').innerHTML = '<option value="">— Semua Line —</option>';
            document.getElementById('f-description').value = '';
            document.querySelectorAll('.f-shift').forEach(cb => cb.checked = false);
            setMode('single');
            if (IS_CONROD_ONLY) loadLinesForDept();
            document.getElementById('modal-overlay').classList.remove('hidden');
            document.getElementById('modal-overlay').classList.add('flex');
        }

        function closeAddModal() {
            document.getElementById('modal-overlay').classList.add('hidden');
            document.getElementById('modal-overlay').classList.remove('flex');
        }

        function submitHoliday() {
            const fd = new FormData();
            fd.append('mode', currentMode);

            if (currentMode === 'single') {
                const date = document.getElementById('f-date').value;
                if (!date) {
                    showToast('Tanggal wajib diisi.', 'error');
                    return;
                }
                fd.append('start_date', date);
                fd.append('end_date', date);
            } else {
                const startDate = document.getElementById('f-start-date').value;
                const endDate = document.getElementById('f-end-date').value;
                if (!startDate || !endDate) {
                    showToast('Tanggal mulai & selesai wajib diisi.', 'error');
                    return;
                }
                if (endDate < startDate) {
                    showToast('Tanggal selesai tidak boleh sebelum tanggal mulai.', 'error');
                    return;
                }
                fd.append('start_date', startDate);
                fd.append('end_date', endDate);
            }

            fd.append('department', IS_CONROD_ONLY ? LOCKED_DEPARTMENT : document.getElementById('f-department').value);
            fd.append('line', document.getElementById('f-line').value);
            fd.append('description', document.getElementById('f-description').value);
            document.querySelectorAll('.f-shift:checked').forEach(cb => fd.append('shifts[]', cb.value));

            const btn = document.getElementById('btn-save');
            btn.disabled = true;
            fetch('holiday_settings.php?ajax=create', {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json())
                .then(res => {
                    showToast(res.message, res.success ? 'success' : 'error');
                    if (res.success) {
                        closeAddModal();
                        loadHolidays();
                    }
                })
                .finally(() => btn.disabled = false);
        }

        loadDepartments();
        loadHolidays();
    </script>
</body>

</html>