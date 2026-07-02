<?php
include 'config.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'user') {
    header('Location: login_user.php');
    exit;
}

if (!function_exists('formatDate') || !function_exists('calculateRemainingDays')) {
    die("Error: Helper functions tidak ditemukan di config.php");
}

// ── Hitung tanggal Change Date Plan (jadwal berikutnya) berdasarkan basis pilihan user ──
// $basis   : 'schedule' = dari Change Date Plan lama (jadwal awal, tidak bergeser),
//            'actual'   = dari tanggal aktual pekerjaan (default),
//            'report'   = dari tanggal pengisian laporan (hari ini).
// $oldChangeDatePlan : Change Date Plan yang tersimpan sebelumnya di schedule (basis 'schedule').
// $actualDate        : tanggal aktual pekerjaan yang diinput user di form report.
if (!function_exists('computeNextChangeDate')) {
    function computeNextChangeDate(string $basis, ?string $oldChangeDatePlan, string $actualDate, int $intervalMonth): ?string
    {
        if ($intervalMonth <= 0) return null;

        switch ($basis) {
            case 'schedule':
                $baseDateStr = !empty($oldChangeDatePlan) ? $oldChangeDatePlan : $actualDate;
                break;
            case 'report':
                $baseDateStr = date('Y-m-d');
                break;
            case 'actual':
            default:
                $baseDateStr = $actualDate;
                break;
        }

        $dt = new DateTime($baseDateStr);
        $dt->modify("+{$intervalMonth} months");
        return $dt->format('Y-m-d');
    }
}

$todayStr = date('Y-m-d');

// Ambil nama user yang sedang login
$stmtUser = $pdo->prepare("SELECT username FROM users WHERE id = ?");
$stmtUser->execute([$_SESSION['user_id']]);
$currentUser = $stmtUser->fetch(PDO::FETCH_ASSOC);
$displayName = $currentUser['username'] ?? 'User';

// Ambil daftar teknisi aktif (dipakai untuk dropdown "Teknisi" di modal Report Preventive)
$technicianList = [];
try {
    $stmtTech = $pdo->prepare("SELECT id, name FROM technician WHERE is_active = 1 ORDER BY name ASC");
    $stmtTech->execute();
    $technicianList = $stmtTech->fetchAll(PDO::FETCH_ASSOC);
    $stmtTech->closeCursor();
} catch (\Exception $e) {
    error_log('[Dashboard] Gagal ambil data technician: ' . $e->getMessage());
}

