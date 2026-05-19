<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login_admin.php');
    exit;
}

include 'config.php';

if (!function_exists('formatDate') || !function_exists('calculateRemainingDays')) {
    die("Error: Helper functions tidak ditemukan di config.php");
}

// ==================== API ENDPOINTS (AJAX) ====================

if (isset($_GET['get_schedule'])) {
    $stmt = $pdo->prepare("SELECT * FROM schedules WHERE id = ?");
    $stmt->execute([$_GET['get_schedule']]);
    echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
    exit;
}

if (isset($_GET['get_part'])) {
    $stmt = $pdo->prepare("SELECT * FROM expenses_part WHERE id = ?");
    $stmt->execute([$_GET['get_part']]);
    echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
    exit;
}

if (isset($_GET['get_user'])) {
    $stmt = $pdo->prepare("SELECT id, username, email, role, is_active FROM users WHERE id = ?");
    $stmt->execute([$_GET['get_user']]);
    echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
    exit;
}

// ==================== LOGIKA POST ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        // --- SCHEDULE: ADD ---
        if ($_POST['action'] === 'add_schedule') {
            $change_date_plan = $_POST['change_date_plan'] ?? null;
            $remaining_day = calculateRemainingDays($change_date_plan);
            $stmt = $pdo->prepare("INSERT INTO schedules
                (department, line, operation_process, machine_name, process_machine, name_unit,
                 maintenance_point, interval_month, use_date, change_date_plan,
                 reminder_activity, reminder_request_part, remaining_day)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([
                $_POST['dept_name'] ?? '',
                $_POST['line_name'] ?? '',
                $_POST['operation_process'] ?? '',
                $_POST['machine_name'] ?? '',
                $_POST['process_machine'] ?? '',
                $_POST['name_unit'] ?? '',
                $_POST['maintenance_point'] ?? '',
                (int)($_POST['interval_month'] ?? 0),
                $_POST['use_date'] ?? null,
                $change_date_plan,
                (int)($_POST['reminder_activity'] ?? 0),
                (int)($_POST['reminder_request_part'] ?? 0),
                $remaining_day,
            ]);
            echo json_encode(['status' => 'success', 'message' => 'Schedule berhasil ditambahkan']);
            exit;
        }

        // --- SCHEDULE: EDIT ---
        if ($_POST['action'] === 'edit_schedule') {
            $change_date_plan = $_POST['change_date_plan'] ?? null;
            $remaining_day = calculateRemainingDays($change_date_plan);
            $stmt = $pdo->prepare("UPDATE schedules SET
                department=?, line=?, operation_process=?, machine_name=?,
                process_machine=?, name_unit=?, maintenance_point=?,
                interval_month=?, use_date=?, change_date_plan=?,
                reminder_activity=?, reminder_request_part=?, remaining_day=?
                WHERE id=?");
            $stmt->execute([
                $_POST['dept_name'] ?? '',
                $_POST['line_name'] ?? '',
                $_POST['operation_process'] ?? '',
                $_POST['machine_name'] ?? '',
                $_POST['process_machine'] ?? '',
                $_POST['name_unit'] ?? '',
                $_POST['maintenance_point'] ?? '',
                (int)($_POST['interval_month'] ?? 0),
                $_POST['use_date'] ?? null,
                $change_date_plan,
                (int)($_POST['reminder_activity'] ?? 0),
                (int)($_POST['reminder_request_part'] ?? 0),
                $remaining_day,
                (int)($_POST['edit_id'] ?? 0)
            ]);
            echo json_encode(['status' => 'success', 'message' => 'Schedule berhasil diupdate']);
            exit;
        }

        // --- SCHEDULE: DELETE ---
        if ($_POST['action'] === 'delete_schedule') {
            $stmt = $pdo->prepare("DELETE FROM schedules WHERE id=?");
            $stmt->execute([(int)$_POST['id']]);
            echo json_encode(['status' => 'success', 'message' => 'Schedule berhasil dihapus']);
            exit;
        }

        // --- SCHEDULE PREVENTIVE: DELETE ---
        if ($_POST['action'] === 'delete_prev_schedule') {
            $stmt = $pdo->prepare("DELETE FROM schedules_preventive WHERE id=?");
            $stmt->execute([(int)$_POST['id']]);
            echo json_encode(['status' => 'success', 'message' => 'Schedule preventive berhasil dihapus']);
            exit;
        }

        // --- PART: ADD ---
        if ($_POST['action'] === 'add_part') {
            $itemCode  = trim($_POST['item_code'] ?? '');
            $itemDesc  = trim($_POST['item_description'] ?? '');
            $safety    = (int)($_POST['safety_stock'] ?? 0);
            $actual    = (int)($_POST['actual_stock'] ?? 0);
            if (!$itemCode) {
                echo json_encode(['status' => 'error', 'message' => 'Item Code wajib diisi']);
                exit;
            }
            $effective = $actual - $safety;
            $status    = ($actual === 0) ? 'Zero Stock' : (($actual < $safety) ? 'Low Stock' : (($actual === $safety) ? 'In Stock' : 'Over Stock'));
            $stmt = $pdo->prepare("INSERT INTO expenses_part (item_code, item_description, safety_stock, actual_stock, effective_stock, status) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE item_description=VALUES(item_description),safety_stock=VALUES(safety_stock),actual_stock=VALUES(actual_stock),effective_stock=VALUES(effective_stock),status=VALUES(status)");
            $stmt->execute([$itemCode, $itemDesc, $safety, $actual, $effective, $status]);
            echo json_encode(['status' => 'success', 'message' => 'Part berhasil ditambahkan']);
            exit;
        }

        // --- PART: EDIT ---
        if ($_POST['action'] === 'edit_part') {
            $partId   = (int)($_POST['edit_id'] ?? 0);
            $itemCode = trim($_POST['item_code'] ?? '');
            $itemDesc = trim($_POST['item_description'] ?? '');
            $safety   = (int)($_POST['safety_stock'] ?? 0);
            $actual   = (int)($_POST['actual_stock'] ?? 0);
            $effective = $actual - $safety;
            $status    = ($actual === 0) ? 'Zero Stock' : (($actual < $safety) ? 'Low Stock' : (($actual === $safety) ? 'In Stock' : 'Over Stock'));
            $stmt = $pdo->prepare("UPDATE expenses_part SET item_code=?, item_description=?, safety_stock=?, actual_stock=?, effective_stock=?, status=? WHERE id=?");
            $stmt->execute([$itemCode, $itemDesc, $safety, $actual, $effective, $status, $partId]);
            echo json_encode(['status' => 'success', 'message' => 'Part berhasil diupdate']);
            exit;
        }

        // --- PART: DELETE ---
        if ($_POST['action'] === 'delete_part') {
            $stmt = $pdo->prepare("DELETE FROM expenses_part WHERE id=?");
            $stmt->execute([(int)$_POST['id']]);
            echo json_encode(['status' => 'success', 'message' => 'Part berhasil dihapus']);
            exit;
        }

        // --- HISTORY: DELETE (predictive) ---
        if ($_POST['action'] === 'delete_history') {
            $stmt = $pdo->prepare("DELETE FROM history_maintenance WHERE id=?");
            $stmt->execute([(int)$_POST['id']]);
            echo json_encode(['status' => 'success', 'message' => 'History berhasil dihapus']);
            exit;
        }

        // --- HISTORY PREVENTIVE: DELETE ---
        if ($_POST['action'] === 'delete_history_prev') {
            $stmt = $pdo->prepare("DELETE FROM history_preventive WHERE id=?");
            $stmt->execute([(int)$_POST['id']]);
            echo json_encode(['status' => 'success', 'message' => 'History preventive berhasil dihapus']);
            exit;
        }

        // --- DELETE ALL: SCHEDULE (predictive) ---
        if ($_POST['action'] === 'delete_all_schedule') {
            $pdo->exec("DELETE FROM schedules");
            echo json_encode(['status' => 'success', 'message' => 'Semua schedule predictive berhasil dihapus']);
            exit;
        }

        // --- DELETE ALL: SCHEDULE (preventive) ---
        if ($_POST['action'] === 'delete_all_prev_schedule') {
            $pdo->exec("DELETE FROM schedules_preventive");
            echo json_encode(['status' => 'success', 'message' => 'Semua schedule preventive berhasil dihapus']);
            exit;
        }

        // --- DELETE ALL: PARTS ---
        if ($_POST['action'] === 'delete_all_parts') {
            $pdo->exec("DELETE FROM expenses_part");
            echo json_encode(['status' => 'success', 'message' => 'Semua data part berhasil dihapus']);
            exit;
        }

        // --- DELETE ALL: HISTORY (predictive) ---
        if ($_POST['action'] === 'delete_all_history') {
            $pdo->exec("DELETE FROM history_maintenance");
            echo json_encode(['status' => 'success', 'message' => 'Semua history predictive berhasil dihapus']);
            exit;
        }

        // --- DELETE ALL: HISTORY (preventive) ---
        if ($_POST['action'] === 'delete_all_history_prev') {
            $pdo->exec("DELETE FROM history_preventive");
            echo json_encode(['status' => 'success', 'message' => 'Semua history preventive berhasil dihapus']);
            exit;
        }

        // --- USER: ADD ---
        if ($_POST['action'] === 'add_user') {
            $hashedPass = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, email_user, password, role, is_active) VALUES (?,?,?,?,1)");
            $stmt->execute([
                $_POST['username'] ?? '',
                $_POST['email'] ?? '',
                $hashedPass,
                $_POST['role'] ?? 'user',
            ]);
            echo json_encode(['status' => 'success', 'message' => 'User berhasil ditambahkan']);
            exit;
        }

        // --- USER: EDIT ---
        if ($_POST['action'] === 'edit_user') {
            if (!empty($_POST['password'])) {
                $hashedPass = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET username=?, email_user=?, password=?, role=? WHERE id=?");
                $stmt->execute([$_POST['username'], $_POST['email'], $hashedPass, $_POST['role'], (int)$_POST['edit_id']]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET username=?, email_user=?, role=? WHERE id=?");
                $stmt->execute([$_POST['username'], $_POST['email'], $_POST['role'], (int)$_POST['edit_id']]);
            }
            echo json_encode(['status' => 'success', 'message' => 'User berhasil diupdate']);
            exit;
        }

        // --- USER: TOGGLE ACTIVE ---
        if ($_POST['action'] === 'toggle_user') {
            $stmt = $pdo->prepare("UPDATE users SET is_active = NOT is_active WHERE id=?");
            $stmt->execute([(int)$_POST['id']]);
            echo json_encode(['status' => 'success', 'message' => 'Status user berhasil diubah']);
            exit;
        }

        // --- USER: DELETE ---
        if ($_POST['action'] === 'delete_user') {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id=?");
            $stmt->execute([(int)$_POST['id']]);
            echo json_encode(['status' => 'success', 'message' => 'User berhasil dihapus']);
            exit;
        }

        // --- NOTIFICATION: SAVE ---
        if ($_POST['action'] === 'save_notification') {
            $recipients = $_POST['recipients'] ?? [];
            // Hapus semua penerima lama, lalu insert ulang
            $pdo->exec("DELETE FROM notification_recipients");
            $stmt = $pdo->prepare("INSERT INTO notification_recipients (email, name) VALUES (?,?)");
            foreach ($recipients as $r) {
                if (!empty($r['email'])) {
                    $stmt->execute([$r['email'], $r['name'] ?? '']);
                }
            }
            echo json_encode(['status' => 'success', 'message' => 'Penerima notifikasi berhasil disimpan']);
            exit;
        }

        // --- RECIPIENT: ADD ---
        if ($_POST['action'] === 'add_recipient') {
            $name  = trim($_POST['rec_name'] ?? '');
            $email = trim($_POST['rec_email'] ?? '');
            if (!$email) {
                echo json_encode(['status' => 'error', 'message' => 'Email wajib diisi']);
                exit;
            }
            $stmt = $pdo->prepare("INSERT INTO notification_recipients (name, email) VALUES (?, ?)");
            $stmt->execute([$name, $email]);
            echo json_encode(['status' => 'success', 'message' => 'Penerima berhasil ditambahkan']);
            exit;
        }

        // --- RECIPIENT: DELETE ---
        if ($_POST['action'] === 'delete_recipient') {
            $stmt = $pdo->prepare("DELETE FROM notification_recipients WHERE id=?");
            $stmt->execute([(int)$_POST['id']]);
            echo json_encode(['status' => 'success', 'message' => 'Penerima berhasil dihapus']);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// ==================== FETCH DATA ====================
$schedules = $pdo->query("
    SELECT s.*, DATEDIFF(change_date_plan, CURDATE()) AS remaining_day,
           COALESCE(pl.plant_name, s.department) AS department,
           COALESCE(ln.line_name, s.line) AS line
    FROM schedules s
    LEFT JOIN plants pl ON pl.id = s.department
    LEFT JOIN line ln ON ln.id = s.line
    ORDER BY change_date_plan ASC
")->fetchAll(PDO::FETCH_ASSOC);

$prevSchedules = [];
try {
    $prevSchedules = $pdo->query("
        SELECT ps.*, DATEDIFF(ps.change_date_plan, CURDATE()) AS remaining_day,
               COALESCE(pl.plant_name, ps.department) AS department,
               COALESCE(ln.line_name, ps.line) AS line
        FROM schedules_preventive ps
        LEFT JOIN plants pl ON pl.id = ps.department
        LEFT JOIN line ln ON ln.id = ps.line
        ORDER BY ps.change_date_plan ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}

$parts = [];
try {
    $parts = $pdo->query("SELECT * FROM expenses_part ORDER BY item_code ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}

// Part stats
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

$histories = [];
try {
    $histories = $pdo->query("
        SELECT h.*, u.username AS technician_name
        FROM history_maintenance h
        LEFT JOIN users u ON u.id = h.reported_by
        ORDER BY h.reported_at DESC LIMIT 200
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}

$historiesPrev = [];
try {
    $historiesPrev = $pdo->query("
        SELECT h.*, u.username AS technician_name
        FROM history_preventive h
        LEFT JOIN users u ON u.id = h.reported_by
        ORDER BY h.reported_at DESC LIMIT 200
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}

$users = [];
try {
    $users = $pdo->query("SELECT id, username, email_user, role, is_active FROM users WHERE role = 'user' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Error fetching users: ' . $e->getMessage());
}

$notif_admins = [];
try {
    $notif_admins = $pdo->query("SELECT id, username AS name, email_user AS email FROM users WHERE role = 'admin' ORDER BY username ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Error fetching admin notif: ' . $e->getMessage());
}

$notif_recipients = [];
try {
    $notif_recipients = $pdo->query("SELECT id, name, email FROM notification_recipients ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Error fetching notif recipients: ' . $e->getMessage());
}

$plants = $pdo->query("SELECT id, plant_name FROM plants ORDER BY plant_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Stats
$total_schedules = count($schedules);
$overdue = count(array_filter($schedules, fn($r) => (int)$r['remaining_day'] <= 0));
$near_due = count(array_filter($schedules, fn($r) => (int)$r['remaining_day'] > 0 && (int)$r['remaining_day'] <= 7));
$total_users = count($users);

// Schedule stats by status
$schedByStatus = [
    'overdue'  => array_values(array_filter($schedules, fn($r) => (int)$r['remaining_day'] <= 0)),
    'alert'    => array_values(array_filter($schedules, fn($r) => (int)$r['remaining_day'] > 0 && (int)$r['remaining_day'] <= 7)),
    'reminder' => array_values(array_filter($schedules, fn($r) => (int)$r['remaining_day'] > 7 && (int)$r['remaining_day'] <= (int)($r['reminder_activity'] ?? 30))),
    'secure'   => array_values(array_filter($schedules, fn($r) => (int)$r['remaining_day'] > (int)($r['reminder_activity'] ?? 30))),
];

// Preventive schedule stats by status
$prevSchedByStatus = [
    'overdue'  => array_values(array_filter($prevSchedules, fn($r) => (int)$r['remaining_day'] <= 0)),
    'alert'    => array_values(array_filter($prevSchedules, fn($r) => (int)$r['remaining_day'] > 0 && (int)$r['remaining_day'] <= 7)),
    'reminder' => array_values(array_filter($prevSchedules, fn($r) => (int)$r['remaining_day'] > 7 && (int)$r['remaining_day'] <= (int)($r['reminder_activity'] ?? 30))),
    'secure'   => array_values(array_filter($prevSchedules, fn($r) => (int)$r['remaining_day'] > (int)($r['reminder_activity'] ?? 30))),
];
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | Maintenance Hub</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        .tab-btn {
            transition: all 0.2s;
        }

        .tab-btn.active {
            background: #1e293b;
            color: white;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .modal-overlay {
            display: none;
        }

        .modal-overlay.open {
            display: flex;
        }

        .sidebar-link {
            transition: all 0.2s;
            border-radius: 0.75rem;
            position: relative;
        }

        .sidebar-link.active,
        .sidebar-link:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .sidebar-link.active {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.35), rgba(37, 99, 235, 0.25));
            font-weight: 700;
            color: #93c5fd;
            border-left: 3px solid #3b82f6;
        }

        .sidebar-link.active i {
            color: #60a5fa;
        }

        .sidebar-link:hover:not(.active) {
            background: rgba(255, 255, 255, 0.08);
        }

        table tbody tr:hover {
            background: #f8fafc;
        }

        .badge-overdue {
            background: #fee2e2;
            color: #b91c1c;
        }

        .badge-near {
            background: #fef9c3;
            color: #b45309;
        }

        .badge-ok {
            background: #dcfce7;
            color: #166534;
        }

        /* Part Availability badges */
        .badge-part {
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

        .badge-part-zero {
            background: #fee2e2;
            color: #b91c1c;
            border-color: #fca5a5;
        }

        .badge-part-low {
            background: #ffedd5;
            color: #c2410c;
            border-color: #fdba74;
        }

        .badge-part-in {
            background: #d1fae5;
            color: #065f46;
            border-color: #6ee7b7;
        }

        .badge-part-over {
            background: #ede9fe;
            color: #6d28d9;
            border-color: #c4b5fd;
        }

        .badge-part-none {
            background: #f1f5f9;
            color: #64748b;
            border-color: #cbd5e1;
        }
    </style>
</head>

<body class="bg-slate-100 min-h-screen">

    <!-- SIDEBAR + LAYOUT -->
    <div class="flex min-h-screen">

        <!-- Sidebar -->
        <aside class="w-64 bg-slate-800 text-white flex flex-col fixed inset-y-0 left-0 z-30">
            <div class="p-6 border-b border-slate-700">
                <img src="assets/yanmar.png" alt="Logo" class="h-10 mb-3">
                <div class="text-xs text-slate-400 uppercase tracking-widest font-bold">Admin Panel</div>
                <div class="font-bold text-white mt-1"><?= htmlspecialchars($_SESSION['username']) ?></div>
            </div>

            <nav class="flex-1 p-4 space-y-1 overflow-y-auto">
                <button onclick="switchSection('overview')" class="sidebar-link active w-full text-left px-4 py-3 flex items-center gap-3 text-sm" id="nav-overview">
                    <i class="fas fa-chart-pie w-4"></i>
                    <span>Overview</span>
                </button>
                <button onclick="switchSection('schedule')" class="sidebar-link w-full text-left px-4 py-3 flex items-center gap-3 text-sm" id="nav-schedule">
                    <i class="fas fa-calendar-check w-4"></i>
                    <span>Schedule</span>
                    <?php if ($overdue > 0): ?>
                        <span class="ml-auto bg-red-500 text-white text-[10px] font-black px-1.5 py-0.5 rounded-full"><?= $overdue ?></span>
                    <?php endif; ?>
                </button>
                <button onclick="switchSection('inventory')" class="sidebar-link w-full text-left px-4 py-3 flex items-center gap-3 text-sm" id="nav-inventory">
                    <i class="fas fa-boxes-stacked w-4"></i>
                    <span>Part Availability</span>
                    <?php if ($zeroStock > 0): ?>
                        <span class="ml-auto bg-orange-500 text-white text-[10px] font-black px-1.5 py-0.5 rounded-full"><?= $zeroStock ?></span>
                    <?php endif; ?>
                </button>
                <button onclick="switchSection('history')" class="sidebar-link w-full text-left px-4 py-3 flex items-center gap-3 text-sm" id="nav-history">
                    <i class="fas fa-history w-4"></i>
                    <span>History</span>
                </button>
                <div class="pt-4 pb-1 px-4 text-[10px] uppercase tracking-widest text-slate-500 font-bold">Management</div>
                <button onclick="switchSection('users')" class="sidebar-link w-full text-left px-4 py-3 flex items-center gap-3 text-sm" id="nav-users">
                    <i class="fas fa-users w-4"></i>
                    <span>Kelola User</span>
                    <?php if ($total_users > 0): ?>
                        <span class="ml-auto bg-slate-600 text-slate-200 text-[10px] font-black px-1.5 py-0.5 rounded-full"><?= $total_users ?></span>
                    <?php endif; ?>
                </button>
                <button onclick="switchSection('notif')" class="sidebar-link w-full text-left px-4 py-3 flex items-center gap-3 text-sm" id="nav-notif">
                    <i class="fas fa-bell w-4"></i>
                    <span>Notifikasi</span>
                    <?php $activeAdmins = count($notif_recipients); ?>
                    <?php if ($activeAdmins > 0): ?>
                        <span class="ml-auto bg-blue-500 text-white text-[10px] font-black px-1.5 py-0.5 rounded-full"><?= $activeAdmins ?></span>
                    <?php endif; ?>
                </button>
            </nav>

            <div class="p-4 border-t border-slate-700">
                <a href="logout_admin.php" class="w-full flex items-center gap-3 px-4 py-3 text-sm text-red-400 hover:text-red-300 hover:bg-red-900/20 rounded-xl transition">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="ml-64 flex-1 p-6 lg:p-8">

            <!-- ===== OVERVIEW ===== -->
            <section id="section-overview" class="section-panel">
                <h1 class="text-2xl font-extrabold text-slate-800 mb-1">Overview</h1>
                <p class="text-slate-500 text-sm mb-6">Ringkasan sistem maintenance secara keseluruhan.</p>

                <?php
                $predOverdue  = count($schedByStatus['overdue']);
                $predAlert    = count($schedByStatus['alert']);
                $predReminder = count($schedByStatus['reminder']);
                $predSecure   = count($schedByStatus['secure']);
                $prevOverdue  = count($prevSchedByStatus['overdue']);
                $prevAlert    = count($prevSchedByStatus['alert']);
                $prevReminder = count($prevSchedByStatus['reminder']);
                $prevSecure   = count($prevSchedByStatus['secure']);
                ?>
                <!-- Overview baris 1: ringkasan total + critical -->
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-3">
                    <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-sm">
                        <div class="flex items-center gap-2 mb-2"><span class="w-2 h-2 rounded-full bg-blue-500 inline-block"></span><span class="text-[10px] text-slate-400 font-bold uppercase tracking-wide">Predictive</span></div>
                        <div class="text-3xl font-black text-blue-600"><?= count($schedules) ?></div>
                        <div class="text-xs text-slate-500 font-semibold mt-1 uppercase tracking-wide">Total Schedule</div>
                    </div>
                    <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-sm">
                        <div class="flex items-center gap-2 mb-2"><span class="w-2 h-2 rounded-full bg-teal-500 inline-block"></span><span class="text-[10px] text-slate-400 font-bold uppercase tracking-wide">Preventive</span></div>
                        <div class="text-3xl font-black text-teal-600"><?= count($prevSchedules) ?></div>
                        <div class="text-xs text-slate-500 font-semibold mt-1 uppercase tracking-wide">Total Schedule</div>
                    </div>
                    <div class="bg-red-50 rounded-2xl p-5 border border-red-200 shadow-sm">
                        <div class="flex items-center gap-2 mb-2"><i class="fas fa-exclamation-circle text-red-400 text-xs"></i><span class="text-[10px] text-slate-400 font-bold uppercase tracking-wide">Overdue</span></div>
                        <div class="text-3xl font-black text-red-600"><?= $predOverdue + $prevOverdue ?></div>
                        <div class="text-xs text-slate-500 font-semibold mt-1 uppercase tracking-wide">Pred + Prev</div>
                        <div class="text-[10px] text-red-400 mt-1"><?= $predOverdue ?> pred &bull; <?= $prevOverdue ?> prev</div>
                    </div>
                    <div class="bg-amber-50 rounded-2xl p-5 border border-amber-200 shadow-sm">
                        <div class="flex items-center gap-2 mb-2"><i class="fas fa-triangle-exclamation text-amber-400 text-xs"></i><span class="text-[10px] text-slate-400 font-bold uppercase tracking-wide">Alert</span></div>
                        <div class="text-3xl font-black text-amber-600"><?= $predAlert + $prevAlert ?></div>
                        <div class="text-xs text-slate-500 font-semibold mt-1 uppercase tracking-wide">≤ 7 Hari Tersisa</div>
                        <div class="text-[10px] text-amber-400 mt-1"><?= $predAlert ?> pred &bull; <?= $prevAlert ?> prev</div>
                    </div>
                </div>
                <!-- Overview baris 2: breakdown detail -->
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                    <div class="bg-blue-50 rounded-2xl p-5 border border-blue-100 shadow-sm">
                        <div class="flex items-center gap-2 mb-2"><i class="fas fa-bell text-blue-400 text-xs"></i><span class="text-[10px] text-slate-400 font-bold uppercase tracking-wide">Reminder Pred.</span></div>
                        <div class="text-3xl font-black text-blue-600"><?= $predReminder ?></div>
                        <div class="text-xs text-slate-500 font-semibold mt-1 uppercase tracking-wide">Predictive</div>
                    </div>
                    <div class="bg-emerald-50 rounded-2xl p-5 border border-emerald-100 shadow-sm">
                        <div class="flex items-center gap-2 mb-2"><i class="fas fa-check-circle text-emerald-400 text-xs"></i><span class="text-[10px] text-slate-400 font-bold uppercase tracking-wide">Aman Pred.</span></div>
                        <div class="text-3xl font-black text-emerald-600"><?= $predSecure ?></div>
                        <div class="text-xs text-slate-500 font-semibold mt-1 uppercase tracking-wide">Predictive</div>
                    </div>
                    <div class="bg-teal-50 rounded-2xl p-5 border border-teal-100 shadow-sm">
                        <div class="flex items-center gap-2 mb-2"><i class="fas fa-bell text-teal-400 text-xs"></i><span class="text-[10px] text-slate-400 font-bold uppercase tracking-wide">Reminder Prev.</span></div>
                        <div class="text-3xl font-black text-teal-600"><?= $prevReminder ?></div>
                        <div class="text-xs text-slate-500 font-semibold mt-1 uppercase tracking-wide">Preventive</div>
                    </div>
                    <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-sm">
                        <div class="flex items-center gap-2 mb-2"><i class="fas fa-users text-slate-400 text-xs"></i><span class="text-[10px] text-slate-400 font-bold uppercase tracking-wide">User</span></div>
                        <div class="text-3xl font-black text-slate-700"><?= $total_users ?></div>
                        <div class="text-xs text-slate-500 font-semibold mt-1 uppercase tracking-wide">Total User Aktif</div>
                    </div>
                </div>

                <!-- Quick Table: Upcoming -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                        <h2 class="font-bold text-slate-700">Jadwal Mendatang (30 Hari)</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 text-slate-500 text-xs uppercase">
                                <tr>
                                    <th class="px-5 py-3 text-left font-semibold">Mesin</th>
                                    <th class="px-5 py-3 text-left font-semibold">Maintenance Point</th>
                                    <th class="px-5 py-3 text-center font-semibold">Plan Date</th>
                                    <th class="px-5 py-3 text-center font-semibold">Sisa Hari</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                <?php foreach (array_slice(array_filter($schedules, fn($r) => (int)$r['remaining_day'] <= 30), 0, 10) as $row):
                                    $d = (int)$row['remaining_day'];
                                    $cls = $d <= 0 ? 'badge-overdue' : ($d <= 7 ? 'badge-near' : 'badge-ok');
                                ?>
                                    <tr>
                                        <td class="px-5 py-3">
                                            <div class="font-semibold text-slate-800"><?= htmlspecialchars($row['machine_name']) ?></div>
                                            <div class="text-xs text-slate-400"><?= htmlspecialchars($row['department']) ?> | <?= htmlspecialchars($row['line']) ?></div>
                                        </td>
                                        <td class="px-5 py-3 text-slate-600"><?= htmlspecialchars($row['maintenance_point']) ?></td>
                                        <td class="px-5 py-3 text-center font-mono font-bold text-slate-700"><?= formatDate($row['change_date_plan']) ?></td>
                                        <td class="px-5 py-3 text-center">
                                            <span class="px-3 py-1 rounded-full text-xs font-black <?= $cls ?>"><?= $d ?> hari</span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- ===== SCHEDULE ===== -->
            <section id="section-schedule" class="section-panel hidden">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h1 class="text-2xl font-extrabold text-slate-800 mb-1">Schedule</h1>
                        <p class="text-slate-500 text-sm">Kelola jadwal maintenance semua mesin.</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button onclick="openDeleteAllModal('schedule')" class="bg-red-50 hover:bg-red-100 text-red-600 border border-red-200 px-4 py-2.5 rounded-xl font-bold text-sm flex items-center gap-2 transition">
                            <i class="fas fa-trash-can"></i> Hapus Semua
                        </button>
                        <button onclick="openModal('addScheduleModal')" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-xl font-bold text-sm flex items-center gap-2 shadow">
                            <i class="fas fa-plus"></i> Tambah Schedule
                        </button>
                    </div>
                </div>

                <!-- Stat Cards - Predictive (tampil default) -->
                <div id="predStatCards" class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-5">
                    <?php
                    $statusConfig = [
                        'overdue'  => ['label' => 'Overdue',  'bg' => 'bg-red-50',     'border' => 'border-red-200',     'text' => 'text-red-600',     'icon' => 'fa-exclamation-circle', 'sub' => ''],
                        'alert'    => ['label' => 'Alert',    'bg' => 'bg-amber-50',   'border' => 'border-amber-200',   'text' => 'text-amber-600',   'icon' => 'fa-triangle-exclamation', 'sub' => '≤ 7 hari tersisa'],
                        'reminder' => ['label' => 'Reminder', 'bg' => 'bg-blue-50',    'border' => 'border-blue-200',    'text' => 'text-blue-600',    'icon' => 'fa-bell',               'sub' => ''],
                        'secure'   => ['label' => 'Aman',     'bg' => 'bg-emerald-50', 'border' => 'border-emerald-200', 'text' => 'text-emerald-600', 'icon' => 'fa-check-circle',       'sub' => ''],
                    ];
                    foreach ($statusConfig as $key => $cfg): ?>
                        <div class="<?= $cfg['bg'] ?> rounded-2xl p-5 border <?= $cfg['border'] ?> shadow-sm">
                            <div class="flex items-center gap-2 mb-1">
                                <i class="fas <?= $cfg['icon'] ?> <?= $cfg['text'] ?> text-sm"></i>
                                <div class="text-[10px] text-slate-500 font-bold uppercase tracking-wide"><?= $cfg['label'] ?></div>
                            </div>
                            <div class="text-3xl font-black <?= $cfg['text'] ?>"><?= count($schedByStatus[$key]) ?></div>
                            <?php if ($cfg['sub']): ?><div class="text-[10px] <?= $cfg['text'] ?> opacity-70 mt-0.5"><?= $cfg['sub'] ?></div><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Stat Cards - Preventive (tersembunyi default) -->
                <div id="prevStatCards" class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-5 hidden">
                    <?php
                    $prevStatusConfig = [
                        'overdue'  => ['label' => 'Overdue',  'bg' => 'bg-red-50',     'border' => 'border-red-200',     'text' => 'text-red-600',     'icon' => 'fa-exclamation-circle', 'sub' => ''],
                        'alert'    => ['label' => 'Alert',    'bg' => 'bg-amber-50',   'border' => 'border-amber-200',   'text' => 'text-amber-600',   'icon' => 'fa-triangle-exclamation', 'sub' => '≤ 7 hari tersisa'],
                        'reminder' => ['label' => 'Reminder', 'bg' => 'bg-teal-50',    'border' => 'border-teal-200',    'text' => 'text-teal-600',    'icon' => 'fa-bell',               'sub' => ''],
                        'secure'   => ['label' => 'Aman',     'bg' => 'bg-emerald-50', 'border' => 'border-emerald-200', 'text' => 'text-emerald-600', 'icon' => 'fa-check-circle',       'sub' => ''],
                    ];
                    foreach ($prevStatusConfig as $key => $cfg): ?>
                        <div class="<?= $cfg['bg'] ?> rounded-2xl p-5 border <?= $cfg['border'] ?> shadow-sm">
                            <div class="flex items-center gap-2 mb-1">
                                <i class="fas <?= $cfg['icon'] ?> <?= $cfg['text'] ?> text-sm"></i>
                                <div class="text-[10px] text-slate-500 font-bold uppercase tracking-wide"><?= $cfg['label'] ?></div>
                            </div>
                            <div class="text-3xl font-black <?= $cfg['text'] ?>"><?= count($prevSchedByStatus[$key]) ?></div>
                            <?php if ($cfg['sub']): ?><div class="text-[10px] <?= $cfg['text'] ?> opacity-70 mt-0.5"><?= $cfg['sub'] ?></div><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Tab switcher -->
                <div class="mb-4">
                    <div class="relative bg-slate-100 rounded-2xl p-1.5 flex gap-1 shadow-inner">
                        <div id="schedTabIndicator" class="absolute top-1.5 left-1.5 bottom-1.5 rounded-xl transition-all duration-300 ease-in-out shadow-md pointer-events-none"
                            style="background:linear-gradient(135deg,#2563eb,#1d4ed8);width:calc(50% - 4px);transform:translateX(0);"></div>
                        <button id="schedTabPred" onclick="switchSchedTab('predictive')"
                            class="relative z-10 flex-1 flex items-center justify-center gap-2 py-3 px-6 font-bold text-sm rounded-xl transition-all duration-300 text-white">
                            <i class="fas fa-chart-line"></i> Predictive
                            <span class="ml-1 bg-white/20 text-white text-[10px] font-black px-2 py-0.5 rounded-full"><?= count($schedules) ?></span>
                        </button>
                        <button id="schedTabPrev" onclick="switchSchedTab('preventive')"
                            class="relative z-10 flex-1 flex items-center justify-center gap-2 py-3 px-6 font-bold text-sm rounded-xl transition-all duration-300 text-slate-500">
                            <i class="fas fa-shield-halved"></i> Preventive
                            <span class="ml-1 bg-slate-300/60 text-slate-600 text-[10px] font-black px-2 py-0.5 rounded-full"><?= count($prevSchedules ?? []) ?></span>
                        </button>
                    </div>
                </div>

                <!-- Predictive Table -->
                <div id="schedPredContent">
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                        <div class="px-5 py-3 border-b border-slate-100">
                            <input type="text" placeholder="Cari mesin / maintenance point..." onkeyup="filterTable('scheduleTable', this.value)" class="w-full max-w-sm border border-slate-200 rounded-lg px-4 py-2 text-sm outline-none focus:ring-2 focus:ring-blue-100">
                        </div>
                        <div class="overflow-x-auto" style="max-height:520px;overflow-y:auto;">
                            <table class="w-full text-sm" id="scheduleTable">
                                <thead class="text-white text-xs uppercase sticky top-0 z-10" style="background:linear-gradient(135deg,#2563eb,#1d4ed8);">
                                    <tr>
                                        <th class="px-5 py-3 text-left">Mesin</th>
                                        <th class="px-5 py-3 text-left">Maintenance Point</th>
                                        <th class="px-5 py-3 text-center">Interval</th>
                                        <th class="px-5 py-3 text-center">Use Date</th>
                                        <th class="px-5 py-3 text-center">Plan Date</th>
                                        <th class="px-5 py-3 text-center">Status</th>
                                        <th class="px-5 py-3 text-center">Sisa Hari</th>
                                        <th class="px-5 py-3 text-center">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-50">
                                    <?php foreach ($schedules as $row):
                                        $d = (int)$row['remaining_day'];
                                        $cls = $d <= 0 ? 'badge-overdue' : ($d <= 7 ? 'badge-near' : 'badge-ok');
                                        $maintStatus = $row['maintenance_status'] ?? 'soon';
                                        $partOrder   = $row['part_order'] ?? 'close';
                                        $partAvail   = $row['part_availability'] ?? 'close';
                                    ?>
                                        <tr>
                                            <td class="px-5 py-3">
                                                <div class="font-semibold text-slate-800"><?= htmlspecialchars($row['machine_name']) ?></div>
                                                <div class="text-xs text-slate-400"><?= htmlspecialchars($row['department']) ?> | <?= htmlspecialchars($row['line']) ?></div>
                                            </td>
                                            <td class="px-5 py-3 text-slate-600 max-w-[180px] truncate"><?= htmlspecialchars($row['maintenance_point']) ?></td>
                                            <td class="px-5 py-3 text-center text-slate-600"><?= htmlspecialchars($row['interval_month']) ?> bln</td>
                                            <td class="px-5 py-3 text-center font-mono text-slate-600"><?= formatDate($row['use_date']) ?></td>
                                            <td class="px-5 py-3 text-center font-mono font-bold text-slate-800"><?= formatDate($row['change_date_plan']) ?></td>
                                            <td class="px-5 py-3 text-center">
                                                <span class="px-2 py-1 rounded-full text-[10px] font-black <?= $maintStatus === 'done' ? 'bg-emerald-100 text-emerald-700' : 'bg-blue-100 text-blue-700' ?>">
                                                    <?= strtoupper($maintStatus) ?>
                                                </span>
                                            </td>
                                            <td class="px-5 py-3 text-center">
                                                <span class="px-3 py-1 rounded-full text-xs font-black <?= $cls ?>"><?= $d ?>d</span>
                                            </td>
                                            <td class="px-5 py-3 text-center">
                                                <div class="flex justify-center gap-1.5">
                                                    <button onclick="editSchedule(<?= $row['id'] ?>)" class="w-8 h-8 bg-amber-100 text-amber-700 rounded-lg hover:bg-amber-200 transition text-xs" title="Edit"><i class="fas fa-edit"></i></button>
                                                    <button onclick="deleteRecord('delete_schedule', <?= $row['id'] ?>)" class="w-8 h-8 bg-red-100 text-red-600 rounded-lg hover:bg-red-200 transition text-xs" title="Hapus"><i class="fas fa-trash"></i></button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach;
                                    if (empty($schedules)): ?>
                                        <tr>
                                            <td colspan="8" class="px-5 py-10 text-center text-slate-400 text-sm">Belum ada schedule predictive.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Preventive Table -->
                <div id="schedPrevContent" class="hidden">
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                        <div class="px-5 py-3 border-b border-slate-100">
                            <input type="text" placeholder="Cari mesin / maintenance point..." onkeyup="filterTable('schedPrevTable', this.value)" class="w-full max-w-sm border border-slate-200 rounded-lg px-4 py-2 text-sm outline-none focus:ring-2 focus:ring-teal-100">
                        </div>
                        <div class="overflow-x-auto" style="max-height:520px;overflow-y:auto;">
                            <table class="w-full text-sm" id="schedPrevTable">
                                <thead class="text-white text-xs uppercase sticky top-0 z-10" style="background:linear-gradient(135deg,#0f766e,#0d9488);">
                                    <tr>
                                        <th class="px-5 py-3 text-left">Mesin</th>
                                        <th class="px-5 py-3 text-left">Maintenance Point</th>
                                        <th class="px-5 py-3 text-center">Interval</th>
                                        <th class="px-5 py-3 text-center">Use Date</th>
                                        <th class="px-5 py-3 text-center">Plan Date</th>
                                        <th class="px-5 py-3 text-center">Status</th>
                                        <th class="px-5 py-3 text-center">Sisa Hari</th>
                                        <th class="px-5 py-3 text-center">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-50">
                                    <?php foreach ($prevSchedules ?? [] as $row):
                                        $d = (int)$row['remaining_day'];
                                        $cls = $d <= 0 ? 'badge-overdue' : ($d <= 7 ? 'badge-near' : 'badge-ok');
                                        $maintStatus = $row['maintenance_status'] ?? 'soon';
                                    ?>
                                        <tr>
                                            <td class="px-5 py-3">
                                                <div class="font-semibold text-slate-800"><?= htmlspecialchars($row['machine_name']) ?></div>
                                                <div class="text-xs text-slate-400"><?= htmlspecialchars($row['department']) ?> | <?= htmlspecialchars($row['line']) ?></div>
                                            </td>
                                            <td class="px-5 py-3 text-slate-600 max-w-[180px] truncate"><?= htmlspecialchars($row['maintenance_point']) ?></td>
                                            <td class="px-5 py-3 text-center text-slate-600"><?= htmlspecialchars($row['interval_month']) ?> bln</td>
                                            <td class="px-5 py-3 text-center font-mono text-slate-600"><?= formatDate($row['use_date']) ?></td>
                                            <td class="px-5 py-3 text-center font-mono font-bold text-slate-800"><?= formatDate($row['change_date_plan']) ?></td>
                                            <td class="px-5 py-3 text-center">
                                                <span class="px-2 py-1 rounded-full text-[10px] font-black <?= $maintStatus === 'done' ? 'bg-emerald-100 text-emerald-700' : 'bg-teal-100 text-teal-700' ?>">
                                                    <?= strtoupper($maintStatus) ?>
                                                </span>
                                            </td>
                                            <td class="px-5 py-3 text-center">
                                                <span class="px-3 py-1 rounded-full text-xs font-black <?= $cls ?>"><?= $d ?>d</span>
                                            </td>
                                            <td class="px-5 py-3 text-center">
                                                <button onclick="deleteRecord('delete_prev_schedule', <?= $row['id'] ?>)" class="w-8 h-8 bg-red-100 text-red-600 rounded-lg hover:bg-red-200 transition text-xs" title="Hapus"><i class="fas fa-trash"></i></button>
                                            </td>
                                        </tr>
                                    <?php endforeach;
                                    if (empty($prevSchedules)): ?>
                                        <tr>
                                            <td colspan="8" class="px-5 py-10 text-center text-slate-400 text-sm">Belum ada schedule preventive.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ===== INVENTORY ===== -->
            <section id="section-inventory" class="section-panel hidden">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h1 class="text-2xl font-extrabold text-slate-800 mb-1">Part Availability</h1>
                        <p class="text-slate-500 text-sm">Kelola stok sparepart dan ketersediaan gudang.</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button onclick="openDeleteAllModal('parts')" class="bg-red-50 hover:bg-red-100 text-red-600 border border-red-200 px-4 py-2.5 rounded-xl font-bold text-sm flex items-center gap-2 transition">
                            <i class="fas fa-trash-can"></i> Hapus Semua
                        </button>
                        <button onclick="openModal('addPartModal')" class="bg-emerald-600 hover:bg-emerald-700 text-white px-5 py-2.5 rounded-xl font-bold text-sm flex items-center gap-2 shadow">
                            <i class="fas fa-plus"></i> Tambah Part
                        </button>
                    </div>
                </div>

                <!-- Stat Cards -->
                <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
                    <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
                        <p class="text-slate-400 text-[10px] font-bold uppercase tracking-widest mb-1">Total Parts</p>
                        <p class="text-3xl font-black text-slate-800"><?= $totalParts ?></p>
                    </div>
                    <div class="bg-red-50 p-5 rounded-2xl border border-red-100 shadow-sm">
                        <p class="text-red-400 text-[10px] font-bold uppercase tracking-widest mb-1">Zero Stock</p>
                        <p class="text-3xl font-black text-red-600"><?= $zeroStock ?></p>
                    </div>
                    <div class="bg-orange-50 p-5 rounded-2xl border border-orange-100 shadow-sm">
                        <p class="text-orange-400 text-[10px] font-bold uppercase tracking-widest mb-1">Low Stock</p>
                        <p class="text-3xl font-black text-orange-600"><?= $lowStock ?></p>
                    </div>
                    <div class="bg-emerald-50 p-5 rounded-2xl border border-emerald-100 shadow-sm">
                        <p class="text-emerald-500 text-[10px] font-bold uppercase tracking-widest mb-1">In Stock</p>
                        <p class="text-3xl font-black text-emerald-600"><?= $inStock ?></p>
                    </div>
                    <div class="bg-violet-50 p-5 rounded-2xl border border-violet-100 shadow-sm">
                        <p class="text-violet-400 text-[10px] font-bold uppercase tracking-widest mb-1">Over Stock</p>
                        <p class="text-3xl font-black text-violet-600"><?= $overstock ?></p>
                    </div>
                </div>

                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-100">
                        <input type="text" placeholder="Cari kode / nama part..." onkeyup="filterTable('partTable', this.value)" class="w-full max-w-sm border border-slate-200 rounded-lg px-4 py-2 text-sm outline-none focus:ring-2 focus:ring-emerald-100">
                    </div>
                    <div class="overflow-x-auto" style="max-height:520px;overflow-y:auto;">
                        <table class="w-full text-sm" id="partTable">
                            <thead class="text-white text-xs uppercase sticky top-0 z-10" style="background:linear-gradient(135deg,#0f766e,#0d9488);">
                                <tr>
                                    <th class="px-5 py-3 text-left">No</th>
                                    <th class="px-5 py-3 text-left">Item Code</th>
                                    <th class="px-5 py-3 text-left">Item Description</th>
                                    <th class="px-5 py-3 text-center">Safety Stock</th>
                                    <th class="px-5 py-3 text-center">Actual Stock</th>
                                    <th class="px-5 py-3 text-center">Effective Stock</th>
                                    <th class="px-5 py-3 text-center">Status</th>
                                    <th class="px-5 py-3 text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                <?php if (empty($parts)): ?>
                                    <tr>
                                        <td colspan="8" class="px-5 py-12 text-center text-slate-400 text-sm">
                                            <i class="fas fa-box-open text-4xl block mb-3 text-slate-200"></i>
                                            Belum ada data part.
                                        </td>
                                    </tr>
                                    <?php else:
                                    foreach ($parts as $i => $p):
                                        $actual    = (int)$p['actual_stock'];
                                        $safety    = (int)$p['safety_stock'];
                                        $effective = (int)$p['effective_stock'];
                                        $status    = $p['status'] ?? (($actual === 0) ? 'Zero Stock' : (($actual < $safety) ? 'Low Stock' : (($actual === $safety) ? 'In Stock' : 'Over Stock')));
                                        $badgeCls  = match ($status) {
                                            'Zero Stock' => 'badge-part-zero',
                                            'Low Stock'  => 'badge-part-low',
                                            'In Stock'   => 'badge-part-in',
                                            'Over Stock' => 'badge-part-over',
                                            default      => 'badge-part-none',
                                        };
                                    ?>
                                        <tr>
                                            <td class="px-5 py-3 text-slate-400 font-medium"><?= $i + 1 ?></td>
                                            <td class="px-5 py-3 font-mono font-bold text-slate-700 tracking-wide"><?= htmlspecialchars($p['item_code']) ?></td>
                                            <td class="px-5 py-3 text-slate-700 max-w-xs"><?= htmlspecialchars($p['item_description'] ?? '-') ?></td>
                                            <td class="px-5 py-3 text-center">
                                                <span class="inline-block bg-slate-100 text-slate-600 font-bold px-3 py-1 rounded-lg"><?= $safety ?></span>
                                            </td>
                                            <td class="px-5 py-3 text-center font-black text-lg <?= $actual === 0 ? 'text-red-500' : ($actual < $safety ? 'text-orange-500' : ($actual === $safety ? 'text-emerald-600' : 'text-violet-600')) ?>"><?= $actual ?></td>
                                            <td class="px-5 py-3 text-center font-bold <?= $effective < 0 ? 'text-red-500' : 'text-slate-600' ?>"><?= ($effective >= 0 ? '+' : '') . $effective ?></td>
                                            <td class="px-5 py-3 text-center">
                                                <span class="badge-part <?= $badgeCls ?>"><?= htmlspecialchars($status) ?></span>
                                            </td>
                                            <td class="px-5 py-3 text-center">
                                                <div class="flex justify-center gap-1.5">
                                                    <button onclick="editPartAdmin(<?= htmlspecialchars(json_encode($p)) ?>)" class="w-8 h-8 bg-amber-100 text-amber-700 rounded-lg hover:bg-amber-200 transition text-xs" title="Edit"><i class="fas fa-edit"></i></button>
                                                    <button onclick="deleteRecord('delete_part', <?= $p['id'] ?>)" class="w-8 h-8 bg-red-100 text-red-600 rounded-lg hover:bg-red-200 transition text-xs" title="Hapus"><i class="fas fa-trash"></i></button>
                                                </div>
                                            </td>
                                        </tr>
                                <?php endforeach;
                                endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- ===== HISTORY ===== -->
            <section id="section-history" class="section-panel hidden">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h1 class="text-2xl font-extrabold text-slate-800 mb-1">History</h1>
                        <p class="text-slate-500 text-sm">Log aktivitas maintenance yang sudah dilakukan.</p>
                    </div>
                    <button onclick="openDeleteAllModal('history')" class="bg-red-50 hover:bg-red-100 text-red-600 border border-red-200 px-4 py-2.5 rounded-xl font-bold text-sm flex items-center gap-2 transition">
                        <i class="fas fa-trash-can"></i> Hapus Semua
                    </button>
                </div>

                <!-- Tab switcher history -->
                <div class="mb-4">
                    <div class="relative bg-slate-100 rounded-2xl p-1.5 flex gap-1 shadow-inner">
                        <div id="histTabIndicator" class="absolute top-1.5 left-1.5 bottom-1.5 rounded-xl transition-all duration-300 ease-in-out shadow-md pointer-events-none"
                            style="background:linear-gradient(135deg,#f59e0b,#d97706);width:calc(50% - 4px);transform:translateX(0);"></div>
                        <button id="histTabPred" onclick="switchHistTab('predictive')"
                            class="relative z-10 flex-1 flex items-center justify-center gap-2 py-3 px-6 font-bold text-sm rounded-xl transition-all duration-300 text-white">
                            <i class="fas fa-chart-line"></i> Predictive
                            <span class="ml-1 bg-white/20 text-white text-[10px] font-black px-2 py-0.5 rounded-full"><?= count($histories) ?></span>
                        </button>
                        <button id="histTabPrev" onclick="switchHistTab('preventive')"
                            class="relative z-10 flex-1 flex items-center justify-center gap-2 py-3 px-6 font-bold text-sm rounded-xl transition-all duration-300 text-slate-500">
                            <i class="fas fa-shield-halved"></i> Preventive
                            <span class="ml-1 bg-slate-300/60 text-slate-600 text-[10px] font-black px-2 py-0.5 rounded-full"><?= count($historiesPrev) ?></span>
                        </button>
                    </div>
                </div>

                <!-- History Predictive -->
                <div id="histPredContent">
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                        <div class="px-5 py-3 border-b border-slate-100">
                            <input type="text" placeholder="Cari history predictive..." onkeyup="filterTable('historyTable', this.value)" class="w-full max-w-sm border border-slate-200 rounded-lg px-4 py-2 text-sm outline-none">
                        </div>
                        <div class="overflow-x-auto" style="max-height:520px;overflow-y:auto;">
                            <table class="w-full text-sm" id="historyTable">
                                <thead class="text-white text-xs uppercase sticky top-0 z-10" style="background:linear-gradient(135deg,#f59e0b,#d97706);">
                                    <tr>
                                        <th class="px-5 py-3 text-left">Department</th>
                                        <th class="px-5 py-3 text-left">Mesin</th>
                                        <th class="px-5 py-3 text-left">Maintenance Point</th>
                                        <th class="px-5 py-3 text-center">Change Date Plan</th>
                                        <th class="px-5 py-3 text-left">Teknisi</th>
                                        <th class="px-5 py-3 text-center">Dilaporkan</th>
                                        <th class="px-5 py-3 text-left">Catatan</th>
                                        <th class="px-5 py-3 text-center">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-50">
                                    <?php foreach ($histories as $h): ?>
                                        <tr>
                                            <td class="px-5 py-3 text-slate-500 text-xs"><?= htmlspecialchars($h['department'] ?? '-') ?></td>
                                            <td class="px-5 py-3">
                                                <div class="font-semibold text-slate-800"><?= htmlspecialchars($h['machine_name'] ?? '-') ?></div>
                                                <div class="text-xs text-slate-400"><?= htmlspecialchars($h['line'] ?? '') ?><?= ($h['line'] ?? '') && ($h['operation_process'] ?? '') ? ' · ' : '' ?><?= htmlspecialchars($h['operation_process'] ?? '') ?></div>
                                            </td>
                                            <td class="px-5 py-3 text-slate-600 max-w-[160px] truncate"><?= htmlspecialchars($h['maintenance_point'] ?? '-') ?></td>
                                            <td class="px-5 py-3 text-center font-mono text-slate-600"><?= htmlspecialchars(isset($h['change_date_plan']) ? date('d M Y', strtotime($h['change_date_plan'])) : '-') ?></td>
                                            <td class="px-5 py-3 text-slate-600"><?= htmlspecialchars($h['technician_name'] ?? ('User #' . ($h['reported_by'] ?? '-'))) ?></td>
                                            <td class="px-5 py-3 text-center font-mono text-slate-500 text-xs"><?= htmlspecialchars($h['reported_at'] ?? '-') ?></td>
                                            <td class="px-5 py-3 text-slate-500 text-xs max-w-[180px] truncate"><?= htmlspecialchars($h['note'] ?? '-') ?></td>
                                            <td class="px-5 py-3 text-center">
                                                <button onclick="deleteRecord('delete_history', <?= $h['id'] ?>)" class="w-8 h-8 bg-red-100 text-red-600 rounded-lg hover:bg-red-200 transition text-xs" title="Hapus"><i class="fas fa-trash"></i></button>
                                            </td>
                                        </tr>
                                    <?php endforeach;
                                    if (empty($histories)): ?>
                                        <tr>
                                            <td colspan="8" class="px-5 py-10 text-center text-slate-400 text-sm">Belum ada history predictive.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- History Preventive -->
                <div id="histPrevContent" class="hidden">
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                        <div class="px-5 py-3 border-b border-slate-100">
                            <input type="text" placeholder="Cari history preventive..." onkeyup="filterTable('histPrevTable', this.value)" class="w-full max-w-sm border border-slate-200 rounded-lg px-4 py-2 text-sm outline-none">
                        </div>
                        <div class="overflow-x-auto" style="max-height:520px;overflow-y:auto;">
                            <table class="w-full text-sm" id="histPrevTable">
                                <thead class="text-white text-xs uppercase sticky top-0 z-10" style="background:linear-gradient(135deg,#0f766e,#0d9488);">
                                    <tr>
                                        <th class="px-5 py-3 text-left">Department</th>
                                        <th class="px-5 py-3 text-left">Mesin</th>
                                        <th class="px-5 py-3 text-left">Maintenance Point</th>
                                        <th class="px-5 py-3 text-center">Change Date Plan</th>
                                        <th class="px-5 py-3 text-left">Teknisi</th>
                                        <th class="px-5 py-3 text-center">Dilaporkan</th>
                                        <th class="px-5 py-3 text-left">Catatan</th>
                                        <th class="px-5 py-3 text-center">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-50">
                                    <?php foreach ($historiesPrev as $h): ?>
                                        <tr>
                                            <td class="px-5 py-3 text-slate-500 text-xs"><?= htmlspecialchars($h['department'] ?? '-') ?></td>
                                            <td class="px-5 py-3">
                                                <div class="font-semibold text-slate-800"><?= htmlspecialchars($h['machine_name'] ?? '-') ?></div>
                                                <div class="text-xs text-slate-400"><?= htmlspecialchars($h['line'] ?? '') ?><?= ($h['line'] ?? '') && ($h['operation_process'] ?? '') ? ' · ' : '' ?><?= htmlspecialchars($h['operation_process'] ?? '') ?></div>
                                            </td>
                                            <td class="px-5 py-3 text-slate-600 max-w-[160px] truncate"><?= htmlspecialchars($h['maintenance_point'] ?? '-') ?></td>
                                            <td class="px-5 py-3 text-center font-mono text-slate-600"><?= htmlspecialchars(isset($h['change_date_plan']) ? date('d M Y', strtotime($h['change_date_plan'])) : '-') ?></td>
                                            <td class="px-5 py-3 text-slate-600"><?= htmlspecialchars($h['technician_name'] ?? ('User #' . ($h['reported_by'] ?? '-'))) ?></td>
                                            <td class="px-5 py-3 text-center font-mono text-slate-500 text-xs"><?= htmlspecialchars($h['reported_at'] ?? '-') ?></td>
                                            <td class="px-5 py-3 text-slate-500 text-xs max-w-[180px] truncate"><?= htmlspecialchars($h['note'] ?? '-') ?></td>
                                            <td class="px-5 py-3 text-center">
                                                <button onclick="deleteRecord('delete_history_prev', <?= $h['id'] ?>)" class="w-8 h-8 bg-red-100 text-red-600 rounded-lg hover:bg-red-200 transition text-xs" title="Hapus"><i class="fas fa-trash"></i></button>
                                            </td>
                                        </tr>
                                    <?php endforeach;
                                    if (empty($historiesPrev)): ?>
                                        <tr>
                                            <td colspan="8" class="px-5 py-10 text-center text-slate-400 text-sm">Belum ada history preventive.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ===== USERS ===== -->
            <section id="section-users" class="section-panel hidden">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h1 class="text-2xl font-extrabold text-slate-800 mb-1">Kelola User</h1>
                        <p class="text-slate-500 text-sm">Tambah, edit, aktifkan/nonaktifkan, dan hapus user.</p>
                    </div>
                    <button onclick="openModal('addUserModal')" class="bg-slate-800 hover:bg-slate-900 text-white px-5 py-2.5 rounded-xl font-bold text-sm flex items-center gap-2 shadow">
                        <i class="fas fa-user-plus"></i> Tambah User
                    </button>
                </div>

                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-100 flex items-center gap-3">
                        <input type="text" placeholder="Cari username / email..." onkeyup="filterTable('usersTable', this.value)" class="w-full max-w-sm border border-slate-200 rounded-lg px-4 py-2 text-sm outline-none focus:ring-2 focus:ring-slate-200">
                        <span class="text-xs text-slate-400 font-semibold ml-auto"><?= count($users) ?> user ditemukan</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm" id="usersTable">
                            <thead class="bg-slate-800 text-white text-xs uppercase">
                                <tr>
                                    <th class="px-5 py-3 text-left">Username</th>
                                    <th class="px-5 py-3 text-left">Email</th>
                                    <th class="px-5 py-3 text-center">Role</th>
                                    <th class="px-5 py-3 text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                <?php foreach ($users as $u): ?>
                                    <tr>
                                        <td class="px-5 py-3">
                                            <div class="flex items-center gap-3">
                                                <div class="w-8 h-8 rounded-full bg-slate-200 flex items-center justify-center text-xs font-bold text-slate-600"><?= strtoupper(substr($u['username'], 0, 1)) ?></div>
                                                <span class="font-semibold text-slate-800"><?= htmlspecialchars($u['username']) ?></span>
                                            </div>
                                        </td>
                                        <td class="px-5 py-3 text-slate-500"><?= htmlspecialchars($u['email_user']) ?></td>
                                        <td class="px-5 py-3 text-center">
                                            <span class="px-2 py-1 rounded text-xs font-bold bg-slate-100 text-slate-600">
                                                <?= htmlspecialchars($u['role']) ?>
                                            </span>
                                        </td>
                                        <td class="px-5 py-3 text-center">
                                            <div class="flex justify-center gap-2">
                                                <button onclick="editUser(<?= htmlspecialchars(json_encode($u)) ?>)" class="w-8 h-8 bg-amber-100 text-amber-700 rounded-lg hover:bg-amber-200 transition text-xs" title="Edit"><i class="fas fa-edit"></i></button>
                                                <button onclick="toggleUser(<?= $u['id'] ?>, <?= $u['is_active'] ?>)" class="w-8 h-8 <?= $u['is_active'] ? 'bg-orange-100 text-orange-600 hover:bg-orange-200' : 'bg-green-100 text-green-600 hover:bg-green-200' ?> rounded-lg transition text-xs" title="<?= $u['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?>">
                                                    <i class="fas <?= $u['is_active'] ? 'fa-ban' : 'fa-check-circle' ?>"></i>
                                                </button>
                                                <button onclick="deleteRecord('delete_user', <?= $u['id'] ?>)" class="w-8 h-8 bg-red-100 text-red-600 rounded-lg hover:bg-red-200 transition text-xs" title="Hapus"><i class="fas fa-trash"></i></button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach;
                                if (empty($users)): ?>
                                    <tr>
                                        <td colspan="4" class="px-5 py-10 text-center text-slate-400 text-sm">Belum ada user.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- ===== NOTIFIKASI ===== -->
            <section id="section-notif" class="section-panel hidden">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h1 class="text-2xl font-extrabold text-slate-800 mb-1">Pengaturan Notifikasi</h1>
                        <p class="text-slate-500 text-sm">Daftar penerima email notifikasi maintenance.</p>
                    </div>
                    <button onclick="openModal('addRecipientModal')" class="bg-slate-800 hover:bg-slate-900 text-white px-5 py-2.5 rounded-xl font-bold text-sm flex items-center gap-2 shadow">
                        <i class="fas fa-plus"></i> Tambah Penerima
                    </button>
                </div>

                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden max-w-3xl">
                    <div class="px-6 py-4 border-b border-slate-100 flex items-center gap-2">
                        <i class="fas fa-envelope text-blue-500 text-sm"></i>
                        <h2 class="font-bold text-slate-700 text-sm">Semua Penerima Notifikasi</h2>
                        <?php $totalNotif = count($notif_admins) + count($notif_recipients); ?>
                        <span class="ml-auto text-xs text-slate-400 font-semibold"><?= $totalNotif ?> penerima</span>
                    </div>
                    <table class="w-full text-sm" id="notifTable">
                        <thead class="bg-slate-50 text-slate-500 text-xs uppercase">
                            <tr>
                                <th class="px-5 py-3 text-center font-semibold w-12">No</th>
                                <th class="px-5 py-3 text-left font-semibold">Name</th>
                                <th class="px-5 py-3 text-left font-semibold">Email</th>
                                <th class="px-5 py-3 text-center font-semibold">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <?php
                            $no = 1;
                            foreach ($notif_admins as $a): ?>
                                <tr class="bg-blue-50/40">
                                    <td class="px-5 py-3 text-center text-slate-400 font-medium"><?= $no++ ?></td>
                                    <td class="px-5 py-3">
                                        <div class="flex items-center gap-2">
                                            <span class="font-semibold text-slate-800"><?= htmlspecialchars($a['name']) ?></span>
                                            <span class="text-[10px] bg-purple-100 text-purple-700 font-bold px-1.5 py-0.5 rounded">Admin</span>
                                        </div>
                                    </td>
                                    <td class="px-5 py-3 text-slate-500"><?= htmlspecialchars($a['email']) ?></td>
                                    <td class="px-5 py-3 text-center">
                                        <span class="text-xs text-slate-400 italic">—</span>
                                    </td>
                                </tr>
                            <?php endforeach;
                            foreach ($notif_recipients as $nr): ?>
                                <tr>
                                    <td class="px-5 py-3 text-center text-slate-400 font-medium"><?= $no++ ?></td>
                                    <td class="px-5 py-3 font-semibold text-slate-800"><?= htmlspecialchars($nr['name']) ?></td>
                                    <td class="px-5 py-3 text-slate-500"><?= htmlspecialchars($nr['email']) ?></td>
                                    <td class="px-5 py-3 text-center">
                                        <button onclick="deleteRecord('delete_recipient', <?= $nr['id'] ?>)" class="w-8 h-8 bg-red-100 text-red-600 rounded-lg hover:bg-red-200 transition text-xs" title="Hapus"><i class="fas fa-trash"></i></button>
                                    </td>
                                </tr>
                            <?php endforeach;
                            if ($totalNotif === 0): ?>
                                <tr>
                                    <td colspan="4" class="px-5 py-10 text-center text-slate-400 text-sm">Belum ada penerima notifikasi.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <div class="px-6 py-3 border-t border-slate-100 bg-slate-50/50">
                        <p class="text-xs text-slate-400 flex items-center gap-1.5">
                            <i class="fas fa-info-circle text-blue-400"></i>
                            Baris biru = admin sistem (tidak dapat dihapus). Tambah penerima lain via tombol "Tambah Penerima".
                        </p>
                    </div>
                </div>
            </section>

        </main>
    </div>

    <!-- ===== MODALS ===== -->

    <!-- Modal: Add Recipient Notifikasi -->
    <!-- ===== MODAL: KONFIRMASI HAPUS SEMUA ===== -->
    <div id="deleteAllModal" class="modal-overlay fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-0 overflow-hidden">
            <!-- Header merah -->
            <div class="flex items-center gap-3 px-6 py-5" style="background:linear-gradient(135deg,#ef4444,#dc2626);">
                <div class="w-10 h-10 bg-white/20 rounded-xl flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-triangle-exclamation text-white text-lg"></i>
                </div>
                <div>
                    <h3 class="text-white font-extrabold text-base" id="deleteAllModalTitle">Hapus Semua Data</h3>
                    <p class="text-red-100 text-xs font-medium" id="deleteAllModalSub">Tindakan ini tidak dapat dibatalkan</p>
                </div>
            </div>
            <!-- Body -->
            <div class="px-6 py-5">
                <p class="text-slate-600 text-sm mb-1" id="deleteAllModalDesc"></p>
                <div class="mt-4 bg-red-50 border border-red-200 rounded-xl px-4 py-3 flex items-start gap-2.5">
                    <i class="fas fa-circle-exclamation text-red-400 text-sm mt-0.5 flex-shrink-0"></i>
                    <p class="text-red-700 text-xs font-semibold leading-relaxed">
                        Seluruh data akan dihapus secara permanen dari database dan <span class="font-black">tidak dapat dipulihkan</span>. Pastikan Anda sudah melakukan backup sebelum melanjutkan.
                    </p>
                </div>
                <!-- Konfirmasi ketik -->
                <div class="mt-4">
                    <label class="block text-slate-500 text-xs font-bold mb-1.5 uppercase tracking-wide">Ketik <span class="text-red-600 font-black">HAPUS</span> untuk konfirmasi</label>
                    <input type="text" id="deleteAllConfirmInput" placeholder="Ketik HAPUS di sini..."
                        oninput="checkDeleteAllInput()"
                        class="w-full border-2 border-slate-200 focus:border-red-400 rounded-xl px-4 py-2.5 text-sm font-bold outline-none transition">
                </div>
            </div>
            <!-- Footer -->
            <div class="flex items-center gap-3 px-6 pb-5">
                <button onclick="closeModal('deleteAllModal')" class="flex-1 px-4 py-2.5 rounded-xl border border-slate-200 text-slate-600 font-bold text-sm hover:bg-slate-50 transition">
                    Batal
                </button>
                <button id="deleteAllConfirmBtn" onclick="confirmDeleteAll()" disabled
                    class="flex-1 px-4 py-2.5 rounded-xl font-bold text-sm text-white transition flex items-center justify-center gap-2 opacity-40 cursor-not-allowed"
                    style="background:linear-gradient(135deg,#ef4444,#dc2626);">
                    <i class="fas fa-trash-can"></i>
                    <span id="deleteAllBtnLabel">Hapus Semua</span>
                </button>
            </div>
        </div>
    </div>

    <div id="addRecipientModal" class="modal-overlay fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 items-center justify-center p-4">
        <div class="bg-white w-full max-w-md rounded-2xl shadow-2xl overflow-hidden">
            <div class="bg-slate-800 px-7 py-5 flex justify-between items-center text-white">
                <h3 class="text-lg font-bold"><i class="fas fa-user-plus mr-2"></i>Tambah Penerima Notifikasi</h3>
                <button onclick="closeModal('addRecipientModal')" class="hover:opacity-70"><i class="fas fa-times text-xl"></i></button>
            </div>
            <form id="addRecipientForm" class="p-7 space-y-4">
                <input type="hidden" name="action" value="add_recipient">
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Nama</label>
                    <input type="text" name="rec_name" required class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-slate-200" placeholder="Nama penerima">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Email</label>
                    <input type="email" name="rec_email" required class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-slate-200" placeholder="email@example.com">
                </div>
                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" onclick="closeModal('addRecipientModal')" class="px-6 py-2.5 font-semibold text-slate-500 hover:bg-slate-100 rounded-xl text-sm">Batal</button>
                    <button type="submit" class="bg-slate-800 hover:bg-slate-900 text-white px-8 py-2.5 rounded-xl font-bold text-sm shadow">Simpan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Add Schedule -->
    <div id="addScheduleModal" class="modal-overlay fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 items-center justify-center p-4">
        <div class="bg-white w-full max-w-4xl rounded-2xl shadow-2xl overflow-hidden">
            <div class="bg-blue-600 px-7 py-5 flex justify-between items-center text-white">
                <h3 class="text-lg font-bold"><i class="fas fa-plus mr-2"></i>Tambah Schedule</h3>
                <button onclick="closeModal('addScheduleModal')" class="hover:opacity-70"><i class="fas fa-times text-xl"></i></button>
            </div>
            <form id="addScheduleForm" class="p-7 max-h-[80vh] overflow-y-auto">
                <input type="hidden" name="action" value="add_schedule">
                <?php echo renderScheduleFields('add', $plants); ?>
                <div class="flex justify-end gap-3 mt-6 pt-4 border-t border-slate-100">
                    <button type="button" onclick="closeModal('addScheduleModal')" class="px-6 py-2.5 font-semibold text-slate-500 hover:bg-slate-100 rounded-xl text-sm">Batal</button>
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-8 py-2.5 rounded-xl font-bold text-sm shadow">Simpan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Edit Schedule -->
    <div id="editScheduleModal" class="modal-overlay fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 items-center justify-center p-4">
        <div class="bg-white w-full max-w-4xl rounded-2xl shadow-2xl overflow-hidden">
            <div class="bg-amber-500 px-7 py-5 flex justify-between items-center text-white">
                <h3 class="text-lg font-bold"><i class="fas fa-edit mr-2"></i>Edit Schedule</h3>
                <button onclick="closeModal('editScheduleModal')" class="hover:opacity-70"><i class="fas fa-times text-xl"></i></button>
            </div>
            <form id="editScheduleForm" class="p-7 max-h-[80vh] overflow-y-auto">
                <input type="hidden" name="action" value="edit_schedule">
                <input type="hidden" name="edit_id" id="edit_id">
                <input type="hidden" name="dept_name" id="edit_dept_name_val">
                <input type="hidden" name="line_name" id="edit_line_name_val">
                <?php echo renderScheduleFields('edit', $plants); ?>
                <div class="flex justify-end gap-3 mt-6 pt-4 border-t border-slate-100">
                    <button type="button" onclick="closeModal('editScheduleModal')" class="px-6 py-2.5 font-semibold text-slate-500 hover:bg-slate-100 rounded-xl text-sm">Batal</button>
                    <button type="submit" class="bg-amber-500 hover:bg-amber-600 text-white px-8 py-2.5 rounded-xl font-bold text-sm shadow">Update</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Add Part -->
    <div id="addPartModal" class="modal-overlay fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 items-center justify-center p-4">
        <div class="bg-white w-full max-w-xl rounded-2xl shadow-2xl overflow-hidden">
            <div class="bg-emerald-600 px-7 py-5 flex justify-between items-center text-white">
                <h3 class="text-lg font-bold"><i class="fas fa-plus mr-2"></i>Tambah Part</h3>
                <button onclick="closeModal('addPartModal')" class="hover:opacity-70"><i class="fas fa-times text-xl"></i></button>
            </div>
            <form id="addPartForm" class="p-7 space-y-4">
                <input type="hidden" name="action" value="add_part">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">Item Code <span class="text-red-500">*</span></label>
                        <input type="text" name="item_code" required class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-100">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">Safety Stock</label>
                        <input type="number" name="safety_stock" min="0" value="0" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-100">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">Item Description</label>
                    <input type="text" name="item_description" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-100">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">Actual Stock</label>
                    <input type="number" name="actual_stock" min="0" value="0" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-100">
                </div>
                <div class="flex justify-end gap-3 mt-6 pt-4 border-t border-slate-100">
                    <button type="button" onclick="closeModal('addPartModal')" class="px-6 py-2.5 font-semibold text-slate-500 hover:bg-slate-100 rounded-xl text-sm">Batal</button>
                    <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white px-8 py-2.5 rounded-xl font-bold text-sm shadow">Simpan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Edit Part -->
    <div id="editPartModal" class="modal-overlay fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 items-center justify-center p-4">
        <div class="bg-white w-full max-w-xl rounded-2xl shadow-2xl overflow-hidden">
            <div class="bg-amber-500 px-7 py-5 flex justify-between items-center text-white">
                <h3 class="text-lg font-bold"><i class="fas fa-edit mr-2"></i>Edit Part</h3>
                <button onclick="closeModal('editPartModal')" class="hover:opacity-70"><i class="fas fa-times text-xl"></i></button>
            </div>
            <form id="editPartForm" class="p-7 space-y-4">
                <input type="hidden" name="action" value="edit_part">
                <input type="hidden" name="edit_id" id="ep_edit_id">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">Item Code <span class="text-red-500">*</span></label>
                        <input type="text" name="item_code" id="ep_item_code" required class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-amber-100">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">Safety Stock</label>
                        <input type="number" name="safety_stock" id="ep_safety_stock" min="0" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-amber-100">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">Item Description</label>
                    <input type="text" name="item_description" id="ep_item_description" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-amber-100">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">Actual Stock</label>
                    <input type="number" name="actual_stock" id="ep_actual_stock" min="0" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-amber-100">
                </div>
                <div class="flex justify-end gap-3 mt-6 pt-4 border-t border-slate-100">
                    <button type="button" onclick="closeModal('editPartModal')" class="px-6 py-2.5 font-semibold text-slate-500 hover:bg-slate-100 rounded-xl text-sm">Batal</button>
                    <button type="submit" class="bg-amber-500 hover:bg-amber-600 text-white px-8 py-2.5 rounded-xl font-bold text-sm shadow">Update</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Add User -->
    <div id="addUserModal" class="modal-overlay fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 items-center justify-center p-4">
        <div class="bg-white w-full max-w-md rounded-2xl shadow-2xl overflow-hidden">
            <div class="bg-slate-800 px-7 py-5 flex justify-between items-center text-white">
                <h3 class="text-lg font-bold"><i class="fas fa-user-plus mr-2"></i>Tambah User</h3>
                <button onclick="closeModal('addUserModal')" class="hover:opacity-70"><i class="fas fa-times text-xl"></i></button>
            </div>
            <form id="addUserForm" class="p-7 space-y-4">
                <input type="hidden" name="action" value="add_user">
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Username</label>
                    <input type="text" name="username" required class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-slate-200">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Email</label>
                    <input type="email" name="email" required class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-slate-200">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Password</label>
                    <input type="password" name="password" required class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-slate-200">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Role</label>
                    <select name="role" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none bg-white">
                        <option value="user" selected>User</option>
                    </select>
                </div>
                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" onclick="closeModal('addUserModal')" class="px-6 py-2.5 font-semibold text-slate-500 hover:bg-slate-100 rounded-xl text-sm">Batal</button>
                    <button type="submit" class="bg-slate-800 hover:bg-slate-900 text-white px-8 py-2.5 rounded-xl font-bold text-sm shadow">Simpan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Edit User -->
    <div id="editUserModal" class="modal-overlay fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 items-center justify-center p-4">
        <div class="bg-white w-full max-w-md rounded-2xl shadow-2xl overflow-hidden">
            <div class="bg-amber-500 px-7 py-5 flex justify-between items-center text-white">
                <h3 class="text-lg font-bold"><i class="fas fa-user-edit mr-2"></i>Edit User</h3>
                <button onclick="closeModal('editUserModal')" class="hover:opacity-70"><i class="fas fa-times text-xl"></i></button>
            </div>
            <form id="editUserForm" class="p-7 space-y-4">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="edit_id" id="eu_edit_id">
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Username</label>
                    <input type="text" name="username" id="eu_username" required class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-slate-200">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Email</label>
                    <input type="email" name="email" id="eu_email" required class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-slate-200">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Password Baru <span class="text-slate-400 font-normal normal-case">(kosongkan jika tidak diubah)</span></label>
                    <input type="password" name="password" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-slate-200">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Role</label>
                    <select name="role" id="eu_role" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none bg-white">
                        <option value="user">User</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" onclick="closeModal('editUserModal')" class="px-6 py-2.5 font-semibold text-slate-500 hover:bg-slate-100 rounded-xl text-sm">Batal</button>
                    <button type="submit" class="bg-amber-500 hover:bg-amber-600 text-white px-8 py-2.5 rounded-xl font-bold text-sm shadow">Update</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Toast -->
    <div id="toast" class="fixed bottom-6 right-6 z-[9999] hidden">
        <div id="toastInner" class="px-5 py-3 rounded-xl shadow-lg text-sm font-semibold text-white flex items-center gap-3"></div>
    </div>

    <script>
        // ===== NAVIGATION =====
        function switchSection(name) {
            document.querySelectorAll('.section-panel').forEach(el => el.classList.add('hidden'));
            document.querySelectorAll('[id^="nav-"]').forEach(el => el.classList.remove('active'));
            document.getElementById('section-' + name).classList.remove('hidden');
            document.getElementById('nav-' + name).classList.add('active');
        }

        // ===== MODAL =====
        function openModal(id) {
            document.getElementById(id).classList.add('open');
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('open');
        }

        // ===== TOAST =====
        function showToast(msg, type = 'success') {
            const t = document.getElementById('toast');
            const inner = document.getElementById('toastInner');
            inner.className = `px-5 py-3 rounded-xl shadow-lg text-sm font-semibold text-white flex items-center gap-3 ${type === 'success' ? 'bg-emerald-600' : 'bg-red-600'}`;
            inner.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i> ${msg}`;
            t.classList.remove('hidden');
            setTimeout(() => t.classList.add('hidden'), 3500);
        }

        // ===== AJAX POST =====
        async function postForm(formId, successMsg) {
            const form = document.getElementById(formId);
            const res = await fetch('', {
                method: 'POST',
                body: new FormData(form)
            });
            const result = await res.json();
            if (result.status === 'success') {
                showToast(result.message || successMsg);
                setTimeout(() => location.reload(), 1000);
            } else showToast(result.message || 'Terjadi kesalahan', 'error');
        }

        // ===== FILTER TABLE =====
        function filterTable(tableId, query) {
            const q = query.toLowerCase();
            document.querySelectorAll(`#${tableId} tbody tr`).forEach(tr => {
                tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        }

        // ===== SCHEDULE =====
        async function editSchedule(id) {
            const res = await fetch(`?get_schedule=${id}`);
            const d = await res.json();
            document.getElementById('edit_id').value = d.id;
            document.getElementById('edit_machine_name').value = d.machine_name;
            document.getElementById('edit_process_machine').value = d.process_machine;
            document.getElementById('edit_name_unit').value = d.name_unit;
            document.getElementById('edit_maintenance_point').value = d.maintenance_point;
            document.getElementById('edit_use_date').value = d.use_date;
            document.getElementById('edit_interval_month').value = d.interval_month;
            document.getElementById('edit_change_date_plan').value = d.change_date_plan;
            document.getElementById('edit_reminder_activity').value = d.reminder_activity;
            document.getElementById('edit_reminder_request_part').value = d.reminder_request_part;
            document.getElementById('edit_dept_display').value = d.department;
            document.getElementById('edit_line_display').value = d.line;
            document.getElementById('edit_op_display').value = d.operation_process;
            document.getElementById('edit_dept_name_val').value = d.department;
            document.getElementById('edit_line_name_val').value = d.line;
            openModal('editScheduleModal');
        }

        document.getElementById('addScheduleForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            await postForm('addScheduleForm', 'Schedule ditambahkan');
        });
        document.getElementById('editScheduleForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            await postForm('editScheduleForm', 'Schedule diupdate');
        });

        // ===== PART =====
        function editPartAdmin(p) {
            document.getElementById('ep_edit_id').value = p.id;
            document.getElementById('ep_item_code').value = p.item_code || '';
            document.getElementById('ep_item_description').value = p.item_description || '';
            document.getElementById('ep_actual_stock').value = p.actual_stock || 0;
            document.getElementById('ep_safety_stock').value = p.safety_stock || 0;
            openModal('editPartModal');
        }

        document.getElementById('addPartForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            await postForm('addPartForm', 'Part ditambahkan');
        });
        document.getElementById('editPartForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            await postForm('editPartForm', 'Part diupdate');
        });

        // ===== SCHEDULE TABS =====
        function switchSchedTab(tab) {
            const isPred = tab === 'predictive';
            const indicator = document.getElementById('schedTabIndicator');
            indicator.style.transform = isPred ? 'translateX(0)' : 'translateX(calc(100% + 4px))';
            indicator.style.background = isPred ? 'linear-gradient(135deg,#2563eb,#1d4ed8)' : 'linear-gradient(135deg,#0f766e,#0d9488)';
            document.getElementById('schedTabPred').className = `relative z-10 flex-1 flex items-center justify-center gap-2 py-3 px-6 font-bold text-sm rounded-xl transition-all duration-300 ${isPred ? 'text-white' : 'text-slate-500'}`;
            document.getElementById('schedTabPrev').className = `relative z-10 flex-1 flex items-center justify-center gap-2 py-3 px-6 font-bold text-sm rounded-xl transition-all duration-300 ${!isPred ? 'text-white' : 'text-slate-500'}`;
            // Tabel
            document.getElementById('schedPredContent').classList.toggle('hidden', !isPred);
            document.getElementById('schedPrevContent').classList.toggle('hidden', isPred);
            // Stat cards ikut berganti sesuai tab
            document.getElementById('predStatCards').classList.toggle('hidden', !isPred);
            document.getElementById('prevStatCards').classList.toggle('hidden', isPred);
        }

        // ===== HISTORY TABS =====
        function switchHistTab(tab) {
            const isPred = tab === 'predictive';
            const indicator = document.getElementById('histTabIndicator');
            indicator.style.transform = isPred ? 'translateX(0)' : 'translateX(calc(100% + 4px))';
            document.getElementById('histTabPred').className = `relative z-10 flex-1 flex items-center justify-center gap-2 py-3 px-6 font-bold text-sm rounded-xl transition-all duration-300 ${isPred ? 'text-white' : 'text-slate-500'}`;
            document.getElementById('histTabPrev').className = `relative z-10 flex-1 flex items-center justify-center gap-2 py-3 px-6 font-bold text-sm rounded-xl transition-all duration-300 ${!isPred ? 'text-white' : 'text-slate-500'}`;
            document.getElementById('histPredContent').classList.toggle('hidden', !isPred);
            document.getElementById('histPrevContent').classList.toggle('hidden', isPred);
        }

        // ===== USER =====
        function editUser(u) {
            document.getElementById('eu_edit_id').value = u.id;
            document.getElementById('eu_username').value = u.username;
            document.getElementById('eu_email').value = u.email_user;
            document.getElementById('eu_role').value = u.role;
            document.getElementById('editUserForm').querySelector('[name="password"]').value = '';
            openModal('editUserModal');
        }

        async function toggleUser(id, isActive) {
            const label = isActive ? 'nonaktifkan' : 'aktifkan';
            if (!confirm(`Yakin ingin ${label} user ini?`)) return;
            const fd = new FormData();
            fd.append('action', 'toggle_user');
            fd.append('id', id);
            const res = await fetch('', {
                method: 'POST',
                body: fd
            });
            const result = await res.json();
            if (result.status === 'success') {
                showToast(result.message);
                setTimeout(() => location.reload(), 1000);
            } else showToast(result.message, 'error');
        }

        document.getElementById('addUserForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            await postForm('addUserForm', 'User ditambahkan');
        });
        document.getElementById('editUserForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            await postForm('editUserForm', 'User diupdate');
        });

        // ===== RECIPIENT =====
        document.getElementById('addRecipientForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            await postForm('addRecipientForm', 'Penerima ditambahkan');
            closeModal('addRecipientModal');
        });

        // ===== DELETE =====
        // ── Delete All ───────────────────────────────────────────────────────────
        let _deleteAllTarget = null;

        const _deleteAllConfig = {
            schedule: {
                title: 'Hapus Semua Schedule',
                sub: 'Schedule Predictive & Preventive akan dihapus',
                desc: 'Anda akan menghapus <strong>seluruh data schedule predictive dan preventive</strong>. Semua jadwal maintenance akan hilang dari sistem.',
                actions: ['delete_all_schedule', 'delete_all_prev_schedule'],
                btnLabel: 'Hapus Semua Schedule',
            },
            parts: {
                title: 'Hapus Semua Part',
                sub: 'Seluruh data Part Availability akan dihapus',
                desc: 'Anda akan menghapus <strong>seluruh data part availability</strong>. Semua data stok sparepart akan hilang dari sistem.',
                actions: ['delete_all_parts'],
                btnLabel: 'Hapus Semua Part',
            },
            history: {
                title: 'Hapus Semua History',
                sub: 'History Predictive & Preventive akan dihapus',
                desc: 'Anda akan menghapus <strong>seluruh history maintenance predictive dan preventive</strong>. Semua log aktivitas akan hilang dari sistem.',
                actions: ['delete_all_history', 'delete_all_history_prev'],
                btnLabel: 'Hapus Semua History',
            },
        };

        function openDeleteAllModal(target) {
            const cfg = _deleteAllConfig[target];
            if (!cfg) return;
            _deleteAllTarget = target;
            document.getElementById('deleteAllModalTitle').textContent = cfg.title;
            document.getElementById('deleteAllModalSub').textContent = cfg.sub;
            document.getElementById('deleteAllModalDesc').innerHTML = cfg.desc;
            document.getElementById('deleteAllBtnLabel').textContent = cfg.btnLabel;
            document.getElementById('deleteAllConfirmInput').value = '';
            const btn = document.getElementById('deleteAllConfirmBtn');
            btn.disabled = true;
            btn.classList.add('opacity-40', 'cursor-not-allowed');
            openModal('deleteAllModal');
        }

        function checkDeleteAllInput() {
            const val = document.getElementById('deleteAllConfirmInput').value.trim();
            const btn = document.getElementById('deleteAllConfirmBtn');
            if (val === 'HAPUS') {
                btn.disabled = false;
                btn.classList.remove('opacity-40', 'cursor-not-allowed');
            } else {
                btn.disabled = true;
                btn.classList.add('opacity-40', 'cursor-not-allowed');
            }
        }

        async function confirmDeleteAll() {
            const cfg = _deleteAllConfig[_deleteAllTarget];
            if (!cfg) return;
            const btn = document.getElementById('deleteAllConfirmBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menghapus...';

            try {
                for (const action of cfg.actions) {
                    const fd = new FormData();
                    fd.append('action', action);
                    const res = await fetch('', {
                        method: 'POST',
                        body: fd
                    });
                    const json = await res.json();
                    if (json.status !== 'success') throw new Error(json.message);
                }
                closeModal('deleteAllModal');
                showToast(cfg.btnLabel + ' berhasil!', 'success');
                setTimeout(() => location.reload(), 900);
            } catch (err) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-trash-can"></i> <span>' + cfg.btnLabel + '</span>';
                showToast('Gagal: ' + err.message, 'error');
            }
        }
        // ─────────────────────────────────────────────────────────────────────

        async function deleteRecord(action, id) {
            if (!confirm('Yakin ingin menghapus data ini?')) return;
            const fd = new FormData();
            fd.append('action', action);
            fd.append('id', id);
            const res = await fetch('', {
                method: 'POST',
                body: fd
            });
            const result = await res.json();
            if (result.status === 'success') {
                showToast(result.message);
                setTimeout(() => location.reload(), 1000);
            } else showToast(result.message, 'error');
        }

        // ===== NOTIFIKASI =====
        let recipientCount = <?= count($notif_recipients) ?: 1 ?>;

        function addRecipient() {
            const list = document.getElementById('recipientList');
            const div = document.createElement('div');
            div.className = 'flex gap-3 items-center recipient-row';
            div.innerHTML = `
        <input type="text" name="recipients[${recipientCount}][name]" placeholder="Nama" class="flex-1 border border-slate-200 rounded-lg px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-blue-100">
        <input type="email" name="recipients[${recipientCount}][email]" placeholder="Email" class="flex-1 border border-slate-200 rounded-lg px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-blue-100">
        <button type="button" onclick="this.closest('.recipient-row').remove()" class="w-8 h-8 bg-red-100 text-red-500 rounded-lg hover:bg-red-200 transition text-xs flex-shrink-0"><i class="fas fa-times"></i></button>`;
            list.appendChild(div);
            recipientCount++;
        }

        async function saveNotif() {
            const form = document.getElementById('notifForm');
            const fd = new FormData(form);
            fd.append('action', 'save_notification');
            const res = await fetch('', {
                method: 'POST',
                body: fd
            });
            const result = await res.json();
            showToast(result.message, result.status === 'success' ? 'success' : 'error');
        }

        // Auto-calculate plan date
        function checkAutoCalc(prefix) {
            const useDate = document.getElementById(`${prefix}_use_date`)?.value;
            const interval = parseInt(document.getElementById(`${prefix}_interval_month`)?.value || 0);
            const planInput = document.getElementById(`${prefix}_change_date_plan`);
            if (useDate && interval && planInput) {
                let d = new Date(useDate);
                d.setMonth(d.getMonth() + interval);
                planInput.value = d.toISOString().split('T')[0];
            }
        }
    </script>

</body>

</html>

<?php
function renderScheduleFields($prefix, $plants)
{
    return <<<HTML
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-5">
        <div>
            <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">Department</label>
            <input type="text" id="{$prefix}_dept_display" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm" readonly>
        </div>
        <div>
            <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">Line</label>
            <input type="text" id="{$prefix}_line_display" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm" readonly>
        </div>
        <div>
            <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">Process</label>
            <input type="text" id="{$prefix}_op_display" name="operation_process" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm" readonly>
        </div>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-5">
        <div>
            <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">Machine Name</label>
            <input type="text" name="machine_name" id="{$prefix}_machine_name" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-blue-100" required>
        </div>
        <div>
            <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">Process Machine</label>
            <input type="text" name="process_machine" id="{$prefix}_process_machine" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-blue-100">
        </div>
        <div>
            <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">Unit Name</label>
            <input type="text" name="name_unit" id="{$prefix}_name_unit" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-blue-100">
        </div>
    </div>
    <div class="mb-5">
        <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">Maintenance Point</label>
        <input type="text" name="maintenance_point" id="{$prefix}_maintenance_point" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-blue-100" required>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
        <div>
            <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">Use Date</label>
            <input type="date" name="use_date" id="{$prefix}_use_date" onchange="checkAutoCalc('{$prefix}')" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none">
        </div>
        <div>
            <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">Interval (Bulan)</label>
            <input type="number" name="interval_month" id="{$prefix}_interval_month" onchange="checkAutoCalc('{$prefix}')" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none">
        </div>
    </div>
    <div class="bg-blue-50 border border-blue-100 p-5 rounded-xl">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-xs font-bold text-blue-700 uppercase mb-1.5">Change Date Plan</label>
                <input type="date" name="change_date_plan" id="{$prefix}_change_date_plan" class="w-full bg-white border border-blue-200 rounded-xl px-4 py-2.5 text-sm font-bold outline-none" required>
            </div>
            <div>
                <label class="block text-xs font-bold text-blue-700 uppercase mb-1.5">Reminder Activity (Hari)</label>
                <input type="number" name="reminder_activity" id="{$prefix}_reminder_activity" class="w-full bg-white border border-blue-200 rounded-xl px-4 py-2.5 text-sm outline-none">
            </div>
            <div>
                <label class="block text-xs font-bold text-blue-700 uppercase mb-1.5">Reminder Part (Hari)</label>
                <input type="number" name="reminder_request_part" id="{$prefix}_reminder_request_part" class="w-full bg-white border border-blue-200 rounded-xl px-4 py-2.5 text-sm outline-none">
            </div>
        </div>
    </div>
HTML;
}

function renderPartFields($prefix)
{
    return <<<HTML
    <div class="space-y-4">
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">Kode Part</label>
                <input type="text" name="part_code" id="{$prefix}_part_code" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-100" required>
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">Unit</label>
                <input type="text" name="unit" id="{$prefix}_unit" placeholder="pcs, set, ltr..." class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-100">
            </div>
        </div>
        <div>
            <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">Nama Part</label>
            <input type="text" name="part_name" id="{$prefix}_part_name" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-100" required>
        </div>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">Stok</label>
                <input type="number" name="stock" id="{$prefix}_stock" min="0" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-100">
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">Min. Stok</label>
                <input type="number" name="minimum_stock" id="{$prefix}_minimum_stock" min="0" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-100">
            </div>
        </div>
        <div>
            <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">Lokasi</label>
            <input type="text" name="location" id="{$prefix}_location" placeholder="Rak A1, Gudang B..." class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-100">
        </div>
    </div>
HTML;
}
?>