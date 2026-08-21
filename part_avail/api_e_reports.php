<?php
require_once __DIR__ . '/config.php';
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

header('Content-Type: application/json');

function apiRespond(int $httpCode, array $body): void
{
    http_response_code($httpCode);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ── 1) Autentikasi API Key ───────────────────────────────────────────────────
// Cek header X-API-Key dulu, fallback ke query param ?api_key= kalau header
// tidak ada (beberapa tool/HTTP client lebih gampang pakai query param).
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? ($_GET['api_key'] ?? '');
$apiKey = trim($apiKey);

if ($apiKey === '') {
    apiRespond(401, ['success' => false, 'message' => 'API key wajib disertakan (header X-API-Key atau ?api_key=).']);
}

$keyStmt = $pdo->prepare("SELECT id, owner_name, is_active FROM api_keys WHERE api_key = ?");
$keyStmt->execute([$apiKey]);
$keyRow = $keyStmt->fetch();

if (!$keyRow || (int)$keyRow['is_active'] !== 1) {
    apiRespond(401, ['success' => false, 'message' => 'API key tidak valid atau sudah dinonaktifkan.']);
}

// Catat kapan terakhir dipakai — berguna buat audit/lihat key mana yang
// masih aktif dipakai dan mana yang bisa dimatikan.
$pdo->prepare("UPDATE api_keys SET last_used_at = NOW() WHERE id = ?")->execute([$keyRow['id']]);

// ── 2) Ambil & validasi parameter filter ─────────────────────────────────────
$startDate = trim($_GET['start_date'] ?? '');
$endDate   = trim($_GET['end_date']   ?? '');
$department = trim($_GET['department'] ?? '');
$line       = trim($_GET['line']       ?? '');
$op         = trim($_GET['op']         ?? '');
$machineName = trim($_GET['machine_name'] ?? '');
$status     = trim($_GET['status']     ?? '');
$source     = trim($_GET['source']     ?? '');

$limit  = isset($_GET['limit'])  ? max(1, min(500, (int)$_GET['limit'])) : 100;
$offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

if ($startDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
    apiRespond(400, ['success' => false, 'message' => 'Format start_date harus YYYY-MM-DD.']);
}
if ($endDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    apiRespond(400, ['success' => false, 'message' => 'Format end_date harus YYYY-MM-DD.']);
}
if ($status !== '' && !in_array($status, ['selesai', 'belum selesai'], true)) {
    apiRespond(400, ['success' => false, 'message' => "Parameter status harus 'selesai' atau 'belum selesai'."]);
}
if ($source !== '' && !in_array($source, ['conrod', 'maintenance'], true)) {
    apiRespond(400, ['success' => false, 'message' => "Parameter source harus 'conrod' atau 'maintenance'."]);
}

// ── 3) Bangun query dengan filter dinamis (prepared statement, aman dari SQL injection) ──
$where  = ['1 = 1'];
$params = [];

if ($startDate !== '') {
    $where[] = 'r.report_date >= ?';
    $params[] = $startDate;
}
if ($endDate !== '') {
    $where[] = 'r.report_date <= ?';
    $params[] = $endDate;
}
if ($department !== '') {
    $where[] = 'r.department = ?';
    $params[] = $department;
}
if ($line !== '') {
    $where[] = 'r.line = ?';
    $params[] = $line;
}
if ($op !== '') {
    $where[] = 'r.op = ?';
    $params[] = $op;
}
if ($machineName !== '') {
    $where[] = 'r.machine_name LIKE ?';
    $params[] = '%' . $machineName . '%';
}
if ($status !== '') {
    $where[] = 'r.status = ?';
    $params[] = $status;
}

// Sumber laporan (conrod vs maintenance) — pakai ekspresi yang sama persis
// dengan yang dipakai export_history_report.php & history_report.php, supaya
// hasil API selalu sinkron dengan apa yang tampil di web.
if ($source !== '') {
    $rootConrodExpr = "EXISTS (
        SELECT 1 FROM e_reports rt
        LEFT JOIN users ru ON ru.username = rt.reported_by
        WHERE rt.id = COALESCE(r.parent_id, r.id)
          AND (
                (rt.foreman IS NOT NULL AND rt.foreman <> '')
             OR (rt.source_role IS NOT NULL AND rt.source_role = 'admin_conrod')
             OR (rt.source_role IS NULL AND ru.role = 'admin_conrod')
          )
    )";
    $where[] = $source === 'conrod' ? "{$rootConrodExpr}" : "NOT {$rootConrodExpr}";
}

$whereSql = implode(' AND ', $where);

// Total count (buat info pagination di response, tanpa LIMIT)
$countStmt = $pdo->prepare("SELECT COUNT(*) AS total FROM e_reports r WHERE {$whereSql}");
$countStmt->execute($params);
$total = (int)$countStmt->fetch()['total'];

// Data — kolom yang dikirim: identitas laporan, lokasi mesin, waktu kejadian
// s/d selesai, durasi, deskripsi masalah/tindakan, status, dan metadata asal
// laporan (foreman/source_role, dipakai konsumen API buat tahu itu laporan
// dari admin_conrod atau admin_maintenance kalau perlu).
$dataStmt = $pdo->prepare("
    SELECT
        r.id, r.parent_id, r.report_date, r.department, r.line, r.op,
        r.machine_name, r.machine_type, r.shift,
        r.repair_start, r.repair_finish, r.conrod_finish_at, r.duration_minutes,
        r.problem, r.action, r.status,
        r.reported_by, r.pic, r.foreman, r.source_role,
        r.created_at
    FROM e_reports r
    WHERE {$whereSql}
    ORDER BY r.report_date DESC, r.created_at DESC
    LIMIT {$limit} OFFSET {$offset}
");
$dataStmt->execute($params);
$rows = $dataStmt->fetchAll();

apiRespond(200, [
    'success' => true,
    'count'   => count($rows),
    'total'   => $total,
    'limit'   => $limit,
    'offset'  => $offset,
    'data'    => $rows,
]);