// ── Step 1: Selalu update remaining_day setiap dashboard dibuka ──────────────
try {
    $s1 = $pdo->prepare("
        UPDATE schedules
        SET remaining_day = DATEDIFF(change_date_plan, CURDATE())
        WHERE change_date_plan IS NOT NULL
    ");
    $s1->execute();
    $s1->closeCursor();
    // Auto-reset: jika status 'done' tapi remaining_day sudah masuk window reminder lagi
    // → ubah kembali ke 'soon' dan buka part_order/part_availability
    $s2 = $pdo->prepare("
        UPDATE schedules
        SET maintenance_status = 'soon',
            part_order         = 'open',
            part_availability  = 'open'
        WHERE maintenance_status = 'done'
          AND change_date_plan IS NOT NULL
          AND DATEDIFF(change_date_plan, CURDATE()) <= reminder_activity
          AND DATEDIFF(change_date_plan, CURDATE()) >= 0
    ");
    $s2->execute();
    $s2->closeCursor();
    // NOTE: Auto-open dihapus agar user bisa mengubah part_order/part_availability ke 'close'
    // secara manual meskipun status masih reminder atau alert (sebelum hari-H).
    // Part order/availability hanya di-open otomatis saat transisi 'done' → 'soon' (query di atas).
} catch (\Exception $e) {
    error_log('[Dashboard] Gagal update remaining_day: ' . $e->getMessage());
}

// ── Step 2: Kirim email — 1x per hari, cek via notification_log di DB ────────
// Deduplication via DB agar berlaku lintas login/logout dan lintas user.
// PENTING: $sentToday TIDAK di-prefetch ke array PHP karena menyebabkan race condition —
// jika 2 user login hampir bersamaan, keduanya akan membaca array kosong yang sama
// sebelum salah satunya sempat logSent(), sehingga email terkirim dua kali.
// Solusi: setiap fungsi process*() sudah memanggil alreadySentToday() secara langsung
// ke DB saat dipanggil, ditambah GET_LOCK() MySQL di tryLockAndSend() sebagai
// distributed lock — hanya 1 proses PHP yang bisa eksekusi per kategori per hari.
$reminderFile = __DIR__ . '/send_reminder.php';
if (file_exists($reminderFile)) {
    require_once $reminderFile;

    // Predictive — setiap fungsi punya alreadySentToday() + GET_LOCK() sendiri
    if (function_exists('processReminderByThreshold')) {
        try {
            processReminderByThreshold($pdo);
        } catch (\Exception $e) {
            error_log('[Dashboard] Error batch-reminder: ' . $e->getMessage());
        }
    }
    if (function_exists('processSevenDayReminders')) {
        try {
            processSevenDayReminders($pdo);
        } catch (\Exception $e) {
            error_log('[Dashboard] Error batch-alert7: ' . $e->getMessage());
        }
    }
    if (function_exists('processOverdueReminders')) {
        try {
            processOverdueReminders($pdo);
        } catch (\Exception $e) {
            error_log('[Dashboard] Error batch-overdue: ' . $e->getMessage());
        }
    }

    // Preventive — sama, tiap fungsi handle deduplication sendiri
    if (function_exists('processPrevReminderByThreshold')) {
        try {
            processPrevReminderByThreshold($pdo);
        } catch (\Exception $e) {
            error_log('[Dashboard] Error prev-batch-reminder: ' . $e->getMessage());
        }
    }
    if (function_exists('processPrevSevenDayReminders')) {
        try {
            processPrevSevenDayReminders($pdo);
        } catch (\Exception $e) {
            error_log('[Dashboard] Error prev-batch-alert7: ' . $e->getMessage());
        }
    }
    if (function_exists('processPrevOverdueReminders')) {
        try {
            processPrevOverdueReminders($pdo);
        } catch (\Exception $e) {
            error_log('[Dashboard] Error prev-batch-overdue: ' . $e->getMessage());
        }
    }
}

// ==================== API ENDPOINTS (AJAX) ====================

if (isset($_GET['get_lines'])) {
    // Ambil distinct line dari machine_list berdasarkan department (nama, bukan ID)
    $dept = $_GET['get_lines'] ?? '';
    $stmt = $pdo->prepare("SELECT DISTINCT `line` FROM machine_list WHERE department = ? ORDER BY `line`");
    $stmt->execute([$dept]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Kembalikan dalam format {id, line_name} agar JS tidak perlu berubah banyak
    echo json_encode(array_map(fn($r) => ['id' => $r['line'], 'line_name' => $r['line']], $rows));
    exit;
}

if (isset($_GET['get_lines_plant'])) {
    // Endpoint khusus addMachineModal — masih pakai tabel line lama
    $stmt = $pdo->prepare("SELECT id, line_name FROM line WHERE plant_id = ?");
    $stmt->execute([$_GET['get_lines_plant']]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

if (isset($_GET['get_ops'])) {
    // Ambil distinct op + machine dari machine_list berdasarkan department+line
    // Format: ?get_ops=LINE_NAME&dept=DEPT_NAME
    $dept = $_GET['dept'] ?? '';
    $line = $_GET['get_ops'] ?? '';
    $stmt = $pdo->prepare("SELECT DISTINCT op, machine_name, machine_type FROM machine_list WHERE department = ? AND `line` = ? ORDER BY op, machine_name");
    $stmt->execute([$dept, $line]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Kembalikan format {id, operation_process, machine_name, process_machine} agar JS tetap kompatibel
    echo json_encode(array_map(fn($r) => [
        'id'                => $r['op'],
        'operation_process' => $r['op'],
        'machine_name'      => $r['machine_name'],
        'process_machine'   => $r['machine_type'],
    ], $rows));
    exit;
}

if (isset($_GET['get_schedule'])) {
    $stmt = $pdo->prepare("SELECT * FROM schedules WHERE id = ?");
    $stmt->execute([$_GET['get_schedule']]);
    echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
    exit;
}

if (isset($_GET['get_prev_schedule'])) {
    $stmt = $pdo->prepare("SELECT * FROM schedules_preventive WHERE id = ?");
    $stmt->execute([$_GET['get_prev_schedule']]);
    echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
    exit;
}

// ==================== SIMPAN / UPDATE ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $change_date_plan = $_POST['change_date_plan'] ?? null;
        $remaining_day    = calculateRemainingDays($change_date_plan);

        if ($_POST['action'] === 'add') {
            // Tentukan status awal berdasarkan remaining_day
            $remAct_add    = (int)($_POST['reminder_activity'] ?? 0);
            $initPartStatus = ($remaining_day !== null && (
                $remaining_day <= 0 ||                                      // overdue
                ($remaining_day >= 1 && $remaining_day <= 7) ||             // alert
                ($remAct_add > 0 && $remaining_day <= $remAct_add)          // reminder
            )) ? 'open' : 'close';
            $initMaintStatus = ($initPartStatus === 'open') ? 'soon' : 'done';

            $stmt = $pdo->prepare("INSERT INTO schedules
                (department, line, operation_process, machine_name, process_machine, name_unit,
                 maintenance_point, interval_month, use_date, change_date_plan,
                 reminder_activity, remaining_day, maintenance_status, part_order, part_availability)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $_POST['dept_name']            ?? ($_POST['department'] ?? ''),  // Nama dept string
                $_POST['line_name']            ?? ($_POST['line']       ?? ''),  // Nama line string
                $_POST['operation_process']    ?? '',
                $_POST['machine_name']         ?? '',
                $_POST['process_machine']      ?? '',
                $_POST['name_unit']            ?? '',
                $_POST['maintenance_point']    ?? '',
                (int)($_POST['interval_month']       ?? 0),
                $_POST['use_date']             ?? null,
                $change_date_plan,
                (int)($_POST['reminder_activity']    ?? 0),
                $remaining_day,
                $initMaintStatus,
                $initPartStatus,
                $initPartStatus,
            ]);
            $newId       = (int)$pdo->lastInsertId();
            $needsEmail  = ($remaining_day !== null && (
                $remaining_day <= 0 ||
                $remaining_day <= 7 ||
                ($remAct_add > 0 && $remaining_day <= $remAct_add)
            ));
            if ($needsEmail && $newId > 0) {
                $reminderFile = __DIR__ . '/send_reminder.php';
                if (file_exists($reminderFile)) {
                    if (!function_exists('processReminderByThreshold')) require_once $reminderFile;
                    // Kirim email hanya untuk schedule baru ini ke admin
                    if (function_exists('sendNewScheduleAlert')) sendNewScheduleAlert($pdo, $newId);
                }
            }
            echo json_encode(['status' => 'success', 'message' => 'Data berhasil ditambah']);
            exit;
        }

        if ($_POST['action'] === 'edit') {
            $editId  = (int)($_POST['edit_id'] ?? 0);
            $remAct  = (int)($_POST['reminder_activity'] ?? 0);

            // Ambil status saat ini dari DB
            $currEditRow = $pdo->prepare("SELECT maintenance_status FROM schedules WHERE id = ?");
            $currEditRow->execute([$editId]);
            $currEditStatus = $currEditRow->fetchColumn() ?: 'done';

            // Auto-hitung maintenance_status:
            // Masuk window (overdue/alert/reminder) → 'soon'
            // Keluar window (aman) → 'done'
            // Jika sudah 'soon' tapi tanggal diubah keluar window → reset ke 'done'
            $editInWindow = ($remaining_day !== null && (
                $remaining_day <= 0 ||
                ($remaining_day >= 1 && $remaining_day <= 7) ||
                ($remAct > 0 && $remaining_day <= $remAct)
            ));
            $autoEditMaintStatus = $editInWindow ? 'soon' : 'done';

            // Auto-open part_order & part_availability HANYA saat transisi done → soon
            // Jika sebelumnya 'done' dan sekarang masuk kondisi kritis → paksa open (1x)
            // Selain itu → ikut input manual user
            $wasSecure = ($currEditStatus === 'done');

            if ($editInWindow && $wasSecure) {
                // Transisi done → soon: auto-open sekali
                $part_order = 'open';
                $part_avail = 'open';
            } else {
                // Sudah 'soon' sebelumnya atau kondisi secure: ikut input manual user
                $part_order = $_POST['part_order']        ?? 'close';
                $part_avail = $_POST['part_availability'] ?? 'close';
            }

            $stmt = $pdo->prepare("UPDATE schedules SET
                department = ?, line = ?, operation_process = ?, machine_name = ?,
                process_machine = ?, name_unit = ?, maintenance_point = ?,
                interval_month = ?, use_date = ?, change_date_plan = ?,
                reminder_activity = ?, remaining_day = ?,
                part_order = ?, part_availability = ?,
                maintenance_status = ?
                WHERE id = ?");
            $stmt->execute([
                $_POST['dept_name']            ?? ($_POST['department'] ?? ''),
                $_POST['line_name']            ?? ($_POST['line']       ?? ''),
                $_POST['operation_process']    ?? '',
                $_POST['machine_name']         ?? '',
                $_POST['process_machine']      ?? '',
                $_POST['name_unit']            ?? '',
                $_POST['maintenance_point']    ?? '',
                (int)($_POST['interval_month']       ?? 0),
                $_POST['use_date']             ?? null,
                $change_date_plan,
                (int)($_POST['reminder_activity']    ?? 0),
                $remaining_day,
                $part_order,
                $part_avail,
                $autoEditMaintStatus,
                $editId,
            ]);
            // ── Kirim notifikasi email jika data yang diedit masuk kondisi reminder ──
            $needsEmail = ($remaining_day !== null && $editId > 0 && (
                $remaining_day <= 0 ||
                ($remaining_day >= 1 && $remaining_day <= 7) ||
                ($remAct > 0 && $remaining_day > 7 && $remaining_day <= $remAct)
            ));
            if ($needsEmail) {
                $reminderFile = __DIR__ . '/send_reminder.php';
                if (file_exists($reminderFile)) {
                    if (!function_exists('sendEditedScheduleAlert')) require_once $reminderFile;
                    if (function_exists('sendEditedScheduleAlert')) {
                        sendEditedScheduleAlert($pdo, $editId, $remaining_day);
                    }
                }
            }
            echo json_encode(['status' => 'success', 'message' => 'Data berhasil diupdate']);
            exit;
        }
        // --- ADD MACHINE & OP_PROCESS ---
        if ($_POST['action'] === 'add_machine') {
            $plant_id  = (int)($_POST['plant_id']  ?? 0);
            $line_id   = (int)($_POST['line_id']   ?? 0);
            $op_name   = trim($_POST['operation_process'] ?? '');
            $mach_name = trim($_POST['machine_name']      ?? '');
            $proc_mach = trim($_POST['process_machine']   ?? '');

            if (!$plant_id || !$line_id || $op_name === '' || $mach_name === '') {
                echo json_encode(['status' => 'error', 'message' => 'Field wajib belum lengkap']);
                exit;
            }

            // Insert ke op_process jika belum ada
            // op_process punya FK: plant (-> plants.id) DAN line (-> line.id)
            $chkOp = $pdo->prepare("SELECT id FROM op_process WHERE operation_process = ? AND line = ? AND plant = ?");
            $chkOp->execute([$op_name, $line_id, $plant_id]);
            $opId = $chkOp->fetchColumn();

            if (!$opId) {
                $insOp = $pdo->prepare("INSERT INTO op_process (operation_process, plant, line) VALUES (?, ?, ?)");
                $insOp->execute([$op_name, $plant_id, $line_id]);
                $opId = $pdo->lastInsertId();
            }

            // Insert ke machines
            $insMach = $pdo->prepare("INSERT INTO machines (machine_name, process_machine, process_id) VALUES (?, ?, ?)");
            $insMach->execute([$mach_name, $proc_mach, $opId]);

            echo json_encode(['status' => 'success', 'message' => 'Machine berhasil ditambahkan']);
            exit;
        }

        if ($_POST['action'] === 'submit_report') {
            $schedId      = (int)($_POST['schedule_id'] ?? 0);
            $note         = trim($_POST['note'] ?? '');
            $actualDate   = $_POST['actual_date'] ?? date('Y-m-d'); // tanggal aktual pekerjaan
            $photoPath    = null;

            // Upload foto jika ada
            if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/uploads/reports/';
                if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
                $ext       = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                $allowExt  = ['jpg', 'jpeg', 'png', 'webp'];
                if (in_array($ext, $allowExt)) {
                    $fname     = 'report_' . $schedId . '_' . time() . '.' . $ext;
                    $photoPath = 'uploads/reports/' . $fname;
                    move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $fname);
                }
            }

            // Ambil data schedule
            $rowStmt = $pdo->prepare("SELECT * FROM schedules WHERE id = ?");
            $rowStmt->execute([$schedId]);
            $sched   = $rowStmt->fetch(PDO::FETCH_ASSOC);

            if (!$sched) {
                echo json_encode(['status' => 'error', 'message' => 'Schedule tidak ditemukan']);
                exit;
            }

            // ── Validasi: part_order dan part_availability harus CLOSE keduanya ──
            $currentPartOrder = $sched['part_order'] ?? 'close';
            $currentPartAvail = $sched['part_availability'] ?? 'close';
            if ($currentPartOrder === 'open' || $currentPartAvail === 'open') {
                echo json_encode([
                    'status'  => 'error',
                    'message' => 'Report tidak dapat disubmit. Part Order dan Part Availability harus berstatus CLOSE terlebih dahulu. Ubah status part melalui tombol Edit sebelum submit report.',
                ]);
                exit;
            }

            // ── Hitung use_date & change_date_plan baru ──
            // use_date (Last Change) selalu = tanggal aktual pekerjaan.
            // change_date_plan (jadwal berikutnya) dihitung sesuai basis pilihan user:
            // 'schedule' = dari Change Date Plan lama, 'actual' = dari tanggal aktual
            // pekerjaan (default), 'report' = dari tanggal pengisian laporan (hari ini).
            $intervalMonth   = (int)($sched['interval_month'] ?? 0);
            $newUseDate      = $actualDate; // last change = tanggal aktual pekerjaan
            $nextBasis       = $_POST['next_basis'] ?? 'actual';
            if (!in_array($nextBasis, ['schedule', 'actual', 'report'], true)) {
                $nextBasis = 'actual';
            }
            $newChangePlan   = computeNextChangeDate($nextBasis, $sched['change_date_plan'] ?? null, $actualDate, $intervalMonth);
            $newRemainingDay = $newChangePlan ? (int)(new DateTime())->diff(new DateTime($newChangePlan))->days * ((new DateTime($newChangePlan) >= new DateTime()) ? 1 : -1) : null;
            // Hitung ulang pakai DATEDIFF logic
            if ($newChangePlan) {
                $now = new DateTime('today');
                $cdp = new DateTime($newChangePlan);
                $diff = (int)$now->diff($cdp)->days;
                $newRemainingDay = ($cdp >= $now) ? $diff : -$diff;
            }

            // ── department/line sudah berupa nama string langsung ──
            $histDept = $sched['department'];
            $histLine = $sched['line'];

            $teknisi = trim($_POST['teknisi'] ?? '');

            // ── Simpan ke history_maintenance ──
            $pdo->prepare("INSERT INTO history_maintenance
                (schedule_id, department, line, operation_process, machine_name,
                 process_machine, name_unit, maintenance_point, change_date_plan,
                 note, photo_path, teknisi, reported_by, reported_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
                ->execute([
                    $schedId,
                    $histDept,
                    $histLine,
                    $sched['operation_process'],
                    $sched['machine_name'],
                    $sched['process_machine'],
                    $sched['name_unit'],
                    $sched['maintenance_point'],
                    $sched['change_date_plan'],
                    $note,
                    $photoPath,
                    $teknisi,
                    $_SESSION['user_id'] ?? null,
                ]);

            // ── Update schedule:
            //    - maintenance_status → 'done'
            //    - part_order & part_availability → 'close' (otomatis saat done)
            //    - use_date (last change) → tanggal aktual pekerjaan
            //    - change_date_plan (next change) → last change + interval
            //    - remaining_day → dihitung ulang dari change_date_plan baru
            $pdo->prepare("UPDATE schedules SET
                maintenance_status  = 'done',
                part_order          = 'close',
                part_availability   = 'close',
                use_date            = ?,
                change_date_plan    = ?,
                remaining_day       = ?
                WHERE id = ?")
                ->execute([$newUseDate, $newChangePlan, $newRemainingDay, $schedId]);

            echo json_encode(['status' => 'success', 'message' => 'Report berhasil disimpan. Next change: ' . ($newChangePlan ?? '-')]);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// ==================== PREVENTIVE MAINTENANCE POST HANDLERS ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['prev_action'])) {
    try {
        $change_date_plan = $_POST['change_date_plan'] ?? null;
        $remaining_day    = calculateRemainingDays($change_date_plan);

        if ($_POST['prev_action'] === 'prev_add') {
            $remAct_padd = (int)($_POST['reminder_activity'] ?? 0);
            // Status awal: 'soon' hanya jika sudah masuk window reminder/alert/overdue
            // 'done' jika masih jauh dari reminder (kondisi aman/secure)
            $prevAddInWindow = ($remaining_day !== null && (
                $remaining_day <= 0 ||
                ($remaining_day >= 1 && $remaining_day <= 7) ||
                ($remAct_padd > 0 && $remaining_day <= $remAct_padd)
            ));
            $initPrevStatus = $prevAddInWindow ? 'soon' : 'done';

            $stmt = $pdo->prepare("INSERT INTO schedules_preventive
                (department, line, operation_process, machine_name, process_machine, name_unit,
                 maintenance_point, interval_month, use_date, change_date_plan,
                 reminder_activity, remaining_day, maintenance_status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $_POST['dept_name'] ?? ($_POST['department'] ?? ''),
                $_POST['line_name'] ?? ($_POST['line'] ?? ''),
                $_POST['operation_process'] ?? '',
                $_POST['machine_name']     ?? '',
                $_POST['process_machine']  ?? '',
                $_POST['name_unit']        ?? '',
                $_POST['maintenance_point'] ?? '',
                (int)($_POST['interval_month']    ?? 0),
                $_POST['use_date']         ?? null,
                $change_date_plan,
                (int)($_POST['reminder_activity'] ?? 0),
                $remaining_day,
                $initPrevStatus,
            ]);
            $newPrevId      = (int)$pdo->lastInsertId();
            $needsEmailPrev = $prevAddInWindow;
            if ($needsEmailPrev && $newPrevId > 0) {
                $reminderFile = __DIR__ . '/send_reminder.php';
                if (file_exists($reminderFile)) {
                    if (!function_exists('sendNewPrevScheduleAlert')) require_once $reminderFile;
                    if (function_exists('sendNewPrevScheduleAlert')) sendNewPrevScheduleAlert($pdo, $newPrevId);
                }
            }
            echo json_encode(['status' => 'success', 'message' => 'Data preventive berhasil ditambah']);
            exit;
        }

        if ($_POST['prev_action'] === 'prev_edit') {
            $prevEditId  = (int)($_POST['prev_edit_id'] ?? 0);
            $prevRemAct  = (int)($_POST['reminder_activity'] ?? 0);

            // Ambil status saat ini agar bisa tetap 'soon' jika sudah masuk window
            $currPrevRow = $pdo->prepare("SELECT maintenance_status FROM schedules_preventive WHERE id = ?");
            $currPrevRow->execute([$prevEditId]);
            $currPrevStatus = $currPrevRow->fetchColumn() ?: 'done';

            // Auto-hitung maintenance_status:
            // - Jika remaining masuk window → 'soon' (termasuk yang sudah overdue tapi belum report)
            // - Jika masih aman (jauh dari reminder) DAN status saat ini 'done' → tetap 'done'
            // - Jika status saat ini 'soon' (misal sudah overdue belum report) → tetap 'soon'
            $prevEditInWindow = ($remaining_day !== null && (
                $remaining_day <= 0 ||
                ($remaining_day >= 1 && $remaining_day <= 7) ||
                ($prevRemAct > 0 && $remaining_day <= $prevRemAct)
            ));
            if ($prevEditInWindow) {
                $autoMaintStatus = 'soon';
            } elseif ($currPrevStatus === 'soon') {
                // Masih 'soon' tapi belum tentu di window — pertahankan 'soon' hanya jika memang masih dalam window
                // Jika tidak dalam window lagi, berarti tanggal/reminder diubah, reset ke 'done'
                $autoMaintStatus = 'done';
            } else {
                $autoMaintStatus = 'done';
            }

            $stmt = $pdo->prepare("UPDATE schedules_preventive SET
                department = ?, line = ?, operation_process = ?, machine_name = ?,
                process_machine = ?, name_unit = ?, maintenance_point = ?,
                interval_month = ?, use_date = ?, change_date_plan = ?,
                reminder_activity = ?, remaining_day = ?, maintenance_status = ?
                WHERE id = ?");
            $stmt->execute([
                $_POST['dept_name'] ?? ($_POST['department'] ?? ''),
                $_POST['line_name'] ?? ($_POST['line'] ?? ''),
                $_POST['operation_process'] ?? '',
                $_POST['machine_name']     ?? '',
                $_POST['process_machine']  ?? '',
                $_POST['name_unit']        ?? '',
                $_POST['maintenance_point'] ?? '',
                (int)($_POST['interval_month']    ?? 0),
                $_POST['use_date']         ?? null,
                $change_date_plan,
                (int)($_POST['reminder_activity'] ?? 0),
                $remaining_day,
                $autoMaintStatus,
                $prevEditId,
            ]);
            $needsEmailEdit  = ($remaining_day !== null && $prevEditId > 0 && (
                $remaining_day <= 0 ||
                ($remaining_day >= 1 && $remaining_day <= 7) ||
                ($prevRemAct > 0 && $remaining_day > 7 && $remaining_day <= $prevRemAct)
            ));
            if ($needsEmailEdit) {
                $reminderFile = __DIR__ . '/send_reminder.php';
                if (file_exists($reminderFile)) {
                    if (!function_exists('sendEditedPrevScheduleAlert')) require_once $reminderFile;
                    if (function_exists('sendEditedPrevScheduleAlert')) {
                        sendEditedPrevScheduleAlert($pdo, $prevEditId, $remaining_day);
                    }
                }
            }
            echo json_encode(['status' => 'success', 'message' => 'Data preventive berhasil diupdate']);
            exit;
        }

        if ($_POST['prev_action'] === 'prev_report') {
            $schedId      = (int)($_POST['schedule_id'] ?? 0);
            $note         = trim($_POST['note'] ?? '');
            $actualDate   = $_POST['actual_date'] ?? date('Y-m-d');
            $photoPath    = null;

            if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/uploads/reports/';
                if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
                $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                    $fname = 'prev_report_' . $schedId . '_' . time() . '.' . $ext;
                    $photoPath = 'uploads/reports/' . $fname;
                    move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $fname);
                }
            }

            $rowStmt = $pdo->prepare("SELECT * FROM schedules_preventive WHERE id = ?");
            $rowStmt->execute([$schedId]);
            $sched = $rowStmt->fetch(PDO::FETCH_ASSOC);

            if (!$sched) {
                echo json_encode(['status' => 'error', 'message' => 'Schedule preventive tidak ditemukan']);
                exit;
            }

            // ── Hitung use_date & change_date_plan baru ──
            $intervalMonth = (int)($sched['interval_month'] ?? 0);
            $newUseDate    = $actualDate;
            $nextBasis     = $_POST['next_basis'] ?? 'actual';
            if (!in_array($nextBasis, ['schedule', 'actual', 'report'], true)) {
                $nextBasis = 'actual';
            }
            $newChangePlan = computeNextChangeDate($nextBasis, $sched['change_date_plan'] ?? null, $actualDate, $intervalMonth);
            $newRemainingDay = null;
            if ($newChangePlan) {
                $now = new DateTime('today');
                $cdp = new DateTime($newChangePlan);
                $diff = (int)$now->diff($cdp)->days;
                $newRemainingDay = ($cdp >= $now) ? $diff : -$diff;
            }

            // ── department/line sudah berupa nama string langsung ──
            $prevHistDept = $sched['department'];
            $prevHistLine = $sched['line'];

            $teknisi = trim($_POST['teknisi'] ?? '');

            // ── Simpan ke history_preventive ──
            try {
                $pdo->prepare("INSERT INTO history_preventive
                    (schedule_id, department, line, operation_process, machine_name,
                     process_machine, name_unit, maintenance_point, change_date_plan,
                     note, photo_path, teknisi, reported_by, reported_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
                    ->execute([
                        $schedId,
                        $prevHistDept,
                        $prevHistLine,
                        $sched['operation_process'],
                        $sched['machine_name'],
                        $sched['process_machine'],
                        $sched['name_unit'],
                        $sched['maintenance_point'],
                        $sched['change_date_plan'],
                        $note,
                        $photoPath,
                        $teknisi,
                        $_SESSION['user_id'] ?? null,
                    ]);
            } catch (\Exception $e) { /* history table optional */
            }

            // ── Update schedules_preventive ──
            // maintenance_status → 'done', use_date & change_date_plan diperbarui
            $pdo->prepare("UPDATE schedules_preventive SET
                maintenance_status = 'done',
                use_date           = ?,
                change_date_plan   = ?,
                remaining_day      = ?
                WHERE id = ?")
                ->execute([$newUseDate, $newChangePlan, $newRemainingDay, $schedId]);

            echo json_encode(['status' => 'success', 'message' => 'Report preventive berhasil disimpan. Next change: ' . ($newChangePlan ?? '-')]);
            exit;
        }

        if ($_POST['prev_action'] === 'prev_report_bulk') {
            $items = $_POST['items'] ?? [];
            if (!is_array($items) || count($items) === 0) {
                echo json_encode(['status' => 'error', 'message' => 'Tidak ada job yang dipilih untuk direport']);
                exit;
            }

            $pdo->beginTransaction();
            try {
                $successCount = 0;

                foreach ($items as $idx => $item) {
                    $schedId    = (int)($item['schedule_id'] ?? 0);
                    $note       = trim($item['note'] ?? '');
                    $teknisi    = trim($item['teknisi'] ?? '');
                    $actualDate = $item['actual_date'] ?? date('Y-m-d');
                    $photoPath  = null;

                    if ($schedId <= 0) continue;

                    // ── Upload foto per-item (nama field: items[idx][photo]) ──
                    if (!empty($_FILES['items']['name'][$idx]['photo']) && $_FILES['items']['error'][$idx]['photo'] === UPLOAD_ERR_OK) {
                        $uploadDir = __DIR__ . '/uploads/reports/';
                        if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
                        $origName = $_FILES['items']['name'][$idx]['photo'];
                        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                            $fname = 'prev_report_' . $schedId . '_' . time() . '_' . $idx . '.' . $ext;
                            $photoPath = 'uploads/reports/' . $fname;
                            move_uploaded_file($_FILES['items']['tmp_name'][$idx]['photo'], $uploadDir . $fname);
                        }
                    }

                    $rowStmt = $pdo->prepare("SELECT * FROM schedules_preventive WHERE id = ?");
                    $rowStmt->execute([$schedId]);
                    $sched = $rowStmt->fetch(PDO::FETCH_ASSOC);
                    $rowStmt->closeCursor();
                    if (!$sched) continue;

                    // ── Hitung use_date & change_date_plan baru ──
                    $intervalMonth = (int)($sched['interval_month'] ?? 0);
                    $newUseDate    = $actualDate;
                    $itemNextBasis = $item['next_basis'] ?? 'actual';
                    if (!in_array($itemNextBasis, ['schedule', 'actual', 'report'], true)) {
                        $itemNextBasis = 'actual';
                    }
                    $newChangePlan = computeNextChangeDate($itemNextBasis, $sched['change_date_plan'] ?? null, $actualDate, $intervalMonth);
                    $newRemainingDay = null;
                    if ($newChangePlan) {
                        $now = new DateTime('today');
                        $cdp = new DateTime($newChangePlan);
                        $diff = (int)$now->diff($cdp)->days;
                        $newRemainingDay = ($cdp >= $now) ? $diff : -$diff;
                    }

                    $pdo->prepare("INSERT INTO history_preventive
                        (schedule_id, department, line, operation_process, machine_name,
                         process_machine, name_unit, maintenance_point, change_date_plan,
                         note, photo_path, teknisi, reported_by, reported_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
                        ->execute([
                            $schedId,
                            $sched['department'],
                            $sched['line'],
                            $sched['operation_process'],
                            $sched['machine_name'],
                            $sched['process_machine'],
                            $sched['name_unit'],
                            $sched['maintenance_point'],
                            $sched['change_date_plan'],
                            $note,
                            $photoPath,
                            $teknisi,
                            $_SESSION['user_id'] ?? null,
                        ]);

                    $pdo->prepare("UPDATE schedules_preventive SET
                        maintenance_status = 'done',
                        use_date           = ?,
                        change_date_plan   = ?,
                        remaining_day      = ?
                        WHERE id = ?")
                        ->execute([$newUseDate, $newChangePlan, $newRemainingDay, $schedId]);

                    $successCount++;
                }

                $pdo->commit();

                if ($successCount === 0) {
                    echo json_encode(['status' => 'error', 'message' => 'Tidak ada report yang berhasil disimpan']);
                } else {
                    echo json_encode(['status' => 'success', 'message' => "Berhasil menyimpan {$successCount} report preventive."]);
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// Fetch data
// Gunakan prepare+execute+fetchAll+closeCursor agar tidak ada unbuffered cursor tersisa
// Ambil distinct department dari machine_list (bukan dari tabel plants lagi)
$stmtPlants = $pdo->prepare("SELECT DISTINCT department AS id, department AS plant_name FROM machine_list ORDER BY department ASC");
$stmtPlants->execute();
$plants = $stmtPlants->fetchAll(PDO::FETCH_ASSOC);
$stmtPlants->closeCursor();

$stmt = $pdo->prepare("
    SELECT s.*,
           DATEDIFF(s.change_date_plan, CURDATE()) AS remaining_day,
           s.department AS department,
           s.line AS line
    FROM schedules s
    ORDER BY s.change_date_plan ASC
");
$stmt->execute();
$schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt->closeCursor();

// ── Preventive Maintenance data ───────────────────────────────────────────────
// Setiap UPDATE dipisah ke try-catch sendiri agar jika kolom tidak ada (misal
// part_order/part_availability tidak ada di tabel preventive), SELECT data tetap
// berjalan dan tabel tidak kosong.
try {
    $stmtUpd1 = $pdo->prepare("
        UPDATE schedules_preventive
        SET remaining_day = DATEDIFF(change_date_plan, CURDATE())
        WHERE change_date_plan IS NOT NULL
    ");
    $stmtUpd1->execute();
    $stmtUpd1->closeCursor();
} catch (\Exception $e) {
    error_log('[Dashboard] preventive UPDATE remaining_day gagal: ' . $e->getMessage());
}
// Auto-reset: jika sudah 'done' tapi window reminder baru sudah tiba
// (hanya jika kolom part_order & part_availability ada di tabel)
try {
    $stmtUpd2 = $pdo->prepare("
        UPDATE schedules_preventive
        SET maintenance_status = 'soon'
        WHERE maintenance_status = 'done'
          AND change_date_plan IS NOT NULL
          AND DATEDIFF(change_date_plan, CURDATE()) <= reminder_activity
          AND DATEDIFF(change_date_plan, CURDATE()) >= 0
    ");
    $stmtUpd2->execute();
    $stmtUpd2->closeCursor();
} catch (\Exception $e) {
    error_log('[Dashboard] preventive auto-reset gagal: ' . $e->getMessage());
}
// SELALU dijalankan terlepas dari keberhasilan UPDATE di atas
try {
    $stmtPrev = $pdo->prepare("
        SELECT ps.*,
               DATEDIFF(ps.change_date_plan, CURDATE()) AS remaining_day,
               ps.department AS department,
               ps.line AS line
        FROM schedules_preventive ps
        ORDER BY ps.change_date_plan ASC
    ");
    $stmtPrev->execute();
    $prevSchedules = $stmtPrev->fetchAll(PDO::FETCH_ASSOC);
    $stmtPrev->closeCursor();

    // ── FALLBACK TAMPILAN: beberapa baris lama menyimpan department/line ────────
    // sebagai ID angka (mis. "1") bukan nama string ("ASSEMBLING", "LINE 1").
    // Tanpa mengubah data di DB, terjemahkan ID → nama dengan mencocokkan
    // machine_name + operation_process ke machine_list (yang selalu berisi nama).
    $prevNeedsLookup = array_filter($prevSchedules, function ($r) {
        return (isset($r['department']) && $r['department'] !== '' && ctype_digit((string)$r['department']))
            || (isset($r['line']) && $r['line'] !== '' && ctype_digit((string)$r['line']));
    });
    if (!empty($prevNeedsLookup)) {
        $stmtMl = $pdo->prepare(
            "SELECT department, `line`, machine_name, op
             FROM machine_list
             WHERE machine_name = ? AND op = ?
             LIMIT 1"
        );
        foreach ($prevSchedules as &$prevRow) {
            $deptIsId = isset($prevRow['department']) && $prevRow['department'] !== '' && ctype_digit((string)$prevRow['department']);
            $lineIsId = isset($prevRow['line']) && $prevRow['line'] !== '' && ctype_digit((string)$prevRow['line']);
            if (!$deptIsId && !$lineIsId) {
                continue;
            }
            $stmtMl->execute([$prevRow['machine_name'] ?? '', $prevRow['operation_process'] ?? '']);
            $mlRow = $stmtMl->fetch(PDO::FETCH_ASSOC);
            $stmtMl->closeCursor();
            if ($mlRow) {
                if ($deptIsId) {
                    $prevRow['department'] = $mlRow['department'];
                }
                if ($lineIsId) {
                    $prevRow['line'] = $mlRow['line'];
                }
            }
        }
        unset($prevRow);
    }
} catch (\Exception $e) {
    $prevSchedules = [];
    error_log('[Dashboard] schedules_preventive fetch gagal: ' . $e->getMessage());
}

// Hitung stat cards
$todaySched   = array_filter($schedules, fn($r) => ($r['change_date_plan'] ?? '') === $todayStr);
$cntOverdue   = count(array_filter($schedules, fn($r) => (int)$r['remaining_day'] <= 0));
$cntAlert     = count(array_filter($schedules, fn($r) => (int)$r['remaining_day'] > 0 && (int)$r['remaining_day'] <= 7));
$cntReminder  = count(array_filter($schedules, fn($r) => (int)$r['remaining_day'] > 7 && (int)$r['remaining_day'] <= (int)($r['reminder_activity'] ?? 30)));
$cntSecure    = count(array_filter($schedules, fn($r) => (int)$r['remaining_day'] > (int)($r['reminder_activity'] ?? 30)));
$uniqueLines  = array_values(array_unique(array_filter(array_column($schedules, 'line'))));
sort($uniqueLines);

// ── Preventive stat cards (computed in PHP for use in HTML below) ──
$prevByStatus = [
    'overdue'  => array_values(array_filter($prevSchedules, fn($r) => (int)$r['remaining_day'] <= 0)),
    'alert'    => array_values(array_filter($prevSchedules, fn($r) => (int)$r['remaining_day'] > 0 && (int)$r['remaining_day'] <= 7)),
    'reminder' => array_values(array_filter($prevSchedules, fn($r) => (int)$r['remaining_day'] > 7 && (int)$r['remaining_day'] <= (int)($r['reminder_activity'] ?? 30))),
    'secure'   => array_values(array_filter($prevSchedules, fn($r) => (int)$r['remaining_day'] > (int)($r['reminder_activity'] ?? 30))),
];
$pCntOverdue  = count($prevByStatus['overdue']);
$pCntAlert    = count($prevByStatus['alert']);
$pCntReminder = count($prevByStatus['reminder']);
$pCntSecure   = count($prevByStatus['secure']);
$prevTodaySched  = array_filter($prevSchedules, fn($r) => ($r['change_date_plan'] ?? '') === $todayStr);
$prevUniqueLines = array_values(array_unique(array_filter(array_column($prevSchedules, 'line'))));
sort($prevUniqueLines);

// ── renderFormFields ──────────────────────────────────────────────────────────
function renderFormFields(string $prefix, array $plants): string
{
    $plantsOpts = '';
    foreach ($plants as $p) {
        $plantsOpts .= '<option value="' . $p['id'] . '" data-name="' . htmlspecialchars($p['plant_name']) . '">'
            . htmlspecialchars($p['plant_name']) . '</option>';
    }

    // Edit modes: tampilkan readonly fields
    // Add modes (add, prev_add): tampilkan dropdown interaktif dengan prefix yang benar
    if (in_array($prefix, ['edit', 'prev_edit'])) {
        $editDeptId = ($prefix === 'prev_edit') ? 'prev_edit_dept_display' : 'edit_dept_display';
        $editLineId = ($prefix === 'prev_edit') ? 'prev_edit_line_display' : 'edit_line_display';
        $editOpId   = ($prefix === 'prev_edit') ? 'prev_edit_op_display'  : 'edit_op_display';
        $deptLineOpSection = <<<HTML
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
            <div>
                <label class="block text-xs font-black text-slate-500 uppercase mb-2">Department</label>
                <input type="text" id="{$editDeptId}" class="w-full bg-slate-100 border border-slate-200 rounded-xl px-4 py-3 font-medium text-slate-600" readonly>
            </div>
            <div>
                <label class="block text-xs font-black text-slate-500 uppercase mb-1">Line</label>
                <input type="text" id="{$editLineId}" class="w-full bg-slate-100 border border-slate-200 rounded-xl px-4 py-3 font-medium text-slate-600" readonly>
            </div>
            <div>
                <label class="block text-xs font-black text-slate-500 uppercase mb-1">Operation Process</label>
                <input type="text" id="{$editOpId}" name="operation_process" class="w-full bg-slate-100 border border-slate-200 rounded-xl px-4 py-3 font-medium text-slate-600" readonly>
            </div>
        </div>
HTML;
    } else {
        // add atau prev_add — pakai prefix sebagai base id agar JS handler generik bisa bekerja
        $deptLineOpSection = <<<HTML
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
            <div>
                <label class="block text-xs font-black text-slate-500 uppercase mb-2">Department</label>
                <select name="department" id="{$prefix}_dept_select" onchange="handleDeptChange('{$prefix}')"
                    class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 focus:ring-4 focus:ring-[#f2d4e8] outline-none transition" required>
                    <option value="">-- Choose Department --</option>
                    $plantsOpts
                </select>
            </div>
            <div>
                <label class="block text-xs font-black text-slate-500 uppercase mb-1">Line</label>
                <select name="line" id="{$prefix}_line_select" onchange="handleLineChange('{$prefix}')"
                    class="w-full bg-slate-100 border border-slate-200 rounded-xl px-4 py-3 outline-none cursor-not-allowed" disabled>
                    <option value="">-- Choose Line --</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-black text-slate-500 uppercase mb-1">Operation Process</label>
                <select name="operation_process" id="{$prefix}_op_select" onchange="handleOpChange('{$prefix}')"
                    class="w-full bg-slate-100 border border-slate-200 rounded-xl px-4 py-3 outline-none cursor-not-allowed" disabled>
                    <option value="">-- Choose Process --</option>
                </select>
            </div>
        </div>
HTML;
    }

    return <<<HTML
        $deptLineOpSection

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6 border-t pt-6">
            <div>
                <label class="block text-xs font-black text-slate-500 uppercase mb-1">Machine Name</label>
                <input type="text" name="machine_name" id="{$prefix}_machine_name"
                    class="w-full border border-slate-200 rounded-xl px-4 py-3 bg-slate-50 font-bold" readonly>
            </div>
            <div>
                <label class="block text-xs font-black text-slate-500 uppercase mb-1">Process Machine</label>
                <input type="text" name="process_machine" id="{$prefix}_process_machine"
                    class="w-full border border-slate-200 rounded-xl px-4 py-3 bg-slate-50 font-bold" readonly>
            </div>
            <div>
                <label class="block text-xs font-black text-slate-500 uppercase mb-2">Unit Name</label>
                <input type="text" name="name_unit" id="{$prefix}_name_unit"
                    class="w-full border border-slate-200 rounded-xl px-4 py-3 focus:ring-4 focus:ring-[#f2d4e8] outline-none"
                    placeholder="Example: Spindle Unit">
            </div>
        </div>

        <div class="mb-6">
            <label class="block text-xs font-black text-slate-500 uppercase mb-2">Maintenance Point</label>
            <input type="text" name="maintenance_point" id="{$prefix}_maintenance_point"
                class="w-full border border-slate-200 rounded-xl px-6 py-4 text-lg font-medium focus:ring-4 focus:ring-[#f2d4e8] outline-none"
                placeholder="What will you do" required>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6 border-t pt-6">
            <div>
                <label class="block text-xs font-black text-slate-500 uppercase mb-2">Use Date (Last Change)</label>
                <input type="date" name="use_date" id="{$prefix}_use_date"
                    onchange="checkAutoCalculate('{$prefix}')"
                    class="w-full border border-slate-200 rounded-xl px-4 py-3 focus:ring-4 focus:ring-[#f2d4e8] outline-none transition">
            </div>
            <div>
                <label class="block text-xs font-black text-slate-500 uppercase mb-2">Interval (Month)</label>
                <input type="number" name="interval_month" id="{$prefix}_interval_month"
                    onchange="checkAutoCalculate('{$prefix}')"
                    class="w-full border border-slate-200 rounded-xl px-4 py-3 focus:ring-4 focus:ring-[#f2d4e8] outline-none transition">
            </div>
        </div>

        <div class="bg-[#f9eef5] border border-[#f2d4e8] p-6 rounded-2xl mb-8">
            <div class="flex items-center gap-3 mb-4">
                <input type="checkbox" id="{$prefix}_auto_calc" onchange="checkAutoCalculate('{$prefix}')" class="w-5 h-5 rounded accent-[#5f0f40]">
                <label for="{$prefix}_auto_calc" class="text-sm font-bold text-[#3d0929] cursor-pointer italic">Based on Interval and Last Change</label>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-black text-[#5f0f40] uppercase mb-2">Change Date Plan</label>
                    <input type="date" name="change_date_plan" id="{$prefix}_change_date_plan"
                        class="w-full bg-white border border-[#c97aad] rounded-xl px-4 py-3 font-bold" required>
                </div>
                <div>
                    <label class="block text-xs font-black text-[#5f0f40] uppercase mb-2">Reminder Activity and Reminder Part (Days)</label>
                    <input type="number" name="reminder_activity" id="{$prefix}_reminder_activity"
                        class="w-full bg-white border border-[#c97aad] rounded-xl px-4 py-3" placeholder="Only Number">
                </div>
            </div>
        </div>
HTML;
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Predictive Maintenance Alert System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        #importOverlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, .65);
            backdrop-filter: blur(4px);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }

        #importOverlay.active {
            display: flex;
        }

        #prevImportOverlay {
            display: none;
        }

        #prevImportOverlay.active {
            display: flex;
        }

        #dropZone.dragover {
            border-color: #16a34a;
            background: #f0fdf4;
        }

        @keyframes bar {
            0% {
                transform: translateX(-100%)
            }

            100% {
                transform: translateX(400%)
            }
        }

        .anim-bar {
            animation: bar 1.2s ease-in-out infinite;
        }

        /* Badge status hardcoded — tidak bergantung Tailwind CDN */
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

        .badge-secure {
            background: #d1fae5;
            color: #065f46;
            border-color: #6ee7b7;
        }

        .badge-reminder {
            background: #ffedd5;
            color: #c2410c;
            border-color: #fdba74;
        }

        .badge-alert {
            background: #fef9c3;
            color: #854d0e;
            border-color: #fde047;
        }

        .badge-overdue {
            background: #fee2e2;
            color: #b91c1c;
            border-color: #fca5a5;
        }

        /* Part status badge */
        .ps-close {
            background: #f1f5f9;
            color: #64748b;
            border-color: #cbd5e1;
        }

        .ps-open {
            background: #f9eef5;
            color: #5f0f40;
            border-color: #c97aad;
        }

        /* .ps-finish {
            background: #d1fae5;
            color: #065f46;
            border-color: #6ee7b7;
        } */

        tr.sched-row:hover td {
            background: #f8fafc;
        }

        .stat-card {
            cursor: pointer;
            transition: transform .15s, box-shadow .15s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, .09);
        }

        /* Maint status */
        .ms-soon {
            background: #f9eef5;
            color: #5f0f40;
            border-color: #c97aad;
        }

        .ms-done {
            background: #d1fae5;
            color: #065f46;
            border-color: #6ee7b7;
        }

        /* Tab active/inactive */
        .tab-active {
            background: #fff;
            color: #5f0f40;
            border-color: #c97aad;
            border-bottom-color: #fff;
        }

        .tab-inactive {
            background: #f8fafc;
            color: #64748b;
        }

        /* ── Sidebar layout ── */
        #app-layout {
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
            padding: .75rem .75rem .75rem;
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

        .nav-pill.active-schedule {
            background: #f9eef5;
            color: #5f0f40;
        }

        .nav-pill.active-schedule .np-icon {
            background: #5f0f40;
            color: #fff;
        }

        .nav-pill.active-history {
            background: #f9eef5;
            color: #5f0f40;
        }

        .nav-pill.active-history .np-icon {
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

        /* ── Main content ── */
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
    </style>
</head>

<body class="bg-slate-50 min-h-screen font-sans text-slate-900">

    <div id="app-layout">

        <!-- ═══════════════════ SIDEBAR ═══════════════════ -->
        <nav id="sidebar" class="collapsed">
            <div id="sidebar-logo">
                <div class="logo-icon"><i class="fas fa-calendar-check"></i></div>
                <span class="logo-text">Schedule</span>
            </div>
            <div class="sidebar-nav">
                <a href="index.php" class="sidebar-back">
                    <span class="sb-icon"><i class="fas fa-arrow-left"></i></span>
                    <span class="sb-label">Back to Hub</span>
                </a>
                <div style="height:1px;background:#f1f5f9;margin:.4rem 0;"></div>
                <a href="dashboard_user.php" class="nav-pill active-schedule">
                    <span class="np-icon"><i class="fas fa-calendar-check"></i></span>
                    <span class="np-label">Schedule</span>
                </a>
                <a href="history_maintenance.php" class="nav-pill">
                    <span class="np-icon"><i class="fas fa-history"></i></span>
                    <span class="np-label">History</span>
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
                        <h1 class="text-3xl font-bold text-gray-800">🛠️ Maintenance Dashboard</h1>
                        <p class="text-gray-500 mt-1">Preventive and Predictive Maintenance</p>
                    </div>
                    <div class="flex items-center gap-3 flex-wrap">
                        <div class="flex items-center gap-2 bg-slate-100 px-4 py-2 rounded-xl">
                            <div class="w-7 h-7 rounded-full bg-[#5f0f40] flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-user text-white text-xs"></i>
                            </div>
                            <span class="text-sm font-bold text-slate-700"><?= htmlspecialchars($displayName) ?></span>
                        </div>
                        <a href="logout_user.php" onclick="return confirm('Apakah Anda yakin ingin keluar?')"
                            class="bg-red-100 hover:bg-red-200 text-red-600 px-5 py-2.5 rounded-xl font-bold transition-all flex items-center gap-2 text-sm">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>

                <!-- NAVBAR TABS — Full horizontal pill switcher -->
                <div class="mb-6">
                    <div class="relative bg-slate-100 rounded-2xl p-1.5 flex gap-1 shadow-inner">
                        <div id="tabIndicator"
                            class="absolute top-1.5 left-1.5 bottom-1.5 rounded-xl transition-all duration-300 ease-in-out shadow-md pointer-events-none"
                            style="background:linear-gradient(135deg, #5f0f40, #7a1a5a);width:calc(50% - 4px);transform:translateX(0);">
                        </div>
                        <button id="tabPredictive" onclick="switchTab('predictive')"
                            class="relative z-10 flex-1 flex items-center justify-center gap-2 py-3.5 px-6 font-bold text-sm rounded-xl transition-all duration-300 text-white">
                            <i class="fas fa-chart-line text-base"></i>
                            <span>Predictive Maintenance</span>
                            <span id="badgePredictive" class="ml-1 bg-white/20 text-white text-[10px] font-black px-2 py-0.5 rounded-full"><?= count($schedules) ?></span>
                        </button>
                        <button id="tabPreventive" onclick="switchTab('preventive')"
                            class="relative z-10 flex-1 flex items-center justify-center gap-2 py-3.5 px-6 font-bold text-sm rounded-xl transition-all duration-300 text-slate-500">
                            <i class="fas fa-shield-halved text-base"></i>
                            <span>Preventive Maintenance</span>
                            <span id="badgePreventive" class="ml-1 bg-slate-300/60 text-slate-600 text-[10px] font-black px-2 py-0.5 rounded-full"><?= count($prevSchedules ?? []) ?></span>
                        </button>
                    </div>
                </div>

                <div id="predictiveContent">
                    <!-- STAT CARDS — klikable, persis seperti dashboard_part -->
                    <?php
                    $schedByStatus = [
                        'overdue'  => array_values(array_filter($schedules, fn($r) => (int)$r['remaining_day'] <= 0)),
                        'alert'    => array_values(array_filter($schedules, fn($r) => (int)$r['remaining_day'] > 0 && (int)$r['remaining_day'] <= 7)),
                        'reminder' => array_values(array_filter($schedules, fn($r) => (int)$r['remaining_day'] > 7 && (int)$r['remaining_day'] <= (int)($r['reminder_activity'] ?? 30))),
                        'secure'   => array_values(array_filter($schedules, fn($r) => (int)$r['remaining_day'] > (int)($r['reminder_activity'] ?? 30))),
                    ];
                    ?>

                    <!-- TODAY CARD — PREDICTIVE -->
                    <?php
                    $todaySchedArr  = array_values($todaySched);
                    $todaySchedJson = json_encode(array_map(fn($r) => [
                        'machine'  => $r['machine_name'] ?? '-',
                        'point'    => $r['maintenance_point'] ?? '-',
                        'dept'     => $r['department'] ?? '',
                        'line'     => $r['line'] ?? '',
                        'op'       => $r['operation_process'] ?? '',
                        'interval' => ($r['interval_month'] ?? 0) . ' mo',
                    ], $todaySchedArr), JSON_UNESCAPED_UNICODE);
                    $todayCount = count($todaySchedArr);
                    ?>
                    <div id="predTodayCard"
                        class="bg-white rounded-3xl border border-[#c97aad] shadow-sm mb-5 overflow-hidden cursor-pointer select-none transition-all duration-200 hover:shadow-md hover:border-[#9a3578]"
                        onclick="openTodayModal('pred')"
                        title="Klik untuk lihat semua jadwal hari ini">

                        <!-- Header -->
                        <div class="flex items-center justify-between px-5 pt-5 pb-3 border-b border-[#f2d4e8]">
                            <p class="text-xs font-black text-[#c97aad] uppercase tracking-widest flex items-center gap-2">
                                <i class="fas fa-calendar-day"></i> Today's Schedule — <?= date('d M Y') ?>
                            </p>
                            <div class="flex items-center gap-2">
                                <?php if ($todayCount > 0): ?>
                                    <span class="bg-[#5f0f40] text-white text-xs font-black px-2.5 py-0.5 rounded-full"><?= $todayCount ?> jadwal</span>
                                <?php endif; ?>
                                <span class="text-[#d9a3c8] text-xs font-bold flex items-center gap-1 italic">
                                    <i class="fas fa-expand-alt"></i> Klik untuk lihat semua
                                </span>
                            </div>
                        </div>

                        <!-- Ticker body -->
                        <div class="px-5 py-4">
                            <?php if ($todayCount === 0): ?>
                                <p class="text-slate-400 text-sm italic py-2">Tidak ada jadwal predictive untuk hari ini.</p>
                            <?php else: ?>
                                <div class="flex items-center gap-4">
                                    <!-- Index bubble -->
                                    <div id="predTickerIdx" class="flex-shrink-0 w-11 h-11 rounded-full bg-[#5f0f40] text-white flex items-center justify-center text-base font-black shadow">1</div>
                                    <!-- Text content -->
                                    <div class="flex-1 min-w-0 relative" style="height:68px;">
                                        <?php foreach ($todaySchedArr as $i => $td): ?>
                                            <div class="pred-ticker-item absolute inset-0 flex flex-col justify-center transition-all duration-500"
                                                style="opacity:<?= $i === 0 ? '1' : '0' ?>;transform:translateY(<?= $i === 0 ? '0' : '8px' ?>);">
                                                <p class="font-black text-slate-800 text-base leading-tight truncate"><?= htmlspecialchars($td['machine_name']) ?></p>
                                                <p class="text-[#5f0f40] text-sm font-semibold truncate mt-1"><?= htmlspecialchars($td['maintenance_point']) ?></p>
                                                <p class="text-slate-400 text-xs mt-0.5 truncate"><?= htmlspecialchars($td['department']) ?><?= $td['department'] && $td['line'] ? ' · ' : '' ?><?= htmlspecialchars($td['line']) ?><?= $td['line'] && $td['operation_process'] ? ' · ' : '' ?><?= htmlspecialchars($td['operation_process']) ?></p>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <!-- Right side: interval + dots -->
                                    <div class="flex-shrink-0 flex flex-col items-end gap-2">
                                        <span id="predTickerInterval" class="bg-[#f9eef5] border border-[#c97aad] text-[#5f0f40] text-xs font-bold px-3 py-1 rounded-lg"><?= (int)($todaySchedArr[0]['interval_month'] ?? 0) ?> mo</span>
                                        <?php if ($todayCount > 1): ?>
                                            <div class="flex gap-1" id="predDots">
                                                <?php for ($i = 0; $i < min($todayCount, 8); $i++): ?>
                                                    <span class="pred-dot block rounded-full transition-all duration-300" style="height:7px;width:<?= $i === 0 ? '16' : '7' ?>px;background:<?= $i === 0 ? '#5f0f40' : '#f2d4e8' ?>;"></span>
                                                <?php endfor; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>


                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-5">
                        <div class="p-5 rounded-3xl border shadow-sm cursor-pointer transition hover:-translate-y-1 hover:shadow-md"
                            style="background:#fee2e2;border-color:#fca5a5;" onclick="openStatusModal('overdue')">
                            <p class="text-[10px] font-black uppercase tracking-widest mb-1 flex items-center" style="color:#b91c1c;">
                                Overdue <i class="fas fa-chevron-right text-[8px] ml-auto opacity-50"></i>
                            </p>
                            <p class="text-3xl font-black" style="color:#b91c1c;"><?= $cntOverdue ?></p>
                        </div>
                        <div class="p-5 rounded-3xl border shadow-sm cursor-pointer transition hover:-translate-y-1 hover:shadow-md"
                            style="background:#fef9c3;border-color:#fde047;" onclick="openStatusModal('alert')">
                            <p class="text-[10px] font-black uppercase tracking-widest mb-1 flex items-center" style="color:#854d0e;">
                                Alert <i class="fas fa-chevron-right text-[8px] ml-auto opacity-50"></i>
                            </p>
                            <p class="text-3xl font-black" style="color:#854d0e;"><?= $cntAlert ?></p>
                        </div>
                        <div class="p-5 rounded-3xl border shadow-sm cursor-pointer transition hover:-translate-y-1 hover:shadow-md"
                            style="background:#ffedd5;border-color:#fdba74;" onclick="openStatusModal('reminder')">
                            <p class="text-[10px] font-black uppercase tracking-widest mb-1 flex items-center" style="color:#c2410c;">
                                Reminder <i class="fas fa-chevron-right text-[8px] ml-auto opacity-50"></i>
                            </p>
                            <p class="text-3xl font-black" style="color:#c2410c;"><?= $cntReminder ?></p>
                        </div>
                        <div class="p-5 rounded-3xl border shadow-sm cursor-pointer transition hover:-translate-y-1 hover:shadow-md"
                            style="background:#d1fae5;border-color:#6ee7b7;" onclick="openStatusModal('secure')">
                            <p class="text-[10px] font-black uppercase tracking-widest mb-1 flex items-center" style="color:#065f46;">
                                Secure <i class="fas fa-chevron-right text-[8px] ml-auto opacity-50"></i>
                            </p>
                            <p class="text-3xl font-black" style="color:#065f46;"><?= $cntSecure ?></p>
                        </div>
                    </div>

                    <!-- SEARCH + FILTER BAR -->
                    <div class="flex flex-col sm:flex-row gap-3 mb-4">
                        <div class="relative flex-1">
                            <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                            <input type="text" id="searchInput" placeholder="Search machine, maintenance point, line..."
                                oninput="applyFilters()"
                                class="w-full pl-11 pr-4 py-3 bg-white border border-slate-200 rounded-2xl focus:ring-4 focus:ring-[#f2d4e8] outline-none transition shadow-sm text-sm">
                        </div>
                        <div class="relative">
                            <i class="fas fa-filter absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                            <select id="filterLine" onchange="applyFilters()"
                                class="pl-11 pr-10 py-3 bg-white border border-slate-200 rounded-2xl focus:ring-4 focus:ring-[#f2d4e8] outline-none transition shadow-sm text-sm appearance-none font-medium min-w-[180px]">
                                <option value="">All Line</option>
                                <?php foreach ($uniqueLines as $ln): ?>
                                    <option value="<?= htmlspecialchars($ln) ?>"><?= htmlspecialchars($ln) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <i class="fas fa-chevron-down absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 text-xs pointer-events-none"></i>
                        </div>
                        <div class="bg-white border border-slate-200 rounded-2xl px-5 py-3 text-sm text-slate-500 shadow-sm flex items-center gap-2 whitespace-nowrap">
                            <i class="fas fa-table-list text-slate-400"></i>
                            <span id="rowCountLabel"><?= count($schedules) ?> jadwal</span>
                        </div>

                        <!-- Predictive Action Buttons -->
                        <div id="predictiveActions" class="flex items-center gap-2 w-full lg:w-auto">
                            <button onclick="showAddModal()"
                                class="flex-1 lg:flex-none bg-[#5f0f40] hover:bg-[#4a0b31] text-white px-5 py-3 rounded-2xl font-bold shadow-sm transition-all flex items-center justify-center gap-2 text-sm whitespace-nowrap">
                                <i class="fas fa-plus"></i> Add
                            </button>
                            <button onclick="showImportModal()"
                                class="flex-1 lg:flex-none bg-[#5f0f40] hover:bg-[#4a0b31] text-white px-5 py-3 rounded-2xl font-bold shadow-sm transition-all flex items-center justify-center gap-2 text-sm whitespace-nowrap">
                                <i class="fas fa-file-excel"></i> Import
                            </button>
                            <button onclick="showAddMachineModal()"
                                class="flex-1 lg:flex-none bg-slate-600 hover:bg-slate-700 text-white px-5 py-3 rounded-2xl font-bold shadow-sm transition-all flex items-center justify-center gap-2 text-sm whitespace-nowrap">
                                <i class="fas fa-cog"></i> Add Machine
                            </button>
                        </div>
                    </div>

                    <!-- TABEL dengan scroll & sticky header -->
                    <div class="bg-white rounded-3xl shadow-xl border border-slate-200 overflow-hidden">
                        <div style="max-height:540px;overflow-y:auto;overflow-x:auto;">
                            <table class="w-full text-left border-collapse" id="schedTable">
                                <thead class="bg-slate-800 text-white" style="background:linear-gradient(135deg, #5f0f40, #7a1a5a);position:sticky;top:0;z-index:10;">
                                    <tr>
                                        <th class="px-5 py-4 font-semibold text-[11px] uppercase tracking-widest whitespace-nowrap">Machine Information</th>
                                        <th class="px-5 py-4 font-semibold text-[11px] uppercase tracking-widest whitespace-nowrap">Maintenance Point</th>
                                        <th class="px-5 py-4 font-semibold text-[11px] uppercase tracking-widest text-center whitespace-nowrap">Last Change</th>
                                        <th class="px-5 py-4 font-semibold text-[11px] uppercase tracking-widest text-center whitespace-nowrap">Interval</th>
                                        <th class="px-5 py-4 font-semibold text-[11px] uppercase tracking-widest text-center whitespace-nowrap">Change Date Plan</th>
                                        <th class="px-5 py-4 font-semibold text-[11px] uppercase tracking-widest text-center whitespace-nowrap">Remaining (Day(s))</th>
                                        <th class="px-5 py-4 font-semibold text-[11px] uppercase tracking-widest text-center whitespace-nowrap">Part Order</th>
                                        <th class="px-5 py-4 font-semibold text-[11px] uppercase tracking-widest text-center whitespace-nowrap">Part Availability</th>
                                        <th class="px-5 py-4 font-semibold text-[11px] uppercase tracking-widest text-center whitespace-nowrap">Maint. Status</th>
                                        <th class="px-5 py-4 font-semibold text-[11px] uppercase tracking-widest text-center whitespace-nowrap">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="schedBody" class="divide-y divide-slate-100">
                                    <?php foreach ($schedules as $row):
                                        $days     = (int)$row['remaining_day'];
                                        $reminder = (int)($row['reminder_activity'] ?? 30);
                                        // Status berbasis reminder_activity dari DB per jadwal
                                        if ($days <= 0) {
                                            $bc = 'badge-overdue';
                                            $st = 'Overdue';
                                        } elseif ($days <= 7) {
                                            $bc = 'badge-alert';
                                            $st = 'Alert';
                                        } elseif ($days <= $reminder) {
                                            $bc = 'badge-reminder';
                                            $st = 'Reminder';
                                        } else {
                                            $bc = 'badge-secure';
                                            $st = 'Secure';
                                        }

                                        // Part order & availability — langsung dari DB (sudah di-manage server-side)
                                        $pOrder = $row['part_order'] ?? 'close';
                                        $pAvail = $row['part_availability'] ?? 'close';

                                        $poClass = ($pOrder === 'open') ? 'ps-open' : 'ps-close';
                                        $paClass = ($pAvail === 'open') ? 'ps-open' : 'ps-close';

                                        // Maintenance status
                                        $maintSt = $row['maintenance_status'] ?? 'soon';
                                        $isSoon  = ($maintSt !== 'done');
                                        $msClass = $isSoon ? 'ms-soon' : 'ms-done';
                                        // Tombol report muncul jika: status=soon DAN sudah masuk window reminder (remaining <= reminder) DAN not overdue terlalu jauh
                                        $canReport = $isSoon && $days <= $reminder;
                                        $srch = strtolower(($row['machine_name'] ?? '') . ' ' . ($row['maintenance_point'] ?? '') . ' ' . ($row['department'] ?? '') . ' ' . ($row['line'] ?? '') . ' ' . ($row['operation_process'] ?? ''));
                                    ?>
                                        <tr class="sched-row transition-colors"
                                            data-line="<?= htmlspecialchars($row['line'] ?? '') ?>"
                                            data-search="<?= htmlspecialchars($srch) ?>">
                                            <td class="px-5 py-3">
                                                <div class="font-bold text-sm text-slate-800 whitespace-nowrap">
                                                    <?= htmlspecialchars($row['machine_name']) ?> | <?= htmlspecialchars($row['operation_process']) ?>
                                                </div>
                                                <div class="text-xs text-slate-500 mt-0.5">
                                                    <?= htmlspecialchars($row['department']) ?> | <?= htmlspecialchars($row['line']) ?>
                                                </div>
                                            </td>
                                            <td class="px-5 py-3 text-sm text-slate-600 font-medium max-w-[180px]"><?= htmlspecialchars($row['maintenance_point']) ?></td>
                                            <td class="px-5 py-3 text-center font-mono text-xs whitespace-nowrap"><?= formatDate($row['use_date']) ?></td>
                                            <td class="px-5 py-3 text-center">
                                                <span class="bg-slate-100 text-slate-700 font-bold px-2 py-1 rounded-lg text-xs">
                                                    <?= (int)$row['interval_month'] ?> mo
                                                </span>
                                            </td>
                                            <td class="px-5 py-3 text-center font-mono font-bold text-xs whitespace-nowrap"><?= formatDate($row['change_date_plan']) ?></td>
                                            <td class="px-5 py-3 text-center">
                                                <span class="badge <?= $bc ?>">
                                                    <?= $days ?> DAYS (<?= $st ?>)
                                                </span>
                                            </td>
                                            <td class="px-5 py-3 text-center">
                                                <span class="badge <?= $poClass ?>"><?= strtoupper($pOrder) ?></span>
                                            </td>
                                            <td class="px-5 py-3 text-center">
                                                <span class="badge <?= $paClass ?>"><?= strtoupper($pAvail) ?></span>
                                            </td>
                                            <td class="px-5 py-3 text-center">
                                                <span class="badge <?= $msClass ?>"><?= strtoupper($maintSt) ?></span>
                                            </td>
                                            <td class="px-5 py-3 text-center">
                                                <div class="flex items-center justify-center gap-1.5">
                                                    <button onclick="showEditModal(<?= $row['id'] ?>)"
                                                        class="bg-[#7a1a5a] text-white p-2 rounded-lg hover:bg-[#5f0f40] transition" title="Edit">
                                                        <i class="fas fa-edit text-xs"></i>
                                                    </button>
                                                    <?php if ($canReport): ?>
                                                        <button onclick="showReportModal(<?= $row['id'] ?>, '<?= htmlspecialchars($row['machine_name'], ENT_QUOTES) ?>')"
                                                            class="bg-emerald-500 text-white p-2 rounded-lg hover:bg-emerald-600 transition" title="Submit Report">
                                                            <i class="fas fa-clipboard-check text-xs"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($schedules)): ?>
                                        <tr>
                                            <td colspan="11" class="px-6 py-16 text-center text-slate-400">
                                                <i class="fas fa-inbox text-4xl mb-3 block"></i>
                                                <p class="font-medium">Belum ada data. Tambah manual atau import dari Excel.</p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="px-6 py-3 bg-slate-50 border-t border-slate-100 flex items-center justify-between text-xs text-slate-400">
                            <span id="tableFooter">Menampilkan <?= count($schedules) ?> jadwal</span>
                            <?php date_default_timezone_set('Asia/Jakarta'); ?>
                            <span>Last updated: <?= date('d M Y H:i') ?></span>
                        </div>
                    </div>
                </div><!-- /predictiveContent -->

                <!-- TAB: PREVENTIVE MAINTENANCE — inside the same max-w wrapper -->
                <div id="preventiveTab" style="display:none;">

                    <!-- TODAY CARD — PREVENTIVE -->
                    <?php
                    $prevTodayArr  = array_values($prevTodaySched);
                    $prevTodayJson = json_encode(array_map(fn($r) => [
                        'machine'  => $r['machine_name'] ?? '-',
                        'point'    => $r['maintenance_point'] ?? '-',
                        'dept'     => $r['department'] ?? '',
                        'line'     => $r['line'] ?? '',
                        'op'       => $r['operation_process'] ?? '',
                        'interval' => ($r['interval_month'] ?? 0) . ' mo',
                    ], $prevTodayArr), JSON_UNESCAPED_UNICODE);
                    $prevTodayCount = count($prevTodayArr);
                    ?>
                    <div id="prevTodayCard"
                        class="bg-white rounded-3xl border border-[#e8c5da] shadow-sm mb-5 overflow-hidden cursor-pointer select-none transition-all duration-200 hover:shadow-md hover:border-[#c97aad]"
                        onclick="openTodayModal('prev')"
                        title="Klik untuk lihat semua jadwal hari ini">

                        <!-- Header -->
                        <div class="flex items-center justify-between px-5 pt-5 pb-3 border-b border-[#f9eef5]">
                            <p class="text-xs font-black text-[#8b1a6b] uppercase tracking-widest flex items-center gap-2">
                                <i class="fas fa-calendar-day"></i> Today's Schedule — <?= date('d M Y') ?>
                            </p>
                            <div class="flex items-center gap-2">
                                <?php if ($prevTodayCount > 0): ?>
                                    <span class="bg-[#8b1a6b] text-white text-xs font-black px-2.5 py-0.5 rounded-full"><?= $prevTodayCount ?> jadwal</span>
                                <?php endif; ?>
                                <span class="text-[#d9a3c8] text-xs font-bold flex items-center gap-1 italic">
                                    <i class="fas fa-expand-alt"></i> Klik untuk lihat semua
                                </span>
                            </div>
                        </div>

                        <!-- Ticker body -->
                        <div class="px-5 py-4">
                            <?php if ($prevTodayCount === 0): ?>
                                <p class="text-slate-400 text-sm italic py-2">Tidak ada jadwal preventive untuk hari ini.</p>
                            <?php else: ?>
                                <div class="flex items-center gap-4">
                                    <!-- Index bubble -->
                                    <div id="prevTickerIdx" class="flex-shrink-0 w-11 h-11 rounded-full bg-[#8b1a6b] text-white flex items-center justify-center text-base font-black shadow">1</div>
                                    <!-- Text content -->
                                    <div class="flex-1 min-w-0 relative" style="height:68px;">
                                        <?php foreach ($prevTodayArr as $i => $td): ?>
                                            <div class="prev-ticker-item absolute inset-0 flex flex-col justify-center transition-all duration-500"
                                                style="opacity:<?= $i === 0 ? '1' : '0' ?>;transform:translateY(<?= $i === 0 ? '0' : '8px' ?>);">
                                                <p class="font-black text-slate-800 text-base leading-tight truncate"><?= htmlspecialchars($td['machine_name']) ?></p>
                                                <p class="text-[#8b1a6b] text-sm font-semibold truncate mt-1"><?= htmlspecialchars($td['maintenance_point']) ?></p>
                                                <p class="text-slate-400 text-xs mt-0.5 truncate"><?= htmlspecialchars($td['department']) ?><?= $td['department'] && $td['line'] ? ' · ' : '' ?><?= htmlspecialchars($td['line']) ?><?= $td['line'] && $td['operation_process'] ? ' · ' : '' ?><?= htmlspecialchars($td['operation_process']) ?></p>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <!-- Right side: interval + dots -->
                                    <div class="flex-shrink-0 flex flex-col items-end gap-2">
                                        <span id="prevTickerInterval" class="bg-[#f9eef5] border border-[#e8c5da] text-[#8b1a6b] text-xs font-bold px-3 py-1 rounded-lg"><?= (int)($prevTodayArr[0]['interval_month'] ?? 0) ?> mo</span>
                                        <?php if ($prevTodayCount > 1): ?>
                                            <div class="flex gap-1" id="prevDots">
                                                <?php for ($i = 0; $i < min($prevTodayCount, 8); $i++): ?>
                                                    <span class="prev-dot block rounded-full transition-all duration-300" style="height:7px;width:<?= $i === 0 ? '16' : '7' ?>px;background:<?= $i === 0 ? '#8b1a6b' : '#99f6e4' ?>;"></span>
                                                <?php endfor; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>


                    <!-- STAT CARDS -->
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-5">
                        <div class="p-5 rounded-3xl border shadow-sm cursor-pointer transition hover:-translate-y-1 hover:shadow-md"
                            style="background:#fee2e2;border-color:#fca5a5;" onclick="openPrevStatusModal('overdue')">
                            <p class="text-[10px] font-black uppercase tracking-widest mb-1 flex items-center" style="color:#b91c1c;">
                                Overdue <i class="fas fa-chevron-right text-[8px] ml-auto opacity-50"></i>
                            </p>
                            <p class="text-3xl font-black" style="color:#b91c1c;"><?= $pCntOverdue ?></p>
                        </div>
                        <div class="p-5 rounded-3xl border shadow-sm cursor-pointer transition hover:-translate-y-1 hover:shadow-md"
                            style="background:#fef9c3;border-color:#fde047;" onclick="openPrevStatusModal('alert')">
                            <p class="text-[10px] font-black uppercase tracking-widest mb-1 flex items-center" style="color:#854d0e;">
                                Alert <i class="fas fa-chevron-right text-[8px] ml-auto opacity-50"></i>
                            </p>
                            <p class="text-3xl font-black" style="color:#854d0e;"><?= $pCntAlert ?></p>
                        </div>
                        <div class="p-5 rounded-3xl border shadow-sm cursor-pointer transition hover:-translate-y-1 hover:shadow-md"
                            style="background:#ffedd5;border-color:#fdba74;" onclick="openPrevStatusModal('reminder')">
                            <p class="text-[10px] font-black uppercase tracking-widest mb-1 flex items-center" style="color:#c2410c;">
                                Reminder <i class="fas fa-chevron-right text-[8px] ml-auto opacity-50"></i>
                            </p>
                            <p class="text-3xl font-black" style="color:#c2410c;"><?= $pCntReminder ?></p>
                        </div>
                        <div class="p-5 rounded-3xl border shadow-sm cursor-pointer transition hover:-translate-y-1 hover:shadow-md"
                            style="background:#d1fae5;border-color:#6ee7b7;" onclick="openPrevStatusModal('secure')">
                            <p class="text-[10px] font-black uppercase tracking-widest mb-1 flex items-center" style="color:#065f46;">
                                Secure <i class="fas fa-chevron-right text-[8px] ml-auto opacity-50"></i>
                            </p>
                            <p class="text-3xl font-black" style="color:#065f46;"><?= $pCntSecure ?></p>
                        </div>
                    </div>


                    <!-- SEARCH + FILTER BAR -->
                    <div class="flex flex-col sm:flex-row gap-3 mb-4">
                        <div class="relative flex-1">
                            <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                            <input type="text" id="prevSearchInput" placeholder="Search machine, maintenance point, line..."
                                oninput="applyPrevFilters()"
                                class="w-full pl-11 pr-4 py-3 bg-white border border-slate-200 rounded-2xl focus:ring-4 focus:ring-[#f2d4e8] outline-none transition shadow-sm text-sm">
                        </div>
                        <div class="relative">
                            <i class="fas fa-filter absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                            <select id="prevFilterLine" onchange="applyPrevFilters()"
                                class="pl-11 pr-10 py-3 bg-white border border-slate-200 rounded-2xl focus:ring-4 focus:ring-[#f2d4e8] outline-none transition shadow-sm text-sm appearance-none font-medium min-w-[180px]">
                                <option value="">All Line</option>
                                <?php foreach ($prevUniqueLines as $ln): ?>
                                    <option value="<?= htmlspecialchars($ln) ?>"><?= htmlspecialchars($ln) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <i class="fas fa-chevron-down absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 text-xs pointer-events-none"></i>
                        </div>
                        <div class="bg-white border border-slate-200 rounded-2xl px-5 py-3 text-sm text-slate-500 shadow-sm flex items-center gap-2 whitespace-nowrap">
                            <i class="fas fa-table-list text-slate-400"></i>
                            <span id="prevRowCountLabel"><?= count($prevSchedules) ?> jadwal</span>
                        </div>

                        <!-- Preventive Action Buttons (Hidden by Default) -->
                        <div id="preventiveActions" class="flex items-center gap-2 w-full lg:w-auto" style="display: none;">
                            <button onclick="showPrevAddModal()"
                                class="flex-1 lg:flex-none text-white px-5 py-3 rounded-2xl font-bold shadow-sm transition-all flex items-center justify-center gap-2 text-sm whitespace-nowrap" style="background:#7a1355;" onmouseover="this.style.background='#8b1a6b'" onmouseout="this.style.background='#7a1355'">
                                <i class="fas fa-plus"></i> Add
                            </button>
                            <button onclick="showPrevImportModal()"
                                class="flex-1 lg:flex-none text-white px-5 py-3 rounded-2xl font-bold shadow-sm transition-all flex items-center justify-center gap-2 text-sm whitespace-nowrap" style="background:#7a1355;" onmouseover="this.style.background='#8b1a6b'" onmouseout="this.style.background='#7a1355'">
                                <i class="fas fa-file-excel"></i> Import
                            </button>
                            <button onclick="showAddMachineModal()"
                                class="flex-1 lg:flex-none bg-slate-600 hover:bg-slate-700 text-white px-5 py-3 rounded-2xl font-bold shadow-sm transition-all flex items-center justify-center gap-2 text-sm whitespace-nowrap">
                                <i class="fas fa-cog"></i> Add Machine
                            </button>
                        </div>
                    </div>
                    <!-- TABEL PREVENTIVE — tanpa Part Order & Part Availability -->
                    <div class="bg-white rounded-3xl shadow-xl border border-slate-200 overflow-hidden">
                        <div style="max-height:540px;overflow-y:auto;overflow-x:auto;">
                            <table class="w-full text-left border-collapse" id="prevSchedTable">
                                <thead class="text-white" style="background:linear-gradient(135deg, #7a1355, #8b1a6b);position:sticky;top:0;z-index:10;">
                                    <tr>
                                        <th class="px-5 py-4 font-semibold text-[11px] uppercase tracking-widest whitespace-nowrap">Machine Information</th>
                                        <th class="px-5 py-4 font-semibold text-[11px] uppercase tracking-widest whitespace-nowrap">Maintenance Point</th>
                                        <th class="px-5 py-4 font-semibold text-[11px] uppercase tracking-widest text-center whitespace-nowrap">Last Change</th>
                                        <th class="px-5 py-4 font-semibold text-[11px] uppercase tracking-widest text-center whitespace-nowrap">Interval</th>
                                        <th class="px-5 py-4 font-semibold text-[11px] uppercase tracking-widest text-center whitespace-nowrap">Change Date Plan</th>
                                        <th class="px-5 py-4 font-semibold text-[11px] uppercase tracking-widest text-center whitespace-nowrap">Remaining (Day(s))</th>
                                        <th class="px-5 py-4 font-semibold text-[11px] uppercase tracking-widest text-center whitespace-nowrap">Maint. Status</th>
                                        <th class="px-5 py-4 font-semibold text-[11px] uppercase tracking-widest text-center whitespace-nowrap">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="prevSchedBody" class="divide-y divide-slate-100">
                                    <?php
                                    // ── Precompute job yang due/bisa-direport, dikelompokkan per machine_name ──
                                    // Dipakai untuk tombol "Report" gabungan per mesin (checklist multi-select)
                                    $prevMachineDueJobs = [];
                                    if (!empty($prevSchedules)) {
                                        foreach ($prevSchedules as $r) {
                                            $rDays = (int)$r['remaining_day'];
                                            $rReminder = (int)($r['reminder_activity'] ?? 30);
                                            $rMaintSt = $r['maintenance_status'] ?? 'soon';
                                            $rCanReport = ($rMaintSt !== 'done') && $rDays <= $rReminder;
                                            if ($rCanReport) {
                                                $prevMachineDueJobs[$r['machine_name']][] = [
                                                    'id'                => (int)$r['id'],
                                                    'maintenance_point' => $r['maintenance_point'],
                                                    'change_date_plan'  => $r['change_date_plan'],
                                                    'remaining_day'     => $rDays,
                                                    'department'        => $r['department'] ?? '',
                                                    'line'              => $r['line'] ?? '',
                                                    'operation_process' => $r['operation_process'] ?? '',
                                                    'machine_name'      => $r['machine_name'] ?? '',
                                                ];
                                            }
                                        }
                                    }
                                    $renderedMachineBtn = [];
                                    ?>
                                    <?php if (!empty($prevSchedules)): foreach ($prevSchedules as $row):
                                            $days     = (int)$row['remaining_day'];
                                            $reminder = (int)($row['reminder_activity'] ?? 30);
                                            if ($days <= 0) {
                                                $bc = 'badge-overdue';
                                                $st = 'Overdue';
                                            } elseif ($days <= 7) {
                                                $bc = 'badge-alert';
                                                $st = 'Alert';
                                            } elseif ($days <= $reminder) {
                                                $bc = 'badge-reminder';
                                                $st = 'Reminder';
                                            } else {
                                                $bc = 'badge-secure';
                                                $st = 'Secure';
                                            }
                                            $maintSt = $row['maintenance_status'] ?? 'soon';
                                            $isSoon  = ($maintSt !== 'done');
                                            $msClass = $isSoon ? 'ms-soon' : 'ms-done';
                                            $canReport = $isSoon && $days <= $reminder;
                                            $srch = strtolower(($row['machine_name'] ?? '') . ' ' . ($row['maintenance_point'] ?? '') . ' ' . ($row['department'] ?? '') . ' ' . ($row['line'] ?? '') . ' ' . ($row['operation_process'] ?? ''));
                                    ?>
                                            <tr class="prev-sched-row transition-colors"
                                                data-line="<?= htmlspecialchars($row['line'] ?? '') ?>"
                                                data-search="<?= htmlspecialchars($srch) ?>">
                                                <td class="px-5 py-3">
                                                    <div class="font-bold text-sm text-slate-800 whitespace-nowrap">
                                                        <?= htmlspecialchars($row['machine_name']) ?> | <?= htmlspecialchars($row['operation_process']) ?>
                                                    </div>
                                                    <div class="text-xs text-slate-500 mt-0.5">
                                                        <?= htmlspecialchars($row['department']) ?> | <?= htmlspecialchars($row['line']) ?>
                                                    </div>
                                                </td>
                                                <td class="px-5 py-3 text-sm text-slate-600 font-medium max-w-[180px]"><?= htmlspecialchars($row['maintenance_point']) ?></td>
                                                <td class="px-5 py-3 text-center font-mono text-xs whitespace-nowrap"><?= formatDate($row['use_date']) ?></td>
                                                <td class="px-5 py-3 text-center">
                                                    <span class="bg-slate-100 text-slate-700 font-bold px-2 py-1 rounded-lg text-xs">
                                                        <?= (int)$row['interval_month'] ?> mo
                                                    </span>
                                                </td>
                                                <td class="px-5 py-3 text-center font-mono font-bold text-xs whitespace-nowrap"><?= formatDate($row['change_date_plan']) ?></td>
                                                <td class="px-5 py-3 text-center">
                                                    <span class="badge <?= $bc ?>"><?= $days ?> DAYS (<?= $st ?>)</span>
                                                </td>
                                                <td class="px-5 py-3 text-center">
                                                    <span class="badge <?= $msClass ?>"><?= strtoupper($maintSt) ?></span>
                                                </td>
                                                <td class="px-5 py-3 text-center">
                                                    <div class="flex items-center justify-center gap-1.5">
                                                        <button onclick="showPrevEditModal(<?= $row['id'] ?>)"
                                                            class="bg-[#8b1a6b] text-white p-2 rounded-lg hover:bg-[#8b1a6b] transition" title="Edit">
                                                            <i class="fas fa-edit text-xs"></i>
                                                        </button>
                                                        <?php
                                                        $mName = $row['machine_name'];
                                                        if (!isset($renderedMachineBtn[$mName])):
                                                            $renderedMachineBtn[$mName] = true;
                                                            $dueJobsForMachine = $prevMachineDueJobs[$mName] ?? [];
                                                            $dueCount = count($dueJobsForMachine);
                                                            if ($dueCount > 0):
                                                        ?>
                                                                <button onclick="showPrevMachineReportModal('<?= htmlspecialchars($mName, ENT_QUOTES) ?>')"
                                                                    class="bg-emerald-500 text-white p-2 rounded-lg hover:bg-emerald-600 transition relative" title="Report Mesin (<?= $dueCount ?> job)">
                                                                    <i class="fas fa-clipboard-check text-xs"></i>
                                                                    <span class="absolute -top-1.5 -right-1.5 bg-red-500 text-white text-[9px] font-black rounded-full min-w-[16px] h-4 px-0.5 flex items-center justify-center leading-none"><?= $dueCount ?></span>
                                                                </button>
                                                        <?php
                                                            endif;
                                                        endif;
                                                        ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach;
                                    else: ?>
                                        <tr>
                                            <td colspan="8" class="px-6 py-16 text-center text-slate-400">
                                                <i class="fas fa-shield-halved text-4xl mb-3 block text-slate-200"></i>
                                                <p class="font-medium">No data yet.</p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="px-6 py-3 bg-slate-50 border-t border-slate-100 flex items-center justify-between text-xs text-slate-400">
                            <span id="prevTableFooter">Menampilkan <?= count($prevSchedules) ?> jadwal</span>
                            <?php date_default_timezone_set('Asia/Jakarta'); ?>
                            <span>Last updated: <?= date('d M Y H:i') ?></span>
                        </div>
                    </div>
                </div><!-- /preventiveTab -->

            </div><!-- /max-w-[1400px] wrapper -->

            <!-- ========================= MODAL TAMBAH ========================= -->
            <div id="addModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm items-center justify-center z-50 p-4" style="display:none;">
                <div class="bg-white w-full max-w-4xl rounded-3xl shadow-2xl overflow-hidden">
                    <div class="bg-slate-800 px-8 py-6 flex justify-between items-center" style="background:linear-gradient(135deg, #5f0f40, #7a1a5a);">
                        <h3 class="text-xl font-bold text-white">Add Predictive Maintenance Schedule</h3>
                        <button onclick="hideModal('addModal')" class="text-slate-400 hover:text-white transition"><i class="fas fa-times text-xl"></i></button>
                    </div>
                    <form id="addForm" class="p-8 max-h-[85vh] overflow-y-auto">
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="dept_name" id="add_dept_name_val">
                        <input type="hidden" name="line_name" id="add_line_name_val">
                        <?php echo renderFormFields('add', $plants); ?>
                        <div class="flex justify-end gap-3">
                            <button type="button" onclick="hideModal('addModal')" class="px-8 py-3 font-bold text-slate-500 hover:bg-slate-100 rounded-xl transition">Cancel</button>
                            <button type="submit" class="bg-[#5f0f40] hover:bg-[#4a0b31] text-white px-10 py-3 rounded-xl font-black shadow-lg shadow-[#c97aad] transition-all">Save</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ========================= MODAL EDIT ========================= -->
            <div id="editModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm items-center justify-center z-50 p-4" style="display:none;">
                <div class="bg-white w-full max-w-4xl rounded-3xl shadow-2xl overflow-hidden">
                    <div class="bg-[#5f0f40] px-8 py-6 flex justify-between items-center">
                        <h3 class="text-xl font-bold text-white"><i class="fas fa-edit mr-2"></i>Edit Jadwal Predictive</h3>
                        <button onclick="hideModal('editModal')" class="text-[#e8c5da] hover:text-white transition"><i class="fas fa-times text-xl"></i></button>
                    </div>
                    <form id="editForm" class="p-8 max-h-[85vh] overflow-y-auto">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="edit_id" id="edit_id">
                        <input type="hidden" name="department" id="edit_dept_id_val">
                        <input type="hidden" name="line" id="edit_line_id_val">
                        <input type="hidden" name="dept_name" id="edit_dept_name_val">
                        <input type="hidden" name="line_name" id="edit_line_name_val">
                        <?php echo renderFormFields('edit', $plants); ?>
                        <!-- Schedule Status Indicator -->
                        <div class="mb-4 border-t pt-6">
                            <div id="edit_schedule_indicator" class="flex items-center gap-3 p-3 rounded-xl border" style="background:#f1f5f9;border-color:#e2e8f0;">
                                <span class="text-xs font-black text-slate-400 uppercase">Status Jadwal:</span>
                                <span id="edit_status_badge" class="badge badge-secure">Secure</span>
                                <span id="edit_status_desc" class="text-xs text-slate-400 ml-1"></span>
                            </div>
                            <div id="edit_auto_open_notice" class="mt-2 text-xs text-amber-700 font-semibold bg-amber-50 border border-amber-200 rounded-lg px-3 py-2" style="display:none;">
                                ⚠️ Part Order & Part Availability akan otomatis di-<b>open</b> karena jadwal masuk kondisi kritis.
                            </div>
                        </div>
                        <!-- Part Status Fields -->
                        <div class="grid grid-cols-2 gap-6 mb-6">
                            <div>
                                <label class="block text-xs font-black text-slate-500 uppercase mb-2">Part Order Status</label>
                                <select name="part_order" id="edit_part_order"
                                    class="w-full border border-slate-200 rounded-xl px-4 py-3 focus:ring-4 focus:ring-amber-100 outline-none transition text-sm font-bold">
                                    <option value="close">close</option>
                                    <option value="open">open</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-black text-slate-500 uppercase mb-2">Part Availability Status</label>
                                <select name="part_availability" id="edit_part_availability"
                                    class="w-full border border-slate-200 rounded-xl px-4 py-3 focus:ring-4 focus:ring-amber-100 outline-none transition text-sm font-bold">
                                    <option value="close">close</option>
                                    <option value="open">open</option>
                                </select>
                            </div>
                        </div>
                        <div class="flex justify-end gap-3">
                            <button type="button" onclick="hideModal('editModal')" class="px-8 py-3 font-bold text-slate-500 hover:bg-slate-100 rounded-xl transition">Batal</button>
                            <button type="submit" class="bg-[#5f0f40] hover:bg-[#4a0b31] text-white px-10 py-3 rounded-xl font-black shadow-lg shadow-[#c97aad] transition-all">Update Data</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ========================= MODAL IMPORT EXCEL ========================= -->
            <div id="importModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm items-center justify-center z-50 p-4" style="display:none;">
                <div class="bg-white w-full max-w-lg rounded-2xl shadow-2xl overflow-hidden">
                    <div class="bg-[#4a0b31] px-6 py-4 flex justify-between items-center">
                        <h3 class="text-base font-bold text-white"><i class="fas fa-file-excel mr-2"></i>Import data from excel</h3>
                        <button onclick="hideModal('importModal')" class="text-green-200 hover:text-white transition"><i class="fas fa-times text-lg"></i></button>
                    </div>
                    <div class="p-5">
                        <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 mb-4 text-sm">
                            <p class="font-black text-slate-600 mb-2 text-xs uppercase tracking-widest">📋 Mapping Kolom Excel → Dashboard</p>
                            <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-slate-600 font-medium text-xs">
                                <div class="flex items-center gap-1.5"><span class="bg-green-100 text-green-700 px-1.5 py-0.5 rounded font-mono text-[10px]">DEPARTEMENT</span><span class="text-slate-400">→</span><span>Department</span></div>
                                <div class="flex items-center gap-1.5"><span class="bg-green-100 text-green-700 px-1.5 py-0.5 rounded font-mono text-[10px]">LINE</span><span class="text-slate-400">→</span><span>Line</span></div>
                                <div class="flex items-center gap-1.5"><span class="bg-green-100 text-green-700 px-1.5 py-0.5 rounded font-mono text-[10px]">OPERATION PROCESS</span><span class="text-slate-400">→</span><span>Op. Process</span></div>
                                <div class="flex items-center gap-1.5"><span class="bg-green-100 text-green-700 px-1.5 py-0.5 rounded font-mono text-[10px]">MACHINE NAME</span><span class="text-slate-400">→</span><span>Machine Name</span></div>
                                <div class="flex items-center gap-1.5"><span class="bg-green-100 text-green-700 px-1.5 py-0.5 rounded font-mono text-[10px]">PROCESS MACHINE</span><span class="text-slate-400">→</span><span>Process Machine</span></div>
                                <div class="flex items-center gap-1.5"><span class="bg-green-100 text-green-700 px-1.5 py-0.5 rounded font-mono text-[10px]">NAME UNIT</span><span class="text-slate-400">→</span><span>Unit Name</span></div>
                                <div class="flex items-center gap-1.5"><span class="bg-green-100 text-green-700 px-1.5 py-0.5 rounded font-mono text-[10px]">MAINTENANCE POINT</span><span class="text-slate-400">→</span><span>Maint. Point</span></div>
                                <div class="flex items-center gap-1.5"><span class="bg-green-100 text-green-700 px-1.5 py-0.5 rounded font-mono text-[10px]">INTERVAL (MONTH)</span><span class="text-slate-400">→</span><span>Interval</span></div>
                                <div class="flex items-center gap-1.5"><span class="bg-green-100 text-green-700 px-1.5 py-0.5 rounded font-mono text-[10px]">USE DATE</span><span class="text-slate-400">→</span><span>Last Change</span></div>
                                <div class="flex items-center gap-1.5"><span class="bg-green-100 text-green-700 px-1.5 py-0.5 rounded font-mono text-[10px]">CHANGE DATE PLAN</span><span class="text-slate-400">→</span><span>Change Plan</span></div>
                                <div class="flex items-center gap-1.5"><span class="bg-green-100 text-green-700 px-1.5 py-0.5 rounded font-mono text-[10px]">REMINDER ACTIVITY</span><span class="text-slate-400">→</span><span>Reminder</span></div>
                                <div class="flex items-center gap-1.5"><span class="bg-green-100 text-green-700 px-1.5 py-0.5 rounded font-mono text-[10px]">REMAINING DAY</span><span class="text-slate-400">→</span><span>Remaining Day</span></div>
                            </div>
                        </div>

                        <div id="dropZone"
                            class="border-2 border-dashed border-slate-300 rounded-xl p-6 text-center cursor-pointer transition-all hover:border-green-500 hover:bg-green-50 mb-4"
                            onclick="document.getElementById('excelFileInput').click()"
                            ondragover="event.preventDefault();this.classList.add('dragover')"
                            ondragleave="this.classList.remove('dragover')"
                            ondrop="handleDrop(event)">
                            <i class="fas fa-cloud-upload-alt text-3xl text-slate-300 mb-2 block"></i>
                            <p class="font-bold text-slate-600 text-sm">Klik atau drag & drop file Excel di sini</p>
                            <p class="text-slate-400 text-xs mt-1">Format: .xlsx atau .xls &nbsp;|&nbsp; Maks: 10 MB</p>
                            <div id="selectedFileName" class="mt-3 hidden">
                                <span class="bg-green-100 text-green-700 font-bold px-3 py-1 rounded-full text-xs" id="fileNameLabel"></span>
                            </div>
                        </div>
                        <input type="file" id="excelFileInput" accept=".xlsx,.xls" class="hidden" onchange="handleFileSelect(event)">

                        <div id="importAlert" class="hidden rounded-xl p-3 mb-4 text-sm font-medium border"></div>

                        <div class="flex justify-end gap-3">
                            <button type="button" onclick="hideModal('importModal')"
                                class="px-6 py-2.5 font-bold text-slate-500 hover:bg-slate-100 rounded-xl transition text-sm">Batal</button>
                            <button id="btnStartImport" onclick="startImport()" disabled
                                class="bg-[#5f0f40] text-white px-8 py-2.5 rounded-xl font-black shadow-lg transition-all opacity-50 cursor-not-allowed flex items-center gap-2 text-sm">
                                <i class="fas fa-upload"></i> Import Sekarang
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Loading overlay -->
            <div id="importOverlay">
                <div class="bg-white rounded-3xl shadow-2xl p-10 text-center max-w-sm w-full mx-4">
                    <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-spinner fa-spin text-green-600 text-2xl"></i>
                    </div>
                    <p class="font-bold text-slate-700 text-lg mb-2">Sedang mengimport data...</p>
                    <p class="text-slate-400 text-sm mb-4">Mohon tunggu, jangan tutup halaman ini.</p>
                    <div class="w-full bg-slate-100 rounded-full h-2 overflow-hidden">
                        <div class="anim-bar bg-green-500 h-2 w-1/3 rounded-full"></div>
                    </div>
                </div>
            </div>

            <!-- MODAL: STATUS CARD LIST -->
            <div id="statusModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 items-center justify-center p-6" style="display:none;">
                <div class="bg-white w-full max-w-2xl rounded-3xl shadow-2xl overflow-hidden">
                    <div id="statusModalHeader" class="px-7 py-5 flex justify-between items-center">
                        <div>
                            <p class="text-white/60 text-[10px] font-black uppercase tracking-widest mb-0.5">Filter Status</p>
                            <h3 class="text-base font-black text-white" id="statusModalTitle">—</h3>
                        </div>
                        <button onclick="hideModal('statusModal')" class="text-white/60 hover:text-white w-8 h-8 flex items-center justify-center rounded-full hover:bg-white/10 transition">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <!-- Filter pilih hari (khusus kategori Alert, H-1 s.d H-7) -->
                    <div id="statusModalDayFilter" class="px-7 py-3 bg-amber-50 border-b border-amber-100 hidden">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-[10px] font-black uppercase tracking-widest text-amber-700 mr-1"><i class="fas fa-calendar-day mr-1"></i>Pilih Hari:</span>
                            <div id="statusModalDayChips" class="flex items-center gap-1.5 flex-wrap"></div>
                        </div>
                    </div>
                    <div style="max-height:360px;overflow-y:auto;">
                        <table class="w-full text-left border-collapse">
                            <thead style="position:sticky;top:0;background:#f8fafc;z-index:5;">
                                <tr class="border-b border-slate-100">
                                    <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Machine</th>
                                    <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Maintenance Point</th>
                                    <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Remaining</th>
                                    <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Plan Date</th>
                                </tr>
                            </thead>
                            <tbody id="statusModalBody" class="divide-y divide-slate-50 text-sm"></tbody>
                        </table>
                    </div>
                    <div class="px-7 py-4 bg-slate-50 border-t border-slate-100 flex justify-between items-center gap-3">
                        <span id="statusModalCount" class="text-xs text-slate-400 font-medium"></span>
                        <div class="flex items-center gap-2">
                            <button id="statusModalExportBtn" onclick="exportStatusModalExcel('predictive')" class="hidden px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl font-bold text-sm transition items-center gap-2">
                                <i class="fas fa-file-excel"></i> Export Excel
                            </button>
                            <button onclick="hideModal('statusModal')" class="px-5 py-2 bg-slate-800 text-white rounded-xl font-bold text-sm hover:bg-slate-700 transition">Tutup</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- MODAL: FORM REPORT -->
            <div id="reportModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 items-center justify-center p-4" style="display:none;">
                <div class="bg-white w-full max-w-md rounded-3xl shadow-2xl overflow-hidden">
                    <div class="bg-gradient-to-r from-[#5f0f40] to-[#7a1a5a] px-5 py-3  flex justify-between items-center">
                        <div>
                            <h3 class="text-base font-bold text-white"><i class="fas fa-clipboard-check mr-2"></i>Form Report Predictive Maintenance</h3>
                            <p class="text-emerald-100 text-xs mt-0.5" id="reportModalMachine">—</p>
                        </div>
                        <button onclick="hideModal('reportModal')" class="text-emerald-100 hover:text-white w-8 h-8 flex items-center justify-center rounded-full hover:bg-white/10 transition">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <form id="reportForm" class="p-5">
                        <input type="hidden" name="action" value="submit_report">
                        <input type="hidden" name="schedule_id" id="report_schedule_id">

                        <!-- Info part status warning -->
                        <div id="reportPartWarning" class="hidden mb-5 bg-red-50 border border-red-200 rounded-xl px-4 py-3 text-sm text-red-700 font-medium">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            <strong>Perhatian:</strong> Part Order atau Part Availability masih berstatus <strong>OPEN</strong>. Ubah ke <strong>CLOSE</strong> melalui tombol Edit sebelum submit report.
                        </div>

                        <!-- Info part status OK -->
                        <div id="reportPartOk" class="hidden mb-5 bg-emerald-50 border border-emerald-200 rounded-xl px-4 py-3 text-sm text-emerald-700 font-medium">
                            <i class="fas fa-check-circle mr-2"></i>Part Order & Part Availability sudah <strong>CLOSE</strong>. Siap untuk submit.
                        </div>

                        <div class="mb-3">
                            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Tanggal Aktual Pekerjaan <span class="text-red-500">*</span></label>
                            <input type="date" name="actual_date" id="report_actual_date" required
                                class="w-full border border-slate-200 rounded-xl px-4 py-2 focus:ring-4 focus:ring-emerald-100 outline-none transition text-sm">
                            <p class="text-xs text-slate-400 mt-1">Last Change akan diperbarui ke tanggal ini.</p>
                        </div>
                        <div class="mb-3">
                            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Teknisi <span class="text-red-500">*</span></label>
                            <input type="text" name="teknisi" id="report_teknisi" required
                                placeholder="Nama teknisi yang mengerjakan..."
                                class="w-full border border-slate-200 rounded-xl px-4 py-2 focus:ring-4 focus:ring-emerald-100 outline-none transition text-sm">
                        </div>
                        <div class="mb-3">
                            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Basis Jadwal Berikutnya <span class="text-red-500">*</span></label>
                            <div class="space-y-1.5 bg-slate-50 border border-slate-200 rounded-xl p-3">
                                <label class="flex items-start gap-2 text-xs text-slate-600 cursor-pointer">
                                    <input type="radio" name="next_basis" value="actual" checked class="mt-0.5 accent-[#5f0f40]">
                                    <span><strong>Sesuai Tanggal Pekerjaan</strong> — jadwal berikutnya = tanggal aktual pekerjaan + interval.</span>
                                </label>
                                <label class="flex items-start gap-2 text-xs text-slate-600 cursor-pointer">
                                    <input type="radio" name="next_basis" value="schedule" class="mt-0.5 accent-[#5f0f40]">
                                    <span><strong>Sesuai Jadwal Awal</strong> — jadwal berikutnya = Change Date Plan semula + interval (tidak bergeser meski pekerjaan/laporan telat).</span>
                                </label>
                                <label class="flex items-start gap-2 text-xs text-slate-600 cursor-pointer">
                                    <input type="radio" name="next_basis" value="report" class="mt-0.5 accent-[#5f0f40]">
                                    <span><strong>Sesuai Tanggal Pengisian Laporan</strong> — jadwal berikutnya = hari ini (saat laporan disubmit) + interval.</span>
                                </label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Keterangan / Note <span class="text-red-500">*</span></label>
                            <textarea name="note" id="report_note" rows="2" required
                                placeholder="Tuliskan detail pekerjaan maintenance yang dilakukan..."
                                class="w-full border border-slate-200 rounded-xl px-4 py-2 focus:ring-4 focus:ring-emerald-100 outline-none transition text-sm resize-none"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Foto Dokumentasi <span class="text-red-500">*</span></label>
                            <div class="border-2 border-dashed border-slate-200 rounded-xl p-3 text-center cursor-pointer hover:border-emerald-400 hover:bg-emerald-50/30 transition"
                                onclick="document.getElementById('report_photo').click()">
                                <i class="fas fa-image text-3xl text-slate-200 mb-1 block"></i>
                                <p class="text-sm text-slate-400 font-medium">Klik untuk pilih foto</p>
                                <p class="text-xs text-slate-300 mt-1">.jpg, .jpeg, .png — maks 5MB</p>
                                <p class="text-xs text-emerald-600 font-bold mt-2 hidden" id="photoNameLabel"></p>
                            </div>
                            <input type="file" name="photo" id="report_photo" accept=".jpg,.jpeg,.png,.webp" class="hidden"
                                onchange="document.getElementById('photoNameLabel').textContent=this.files[0]?.name||''; document.getElementById('photoNameLabel').classList.toggle('hidden',!this.files[0])">
                        </div>
                        <div id="reportAlert" class="hidden rounded-xl p-3 mb-3 text-sm font-medium border"></div>
                        <div class="flex justify-end gap-3">
                            <button type="button" onclick="hideModal('reportModal')" class="px-6 py-2 font-bold text-slate-400 hover:bg-slate-100 rounded-xl transition text-sm">Batal</button>
                            <button type="button" id="btnSubmitReport" onclick="submitReport()"
                                class="bg-[#5f0f40] hover:bg-[#4a0b31] text-white px-8 py-2 rounded-xl font-black shadow-lg transition text-sm">
                                <i class="fas fa-paper-plane mr-1"></i> Submit Report
                            </button>
                        </div>
                    </form>
                </div>
            </div>



            <script>
                // Data per status dari PHP
                const SCHED_BY_STATUS = <?= json_encode($schedByStatus, JSON_UNESCAPED_UNICODE) ?>;
                const PREV_BY_STATUS = <?= json_encode($prevByStatus, JSON_UNESCAPED_UNICODE) ?>;
                const STATUS_CFG = {
                    overdue: {
                        label: 'Overdue',
                        bg: 'linear-gradient(135deg,#ef4444,#b91c1c)'
                    },
                    alert: {
                        label: 'Alert (≤7 hari)',
                        bg: 'linear-gradient(135deg,#eab308,#854d0e)'
                    },
                    reminder: {
                        label: 'Reminder',
                        bg: 'linear-gradient(135deg,#f97316,#c2410c)'
                    },
                    secure: {
                        label: 'Secure',
                        bg: 'linear-gradient(135deg,#10b981,#047857)'
                    },
                };

                function showModal(id) {
                    document.getElementById(id).style.display = 'flex';
                }

                function hideModal(id) {
                    document.getElementById(id).style.display = 'none';
                }

                // Backdrop click — event delegation so it covers modals added later in DOM
                document.addEventListener('click', function(e) {
                    if (e.target.matches('[id$="Modal"]') || e.target.matches('[id$="StatusModal"]')) {
                        hideModal(e.target.id);
                    }
                });

                // ── Tab switching (animated pill) ─────────────────────────
                function switchTab(tab) {
                    const isPred = tab === 'predictive';

                    document.getElementById('predictiveContent').style.display = isPred ? '' : 'none';
                    document.getElementById('preventiveTab').style.display = isPred ? 'none' : '';

                    const predActions = document.getElementById('predictiveActions');
                    const prevActions = document.getElementById('preventiveActions');
                    if (predActions) predActions.style.setProperty('display', isPred ? 'flex' : 'none', 'important');
                    if (prevActions) prevActions.style.setProperty('display', !isPred ? 'flex' : 'none', 'important');
                    // Pill slide
                    const indicator = document.getElementById('tabIndicator');
                    indicator.style.transform = isPred ? 'translateX(0)' : 'translateX(calc(100% + 4px))';
                    indicator.style.background = isPred ?
                        'linear-gradient(135deg, #5f0f40, #7a1a5a)' :
                        'linear-gradient(135deg,#4338ca,#4f46e5)';
                    // Text colors
                    document.getElementById('tabPredictive').style.color = isPred ? '#fff' : '#64748b';
                    document.getElementById('tabPreventive').style.color = !isPred ? '#fff' : '#64748b';
                    // Badges
                    const bp = document.getElementById('badgePredictive');
                    const bv = document.getElementById('badgePreventive');
                    if (bp) bp.className = isPred ? 'ml-1 bg-white/20 text-white text-[10px] font-black px-2 py-0.5 rounded-full' : 'ml-1 bg-slate-300/60 text-slate-600 text-[10px] font-black px-2 py-0.5 rounded-full';
                    if (bv) bv.className = !isPred ? 'ml-1 bg-white/20 text-white text-[10px] font-black px-2 py-0.5 rounded-full' : 'ml-1 bg-slate-300/60 text-slate-600 text-[10px] font-black px-2 py-0.5 rounded-full';
                }

                // ── Status card modal ─────────────────────────────
                // State filter hari aktif per modal (predictive & preventive terpisah)
                const dayFilterState = {
                    predictive: 'all',
                    preventive: 'all'
                };

                function renderStatusModalRows(tbodyId, items) {
                    const tbody = document.getElementById(tbodyId);
                    if (!items.length) {
                        tbody.innerHTML = `<tr><td colspan="4" class="px-6 py-10 text-center text-slate-400 text-sm">
                    <i class="fas fa-inbox text-3xl block mb-2 text-slate-200"></i>Tidak ada jadwal</td></tr>`;
                        return;
                    }
                    tbody.innerHTML = items.map(r => `<tr class="hover:bg-slate-50 transition-colors">
                    <td class="px-5 py-3">
                        <div class="font-bold text-slate-700 text-sm">${esc(r.machine_name||'')}${r.operation_process ? ' | ' + esc(r.operation_process) : ''}</div>
                        <div class="text-xs text-slate-400">${esc((r.department||'').toUpperCase())} | ${esc((r.line||'').toUpperCase())}</div>
                    </td>
                    <td class="px-5 py-3 text-slate-600 text-sm max-w-[180px]">${esc(r.maintenance_point||'-')}</td>
                    <td class="px-5 py-3 text-center font-black text-base ${parseInt(r.remaining_day)<=0?'style="color:#ef4444"':''}">${r.remaining_day} hari</td>
                    <td class="px-5 py-3 text-center text-xs text-slate-500 whitespace-nowrap">${r.change_date_plan||'-'}</td>
                </tr>`).join('');
                }

                // Render chip "Pilih Hari" hanya untuk hari-hari yang benar-benar ada datanya.
                // type: 'predictive' | 'preventive' — dipakai untuk key dayFilterState & export.
                function renderDayChips(chipsContainerId, allItems, type, onFilterChange) {
                    const container = document.getElementById(chipsContainerId);
                    const daysPresent = [...new Set(allItems.map(r => parseInt(r.remaining_day)))]
                        .filter(d => d >= 1 && d <= 7)
                        .sort((a, b) => a - b);

                    const activeDay = dayFilterState[type];
                    const chipBase = 'px-3 py-1 rounded-lg text-xs font-bold transition cursor-pointer border';
                    const chipActive = 'bg-amber-500 text-white border-amber-500';
                    const chipInactive = 'bg-white text-amber-700 border-amber-200 hover:bg-amber-100';

                    let html = `<span data-day="all" class="${chipBase} ${activeDay === 'all' ? chipActive : chipInactive}">Semua</span>`;
                    daysPresent.forEach(d => {
                        const isActive = String(activeDay) === String(d);
                        html += `<span data-day="${d}" class="${chipBase} ${isActive ? chipActive : chipInactive}">H-${d}</span>`;
                    });
                    container.innerHTML = html;

                    container.querySelectorAll('[data-day]').forEach(chip => {
                        chip.addEventListener('click', () => {
                            dayFilterState[type] = chip.dataset.day;
                            onFilterChange();
                        });
                    });
                }

                // Render chip "Pilih Hari" untuk kategori Overdue: H0, H+1 ... H+7, dan >H+7.
                // Hanya render chip untuk bucket yang benar-benar ada datanya.
                // type: 'predictive' | 'preventive' — dipakai untuk key dayFilterState.
                function renderOverdueDayChips(chipsContainerId, allItems, type, onFilterChange) {
                    const container = document.getElementById(chipsContainerId);

                    // Bucket: 0 → H0, 1..7 → H+1..H+7 (dari remaining_day -1..-7), 'over7' → lebih dari H+7
                    const bucketOf = (remDay) => {
                        const d = -remDay; // overdue → remaining_day negatif/0, ubah jadi angka hari lewat (positif)
                        if (d <= 0) return 0;
                        if (d <= 7) return d;
                        return 'over7';
                    };

                    const bucketsPresent = [...new Set(allItems.map(r => bucketOf(parseInt(r.remaining_day))))];
                    const orderedBuckets = [0, 1, 2, 3, 4, 5, 6, 7, 'over7'].filter(b => bucketsPresent.includes(b));

                    const activeDay = dayFilterState[type];
                    const chipBase = 'px-3 py-1 rounded-lg text-xs font-bold transition cursor-pointer border';
                    const chipActive = 'bg-red-500 text-white border-red-500';
                    const chipInactive = 'bg-white text-red-700 border-red-200 hover:bg-red-100';

                    let html = `<span data-day="all" class="${chipBase} ${activeDay === 'all' ? chipActive : chipInactive}">Semua</span>`;
                    orderedBuckets.forEach(b => {
                        const isActive = String(activeDay) === String(b);
                        const label = b === 0 ? 'H0' : (b === 'over7' ? '> H+7' : `H+${b}`);
                        html += `<span data-day="${b}" class="${chipBase} ${isActive ? chipActive : chipInactive}">${label}</span>`;
                    });
                    container.innerHTML = html;

                    container.querySelectorAll('[data-day]').forEach(chip => {
                        chip.addEventListener('click', () => {
                            dayFilterState[type] = chip.dataset.day;
                            onFilterChange();
                        });
                    });
                }

                // Menyimpan kondisi (alert/overdue) yang sedang aktif per type, dipakai saat export.
                const statusModalCondition = {
                    predictive: 'alert',
                    preventive: 'alert'
                };

                const overdueBucketOf = (remDay) => {
                    const d = -remDay; // overdue → remaining_day negatif/0, ubah jadi angka hari lewat (positif)
                    if (d <= 0) return '0';
                    if (d <= 7) return String(d);
                    return 'over7';
                };

                function openStatusModal(status) {
                    const cfg = STATUS_CFG[status] || {};
                    const allItems = SCHED_BY_STATUS[status] || [];
                    const isAlert = status === 'alert';
                    const isOverdue = status === 'overdue';
                    statusModalCondition.predictive = isOverdue ? 'overdue' : 'alert';
                    dayFilterState.predictive = 'all';

                    document.getElementById('statusModalHeader').style.background = cfg.bg || '#334155';

                    const dayFilterWrap = document.getElementById('statusModalDayFilter');
                    const exportBtn = document.getElementById('statusModalExportBtn');
                    dayFilterWrap.classList.toggle('hidden', !(isAlert || isOverdue));
                    exportBtn.classList.toggle('hidden', !(isAlert || isOverdue));
                    if (isAlert || isOverdue) exportBtn.classList.add('flex');
                    else exportBtn.classList.remove('flex');

                    function refresh() {
                        const day = dayFilterState.predictive;
                        let filtered = allItems;
                        if (isAlert && day !== 'all') {
                            filtered = allItems.filter(r => String(parseInt(r.remaining_day)) === String(day));
                        } else if (isOverdue && day !== 'all') {
                            filtered = allItems.filter(r => overdueBucketOf(parseInt(r.remaining_day)) === String(day));
                        }
                        document.getElementById('statusModalTitle').textContent = (cfg.label || status) + ' — ' + filtered.length + ' jadwal';
                        document.getElementById('statusModalCount').textContent = filtered.length + ' jadwal ditemukan';
                        renderStatusModalRows('statusModalBody', filtered);
                        // [FIX] Render ulang chip supaya highlight "active" pindah ke chip yang baru diklik.
                        // Sebelumnya chip hanya digambar sekali di awal, jadi walau data tabel sudah
                        // terfilter benar, tampilan chip tetap "Semua" terus karena tidak pernah digambar ulang.
                        if (isAlert) renderDayChips('statusModalDayChips', allItems, 'predictive', refresh);
                        else if (isOverdue) renderOverdueDayChips('statusModalDayChips', allItems, 'predictive', refresh);
                    }

                    refresh();
                    showModal('statusModal');
                }

                // Memicu download export Excel sesuai filter hari & kondisi (alert/overdue) yang sedang aktif di modal.
                // type: 'predictive' | 'preventive'
                function exportStatusModalExcel(type) {
                    const day = dayFilterState[type] || 'all';
                    const condition = statusModalCondition[type] || 'alert';
                    window.location.href = `export_alert_schedule.php?type=${type}&day=${encodeURIComponent(day)}&condition=${encodeURIComponent(condition)}`;
                }

                function esc(s) {
                    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                }

                // ── Report modal ──────────────────────────────────
                function showReportModal(id, machineName) {
                    document.getElementById('report_schedule_id').value = id;
                    document.getElementById('reportModalMachine').textContent = machineName;
                    document.getElementById('reportForm').reset();
                    document.getElementById('report_actual_date').value = new Date().toISOString().split('T')[0];
                    document.getElementById('photoNameLabel').classList.add('hidden');
                    document.getElementById('reportAlert').classList.add('hidden');
                    document.getElementById('reportPartWarning').classList.add('hidden');
                    document.getElementById('reportPartOk').classList.add('hidden');
                    // Fetch part status dari server
                    fetch(`?get_schedule=${id}`)
                        .then(r => r.json())
                        .then(data => {
                            if (!data) return;
                            const partOpen = (data.part_order === 'open' || data.part_availability === 'open');
                            document.getElementById('reportPartWarning').classList.toggle('hidden', !partOpen);
                            document.getElementById('reportPartOk').classList.toggle('hidden', partOpen);
                            document.getElementById('btnSubmitReport').disabled = partOpen;
                            document.getElementById('btnSubmitReport').className = partOpen ?
                                'bg-slate-300 text-slate-400 cursor-not-allowed px-8 py-3 rounded-xl font-black transition text-sm' :
                                'bg-emerald-600 hover:bg-emerald-700 text-white px-8 py-3 rounded-xl font-black shadow-lg transition text-sm';
                        })
                        .catch(() => {});
                    showModal('reportModal');
                }
                async function submitReport() {
                    // Validasi wajib isi
                    const teknisi = document.getElementById('report_teknisi')?.value?.trim();
                    const note = document.getElementById('report_note')?.value?.trim();
                    const photo = document.getElementById('report_photo')?.files[0];
                    const actualDate = document.getElementById('report_actual_date')?.value;

                    if (!actualDate) {
                        showAlert('reportAlert', 'error', '❌ Tanggal aktual pekerjaan wajib diisi.');
                        return;
                    }
                    if (!teknisi) {
                        showAlert('reportAlert', 'error', '❌ Nama teknisi wajib diisi.');
                        return;
                    }
                    if (!note) {
                        showAlert('reportAlert', 'error', '❌ Keterangan / Note wajib diisi.');
                        return;
                    }
                    if (!photo) {
                        showAlert('reportAlert', 'error', '❌ Foto dokumentasi wajib dilampirkan.');
                        return;
                    }

                    const fd = new FormData(document.getElementById('reportForm'));
                    const btn = document.getElementById('btnSubmitReport');
                    if (btn.disabled) return;
                    btn.disabled = true;
                    btn.textContent = 'Menyimpan...';
                    try {
                        const r = await (await fetch('', {
                            method: 'POST',
                            body: fd
                        })).json();
                        if (r.status === 'success') {
                            showAlert('reportAlert', 'success', '✅ ' + r.message);
                            setTimeout(() => {
                                hideModal('reportModal');
                                location.reload();
                            }, 1800);
                        } else {
                            showAlert('reportAlert', 'error', '❌ ' + (r.message || 'Gagal'));
                            btn.disabled = false;
                            btn.innerHTML = '<i class="fas fa-paper-plane mr-1"></i> Submit Report';
                            btn.className = 'bg-[#5f0f40] hover:bg-[#4a0b31] text-white px-8 py-3 rounded-xl font-black shadow-lg transition text-sm';
                        }
                    } catch (e) {
                        showAlert('reportAlert', 'error', '❌ Gagal: ' + e.message);
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-paper-plane mr-1"></i> Submit Report';
                        btn.className = 'bg-[#5f0f40] hover:bg-[#4a0b31] text-white px-8 py-3 rounded-xl font-black shadow-lg transition text-sm';
                    }
                }

                function showAlert(elId, type, msg) {
                    const el = document.getElementById(elId);
                    el.className = 'rounded-xl p-3 mb-4 text-sm font-medium border ' +
                        (type === 'success' ? 'bg-green-50 text-green-800 border-green-200' : 'bg-red-50 text-red-800 border-red-200');
                    el.textContent = msg;
                    el.classList.remove('hidden');
                }

                function showAddModal() {
                    document.getElementById('addForm').reset();
                    resetDropdowns('add');
                    showModal('addModal');
                }

                // ── Search + Line Filter ──────────────────────────────
                function applyFilters() {
                    const q = (document.getElementById('searchInput')?.value || '').toLowerCase().trim();
                    const line = (document.getElementById('filterLine')?.value || '').toLowerCase().trim();
                    let shown = 0;
                    document.querySelectorAll('#schedBody tr.sched-row').forEach(row => {
                        const ms = !q || (row.dataset.search || '').includes(q);
                        const ml = !line || (row.dataset.line || '').toLowerCase() === line;
                        row.style.display = (ms && ml) ? '' : 'none';
                        if (ms && ml) shown++;
                    });
                    const total = <?= count($schedules) ?>;
                    const lbl = document.getElementById('rowCountLabel');
                    const ft = document.getElementById('tableFooter');
                    if (lbl) lbl.textContent = (q || line) ? `${shown} dari ${total} jadwal` : `${total} jadwal`;
                    if (ft) ft.textContent = (q || line) ? `Menampilkan ${shown} dari ${total} jadwal` : `Menampilkan ${total} jadwal`;
                }

                async function showEditModal(id) {
                    const res = await fetch(`?get_schedule=${id}`);
                    const data = await res.json();
                    if (!data) return alert('Data tidak ditemukan');
                    document.getElementById('edit_id').value = data.id;
                    // Isi hidden ID fields untuk department dan line (integer FK)
                    const editDeptIdEl = document.getElementById('edit_dept_id_val');
                    const editLineIdEl = document.getElementById('edit_line_id_val');
                    if (editDeptIdEl) editDeptIdEl.value = data.department ?? '';
                    if (editLineIdEl) editLineIdEl.value = data.line ?? '';
                    // Part status selects — populate dari DB
                    const poSel = document.getElementById('edit_part_order');
                    const paSel = document.getElementById('edit_part_availability');
                    if (poSel) poSel.value = data.part_order || 'close';
                    if (paSel) paSel.value = data.part_availability || 'close';
                    document.getElementById('edit_machine_name').value = data.machine_name ?? '';
                    document.getElementById('edit_process_machine').value = data.process_machine ?? '';
                    document.getElementById('edit_name_unit').value = data.name_unit ?? '';
                    document.getElementById('edit_maintenance_point').value = data.maintenance_point ?? '';
                    document.getElementById('edit_use_date').value = data.use_date ?? '';
                    document.getElementById('edit_interval_month').value = data.interval_month ?? '';
                    document.getElementById('edit_change_date_plan').value = data.change_date_plan ?? '';
                    document.getElementById('edit_reminder_activity').value = data.reminder_activity ?? '';
                    document.getElementById('edit_dept_name_val').value = data.department ?? '';
                    document.getElementById('edit_line_name_val').value = data.line ?? '';
                    document.getElementById('edit_dept_display').value = data.department ?? '';
                    document.getElementById('edit_line_display').value = data.line ?? '';
                    document.getElementById('edit_op_display').value = data.operation_process ?? '';
                    showModal('editModal');
                    // Jalankan indikator saat modal dibuka
                    updateEditScheduleIndicator();
                }

                // ── Indikator status jadwal real-time di modal edit ──
                function updateEditScheduleIndicator() {
                    const useDate = document.getElementById('edit_use_date')?.value;
                    const intervalVal = parseInt(document.getElementById('edit_interval_month')?.value) || 0;
                    const changeDateEl = document.getElementById('edit_change_date_plan');
                    const reminderEl = document.getElementById('edit_reminder_activity');
                    const badge = document.getElementById('edit_status_badge');
                    const desc = document.getElementById('edit_status_desc');
                    const notice = document.getElementById('edit_auto_open_notice');
                    const poSel = document.getElementById('edit_part_order');
                    const paSel = document.getElementById('edit_part_availability');
                    if (!badge || !changeDateEl) return;

                    const changeDate = changeDateEl.value;
                    const reminder = parseInt(reminderEl?.value) || 0;

                    if (!changeDate) {
                        badge.className = 'badge badge-secure';
                        badge.textContent = 'Secure';
                        desc.textContent = '';
                        notice.style.display = 'none';
                        return;
                    }

                    // Hitung remaining_day di sisi client
                    const today = new Date();
                    today.setHours(0, 0, 0, 0);
                    const planDate = new Date(changeDate);
                    planDate.setHours(0, 0, 0, 0);
                    const diffMs = planDate - today;
                    const remaining = Math.round(diffMs / (1000 * 60 * 60 * 24));

                    let status, badgeClass, descText, isCritical;

                    if (remaining <= 0) {
                        status = 'Overdue';
                        badgeClass = 'badge badge-overdue';
                        descText = `${Math.abs(remaining)} hari terlewat`;
                        isCritical = true;
                    } else if (remaining <= 7) {
                        status = 'H-' + remaining;
                        badgeClass = 'badge badge-alert';
                        descText = `${remaining} hari lagi`;
                        isCritical = true;
                    } else if (reminder > 0 && remaining <= reminder) {
                        status = 'Reminder';
                        badgeClass = 'badge badge-reminder';
                        descText = `${remaining} hari lagi (dalam window reminder)`;
                        isCritical = true;
                    } else {
                        status = 'Secure';
                        badgeClass = 'badge badge-secure';
                        descText = `${remaining} hari lagi`;
                        isCritical = false;
                    }

                    badge.className = badgeClass;
                    badge.textContent = status;
                    desc.textContent = descText;

                    if (isCritical) {
                        // Hanya tampilkan notice — select tetap bisa diubah manual oleh user
                        notice.style.display = 'block';
                    } else {
                        notice.style.display = 'none';
                    }
                }

                // Attach listener ke field yang mempengaruhi status
                document.addEventListener('DOMContentLoaded', function() {
                    ['edit_change_date_plan', 'edit_reminder_activity', 'edit_interval_month', 'edit_use_date'].forEach(id => {
                        const el = document.getElementById(id);
                        if (el) el.addEventListener('change', updateEditScheduleIndicator);
                        if (el) el.addEventListener('input', updateEditScheduleIndicator);
                    });
                });

                function setDropdownEnabled(el, enabled) {
                    el.disabled = !enabled;
                    el.className = enabled ?
                        'w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 focus:ring-4 focus:ring-[#f2d4e8] outline-none transition cursor-pointer' :
                        'w-full bg-slate-100 border border-slate-200 rounded-xl px-4 py-3 outline-none cursor-not-allowed';
                }

                function resetDropdowns(prefix) {
                    const ls = document.getElementById(`${prefix}_line_select`);
                    const os = document.getElementById(`${prefix}_op_select`);
                    ls.innerHTML = '<option value="">-- Choose Line --</option>';
                    setDropdownEnabled(ls, false);
                    os.innerHTML = '<option value="">-- Choose Process --</option>';
                    setDropdownEnabled(os, false);
                }
                async function handleDeptChange(prefix) {
                    const ds = document.getElementById(`${prefix}_dept_select`);
                    const ls = document.getElementById(`${prefix}_line_select`);
                    // Simpan dept name (value sekarang adalah nama department langsung)
                    if (document.getElementById(`${prefix}_dept_name_val`)) {
                        document.getElementById(`${prefix}_dept_name_val`).value = ds.value || '';
                    }
                    if (!ds.value) return resetDropdowns(prefix);
                    // get_lines sekarang menerima nama department
                    const data = await (await fetch(`?get_lines=${encodeURIComponent(ds.value)}`)).json();
                    ls.innerHTML = '<option value="">-- Choose Line --</option>';
                    data.forEach(l => ls.innerHTML += `<option value="${l.id}" data-name="${l.line_name}">${l.line_name}</option>`);
                    setDropdownEnabled(ls, true);
                }
                async function handleLineChange(prefix) {
                    const ds = document.getElementById(`${prefix}_dept_select`);
                    const ls = document.getElementById(`${prefix}_line_select`);
                    const os = document.getElementById(`${prefix}_op_select`);
                    if (document.getElementById(`${prefix}_line_name_val`)) {
                        document.getElementById(`${prefix}_line_name_val`).value =
                            ls.options[ls.selectedIndex].getAttribute('data-name') || '';
                    }
                    if (!ls.value) return;
                    // get_ops sekarang menerima line name + dept name
                    const data = await (await fetch(`?get_ops=${encodeURIComponent(ls.value)}&dept=${encodeURIComponent(ds.value)}`)).json();
                    os.innerHTML = '<option value="">-- Choose Process --</option>';
                    // Deduplicate by OP (ambil machine pertama per OP unik)
                    const seen = new Set();
                    data.forEach(o => {
                        if (seen.has(o.operation_process)) return;
                        seen.add(o.operation_process);
                        os.innerHTML +=
                            `<option value="${o.operation_process}" data-machine="${o.machine_name||''}" data-procmachine="${o.process_machine||''}">${o.operation_process}</option>`;
                    });
                    setDropdownEnabled(os, true);
                }

                function handleOpChange(prefix) {
                    const os = document.getElementById(`${prefix}_op_select`);
                    const s = os.options[os.selectedIndex];
                    document.getElementById(`${prefix}_machine_name`).value = s.getAttribute('data-machine') || '';
                    document.getElementById(`${prefix}_process_machine`).value = s.getAttribute('data-procmachine') || '';
                }

                function checkAutoCalculate(prefix) {
                    const cb = document.getElementById(`${prefix}_auto_calc`);
                    const ud = document.getElementById(`${prefix}_use_date`).value;
                    const iv = parseInt(document.getElementById(`${prefix}_interval_month`).value);
                    const pi = document.getElementById(`${prefix}_change_date_plan`);
                    if (cb.checked && ud && iv) {
                        let d = new Date(ud);
                        d.setMonth(d.getMonth() + iv);
                        pi.value = d.toISOString().split('T')[0];
                        pi.readOnly = true;
                        pi.classList.add('bg-slate-100');
                    } else {
                        pi.readOnly = false;
                        pi.classList.remove('bg-slate-100');
                    }
                }

                document.getElementById('addForm').addEventListener('submit', async function(e) {
                    e.preventDefault();
                    const r = await (await fetch('', {
                        method: 'POST',
                        body: new FormData(this)
                    })).json();
                    if (r.status === 'success') location.reload();
                    else alert(r.message);
                });
                document.getElementById('editForm').addEventListener('submit', async function(e) {
                    e.preventDefault();
                    const r = await (await fetch('', {
                        method: 'POST',
                        body: new FormData(this)
                    })).json();
                    if (r.status === 'success') location.reload();
                    else alert(r.message);
                });

                let selectedImportFile = null;

                function showImportModal() {
                    selectedImportFile = null;
                    document.getElementById('excelFileInput').value = '';
                    document.getElementById('selectedFileName').classList.add('hidden');
                    document.getElementById('fileNameLabel').textContent = '';
                    document.getElementById('importAlert').classList.add('hidden');
                    const btn = document.getElementById('btnStartImport');
                    btn.disabled = true;
                    btn.classList.add('opacity-50', 'cursor-not-allowed');
                    btn.classList.remove('hover:bg-green-700');
                    showModal('importModal');
                }

                function setImportFile(file) {
                    const ext = file.name.split('.').pop().toLowerCase();
                    if (!['xlsx', 'xls'].includes(ext)) {
                        showImportAlert('error', '❌ Format file harus .xlsx atau .xls');
                        return;
                    }
                    if (file.size > 10 * 1024 * 1024) {
                        showImportAlert('error', '❌ Ukuran file maksimal 10MB');
                        return;
                    }
                    selectedImportFile = file;
                    document.getElementById('fileNameLabel').textContent = `📄 ${file.name}`;
                    document.getElementById('selectedFileName').classList.remove('hidden');
                    document.getElementById('importAlert').classList.add('hidden');
                    const btn = document.getElementById('btnStartImport');
                    btn.disabled = false;
                    btn.classList.remove('opacity-50', 'cursor-not-allowed');
                    btn.classList.add('hover:bg-green-700');
                }

                function handleFileSelect(e) {
                    if (e.target.files[0]) setImportFile(e.target.files[0]);
                }

                function handleDrop(e) {
                    e.preventDefault();
                    document.getElementById('dropZone').classList.remove('dragover');
                    if (e.dataTransfer.files[0]) setImportFile(e.dataTransfer.files[0]);
                }
                async function startImport() {
                    if (!selectedImportFile) return;
                    document.getElementById('importOverlay').classList.add('active');
                    const fd = new FormData();
                    fd.append('excel_file', selectedImportFile);
                    try {
                        const res = await fetch('import_excel.php', {
                            method: 'POST',
                            body: fd
                        });
                        const result = await res.json();
                        document.getElementById('importOverlay').classList.remove('active');
                        if (result.status === 'success') {
                            showImportAlert('success', `✅ ${result.message}`);
                            setTimeout(() => location.reload(), 2000);
                            alert(result.message); // Menampilkan "X data berhasil diimport"
                        } else {
                            showImportAlert('error', `❌ ${result.message}`);
                            if (result.errors?.length) console.warn('Import errors:', result.errors);
                            alert("Gagal: " + result.message + "\nDetail: " + (result.errors ? result.errors.join(", ") : ""));
                        }
                    } catch (err) {
                        document.getElementById('importOverlay').classList.remove('active');
                        showImportAlert('error', `❌ Gagal menghubungi server: ${err.message}`);
                    }
                }

                function showImportAlert(type, msg) {
                    const el = document.getElementById('importAlert');
                    el.className = 'rounded-2xl p-4 mb-6 text-sm font-medium border';
                    if (type === 'success') {
                        el.classList.add('bg-green-50', 'text-green-800', 'border-green-200');
                    } else {
                        el.classList.add('bg-red-50', 'text-red-800', 'border-red-200');
                    }
                    el.textContent = msg;
                    el.classList.remove('hidden');
                }

                // ═══════════════════════════════════════════════════════
                //  PREVENTIVE MAINTENANCE — JavaScript
                // ═══════════════════════════════════════════════════════

                // Filter tabel preventive
                function applyPrevFilters() {
                    const q = (document.getElementById('prevSearchInput')?.value || '').toLowerCase().trim();
                    const line = (document.getElementById('prevFilterLine')?.value || '').toLowerCase().trim();
                    let shown = 0;
                    document.querySelectorAll('#prevSchedBody tr.prev-sched-row').forEach(row => {
                        const ms = !q || (row.dataset.search || '').includes(q);
                        const ml = !line || (row.dataset.line || '').toLowerCase() === line;
                        row.style.display = (ms && ml) ? '' : 'none';
                        if (ms && ml) shown++;
                    });
                    const total = <?= count($prevSchedules) ?>;
                    const lbl = document.getElementById('prevRowCountLabel');
                    const ft = document.getElementById('prevTableFooter');
                    const txt = (q || line) ? `${shown} dari ${total} jadwal` : `${total} jadwal`;
                    if (lbl) lbl.textContent = txt;
                    if (ft) ft.textContent = `Menampilkan ${txt}`;
                }

                function renderPrevStatusModalRows(items) {
                    const tbody = document.getElementById('prevStatusModalBody');
                    if (!items.length) {
                        tbody.innerHTML = `<tr><td colspan="4" class="px-6 py-10 text-center text-slate-400 text-sm italic">Tidak ada jadwal</td></tr>`;
                        return;
                    }
                    tbody.innerHTML = items.map(r => `<tr class="hover:bg-slate-50 transition-colors">
                    <td class="px-5 py-3">
                        <div class="font-bold text-slate-700 text-sm">${esc(r.machine_name||'')}${r.operation_process ? ' | ' + esc(r.operation_process) : ''}</div>
                        <div class="text-xs text-slate-400">${esc((r.department||'').toUpperCase())} | ${esc((r.line||'').toUpperCase())}</div>
                    </td>
                    <td class="px-5 py-3 text-slate-600 text-sm">${esc(r.maintenance_point||'-')}</td>
                    <td class="px-5 py-3 text-center font-black text-base ${parseInt(r.remaining_day)<=0?'text-red-500':''}">${r.remaining_day} hari</td>
                    <td class="px-5 py-3 text-center text-xs text-slate-500 whitespace-nowrap">${r.change_date_plan||'-'}</td>
                </tr>`).join('');
                }

                // Status modal preventive
                function openPrevStatusModal(status) {
                    const cfg = STATUS_CFG[status] || {};
                    const allItems = PREV_BY_STATUS[status] || [];
                    const isAlert = status === 'alert';
                    const isOverdue = status === 'overdue';
                    statusModalCondition.preventive = isOverdue ? 'overdue' : 'alert';
                    dayFilterState.preventive = 'all';

                    document.getElementById('prevStatusModalHeader').style.background = cfg.bg || '#7a1355';

                    const dayFilterWrap = document.getElementById('prevStatusModalDayFilter');
                    const exportBtn = document.getElementById('prevStatusModalExportBtn');
                    dayFilterWrap.classList.toggle('hidden', !(isAlert || isOverdue));
                    exportBtn.classList.toggle('hidden', !(isAlert || isOverdue));
                    if (isAlert || isOverdue) exportBtn.classList.add('flex');
                    else exportBtn.classList.remove('flex');

                    function refresh() {
                        const day = dayFilterState.preventive;
                        let filtered = allItems;
                        if (isAlert && day !== 'all') {
                            filtered = allItems.filter(r => String(parseInt(r.remaining_day)) === String(day));
                        } else if (isOverdue && day !== 'all') {
                            filtered = allItems.filter(r => overdueBucketOf(parseInt(r.remaining_day)) === String(day));
                        }
                        document.getElementById('prevStatusModalTitle').textContent = (cfg.label || status) + ' — ' + filtered.length + ' jadwal';
                        document.getElementById('prevStatusModalCount').textContent = filtered.length + ' jadwal ditemukan';
                        renderPrevStatusModalRows(filtered);
                        // [FIX] Render ulang chip supaya highlight "active" pindah ke chip yang baru diklik.
                        // Sebelumnya chip hanya digambar sekali di awal, jadi walau data tabel sudah
                        // terfilter benar, tampilan chip tetap "Semua" terus karena tidak pernah digambar ulang.
                        if (isAlert) renderDayChips('prevStatusModalDayChips', allItems, 'preventive', refresh);
                        else if (isOverdue) renderOverdueDayChips('prevStatusModalDayChips', allItems, 'preventive', refresh);
                    }

                    refresh();
                    showModal('prevStatusModal');
                }

                // Add modal preventive
                function showPrevAddModal() {
                    document.getElementById('prevAddForm').reset();
                    resetDropdowns('prev_add');
                    showModal('prevAddModal');
                }

                // Edit modal preventive
                async function showPrevEditModal(id) {
                    const res = await fetch(`?get_prev_schedule=${id}`);
                    const data = await res.json();
                    if (!data) {
                        alert('Data tidak ditemukan');
                        return;
                    }
                    document.getElementById('prev_edit_id').value = data.id;
                    // Isi hidden ID fields untuk department dan line (integer FK)
                    const prevDeptIdEl = document.getElementById('prev_edit_dept_id_val');
                    const prevLineIdEl = document.getElementById('prev_edit_line_id_val');
                    if (prevDeptIdEl) prevDeptIdEl.value = data.department ?? '';
                    if (prevLineIdEl) prevLineIdEl.value = data.line ?? '';
                    document.getElementById('prev_edit_machine_name').value = data.machine_name ?? '';
                    document.getElementById('prev_edit_process_machine').value = data.process_machine ?? '';
                    document.getElementById('prev_edit_name_unit').value = data.name_unit ?? '';
                    document.getElementById('prev_edit_maintenance_point').value = data.maintenance_point ?? '';
                    document.getElementById('prev_edit_use_date').value = data.use_date ?? '';
                    document.getElementById('prev_edit_interval_month').value = data.interval_month ?? '';
                    document.getElementById('prev_edit_change_date_plan').value = data.change_date_plan ?? '';
                    document.getElementById('prev_edit_reminder_activity').value = data.reminder_activity ?? '';
                    document.getElementById('prev_edit_dept_display').value = data.department ?? '';
                    document.getElementById('prev_edit_line_display').value = data.line ?? '';
                    document.getElementById('prev_edit_op_display').value = data.operation_process ?? '';
                    document.getElementById('prev_edit_dept_name_val').value = data.department ?? '';
                    document.getElementById('prev_edit_line_name_val').value = data.line ?? '';
                    // maintenance_status dikelola otomatis oleh server — tidak perlu diisi
                    showModal('prevEditModal');
                }
                // Data job yang due/bisa direport, dikelompokkan per machine_name (dari PHP)
                const prevDueJobsData = <?= json_encode($prevMachineDueJobs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
                const technicianListData = <?= json_encode($technicianList, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

                // Report modal preventive — per mesin, checklist multi-select
                // Job dikelompokkan berdasarkan Department → Line → Operation Process
                // agar user mudah mencari & urut.
                function showPrevMachineReportModal(machineName) {
                    document.getElementById('prevMachineReportModalMachine').textContent = machineName;
                    const jobs = prevDueJobsData[machineName] || [];
                    const listEl = document.getElementById('prevMachineReportJobsList');
                    const today = new Date().toISOString().split('T')[0];

                    const jobCardHtml = (job, idx) => `
                        <div class="border border-slate-200 rounded-xl overflow-hidden" data-job-card="${idx}">
                            <label class="flex items-start gap-3 p-3 cursor-pointer hover:bg-slate-50 transition">
                                <input type="checkbox" class="mt-1 w-4 h-4 accent-[#7a1355]" id="pmr_check_${idx}"
                                    onchange="togglePrevJobDetail(${idx})">
                                <div class="flex-1 min-w-0">
                                    <p class="font-bold text-sm text-slate-800">${esc(job.maintenance_point)}</p>
                                    <p class="text-xs text-slate-400 mt-0.5">Change Date Plan: ${esc(job.change_date_plan ?? '-')} • Sisa ${job.remaining_day} hari</p>
                                </div>
                            </label>
                            <div id="pmr_detail_${idx}" class="hidden border-t border-slate-100 bg-slate-50 p-4 space-y-3">
                                <div>
                                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Tanggal Aktual Pekerjaan <span class="text-red-500">*</span></label>
                                    <input type="date" id="pmr_date_${idx}" value="${today}" oninput="updatePrevMachineReportCount()"
                                        class="w-full border border-slate-200 rounded-lg px-3 py-2 focus:ring-4 focus:ring-[#f2d4e8] outline-none transition text-sm">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Teknisi <span class="text-red-500">*</span></label>
                                    <select id="pmr_teknisi_${idx}" onchange="updatePrevMachineReportCount()"
                                        class="w-full border border-slate-200 rounded-lg px-3 py-2 focus:ring-4 focus:ring-[#f2d4e8] outline-none transition text-sm bg-white">
                                        <option value="">-- Pilih teknisi --</option>
                                        ${technicianListData.map(t => `<option value="${esc(t.name)}">${esc(t.name)}</option>`).join('')}
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Basis Jadwal Berikutnya <span class="text-red-500">*</span></label>
                                    <div class="space-y-1 bg-white border border-slate-200 rounded-lg p-2.5">
                                        <label class="flex items-start gap-2 text-xs text-slate-600 cursor-pointer">
                                            <input type="radio" name="pmr_basis_group_${idx}" id="pmr_basis_actual_${idx}" value="actual" checked class="mt-0.5 accent-[#7a1355]" onchange="updatePrevMachineReportCount()">
                                            <span><strong>Sesuai Tanggal Pekerjaan</strong> — jadwal berikutnya = tanggal aktual pekerjaan + interval.</span>
                                        </label>
                                        <label class="flex items-start gap-2 text-xs text-slate-600 cursor-pointer">
                                            <input type="radio" name="pmr_basis_group_${idx}" value="schedule" class="mt-0.5 accent-[#7a1355]" onchange="updatePrevMachineReportCount()">
                                            <span><strong>Sesuai Jadwal Awal</strong> — dari Change Date Plan semula + interval.</span>
                                        </label>
                                        <label class="flex items-start gap-2 text-xs text-slate-600 cursor-pointer">
                                            <input type="radio" name="pmr_basis_group_${idx}" value="report" class="mt-0.5 accent-[#7a1355]" onchange="updatePrevMachineReportCount()">
                                            <span><strong>Sesuai Tanggal Pengisian Laporan</strong> — dari hari ini + interval.</span>
                                        </label>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Keterangan / Note <span class="text-red-500">*</span></label>
                                    <textarea id="pmr_note_${idx}" rows="2" placeholder="Tuliskan detail pekerjaan preventive maintenance..." oninput="updatePrevMachineReportCount()"
                                        class="w-full border border-slate-200 rounded-lg px-3 py-2 focus:ring-4 focus:ring-[#f2d4e8] outline-none transition text-sm resize-none"></textarea>
                                </div>
                            </div>
                        </div>
                    `;

                    if (jobs.length === 0) {
                        listEl.innerHTML = '<p class="text-center text-slate-400 text-sm py-6">Tidak ada job yang bisa direport saat ini.</p>';
                    } else {
                        // ── Kelompokkan index job berdasarkan Department → Line → Operation Process ──
                        const groups = {};
                        jobs.forEach((job, idx) => {
                            const dept = job.department || '-';
                            const line = job.line || '-';
                            const op = job.operation_process || '-';
                            (groups[dept] ??= {});
                            (groups[dept][line] ??= {});
                            (groups[dept][line][op] ??= []).push(idx);
                        });

                        let html = '';
                        Object.keys(groups).sort().forEach(dept => {
                            html += `<div class="mb-4">
                                <div class="flex items-center gap-1.5 mb-2">
                                    <i class="fas fa-building text-[#7a1355] text-xs"></i>
                                    <span class="text-xs font-black text-[#7a1355] uppercase tracking-widest">${esc(dept)}</span>
                                </div>`;
                            Object.keys(groups[dept]).sort().forEach(line => {
                                html += `<div class="ml-3 pl-3 border-l-2 border-[#f2d4e8] mb-3">
                                    <div class="flex items-center gap-1.5 mb-2">
                                        <i class="fas fa-industry text-slate-400 text-[11px]"></i>
                                        <span class="text-[11px] font-bold text-slate-500 uppercase tracking-wide">${esc(line)}</span>
                                    </div>`;
                                Object.keys(groups[dept][line]).sort().forEach(op => {
                                    const opIdxs = groups[dept][line][op];
                                    html += `<details class="ml-1 mb-2 group border border-slate-200 rounded-xl overflow-hidden">
                                        <summary class="flex items-center gap-2 cursor-pointer select-none list-none px-3 py-2.5 bg-[#f9eef5] hover:bg-[#f2d4e8] transition">
                                            <i class="fas fa-chevron-right text-[#7a1355] text-[10px] transition-transform group-open:rotate-90"></i>
                                            <span class="flex-1 min-w-0">
                                                <span class="block text-sm font-black text-[#7a1355] leading-tight truncate">${esc(op)}</span>
                                                <span class="block text-xs font-bold text-slate-500 leading-tight truncate"><i class="fas fa-industry mr-1 text-[10px]"></i>${esc(machineName)}</span>
                                            </span>
                                            <span class="flex-shrink-0 bg-[#7a1355] text-white text-[10px] font-black px-2 py-1 rounded-full">${opIdxs.length} job</span>
                                        </summary>
                                        <div class="space-y-2 p-3 bg-white">
                                            ${opIdxs.map(idx => jobCardHtml(jobs[idx], idx)).join('')}
                                        </div>
                                    </details>`;
                                });
                                html += `</div>`;
                            });
                            html += `</div>`;
                        });
                        listEl.innerHTML = html;
                    }

                    // simpan data job (id, dll) untuk dipakai saat submit
                    listEl.dataset.jobs = JSON.stringify(jobs);

                    document.getElementById('prevMachineReportAlert').classList.add('hidden');
                    updatePrevMachineReportCount();
                    showModal('prevMachineReportModal');
                }

                function togglePrevJobDetail(idx) {
                    const checked = document.getElementById(`pmr_check_${idx}`).checked;
                    document.getElementById(`pmr_detail_${idx}`).classList.toggle('hidden', !checked);
                    updatePrevMachineReportCount();
                }

                function updatePrevMachineReportCount() {
                    const listEl = document.getElementById('prevMachineReportJobsList');
                    const jobs = JSON.parse(listEl.dataset.jobs || '[]');
                    let count = 0;
                    let allFilled = true;
                    jobs.forEach((job, idx) => {
                        const cb = document.getElementById(`pmr_check_${idx}`);
                        if (cb && cb.checked) {
                            count++;
                            const date = document.getElementById(`pmr_date_${idx}`)?.value;
                            const teknisi = document.getElementById(`pmr_teknisi_${idx}`)?.value?.trim();
                            const note = document.getElementById(`pmr_note_${idx}`)?.value?.trim();
                            if (!date || !teknisi || !note) allFilled = false;
                        }
                    });
                    document.getElementById('prevMachineReportSelectedCount').textContent = count;

                    // Submit hanya bisa diklik apabila minimal 1 job dicentang DAN
                    // semua kolom pada setiap job yang dicentang sudah terisi lengkap.
                    const btn = document.getElementById('btnSubmitPrevMachineReport');
                    const canSubmit = count > 0 && allFilled;
                    btn.disabled = !canSubmit;
                    btn.classList.toggle('opacity-50', !canSubmit);
                    btn.classList.toggle('cursor-not-allowed', !canSubmit);
                }

                async function submitPrevMachineReport() {
                    const listEl = document.getElementById('prevMachineReportJobsList');
                    const jobs = JSON.parse(listEl.dataset.jobs || '[]');
                    const al = document.getElementById('prevMachineReportAlert');

                    const showErr = (msg) => {
                        al.className = 'rounded-xl p-3 mt-4 text-sm font-medium border bg-red-50 text-red-800 border-red-200';
                        al.textContent = msg;
                        al.classList.remove('hidden');
                    };

                    const fd = new FormData();
                    fd.append('prev_action', 'prev_report_bulk');

                    let selectedCount = 0;
                    for (let idx = 0; idx < jobs.length; idx++) {
                        const cb = document.getElementById(`pmr_check_${idx}`);
                        if (!cb || !cb.checked) continue;

                        const date = document.getElementById(`pmr_date_${idx}`)?.value;
                        const teknisi = document.getElementById(`pmr_teknisi_${idx}`)?.value?.trim();
                        const note = document.getElementById(`pmr_note_${idx}`)?.value?.trim();
                        const basisEl = document.querySelector(`input[name="pmr_basis_group_${idx}"]:checked`);
                        const basis = basisEl ? basisEl.value : 'actual';

                        if (!date || !teknisi || !note) {
                            showErr(`❌ Lengkapi semua field (tanggal, teknisi, note) untuk: ${jobs[idx].maintenance_point}`);
                            return;
                        }

                        fd.append(`items[${selectedCount}][schedule_id]`, jobs[idx].id);
                        fd.append(`items[${selectedCount}][actual_date]`, date);
                        fd.append(`items[${selectedCount}][teknisi]`, teknisi);
                        fd.append(`items[${selectedCount}][note]`, note);
                        fd.append(`items[${selectedCount}][next_basis]`, basis);
                        selectedCount++;
                    }

                    if (selectedCount === 0) {
                        showErr('❌ Pilih minimal 1 pekerjaan untuk direport.');
                        return;
                    }

                    const btn = document.getElementById('btnSubmitPrevMachineReport');
                    if (btn.disabled) return;
                    btn.disabled = true;
                    const originalHtml = btn.innerHTML;
                    btn.innerHTML = 'Menyimpan...';

                    try {
                        const r = await (await fetch('', {
                            method: 'POST',
                            body: fd
                        })).json();
                        if (r.status === 'success') {
                            al.className = 'rounded-xl p-3 mt-4 text-sm font-medium border bg-green-50 text-green-800 border-green-200';
                            al.textContent = '✅ ' + r.message;
                            al.classList.remove('hidden');
                            setTimeout(() => {
                                hideModal('prevMachineReportModal');
                                location.reload();
                            }, 1500);
                        } else {
                            showErr('❌ ' + (r.message || 'Gagal'));
                            btn.disabled = false;
                            btn.innerHTML = originalHtml;
                        }
                    } catch (err) {
                        showErr('❌ Gagal: ' + err.message);
                        btn.disabled = false;
                        btn.innerHTML = originalHtml;
                    }
                }

                // Import Excel preventive
                let selectedPrevImportFile = null;

                function showPrevImportModal() {
                    // Akses elemen saat fungsi dipanggil (bukan saat parse), DOM sudah ready
                    selectedPrevImportFile = null;
                    const fileInput = document.getElementById('prevExcelFileInput');
                    const selectedFileName = document.getElementById('prevSelectedFileName');
                    const fileNameLabel = document.getElementById('prevFileNameLabel');
                    const importAlert = document.getElementById('prevImportAlert');
                    const btn = document.getElementById('btnStartPrevImport');
                    if (fileInput) fileInput.value = '';
                    if (selectedFileName) selectedFileName.classList.add('hidden');
                    if (fileNameLabel) fileNameLabel.textContent = '';
                    if (importAlert) importAlert.classList.add('hidden');
                    if (btn) {
                        btn.disabled = true;
                        btn.classList.add('opacity-50', 'cursor-not-allowed');
                        btn.classList.remove('hover:bg-[#5f0f40]');
                    }
                    showModal('prevImportModal');
                }

                function setPrevImportFile(file) {
                    const ext = file.name.split('.').pop().toLowerCase();
                    if (!['xlsx', 'xls'].includes(ext)) {
                        showPrevImportAlert('error', '❌ Format file harus .xlsx atau .xls');
                        return;
                    }
                    if (file.size > 10 * 1024 * 1024) {
                        showPrevImportAlert('error', '❌ Ukuran file maksimal 10MB');
                        return;
                    }
                    selectedPrevImportFile = file;
                    document.getElementById('prevFileNameLabel').textContent = `📄 ${file.name}`;
                    document.getElementById('prevSelectedFileName').classList.remove('hidden');
                    document.getElementById('prevImportAlert').classList.add('hidden');
                    const btn = document.getElementById('btnStartPrevImport');
                    btn.disabled = false;
                    btn.classList.remove('opacity-50', 'cursor-not-allowed');
                    btn.classList.add('hover:bg-[#5f0f40]');
                }

                function handlePrevFileSelect(e) {
                    if (e.target.files[0]) setPrevImportFile(e.target.files[0]);
                }

                function handlePrevDrop(e) {
                    e.preventDefault();
                    document.getElementById('prevDropZone').classList.remove('dragover');
                    if (e.dataTransfer.files[0]) setPrevImportFile(e.dataTransfer.files[0]);
                }
                async function startPrevImport() {
                    if (!selectedPrevImportFile) return;
                    document.getElementById('prevImportOverlay').classList.add('active');
                    const fd = new FormData();
                    fd.append('excel_file', selectedPrevImportFile);
                    fd.append('target_table', 'preventive');
                    try {
                        const res = await fetch('import_excel_preventive.php', {
                            method: 'POST',
                            body: fd
                        });
                        const result = await res.json();
                        document.getElementById('prevImportOverlay').classList.remove('active');
                        if (result.status === 'success') {
                            showPrevImportAlert('success', `✅ ${result.message}`);
                            setTimeout(() => location.reload(), 2000);
                            alert(result.message); // Menampilkan "X data berhasil diimport"
                        } else {
                            showPrevImportAlert('error', `❌ ${result.message}`);
                            if (result.errors?.length) console.warn('Import errors:', result.errors);
                            alert("Gagal: " + result.message + "\nDetail: " + (result.errors ? result.errors.join(", ") : ""));
                        }
                    } catch (err) {
                        document.getElementById('prevImportOverlay').classList.remove('active');
                        showPrevImportAlert('error', `❌ Gagal menghubungi server: ${err.message}`);
                    }
                }

                function showPrevImportAlert(type, msg) {
                    const el = document.getElementById('prevImportAlert');
                    el.className = 'rounded-2xl p-4 mb-6 text-sm font-medium border';
                    el.classList.add(type === 'success' ? 'bg-green-50' : 'bg-red-50',
                        type === 'success' ? 'text-green-800' : 'text-red-800',
                        type === 'success' ? 'border-green-200' : 'border-red-200');
                    el.textContent = msg;
                    el.classList.remove('hidden');
                }
            </script>

            <!-- =========================================================
         MODAL — PREVENTIVE ADD
    ========================================================== -->
            <div id="prevAddModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm items-center justify-center z-50 p-4" style="display:none;">
                <div class="bg-white w-full max-w-4xl rounded-3xl shadow-2xl overflow-hidden">
                    <div class="px-8 py-6 flex justify-between items-center" style="background:linear-gradient(135deg, #7a1355, #8b1a6b);">
                        <h3 class="text-xl font-bold text-white"><i class="fas fa-shield-halved mr-2"></i>Add Preventive Maintenance Schedule</h3>
                        <button onclick="hideModal('prevAddModal')" class="text-white/60 hover:text-white transition"><i class="fas fa-times text-xl"></i></button>
                    </div>
                    <form id="prevAddForm" class="p-8 max-h-[85vh] overflow-y-auto">
                        <input type="hidden" name="prev_action" value="prev_add">
                        <input type="hidden" name="dept_name" id="prev_add_dept_name_val">
                        <input type="hidden" name="line_name" id="prev_add_line_name_val">
                        <?php echo renderFormFields('prev_add', $plants); ?>
                        <div class="flex justify-end gap-3 mt-2">
                            <button type="button" onclick="hideModal('prevAddModal')" class="px-8 py-3 font-bold text-slate-500 hover:bg-slate-100 rounded-xl transition">Cancel</button>
                            <button type="submit" class="text-white px-10 py-3 rounded-xl font-black shadow-lg transition-all" style="background:#7a1355;">Save</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- =========================================================
         MODAL — PREVENTIVE EDIT
    ========================================================== -->
            <div id="prevEditModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm items-center justify-center z-50 p-4" style="display:none;">
                <div class="bg-white w-full max-w-4xl rounded-3xl shadow-2xl overflow-hidden">
                    <div class="px-8 py-6 flex justify-between items-center" style="background:linear-gradient(135deg, #7a1355, #8b1a6b);">
                        <h3 class="text-xl font-bold text-white"><i class="fas fa-edit mr-2"></i>Edit Jadwal Preventive</h3>
                        <button onclick="hideModal('prevEditModal')" class="text-white/60 hover:text-white transition"><i class="fas fa-times text-xl"></i></button>
                    </div>
                    <form id="prevEditForm" class="p-8 max-h-[85vh] overflow-y-auto">
                        <input type="hidden" name="prev_action" value="prev_edit">
                        <input type="hidden" name="prev_edit_id" id="prev_edit_id">
                        <input type="hidden" name="department" id="prev_edit_dept_id_val">
                        <input type="hidden" name="line" id="prev_edit_line_id_val">
                        <input type="hidden" name="dept_name" id="prev_edit_dept_name_val">
                        <input type="hidden" name="line_name" id="prev_edit_line_name_val">
                        <?php echo renderFormFields('prev_edit', $plants); ?>
                        <div class="mb-6 border-t pt-6">
                            <div class="bg-[#f9eef5] border border-[#e8c5da] rounded-xl px-4 py-3 text-sm text-[#8b1a6b] font-medium">
                                <i class="fas fa-info-circle mr-2"></i>
                                <strong>Maintenance Status</strong> dikelola otomatis oleh sistem:
                                <span class="block mt-1 text-xs text-[#8b1a6b]">• Jauh dari reminder → <strong>DONE</strong> &nbsp;|&nbsp; Masuk window reminder → <strong>SOON</strong> &nbsp;|&nbsp; Setelah submit report → <strong>DONE</strong></span>
                            </div>
                        </div>
                        <div class="flex justify-end gap-3">
                            <button type="button" onclick="hideModal('prevEditModal')" class="px-8 py-3 font-bold text-slate-500 hover:bg-slate-100 rounded-xl transition">Batal</button>
                            <button type="submit" class="bg-[#8b1a6b] hover:bg-[#7a1355] text-white px-10 py-3 rounded-xl font-black shadow-lg transition-all">Update Data</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- =========================================================
         MODAL — PREVENTIVE STATUS DETAIL
    ========================================================== -->
            <div id="prevStatusModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 items-center justify-center p-6" style="display:none;">
                <div class="bg-white w-full max-w-2xl rounded-3xl shadow-2xl overflow-hidden">
                    <div id="prevStatusModalHeader" class="px-7 py-5 flex justify-between items-center">
                        <div>
                            <p class="text-white/60 text-[10px] font-black uppercase tracking-widest mb-0.5">Filter Status</p>
                            <h3 class="text-base font-black text-white" id="prevStatusModalTitle">—</h3>
                        </div>
                        <button onclick="hideModal('prevStatusModal')" class="text-white/60 hover:text-white w-8 h-8 flex items-center justify-center rounded-full hover:bg-white/10 transition"><i class="fas fa-times"></i></button>
                    </div>
                    <!-- Filter pilih hari (kategori Alert: H-1 s.d H-7, atau Overdue: H0 s.d H+7 dan >H+7) -->
                    <div id="prevStatusModalDayFilter" class="px-7 py-3 bg-amber-50 border-b border-amber-100 hidden">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-[10px] font-black uppercase tracking-widest text-amber-700 mr-1"><i class="fas fa-calendar-day mr-1"></i>Pilih Hari:</span>
                            <div id="prevStatusModalDayChips" class="flex items-center gap-1.5 flex-wrap"></div>
                        </div>
                    </div>
                    <div style="max-height:360px;overflow-y:auto;">
                        <table class="w-full text-left border-collapse">
                            <thead style="position:sticky;top:0;background:#f8fafc;z-index:5;">
                                <tr class="border-b border-slate-100">
                                    <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Machine</th>
                                    <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Maintenance Point</th>
                                    <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Remaining</th>
                                    <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Plan Date</th>
                                </tr>
                            </thead>
                            <tbody id="prevStatusModalBody" class="divide-y divide-slate-50 text-sm"></tbody>
                        </table>
                    </div>
                    <div class="px-7 py-4 bg-slate-50 border-t border-slate-100 flex justify-between items-center gap-3">
                        <span id="prevStatusModalCount" class="text-xs text-slate-400 font-medium"></span>
                        <div class="flex items-center gap-2">
                            <button id="prevStatusModalExportBtn" onclick="exportStatusModalExcel('preventive')" class="hidden px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl font-bold text-sm transition items-center gap-2">
                                <i class="fas fa-file-excel"></i> Export Excel
                            </button>
                            <button onclick="hideModal('prevStatusModal')" class="px-5 py-2 bg-[#7a1355] text-white rounded-xl font-bold text-sm hover:bg-[#5f0f40] transition">Tutup</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- =========================================================
         MODAL — PREVENTIVE REPORT (PER MESIN, CHECKLIST MULTI-SELECT)
    ========================================================== -->
            <div id="prevMachineReportModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 items-center justify-center p-4" style="display:none;">
                <div class="bg-white w-full max-w-2xl rounded-2xl shadow-2xl overflow-hidden flex flex-col" style="max-height:90vh;">
                    <div class="px-5 py-4 flex justify-between items-center flex-shrink-0" style="background:linear-gradient(135deg,#7a1355,#8b1a6b);">
                        <div>
                            <h3 class="text-base font-bold text-white"><i class="fas fa-clipboard-check mr-2"></i>Report Preventive — <span id="prevMachineReportModalMachine">—</span></h3>
                            <p class="text-[#f2d4e8] text-xs mt-0.5">Centang pekerjaan yang ingin direport, lalu isi detailnya masing-masing.</p>
                        </div>
                        <button onclick="hideModal('prevMachineReportModal')" class="text-[#f2d4e8] hover:text-white w-8 h-8 flex items-center justify-center rounded-full hover:bg-white/10 transition flex-shrink-0"><i class="fas fa-times"></i></button>
                    </div>
                    <form id="prevMachineReportForm" class="p-5 overflow-y-auto" style="flex:1;">
                        <div id="prevMachineReportJobsList" class="space-y-3"></div>
                        <div id="prevMachineReportAlert" class="hidden rounded-xl p-3 mt-4 text-sm font-medium border"></div>
                    </form>
                    <div class="px-5 py-4 border-t border-slate-100 flex justify-end gap-3 flex-shrink-0 bg-white">
                        <button type="button" onclick="hideModal('prevMachineReportModal')" class="px-6 py-3 font-bold text-slate-400 hover:bg-slate-100 rounded-xl transition text-sm">Batal</button>
                        <button type="button" id="btnSubmitPrevMachineReport" onclick="submitPrevMachineReport()" disabled
                            class="btn-submit-prev text-white px-8 py-3 rounded-xl font-black shadow-lg transition text-sm opacity-50 cursor-not-allowed" style="background:#7a1355;">
                            <i class="fas fa-paper-plane mr-1"></i> Submit Report (<span id="prevMachineReportSelectedCount">0</span>)
                        </button>
                    </div>
                </div>
            </div>

            <!-- =========================================================
         MODAL — PREVENTIVE IMPORT EXCEL
    ========================================================== -->
            <div id="prevImportModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm items-center justify-center z-50 p-4" style="display:none;">
                <div class="bg-white w-full max-w-lg rounded-2xl shadow-2xl overflow-hidden">
                    <div class="px-5 py-4 flex justify-between items-center" style="background:linear-gradient(135deg, #3730a3, #4f46e5);">
                        <h3 class="text-base font-bold text-white"><i class="fas fa-file-excel mr-2"></i>Import Data Preventive dari Excel</h3>
                        <button onclick="hideModal('prevImportModal')" class="text-[#f2d4e8] hover:text-white transition"><i class="fas fa-times text-lg"></i></button>
                    </div>
                    <div class="p-5">
                        <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 mb-4 text-sm">
                            <p class="font-black text-slate-600 mb-2 text-xs uppercase tracking-widest">📋 Mapping Kolom Excel → Preventive</p>
                            <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-slate-600 font-medium text-xs">
                                <div class="flex items-center gap-1.5"><span class="bg-[#f9eef5] text-[#8b1a6b] px-1.5 py-0.5 rounded font-mono text-[10px]">DEPARTEMENT</span><span class="text-slate-400">→</span><span>Department</span></div>
                                <div class="flex items-center gap-1.5"><span class="bg-[#f9eef5] text-[#8b1a6b] px-1.5 py-0.5 rounded font-mono text-[10px]">LINE</span><span class="text-slate-400">→</span><span>Line</span></div>
                                <div class="flex items-center gap-1.5"><span class="bg-[#f9eef5] text-[#8b1a6b] px-1.5 py-0.5 rounded font-mono text-[10px]">OPERATION PROCESS</span><span class="text-slate-400">→</span><span>Op. Process</span></div>
                                <div class="flex items-center gap-1.5"><span class="bg-[#f9eef5] text-[#8b1a6b] px-1.5 py-0.5 rounded font-mono text-[10px]">MACHINE NAME</span><span class="text-slate-400">→</span><span>Machine Name</span></div>
                                <div class="flex items-center gap-1.5"><span class="bg-[#f9eef5] text-[#8b1a6b] px-1.5 py-0.5 rounded font-mono text-[10px]">PROCESS MACHINE</span><span class="text-slate-400">→</span><span>Process Machine</span></div>
                                <div class="flex items-center gap-1.5"><span class="bg-[#f9eef5] text-[#8b1a6b] px-1.5 py-0.5 rounded font-mono text-[10px]">NAME UNIT</span><span class="text-slate-400">→</span><span>Unit Name</span></div>
                                <div class="flex items-center gap-1.5"><span class="bg-[#f9eef5] text-[#8b1a6b] px-1.5 py-0.5 rounded font-mono text-[10px]">MAINTENANCE POINT</span><span class="text-slate-400">→</span><span>Maint. Point</span></div>
                                <div class="flex items-center gap-1.5"><span class="bg-[#f9eef5] text-[#8b1a6b] px-1.5 py-0.5 rounded font-mono text-[10px]">INTERVAL (MONTH)</span><span class="text-slate-400">→</span><span>Interval</span></div>
                                <div class="flex items-center gap-1.5"><span class="bg-[#f9eef5] text-[#8b1a6b] px-1.5 py-0.5 rounded font-mono text-[10px]">USE DATE</span><span class="text-slate-400">→</span><span>Last Change</span></div>
                                <div class="flex items-center gap-1.5"><span class="bg-[#f9eef5] text-[#8b1a6b] px-1.5 py-0.5 rounded font-mono text-[10px]">CHANGE DATE PLAN</span><span class="text-slate-400">→</span><span>Change Plan</span></div>
                                <div class="flex items-center gap-1.5"><span class="bg-[#f9eef5] text-[#8b1a6b] px-1.5 py-0.5 rounded font-mono text-[10px]">REMINDER ACTIVITY</span><span class="text-slate-400">→</span><span>Reminder</span></div>
                            </div>
                            <p class="text-[10px] text-slate-400 mt-2 italic">⚠️ Kolom Part Order &amp; Part Availability tidak ada di preventive.</p>
                        </div>
                        <div id="prevDropZone"
                            class="border-2 border-dashed border-slate-300 rounded-xl p-6 text-center cursor-pointer transition-all hover:border-[#f9eef5]0 hover:bg-[#f9eef5] mb-4"
                            onclick="document.getElementById('prevExcelFileInput').click()"
                            ondragover="event.preventDefault();this.classList.add('dragover')"
                            ondragleave="this.classList.remove('dragover')"
                            ondrop="handlePrevDrop(event)">
                            <i class="fas fa-cloud-upload-alt text-3xl text-slate-300 mb-2 block"></i>
                            <p class="font-bold text-slate-600 text-sm">Klik atau drag &amp; drop file Excel di sini</p>
                            <p class="text-slate-400 text-xs mt-1">Format: .xlsx atau .xls &nbsp;|&nbsp; Maks: 10 MB</p>
                            <div id="prevSelectedFileName" class="mt-3 hidden">
                                <span class="bg-[#f9eef5] text-[#8b1a6b] font-bold px-3 py-1 rounded-full text-xs" id="prevFileNameLabel"></span>
                            </div>
                        </div>
                        <input type="file" id="prevExcelFileInput" accept=".xlsx,.xls" class="hidden" onchange="handlePrevFileSelect(event)">
                        <div id="prevImportAlert" class="hidden rounded-xl p-3 mb-4 text-sm font-medium border"></div>
                        <div class="flex justify-end gap-3">
                            <button type="button" onclick="hideModal('prevImportModal')"
                                class="px-6 py-2.5 font-bold text-slate-500 hover:bg-slate-100 rounded-xl transition text-sm">Batal</button>
                            <button id="btnStartPrevImport" onclick="startPrevImport()" disabled
                                class="text-white px-8 py-2.5 rounded-xl font-black shadow-lg transition-all opacity-50 cursor-not-allowed flex items-center gap-2 text-sm"
                                style="background:#7a1355;">
                                <i class="fas fa-upload"></i> Import Sekarang
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Loading overlay preventive -->
            <div id="prevImportOverlay" style="position:fixed;inset:0;background:rgba(15,118,110,.55);backdrop-filter:blur(4px);z-index:9999;align-items:center;justify-content:center;">
                <div class="bg-white rounded-3xl shadow-2xl p-10 text-center max-w-sm w-full mx-4">
                    <div class="w-16 h-16 bg-[#f9eef5] rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-spinner fa-spin text-[#8b1a6b] text-2xl"></i>
                    </div>
                    <p class="font-bold text-slate-700 text-lg mb-2">Sedang mengimport data...</p>
                    <p class="text-slate-400 text-sm mb-4">Mohon tunggu, jangan tutup halaman ini.</p>
                    <div class="w-full bg-slate-100 rounded-full h-2 overflow-hidden">
                        <div class="anim-bar bg-[#8b1a6b] h-2 w-1/3 rounded-full"></div>
                    </div>
                </div>
            </div>
            <script>
                // prevAddForm & prevEditForm listeners — dipasang di sini agar DOM modal sudah ada
                document.getElementById('prevAddForm').addEventListener('submit', async function(e) {
                    e.preventDefault();
                    const r = await (await fetch('', {
                        method: 'POST',
                        body: new FormData(this)
                    })).json();
                    if (r.status === 'success') location.reload();
                    else alert(r.message);
                });
                document.getElementById('prevEditForm').addEventListener('submit', async function(e) {
                    e.preventDefault();
                    const r = await (await fetch('', {
                        method: 'POST',
                        body: new FormData(this)
                    })).json();
                    if (r.status === 'success') location.reload();
                    else alert(r.message);
                });
                // prevImportOverlay: handled via CSS .active class (same as importOverlay)
            </script>

            <!-- =========================================================
         MODAL — TODAY'S FULL SCHEDULE LIST
    ========================================================== -->
            <div id="todayModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 items-center justify-center p-4" style="display:none;">
                <div class="bg-white w-full max-w-xl rounded-3xl shadow-2xl overflow-hidden">
                    <div id="todayModalHeader" class="px-6 py-4 flex justify-between items-center">
                        <div>
                            <p class="text-white/60 text-[10px] font-black uppercase tracking-widest mb-0.5">Jadwal Hari Ini</p>
                            <h3 class="text-base font-black text-white" id="todayModalTitle">—</h3>
                        </div>
                        <button onclick="hideModal('todayModal')" class="text-white/60 hover:text-white w-8 h-8 flex items-center justify-center rounded-full hover:bg-white/10 transition">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div style="max-height:420px;overflow-y:auto;" id="todayModalBody"></div>
                    <div class="px-6 py-3 bg-slate-50 border-t border-slate-100 flex justify-between items-center">
                        <span id="todayModalCount" class="text-xs text-slate-400 font-medium"></span>
                        <button onclick="hideModal('todayModal')" class="px-5 py-2 bg-slate-800 text-white rounded-xl font-bold text-sm hover:bg-slate-700 transition">Tutup</button>
                    </div>
                </div>
            </div>

            <script>
                // ═══════════════════════════════════════════════════════════
                //  TODAY TICKER — Predictive & Preventive
                // ═══════════════════════════════════════════════════════════
                const TODAY_DATA = {
                    pred: <?= isset($todaySchedJson) ? $todaySchedJson : '[]' ?>,
                    prev: <?= isset($prevTodayJson)  ? $prevTodayJson  : '[]' ?>,
                };

                const tickerState = {
                    pred: 0,
                    prev: 0
                };
                const tickerTimers = {
                    pred: null,
                    prev: null
                };

                function updateTicker(type) {
                    const items = document.querySelectorAll(`.${type}-ticker-item`);
                    const dots = document.querySelectorAll(`.${type}-dot`);
                    const idxEl = document.getElementById(`${type}TickerIdx`);
                    const ivEl = document.getElementById(`${type}TickerInterval`);
                    const data = TODAY_DATA[type];
                    const i = tickerState[type];

                    items.forEach((el, n) => {
                        el.style.opacity = n === i ? '1' : '0';
                        el.style.transform = n === i ? 'translateY(0)' : 'translateY(8px)';
                    });
                    dots.forEach((el, n) => {
                        el.style.width = n === i ? '14px' : '6px';
                        el.style.background = type === 'pred' ?
                            (n === i ? '#5f0f40' : '#f2d4e8') :
                            (n === i ? '#8b1a6b' : '#99f6e4');
                    });
                    if (idxEl) idxEl.textContent = i + 1;
                    if (ivEl && data[i]) ivEl.textContent = data[i].interval || '-';
                }

                function startTicker(type) {
                    const data = TODAY_DATA[type];
                    if (!data || data.length <= 1) return;
                    if (tickerTimers[type]) clearInterval(tickerTimers[type]);
                    tickerTimers[type] = setInterval(() => {
                        tickerState[type] = (tickerState[type] + 1) % data.length;
                        updateTicker(type);
                    }, 2000);
                }

                function openTodayModal(type) {
                    const data = TODAY_DATA[type];
                    const isPred = type === 'pred';
                    const color = isPred ? 'linear-gradient(135deg,#5f0f40,#5f0f40)' : 'linear-gradient(135deg,#7a1355,#8b1a6b)';
                    const label = isPred ? 'Predictive' : 'Preventive';

                    document.getElementById('todayModalHeader').style.background = color;
                    document.getElementById('todayModalTitle').textContent = `${label} — ${data.length} Jadwal Hari Ini`;
                    document.getElementById('todayModalCount').textContent = `${data.length} jadwal ditemukan`;

                    const itemColor = isPred ? '#5f0f40' : '#8b1a6b';
                    const bgPill = isPred ? '#f9eef5' : '#f0fdfa';
                    const bdPill = isPred ? '#f2d4e8' : '#99f6e4';

                    if (!data.length) {
                        document.getElementById('todayModalBody').innerHTML =
                            `<div class="px-6 py-12 text-center text-slate-400 text-sm italic">
                    <i class="fas fa-calendar-xmark text-3xl block mb-3 text-slate-200"></i>
                    Tidak ada jadwal untuk hari ini.
                </div>`;
                    } else {
                        document.getElementById('todayModalBody').innerHTML = data.map((r, i) => `
                <div class="flex items-start gap-4 px-6 py-4 border-b border-slate-50 hover:bg-slate-50 transition-colors">
                    <div class="flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center text-xs font-black text-white mt-0.5"
                        style="background:${itemColor};">${i + 1}</div>
                    <div class="flex-1 min-w-0">
                        <p class="font-black text-slate-800 text-sm">${esc(r.machine)}</p>
                        <p class="font-semibold text-xs mt-0.5" style="color:${itemColor};">${esc(r.point)}</p>
                        <p class="text-slate-400 text-[10px] mt-1">${[r.dept,r.line,r.op].filter(Boolean).map(esc).join(' · ')}</p>
                    </div>
                    <span class="flex-shrink-0 text-[10px] font-bold px-2 py-0.5 rounded-lg border"
                        style="background:${bgPill};color:${itemColor};border-color:${bdPill};">${esc(r.interval)}</span>
                </div>`).join('');
                    }
                    showModal('todayModal');
                }

                // Boot tickers
                document.addEventListener('DOMContentLoaded', function() {
                    startTicker('pred');
                    startTicker('prev');
                });
            </script>

            <!-- Modal: Add Machine -->
            <div id="addMachineModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm items-center justify-center z-50 p-4" style="display:none;">
                <div class="bg-white w-full max-w-md rounded-2xl shadow-2xl overflow-hidden">
                    <div class="bg-slate-700 px-5 py-4 flex justify-between items-center">
                        <h3 class="text-xl font-bold text-white"><i class="fas fa-cog mr-2"></i>Add Machine</h3>
                        <button onclick="hideModal('addMachineModal')" class="text-slate-300 hover:text-white transition"><i class="fas fa-times text-xl"></i></button>
                    </div>
                    <form id="addMachineForm" class="p-5 space-y-3 max-h-[75vh] overflow-y-auto">
                        <input type="hidden" name="action" value="add_machine">

                        <div>
                            <label class="block text-xs font-black text-slate-500 uppercase mb-1">Plant <span class="text-red-500">*</span></label>
                            <select id="am_plant" name="plant_id"
                                class="w-full border border-slate-200 rounded-xl px-3 py-2 focus:ring-4 focus:ring-slate-100 outline-none transition text-sm"
                                onchange="amLoadLines()">
                                <option value="">-- Pilih Plant --</option>
                                <?php
                                $amPlants = $pdo->query("SELECT id, plant_name FROM plants ORDER BY plant_name ASC")->fetchAll(PDO::FETCH_ASSOC);
                                foreach ($amPlants as $pl): ?>
                                    <option value="<?= $pl['id'] ?>" data-name="<?= htmlspecialchars($pl['plant_name']) ?>">
                                        <?= htmlspecialchars($pl['plant_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-black text-slate-500 uppercase mb-1">Line <span class="text-red-500">*</span></label>
                            <select id="am_line" name="line_id"
                                class="w-full border border-slate-200 rounded-xl px-3 py-2 focus:ring-4 focus:ring-slate-100 outline-none transition text-sm"
                                onchange="amCheckConnectingRod()"
                                disabled>
                                <option value="">-- Pilih Line --</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-black text-slate-500 uppercase mb-1">Operation Process <span class="text-red-500">*</span></label>
                            <input type="text" name="operation_process" id="am_op_process"
                                placeholder="Ketik operation process..."
                                class="w-full border border-slate-200 rounded-xl px-3 py-2 focus:ring-4 focus:ring-slate-100 outline-none transition text-sm">
                        </div>

                        <div>
                            <label class="block text-xs font-black text-slate-500 uppercase mb-1">Machine Name <span class="text-red-500">*</span></label>
                            <input type="text" name="machine_name" id="am_machine_name"
                                placeholder="Ketik nama mesin..."
                                class="w-full border border-slate-200 rounded-xl px-3 py-2 focus:ring-4 focus:ring-slate-100 outline-none transition text-sm">
                        </div>

                        <div>
                            <label class="block text-xs font-black text-slate-500 uppercase mb-1">Process Machine</label>
                            <input type="text" name="process_machine" id="am_process_machine"
                                placeholder="Otomatis terisi jika plant Connecting Rod"
                                class="w-full border border-slate-200 rounded-xl px-3 py-2 focus:ring-4 focus:ring-slate-100 outline-none transition text-sm bg-slate-50">
                            <p id="am_proc_mach_note" class="text-xs text-[#8b1a6b] mt-1" style="display:none;">
                                <i class="fas fa-info-circle"></i> Otomatis diisi "machining" untuk plant Connecting Rod.
                            </p>
                        </div>

                        <div class="flex justify-end gap-2 pt-2 border-t border-slate-100">
                            <button type="button" onclick="hideModal('addMachineModal')"
                                class="px-6 py-2 font-bold text-slate-500 hover:bg-slate-100 rounded-xl transition text-sm">Batal</button>
                            <button type="submit" id="am_submit_btn"
                                class="bg-slate-700 hover:bg-slate-800 text-white px-8 py-2 rounded-xl font-black shadow-lg transition-all text-sm">Simpan</button>
                        </div>
                    </form>
                </div>
            </div>

            <script>
                // ── Add Machine Modal JS ──
                function showAddMachineModal() {
                    document.getElementById('addMachineForm').reset();
                    document.getElementById('am_line').innerHTML = '<option value="">-- Pilih Line --</option>';
                    document.getElementById('am_line').disabled = true;
                    document.getElementById('am_proc_mach_note').style.display = 'none';
                    showModal('addMachineModal');
                }

                async function amLoadLines() {
                    const plantSel = document.getElementById('am_plant');
                    const lineSel = document.getElementById('am_line');
                    const plantId = plantSel.value;

                    lineSel.innerHTML = '<option value="">-- Pilih Line --</option>';
                    lineSel.disabled = true;
                    document.getElementById('am_process_machine').value = '';
                    document.getElementById('am_proc_mach_note').style.display = 'none';

                    if (!plantId) return;

                    const data = await (await fetch(`?get_lines_plant=${plantId}`)).json();
                    data.forEach(l => {
                        lineSel.innerHTML += `<option value="${l.id}">${l.line_name}</option>`;
                    });
                    lineSel.disabled = false;
                    amCheckConnectingRod();
                }

                function amCheckConnectingRod() {
                    const plantSel = document.getElementById('am_plant');
                    const plantName = plantSel.options[plantSel.selectedIndex]?.getAttribute('data-name') || '';
                    const procMachEl = document.getElementById('am_process_machine');
                    const noteEl = document.getElementById('am_proc_mach_note');

                    if (plantName.toLowerCase().includes('connecting rod')) {
                        procMachEl.value = 'machining';
                        procMachEl.readOnly = true;
                        noteEl.style.display = 'block';
                    } else {
                        if (procMachEl.readOnly) procMachEl.value = '';
                        procMachEl.readOnly = false;
                        noteEl.style.display = 'none';
                    }
                }

                document.getElementById('addMachineForm').addEventListener('submit', async function(e) {
                    e.preventDefault();
                    const btn = document.getElementById('am_submit_btn');
                    btn.disabled = true;
                    btn.textContent = 'Menyimpan...';

                    const formData = new FormData(this);
                    const res = await fetch('', {
                        method: 'POST',
                        body: new URLSearchParams(formData)
                    });
                    const data = await res.json();

                    btn.disabled = false;
                    btn.textContent = 'Simpan';

                    if (data.status === 'success') {
                        hideModal('addMachineModal');
                        alert('Machine berhasil ditambahkan!');
                    } else {
                        alert('Error: ' + (data.message || 'Gagal menyimpan'));
                    }
                });
            </script>

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

                    // Init: baca sessionStorage, default = collapsed
                    const saved = sessionStorage.getItem('schedule_sidebar');
                    applyState(saved !== 'expanded');

                    document.getElementById('sidebarToggle').addEventListener('click', () => {
                        const isCollapsed = !sidebar.classList.contains('collapsed');
                        applyState(isCollapsed);
                        sessionStorage.setItem('schedule_sidebar', isCollapsed ? 'collapsed' : 'expanded');
                    });
                })();
            </script>

        </div><!-- /main-content -->
    </div><!-- /app-layout -->

</body>

</html>