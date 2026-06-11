<?php
$_env = parse_ini_file(__DIR__ . '/.env') ?: [];
define('BREVO_API_KEY',   $_env['BREVO_API_KEY']   ?? '');
define('MAIL_FROM_NAME',  'Maintenance System');
define('MAIL_FROM_EMAIL', 'noreply@yanmar.co.id');

function sendMail(string $to, string $subject, string $body): bool
{
  if (empty(BREVO_API_KEY)) {
    return false;
  }
  $payload = json_encode([
    'sender'      => ['name' => MAIL_FROM_NAME, 'email' => MAIL_FROM_EMAIL],
    'to'          => [['email' => $to]],
    'subject'     => $subject,
    'htmlContent' => $body,
  ]);
  $ch = curl_init('https://api.brevo.com/v3/smtp/email');
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
      'accept: application/json',
      'api-key: ' . BREVO_API_KEY,
      'content-type: application/json',
    ],
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
  ]);
  $response = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $curlErr  = curl_error($ch);
  curl_close($ch);
  if ($curlErr) {
    error_log('[Reminder] cURL error ke ' . $to . ': ' . $curlErr);
    return false;
  }
  $ok = $httpCode === 201;
  if ($ok) {
    error_log('[Reminder] Email berhasil dikirim ke: ' . $to);
  } else {
    error_log('[Reminder] GAGAL kirim ke ' . $to . ' — HTTP ' . $httpCode . ': ' . $response);
  }
  return $ok;
}

// Kirim ke ADMIN saja (bukan semua user) — sesuai permintaan
function getActiveAdmins(PDO $pdo): array
{
  $stmt = $pdo->prepare("
        SELECT username, email_user FROM users
        WHERE role = 'admin' AND is_active = 1
          AND email_user != '' AND email_user IS NOT NULL
    ");
  $stmt->execute();
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Ambil semua penerima dari tabel notification_recipients
function getNotificationRecipients(PDO $pdo): array
{
  try {
    $stmt = $pdo->prepare("
          SELECT name, email FROM notification_recipients
          WHERE email != '' AND email IS NOT NULL
      ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  } catch (\Exception $e) {
    error_log('[Reminder] Gagal ambil notification_recipients: ' . $e->getMessage());
    return [];
  }
}

// Gabungkan admin + notification_recipients (tanpa duplikat email)
function getAllRecipients(PDO $pdo): array
{
  $admins     = getActiveAdmins($pdo);
  $recipients = getNotificationRecipients($pdo);

  $all  = [];
  $seen = [];

  foreach ($admins as $a) {
    $email = strtolower(trim($a['email_user']));
    if ($email && !isset($seen[$email])) {
      $all[]        = ['name' => $a['username'], 'email' => $a['email_user']];
      $seen[$email] = true;
    }
  }
  foreach ($recipients as $r) {
    $email = strtolower(trim($r['email']));
    if ($email && !isset($seen[$email])) {
      $all[]        = ['name' => $r['name'] ?: 'Recipient', 'email' => $r['email']];
      $seen[$email] = true;
    }
  }

  return $all;
}

// Catat ke notification_log dengan message unik per kategori per hari
function alreadySentToday(PDO $pdo, string $messageKey): bool
{
  $count = $pdo->prepare("
        SELECT COUNT(*) FROM notification_log
        WHERE DATE(sent_at) = CURDATE() AND message = ?
    ");
  $count->execute([$messageKey]);
  return (int)$count->fetchColumn() > 0;
}

function logSent(PDO $pdo, string $messageKey, ?int $scheduleId = null, string $sentTo = 'all-admins'): void
{
  try {
    $pdo->prepare("INSERT INTO notification_log (schedule_id, sent_to, sent_at, message) VALUES (?,?,NOW(),?)")
      ->execute([$scheduleId, $sentTo, $messageKey]);
  } catch (\Exception $e) { /* ignore */
  }
}

/**
 * Distributed lock via MySQL GET_LOCK() untuk mencegah race condition
 * ketika 2+ user login hampir bersamaan di hari yang sama.
 *
 * Alur:
 *  1. Cek alreadySentToday() — jika sudah terkirim, langsung return false.
 *  2. Coba GET_LOCK(key, 0) — jika gagal (proses lain sedang pegang lock), return false.
 *  3. Cek alreadySentToday() LAGI di dalam lock (double-check) — jika proses lain
 *     sudah logSent() saat kita menunggu, lepas lock dan return false.
 *  4. Jika semua lolos → return true; pemanggil wajib panggil releaseLock() setelah selesai.
 *
 * @param PDO    $pdo
 * @param string $messageKey  Key unik kategori (mis. 'batch-reminder')
 * @return bool  true jika lock berhasil dipegang DAN belum ada kiriman hari ini
 */
function tryLockAndSend(PDO $pdo, string $messageKey): bool
{
  // Tahap 1: cek cepat sebelum masuk lock
  if (alreadySentToday($pdo, $messageKey)) {
    error_log("[Reminder] {$messageKey} sudah terkirim hari ini (pre-lock check), skip.");
    return false;
  }

  // Tahap 2: ambil distributed lock (timeout 0 = non-blocking, tidak tunggu)
  $lockName  = 'notif_' . $messageKey;
  $lockStmt  = $pdo->query("SELECT GET_LOCK(" . $pdo->quote($lockName) . ", 0) AS got");
  $row       = $lockStmt->fetch(PDO::FETCH_ASSOC);
  $lockStmt->closeCursor();
  if (!$row || (int)$row['got'] !== 1) {
    error_log("[Reminder] {$messageKey} gagal ambil lock — proses lain sedang berjalan, skip.");
    return false;
  }

  // Tahap 3: double-check setelah lock berhasil dipegang
  if (alreadySentToday($pdo, $messageKey)) {
    $relStmt = $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lockName) . ")");
    $relStmt->closeCursor();
    error_log("[Reminder] {$messageKey} sudah terkirim oleh proses lain (post-lock check), skip.");
    return false;
  }

  return true; // lock dipegang — aman untuk kirim email
}

/**
 * Lepas distributed lock MySQL setelah proses pengiriman selesai.
 */
function releaseLock(PDO $pdo, string $messageKey): void
{
  try {
    $lockName = 'notif_' . $messageKey;
    $relStmt  = $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lockName) . ")");
    $relStmt->closeCursor();
  } catch (\Exception $e) { /* ignore */
  }
}

