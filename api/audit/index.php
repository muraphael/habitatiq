<?php
// ============================================================
// GET  /api/audit/index.php  → list audit logs (admin only)
// POST /api/audit/index.php  → write a log entry
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';

handleOptions();
$user = requireAuth();
$db   = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    requireAdmin();
    $limit  = min(500, intval($_GET['limit'] ?? 200));
    $action = $_GET['action'] ?? '';
    $role   = $_GET['role']   ?? '';

    $where  = [];
    $params = [];
    if ($action) { $where[] = 'action LIKE ?'; $params[] = "%$action%"; }
    if ($role)   { $where[] = 'role = ?';       $params[] = $role; }
    $sql = "SELECT * FROM audit_logs"
         . ($where ? ' WHERE '.implode(' AND ',$where) : '')
         . " ORDER BY created_at DESC LIMIT $limit";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    sendSuccess($stmt->fetchAll());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Any authenticated user can write audit log entries
    $body   = getBody();
    $action = $body['action'] ?? '';
    $entity = $body['entity'] ?? '';
    $detail = $body['detail'] ?? '';
    if (!$action) sendError('action is required');
    auditLog($action, $entity, $detail, $db);
    sendSuccess([], 'Logged');
}
