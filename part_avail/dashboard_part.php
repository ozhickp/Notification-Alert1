<?php
include 'config.php';
session_start();

requireRole([ROLE_ADMIN_MAINTENANCE, ROLE_SUPERADMIN]);

// Ambil nama user yang sedang login
$stmtUser = $pdo->prepare("SELECT username FROM users WHERE id = ?");
$stmtUser->execute([$_SESSION['user_id']]);
$currentUser = $stmtUser->fetch(PDO::FETCH_ASSOC);
$displayName = $currentUser['username'] ?? 'User';

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
            // Catat history: stok awal 0 → actual
            if ($actual > 0) logPartHistory($pdo, $pid, $itemCode, $itemDesc, 0, $actual, $actual);
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
            // Catat history
            $partInfo = $pdo->prepare("SELECT item_code, item_description FROM expenses_part WHERE id=?");
            $partInfo->execute([$partId]);
            $pi = $partInfo->fetch(PDO::FETCH_ASSOC);
            logPartHistory($pdo, $partId, $pi['item_code'] ?? '', $pi['item_description'] ?? '', (int)$part['actual_stock'], $change, $newActual);
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
                        // Ambil stok lama sebelum update
                        $oldStockStmt = $pdo->prepare("SELECT actual_stock, item_description FROM expenses_part WHERE item_code=?");
                        $oldStockStmt->execute([$code]);
                        $oldRow = $oldStockStmt->fetch(PDO::FETCH_ASSOC);
                        $oldActual = (int)($oldRow['actual_stock'] ?? 0);
                        $stmtUpdatePart->execute([$desc, $safety, $actual, $effective, $status, $code]);
                        $diff = $actual - $oldActual;
                        if ($diff !== 0) logPartHistory($pdo, (int)$existingId, $code, $desc ?? ($oldRow['item_description'] ?? ''), $oldActual, $diff, $actual);
                        $updated++;
                    } else {
                        // Belum ada → INSERT baru
                        $stmtInsertPart->execute([$code, $desc, $safety, $actual, $effective, $status]);
                        $newId = (int)$pdo->lastInsertId();
                        if ($actual > 0) logPartHistory($pdo, $newId, $code, $desc ?? '', 0, $actual, $actual);
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

/**
 * Catat satu baris ke tabel history_part.
 * $pdo, $_SESSION sudah tersedia di scope global.
 */
function logPartHistory(PDO $pdo, int $itemId, string $itemCode, string $itemDescription, int $lastStock, int $amountProcess, int $newStock): void
{
    $reportedBy = $_SESSION['user_id'] ?? null;
    $pdo->prepare(
        "INSERT INTO history_part (item_id, item_code, item_description, last_stock, amount_process, new_stock, reported_by, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
    )->execute([$itemId, $itemCode, $itemDescription, $lastStock, $amountProcess, $newStock, $reportedBy]);
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

// Query history_part — 200 record terbaru
$historyRows = $pdo->query(
    "SELECT h.*, u.username AS reporter_name
     FROM history_part h
     LEFT JOIN users u ON u.id = h.reported_by
     ORDER BY h.created_at DESC
     LIMIT 200"
)->fetchAll(PDO::FETCH_ASSOC);

// Pisahkan transaksi menjadi IN (stok masuk / bertambah) dan OUT (stok keluar / berkurang)
$inRows  = array_values(array_filter($historyRows, fn($h) => (int)$h['amount_process'] >= 0));
$outRows = array_values(array_filter($historyRows, fn($h) => (int)$h['amount_process'] < 0));

/**
 * Render baris-baris <tr> untuk tabel transaksi (dipakai bersama oleh sub-tab IN, OUT, dan History).
 */
function renderPartHistoryRows(array $rows, string $emptyMessage): void
{
    if (empty($rows)) {
        echo '<tr><td colspan="8" class="px-6 py-20 text-center text-slate-400">'
            . '<i class="fas fa-history text-5xl mb-4 block text-slate-200"></i>'
            . '<p class="font-semibold">' . htmlspecialchars($emptyMessage) . '</p>'
            . '</td></tr>';
        return;
    }
    $i = 0;
    foreach ($rows as $h):
        $i++;
        $amt       = (int)$h['amount_process'];
        $isPlus    = $amt >= 0;
        $amtLabel  = ($isPlus ? '+' : '') . $amt;
        $hbadge    = $isPlus ? 'hbadge-plus' : 'hbadge-minus';
        $reporter  = htmlspecialchars($h['reporter_name'] ?? ('User #' . $h['reported_by']));
        $searchVal = strtolower($h['item_code'] . ' ' . ($h['item_description'] ?? ''));
?>
        <tr class="history-row transition-colors hover:bg-slate-50"
            data-search="<?= htmlspecialchars($searchVal) ?>"
            data-type="<?= $isPlus ? 'plus' : 'minus' ?>">
            <td class="px-5 py-3.5 text-slate-400 text-sm font-medium"><?= $i ?></td>
            <td class="px-5 py-3.5 text-slate-500 text-xs font-medium whitespace-nowrap">
                <?= date('d M Y', strtotime($h['created_at'])) ?>
                <span class="block text-slate-400"><?= date('H:i:s', strtotime($h['created_at'])) ?></span>
            </td>
            <td class="px-5 py-3.5 font-mono font-bold text-slate-700 text-sm tracking-wide"><?= htmlspecialchars($h['item_code']) ?></td>
            <td class="px-5 py-3.5 text-slate-600 text-sm max-w-[220px] truncate" title="<?= htmlspecialchars($h['item_description'] ?? '') ?>">
                <?= htmlspecialchars($h['item_description'] ?? '-') ?>
            </td>
            <td class="px-5 py-3.5 text-center font-bold text-slate-600 text-sm"><?= (int)$h['last_stock'] ?></td>
            <td class="px-5 py-3.5 text-center">
                <span class="badge <?= $hbadge ?>"><?= $amtLabel ?></span>
            </td>
            <td class="px-5 py-3.5 text-center font-black text-slate-800 text-sm"><?= (int)$h['new_stock'] ?></td>
            <td class="px-5 py-3.5 text-slate-600 text-sm">
                <div class="flex items-center gap-2">
                    <span class="w-7 h-7 rounded-full bg-slate-200 flex items-center justify-center text-slate-500 text-[10px] font-black">
                        <?= strtoupper(substr($h['reporter_name'] ?? 'U', 0, 1)) ?>
                    </span>
                    <?= $reporter ?>
                </div>
            </td>
        </tr>
<?php
    endforeach;
}

$activeTab = $_GET['tab'] ?? 'inventory';
if ($activeTab === 'history') $activeTab = 'transactions'; // kompatibilitas link/bookmark lama
$activeSubtab = $_GET['subtab'] ?? 'in';
if (!in_array($activeSubtab, ['in', 'out', 'history'], true)) $activeSubtab = 'in';

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
    <script>
/* JsBarcode v3.12.3 — di-embed langsung (self-hosted), tidak bergantung ke CDN eksternal */
/*! JsBarcode v3.12.3 | (c) Johan Lindell | MIT license */
!function(t){var e={};function n(r){if(e[r])return e[r].exports;var o=e[r]={i:r,l:!1,exports:{}};return t[r].call(o.exports,o,o.exports,n),o.l=!0,o.exports}n.m=t,n.c=e,n.d=function(t,e,r){n.o(t,e)||Object.defineProperty(t,e,{enumerable:!0,get:r})},n.r=function(t){"undefined"!=typeof Symbol&&Symbol.toStringTag&&Object.defineProperty(t,Symbol.toStringTag,{value:"Module"}),Object.defineProperty(t,"__esModule",{value:!0})},n.t=function(t,e){if(1&e&&(t=n(t)),8&e)return t;if(4&e&&"object"==typeof t&&t&&t.__esModule)return t;var r=Object.create(null);if(n.r(r),Object.defineProperty(r,"default",{enumerable:!0,value:t}),2&e&&"string"!=typeof t)for(var o in t)n.d(r,o,function(e){return t[e]}.bind(null,o));return r},n.n=function(t){var e=t&&t.__esModule?function(){return t.default}:function(){return t};return n.d(e,"a",e),e},n.o=function(t,e){return Object.prototype.hasOwnProperty.call(t,e)},n.p="",n(n.s=16)}([function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0});e.default=function t(e,n){!function(t,e){if(!(t instanceof e))throw new TypeError("Cannot call a class as a function")}(this,t),this.data=e,this.text=n.text||e,this.options=n}},function(t,e,n){"use strict";var r;function o(t,e,n){return e in t?Object.defineProperty(t,e,{value:n,enumerable:!0,configurable:!0,writable:!0}):t[e]=n,t}Object.defineProperty(e,"__esModule",{value:!0});var i=e.SET_A=0,a=e.SET_B=1,u=e.SET_C=2,f=(e.SHIFT=98,e.START_A=103),c=e.START_B=104,s=e.START_C=105;e.MODULO=103,e.STOP=106,e.FNC1=207,e.SET_BY_CODE=(o(r={},f,i),o(r,c,a),o(r,s,u),r),e.SWAP={101:i,100:a,99:u},e.A_START_CHAR=String.fromCharCode(208),e.B_START_CHAR=String.fromCharCode(209),e.C_START_CHAR=String.fromCharCode(210),e.A_CHARS="[\0-_È-Ï]",e.B_CHARS="[ -È-Ï]",e.C_CHARS="(Ï*[0-9]{2}Ï*)",e.BARS=[11011001100,11001101100,11001100110,10010011e3,10010001100,10001001100,10011001e3,10011000100,10001100100,11001001e3,11001000100,11000100100,10110011100,10011011100,10011001110,10111001100,10011101100,10011100110,11001110010,11001011100,11001001110,11011100100,11001110100,11101101110,11101001100,11100101100,11100100110,11101100100,11100110100,11100110010,11011011e3,11011000110,11000110110,10100011e3,10001011e3,10001000110,10110001e3,10001101e3,10001100010,11010001e3,11000101e3,11000100010,10110111e3,10110001110,10001101110,10111011e3,10111000110,10001110110,11101110110,11010001110,11000101110,11011101e3,11011100010,11011101110,11101011e3,11101000110,11100010110,11101101e3,11101100010,11100011010,11101111010,11001000010,11110001010,1010011e4,10100001100,1001011e4,10010000110,10000101100,10000100110,1011001e4,10110000100,1001101e4,10011000010,10000110100,10000110010,11000010010,1100101e4,11110111010,11000010100,10001111010,10100111100,10010111100,10010011110,10111100100,10011110100,10011110010,11110100100,11110010100,11110010010,11011011110,11011110110,11110110110,10101111e3,10100011110,10001011110,10111101e3,10111100010,11110101e3,11110100010,10111011110,10111101110,11101011110,11110101110,11010000100,1101001e4,11010011100,1100011101011]},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0});e.SIDE_BIN="101",e.MIDDLE_BIN="01010",e.BINARIES={L:["0001101","0011001","0010011","0111101","0100011","0110001","0101111","0111011","0110111","0001011"],G:["0100111","0110011","0011011","0100001","0011101","0111001","0000101","0010001","0001001","0010111"],R:["1110010","1100110","1101100","1000010","1011100","1001110","1010000","1000100","1001000","1110100"],O:["0001101","0011001","0010011","0111101","0100011","0110001","0101111","0111011","0110111","0001011"],E:["0100111","0110011","0011011","0100001","0011101","0111001","0000101","0010001","0001001","0010111"]},e.EAN2_STRUCTURE=["LL","LG","GL","GG"],e.EAN5_STRUCTURE=["GGLLL","GLGLL","GLLGL","GLLLG","LGGLL","LLGGL","LLLGG","LGLGL","LGLLG","LLGLG"],e.EAN13_STRUCTURE=["LLLLLL","LLGLGG","LLGGLG","LLGGGL","LGLLGG","LGGLLG","LGGGLL","LGLGLG","LGLGGL","LGGLGL"]},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0});var r=n(2);e.default=function(t,e,n){var o=t.split("").map((function(t,n){return r.BINARIES[e[n]]})).map((function(e,n){return e?e[t[n]]:""}));if(n){var i=t.length-1;o=o.map((function(t,e){return e<i?t+n:t}))}return o.join("")}},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0});var r,o=function(){function t(t,e){for(var n=0;n<e.length;n++){var r=e[n];r.enumerable=r.enumerable||!1,r.configurable=!0,"value"in r&&(r.writable=!0),Object.defineProperty(t,r.key,r)}}return function(e,n,r){return n&&t(e.prototype,n),r&&t(e,r),e}}(),i=n(0);var a=function(t){function e(t,n){return function(t,e){if(!(t instanceof e))throw new TypeError("Cannot call a class as a function")}(this,e),function(t,e){if(!t)throw new ReferenceError("this hasn't been initialised - super() hasn't been called");return!e||"object"!=typeof e&&"function"!=typeof e?t:e}(this,(e.__proto__||Object.getPrototypeOf(e)).call(this,t,n))}return function(t,e){if("function"!=typeof e&&null!==e)throw new TypeError("Super expression must either be null or a function, not "+typeof e);t.prototype=Object.create(e&&e.prototype,{constructor:{value:t,enumerable:!1,writable:!0,configurable:!0}}),e&&(Object.setPrototypeOf?Object.setPrototypeOf(t,e):t.__proto__=e)}(e,t),o(e,[{key:"encode",value:function(){for(var t="110",e=0;e<this.data.length;e++){var n=parseInt(this.data[e]).toString(2);n=u(n,4-n.length);for(var r=0;r<n.length;r++)t+="0"==n[r]?"100":"110"}return{data:t+="1001",text:this.text}}},{key:"valid",value:function(){return-1!==this.data.search(/^[0-9]+$/)}}]),e}(((r=i)&&r.__esModule?r:{default:r}).default);function u(t,e){for(var n=0;n<e;n++)t="0"+t;return t}e.default=a},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0});var r,o=function(){function t(t,e){for(var n=0;n<e.length;n++){var r=e[n];r.enumerable=r.enumerable||!1,r.configurable=!0,"value"in r&&(r.writable=!0),Object.defineProperty(t,r.key,r)}}return function(e,n,r){return n&&t(e.prototype,n),r&&t(e,r),e}}(),i=n(0),a=(r=i)&&r.__esModule?r:{default:r},u=n(1);var f=function(t){function e(t,n){!function(t,e){if(!(t instanceof e))throw new TypeError("Cannot call a class as a function")}(this,e);var r=function(t,e){if(!t)throw new ReferenceError("this hasn't been initialised - super() hasn't been called");return!e||"object"!=typeof e&&"function"!=typeof e?t:e}(this,(e.__proto__||Object.getPrototypeOf(e)).call(this,t.substring(1),n));return r.bytes=t.split("").map((function(t){return t.charCodeAt(0)})),r}return function(t,e){if("function"!=typeof e&&null!==e)throw new TypeError("Super expression must either be null or a function, not "+typeof e);t.prototype=Object.create(e&&e.prototype,{constructor:{value:t,enumerable:!1,writable:!0,configurable:!0}}),e&&(Object.setPrototypeOf?Object.setPrototypeOf(t,e):t.__proto__=e)}(e,t),o(e,[{key:"valid",value:function(){return/^[\x00-\x7F\xC8-\xD3]+$/.test(this.data)}},{key:"encode",value:function(){var t=this.bytes,n=t.shift()-105,r=u.SET_BY_CODE[n];if(void 0===r)throw new RangeError("The encoding does not start with a start character.");!0===this.shouldEncodeAsEan128()&&t.unshift(u.FNC1);var o=e.next(t,1,r);return{text:this.text===this.data?this.text.replace(/[^\x20-\x7E]/g,""):this.text,data:e.getBar(n)+o.result+e.getBar((o.checksum+n)%u.MODULO)+e.getBar(u.STOP)}}},{key:"shouldEncodeAsEan128",value:function(){var t=this.options.ean128||!1;return"string"==typeof t&&(t="true"===t.toLowerCase()),t}}],[{key:"getBar",value:function(t){return u.BARS[t]?u.BARS[t].toString():""}},{key:"correctIndex",value:function(t,e){if(e===u.SET_A){var n=t.shift();return n<32?n+64:n-32}return e===u.SET_B?t.shift()-32:10*(t.shift()-48)+t.shift()-48}},{key:"next",value:function(t,n,r){if(!t.length)return{result:"",checksum:0};var o=void 0,i=void 0;if(t[0]>=200){i=t.shift()-105;var a=u.SWAP[i];void 0!==a?o=e.next(t,n+1,a):(r!==u.SET_A&&r!==u.SET_B||i!==u.SHIFT||(t[0]=r===u.SET_A?t[0]>95?t[0]-96:t[0]:t[0]<32?t[0]+96:t[0]),o=e.next(t,n+1,r))}else i=e.correctIndex(t,r),o=e.next(t,n+1,r);var f=i*n;return{result:e.getBar(i)+o.result,checksum:f+o.checksum}}}]),e}(a.default);e.default=f},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0}),e.mod10=function(t){for(var e=0,n=0;n<t.length;n++){var r=parseInt(t[n]);(n+t.length)%2==0?e+=r:e+=2*r%10+Math.floor(2*r/10)}return(10-e%10)%10},e.mod11=function(t){for(var e=0,n=[2,3,4,5,6,7],r=0;r<t.length;r++){var o=parseInt(t[t.length-1-r]);e+=n[r%n.length]*o}return(11-e%11)%11}},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0});var r=Object.assign||function(t){for(var e=1;e<arguments.length;e++){var n=arguments[e];for(var r in n)Object.prototype.hasOwnProperty.call(n,r)&&(t[r]=n[r])}return t};e.default=function(t,e){return r({},t,e)}},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0});var r=function(){function t(t,e){for(var n=0;n<e.length;n++){var r=e[n];r.enumerable=r.enumerable||!1,r.configurable=!0,"value"in r&&(r.writable=!0),Object.defineProperty(t,r.key,r)}}return function(e,n,r){return n&&t(e.prototype,n),r&&t(e,r),e}}(),o=n(2),i=a(n(3));function a(t){return t&&t.__esModule?t:{default:t}}var u=function(t){function e(t,n){!function(t,e){if(!(t instanceof e))throw new TypeError("Cannot call a class as a function")}(this,e);var r=function(t,e){if(!t)throw new ReferenceError("this hasn't been initialised - super() hasn't been called");return!e||"object"!=typeof e&&"function"!=typeof e?t:e}(this,(e.__proto__||Object.getPrototypeOf(e)).call(this,t,n));return r.fontSize=!n.flat&&n.fontSize>10*n.width?10*n.width:n.fontSize,r.guardHeight=n.height+r.fontSize/2+n.textMargin,r}return function(t,e){if("function"!=typeof e&&null!==e)throw new TypeError("Super expression must either be null or a function, not "+typeof e);t.prototype=Object.create(e&&e.prototype,{constructor:{value:t,enumerable:!1,writable:!0,configurable:!0}}),e&&(Object.setPrototypeOf?Object.setPrototypeOf(t,e):t.__proto__=e)}(e,t),r(e,[{key:"encode",value:function(){return this.options.flat?this.encodeFlat():this.encodeGuarded()}},{key:"leftText",value:function(t,e){return this.text.substr(t,e)}},{key:"leftEncode",value:function(t,e){return(0,i.default)(t,e)}},{key:"rightText",value:function(t,e){return this.text.substr(t,e)}},{key:"rightEncode",value:function(t,e){return(0,i.default)(t,e)}},{key:"encodeGuarded",value:function(){var t={fontSize:this.fontSize},e={height:this.guardHeight};return[{data:o.SIDE_BIN,options:e},{data:this.leftEncode(),text:this.leftText(),options:t},{data:o.MIDDLE_BIN,options:e},{data:this.rightEncode(),text:this.rightText(),options:t},{data:o.SIDE_BIN,options:e}]}},{key:"encodeFlat",value:function(){return{data:[o.SIDE_BIN,this.leftEncode(),o.MIDDLE_BIN,this.rightEncode(),o.SIDE_BIN].join(""),text:this.text}}}]),e}(a(n(0)).default);e.default=u},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0});var r=function(){function t(t,e){for(var n=0;n<e.length;n++){var r=e[n];r.enumerable=r.enumerable||!1,r.configurable=!0,"value"in r&&(r.writable=!0),Object.defineProperty(t,r.key,r)}}return function(e,n,r){return n&&t(e.prototype,n),r&&t(e,r),e}}();e.checksum=u;var o=i(n(3));function i(t){return t&&t.__esModule?t:{default:t}}var a=function(t){function e(t,n){!function(t,e){if(!(t instanceof e))throw new TypeError("Cannot call a class as a function")}(this,e),-1!==t.search(/^[0-9]{11}$/)&&(t+=u(t));var r=function(t,e){if(!t)throw new ReferenceError("this hasn't been initialised - super() hasn't been called");return!e||"object"!=typeof e&&"function"!=typeof e?t:e}(this,(e.__proto__||Object.getPrototypeOf(e)).call(this,t,n));return r.displayValue=n.displayValue,n.fontSize>10*n.width?r.fontSize=10*n.width:r.fontSize=n.fontSize,r.guardHeight=n.height+r.fontSize/2+n.textMargin,r}return function(t,e){if("function"!=typeof e&&null!==e)throw new TypeError("Super expression must either be null or a function, not "+typeof e);t.prototype=Object.create(e&&e.prototype,{constructor:{value:t,enumerable:!1,writable:!0,configurable:!0}}),e&&(Object.setPrototypeOf?Object.setPrototypeOf(t,e):t.__proto__=e)}(e,t),r(e,[{key:"valid",value:function(){return-1!==this.data.search(/^[0-9]{12}$/)&&this.data[11]==u(this.data)}},{key:"encode",value:function(){return this.options.flat?this.flatEncoding():this.guardedEncoding()}},{key:"flatEncoding",value:function(){var t="";return t+="101",t+=(0,o.default)(this.data.substr(0,6),"LLLLLL"),t+="01010",t+=(0,o.default)(this.data.substr(6,6),"RRRRRR"),{data:t+="101",text:this.text}}},{key:"guardedEncoding",value:function(){var t=[];return this.displayValue&&t.push({data:"00000000",text:this.text.substr(0,1),options:{textAlign:"left",fontSize:this.fontSize}}),t.push({data:"101"+(0,o.default)(this.data[0],"L"),options:{height:this.guardHeight}}),t.push({data:(0,o.default)(this.data.substr(1,5),"LLLLL"),text:this.text.substr(1,5),options:{fontSize:this.fontSize}}),t.push({data:"01010",options:{height:this.guardHeight}}),t.push({data:(0,o.default)(this.data.substr(6,5),"RRRRR"),text:this.text.substr(6,5),options:{fontSize:this.fontSize}}),t.push({data:(0,o.default)(this.data[11],"R")+"101",options:{height:this.guardHeight}}),this.displayValue&&t.push({data:"00000000",text:this.text.substr(11,1),options:{textAlign:"right",fontSize:this.fontSize}}),t}}]),e}(i(n(0)).default);function u(t){var e,n=0;for(e=1;e<11;e+=2)n+=parseInt(t[e]);for(e=0;e<11;e+=2)n+=3*parseInt(t[e]);return(10-n%10)%10}e.default=a},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0});var r,o=function(){function t(t,e){for(var n=0;n<e.length;n++){var r=e[n];r.enumerable=r.enumerable||!1,r.configurable=!0,"value"in r&&(r.writable=!0),Object.defineProperty(t,r.key,r)}}return function(e,n,r){return n&&t(e.prototype,n),r&&t(e,r),e}}(),i=n(32),a=n(0);function u(t,e){if(!(t instanceof e))throw new TypeError("Cannot call a class as a function")}function f(t,e){if(!t)throw new ReferenceError("this hasn't been initialised - super() hasn't been called");return!e||"object"!=typeof e&&"function"!=typeof e?t:e}var c=function(t){function e(){return u(this,e),f(this,(e.__proto__||Object.getPrototypeOf(e)).apply(this,arguments))}return function(t,e){if("function"!=typeof e&&null!==e)throw new TypeError("Super expression must either be null or a function, not "+typeof e);t.prototype=Object.create(e&&e.prototype,{constructor:{value:t,enumerable:!1,writable:!0,configurable:!0}}),e&&(Object.setPrototypeOf?Object.setPrototypeOf(t,e):t.__proto__=e)}(e,t),o(e,[{key:"valid",value:function(){return-1!==this.data.search(/^([0-9]{2})+$/)}},{key:"encode",value:function(){var t=this,e=this.data.match(/.{2}/g).map((function(e){return t.encodePair(e)})).join("");return{data:i.START_BIN+e+i.END_BIN,text:this.text}}},{key:"encodePair",value:function(t){var e=i.BINARIES[t[1]];return i.BINARIES[t[0]].split("").map((function(t,n){return("1"===t?"111":"1")+("1"===e[n]?"000":"0")})).join("")}}]),e}(((r=a)&&r.__esModule?r:{default:r}).default);e.default=c},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0});var r,o=function(){function t(t,e){for(var n=0;n<e.length;n++){var r=e[n];r.enumerable=r.enumerable||!1,r.configurable=!0,"value"in r&&(r.writable=!0),Object.defineProperty(t,r.key,r)}}return function(e,n,r){return n&&t(e.prototype,n),r&&t(e,r),e}}(),i=n(42),a=n(0);var u=function(t){function e(t,n){return function(t,e){if(!(t instanceof e))throw new TypeError("Cannot call a class as a function")}(this,e),function(t,e){if(!t)throw new ReferenceError("this hasn't been initialised - super() hasn't been called");return!e||"object"!=typeof e&&"function"!=typeof e?t:e}(this,(e.__proto__||Object.getPrototypeOf(e)).call(this,t,n))}return function(t,e){if("function"!=typeof e&&null!==e)throw new TypeError("Super expression must either be null or a function, not "+typeof e);t.prototype=Object.create(e&&e.prototype,{constructor:{value:t,enumerable:!1,writable:!0,configurable:!0}}),e&&(Object.setPrototypeOf?Object.setPrototypeOf(t,e):t.__proto__=e)}(e,t),o(e,[{key:"valid",value:function(){return/^[0-9A-Z\-. $/+%]+$/.test(this.data)}},{key:"encode",value:function(){var t=this.data.split("").flatMap((function(t){return i.MULTI_SYMBOLS[t]||t})),n=t.map((function(t){return e.getEncoding(t)})).join(""),r=e.checksum(t,20),o=e.checksum(t.concat(r),15);return{text:this.text,data:e.getEncoding("ÿ")+n+e.getEncoding(r)+e.getEncoding(o)+e.getEncoding("ÿ")+"1"}}}],[{key:"getEncoding",value:function(t){return i.BINARIES[e.symbolValue(t)]}},{key:"getSymbol",value:function(t){return i.SYMBOLS[t]}},{key:"symbolValue",value:function(t){return i.SYMBOLS.indexOf(t)}},{key:"checksum",value:function(t,n){var r=t.slice().reverse().reduce((function(t,r,o){var i=o%n+1;return t+e.symbolValue(r)*i}),0);return e.getSymbol(r%47)}}]),e}(((r=a)&&r.__esModule?r:{default:r}).default);e.default=u},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0}),e.default=function(t){var e=["width","height","textMargin","fontSize","margin","marginTop","marginBottom","marginLeft","marginRight"];for(var n in e)e.hasOwnProperty(n)&&(n=e[n],"string"==typeof t[n]&&(t[n]=parseInt(t[n],10)));"string"==typeof t.displayValue&&(t.displayValue="false"!=t.displayValue);return t}},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0});var r={width:2,height:100,format:"auto",displayValue:!0,fontOptions:"",font:"monospace",text:void 0,textAlign:"center",textPosition:"bottom",textMargin:2,fontSize:20,background:"#ffffff",lineColor:"#000000",margin:10,marginTop:void 0,marginBottom:void 0,marginLeft:void 0,marginRight:void 0,valid:function(){}};e.default=r},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0}),e.getTotalWidthOfEncodings=e.calculateEncodingAttributes=e.getBarcodePadding=e.getEncodingHeight=e.getMaximumHeightOfEncodings=void 0;var r,o=n(7),i=(r=o)&&r.__esModule?r:{default:r};function a(t,e){return e.height+(e.displayValue&&t.text.length>0?e.fontSize+e.textMargin:0)+e.marginTop+e.marginBottom}function u(t,e,n){if(n.displayValue&&e<t){if("center"==n.textAlign)return Math.floor((t-e)/2);if("left"==n.textAlign)return 0;if("right"==n.textAlign)return Math.floor(t-e)}return 0}function f(t,e,n){var r;if(n)r=n;else{if("undefined"==typeof document)return 0;r=document.createElement("canvas").getContext("2d")}r.font=e.fontOptions+" "+e.fontSize+"px "+e.font;var o=r.measureText(t);return o?o.width:0}e.getMaximumHeightOfEncodings=function(t){for(var e=0,n=0;n<t.length;n++)t[n].height>e&&(e=t[n].height);return e},e.getEncodingHeight=a,e.getBarcodePadding=u,e.calculateEncodingAttributes=function(t,e,n){for(var r=0;r<t.length;r++){var o,c=t[r],s=(0,i.default)(e,c.options);o=s.displayValue?f(c.text,s,n):0;var l=c.data.length*s.width;c.width=Math.ceil(Math.max(o,l)),c.height=a(c,s),c.barcodePadding=u(o,l,s)}},e.getTotalWidthOfEncodings=function(t){for(var e=0,n=0;n<t.length;n++)e+=t[n].width;return e}},function(t,e,n){"use strict";function r(t,e){if(!(t instanceof e))throw new TypeError("Cannot call a class as a function")}function o(t,e){if(!t)throw new ReferenceError("this hasn't been initialised - super() hasn't been called");return!e||"object"!=typeof e&&"function"!=typeof e?t:e}function i(t,e){if("function"!=typeof e&&null!==e)throw new TypeError("Super expression must either be null or a function, not "+typeof e);t.prototype=Object.create(e&&e.prototype,{constructor:{value:t,enumerable:!1,writable:!0,configurable:!0}}),e&&(Object.setPrototypeOf?Object.setPrototypeOf(t,e):t.__proto__=e)}Object.defineProperty(e,"__esModule",{value:!0});var a=function(t){function e(t,n){r(this,e);var i=o(this,(e.__proto__||Object.getPrototypeOf(e)).call(this));return i.name="InvalidInputException",i.symbology=t,i.input=n,i.message='"'+i.input+'" is not a valid input for '+i.symbology,i}return i(e,Error),e}(),u=function(t){function e(){r(this,e);var t=o(this,(e.__proto__||Object.getPrototypeOf(e)).call(this));return t.name="InvalidElementException",t.message="Not supported type to render on",t}return i(e,Error),e}(),f=function(t){function e(){r(this,e);var t=o(this,(e.__proto__||Object.getPrototypeOf(e)).call(this));return t.name="NoElementException",t.message="No element to render on.",t}return i(e,Error),e}();e.InvalidInputException=a,e.InvalidElementException=u,e.NoElementException=f},function(t,e,n){"use strict";var r=p(n(17)),o=p(n(7)),i=p(n(45)),a=p(n(46)),u=p(n(47)),f=p(n(12)),c=p(n(53)),s=n(15),l=p(n(13));function p(t){return t&&t.__esModule?t:{default:t}}var d=function(){},h=function(t,e,n){var r=new d;if(void 0===t)throw Error("No element to render on was provided.");return r._renderProperties=(0,u.default)(t),r._encodings=[],r._options=l.default,r._errorHandler=new c.default(r),void 0!==e&&((n=n||{}).format||(n.format=_()),r.options(n)[n.format](e,n).render()),r};for(var y in h.getModule=function(t){return r.default[t]},r.default)r.default.hasOwnProperty(y)&&b(r.default,y);function b(t,e){d.prototype[e]=d.prototype[e.toUpperCase()]=d.prototype[e.toLowerCase()]=function(n,r){var i=this;return i._errorHandler.wrapBarcodeCall((function(){r.text=void 0===r.text?void 0:""+r.text;var a=(0,o.default)(i._options,r);a=(0,f.default)(a);var u=t[e],c=v(n,u,a);return i._encodings.push(c),i}))}}function v(t,e,n){var r=new e(t=""+t,n);if(!r.valid())throw new s.InvalidInputException(r.constructor.name,t);var a=r.encode();a=(0,i.default)(a);for(var u=0;u<a.length;u++)a[u].options=(0,o.default)(n,a[u].options);return a}function _(){return r.default.CODE128?"CODE128":Object.keys(r.default)[0]}function g(t,e,n){e=(0,i.default)(e);for(var r=0;r<e.length;r++)e[r].options=(0,o.default)(n,e[r].options),(0,a.default)(e[r].options);(0,a.default)(n),new(0,t.renderer)(t.element,e,n).render(),t.afterRender&&t.afterRender()}d.prototype.options=function(t){return this._options=(0,o.default)(this._options,t),this},d.prototype.blank=function(t){var e=new Array(t+1).join("0");return this._encodings.push({data:e}),this},d.prototype.init=function(){var t;if(this._renderProperties)for(var e in Array.isArray(this._renderProperties)||(this._renderProperties=[this._renderProperties]),this._renderProperties){t=this._renderProperties[e];var n=(0,o.default)(this._options,t.options);"auto"==n.format&&(n.format=_()),this._errorHandler.wrapBarcodeCall((function(){var e=v(n.value,r.default[n.format.toUpperCase()],n);g(t,e,n)}))}},d.prototype.render=function(){if(!this._renderProperties)throw new s.NoElementException;if(Array.isArray(this._renderProperties))for(var t=0;t<this._renderProperties.length;t++)g(this._renderProperties[t],this._encodings,this._options);else g(this._renderProperties,this._encodings,this._options);return this},d.prototype._defaults=l.default,"undefined"!=typeof window&&(window.JsBarcode=h),"undefined"!=typeof jQuery&&(jQuery.fn.JsBarcode=function(t,e){var n=[];return jQuery(this).each((function(){n.push(this)})),h(n,t,e)}),t.exports=h},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0});var r=n(18),o=n(19),i=n(25),a=n(31),u=n(34),f=n(39),c=n(40),s=n(41),l=n(44);e.default={CODE39:r.CODE39,CODE128:o.CODE128,CODE128A:o.CODE128A,CODE128B:o.CODE128B,CODE128C:o.CODE128C,EAN13:i.EAN13,EAN8:i.EAN8,EAN5:i.EAN5,EAN2:i.EAN2,UPC:i.UPC,UPCE:i.UPCE,ITF14:a.ITF14,ITF:a.ITF,MSI:u.MSI,MSI10:u.MSI10,MSI11:u.MSI11,MSI1010:u.MSI1010,MSI1110:u.MSI1110,pharmacode:f.pharmacode,codabar:c.codabar,CODE93:s.CODE93,CODE93FullASCII:s.CODE93FullASCII,GenericBarcode:l.GenericBarcode}},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0}),e.CODE39=void 0;var r,o=function(){function t(t,e){for(var n=0;n<e.length;n++){var r=e[n];r.enumerable=r.enumerable||!1,r.configurable=!0,"value"in r&&(r.writable=!0),Object.defineProperty(t,r.key,r)}}return function(e,n,r){return n&&t(e.prototype,n),r&&t(e,r),e}}(),i=n(0);var a=function(t){function e(t,n){return function(t,e){if(!(t instanceof e))throw new TypeError("Cannot call a class as a function")}(this,e),t=t.toUpperCase(),n.mod43&&(t+=function(t){return u[t]}(function(t){for(var e=0,n=0;n<t.length;n++)e+=s(t[n]);return e%=43}(t))),function(t,e){if(!t)throw new ReferenceError("this hasn't been initialised - super() hasn't been called");return!e||"object"!=typeof e&&"function"!=typeof e?t:e}(this,(e.__proto__||Object.getPrototypeOf(e)).call(this,t,n))}return function(t,e){if("function"!=typeof e&&null!==e)throw new TypeError("Super expression must either be null or a function, not "+typeof e);t.prototype=Object.create(e&&e.prototype,{constructor:{value:t,enumerable:!1,writable:!0,configurable:!0}}),e&&(Object.setPrototypeOf?Object.setPrototypeOf(t,e):t.__proto__=e)}(e,t),o(e,[{key:"encode",value:function(){for(var t=c("*"),e=0;e<this.data.length;e++)t+=c(this.data[e])+"0";return{data:t+=c("*"),text:this.text}}},{key:"valid",value:function(){return-1!==this.data.search(/^[0-9A-Z\-\.\ \$\/\+\%]+$/)}}]),e}(((r=i)&&r.__esModule?r:{default:r}).default),u=["0","1","2","3","4","5","6","7","8","9","A","B","C","D","E","F","G","H","I","J","K","L","M","N","O","P","Q","R","S","T","U","V","W","X","Y","Z","-","."," ","$","/","+","%","*"],f=[20957,29783,23639,30485,20951,29813,23669,20855,29789,23645,29975,23831,30533,22295,30149,24005,21623,29981,23837,22301,30023,23879,30545,22343,30161,24017,21959,30065,23921,22385,29015,18263,29141,17879,29045,18293,17783,29021,18269,17477,17489,17681,20753,35770];function c(t){return function(t){return f[t].toString(2)}(s(t))}function s(t){return u.indexOf(t)}e.CODE39=a},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0}),e.CODE128C=e.CODE128B=e.CODE128A=e.CODE128=void 0;var r=u(n(20)),o=u(n(22)),i=u(n(23)),a=u(n(24));function u(t){return t&&t.__esModule?t:{default:t}}e.CODE128=r.default,e.CODE128A=o.default,e.CODE128B=i.default,e.CODE128C=a.default},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0});var r=i(n(5)),o=i(n(21));function i(t){return t&&t.__esModule?t:{default:t}}function a(t,e){if(!t)throw new ReferenceError("this hasn't been initialised - super() hasn't been called");return!e||"object"!=typeof e&&"function"!=typeof e?t:e}var u=function(t){function e(t,n){if(function(t,e){if(!(t instanceof e))throw new TypeError("Cannot call a class as a function")}(this,e),/^[\x00-\x7F\xC8-\xD3]+$/.test(t))var r=a(this,(e.__proto__||Object.getPrototypeOf(e)).call(this,(0,o.default)(t),n));else r=a(this,(e.__proto__||Object.getPrototypeOf(e)).call(this,t,n));return a(r)}return function(t,e){if("function"!=typeof e&&null!==e)throw new TypeError("Super expression must either be null or a function, not "+typeof e);t.prototype=Object.create(e&&e.prototype,{constructor:{value:t,enumerable:!1,writable:!0,configurable:!0}}),e&&(Object.setPrototypeOf?Object.setPrototypeOf(t,e):t.__proto__=e)}(e,t),e}(r.default);e.default=u},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0});var r=n(1),o=function(t){return t.match(new RegExp("^"+r.A_CHARS+"*"))[0].length},i=function(t){return t.match(new RegExp("^"+r.B_CHARS+"*"))[0].length},a=function(t){return t.match(new RegExp("^"+r.C_CHARS+"*"))[0]};function u(t,e){var n=e?r.A_CHARS:r.B_CHARS,o=t.match(new RegExp("^("+n+"+?)(([0-9]{2}){2,})([^0-9]|$)"));if(o)return o[1]+String.fromCharCode(204)+f(t.substring(o[1].length));var i=t.match(new RegExp("^"+n+"+"))[0];return i.length===t.length?t:i+String.fromCharCode(e?205:206)+u(t.substring(i.length),!e)}function f(t){var e=a(t),n=e.length;if(n===t.length)return t;t=t.substring(n);var r=o(t)>=i(t);return e+String.fromCharCode(r?206:205)+u(t,r)}e.default=function(t){var e=void 0;if(a(t).length>=2)e=r.C_START_CHAR+f(t);else{var n=o(t)>i(t);e=(n?r.A_START_CHAR:r.B_START_CHAR)+u(t,n)}return e.replace(/[\xCD\xCE]([^])[\xCD\xCE]/,(function(t,e){return String.fromCharCode(203)+e}))}},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0});var r,o=function(){function t(t,e){for(var n=0;n<e.length;n++){var r=e[n];r.enumerable=r.enumerable||!1,r.configurable=!0,"value"in r&&(r.writable=!0),Object.defineProperty(t,r.key,r)}}return function(e,n,r){return n&&t(e.prototype,n),r&&t(e,r),e}}(),i=n(5),a=(r=i)&&r.__esModule?r:{default:r},u=n(1);var f=function(t){function e(t,n){return function(t,e){if(!(t instanceof e))throw new TypeError("Cannot call a class as a function")}(this,e),function(t,e){if(!t)throw new ReferenceError("this hasn't been initialised - super() hasn't been called");return!e||"object"!=typeof e&&"function"!=typeof e?t:e}(this,(e.__proto__||Object.getPrototypeOf(e)).call(this,u.A_START_CHAR+t,n))}return function(t,e){if("function"!=typeof e&&null!==e)throw new TypeError("Super expression must either be null or a function, not "+typeof e);t.prototype=Object.create(e&&e.prototype,{constructor:{value:t,enumerable:!1,writable:!0,configurable:!0}}),e&&(Object.setPrototypeOf?Object.setPrototypeOf(t,e):t.__proto__=e)}(e,t),o(e,[{key:"valid",value:function(){return new RegExp("^"+u.A_CHARS+"+$").test(this.data)}}]),e}(a.default);e.default=f},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0});var r,o=function(){function t(t,e){for(var n=0;n<e.length;n++){var r=e[n];r.enumerable=r.enumerable||!1,r.configurable=!0,"value"in r&&(r.writable=!0),Object.defineProperty(t,r.key,r)}}return function(e,n,r){return n&&t(e.prototype,n),r&&t(e,r),e}}(),i=n(5),a=(r=i)&&r.__esModule?r:{default:r},u=n(1);var f=function(t){function e(t,n){return function(t,e){if(!(t instanceof e))throw new TypeError("Cannot call a class as a function")}(this,e),function(t,e){if(!t)throw new ReferenceError("this hasn't been initialised - super() hasn't been called");return!e||"object"!=typeof e&&"function"!=typeof e?t:e}(this,(e.__proto__||Object.getPrototypeOf(e)).call(this,u.B_START_CHAR+t,n))}return function(t,e){if("function"!=typeof e&&null!==e)throw new TypeError("Super expression must either be null or a function, not "+typeof e);t.prototype=Object.create(e&&e.prototype,{constructor:{value:t,enumerable:!1,writable:!0,configurable:!0}}),e&&(Object.setPrototypeOf?Object.setPrototypeOf(t,e):t.__proto__=e)}(e,t),o(e,[{key:"valid",value:function(){return new RegExp("^"+u.B_CHARS+"+$").test(this.data)}}]),e}(a.default);e.default=f},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0});var r,o=function(){function t(t,e){for(var n=0;n<e.length;n++){var r=e[n];r.enumerable=r.enumerable||!1,r.configurable=!0,"value"in r&&(r.writable=!0),Object.defineProperty(t,r.key,r)}}return function(e,n,r){return n&&t(e.prototype,n),r&&t(e,r),e}}(),i=n(5),a=(r=i)&&r.__esModule?r:{default:r},u=n(1);var f=function(t){function e(t,n){return function(t,e){if(!(t instanceof e))throw new TypeError("Cannot call a class as a function")}(this,e),function(t,e){if(!t)throw new ReferenceError("this hasn't been initialised - super() hasn't been called");return!e||"object"!=typeof e&&"function"!=typeof e?t:e}(this,(e.__proto__||Object.getPrototypeOf(e)).call(this,u.C_START_CHAR+t,n))}return function(t,e){if("function"!=typeof e&&null!==e)throw new TypeError("Super expression must either be null or a function, not "+typeof e);t.prototype=Object.create(e&&e.prototype,{constructor:{value:t,enumerable:!1,writable:!0,configurable:!0}}),e&&(Object.setPrototypeOf?Object.setPrototypeOf(t,e):t.__proto__=e)}(e,t),o(e,[{key:"valid",value:function(){return new RegExp("^"+u.C_CHARS+"+$").test(this.data)}}]),e}(a.default);e.default=f},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0}),e.UPCE=e.UPC=e.EAN2=e.EAN5=e.EAN8=e.EAN13=void 0;var r=c(n(26)),o=c(n(27)),i=c(n(28)),a=c(n(29)),u=c(n(9)),f=c(n(30));function c(t){return t&&t.__esModule?t:{default:t}}e.EAN13=r.default,e.EAN8=o.default,e.EAN5=i.default,e.EAN2=a.default,e.UPC=u.default,e.UPCE=f.default},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0});var r,o=function(){function t(t,e){for(var n=0;n<e.length;n++){var r=e[n];r.enumerable=r.enumerable||!1,r.configurable=!0,"value"in r&&(r.writable=!0),Object.defineProperty(t,r.key,r)}}return function(e,n,r){return n&&t(e.prototype,n),r&&t(e,r),e}}(),i=function t(e,n,r){null===e&&(e=Function.prototype);var o=Object.getOwnPropertyDescriptor(e,n);if(void 0===o){var i=Object.getPrototypeOf(e);return null===i?void 0:t(i,n,r)}if("value"in o)return o.value;var a=o.get;return void 0!==a?a.call(r):void 0},a=n(2),u=n(8),f=(r=u)&&r.__esModule?r:{default:r};var c=function(t){return(10-t.substr(0,12).split("").map((function(t){return+t})).reduce((function(t,e,n){return n%2?t+3*e:t+e}),0)%10)%10},s=function(t){function e(t,n){!function(t,e){if(!(t instanceof e))throw new TypeError("Cannot call a class as a function")}(this,e),-1!==t.search(/^[0-9]{12}$/)&&(t+=c(t));var r=function(t,e){if(!t)throw new ReferenceError("this hasn't been initialised - super() hasn't been called");return!e||"object"!=typeof e&&"function"!=typeof e?t:e}(this,(e.__proto__||Object.getPrototypeOf(e)).call(this,t,n));return r.lastChar=n.lastChar,r}return function(t,e){if("function"!=typeof e&&null!==e)throw new TypeError("Super expression must either be null or a function, not "+typeof e);t.prototype=Object.create(e&&e.prototype,{constructor:{value:t,enumerable:!1,writable:!0,configurable:!0}}),e&&(Object.setPrototypeOf?Object.setPrototypeOf(t,e):t.__proto__=e)}(e,t),o(e,[{key:"valid",value:function(){return-1!==this.data.search(/^[0-9]{13}$/)&&+this.data[12]===c(this.data)}},{key:"leftText",value:function(){return i(e.prototype.__proto__||Object.getPrototypeOf(e.prototype),"leftText",this).call(this,1,6)}},{key:"leftEncode",value:function(){var t=this.data.substr(1,6),n=a.EAN13_STRUCTURE[this.data[0]];return i(e.prototype.__proto__||Object.getPrototypeOf(e.prototype),"leftEncode",this).call(this,t,n)}},{key:"rightText",value:function(){return i(e.prototype.__proto__||Object.getPrototypeOf(e.prototype),"rightText",this).call(this,7,6)}},{key:"rightEncode",value:function(){var t=this.data.substr(7,6);return i(e.prototype.__proto__||Object.getPrototypeOf(e.prototype),"rightEncode",this).call(this,t,"RRRRRR")}},{key:"encodeGuarded",value:function(){var t=i(e.prototype.__proto__||Object.getPrototypeOf(e.prototype),"encodeGuarded",this).call(this);return this.options.displayValue&&(t.unshift({data:"000000000000",text:this.text.substr(0,1),options:{textAlign:"left",fontSize:this.fontSize}}),this.options.lastChar&&(t.push({data:"00"}),t.push({data:"00000",text:this.options.lastChar,options:{fontSize:this.fontSize}}))),t}}]),e}(f.default);e.default=s},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0});var r,o=function(){function t(t,e){for(var n=0;n<e.length;n++){var r=e[n];r.enumerable=r.enumerable||!1,r.configurable=!0,"value"in r&&(r.writable=!0),Object.defineProperty(t,r.key,r)}}return function(e,n,r){return n&&t(e.prototype,n),r&&t(e,r),e}}(),i=function t(e,n,r){null===e&&(e=Function.prototype);var o=Object.getOwnPropertyDescriptor(e,n);if(void 0===o){var i=Object.getPrototypeOf(e);return null===i?void 0:t(i,n,r)}if("value"in o)return o.value;var a=o.get;return void 0!==a?a.call(r):void 0},a=n(8),u=(r=a)&&r.__esModule?r:{default:r};var f=function(t){return(10-t.substr(0,7).split("").map((function(t){return+t})).reduce((function(t,e,n){return n%2?t+e:t+3*e}),0)%10)%10},c=function(t){function e(t,n){return function(t,e){if(!(t instanceof e))throw new TypeError("Cannot call a class as a function")}(this,e),-1!==t.search(/^[0-9]{7}$/)&&(t+=f(t)),function(t,e){if(!t)throw new ReferenceError("this hasn't been initialised - super() hasn't been called");return!e||"object"!=typeof e&&"function"!=typeof e?t:e}(this,(e.__proto__||Object.getPrototypeOf(e)).call(this,t,n))}return function(t,e){if("function"!=typeof e&&null!==e)throw new TypeError("Super expression must either be null or a function, not "+typeof e);t.prototype=Object.create(e&&e.prototype,{constructor:{value:t,enumerable:!1,writable:!0,configurable:!0}}),e&&(Object.setPrototypeOf?Object.setPrototypeOf(t,e):t.__proto__=e)}(e,t),o(e,[{key:"valid",value:function(){return-1!==this.data.search(/^[0-9]{8}$/)&&+this.data[7]===f(this.data)}},{key:"leftText",value:function(){return i(e.prototype.__proto__||Object.getPrototypeOf(e.prototype),"leftText",this).call(this,0,4)}},{key:"leftEncode",value:function(){var t=this.data.substr(0,4);return i(e.prototype.__proto__||Object.getPrototypeOf(e.prototype),"leftEncode",this).call(this,t,"LLLL")}},{key:"rightText",value:function(){return i(e.prototype.__proto__||Object.getPrototypeOf(e.prototype),"rightText",this).call(this,4,4)}},{key:"rightEncode",value:function(){var t=this.data.substr(4,4);return i(e.prototype.__proto__||Object.getPrototypeOf(e.prototype),"rightEncode",this).call(this,t,"RRRR")}}]),e}(u.default);e.default=c},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0});var r=function(){function t(t,e){for(var n=0;n<e.length;n++){var r=e[n];r.enumerable=r.enumerable||!1,r.configurable=!0,"value"in r&&(r.writable=!0),Object.defineProperty(t,r.key,r)}}return function(e,n,r){return n&&t(e.prototype,n),r&&t(e,r),e}}(),o=n(2),i=u(n(3)),a=u(n(0));function u(t){return t&&t.__esModule?t:{default:t}}var f=function(t){function e(t,n){return function(t,e){if(!(t instanceof e))throw new TypeError("Cannot call a class as a function")}(this,e),function(t,e){if(!t)throw new ReferenceError("this hasn't been initialised - super() hasn't been called");return!e||"object"!=typeof e&&"function"!=typeof e?t:e}(this,(e.__proto__||Object.getPrototypeOf(e)).call(this,t,n))}return function(t,e){if("function"!=typeof e&&null!==e)throw new TypeError("Super expression must either be null or a function, not "+typeof e);t.prototype=Object.create(e&&e.prototype,{constructor:{value:t,enumerable:!1,writable:!0,configurable:!0}}),e&&(Object.setPrototypeOf?Object.setPrototypeOf(t,e):t.__proto__=e)}(e,t),r(e,[{key:"valid",value:function(){return-1!==this.data.search(/^[0-9]{5}$/)}},{key:"encode",value:function(){var t,e=o.EAN5_STRUCTURE[(t=this.data,t.split("").map((function(t){return+t})).reduce((function(t,e,n){return n%2?t+9*e:t+3*e}),0)%10)];return{data:"1011"+(0,i.default)(this.data,e,"01"),text:this.text}}}]),e}(a.default);e.default=f},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0});var r=function(){function t(t,e){for(var n=0;n<e.length;n++){var r=e[n];r.enumerable=r.enumerable||!1,r.configurable=!0,"value"in r&&(r.writable=!0),Object.defineProperty(t,r.key,r)}}return function(e,n,r){return n&&t(e.prototype,n),r&&t(e,r),e}}(),o=n(2),i=a(n(3));function a(t){return t&&t.__esModule?t:{default:t}}var u=function(t){function e(t,n){return function(t,e){if(!(t instanceof e))throw new TypeError("Cannot call a class as a function")}(this,e),function(t,e){if(!t)throw new ReferenceError("this hasn't been initialised - super() hasn't been called");return!e||"object"!=typeof e&&"function"!=typeof e?t:e}(this,(e.__proto__||Object.getPrototypeOf(e)).call(this,t,n))}return function(t,e){if("function"!=typeof e&&null!==e)throw new TypeError("Super expression must either be null or a function, not "+typeof e);t.prototype=Object.create(e&&e.prototype,{constructor:{value:t,enumerable:!1,writable:!0,configurable:!0}}),e&&(Object.setPrototypeOf?Object.setPrototypeOf(t,e):t.__proto__=e)}(e,t),r(e,[{key:"valid",value:function(){return-1!==this.data.search(/^[0-9]{2}$/)}},{key:"encode",value:function(){var t=o.EAN2_STRUCTURE[parseInt(this.data)%4];return{data:"1011"+(0,i.default)(this.data,t,"01"),text:this.text}}}]),e}(a(n(0)).default);e.default=u},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0});var r=function(){function t(t,e){for(var n=0;n<e.length;n++){var r=e[n];r.enumerable=r.enumerable||!1,r.configurable=!0,"value"in r&&(r.writable=!0),Object.defineProperty(t,r.key,r)}}return function(e,n,r){return n&&t(e.prototype,n),r&&t(e,r),e}}(),o=u(n(3)),i=u(n(0)),a=n(9);function u(t){return t&&t.__esModule?t:{default:t}}function f(t,e){if(!t)throw new ReferenceError("this hasn't been initialised - super() hasn't been called");return!e||"object"!=typeof e&&"function"!=typeof e?t:e}var c=["XX00000XXX","XX10000XXX","XX20000XXX","XXX00000XX","XXXX00000X","XXXXX00005","XXXXX00006","XXXXX00007","XXXXX00008","XXXXX00009"],s=[["EEEOOO","OOOEEE"],["EEOEOO","OOEOEE"],["EEOOEO","OOEEOE"],["EEOOOE","OOEEEO"],["EOEEOO","OEOOEE"],["EOOEEO","OEEOOE"],["EOOOEE","OEEEOO"],["EOEOEO","OEOEOE"],["EOEOOE","OEOEEO"],["EOOEOE","OEEOEO"]],l=function(t){function e(t,n){!function(t,e){if(!(t instanceof e))throw new TypeError("Cannot call a class as a function")}(this,e);var r=f(this,(e.__proto__||Object.getPrototypeOf(e)).call(this,t,n));if(r.isValid=!1,-1!==t.search(/^[0-9]{6}$/))r.middleDigits=t,r.upcA=p(t,"0"),r.text=n.text||""+r.upcA[0]+t+r.upcA[r.upcA.length-1],r.isValid=!0;else{if(-1===t.search(/^[01][0-9]{7}$/))return f(r);if(r.middleDigits=t.substring(1,t.length-1),r.upcA=p(r.middleDigits,t[0]),r.upcA[r.upcA.length-1]!==t[t.length-1])return f(r);r.isValid=!0}return r.displayValue=n.displayValue,n.fontSize>10*n.width?r.fontSize=10*n.width:r.fontSize=n.fontSize,r.guardHeight=n.height+r.fontSize/2+n.textMargin,r}return function(t,e){if("function"!=typeof e&&null!==e)throw new TypeError("Super expression must either be null or a function, not "+typeof e);t.prototype=Object.create(e&&e.prototype,{constructor:{value:t,enumerable:!1,writable:!0,configurable:!0}}),e&&(Object.setPrototypeOf?Object.setPrototypeOf(t,e):t.__proto__=e)}(e,t),r(e,[{key:"valid",value:function(){return this.isValid}},{key:"encode",value:function(){return this.options.flat?this.flatEncoding():this.guardedEncoding()}},{key:"flatEncoding",value:function(){var t="";return t+="101",t+=this.encodeMiddleDigits(),{data:t+="010101",text:this.text}}},{key:"guardedEncoding",value:function(){var t=[];return this.displayValue&&t.push({data:"00000000",text:this.text[0],options:{textAlign:"left",fontSize:this.fontSize}}),t.push({data:"101",options:{height:this.guardHeight}}),t.push({data:this.encodeMiddleDigits(),text:this.text.substring(1,7),options:{fontSize:this.fontSize}}),t.push({data:"010101",options:{height:this.guardHeight}}),this.displayValue&&t.push({data:"00000000",text:this.text[7],options:{textAlign:"right",fontSize:this.fontSize}}),t}},{key:"encodeMiddleDigits",value:function(){var t=this.upcA[0],e=this.upcA[this.upcA.length-1],n=s[parseInt(e)][parseInt(t)];return(0,o.default)(this.middleDigits,n)}}]),e}(i.default);function p(t,e){for(var n=parseInt(t[t.length-1]),r=c[n],o="",i=0,u=0;u<r.length;u++){var f=r[u];o+="X"===f?t[i++]:f}return""+(o=""+e+o)+(0,a.checksum)(o)}e.default=l},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0}),e.ITF14=e.ITF=void 0;var r=i(n(10)),o=i(n(33));function i(t){return t&&t.__esModule?t:{default:t}}e.ITF=r.default,e.ITF14=o.default},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0});e.START_BIN="1010",e.END_BIN="11101",e.BINARIES=["00110","10001","01001","11000","00101","10100","01100","00011","10010","01010"]},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0});var r,o=function(){function t(t,e){for(var n=0;n<e.length;n++){var r=e[n];r.enumerable=r.enumerable||!1,r.configurable=!0,"value"in r&&(r.writable=!0),Object.defineProperty(t,r.key,r)}}return function(e,n,r){return n&&t(e.prototype,n),r&&t(e,r),e}}(),i=n(10),a=(r=i)&&r.__esModule?r:{default:r};var u=function(t){var e=t.substr(0,13).split("").map((function(t){return parseInt(t,10)})).reduce((function(t,e,n){return t+e*(3-n%2*2)}),0);return 10*Math.ceil(e/10)-e},f=function(t){function e(t,n){return function(t,e){if(!(t instanceof e))throw new TypeError("Cannot call a class as a function")}(this,e),-1!==t.search(/^[0-9]{13}$/)&&(t+=u(t)),function(t,e){if(!t)throw new ReferenceError("this hasn't been initialised - super() hasn't been called");return!e||"object"!=typeof e&&"function"!=typeof e?t:e}(this,(e.__proto__||Object.getPrototypeOf(e)).call(this,t,n))}return function(t,e){if("function"!=typeof e&&null!==e)throw new TypeError("Super expression must either be null or a function, not "+typeof e);t.prototype=Object.create(e&&e.prototype,{constructor:{value:t,enumerable:!1,writable:!0,configurable:!0}}),e&&(Object.setPrototypeOf?Object.setPrototypeOf(t,e):t.__proto__=e)}(e,t),o(e,[{key:"valid",value:function(){return-1!==this.data.search(/^[0-9]{14}$/)&&+this.data[13]===u(this.data)}}]),e}(a.default);e.default=f},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0}),e.MSI1110=e.MSI1010=e.MSI11=e.MSI10=e.MSI=void 0;var r=f(n(4)),o=f(n(35)),i=f(n(36)),a=f(n(37)),u=f(n(38));function f(t){return t&&t.__esModule?t:{default:t}}e.MSI=r.default,e.MSI10=o.default,e.MSI11=i.default,e.MSI1010=a.default,e.MSI1110=u.default},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0});var r,o=n(4),i=(r=o)&&r.__esModule?r:{default:r},a=n(6);var u=function(t){function e(t,n){return function(t,e){if(!(t instanceof e))throw new TypeError("Cannot call a class as a function")}(this,e),function(t,e){if(!t)throw new ReferenceError("this hasn't been initialised - super() hasn't been called");return!e||"object"!=typeof e&&"function"!=typeof e?t:e}(this,(e.__proto__||Object.getPrototypeOf(e)).call(this,t+(0,a.mod10)(t),n))}return function(t,e){if("function"!=typeof e&&null!==e)throw new TypeError("Super expression must either be null or a function, not "+typeof e);t.prototype=Object.create(e&&e.prototype,{constructor:{value:t,enumerable:!1,writable:!0,configurable:!0}}),e&&(Object.setPrototypeOf?Object.setPrototypeOf(t,e):t.__proto__=e)}(e,t),e}(i.default);e.default=u},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0});var r,o=n(4),i=(r=o)&&r.__esModule?r:{default:r},a=n(6);var u=function(t){function e(t,n){return function(t,e){if(!(t instanceof e))throw new TypeError("Cannot call a class as a function")}(this,e),function(t,e){if(!t)throw new ReferenceError("this hasn't been initialised - super() hasn't been called");return!e||"object"!=typeof e&&"function"!=typeof e?t:e}(this,(e.__proto__||Object.getPrototypeOf(e)).call(this,t+(0,a.mod11)(t),n))}return function(t,e){if("function"!=typeof e&&null!==e)throw new TypeError("Super expression must either be null or a function, not "+typeof e);t.prototype=Object.create(e&&e.prototype,{constructor:{value:t,enumerable:!1,writable:!0,configurable:!0}}),e&&(Object.setPrototypeOf?Object.setPrototypeOf(t,e):t.__proto__=e)}(e,t),e}(i.default);e.default=u},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0});var r,o=n(4),i=(r=o)&&r.__esModule?r:{default:r},a=n(6);var u=function(t){function e(t,n){return function(t,e){if(!(t instanceof e))throw new TypeError("Cannot call a class as a function")}(this,e),t+=(0,a.mod10)(t),t+=(0,a.mod10)(t),function(t,e){if(!t)throw new ReferenceError("this hasn't been initialised - super() hasn't been called");return!e||"object"!=typeof e&&"function"!=typeof e?t:e}(this,(e.__proto__||Object.getPrototypeOf(e)).call(this,t,n))}return function(t,e){if("function"!=typeof e&&null!==e)throw new TypeError("Super expression must either be null or a function, not "+typeof e);t.prototype=Object.create(e&&e.prototype,{constructor:{value:t,enumerable:!1,writable:!0,configurable:!0}}),e&&(Object.setPrototypeOf?Object.setPrototypeOf(t,e):t.__proto__=e)}(e,t),e}(i.default);e.default=u},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0});var r,o=n(4),i=(r=o)&&r.__esModule?r:{default:r},a=n(6);var u=function(t){function e(t,n){return function(t,e){if(!(t instanceof e))throw new TypeError("Cannot call a class as a function")}(this,e),t+=(0,a.mod11)(t),t+=(0,a.mod10)(t),function(t,e){if(!t)throw new ReferenceError("this hasn't been initialised - super() hasn't been called");return!e||"object"!=typeof e&&"function"!=typeof e?t:e}(this,(e.__proto__||Object.getPrototypeOf(e)).call(this,t,n))}return function(t,e){if("function"!=typeof e&&null!==e)throw new TypeError("Super expression must either be null or a function, not "+typeof e);t.prototype=Object.create(e&&e.prototype,{constructor:{value:t,enumerable:!1,writable:!0,configurable:!0}}),e&&(Object.setPrototypeOf?Object.setPrototypeOf(t,e):t.__proto__=e)}(e,t),e}(i.default);e.default=u},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0}),e.pharmacode=void 0;var r,o=function(){function t(t,e){for(var n=0;n<e.length;n++){var r=e[n];r.enumerable=r.enumerable||!1,r.configurable=!0,"value"in r&&(r.writable=!0),Object.defineProperty(t,r.key,r)}}return function(e,n,r){return n&&t(e.prototype,n),r&&t(e,r),e}}(),i=n(0);var a=function(t){function e(t,n){!function(t,e){if(!(t instanceof e))throw new TypeError("Cannot call a class as a function")}(this,e);var r=function(t,e){if(!t)throw new ReferenceError("this hasn't been initialised - super() hasn't been called");return!e||"object"!=typeof e&&"function"!=typeof e?t:e}(this,(e.__proto__||Object.getPrototypeOf(e)).call(this,t,n));return r.number=parseInt(t,10),r}return function(t,e){if("function"!=typeof e&&null!==e)throw new TypeError("Super expression must either be null or a function, not "+typeof e);t.prototype=Object.create(e&&e.prototype,{constructor:{value:t,enumerable:!1,writable:!0,configurable:!0}}),e&&(Object.setPrototypeOf?Object.setPrototypeOf(t,e):t.__proto__=e)}(e,t),o(e,[{key:"encode",value:function(){for(var t=this.number,e="";!isNaN(t)&&0!=t;)t%2==0?(e="11100"+e,t=(t-2)/2):(e="100"+e,t=(t-1)/2);return{data:e=e.slice(0,-2),text:this.text}}},{key:"valid",value:function(){return this.number>=3&&this.number<=131070}}]),e}(((r=i)&&r.__esModule?r:{default:r}).default);e.pharmacode=a},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0}),e.codabar=void 0;var r,o=function(){function t(t,e){for(var n=0;n<e.length;n++){var r=e[n];r.enumerable=r.enumerable||!1,r.configurable=!0,"value"in r&&(r.writable=!0),Object.defineProperty(t,r.key,r)}}return function(e,n,r){return n&&t(e.prototype,n),r&&t(e,r),e}}(),i=n(0);var a=function(t){function e(t,n){!function(t,e){if(!(t instanceof e))throw new TypeError("Cannot call a class as a function")}(this,e),0===t.search(/^[0-9\-\$\:\.\+\/]+$/)&&(t="A"+t+"A");var r=function(t,e){if(!t)throw new ReferenceError("this hasn't been initialised - super() hasn't been called");return!e||"object"!=typeof e&&"function"!=typeof e?t:e}(this,(e.__proto__||Object.getPrototypeOf(e)).call(this,t.toUpperCase(),n));return r.text=r.options.text||r.text.replace(/[A-D]/g,""),r}return function(t,e){if("function"!=typeof e&&null!==e)throw new TypeError("Super expression must either be null or a function, not "+typeof e);t.prototype=Object.create(e&&e.prototype,{constructor:{value:t,enumerable:!1,writable:!0,configurable:!0}}),e&&(Object.setPrototypeOf?Object.setPrototypeOf(t,e):t.__proto__=e)}(e,t),o(e,[{key:"valid",value:function(){return-1!==this.data.search(/^[A-D][0-9\-\$\:\.\+\/]+[A-D]$/)}},{key:"encode",value:function(){for(var t=[],e=this.getEncodings(),n=0;n<this.data.length;n++)t.push(e[this.data.charAt(n)]),n!==this.data.length-1&&t.push("0");return{text:this.text,data:t.join("")}}},{key:"getEncodings",value:function(){return{0:"101010011",1:"101011001",2:"101001011",3:"110010101",4:"101101001",5:"110101001",6:"100101011",7:"100101101",8:"100110101",9:"110100101","-":"101001101",$:"101100101",":":"1101011011","/":"1101101011",".":"1101101101","+":"1011011011",A:"1011001001",B:"1001001011",C:"1010010011",D:"1010011001"}}}]),e}(((r=i)&&r.__esModule?r:{default:r}).default);e.codabar=a},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0}),e.CODE93FullASCII=e.CODE93=void 0;var r=i(n(11)),o=i(n(43));function i(t){return t&&t.__esModule?t:{default:t}}e.CODE93=r.default,e.CODE93FullASCII=o.default},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0});e.SYMBOLS=["0","1","2","3","4","5","6","7","8","9","A","B","C","D","E","F","G","H","I","J","K","L","M","N","O","P","Q","R","S","T","U","V","W","X","Y","Z","-","."," ","$","/","+","%","($)","(%)","(/)","(+)","ÿ"],e.BINARIES=["100010100","101001000","101000100","101000010","100101000","100100100","100100010","101010000","100010010","100001010","110101000","110100100","110100010","110010100","110010010","110001010","101101000","101100100","101100010","100110100","100011010","101011000","101001100","101000110","100101100","100010110","110110100","110110010","110101100","110100110","110010110","110011010","101101100","101100110","100110110","100111010","100101110","111010100","111010010","111001010","101101110","101110110","110101110","100100110","111011010","111010110","100110010","101011110"],e.MULTI_SYMBOLS={"\0":["(%)","U"],"":["($)","A"],"":["($)","B"],"":["($)","C"],"":["($)","D"],"":["($)","E"],"":["($)","F"],"":["($)","G"],"\b":["($)","H"],"\t":["($)","I"],"\n":["($)","J"],"\v":["($)","K"],"\f":["($)","L"],"\r":["($)","M"],"":["($)","N"],"":["($)","O"],"":["($)","P"],"":["($)","Q"],"":["($)","R"],"":["($)","S"],"":["($)","T"],"":["($)","U"],"":["($)","V"],"":["($)","W"],"":["($)","X"],"":["($)","Y"],"":["($)","Z"],"":["(%)","A"],"":["(%)","B"],"":["(%)","C"],"":["(%)","D"],"":["(%)","E"],"!":["(/)","A"],'"':["(/)","B"],"#":["(/)","C"],"&":["(/)","F"],"'":["(/)","G"],"(":["(/)","H"],")":["(/)","I"],"*":["(/)","J"],",":["(/)","L"],":":["(/)","Z"],";":["(%)","F"],"<":["(%)","G"],"=":["(%)","H"],">":["(%)","I"],"?":["(%)","J"],"@":["(%)","V"],"[":["(%)","K"],"\\":["(%)","L"],"]":["(%)","M"],"^":["(%)","N"],_:["(%)","O"],"`":["(%)","W"],a:["(+)","A"],b:["(+)","B"],c:["(+)","C"],d:["(+)","D"],e:["(+)","E"],f:["(+)","F"],g:["(+)","G"],h:["(+)","H"],i:["(+)","I"],j:["(+)","J"],k:["(+)","K"],l:["(+)","L"],m:["(+)","M"],n:["(+)","N"],o:["(+)","O"],p:["(+)","P"],q:["(+)","Q"],r:["(+)","R"],s:["(+)","S"],t:["(+)","T"],u:["(+)","U"],v:["(+)","V"],w:["(+)","W"],x:["(+)","X"],y:["(+)","Y"],z:["(+)","Z"],"{":["(%)","P"],"|":["(%)","Q"],"}":["(%)","R"],"~":["(%)","S"],"":["(%)","T"]}},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0});var r,o=function(){function t(t,e){for(var n=0;n<e.length;n++){var r=e[n];r.enumerable=r.enumerable||!1,r.configurable=!0,"value"in r&&(r.writable=!0),Object.defineProperty(t,r.key,r)}}return function(e,n,r){return n&&t(e.prototype,n),r&&t(e,r),e}}(),i=n(11);var a=function(t){function e(t,n){return function(t,e){if(!(t instanceof e))throw new TypeError("Cannot call a class as a function")}(this,e),function(t,e){if(!t)throw new ReferenceError("this hasn't been initialised - super() hasn't been called");return!e||"object"!=typeof e&&"function"!=typeof e?t:e}(this,(e.__proto__||Object.getPrototypeOf(e)).call(this,t,n))}return function(t,e){if("function"!=typeof e&&null!==e)throw new TypeError("Super expression must either be null or a function, not "+typeof e);t.prototype=Object.create(e&&e.prototype,{constructor:{value:t,enumerable:!1,writable:!0,configurable:!0}}),e&&(Object.setPrototypeOf?Object.setPrototypeOf(t,e):t.__proto__=e)}(e,t),o(e,[{key:"valid",value:function(){return/^[\x00-\x7f]+$/.test(this.data)}}]),e}(((r=i)&&r.__esModule?r:{default:r}).default);e.default=a},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0}),e.GenericBarcode=void 0;var r,o=function(){function t(t,e){for(var n=0;n<e.length;n++){var r=e[n];r.enumerable=r.enumerable||!1,r.configurable=!0,"value"in r&&(r.writable=!0),Object.defineProperty(t,r.key,r)}}return function(e,n,r){return n&&t(e.prototype,n),r&&t(e,r),e}}(),i=n(0);var a=function(t){function e(t,n){return function(t,e){if(!(t instanceof e))throw new TypeError("Cannot call a class as a function")}(this,e),function(t,e){if(!t)throw new ReferenceError("this hasn't been initialised - super() hasn't been called");return!e||"object"!=typeof e&&"function"!=typeof e?t:e}(this,(e.__proto__||Object.getPrototypeOf(e)).call(this,t,n))}return function(t,e){if("function"!=typeof e&&null!==e)throw new TypeError("Super expression must either be null or a function, not "+typeof e);t.prototype=Object.create(e&&e.prototype,{constructor:{value:t,enumerable:!1,writable:!0,configurable:!0}}),e&&(Object.setPrototypeOf?Object.setPrototypeOf(t,e):t.__proto__=e)}(e,t),o(e,[{key:"encode",value:function(){return{data:"10101010101010101010101010101010101010101",text:this.text}}},{key:"valid",value:function(){return!0}}]),e}(((r=i)&&r.__esModule?r:{default:r}).default);e.GenericBarcode=a},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0}),e.default=function(t){var e=[];return function t(n){if(Array.isArray(n))for(var r=0;r<n.length;r++)t(n[r]);else n.text=n.text||"",n.data=n.data||"",e.push(n)}(t),e}},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0}),e.default=function(t){return t.marginTop=t.marginTop||t.margin,t.marginBottom=t.marginBottom||t.margin,t.marginRight=t.marginRight||t.margin,t.marginLeft=t.marginLeft||t.margin,t}},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0});var r="function"==typeof Symbol&&"symbol"==typeof Symbol.iterator?function(t){return typeof t}:function(t){return t&&"function"==typeof Symbol&&t.constructor===Symbol&&t!==Symbol.prototype?"symbol":typeof t},o=u(n(48)),i=u(n(49)),a=n(15);function u(t){return t&&t.__esModule?t:{default:t}}function f(t){if("string"==typeof t)return function(t){var e=document.querySelectorAll(t);if(0===e.length)return;for(var n=[],r=0;r<e.length;r++)n.push(f(e[r]));return n}(t);if(Array.isArray(t)){for(var e=[],n=0;n<t.length;n++)e.push(f(t[n]));return e}if("undefined"!=typeof HTMLCanvasElement&&t instanceof HTMLImageElement)return u=t,{element:c=document.createElement("canvas"),options:(0,o.default)(u),renderer:i.default.CanvasRenderer,afterRender:function(){u.setAttribute("src",c.toDataURL())}};if(t&&t.nodeName&&"svg"===t.nodeName.toLowerCase()||"undefined"!=typeof SVGElement&&t instanceof SVGElement)return{element:t,options:(0,o.default)(t),renderer:i.default.SVGRenderer};if("undefined"!=typeof HTMLCanvasElement&&t instanceof HTMLCanvasElement)return{element:t,options:(0,o.default)(t),renderer:i.default.CanvasRenderer};if(t&&t.getContext)return{element:t,renderer:i.default.CanvasRenderer};if(t&&"object"===(void 0===t?"undefined":r(t))&&!t.nodeName)return{element:t,renderer:i.default.ObjectRenderer};throw new a.InvalidElementException;var u,c}e.default=f},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0});var r=i(n(12)),o=i(n(13));function i(t){return t&&t.__esModule?t:{default:t}}e.default=function(t){var e={};for(var n in o.default)o.default.hasOwnProperty(n)&&(t.hasAttribute("jsbarcode-"+n.toLowerCase())&&(e[n]=t.getAttribute("jsbarcode-"+n.toLowerCase())),t.hasAttribute("data-"+n.toLowerCase())&&(e[n]=t.getAttribute("data-"+n.toLowerCase())));return e.value=t.getAttribute("jsbarcode-value")||t.getAttribute("data-value"),e=(0,r.default)(e)}},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0});var r=a(n(50)),o=a(n(51)),i=a(n(52));function a(t){return t&&t.__esModule?t:{default:t}}e.default={CanvasRenderer:r.default,SVGRenderer:o.default,ObjectRenderer:i.default}},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0});var r,o=function(){function t(t,e){for(var n=0;n<e.length;n++){var r=e[n];r.enumerable=r.enumerable||!1,r.configurable=!0,"value"in r&&(r.writable=!0),Object.defineProperty(t,r.key,r)}}return function(e,n,r){return n&&t(e.prototype,n),r&&t(e,r),e}}(),i=n(7),a=(r=i)&&r.__esModule?r:{default:r},u=n(14);var f=function(){function t(e,n,r){!function(t,e){if(!(t instanceof e))throw new TypeError("Cannot call a class as a function")}(this,t),this.canvas=e,this.encodings=n,this.options=r}return o(t,[{key:"render",value:function(){if(!this.canvas.getContext)throw new Error("The browser does not support canvas.");this.prepareCanvas();for(var t=0;t<this.encodings.length;t++){var e=(0,a.default)(this.options,this.encodings[t].options);this.drawCanvasBarcode(e,this.encodings[t]),this.drawCanvasText(e,this.encodings[t]),this.moveCanvasDrawing(this.encodings[t])}this.restoreCanvas()}},{key:"prepareCanvas",value:function(){var t=this.canvas.getContext("2d");t.save(),(0,u.calculateEncodingAttributes)(this.encodings,this.options,t);var e=(0,u.getTotalWidthOfEncodings)(this.encodings),n=(0,u.getMaximumHeightOfEncodings)(this.encodings);this.canvas.width=e+this.options.marginLeft+this.options.marginRight,this.canvas.height=n,t.clearRect(0,0,this.canvas.width,this.canvas.height),this.options.background&&(t.fillStyle=this.options.background,t.fillRect(0,0,this.canvas.width,this.canvas.height)),t.translate(this.options.marginLeft,0)}},{key:"drawCanvasBarcode",value:function(t,e){var n,r=this.canvas.getContext("2d"),o=e.data;n="top"==t.textPosition?t.marginTop+t.fontSize+t.textMargin:t.marginTop,r.fillStyle=t.lineColor;for(var i=0;i<o.length;i++){var a=i*t.width+e.barcodePadding;"1"===o[i]?r.fillRect(a,n,t.width,t.height):o[i]&&r.fillRect(a,n,t.width,t.height*o[i])}}},{key:"drawCanvasText",value:function(t,e){var n,r,o=this.canvas.getContext("2d"),i=t.fontOptions+" "+t.fontSize+"px "+t.font;t.displayValue&&(r="top"==t.textPosition?t.marginTop+t.fontSize-t.textMargin:t.height+t.textMargin+t.marginTop+t.fontSize,o.font=i,"left"==t.textAlign||e.barcodePadding>0?(n=0,o.textAlign="left"):"right"==t.textAlign?(n=e.width-1,o.textAlign="right"):(n=e.width/2,o.textAlign="center"),o.fillText(e.text,n,r))}},{key:"moveCanvasDrawing",value:function(t){this.canvas.getContext("2d").translate(t.width,0)}},{key:"restoreCanvas",value:function(){this.canvas.getContext("2d").restore()}}]),t}();e.default=f},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0});var r,o=function(){function t(t,e){for(var n=0;n<e.length;n++){var r=e[n];r.enumerable=r.enumerable||!1,r.configurable=!0,"value"in r&&(r.writable=!0),Object.defineProperty(t,r.key,r)}}return function(e,n,r){return n&&t(e.prototype,n),r&&t(e,r),e}}(),i=n(7),a=(r=i)&&r.__esModule?r:{default:r},u=n(14);var f="http://www.w3.org/2000/svg",c=function(){function t(e,n,r){!function(t,e){if(!(t instanceof e))throw new TypeError("Cannot call a class as a function")}(this,t),this.svg=e,this.encodings=n,this.options=r,this.document=r.xmlDocument||document}return o(t,[{key:"render",value:function(){var t=this.options.marginLeft;this.prepareSVG();for(var e=0;e<this.encodings.length;e++){var n=this.encodings[e],r=(0,a.default)(this.options,n.options),o=this.createGroup(t,r.marginTop,this.svg);this.setGroupOptions(o,r),this.drawSvgBarcode(o,r,n),this.drawSVGText(o,r,n),t+=n.width}}},{key:"prepareSVG",value:function(){for(;this.svg.firstChild;)this.svg.removeChild(this.svg.firstChild);(0,u.calculateEncodingAttributes)(this.encodings,this.options);var t=(0,u.getTotalWidthOfEncodings)(this.encodings),e=(0,u.getMaximumHeightOfEncodings)(this.encodings),n=t+this.options.marginLeft+this.options.marginRight;this.setSvgAttributes(n,e),this.options.background&&this.drawRect(0,0,n,e,this.svg).setAttribute("fill",this.options.background)}},{key:"drawSvgBarcode",value:function(t,e,n){var r,o=n.data;r="top"==e.textPosition?e.fontSize+e.textMargin:0;for(var i=0,a=0,u=0;u<o.length;u++)a=u*e.width+n.barcodePadding,"1"===o[u]?i++:i>0&&(this.drawRect(a-e.width*i,r,e.width*i,e.height,t),i=0);i>0&&this.drawRect(a-e.width*(i-1),r,e.width*i,e.height,t)}},{key:"drawSVGText",value:function(t,e,n){var r,o,i=this.document.createElementNS(f,"text");e.displayValue&&(i.setAttribute("font-family",e.font),i.setAttribute("font-size",e.fontSize),e.fontOptions.includes("bold")&&i.setAttribute("font-weight","bold"),e.fontOptions.includes("italic")&&i.setAttribute("font-style","italic"),o="top"==e.textPosition?e.fontSize-e.textMargin:e.height+e.textMargin+e.fontSize,"left"==e.textAlign||n.barcodePadding>0?(r=0,i.setAttribute("text-anchor","start")):"right"==e.textAlign?(r=n.width-1,i.setAttribute("text-anchor","end")):(r=n.width/2,i.setAttribute("text-anchor","middle")),i.setAttribute("x",r),i.setAttribute("y",o),i.appendChild(this.document.createTextNode(n.text)),t.appendChild(i))}},{key:"setSvgAttributes",value:function(t,e){var n=this.svg;n.setAttribute("width",t+"px"),n.setAttribute("height",e+"px"),n.setAttribute("x","0px"),n.setAttribute("y","0px"),n.setAttribute("viewBox","0 0 "+t+" "+e),n.setAttribute("xmlns",f),n.setAttribute("version","1.1")}},{key:"createGroup",value:function(t,e,n){var r=this.document.createElementNS(f,"g");return r.setAttribute("transform","translate("+t+", "+e+")"),n.appendChild(r),r}},{key:"setGroupOptions",value:function(t,e){t.setAttribute("fill",e.lineColor)}},{key:"drawRect",value:function(t,e,n,r,o){var i=this.document.createElementNS(f,"rect");return i.setAttribute("x",t),i.setAttribute("y",e),i.setAttribute("width",n),i.setAttribute("height",r),o.appendChild(i),i}}]),t}();e.default=c},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0});var r=function(){function t(t,e){for(var n=0;n<e.length;n++){var r=e[n];r.enumerable=r.enumerable||!1,r.configurable=!0,"value"in r&&(r.writable=!0),Object.defineProperty(t,r.key,r)}}return function(e,n,r){return n&&t(e.prototype,n),r&&t(e,r),e}}();var o=function(){function t(e,n,r){!function(t,e){if(!(t instanceof e))throw new TypeError("Cannot call a class as a function")}(this,t),this.object=e,this.encodings=n,this.options=r}return r(t,[{key:"render",value:function(){this.object.encodings=this.encodings}}]),t}();e.default=o},function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0});var r=function(){function t(t,e){for(var n=0;n<e.length;n++){var r=e[n];r.enumerable=r.enumerable||!1,r.configurable=!0,"value"in r&&(r.writable=!0),Object.defineProperty(t,r.key,r)}}return function(e,n,r){return n&&t(e.prototype,n),r&&t(e,r),e}}();var o=function(){function t(e){!function(t,e){if(!(t instanceof e))throw new TypeError("Cannot call a class as a function")}(this,t),this.api=e}return r(t,[{key:"handleCatch",value:function(t){if("InvalidInputException"!==t.name)throw t;if(this.api._options.valid===this.api._defaults.valid)throw t.message;this.api._options.valid(!1),this.api.render=function(){}}},{key:"wrapBarcodeCall",value:function(t){try{var e=t.apply(void 0,arguments);return this.api._options.valid(!0),e}catch(t){return this.handleCatch(t),this.api}}}]),t}();e.default=o}]);
</script>
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
            color: #6b0213;
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

        /* ══ TABS ══ */
        .tab-nav {
            display: flex;
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 2rem;
            gap: 0;
        }

        .tab-btn {
            display: flex;
            align-items: center;
            gap: .45rem;
            padding: .75rem 1.4rem;
            font-size: .82rem;
            font-weight: 700;
            color: #94a3b8;
            background: transparent;
            border: none;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            cursor: pointer;
            transition: color .15s, border-color .15s;
            letter-spacing: .01em;
            white-space: nowrap;
        }

        .tab-btn:hover:not(.active) {
            color: #475569;
            border-bottom-color: #cbd5e1;
        }

        .tab-btn.active {
            color: #9a031e;
            border-bottom-color: #9a031e;
        }

        .tab-btn .tab-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 1.4rem;
            height: 1.4rem;
            padding: 0 .35rem;
            border-radius: 9999px;
            font-size: .65rem;
            font-weight: 800;
            background: #f1f5f9;
            color: #64748b;
            transition: background .15s, color .15s;
        }

        .tab-btn.active .tab-count {
            background: #fce7ea;
            color: #9a031e;
        }

        /* ══ HISTORY BADGES ══ */
        .hbadge-plus {
            background: #d1fae5;
            color: #6b0213;
            border-color: #6ee7b7;
        }

        .hbadge-minus {
            background: #fee2e2;
            color: #b91c1c;
            border-color: #fca5a5;
        }
    </style>
