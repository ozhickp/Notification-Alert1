<?php
include 'config.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'user') {
    header('Location: login_user.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    try {
        if ($_POST['action'] === 'add_part') {
            $itemCode  = trim($_POST['item_code'] ?? '');
            $itemDesc  = trim($_POST['item_description'] ?? '');
            $safety    = (int)($_POST['safety_stock'] ?? 0);
            $actual    = (int)($_POST['actual_stock'] ?? 0);
            if (!$itemCode) {
                echo json_encode(['status' => 'error', 'message' => 'Item Code wajib diisi']);
                exit;
            }
            // Cek apakah item_code sudah ada (duplikat)
            $chkStmt = $pdo->prepare("SELECT id FROM expenses_part WHERE item_code = ? LIMIT 1");
            $chkStmt->execute([$itemCode]);
            if ($chkStmt->fetchColumn()) {
                echo json_encode(['status' => 'error', 'message' => "Item Code '{$itemCode}' sudah ada. Gunakan Edit untuk mengubah stok."]);
                exit;
            }
            $effective = $actual - $safety;
            $status    = getPartStatusStr($actual, $safety);
            $stmt = $pdo->prepare("INSERT INTO expenses_part (item_code,item_description,safety_stock,actual_stock,effective_stock,status) VALUES (?,?,?,?,?,?)");
            $stmt->execute([$itemCode, $itemDesc, $safety, $actual, $effective, $status]);
            $pid = (int)$pdo->lastInsertId();
            if ($actual > 0) $pdo->prepare("INSERT INTO stock_log (part_id,change_amount,note,changed_by) VALUES (?,?,?,?)")->execute([$pid, $actual, 'Initial stock', $_SESSION['user_id'] ?? null]);
            echo json_encode(['status' => 'success', 'message' => "Part '{$itemCode}' berhasil ditambahkan."]);
        } elseif ($_POST['action'] === 'update_stock') {
            $partId = (int)$_POST['part_id'];
            $change = (int)$_POST['change_amount'];
            $row = $pdo->prepare("SELECT actual_stock,safety_stock FROM expenses_part WHERE id=?");
            $row->execute([$partId]);
            $part = $row->fetch(PDO::FETCH_ASSOC);
            if (!$part) {
                echo json_encode(['status' => 'error', 'message' => 'Part tidak ditemukan']);
                exit;
            }
            $newActual = (int)$part['actual_stock'] + $change;
            if ($newActual < 0) {
                echo json_encode(['status' => 'error', 'message' => 'Stok tidak boleh negatif']);
                exit;
            }
            $safety = (int)$part['safety_stock'];
            $newEffective = $newActual - $safety;
            $newStatus = getPartStatusStr($newActual, $safety);
            $pdo->prepare("UPDATE expenses_part SET actual_stock=?,effective_stock=?,status=? WHERE id=?")->execute([$newActual, $newEffective, $newStatus, $partId]);
            $pdo->prepare("INSERT INTO stock_log (part_id,change_amount,note,changed_by) VALUES (?,?,?,?)")->execute([$partId, $change, '', $_SESSION['user_id'] ?? null]);
            echo json_encode(['status' => 'success', 'message' => 'Stok diperbarui', 'new_stock' => $newActual, 'new_effective' => $newEffective, 'new_status' => $newStatus]);
        } elseif ($_POST['action'] === 'import_parts') {
            if (!isset($_FILES['parts_file'])) {
                echo json_encode(['status' => 'error', 'message' => 'File tidak ada']);
                exit;
            }
            require_once __DIR__ . '/vendor/autoload.php';
            $file = $_FILES['parts_file'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $tmpPath = sys_get_temp_dir() . '/parts_' . uniqid() . '.' . $ext;
            move_uploaded_file($file['tmp_name'], $tmpPath);
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($tmpPath);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($tmpPath);
            @unlink($tmpPath);
            $sheetNames = $spreadsheet->getSheetNames();
            $targetSheet = null;
            foreach ($sheetNames as $sn) {
                if (stripos($sn, 'maintenance') === false) {
                    $targetSheet = $sn;
                    break;
                }
            }
            $sheet = $targetSheet ? $spreadsheet->getSheetByName($targetSheet) : $spreadsheet->getActiveSheet();
            $highestRow = $sheet->getHighestDataRow();
            $maxCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
            $HEADER_MAP = ['ITEM CODE' => 'item_code', 'ITEM DESCRIPTION' => 'item_description', 'SAFETY STOCK' => 'safety_stock', 'QTY ACTUAL' => 'actual_stock', 'ACTUAL STOCK' => 'actual_stock', 'AMOUNT ACTUAL STOCK' => 'actual_stock'];
            $colMap = [];
            $headerRow = null;
            for ($r = 1; $r <= min($highestRow, 10); $r++) {
                $tmp = [];
                for ($c = 1; $c <= $maxCol; $c++) {
                    $cl = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
                    $val = mb_strtoupper(trim((string)$sheet->getCell($cl . $r)->getValue()));
                    if (isset($HEADER_MAP[$val]) && !isset($tmp[$HEADER_MAP[$val]])) $tmp[$HEADER_MAP[$val]] = $c;
                }
                if (isset($tmp['item_code'])) {
                    $headerRow = $r;
                    $colMap = $tmp;
                    break;
                }
            }
            if (!$headerRow) {
                echo json_encode(['status' => 'error', 'message' => 'Header tidak ditemukan.']);
                exit;
            }
            $stmtInsertPart = $pdo->prepare("INSERT INTO expenses_part (item_code,item_description,safety_stock,actual_stock,effective_stock,status) VALUES (?,?,?,?,?,?)");
            $stmtUpdatePart = $pdo->prepare("UPDATE expenses_part SET item_description=?,safety_stock=?,actual_stock=?,effective_stock=?,status=? WHERE item_code=?");
            $stmtCheckPart  = $pdo->prepare("SELECT id FROM expenses_part WHERE item_code = ? LIMIT 1");
            $success = 0;
            $updated = 0;
            $skipped = 0;
            $errors  = [];
            for ($r = $headerRow + 1; $r <= $highestRow; $r++) {
                $g = function ($col) use ($sheet, $colMap, $r) {
                    if (!isset($colMap[$col])) return null;
                    $cl = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colMap[$col]);
                    $v = $sheet->getCell($cl . $r)->getValue();
                    return ($v instanceof \DateTimeInterface) ? null : trim((string)$v);
                };
                $code = $g('item_code');
                if (!$code || !trim($code)) {
                    $skipped++;
                    continue;
                }
                $safety   = (int)($g('safety_stock') ?? 0);
                $actual   = (int)($g('actual_stock') ?? 0);
                $desc     = $g('item_description');
                $effective = $actual - $safety;
                $status    = getPartStatusStr($actual, $safety);
                try {
                    // UPSERT: cek apakah item_code sudah ada
                    $stmtCheckPart->execute([$code]);
                    $existingId = $stmtCheckPart->fetchColumn();
                    if ($existingId) {
                        // Sudah ada → UPDATE data lama
                        $stmtUpdatePart->execute([$desc, $safety, $actual, $effective, $status, $code]);
                        $updated++;
                    } else {
                        // Belum ada → INSERT baru
                        $stmtInsertPart->execute([$code, $desc, $safety, $actual, $effective, $status]);
                        $success++;
                    }
                } catch (\Exception $e) {
                    $errors[] = "Baris $r: " . $e->getMessage();
                }
            }
            $total = $success + $updated;
            $msg   = "{$total} part berhasil diimport ({$success} baru, {$updated} diperbarui)";
            if ($skipped) $msg .= ", {$skipped} dilewati";
            if (!empty($errors)) $msg .= ', ' . count($errors) . ' gagal';
            echo json_encode(['status' => $total > 0 ? 'success' : 'error', 'message' => $msg, 'inserted' => $success, 'updated' => $updated, 'skipped' => $skipped, 'errors' => array_slice($errors, 0, 5), 'sheet' => $targetSheet]);
        }
    } catch (\Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

if (isset($_GET['get_part'])) {
    header('Content-Type: application/json');
    $s = $pdo->prepare("SELECT * FROM expenses_part WHERE id=?");
    $s->execute([(int)$_GET['get_part']]);
    echo json_encode($s->fetch(PDO::FETCH_ASSOC));
    exit;
}

function getPartStatusStr(int $actual, int $safety): string
{
    if ($actual === 0)       return 'Zero Stock';
    if ($actual < $safety)   return 'Low Stock';
    if ($actual === $safety) return 'In Stock';
    return                          'Over Stock';
}

// Return CSS class name yang ada di <style> hardcoded — BUKAN Tailwind
function getPartStatusClass(string $status): string
{
    return match ($status) {
        'Zero Stock' => 'badge-zero',
        'Low Stock'  => 'badge-low',
        'In Stock'   => 'badge-in',
        'Over Stock' => 'badge-over',
        default      => 'badge-none',
    };
}

$parts      = $pdo->query("SELECT * FROM expenses_part ORDER BY item_code ASC")->fetchAll(PDO::FETCH_ASSOC);
$totalParts = count($parts);
$zeroStock  = count(array_filter($parts, fn($p) => (int)$p['actual_stock'] === 0));
$lowStock   = count(array_filter($parts, fn($p) => (int)$p['actual_stock'] > 0 && (int)$p['actual_stock'] < (int)$p['safety_stock']));
$inStock    = count(array_filter($parts, fn($p) => (int)$p['actual_stock'] === (int)$p['safety_stock']));
$overstock  = count(array_filter($parts, fn($p) => (int)$p['actual_stock'] > (int)$p['safety_stock']));

$partsByCategory = [
    'Zero Stock' => array_values(array_filter($parts, fn($p) => (int)$p['actual_stock'] === 0)),
    'Low Stock'  => array_values(array_filter($parts, fn($p) => (int)$p['actual_stock'] > 0 && (int)$p['actual_stock'] < (int)$p['safety_stock'])),
    'In Stock'   => array_values(array_filter($parts, fn($p) => (int)$p['actual_stock'] === (int)$p['safety_stock'])),
    'Over Stock' => array_values(array_filter($parts, fn($p) => (int)$p['actual_stock'] > (int)$p['safety_stock'])),
];
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Part Availability — Inventory System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        /* ══ BADGE BASE ══ */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: .2rem .72rem;
            border-radius: 9999px;
            font-size: .68rem;
            font-weight: 800;
            border: 1px solid transparent;
            letter-spacing: .05em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        /*
         * WARNA BADGE — HARDCODED HEX.
         * SENGAJA tidak menggunakan Tailwind class (bg-red-100, dll)
         * karena Tailwind CDN tidak mengenali class yang di-generate
         * secara dinamis oleh PHP, sehingga warnanya tidak muncul.
         */
        .badge-zero {
            background: #fee2e2;
            color: #b91c1c;
            border-color: #fca5a5;
        }

        .badge-low {
            background: #ffedd5;
            color: #c2410c;
            border-color: #fdba74;
        }

        .badge-in {
            background: #d1fae5;
            color: #065f46;
            border-color: #6ee7b7;
        }

        .badge-over {
            background: #ede9fe;
            color: #6d28d9;
            border-color: #c4b5fd;
        }

        .badge-none {
            background: #f1f5f9;
            color: #64748b;
            border-color: #cbd5e1;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(8px)
            }

            to {
                opacity: 1;
                transform: translateY(0)
            }
        }

        .modal-enter {
            animation: fadeIn .18s ease;
        }

        tr.part-row:hover td {
            background: #f8fafc;
        }

        .anim-bar {
            animation: bar 1.2s ease-in-out infinite;
        }

        @keyframes bar {
            0% {
                transform: translateX(-100%)
            }

            100% {
                transform: translateX(400%)
            }
        }

        .stat-card {
            cursor: pointer;
            transition: transform .15s, box-shadow .15s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, .09);
        }

        .stat-card:active {
            transform: translateY(0);
        }
    </style>
