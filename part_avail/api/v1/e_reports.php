<?php
require_once __DIR__ . '/../../config.php';
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

header('Content-Type: application/json');

function apiRespond(int $httpCode, array $body): void
{
    http_response_code($httpCode);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ── 1) Ambil & validasi parameter filter ─────────────────────────────────────
$startDate   = trim($_GET['start_date'] ?? '');
$endDate     = trim($_GET['end_date']   ?? '');
$department  = trim($_GET['department'] ?? '');
$line        = trim($_GET['line']       ?? '');
$op          = trim($_GET['op']         ?? '');
$machineName = trim($_GET['machine_name'] ?? '');
$status      = trim($_GET['status']     ?? '');
$source      = trim($_GET['source']     ?? '');

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

// ── 3) Bangun query dengan filter dinamis (prepared statement) ──────────────
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

$countStmt = $pdo->prepare("SELECT COUNT(*) AS total FROM e_reports r WHERE {$whereSql}");
$countStmt->execute($params);
$total = (int)$countStmt->fetch()['total'];

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