function buildEmailBody(string $username, string $headerColor, string $badgeLabel, string $intro, string $taskListHtml): string
{
  return "
    <div style='font-family:Arial,sans-serif;max-width:640px;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;'>
        <div style='background:{$headerColor};padding:20px 28px;'>
            <h2 style='color:#fff;margin:0;font-size:18px;'>🛠️ Maintenance Reminder — {$badgeLabel}</h2>
        </div>
        <div style='padding:24px 28px;'>
            <p style='margin-top:0;'>Halo <b>{$username}</b>,</p>
            <p style='color:#475569;'>{$intro}</p>
            {$taskListHtml}
            <p style='color:#475569;'>Harap segera lakukan persiapan unit dan part yang diperlukan.</p>
            <hr style='border:none;border-top:1px solid #e2e8f0;margin:20px 0;'>
            <small style='color:#94a3b8;'>Email otomatis dari Maintenance System — jangan balas email ini.</small>
        </div>
    </div>";
}

/**
 * Reminder berdasarkan reminder_activity dari DB (bukan hardcoded 30).
 * Dipanggil dari dashboard dengan key 'batch-reminder'.
 * 1x per hari — cek via alreadySentToday().
 *
 * [BUGFIX] Return value sendMail() sekarang dicek via $sent counter.
 * logSent() dan error_log sukses hanya dipanggil jika $sent > 0.
 * Sebelumnya: logSent() selalu dipanggil meski semua email gagal,
 * menyebabkan key masuk ke notification_log dan esok hari tidak dikirim ulang.
 */