</head>

<body class="bg-slate-50 min-h-screen text-slate-900">
    <div class="max-w-[1500px] mx-auto p-6 lg:p-10">

        <!-- HEADER -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-6 mb-8">
            <div>
                <a href="index.php" class="text-emerald-600 font-bold text-sm flex items-center gap-2 mb-2 hover:gap-3 transition-all">
                    <i class="fas fa-arrow-left"></i> Back to Hub
                </a>
                <h1 class="text-3xl font-extrabold text-slate-800">📦 Part Availability</h1>
                <p class="text-slate-500 mt-1 text-sm">Sparepart inventory & stock monitoring</p>
            </div>
            <div class="flex items-center gap-3 flex-wrap">
                <div class="relative">
                    <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                    <input type="text" id="searchInput" placeholder="Search code or name part..."
                        oninput="filterParts()"
                        class="pl-11 pr-4 py-3 bg-white border border-slate-200 rounded-2xl w-72 focus:ring-4 focus:ring-emerald-100 outline-none transition shadow-sm text-sm">
                </div>
                <button onclick="openModal('importModal')"
                    class="bg-emerald-600 hover:bg-emerald-700 text-white px-5 py-3 rounded-2xl font-bold shadow-lg shadow-emerald-100 transition-all flex items-center gap-2 text-sm">
                    <i class="fas fa-file-excel"></i> Import Excel
                </button>
                <button onclick="openModal('addModal')"
                    class="bg-emerald-600 hover:bg-emerald-700 text-white px-5 py-3 rounded-2xl font-bold shadow-lg shadow-emerald-100 transition-all flex items-center gap-2 text-sm">
                    <i class="fas fa-plus"></i> Tambah Part
                </button>
                <a href="logout_user.php" onclick="return confirm('Apakah Anda yakin ingin keluar?')"
                    class="bg-red-100 hover:bg-red-200 text-red-600 px-5 py-3 rounded-2xl font-bold transition-all flex items-center gap-2 text-sm">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>

        <!-- STAT CARDS -->
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-8">
            <div class="bg-white p-5 rounded-3xl border border-slate-200 shadow-sm">
                <p class="text-slate-400 text-[10px] font-bold uppercase tracking-widest mb-1">Total Parts</p>
                <p class="text-3xl font-black text-slate-800"><?= $totalParts ?></p>
            </div>
            <div class="stat-card bg-red-50 p-5 rounded-3xl border border-red-100 shadow-sm" onclick="openCategoryModal('Zero Stock')">
                <p class="text-red-400 text-[10px] font-bold uppercase tracking-widest mb-1 flex items-center">Zero Stock<i class="fas fa-chevron-right text-[8px] ml-auto opacity-50"></i></p>
                <p class="text-3xl font-black text-red-600"><?= $zeroStock ?></p>
            </div>
            <div class="stat-card bg-orange-50 p-5 rounded-3xl border border-orange-100 shadow-sm" onclick="openCategoryModal('Low Stock')">
                <p class="text-orange-400 text-[10px] font-bold uppercase tracking-widest mb-1 flex items-center">Low Stock<i class="fas fa-chevron-right text-[8px] ml-auto opacity-50"></i></p>
                <p class="text-3xl font-black text-orange-600"><?= $lowStock ?></p>
            </div>
            <div class="stat-card bg-emerald-50 p-5 rounded-3xl border border-emerald-100 shadow-sm" onclick="openCategoryModal('In Stock')">
                <p class="text-emerald-500 text-[10px] font-bold uppercase tracking-widest mb-1 flex items-center">In Stock<i class="fas fa-chevron-right text-[8px] ml-auto opacity-50"></i></p>
                <p class="text-3xl font-black text-emerald-600"><?= $inStock ?></p>
            </div>
            <div class="stat-card bg-violet-50 p-5 rounded-3xl border border-violet-100 shadow-sm" onclick="openCategoryModal('Over Stock')">
                <p class="text-violet-400 text-[10px] font-bold uppercase tracking-widest mb-1 flex items-center">Over Stock<i class="fas fa-chevron-right text-[8px] ml-auto opacity-50"></i></p>
                <p class="text-3xl font-black text-violet-600"><?= $overstock ?></p>
            </div>
        </div>

        <!-- TABEL -->
        <div class="bg-white rounded-[2rem] shadow-xl border border-slate-200 overflow-hidden">
            <div style="max-height:520px; overflow-y:auto; overflow-x:auto;">
                <table class="w-full text-left border-collapse" id="partsTable">
                    <thead class="bg-slate-800 text-white" style="background:linear-gradient(135deg,#0f766e,#0d9488);position:sticky;top:0;z-index:10;">
                        <tr>
                            <th class="px-6 py-4 text-[11px] font-semibold uppercase tracking-widest">No</th>
                            <th class="px-6 py-4 text-[11px] font-semibold uppercase tracking-widest">Item Code</th>
                            <th class="px-6 py-4 text-[11px] font-semibold uppercase tracking-widest">Item Description</th>
                            <th class="px-6 py-4 text-[11px] font-semibold uppercase tracking-widest text-center">Safety Stock</th>
                            <th class="px-6 py-4 text-[11px] font-semibold uppercase tracking-widest text-center">Actual Stock</th>
                            <th class="px-6 py-4 text-[11px] font-semibold uppercase tracking-widest text-center">Effective Stock</th>
                            <th class="px-6 py-4 text-[11px] font-semibold uppercase tracking-widest text-center">Status</th>
                            <th class="px-6 py-4 text-[11px] font-semibold uppercase tracking-widest text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody id="partsBody" class="divide-y divide-slate-100">
                        <?php if (empty($parts)): ?>
                            <tr>
                                <td colspan="8" class="px-6 py-20 text-center text-slate-400">
                                    <i class="fas fa-box-open text-5xl mb-4 block text-slate-200"></i>
                                    <p class="font-semibold">Belum ada data part.</p>
                                    <p class="text-sm mt-1">Tambah manual atau import dari Excel.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($parts as $i => $part):
                                $actual    = (int)$part['actual_stock'];
                                $safety    = (int)$part['safety_stock'];
                                $effective = (int)$part['effective_stock'];
                                $status    = getPartStatusStr($actual, $safety);
                                $badgeCls  = getPartStatusClass($status);
                            ?>
                                <tr class="part-row transition-colors"
                                    data-search="<?= htmlspecialchars(strtolower($part['item_code'] . ' ' . ($part['item_description'] ?? ''))) ?>">
                                    <td class="px-6 py-4 text-slate-400 text-sm font-medium"><?= $i + 1 ?></td>
                                    <td class="px-6 py-4 font-mono font-bold text-slate-700 text-sm tracking-wide"><?= htmlspecialchars($part['item_code']) ?></td>
                                    <td class="px-6 py-4 text-slate-700 text-sm font-medium max-w-xs"><?= htmlspecialchars($part['item_description'] ?? '-') ?></td>
                                    <td class="px-6 py-4 text-center">
                                        <span class="inline-block bg-slate-100 text-slate-600 font-bold px-3 py-1 rounded-lg text-sm"><?= $safety ?></span>
                                    </td>
                                    <td class="px-6 py-4 text-center font-black text-lg
                            <?= $actual === 0 ? 'text-red-500' : ($actual < $safety ? 'text-orange-500' : ($actual === $safety ? 'text-emerald-600' : 'text-violet-600')) ?>">
                                        <?= $actual ?>
                                    </td>
                                    <td class="px-6 py-4 text-center font-bold text-sm <?= $effective < 0 ? 'text-red-500' : 'text-slate-600' ?>">
                                        <?= ($effective >= 0 ? '+' : '') . $effective ?>
                                    </td>
                                    <td class="px-6 py-4 text-center">
                                        <span class="badge <?= $badgeCls ?>"><?= $status ?></span>
                                    </td>
                                    <td class="px-6 py-4 text-center">
                                        <div class="flex items-center justify-center gap-2">
                                            <button onclick="openEditModal(<?= $part['id'] ?>,'<?= htmlspecialchars($part['item_code'], ENT_QUOTES) ?>','<?= htmlspecialchars($part['item_description'] ?? '', ENT_QUOTES) ?>',<?= $actual ?>,<?= $safety ?>)"
                                                class="bg-emerald-400 hover:bg-emerald-500 text-white px-3 py-2 rounded-xl font-bold text-xs transition flex items-center gap-1.5">
                                                <i class="fas fa-pencil"></i> Edit
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if (!empty($parts)): ?>
                <div class="px-6 py-3 bg-slate-50 border-t border-slate-100 flex items-center justify-between text-xs text-slate-400">
                    <span id="countLabel">Menampilkan <?= count($parts) ?> part</span>
                    <?php date_default_timezone_set('Asia/Jakarta'); ?>
                    <span>Last updated: <?= date('d M Y H:i') ?></span>
                </div>
            <?php endif; ?>
        </div>

    </div><!-- /container -->


    <!-- MODAL: KATEGORI STAT CARD -->
    <div id="categoryModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 items-center justify-center p-6" style="display:none;">
        <div class="bg-white w-full max-w-2xl rounded-3xl shadow-2xl overflow-hidden modal-enter">
            <div id="catModalHeader" class="px-7 py-5 flex justify-between items-center">
                <div>
                    <p class="text-white/60 text-[10px] font-black uppercase tracking-widest mb-0.5">Filter Kategori</p>
                    <h3 class="text-base font-black text-white" id="catModalTitle">—</h3>
                </div>
                <button onclick="closeModal('categoryModal')" class="text-white/60 hover:text-white w-8 h-8 flex items-center justify-center rounded-full hover:bg-white/10 transition">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div style="max-height:360px; overflow-y:auto;">
                <table class="w-full text-left border-collapse">
                    <thead style="position:sticky;top:0;background:#f8fafc;z-index:5;">
                        <tr class="border-b border-slate-100">
                            <th class="px-6 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Item Code</th>
                            <th class="px-6 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Description</th>
                            <th class="px-6 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Safety</th>
                            <th class="px-6 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Actual</th>
                            <th class="px-6 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Effective</th>
                        </tr>
                    </thead>
                    <tbody id="catModalBody" class="divide-y divide-slate-50 text-sm"></tbody>
                </table>
            </div>
            <div class="px-7 py-4 bg-slate-50 border-t border-slate-100 flex justify-between items-center">
                <span id="catModalCount" class="text-xs text-slate-400 font-medium"></span>
                <button onclick="closeModal('categoryModal')" class="px-5 py-2 bg-slate-800 text-white rounded-xl font-bold text-sm hover:bg-slate-700 transition">Tutup</button>
            </div>
        </div>
    </div>


    <!-- MODAL: TAMBAH PART -->
    <div id="addModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 items-center justify-center p-4" style="display:none;">
        <div class="bg-white w-full max-w-lg rounded-3xl shadow-2xl overflow-hidden modal-enter">
            <div class="bg-gradient-to-r from-emerald-600 to-emerald-700 px-8 py-5 flex justify-between items-center">
                <div>
                    <h3 class="text-lg font-bold text-white"><i class="fas fa-plus-circle mr-2"></i>Tambah Part Baru</h3>
                    <p class="text-emerald-200 text-xs mt-0.5">Isi semua kolom yang diperlukan</p>
                </div>
                <button onclick="closeModal('addModal')" class="text-blue-200 hover:text-white transition w-8 h-8 flex items-center justify-center rounded-full hover:bg-white/10"><i class="fas fa-times"></i></button>
            </div>
            <div class="p-8">
                <div class="mb-5">
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Item Code <span class="text-red-500">*</span></label>
                    <input type="text" id="add_item_code" placeholder="Contoh: MA95090"
                        class="w-full border border-slate-200 rounded-xl px-4 py-3 font-mono font-bold focus:ring-4 focus:ring-blue-100 outline-none transition text-slate-700 text-sm">
                </div>
                <div class="mb-5">
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Item Description</label>
                    <input type="text" id="add_item_desc" placeholder="Nama / deskripsi part"
                        class="w-full border border-slate-200 rounded-xl px-4 py-3 focus:ring-4 focus:ring-blue-100 outline-none transition text-sm">
                </div>
                <div class="grid grid-cols-2 gap-4 mb-6">
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Safety Stock <span class="text-red-500">*</span></label>
                        <input type="number" id="add_safety" min="0" value="0" oninput="previewStatus()"
                            class="w-full border border-slate-200 rounded-xl px-4 py-3 focus:ring-4 focus:ring-blue-100 outline-none transition text-sm font-bold">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Actual Stock (Awal)</label>
                        <input type="number" id="add_actual" min="0" value="0" oninput="previewStatus()"
                            class="w-full border border-slate-200 rounded-xl px-4 py-3 focus:ring-4 focus:ring-blue-100 outline-none transition text-sm font-bold">
                    </div>
                </div>
                <div class="bg-slate-50 rounded-2xl px-5 py-4 mb-6 flex items-center gap-4">
                    <div class="flex-1">
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Effective Stock</p>
                        <p class="text-xl font-black text-slate-700" id="preview_effective">0</p>
                    </div>
                    <div>
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Status</p>
                        <span class="badge badge-zero" id="preview_status">Zero Stock</span>
                    </div>
                </div>
                <div id="addAlert" class="hidden rounded-xl p-3 mb-4 text-sm font-medium border"></div>
                <div class="flex justify-end gap-3">
                    <button onclick="closeModal('addModal')" class="px-6 py-3 font-bold text-slate-400 hover:bg-slate-100 rounded-xl transition text-sm">Batal</button>
                    <button onclick="submitAddPart()" class="bg-blue-600 hover:bg-blue-700 text-white px-8 py-3 rounded-xl font-black shadow-lg shadow-blue-100 transition text-sm">
                        <i class="fas fa-save mr-1"></i> Simpan
                    </button>
                </div>
            </div>
        </div>
    </div>


    <!-- MODAL: EDIT STOK -->
    <div id="editModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 items-center justify-center p-4" style="display:none;">
        <div class="bg-white w-full max-w-sm rounded-3xl shadow-2xl overflow-hidden modal-enter">
            <div class="bg-gradient-to-r from-emerald-500 to-green-500 px-6 py-4 flex justify-between items-center">
                <div>
                    <h3 class="text-base font-bold text-white"><i class="fas fa-pencil mr-2"></i>Edit Actual Stock</h3>
                    <p class="text-emerald-100 text-xs mt-0.5">Hanya actual stock yang dapat diubah</p>
                </div>
                <button onclick="closeModal('editModal')" class="text-amber-100 hover:text-white transition w-8 h-8 flex items-center justify-center rounded-full hover:bg-white/10"><i class="fas fa-times"></i></button>
            </div>
            <div class="p-6">
                <div class="bg-slate-50 rounded-2xl px-4 py-3 mb-4">
                    <p class="font-mono font-black text-slate-700 text-sm" id="edit_code_disp"></p>
                    <p class="text-slate-500 text-xs mt-0.5" id="edit_desc_disp"></p>
                    <div class="flex items-center gap-6 mt-2">
                        <div>
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Safety Stock</p>
                            <p class="font-black text-slate-700 text-lg" id="edit_safety_disp"></p>
                        </div>
                        <div>
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Actual Sekarang</p>
                            <p class="font-black text-blue-600 text-2xl" id="edit_actual_disp"></p>
                        </div>
                    </div>
                </div>
                <input type="hidden" id="edit_part_id">
                <input type="hidden" id="edit_safety_val">
                <div class="mb-3">
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Tipe Perubahan</label>
                    <div class="flex gap-3">
                        <button type="button" id="btnAdd" onclick="setChangeType('add')"
                            class="flex-1 py-2 rounded-xl font-bold text-sm border-2 border-green-500 bg-green-50 text-green-700 transition">
                            <i class="fas fa-plus mr-1"></i> Tambah
                        </button>
                        <button type="button" id="btnSub" onclick="setChangeType('sub')"
                            class="flex-1 py-2 rounded-xl font-bold text-sm border-2 border-slate-200 bg-white text-slate-400 transition">
                            <i class="fas fa-minus mr-1"></i> Kurang
                        </button>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Jumlah</label>
                    <input type="number" id="edit_change_amt" min="1" value="1" oninput="previewEdit()"
                        class="w-full border border-slate-200 rounded-xl px-4 py-3 text-2xl font-black text-center focus:ring-4 focus:ring-amber-100 outline-none transition">
                </div>
                <div class="bg-slate-50 rounded-2xl px-4 py-3 mb-4 grid grid-cols-3 gap-3 text-center">
                    <div>
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Hasil Actual</p>
                        <p class="font-black text-slate-700 text-lg" id="preview_new_actual">-</p>
                    </div>
                    <div>
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Effective</p>
                        <p class="font-black text-slate-700 text-lg" id="preview_new_effective">-</p>
                    </div>
                    <div>
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Status</p>
                        <span class="badge badge-none" id="preview_new_status">-</span>
                    </div>
                </div>
                <div id="editAlert" class="hidden rounded-xl p-3 mb-3 text-sm font-medium border"></div>
                <div class="flex justify-end gap-3">
                    <button onclick="closeModal('editModal')" class="px-5 py-2.5 font-bold text-slate-400 hover:bg-slate-100 rounded-xl transition text-sm">Batal</button>
                    <button onclick="submitEditStock()" class="bg-emerald-500 hover:bg-emerald-600 text-white px-7 py-2.5 rounded-xl font-black shadow-lg transition text-sm">
                        <i class="fas fa-check mr-1"></i> Update
                    </button>
                </div>
            </div>
        </div>
    </div>


    <!-- MODAL: IMPORT EXCEL -->
    <div id="importModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 items-center justify-center p-4" style="display:none;">
        <div class="bg-white w-full max-w-lg rounded-3xl shadow-2xl overflow-hidden modal-enter">
            <div class="bg-gradient-to-r from-emerald-600 to-teal-600 px-8 py-5 flex justify-between items-center">
                <div>
                    <h3 class="text-lg font-bold text-white"><i class="fas fa-file-excel mr-2"></i>Import Parts dari Excel</h3>
                    <p class="text-emerald-100 text-xs mt-0.5">Data akan di-upsert (update jika sudah ada)</p>
                </div>
                <button onclick="closeModal('importModal')" class="text-emerald-100 hover:text-white transition w-8 h-8 flex items-center justify-center rounded-full hover:bg-white/10"><i class="fas fa-times"></i></button>
            </div>
            <div class="p-8">
                <div class="bg-slate-50 rounded-2xl p-4 mb-5 border border-slate-100">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3">Format Kolom yang Dikenali</p>
                    <div class="grid grid-cols-2 gap-2">
                        <?php foreach ([['ITEM CODE', 'wajib'], ['ITEM DESCRIPTION', 'opsional'], ['SAFETY STOCK', 'angka'], ['QTY ACTUAL / ACTUAL STOCK', 'angka']] as [$col, $note]): ?>
                            <div class="flex items-center gap-2">
                                <span class="font-mono text-[10px] bg-emerald-100 text-emerald-700 px-2 py-1 rounded font-bold"><?= $col ?></span>
                                <span class="text-[10px] text-slate-400"><?= $note ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div id="dropZone"
                    class="border-2 border-dashed border-slate-200 rounded-2xl p-8 text-center cursor-pointer hover:border-emerald-400 transition-all mb-5"
                    onclick="document.getElementById('partsFile').click()"
                    ondragover="event.preventDefault();this.classList.add('border-emerald-400','bg-emerald-50')"
                    ondragleave="this.classList.remove('border-emerald-400','bg-emerald-50')"
                    ondrop="handleDrop(event)">
                    <i class="fas fa-cloud-upload-alt text-4xl text-slate-200 mb-3 block"></i>
                    <p class="font-bold text-slate-500 text-sm">Klik atau drag & drop file di sini</p>
                    <p class="text-slate-300 text-xs mt-1">.xlsx atau .xls — maks. 10 MB</p>
                    <div id="fileNameBadge" class="hidden mt-3">
                        <span class="inline-flex items-center gap-2 bg-emerald-100 text-emerald-700 font-bold px-4 py-1.5 rounded-full text-sm" id="fileNameLabel"></span>
                    </div>
                </div>
                <input type="file" id="partsFile" accept=".xlsx,.xls" class="hidden" onchange="handleFileSelect(event)">
                <div id="importLoading" class="hidden rounded-xl overflow-hidden bg-slate-100 h-2 mb-4">
                    <div class="anim-bar bg-emerald-500 h-2 w-1/3 rounded-full"></div>
                </div>
                <div id="importAlert" class="hidden rounded-xl p-3 mb-4 text-sm font-medium border"></div>
                <div class="flex justify-end gap-3">
                    <button onclick="closeModal('importModal')" class="px-6 py-3 font-bold text-slate-400 hover:bg-slate-100 rounded-xl transition text-sm">Batal</button>
                    <button id="btnImport" onclick="startImport()" disabled
                        class="bg-emerald-600 text-white px-8 py-3 rounded-xl font-black shadow-lg transition text-sm opacity-50 cursor-not-allowed flex items-center gap-2">
                        <i class="fas fa-upload"></i> Import
                    </button>
                </div>
            </div>
        </div>
    </div>


    <script>
        // ── Data per kategori dari PHP ──
        const PARTS_BY_CAT = <?= json_encode($partsByCategory, JSON_UNESCAPED_UNICODE) ?>;
        const CAT_HEADERS = {
            'Zero Stock': 'linear-gradient(135deg,#ef4444,#b91c1c)',
            'Low Stock': 'linear-gradient(135deg,#f97316,#c2410c)',
            'In Stock': 'linear-gradient(135deg,#10b981,#047857)',
            'Over Stock': 'linear-gradient(135deg,#8b5cf6,#6d28d9)',
        };
        const BADGE_CLS = {
            'Zero Stock': 'badge-zero',
            'Low Stock': 'badge-low',
            'In Stock': 'badge-in',
            'Over Stock': 'badge-over',
        };

        // ── Modal open/close ──
        function openModal(id) {
            document.getElementById(id).style.display = 'flex';
        }

        function closeModal(id) {
            document.getElementById(id).style.display = 'none';
        }
        document.querySelectorAll('[id$="Modal"]').forEach(m =>
            m.addEventListener('click', e => {
                if (e.target === m) closeModal(m.id);
            })
        );

        // ── Stat card → category modal ──
        function openCategoryModal(cat) {
            const parts = PARTS_BY_CAT[cat] || [];
            document.getElementById('catModalHeader').style.background = CAT_HEADERS[cat] || '#334155';
            document.getElementById('catModalTitle').textContent = cat + ' — ' + parts.length + ' item';
            document.getElementById('catModalCount').textContent = parts.length + ' part ditemukan';

            const tbody = document.getElementById('catModalBody');
            tbody.innerHTML = parts.length === 0 ?
                `<tr><td colspan="5" class="px-6 py-10 text-center text-slate-400 text-sm">
               <i class="fas fa-box-open text-3xl block mb-2 text-slate-200"></i>Tidak ada part di kategori ini
           </td></tr>` :
                parts.map(p => {
                    const actual = parseInt(p.actual_stock) || 0;
                    const safety = parseInt(p.safety_stock) || 0;
                    const eff = parseInt(p.effective_stock) || (actual - safety);
                    const effStr = (eff >= 0 ? '+' : '') + eff;
                    return `<tr class="hover:bg-slate-50 transition-colors">
                <td class="px-6 py-3 font-mono font-bold text-slate-700 text-sm">${esc(p.item_code)}</td>
                <td class="px-6 py-3 text-slate-600 text-sm max-w-[200px] truncate" title="${esc(p.item_description||'')}">${esc(p.item_description||'-')}</td>
                <td class="px-6 py-3 text-center font-bold text-slate-500 text-sm">${safety}</td>
                <td class="px-6 py-3 text-center font-black text-base">${actual}</td>
                <td class="px-6 py-3 text-center font-bold text-sm ${eff<0?'style="color:#ef4444"':'style="color:#64748b"'}">${effStr}</td>
            </tr>`;
                }).join('');

            openModal('categoryModal');
        }

        function esc(s) {
            return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        // ── Search ──
        function filterParts() {
            const q = document.getElementById('searchInput').value.toLowerCase().trim();
            let shown = 0;
            document.querySelectorAll('#partsBody tr[data-search]').forEach(row => {
                const vis = !q || row.dataset.search.includes(q);
                row.style.display = vis ? '' : 'none';
                if (vis) shown++;
            });
            const lbl = document.getElementById('countLabel');
            if (lbl) lbl.textContent = q ? `Menampilkan ${shown} dari <?= count($parts) ?> part` : `Menampilkan <?= count($parts) ?> part`;
        }

        // ── Status helper ──
        function computeStatus(actual, safety) {
            if (actual === 0) return ['Zero Stock', 'badge-zero'];
            if (actual < safety) return ['Low Stock', 'badge-low'];
            if (actual === safety) return ['In Stock', 'badge-in'];
            return ['Over Stock', 'badge-over'];
        }

        // ── Modal Tambah ──
        function previewStatus() {
            const actual = parseInt(document.getElementById('add_actual').value) || 0;
            const safety = parseInt(document.getElementById('add_safety').value) || 0;
            const eff = actual - safety;
            const [label, cls] = computeStatus(actual, safety);
            const effEl = document.getElementById('preview_effective');
            effEl.textContent = (eff >= 0 ? '+' : '') + eff;
            effEl.className = eff < 0 ? 'text-xl font-black text-red-500' : 'text-xl font-black text-slate-700';
            const b = document.getElementById('preview_status');
            b.textContent = label;
            b.className = `badge ${cls}`;
        }
        previewStatus();

        async function submitAddPart() {
            const code = document.getElementById('add_item_code').value.trim();
            const desc = document.getElementById('add_item_desc').value.trim();
            const safety = parseInt(document.getElementById('add_safety').value) || 0;
            const actual = parseInt(document.getElementById('add_actual').value) || 0;
            if (!code) {
                showAlert('addAlert', 'error', 'Item Code wajib diisi');
                return;
            }
            const fd = new FormData();
            fd.append('action', 'add_part');
            fd.append('item_code', code);
            fd.append('item_description', desc);
            fd.append('safety_stock', safety);
            fd.append('actual_stock', actual);
            const r = await (await fetch('', {
                method: 'POST',
                body: fd
            })).json();
            if (r.status === 'success') {
                showAlert('addAlert', 'success', '✅ ' + r.message);
                setTimeout(() => location.reload(), 1200);
            } else showAlert('addAlert', 'error', '❌ ' + r.message);
        }

        // ── Modal Edit ──
        let _changeType = 'add',
            _curActual = 0,
            _curSafety = 0;

        function setChangeType(type) {
            _changeType = type;
            const base = 'flex-1 py-2 rounded-xl font-bold text-sm border-2 transition ';
            document.getElementById('btnAdd').className = base + (type === 'add' ? 'border-green-500 bg-green-50 text-green-700' : 'border-slate-200 bg-white text-slate-400');
            document.getElementById('btnSub').className = base + (type === 'sub' ? 'border-red-500 bg-red-50 text-red-700' : 'border-slate-200 bg-white text-slate-400');
            previewEdit();
        }

        function previewEdit() {
            const amt = parseInt(document.getElementById('edit_change_amt').value) || 0;
            const delta = _changeType === 'add' ? amt : -amt;
            const nA = _curActual + delta,
                nE = nA - _curSafety;
            const [label, cls] = computeStatus(Math.max(0, nA), _curSafety);
            document.getElementById('preview_new_actual').textContent = Math.max(0, nA);
            document.getElementById('preview_new_actual').className = nA < 0 ? 'font-black text-red-500 text-lg' : 'font-black text-slate-700 text-lg';
            document.getElementById('preview_new_effective').textContent = (nE >= 0 ? '+' : '') + nE;
            const b = document.getElementById('preview_new_status');
            b.textContent = label;
            b.className = `badge ${cls}`;
        }

        function openEditModal(id, code, desc, actual, safety) {
            _curActual = actual;
            _curSafety = safety;
            document.getElementById('edit_part_id').value = id;
            document.getElementById('edit_safety_val').value = safety;
            document.getElementById('edit_code_disp').textContent = code;
            document.getElementById('edit_desc_disp').textContent = desc || '-';
            document.getElementById('edit_safety_disp').textContent = safety;
            document.getElementById('edit_actual_disp').textContent = actual;
            document.getElementById('edit_change_amt').value = 1;
            document.getElementById('editAlert').classList.add('hidden');
            setChangeType('add');
            openModal('editModal');
        }
        async function submitEditStock() {
            const amt = parseInt(document.getElementById('edit_change_amt').value) || 0;
            if (amt <= 0) {
                showAlert('editAlert', 'error', 'Jumlah harus lebih dari 0');
                return;
            }
            const delta = _changeType === 'add' ? amt : -amt;
            if (_curActual + delta < 0) {
                showAlert('editAlert', 'error', 'Stok tidak boleh negatif');
                return;
            }
            const fd = new FormData();
            fd.append('action', 'update_stock');
            fd.append('part_id', document.getElementById('edit_part_id').value);
            fd.append('change_amount', delta);
            fd.append('note', '');
            const r = await (await fetch('', {
                method: 'POST',
                body: fd
            })).json();
            if (r.status === 'success') {
                showAlert('editAlert', 'success', '✅ Stok diperbarui → Actual: ' + r.new_stock);
                setTimeout(() => location.reload(), 1200);
            } else showAlert('editAlert', 'error', '❌ ' + r.message);
        }

        // ── Import Excel ──
        let _importFile = null;

        function handleFileSelect(e) {
            if (e.target.files[0]) setFile(e.target.files[0]);
        }

        function handleDrop(e) {
            e.preventDefault();
            document.getElementById('dropZone').classList.remove('border-emerald-400', 'bg-emerald-50');
            if (e.dataTransfer.files[0]) setFile(e.dataTransfer.files[0]);
        }

        function setFile(file) {
            _importFile = file;
            document.getElementById('fileNameLabel').innerHTML = `<i class="fas fa-file-excel mr-1"></i>${file.name}`;
            document.getElementById('fileNameBadge').classList.remove('hidden');
            document.getElementById('importAlert').classList.add('hidden');
            const btn = document.getElementById('btnImport');
            btn.disabled = false;
            btn.classList.remove('opacity-50', 'cursor-not-allowed');
        }
        async function startImport() {
            if (!_importFile) return;
            document.getElementById('importLoading').classList.remove('hidden');
            document.getElementById('btnImport').disabled = true;
            const fd = new FormData();
            fd.append('action', 'import_parts');
            fd.append('parts_file', _importFile);
            try {
                const r = await (await fetch('', {
                    method: 'POST',
                    body: fd
                })).json();
                document.getElementById('importLoading').classList.add('hidden');
                if (r.status === 'success') {
                    let msg = '✅ ' + r.message;
                    if (r.sheet) msg += ' (sheet: ' + r.sheet + ')';
                    showAlert('importAlert', 'success', msg);
                    setTimeout(() => location.reload(), 2000);
                } else {
                    let msg = '❌ ' + r.message;
                    if (r.errors?.length) msg += '\n' + r.errors.join('\n');
                    showAlert('importAlert', 'error', msg);
                    document.getElementById('btnImport').disabled = false;
                }
            } catch (e) {
                document.getElementById('importLoading').classList.add('hidden');
                showAlert('importAlert', 'error', '❌ Gagal menghubungi server: ' + e.message);
                document.getElementById('btnImport').disabled = false;
            }
        }

        // ── Alert ──
        function showAlert(elId, type, msg) {
            const el = document.getElementById(elId);
            el.className = 'rounded-xl p-3 mb-4 text-sm font-medium border whitespace-pre-line ' + (type === 'success' ? 'bg-green-50 text-green-800 border-green-200' : 'bg-red-50 text-red-800 border-red-200');
            el.textContent = msg;
            el.classList.remove('hidden');
        }
    </script>
</body>

</html>