</head>

<body class="bg-slate-50 min-h-screen text-slate-900">
    <div class="max-w-[1500px] mx-auto p-6 lg:p-10">

        <!-- HEADER -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-6 mb-8">
            <div>
                <a href="index.php" class="text-[#9a031e] font-bold text-sm flex items-center gap-2 mb-2 hover:gap-3 transition-all">
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
                    class="bg-[#9a031e] hover:bg-[#7a0318] text-white px-5 py-3 rounded-2xl font-bold shadow-lg shadow-[#fce7ea] transition-all flex items-center gap-2 text-sm">
                    <i class="fas fa-file-excel"></i> Import Excel
                </button>
                <button onclick="openModal('addModal')"
                    class="bg-[#9a031e] hover:bg-[#7a0318] text-white px-5 py-3 rounded-2xl font-bold shadow-lg shadow-[#fce7ea] transition-all flex items-center gap-2 text-sm">
                    <i class="fas fa-plus"></i> Tambah Part
                </button>
                <div class="flex items-center gap-2 bg-slate-100 px-4 py-2 rounded-xl">
                    <div class="w-7 h-7 rounded-full bg-[#9a031e] flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-user text-white text-xs"></i>
                    </div>
                    <span class="text-sm font-bold text-slate-700"><?= htmlspecialchars($displayName) ?></span>
                </div>
                <a href="logout_user.php" onclick="return confirm('Apakah Anda yakin ingin keluar?')"
                    class="bg-red-100 hover:bg-red-200 text-red-600 px-5 py-3 rounded-2xl font-bold transition-all flex items-center gap-2 text-sm">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>

        <!-- TAB NAVIGATION -->
        <div class="tab-nav">
            <button class="tab-btn main-tab-btn <?= $activeTab === 'inventory' ? 'active' : '' ?>" onclick="switchTab('inventory')">
                <i class="fas fa-boxes" style="font-size:.8rem;"></i>
                Inventory
                <span class="tab-count"><?= $totalParts ?></span>
            </button>
            <button class="tab-btn main-tab-btn <?= $activeTab === 'transactions' ? 'active' : '' ?>" onclick="switchTab('transactions')">
                <i class="fas fa-right-left" style="font-size:.8rem;"></i>
                Transactions
                <span class="tab-count"><?= count($historyRows) ?></span>
            </button>
        </div>

        <!-- ══════════════ TAB: INVENTORY ══════════════ -->
        <div id="tab-inventory" class="<?= $activeTab !== 'inventory' ? 'hidden' : '' ?>">

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
                <div class="stat-card bg-[#fff0f2] p-5 rounded-3xl border border-[#fce7ea] shadow-sm" onclick="openCategoryModal('In Stock')">
                    <p class="text-[#9a031e] text-[10px] font-bold uppercase tracking-widest mb-1 flex items-center">In Stock<i class="fas fa-chevron-right text-[8px] ml-auto opacity-50"></i></p>
                    <p class="text-3xl font-black text-[#9a031e]"><?= $inStock ?></p>
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
                        <thead class="bg-slate-800 text-white" style="background:linear-gradient(135deg,#9a031e,#b5152a);position:sticky;top:0;z-index:10;">
                            <tr>
                                <th class="px-6 py-4 text-[11px] font-semibold uppercase tracking-widest">No</th>
                                <th class="px-6 py-4 text-[11px] font-semibold uppercase tracking-widest">Item Code</th>
                                <th class="px-6 py-4 text-[11px] font-semibold uppercase tracking-widest">Barcode</th>
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
                                    <td colspan="9" class="px-6 py-20 text-center text-slate-400">
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
                                        <td class="px-6 py-4">
                                            <svg class="barcode" data-code="<?= htmlspecialchars($part['item_code']) ?>"></svg>
                                        </td>
                                        <td class="px-6 py-4 text-slate-700 text-sm font-medium max-w-xs"><?= htmlspecialchars($part['item_description'] ?? '-') ?></td>
                                        <td class="px-6 py-4 text-center">
                                            <span class="inline-block bg-slate-100 text-slate-600 font-bold px-3 py-1 rounded-lg text-sm"><?= $safety ?></span>
                                        </td>
                                        <td class="px-6 py-4 text-center font-black text-lg
                            <?= $actual === 0 ? 'text-red-500' : ($actual < $safety ? 'text-orange-500' : ($actual === $safety ? 'text-[#9a031e]' : 'text-violet-600')) ?>">
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
                                                    class="bg-[#c91f38] hover:bg-[#b5152a] text-white px-3 py-2 rounded-xl font-bold text-xs transition flex items-center gap-1.5">
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

        </div><!-- /tab-inventory -->

        <!-- ══════════════ TAB: TRANSACTIONS ══════════════ -->
        <div id="tab-transactions" class="<?= $activeTab !== 'transactions' ? 'hidden' : '' ?>">

            <!-- SUB-TAB NAVIGATION -->
            <div class="tab-nav" style="margin-bottom:1.5rem;">
                <button class="tab-btn sub-tab-btn <?= $activeSubtab === 'in' ? 'active' : '' ?>" data-subtab="in" onclick="switchSubTab('in')">
                    <i class="fas fa-arrow-down" style="font-size:.8rem;"></i>
                    IN
                    <span class="tab-count"><?= count($inRows) ?></span>
                </button>
                <button class="tab-btn sub-tab-btn <?= $activeSubtab === 'out' ? 'active' : '' ?>" data-subtab="out" onclick="switchSubTab('out')">
                    <i class="fas fa-arrow-up" style="font-size:.8rem;"></i>
                    OUT
                    <span class="tab-count"><?= count($outRows) ?></span>
                </button>
                <button class="tab-btn sub-tab-btn <?= $activeSubtab === 'history' ? 'active' : '' ?>" data-subtab="history" onclick="switchSubTab('history')">
                    <i class="fas fa-clock-rotate-left" style="font-size:.8rem;"></i>
                    History
                    <span class="tab-count"><?= count($historyRows) ?></span>
                </button>
            </div>

            <!-- ── SUB-TAB: IN ── -->
            <div id="subtab-in" class="subtab-panel <?= $activeSubtab !== 'in' ? 'hidden' : '' ?>">
                <div class="flex flex-col sm:flex-row gap-3 mb-5">
                    <div class="relative flex-1">
                        <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                        <input type="text" id="inSearchInput" placeholder="Cari kode atau deskripsi part..."
                            oninput="filterTxTable('in')"
                            class="pl-11 pr-4 py-3 bg-white border border-slate-200 rounded-2xl w-full focus:ring-4 focus:ring-emerald-100 outline-none transition shadow-sm text-sm">
                    </div>
                </div>
                <div class="bg-white rounded-[2rem] shadow-xl border border-slate-200 overflow-hidden">
                    <div style="max-height:560px; overflow-y:auto; overflow-x:auto;">
                        <table class="w-full text-left border-collapse" id="inTable">
                            <thead style="background:linear-gradient(135deg,#059669,#10b981);position:sticky;top:0;z-index:10;">
                                <tr>
                                    <th class="px-5 py-4 text-[11px] font-semibold uppercase tracking-widest text-white">No</th>
                                    <th class="px-5 py-4 text-[11px] font-semibold uppercase tracking-widest text-white">Waktu</th>
                                    <th class="px-5 py-4 text-[11px] font-semibold uppercase tracking-widest text-white">Item Code</th>
                                    <th class="px-5 py-4 text-[11px] font-semibold uppercase tracking-widest text-white">Deskripsi</th>
                                    <th class="px-5 py-4 text-[11px] font-semibold uppercase tracking-widest text-white text-center">Stok Lama</th>
                                    <th class="px-5 py-4 text-[11px] font-semibold uppercase tracking-widest text-white text-center">Jumlah Masuk</th>
                                    <th class="px-5 py-4 text-[11px] font-semibold uppercase tracking-widest text-white text-center">Stok Baru</th>
                                    <th class="px-5 py-4 text-[11px] font-semibold uppercase tracking-widest text-white">Dilaporkan Oleh</th>
                                </tr>
                            </thead>
                            <tbody id="inBody" class="divide-y divide-slate-100">
                                <?php renderPartHistoryRows($inRows, 'Belum ada transaksi stok masuk (IN).'); ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (!empty($inRows)): ?>
                        <div class="px-6 py-3 bg-slate-50 border-t border-slate-100 flex items-center justify-between text-xs text-slate-400">
                            <span id="inCountLabel">Menampilkan <?= count($inRows) ?> record</span>
                            <span>Max 200 record terbaru</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div><!-- /subtab-in -->

            <!-- ── SUB-TAB: OUT ── -->
            <div id="subtab-out" class="subtab-panel <?= $activeSubtab !== 'out' ? 'hidden' : '' ?>">
                <div class="flex flex-col sm:flex-row gap-3 mb-5">
                    <div class="relative flex-1">
                        <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                        <input type="text" id="outSearchInput" placeholder="Cari kode atau deskripsi part..."
                            oninput="filterTxTable('out')"
                            class="pl-11 pr-4 py-3 bg-white border border-slate-200 rounded-2xl w-full focus:ring-4 focus:ring-emerald-100 outline-none transition shadow-sm text-sm">
                    </div>
                </div>
                <div class="bg-white rounded-[2rem] shadow-xl border border-slate-200 overflow-hidden">
                    <div style="max-height:560px; overflow-y:auto; overflow-x:auto;">
                        <table class="w-full text-left border-collapse" id="outTable">
                            <thead style="background:linear-gradient(135deg,#dc2626,#ef4444);position:sticky;top:0;z-index:10;">
                                <tr>
                                    <th class="px-5 py-4 text-[11px] font-semibold uppercase tracking-widest text-white">No</th>
                                    <th class="px-5 py-4 text-[11px] font-semibold uppercase tracking-widest text-white">Waktu</th>
                                    <th class="px-5 py-4 text-[11px] font-semibold uppercase tracking-widest text-white">Item Code</th>
                                    <th class="px-5 py-4 text-[11px] font-semibold uppercase tracking-widest text-white">Deskripsi</th>
                                    <th class="px-5 py-4 text-[11px] font-semibold uppercase tracking-widest text-white text-center">Stok Lama</th>
                                    <th class="px-5 py-4 text-[11px] font-semibold uppercase tracking-widest text-white text-center">Jumlah Keluar</th>
                                    <th class="px-5 py-4 text-[11px] font-semibold uppercase tracking-widest text-white text-center">Stok Baru</th>
                                    <th class="px-5 py-4 text-[11px] font-semibold uppercase tracking-widest text-white">Dilaporkan Oleh</th>
                                </tr>
                            </thead>
                            <tbody id="outBody" class="divide-y divide-slate-100">
                                <?php renderPartHistoryRows($outRows, 'Belum ada transaksi stok keluar (OUT).'); ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (!empty($outRows)): ?>
                        <div class="px-6 py-3 bg-slate-50 border-t border-slate-100 flex items-center justify-between text-xs text-slate-400">
                            <span id="outCountLabel">Menampilkan <?= count($outRows) ?> record</span>
                            <span>Max 200 record terbaru</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div><!-- /subtab-out -->

            <!-- ── SUB-TAB: HISTORY ── -->
            <div id="subtab-history" class="subtab-panel <?= $activeSubtab !== 'history' ? 'hidden' : '' ?>">
                <div class="flex flex-col sm:flex-row gap-3 mb-5">
                    <div class="relative flex-1">
                        <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                        <input type="text" id="histSearchInput" placeholder="Cari kode atau deskripsi part..."
                            oninput="filterHistory()"
                            class="pl-11 pr-4 py-3 bg-white border border-slate-200 rounded-2xl w-full focus:ring-4 focus:ring-emerald-100 outline-none transition shadow-sm text-sm">
                    </div>
                    <select id="histFilterType" onchange="filterHistory()"
                        class="bg-white border border-slate-200 rounded-2xl px-4 py-3 text-sm font-semibold text-slate-600 focus:ring-4 focus:ring-emerald-100 outline-none shadow-sm">
                        <option value="">Semua Perubahan</option>
                        <option value="plus">Penambahan (+)</option>
                        <option value="minus">Pengurangan (−)</option>
                    </select>
                </div>

                <div class="bg-white rounded-[2rem] shadow-xl border border-slate-200 overflow-hidden">
                    <div style="max-height:560px; overflow-y:auto; overflow-x:auto;">
                        <table class="w-full text-left border-collapse" id="historyTable">
                            <thead style="background:linear-gradient(135deg,#9a031e,#b5152a);position:sticky;top:0;z-index:10;">
                                <tr>
                                    <th class="px-5 py-4 text-[11px] font-semibold uppercase tracking-widest text-white">No</th>
                                    <th class="px-5 py-4 text-[11px] font-semibold uppercase tracking-widest text-white">Waktu</th>
                                    <th class="px-5 py-4 text-[11px] font-semibold uppercase tracking-widest text-white">Item Code</th>
                                    <th class="px-5 py-4 text-[11px] font-semibold uppercase tracking-widest text-white">Deskripsi</th>
                                    <th class="px-5 py-4 text-[11px] font-semibold uppercase tracking-widest text-white text-center">Stok Lama</th>
                                    <th class="px-5 py-4 text-[11px] font-semibold uppercase tracking-widest text-white text-center">Perubahan</th>
                                    <th class="px-5 py-4 text-[11px] font-semibold uppercase tracking-widest text-white text-center">Stok Baru</th>
                                    <th class="px-5 py-4 text-[11px] font-semibold uppercase tracking-widest text-white">Dilaporkan Oleh</th>
                                </tr>
                            </thead>
                            <tbody id="historyBody" class="divide-y divide-slate-100">
                                <?php renderPartHistoryRows($historyRows, 'Belum ada history perubahan stok.'); ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (!empty($historyRows)): ?>
                        <div class="px-6 py-3 bg-slate-50 border-t border-slate-100 flex items-center justify-between text-xs text-slate-400">
                            <span id="histCountLabel">Menampilkan <?= count($historyRows) ?> record</span>
                            <span>Max 200 record terbaru</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div><!-- /subtab-history -->

        </div><!-- /tab-transactions -->

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
            <div class="bg-gradient-to-r from-[#9a031e] to-[#7a0318] px-8 py-5 flex justify-between items-center">
                <div>
                    <h3 class="text-lg font-bold text-white"><i class="fas fa-plus-circle mr-2"></i>Tambah Part Baru</h3>
                    <p class="text-[#f9c4cc] text-xs mt-0.5">Isi semua kolom yang diperlukan</p>
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
            <div class="bg-gradient-to-r from-[#b5152a] to-[#b5152a] px-6 py-4 flex justify-between items-center">
                <div>
                    <h3 class="text-base font-bold text-white"><i class="fas fa-pencil mr-2"></i>Edit Actual Stock</h3>
                    <p class="text-[#fce7ea] text-xs mt-0.5">Hanya actual stock yang dapat diubah</p>
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
                    <button onclick="submitEditStock()" class="bg-[#b5152a] hover:bg-[#9a031e] text-white px-7 py-2.5 rounded-xl font-black shadow-lg transition text-sm">
                        <i class="fas fa-check mr-1"></i> Update
                    </button>
                </div>
            </div>
        </div>
    </div>


    <!-- MODAL: IMPORT EXCEL -->
    <div id="importModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 items-center justify-center p-4" style="display:none;">
        <div class="bg-white w-full max-w-lg rounded-3xl shadow-2xl overflow-hidden modal-enter">
            <div class="bg-gradient-to-r from-[#9a031e] to-[#7a1020] px-8 py-5 flex justify-between items-center">
                <div>
                    <h3 class="text-lg font-bold text-white"><i class="fas fa-file-excel mr-2"></i>Import Parts dari Excel</h3>
                    <p class="text-[#fce7ea] text-xs mt-0.5">Data akan di-upsert (update jika sudah ada)</p>
                </div>
                <button onclick="closeModal('importModal')" class="text-[#fce7ea] hover:text-white transition w-8 h-8 flex items-center justify-center rounded-full hover:bg-white/10"><i class="fas fa-times"></i></button>
            </div>
            <div class="p-8">
                <div class="bg-slate-50 rounded-2xl p-4 mb-5 border border-slate-100">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3">Format Kolom yang Dikenali</p>
                    <div class="grid grid-cols-2 gap-2">
                        <?php foreach ([['ITEM CODE', 'wajib'], ['ITEM DESCRIPTION', 'opsional'], ['SAFETY STOCK', 'angka'], ['QTY ACTUAL / ACTUAL STOCK', 'angka']] as [$col, $note]): ?>
                            <div class="flex items-center gap-2">
                                <span class="font-mono text-[10px] bg-[#fce7ea] text-[#9a031e] px-2 py-1 rounded font-bold"><?= $col ?></span>
                                <span class="text-[10px] text-slate-400"><?= $note ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div id="dropZone"
                    class="border-2 border-dashed border-slate-200 rounded-2xl p-8 text-center cursor-pointer hover:border-[#c91f38] transition-all mb-5"
                    onclick="document.getElementById('partsFile').click()"
                    ondragover="event.preventDefault();this.classList.add('border-[#c91f38]','bg-[#fff0f2]')"
                    ondragleave="this.classList.remove('border-[#c91f38]','bg-[#fff0f2]')"
                    ondrop="handleDrop(event)">
                    <i class="fas fa-cloud-upload-alt text-4xl text-slate-200 mb-3 block"></i>
                    <p class="font-bold text-slate-500 text-sm">Klik atau drag & drop file di sini</p>
                    <p class="text-slate-300 text-xs mt-1">.xlsx atau .xls — maks. 10 MB</p>
                    <div id="fileNameBadge" class="hidden mt-3">
                        <span class="inline-flex items-center gap-2 bg-[#fce7ea] text-[#9a031e] font-bold px-4 py-1.5 rounded-full text-sm" id="fileNameLabel"></span>
                    </div>
                </div>
                <input type="file" id="partsFile" accept=".xlsx,.xls" class="hidden" onchange="handleFileSelect(event)">
                <div id="importLoading" class="hidden rounded-xl overflow-hidden bg-slate-100 h-2 mb-4">
                    <div class="anim-bar bg-[#b5152a] h-2 w-1/3 rounded-full"></div>
                </div>
                <div id="importAlert" class="hidden rounded-xl p-3 mb-4 text-sm font-medium border"></div>
                <div class="flex justify-end gap-3">
                    <button onclick="closeModal('importModal')" class="px-6 py-3 font-bold text-slate-400 hover:bg-slate-100 rounded-xl transition text-sm">Batal</button>
                    <button id="btnImport" onclick="startImport()" disabled
                        class="bg-[#9a031e] text-white px-8 py-3 rounded-xl font-black shadow-lg transition text-sm opacity-50 cursor-not-allowed flex items-center gap-2">
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
            'In Stock': 'linear-gradient(135deg,#b5152a,#7a0318)',
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
            document.getElementById('dropZone').classList.remove('border-[#c91f38]', 'bg-[#fff0f2]');
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

        // ── Tab Switching (main) ──
        function switchTab(tab) {
            ['inventory', 'transactions'].forEach(t => {
                document.getElementById('tab-' + t).classList.toggle('hidden', t !== tab);
            });
            document.querySelectorAll('.main-tab-btn').forEach((btn, i) => {
                const tabs = ['inventory', 'transactions'];
                btn.classList.toggle('active', tabs[i] === tab);
            });
            const url = new URL(window.location);
            url.searchParams.set('tab', tab);
            history.replaceState(null, '', url);
        }

        // ── Sub-Tab Switching (IN / OUT / History) ──
        function switchSubTab(subtab) {
            ['in', 'out', 'history'].forEach(t => {
                document.getElementById('subtab-' + t).classList.toggle('hidden', t !== subtab);
            });
            document.querySelectorAll('.sub-tab-btn').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.subtab === subtab);
            });
            const url = new URL(window.location);
            url.searchParams.set('subtab', subtab);
            history.replaceState(null, '', url);
        }

        // ── Filter sederhana untuk sub-tab IN / OUT ──
        function filterTxTable(kind) {
            const inputId = kind === 'in' ? 'inSearchInput' : 'outSearchInput';
            const bodyId  = kind === 'in' ? 'inBody' : 'outBody';
            const lblId   = kind === 'in' ? 'inCountLabel' : 'outCountLabel';
            const q = document.getElementById(inputId).value.toLowerCase().trim();
            let shown = 0;
            document.querySelectorAll('#' + bodyId + ' tr[data-search]').forEach(row => {
                const vis = !q || row.dataset.search.includes(q);
                row.style.display = vis ? '' : 'none';
                if (vis) shown++;
            });
            const lbl = document.getElementById(lblId);
            if (lbl) lbl.textContent = 'Menampilkan ' + shown + ' record';
        }

        // ── Render Barcode dari Item Code ──
        function renderBarcodes(scope) {
            (scope || document).querySelectorAll('svg.barcode').forEach(el => {
                const code = el.dataset.code || '';
                if (!code) return;
                try {
                    JsBarcode(el, code, {
                        format: 'CODE128',
                        width: 1.3,
                        height: 32,
                        fontSize: 10,
                        margin: 4,
                        displayValue: true
                    });
                } catch (e) {
                    el.outerHTML = '<span class="text-[10px] text-slate-300">—</span>';
                }
            });
        }
        document.addEventListener('DOMContentLoaded', () => renderBarcodes());

        // ── History Filter ──
        function filterHistory() {
            const q = document.getElementById('histSearchInput').value.toLowerCase().trim();
            const typ = document.getElementById('histFilterType').value;
            let shown = 0;
            document.querySelectorAll('#historyBody tr[data-search]').forEach(row => {
                const matchQ = !q || row.dataset.search.includes(q);
                const matchTyp = !typ || row.dataset.type === typ;
                const vis = matchQ && matchTyp;
                row.style.display = vis ? '' : 'none';
                if (vis) shown++;
            });
            const lbl = document.getElementById('histCountLabel');
            if (lbl) lbl.textContent = 'Menampilkan ' + shown + ' record';
        }
    </script>
</body>

</html>