function processReminderByThreshold(PDO $pdo, ?int $specificId = null): void
{
  $key = $specificId ? "new-schedule-{$specificId}" : 'batch-reminder';

  // Untuk batch harian: gunakan distributed lock agar tahan race condition multi-user
  if (!$specificId) {
    if (!tryLockAndSend($pdo, $key)) return;
  }

  $whereId = $specificId ? "AND s.id = {$specificId}" : '';
  $stmt = $pdo->prepare("
        SELECT s.id, s.machine_name, s.maintenance_point, s.change_date_plan,
               s.department AS department_name, s.line AS line_name,
               s.operation_process AS op_name,
               s.reminder_activity, s.remaining_day
        FROM schedules s
        WHERE s.remaining_day IS NOT NULL AND s.reminder_activity IS NOT NULL
          AND s.remaining_day > 7
          AND s.remaining_day = s.reminder_activity
          {$whereId}
    ");
  $stmt->execute();
  $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
  if (empty($tasks)) {
    error_log('[Reminder] Tidak ada jadwal threshold hari ini.');
    return;
  }

  $admins = getAllRecipients($pdo);
  if (empty($admins)) return;

  $listHtml = '<ul style="color:#334155;line-height:1.8;">';
  foreach ($tasks as $t) {
    $listHtml .= "<li><b>{$t['machine_name']}</b> [{$t['department_name']} | {$t['line_name']} | OP {$t['op_name']}]"
      . " — {$t['maintenance_point']}"
      . " <span style='color:#64748b;'>(Plan: {$t['change_date_plan']} | Sisa: <b>{$t['remaining_day']} hari</b> | Threshold: {$t['reminder_activity']} hari)</span></li>";
  }
  $listHtml .= '</ul>';

  $minDay  = min(array_column($tasks, 'remaining_day'));
  $subject = "📅 Maintenance Reminder: {$minDay} Hari Lagi";

  // [BUGFIX] Hitung email yang benar-benar berhasil terkirim
  $sent = 0;
  foreach ($admins as $admin) {
    $ok = sendMail($admin['email'], $subject, buildEmailBody(
      $admin['name'],
      '#ea580c',
      "{$minDay} Hari",
      'Berikut jadwal yang <b>mencapai batas reminder</b> hari ini:',
      $listHtml
    ));
    if ($ok) $sent++;
  }

  // [BUGFIX] logSent() hanya dipanggil jika minimal 1 email berhasil
  if ($sent > 0) {
    logSent($pdo, $key);
    error_log('[Reminder] batch-reminder BERHASIL terkirim ke ' . $sent . ' dari ' . count($admins) . ' penerima.');
  } else {
    error_log('[Reminder] batch-reminder GAGAL — tidak ada email terkirim. Cek cURL/SSL/API key.');
  }

  // Lepas distributed lock setelah proses selesai (hanya untuk batch, bukan specificId)
  if (!$specificId) {
    releaseLock($pdo, $key);
  }
}

/** Alias untuk update_remaining_days.php */
function processThirtyDayReminders(PDO $pdo): void
{
  processReminderByThreshold($pdo);
}

/**
 * Alert 7 hari predictive.
 * Key: 'batch-alert7'
 *
 * [BUGFIX] Return value sendMail() sekarang dicek via $sent counter.
 * logSent() hanya dipanggil jika $sent > 0.
 * Sebelumnya: logSent() selalu dipanggil meski semua email gagal.
 */
function processSevenDayReminders(PDO $pdo): void
{
  if (!tryLockAndSend($pdo, 'batch-alert7')) return;

  $stmt = $pdo->prepare("
        SELECT s.id, s.machine_name, s.maintenance_point, s.change_date_plan,
               s.department AS department_name, s.line AS line_name,
               s.operation_process AS op_name, s.remaining_day
        FROM schedules s
        WHERE s.remaining_day BETWEEN 1 AND 7 ORDER BY s.remaining_day ASC
    ");
  $stmt->execute();
  $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
  if (empty($tasks)) {
    error_log('[Reminder] Tidak ada jadwal alert 7 hari.');
    releaseLock($pdo, 'batch-alert7');
    return;
  }

  $admins = getAllRecipients($pdo);
  if (empty($admins)) {
    releaseLock($pdo, 'batch-alert7');
    return;
  }

  // Kelompokkan per nilai remaining_day, kirim 1 email per H-N
  $groups = [];
  foreach ($tasks as $t) {
    $groups[(int)$t['remaining_day']][] = $t;
  }

  $totalSent = 0;
  foreach ($groups as $daysLeft => $items) {
    $key = "batch-alert7-h{$daysLeft}";
    if (alreadySentToday($pdo, $key)) continue;

    $listHtml = '<ul style="color:#334155;">';
    foreach ($items as $t) {
      $listHtml .= "<li>"
        . "<b>{$t['machine_name']}</b> [{$t['department_name']} | {$t['line_name']} | OP {$t['op_name']}]"
        . " — {$t['maintenance_point']}"
        . " <span style='color:#dc2626;font-weight:bold;'>(Tgl: {$t['change_date_plan']})</span>"
        . "</li>";
    }
    $listHtml .= '</ul>';

    $subject = "⚠️ ALERT: Maintenance H-{$daysLeft} Hari Lagi!";
    $intro   = "Berikut mesin yang harus dimaintenance dalam <b>{$daysLeft} hari ke depan</b>:";

    $sent = 0;
    foreach ($admins as $admin) {
      $ok = sendMail($admin['email'], $subject, buildEmailBody(
        $admin['name'],
        '#dc2626',
        "H-{$daysLeft}",
        $intro,
        $listHtml
      ));
      if ($ok) $sent++;
    }

    if ($sent > 0) {
      logSent($pdo, $key);
      error_log("[Reminder] {$key} BERHASIL terkirim ke {$sent} dari " . count($admins) . " penerima.");
      $totalSent += $sent;
    } else {
      error_log("[Reminder] {$key} GAGAL — tidak ada email terkirim.");
    }
  }

  releaseLock($pdo, 'batch-alert7');
}

/**
 * Overdue predictive harian.
 * Key: 'batch-overdue'
 */
function processOverdueReminders(PDO $pdo): void
{
  if (!tryLockAndSend($pdo, 'batch-overdue')) return;

  $stmt = $pdo->prepare("
        SELECT s.id, s.machine_name, s.maintenance_point, s.change_date_plan,
               s.remaining_day, s.department AS department_name, s.line AS line_name,
               s.operation_process AS op_name
        FROM schedules s
        WHERE s.remaining_day <= 0 AND (s.maintenance_status IS NULL OR s.maintenance_status != 'done')
        ORDER BY s.remaining_day ASC
    ");
  $stmt->execute();
  $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
  if (empty($tasks)) {
    error_log('[Reminder] Tidak ada jadwal overdue.');
    return;
  }

  $admins = getAllRecipients($pdo);
  if (empty($admins)) return;

  $listHtml = '<ul style="color:#334155;">';
  foreach ($tasks as $t) {
    $late      = abs((int)$t['remaining_day']);
    $listHtml .= "<li><b>{$t['machine_name']}</b> [{$t['department_name']} | {$t['line_name']} | OP {$t['op_name']}]"
      . " — {$t['maintenance_point']}"
      . " (Plan: {$t['change_date_plan']}, <span style='color:#991b1b;font-weight:bold;'>Terlambat {$late} hari</span>)</li>";
  }
  $listHtml .= '</ul>';

  $subject = '🚨 OVERDUE: ' . count($tasks) . ' Jadwal Maintenance Terlewat!';
  $sent    = 0;
  foreach ($admins as $admin) {
    $ok = sendMail($admin['email'], $subject, buildEmailBody(
      $admin['name'],
      '#7f1d1d',
      'OVERDUE',
      'Berikut jadwal yang <b>sudah terlewat</b>. Tindakan segera diperlukan:',
      $listHtml
    ));
    if ($ok) $sent++;
  }
  if ($sent > 0) {
    logSent($pdo, 'batch-overdue');
    error_log('[Reminder] batch-overdue BERHASIL terkirim ke ' . $sent . ' dari ' . count($admins) . ' penerima.');
  } else {
    error_log('[Reminder] batch-overdue GAGAL — tidak ada email terkirim. Cek cURL/SSL/API key.');
  }

  releaseLock($pdo, 'batch-overdue');
}
function sendNewScheduleAlert(PDO $pdo, int $scheduleId): void
{
  processReminderByThreshold($pdo, $scheduleId);
}

/**
 * Dipanggil saat data schedule diedit dan kondisi berubah masuk kategori reminder.
 *
 * [BUGFIX] Return value sendMail() sekarang dicek via $sent counter.
 * logSent() hanya dipanggil jika $sent > 0.
 * Sebelumnya: logSent() selalu dipanggil meski semua email gagal,
 * menyebabkan key masuk ke notification_log dan notifikasi tidak dikirim ulang esok hari.
 */
function sendEditedScheduleAlert(PDO $pdo, int $scheduleId, int $remainingDay): void
{
  $stmt = $pdo->prepare("
        SELECT s.*, s.department AS department_name, s.line AS line_name,
               s.operation_process AS op_name
        FROM schedules s
        WHERE s.id = ?
    ");
  $stmt->execute([$scheduleId]);
  $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$schedule) return;

  $admins = getAllRecipients($pdo);
  if (empty($admins)) return;

  if ($remainingDay <= 0) {
    $key = "edit-overdue-{$scheduleId}";
    if (alreadySentToday($pdo, $key)) return;
    $late        = abs($remainingDay);
    $listHtml    = "<ul style='color:#334155;'>"
      . "<li><b>{$schedule['machine_name']}</b> [{$schedule['department_name']} | {$schedule['line_name']} | OP {$schedule['op_name']}]"
      . " — {$schedule['maintenance_point']}"
      . " (Plan: {$schedule['change_date_plan']}, <span style='color:#991b1b;font-weight:bold;'>Terlambat {$late} hari</span>)</li>"
      . "</ul>";
    $subject     = "🚨 OVERDUE [Edit]: {$schedule['machine_name']} Terlewat {$late} Hari!";
    $headerColor = '#7f1d1d';
    $badgeLabel  = 'OVERDUE';
    $intro       = "Jadwal berikut baru saja <b>diupdate</b> dan statusnya <b>sudah terlewat</b>. Tindakan segera diperlukan:";
  } elseif ($remainingDay <= 7) {
    $key = "edit-alert7-{$scheduleId}";
    if (alreadySentToday($pdo, $key)) return;
    $listHtml    = "<ul style='color:#334155;'>"
      . "<li><span style='color:#2563eb;font-weight:bold;'>[H-{$remainingDay}]</span> "
      . "<b>{$schedule['machine_name']}</b> [{$schedule['department_name']} | {$schedule['line_name']} | OP {$schedule['op_name']}]"
      . " — {$schedule['maintenance_point']}"
      . " <span style='color:#dc2626;font-weight:bold;'>(Tgl: {$schedule['change_date_plan']})</span></li>"
      . "</ul>";
    $subject     = "⚠️ ALERT [Edit]: {$schedule['machine_name']} — Maintenance {$remainingDay} Hari Lagi!";
    $headerColor = '#dc2626';
    $badgeLabel  = "H-{$remainingDay}";
    $intro       = "Jadwal berikut baru saja <b>diupdate</b> dan akan jatuh tempo dalam <b>{$remainingDay} hari</b>:";
  } else {
    $remAct = (int)($schedule['reminder_activity'] ?? 0);
    if ($remAct <= 0 || $remainingDay > $remAct) return;
    $key = "edit-reminder-{$scheduleId}";
    if (alreadySentToday($pdo, $key)) return;
    $listHtml    = "<ul style='color:#334155;line-height:1.8;'>"
      . "<li><b>{$schedule['machine_name']}</b> [{$schedule['department_name']} | {$schedule['line_name']} | OP {$schedule['op_name']}]"
      . " — {$schedule['maintenance_point']}"
      . " <span style='color:#64748b;'>(Plan: {$schedule['change_date_plan']} | Sisa: <b>{$remainingDay} hari</b> | Threshold: {$remAct} hari)</span></li>"
      . "</ul>";
    $subject     = "📅 Reminder [Edit]: {$schedule['machine_name']} — {$remainingDay} Hari Lagi";
    $headerColor = '#ea580c';
    $badgeLabel  = "{$remainingDay} Hari";
    $intro       = "Jadwal berikut baru saja <b>diupdate</b> dan telah mencapai batas reminder:";
  }

  // [BUGFIX] Hitung email yang benar-benar berhasil terkirim
  $sent = 0;
  foreach ($admins as $admin) {
    $ok = sendMail($admin['email'], $subject, buildEmailBody(
      $admin['name'],
      $headerColor,
      $badgeLabel,
      $intro,
      $listHtml
    ));
    if ($ok) $sent++;
  }

  // [BUGFIX] logSent() hanya dipanggil jika minimal 1 email berhasil
  if ($sent > 0) {
    logSent($pdo, $key, $scheduleId);
    error_log("[Reminder] {$key} BERHASIL terkirim ke " . $sent . ' dari ' . count($admins) . " admin.");
  } else {
    error_log("[Reminder] {$key} GAGAL — tidak ada email terkirim. Cek cURL/SSL/API key.");
  }
}

// ══════════════════════════════════════════════════════════════════════════════
//  PREVENTIVE MAINTENANCE — Email Notification Functions
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Batch reminder harian preventive berdasarkan reminder_activity.
 * Key: 'prev-batch-reminder'
 *
 * [BUGFIX] Return value sendMail() sekarang dicek via $sent counter.
 * logSent() hanya dipanggil jika $sent > 0.
 * Sebelumnya: logSent() selalu dipanggil meski semua email gagal —
 * inilah yang menyebabkan log Apache bilang "terkirim ke 2 admin"
 * padahal email tidak masuk ke inbox maupun notification_log tidak valid.
 */
function processPrevReminderByThreshold(PDO $pdo, ?int $specificId = null): void
{
  $key = $specificId ? "prev-new-schedule-{$specificId}" : 'prev-batch-reminder';

  // Untuk batch harian: gunakan distributed lock agar tahan race condition multi-user
  if (!$specificId) {
    if (!tryLockAndSend($pdo, $key)) return;
  }

  $whereId = $specificId ? "AND s.id = {$specificId}" : '';
  $stmt    = $pdo->prepare("
        SELECT s.id, s.machine_name, s.maintenance_point, s.change_date_plan,
               s.department AS department_name, s.line AS line_name,
               s.operation_process AS op_name,
               s.reminder_activity, s.remaining_day
        FROM schedules_preventive s
        WHERE s.remaining_day IS NOT NULL AND s.reminder_activity IS NOT NULL
          AND s.remaining_day > 7
          AND s.remaining_day = s.reminder_activity
          {$whereId}
    ");
  $stmt->execute();
  $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
  if (empty($tasks)) {
    error_log('[PrevReminder] Tidak ada jadwal preventive threshold hari ini.');
    return;
  }

  $admins = getAllRecipients($pdo);
  if (empty($admins)) return;

  $listHtml = '<ul style="color:#334155;line-height:1.8;">';
  foreach ($tasks as $t) {
    $listHtml .= "<li><b>{$t['machine_name']}</b> [{$t['department_name']} | {$t['line_name']} | OP {$t['op_name']}]"
      . " — {$t['maintenance_point']}"
      . " <span style='color:#64748b;'>(Plan: {$t['change_date_plan']} | Sisa: <b>{$t['remaining_day']} hari</b> | Threshold: {$t['reminder_activity']} hari)</span></li>";
  }
  $listHtml .= '</ul>';

  $minDay  = min(array_column($tasks, 'remaining_day'));
  $subject = "📅 [Preventive] Reminder: {$minDay} Hari Lagi";

  // [BUGFIX] Hitung email yang benar-benar berhasil terkirim
  $sent = 0;
  foreach ($admins as $admin) {
    $ok = sendMail($admin['email'], $subject, buildEmailBody(
      $admin['name'],
      '#0f766e',
      "{$minDay} Hari",
      'Berikut jadwal <b>Preventive</b> yang <b>mencapai batas reminder</b> hari ini:',
      $listHtml
    ));
    if ($ok) $sent++;
  }

  // [BUGFIX] logSent() hanya dipanggil jika minimal 1 email berhasil
  if ($sent > 0) {
    logSent($pdo, $key);
    error_log('[PrevReminder] prev-batch-reminder BERHASIL terkirim ke ' . $sent . ' dari ' . count($admins) . ' admin.');
  } else {
    error_log('[PrevReminder] prev-batch-reminder GAGAL — tidak ada email terkirim. Cek cURL/SSL/API key.');
  }

  // Lepas distributed lock (hanya untuk batch, bukan specificId)
  if (!$specificId) {
    releaseLock($pdo, $key);
  }
}

/**
 * Alert 7 hari preventive.
 * Key: 'prev-batch-alert7'
 *
 * [BUGFIX] Return value sendMail() sekarang dicek via $sent counter.
 * logSent() hanya dipanggil jika $sent > 0.
 * Sebelumnya: logSent() selalu dipanggil meski semua email gagal.
 */
function processPrevSevenDayReminders(PDO $pdo): void
{
  if (!tryLockAndSend($pdo, 'prev-batch-alert7')) return;

  $stmt = $pdo->prepare("
        SELECT s.id, s.machine_name, s.maintenance_point, s.change_date_plan,
               s.department AS department_name, s.line AS line_name,
               s.operation_process AS op_name, s.remaining_day
        FROM schedules_preventive s
        WHERE s.remaining_day BETWEEN 1 AND 7
        ORDER BY s.remaining_day ASC
    ");
  $stmt->execute();
  $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
  if (empty($tasks)) {
    error_log('[PrevReminder] Tidak ada jadwal preventive alert 7 hari.');
    releaseLock($pdo, 'prev-batch-alert7');
    return;
  }

  $admins = getAllRecipients($pdo);
  if (empty($admins)) {
    releaseLock($pdo, 'prev-batch-alert7');
    return;
  }

  // Kelompokkan per nilai remaining_day, kirim 1 email per H-N
  $groups = [];
  foreach ($tasks as $t) {
    $groups[(int)$t['remaining_day']][] = $t;
  }

  $totalSent = 0;
  foreach ($groups as $daysLeft => $items) {
    $key = "prev-batch-alert7-h{$daysLeft}";
    if (alreadySentToday($pdo, $key)) continue;

    $listHtml = '<ul style="color:#334155;">';
    foreach ($items as $t) {
      $listHtml .= "<li>"
        . "<b>{$t['machine_name']}</b> [{$t['department_name']} | {$t['line_name']} | OP {$t['op_name']}]"
        . " — {$t['maintenance_point']}"
        . " <span style='color:#dc2626;font-weight:bold;'>(Tgl: {$t['change_date_plan']})</span>"
        . "</li>";
    }
    $listHtml .= '</ul>';

    $subject = "⚠️ [Preventive] ALERT: Maintenance H-{$daysLeft} Hari Lagi!";
    $intro   = "Berikut mesin <b>Preventive</b> yang harus dimaintenance dalam <b>{$daysLeft} hari ke depan</b>:";

    $sent = 0;
    foreach ($admins as $admin) {
      $ok = sendMail($admin['email'], $subject, buildEmailBody(
        $admin['name'],
        '#0f766e',
        "H-{$daysLeft}",
        $intro,
        $listHtml
      ));
      if ($ok) $sent++;
    }

    if ($sent > 0) {
      logSent($pdo, $key);
      error_log("[PrevReminder] {$key} BERHASIL terkirim ke {$sent} dari " . count($admins) . " penerima.");
      $totalSent += $sent;
    } else {
      error_log("[PrevReminder] {$key} GAGAL — tidak ada email terkirim.");
    }
  }

  releaseLock($pdo, 'prev-batch-alert7');
}

/**
 * Overdue preventive harian.
 * Key: 'prev-batch-overdue'
 */
function processPrevOverdueReminders(PDO $pdo): void
{
  if (!tryLockAndSend($pdo, 'prev-batch-overdue')) return;

  $stmt = $pdo->prepare("
        SELECT s.id, s.machine_name, s.maintenance_point, s.change_date_plan,
               s.remaining_day, s.department AS department_name, s.line AS line_name,
               s.operation_process AS op_name
        FROM schedules_preventive s
        WHERE s.remaining_day <= 0
          AND (s.maintenance_status IS NULL OR s.maintenance_status != 'done')
        ORDER BY s.remaining_day ASC
    ");
  $stmt->execute();
  $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
  if (empty($tasks)) {
    error_log('[PrevReminder] Tidak ada jadwal preventive overdue.');
    releaseLock($pdo, 'prev-batch-overdue');
    return;
  }

  $admins = getAllRecipients($pdo);
  if (empty($admins)) {
    releaseLock($pdo, 'prev-batch-overdue');
    return;
  }

  $listHtml = '<ul style="color:#334155;">';
  foreach ($tasks as $t) {
    $late      = abs((int)$t['remaining_day']);
    $listHtml .= "<li><b>{$t['machine_name']}</b> [{$t['department_name']} | {$t['line_name']} | OP {$t['op_name']}]"
      . " — {$t['maintenance_point']}"
      . " (Plan: {$t['change_date_plan']}, <span style='color:#991b1b;font-weight:bold;'>Terlambat {$late} hari</span>)</li>";
  }
  $listHtml .= '</ul>';

  $subject = '🚨 [Preventive] OVERDUE: ' . count($tasks) . ' Jadwal Maintenance Terlewat!';
  $sent    = 0;
  foreach ($admins as $admin) {
    $ok = sendMail($admin['email'], $subject, buildEmailBody(
      $admin['name'],
      '#7f1d1d',
      'OVERDUE',
      'Berikut jadwal <b>Preventive</b> yang <b>sudah terlewat</b>. Tindakan segera diperlukan:',
      $listHtml
    ));
    if ($ok) $sent++;
  }
  if ($sent > 0) {
    logSent($pdo, 'prev-batch-overdue');
    error_log('[PrevReminder] prev-batch-overdue BERHASIL terkirim ke ' . $sent . ' dari ' . count($admins) . ' penerima.');
  } else {
    error_log('[PrevReminder] prev-batch-overdue GAGAL — tidak ada email terkirim. Cek cURL/SSL/API key.');
  }

  releaseLock($pdo, 'prev-batch-overdue');
}

/** Dipanggil saat schedule preventive baru ditambah. */
function sendNewPrevScheduleAlert(PDO $pdo, int $scheduleId): void
{
  processPrevReminderByThreshold($pdo, $scheduleId);
}

/**
 * Dipanggil saat data preventive diedit dan kondisi berubah masuk kategori reminder.
 *
 * [BUGFIX] Return value sendMail() sekarang dicek via $sent counter.
 * logSent() hanya dipanggil jika $sent > 0.
 * Sebelumnya: logSent() selalu dipanggil meski semua email gagal.
 */
function sendEditedPrevScheduleAlert(PDO $pdo, int $scheduleId, int $remainingDay): void
{
  $stmt = $pdo->prepare("
        SELECT s.*, s.department AS department_name, s.line AS line_name,
               s.operation_process AS op_name
        FROM schedules_preventive s
        WHERE s.id = ?
    ");
  $stmt->execute([$scheduleId]);
  $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$schedule) return;

  $admins = getAllRecipients($pdo);
  if (empty($admins)) return;

  if ($remainingDay <= 0) {
    $key = "prev-edit-overdue-{$scheduleId}";
    if (alreadySentToday($pdo, $key)) return;
    $late        = abs($remainingDay);
    $listHtml    = "<ul style='color:#334155;'>"
      . "<li><b>{$schedule['machine_name']}</b> [{$schedule['department_name']} | {$schedule['line_name']} | OP {$schedule['op_name']}]"
      . " — {$schedule['maintenance_point']}"
      . " (Plan: {$schedule['change_date_plan']}, <span style='color:#991b1b;font-weight:bold;'>Terlambat {$late} hari</span>)</li>"
      . "</ul>";
    $subject     = "🚨 [Preventive] OVERDUE [Edit]: {$schedule['machine_name']} Terlewat {$late} Hari!";
    $headerColor = '#7f1d1d';
    $badgeLabel  = 'OVERDUE';
    $intro       = "Jadwal <b>Preventive</b> berikut baru saja <b>diupdate</b> dan statusnya <b>sudah terlewat</b>:";
  } elseif ($remainingDay <= 7) {
    $key = "prev-edit-alert7-{$scheduleId}";
    if (alreadySentToday($pdo, $key)) return;
    $listHtml    = "<ul style='color:#334155;'>"
      . "<li><span style='color:#0d9488;font-weight:bold;'>[H-{$remainingDay}]</span> "
      . "<b>{$schedule['machine_name']}</b> [{$schedule['department_name']} | {$schedule['line_name']} | OP {$schedule['op_name']}]"
      . " — {$schedule['maintenance_point']}"
      . " <span style='color:#dc2626;font-weight:bold;'>(Tgl: {$schedule['change_date_plan']})</span></li>"
      . "</ul>";
    $subject     = "⚠️ [Preventive] ALERT [Edit]: {$schedule['machine_name']} — Maintenance {$remainingDay} Hari Lagi!";
    $headerColor = '#0f766e';
    $badgeLabel  = "H-{$remainingDay}";
    $intro       = "Jadwal <b>Preventive</b> berikut baru saja <b>diupdate</b> dan akan jatuh tempo dalam <b>{$remainingDay} hari</b>:";
  } else {
    $remAct = (int)($schedule['reminder_activity'] ?? 0);
    if ($remAct <= 0 || $remainingDay > $remAct) return;
    $key = "prev-edit-reminder-{$scheduleId}";
    if (alreadySentToday($pdo, $key)) return;
    $listHtml    = "<ul style='color:#334155;line-height:1.8;'>"
      . "<li><b>{$schedule['machine_name']}</b> [{$schedule['department_name']} | {$schedule['line_name']} | OP {$schedule['op_name']}]"
      . " — {$schedule['maintenance_point']}"
      . " <span style='color:#64748b;'>(Plan: {$schedule['change_date_plan']} | Sisa: <b>{$remainingDay} hari</b> | Threshold: {$remAct} hari)</span></li>"
      . "</ul>";
    $subject     = "📅 [Preventive] Reminder [Edit]: {$schedule['machine_name']} — {$remainingDay} Hari Lagi";
    $headerColor = '#0f766e';
    $badgeLabel  = "{$remainingDay} Hari";
    $intro       = "Jadwal <b>Preventive</b> berikut baru saja <b>diupdate</b> dan telah mencapai batas reminder:";
  }

  // [BUGFIX] Hitung email yang benar-benar berhasil terkirim
  $sent = 0;
  foreach ($admins as $admin) {
    $ok = sendMail($admin['email'], $subject, buildEmailBody(
      $admin['name'],
      $headerColor,
      $badgeLabel,
      $intro,
      $listHtml
    ));
    if ($ok) $sent++;
  }

  // [BUGFIX] logSent() hanya dipanggil jika minimal 1 email berhasil
  if ($sent > 0) {
    logSent($pdo, $key, $scheduleId);
    error_log("[PrevReminder] {$key} BERHASIL terkirim ke " . $sent . ' dari ' . count($admins) . " admin.");
  } else {
    error_log("[PrevReminder] {$key} GAGAL — tidak ada email terkirim. Cek cURL/SSL/API key.");
  }
}

/**
 * Dipanggil setelah import Excel predictive selesai.
 *
 * [BUGFIX] Return value sendMail() sekarang dicek via $sent counter di setiap kategori.
 * logSent() hanya dipanggil jika $sent > 0.
 */
function sendImportAlert(PDO $pdo, array $queue): void
{
  if (empty($queue)) return;

  $admins = getAllRecipients($pdo);
  if (empty($admins)) return;

  $ids      = array_column($queue, 'id');
  $idMap    = array_column($queue, 'remaining_day', 'id');
  $inClause = implode(',', array_map('intval', $ids));
  $stmt      = $pdo->query("SELECT s.*, s.department AS department_name, s.line AS line_name, s.operation_process AS op_name FROM schedules s WHERE s.id IN ({$inClause})");
  $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
  $stmt->closeCursor();

  $groups = ['overdue' => [], 'alert7' => [], 'threshold' => []];
  foreach ($schedules as $s) {
    $rd = (int)$idMap[$s['id']];
    if ($rd <= 0) {
      $groups['overdue'][] = $s;
    } elseif ($rd <= 7) {
      $groups['alert7'][] = $s;
    } else {
      $remAct = (int)($s['reminder_activity'] ?? 0);
      if ($remAct > 0 && $rd <= $remAct) {
        $groups['threshold'][] = $s;
      }
    }
  }

  $today = date('Y-m-d');

  // ── Kategori OVERDUE ──────────────────────────────────────────────────
  if (!empty($groups['overdue'])) {
    $key = "import-overdue-{$today}";
    if (!alreadySentToday($pdo, $key)) {
      $listHtml = '<ul style="color:#334155;">';
      foreach ($groups['overdue'] as $s) {
        $rd   = (int)$idMap[$s['id']];
        $late = abs($rd);
        $listHtml .= "<li><b>{$s['machine_name']}</b> [{$s['department_name']} | {$s['line_name']} | OP {$s['op_name']}]"
          . " — {$s['maintenance_point']}"
          . " (Plan: {$s['change_date_plan']}, <span style='color:#991b1b;font-weight:bold;'>Terlambat {$late} hari</span>)</li>";
      }
      $listHtml .= '</ul>';
      $total   = count($groups['overdue']);
      $subject = "🚨 OVERDUE [Import]: {$total} Jadwal Terlewat dari Import Excel!";

      // [BUGFIX] Hitung email yang benar-benar berhasil
      $sent = 0;
      foreach ($admins as $admin) {
        $ok = sendMail($admin['email'], $subject, buildEmailBody(
          $admin['name'],
          '#7f1d1d',
          'OVERDUE',
          "Berikut <b>{$total} jadwal</b> yang baru diimport dan statusnya <b>sudah terlewat</b>:",
          $listHtml
        ));
        if ($ok) $sent++;
      }
      if ($sent > 0) {
        logSent($pdo, $key);
        error_log("[Reminder] {$key} BERHASIL terkirim: {$total} jadwal overdue.");
      } else {
        error_log("[Reminder] {$key} GAGAL — tidak ada email terkirim. Cek cURL/SSL/API key.");
      }
    }
  }

  // ── Kategori ALERT 7 HARI ─────────────────────────────────────────────
  if (!empty($groups['alert7'])) {
    $key = "import-alert7-{$today}";
    if (!alreadySentToday($pdo, $key)) {
      usort($groups['alert7'], fn($a, $b) => $idMap[$a['id']] <=> $idMap[$b['id']]);
      $nearestDay = (int)$idMap[$groups['alert7'][0]['id']];
      $listHtml   = '<ul style="color:#334155;">';
      foreach ($groups['alert7'] as $s) {
        $rd        = (int)$idMap[$s['id']];
        $listHtml .= "<li>"
          . "<span style='color:#2563eb;font-weight:bold;'>[H-{$rd}]</span> "
          . "<b>{$s['machine_name']}</b> [{$s['department_name']} | {$s['line_name']} | OP {$s['op_name']}]"
          . " — {$s['maintenance_point']}"
          . " <span style='color:#dc2626;font-weight:bold;'>(Tgl: {$s['change_date_plan']})</span></li>";
      }
      $listHtml .= '</ul>';
      $total   = count($groups['alert7']);
      $subject = "⚠️ ALERT [Import]: {$total} Jadwal Dalam {$nearestDay} Hari dari Import Excel!";

      // [BUGFIX] Hitung email yang benar-benar berhasil
      $sent = 0;
      foreach ($admins as $admin) {
        $ok = sendMail($admin['email'], $subject, buildEmailBody(
          $admin['name'],
          '#dc2626',
          "H-{$nearestDay}",
          "Berikut <b>{$total} jadwal</b> yang baru diimport dan akan jatuh tempo dalam <b>{$nearestDay} hari ke depan</b>:",
          $listHtml
        ));
        if ($ok) $sent++;
      }
      if ($sent > 0) {
        logSent($pdo, $key);
        error_log("[Reminder] {$key} BERHASIL terkirim: {$total} jadwal alert7.");
      } else {
        error_log("[Reminder] {$key} GAGAL — tidak ada email terkirim. Cek cURL/SSL/API key.");
      }
    }
  }

  // ── Kategori THRESHOLD REMINDER ───────────────────────────────────────
  if (!empty($groups['threshold'])) {
    $key = "import-reminder-{$today}";
    if (!alreadySentToday($pdo, $key)) {
      $minDay   = min(array_map(fn($s) => (int)$idMap[$s['id']], $groups['threshold']));
      $listHtml = '<ul style="color:#334155;line-height:1.8;">';
      foreach ($groups['threshold'] as $s) {
        $rd     = (int)$idMap[$s['id']];
        $remAct = (int)($s['reminder_activity'] ?? 0);
        $listHtml .= "<li><b>{$s['machine_name']}</b> [{$s['department_name']} | {$s['line_name']} | OP {$s['op_name']}]"
          . " — {$s['maintenance_point']}"
          . " <span style='color:#64748b;'>(Plan: {$s['change_date_plan']} | Sisa: <b>{$rd} hari</b> | Threshold: {$remAct} hari)</span></li>";
      }
      $listHtml .= '</ul>';
      $total   = count($groups['threshold']);
      $subject = "📅 Reminder [Import]: {$total} Jadwal Mencapai Batas Reminder dari Import Excel!";

      // [BUGFIX] Hitung email yang benar-benar berhasil
      $sent = 0;
      foreach ($admins as $admin) {
        $ok = sendMail($admin['email'], $subject, buildEmailBody(
          $admin['name'],
          '#ea580c',
          "{$minDay} Hari",
          "Berikut <b>{$total} jadwal</b> yang baru diimport dan telah mencapai batas reminder:",
          $listHtml
        ));
        if ($ok) $sent++;
      }
      if ($sent > 0) {
        logSent($pdo, $key);
        error_log("[Reminder] {$key} BERHASIL terkirim: {$total} jadwal threshold.");
      } else {
        error_log("[Reminder] {$key} GAGAL — tidak ada email terkirim. Cek cURL/SSL/API key.");
      }
    }
  }
}

/**
 * Dipanggil setelah import Excel preventive selesai.
 *
 * [BUGFIX] Return value sendMail() sekarang dicek via $sent counter di setiap kategori.
 * logSent() hanya dipanggil jika $sent > 0.
 */
function sendPrevImportAlert(PDO $pdo, array $queue): void
{
  if (empty($queue)) return;

  $admins = getAllRecipients($pdo);
  if (empty($admins)) return;

  $ids       = array_column($queue, 'id');
  $idMap     = array_column($queue, 'remaining_day', 'id');
  $inClause  = implode(',', array_map('intval', $ids));
  $stmt      = $pdo->query("SELECT s.*, s.department AS department_name, s.line AS line_name, s.operation_process AS op_name FROM schedules_preventive s WHERE s.id IN ({$inClause})");
  $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
  $stmt->closeCursor();

  $groups = ['overdue' => [], 'alert7' => [], 'threshold' => []];
  foreach ($schedules as $s) {
    $rd = (int)$idMap[$s['id']];
    if ($rd <= 0) {
      $groups['overdue'][] = $s;
    } elseif ($rd <= 7) {
      $groups['alert7'][] = $s;
    } else {
      $remAct = (int)($s['reminder_activity'] ?? 0);
      if ($remAct > 0 && $rd <= $remAct) {
        $groups['threshold'][] = $s;
      }
    }
  }

  $today = date('Y-m-d');

  if (!empty($groups['overdue'])) {
    $key = "prev-import-overdue-{$today}";
    if (!alreadySentToday($pdo, $key)) {
      $listHtml = '<ul style="color:#334155;">';
      foreach ($groups['overdue'] as $s) {
        $late      = abs((int)$idMap[$s['id']]);
        $listHtml .= "<li><b>{$s['machine_name']}</b> [{$s['department_name']} | {$s['line_name']} | OP {$s['op_name']}]"
          . " — {$s['maintenance_point']}"
          . " (Plan: {$s['change_date_plan']}, <span style='color:#991b1b;font-weight:bold;'>Terlambat {$late} hari</span>)</li>";
      }
      $listHtml .= '</ul>';
      $total   = count($groups['overdue']);
      $subject = "🚨 [Preventive] OVERDUE [Import]: {$total} Jadwal Terlewat dari Import Excel!";

      // [BUGFIX] Hitung email yang benar-benar berhasil
      $sent = 0;
      foreach ($admins as $admin) {
        $ok = sendMail($admin['email'], $subject, buildEmailBody(
          $admin['name'],
          '#7f1d1d',
          'OVERDUE',
          "Berikut <b>{$total} jadwal Preventive</b> yang baru diimport dan statusnya <b>sudah terlewat</b>:",
          $listHtml
        ));
        if ($ok) $sent++;
      }
      if ($sent > 0) {
        logSent($pdo, $key);
        error_log("[PrevReminder] {$key} BERHASIL terkirim: {$total} jadwal overdue.");
      } else {
        error_log("[PrevReminder] {$key} GAGAL — tidak ada email terkirim. Cek cURL/SSL/API key.");
      }
    }
  }

  if (!empty($groups['alert7'])) {
    $key = "prev-import-alert7-{$today}";
    if (!alreadySentToday($pdo, $key)) {
      usort($groups['alert7'], fn($a, $b) => $idMap[$a['id']] <=> $idMap[$b['id']]);
      $nearestDay = (int)$idMap[$groups['alert7'][0]['id']];
      $listHtml   = '<ul style="color:#334155;">';
      foreach ($groups['alert7'] as $s) {
        $rd        = (int)$idMap[$s['id']];
        $listHtml .= "<li>"
          . "<span style='color:#0d9488;font-weight:bold;'>[H-{$rd}]</span> "
          . "<b>{$s['machine_name']}</b> [{$s['department_name']} | {$s['line_name']} | OP {$s['op_name']}]"
          . " — {$s['maintenance_point']}"
          . " <span style='color:#dc2626;font-weight:bold;'>(Tgl: {$s['change_date_plan']})</span></li>";
      }
      $listHtml .= '</ul>';
      $total   = count($groups['alert7']);
      $subject = "⚠️ [Preventive] ALERT [Import]: {$total} Jadwal Dalam {$nearestDay} Hari dari Import Excel!";

      // [BUGFIX] Hitung email yang benar-benar berhasil
      $sent = 0;
      foreach ($admins as $admin) {
        $ok = sendMail($admin['email'], $subject, buildEmailBody(
          $admin['name'],
          '#0f766e',
          "H-{$nearestDay}",
          "Berikut <b>{$total} jadwal Preventive</b> yang baru diimport dan akan jatuh tempo dalam <b>{$nearestDay} hari ke depan</b>:",
          $listHtml
        ));
        if ($ok) $sent++;
      }
      if ($sent > 0) {
        logSent($pdo, $key);
        error_log("[PrevReminder] {$key} BERHASIL terkirim: {$total} jadwal alert7.");
      } else {
        error_log("[PrevReminder] {$key} GAGAL — tidak ada email terkirim. Cek cURL/SSL/API key.");
      }
    }
  }

  if (!empty($groups['threshold'])) {
    $key = "prev-import-reminder-{$today}";
    if (!alreadySentToday($pdo, $key)) {
      $minDay   = min(array_map(fn($s) => (int)$idMap[$s['id']], $groups['threshold']));
      $listHtml = '<ul style="color:#334155;line-height:1.8;">';
      foreach ($groups['threshold'] as $s) {
        $rd     = (int)$idMap[$s['id']];
        $remAct = (int)($s['reminder_activity'] ?? 0);
        $listHtml .= "<li><b>{$s['machine_name']}</b> [{$s['department_name']} | {$s['line_name']} | OP {$s['op_name']}]"
          . " — {$s['maintenance_point']}"
          . " <span style='color:#64748b;'>(Plan: {$s['change_date_plan']} | Sisa: <b>{$rd} hari</b> | Threshold: {$remAct} hari)</span></li>";
      }
      $listHtml .= '</ul>';
      $total   = count($groups['threshold']);
      $subject = "📅 [Preventive] Reminder [Import]: {$total} Jadwal Mencapai Batas Reminder dari Import Excel!";

      // [BUGFIX] Hitung email yang benar-benar berhasil
      $sent = 0;
      foreach ($admins as $admin) {
        $ok = sendMail($admin['email'], $subject, buildEmailBody(
          $admin['name'],
          '#0f766e',
          "{$minDay} Hari",
          "Berikut <b>{$total} jadwal Preventive</b> yang baru diimport dan telah mencapai batas reminder:",
          $listHtml
        ));
        if ($ok) $sent++;
      }
      if ($sent > 0) {
        logSent($pdo, $key);
        error_log("[PrevReminder] {$key} BERHASIL terkirim: {$total} jadwal threshold.");
      } else {
        error_log("[PrevReminder] {$key} GAGAL — tidak ada email terkirim. Cek cURL/SSL/API key.");
      }
    }
  }
}
