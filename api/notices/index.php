<?php
// ============================================================
// /api/notices/index.php
// GET  → list notices (role-scoped)
// POST → send notice
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';

handleOptions();
$user = requireAuth();
$db   = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($user['role'] === 'tenant') {
        $tStmt = $db->prepare("SELECT id FROM tenants WHERE user_id=? LIMIT 1");
        $tStmt->execute([$user['id']]);
        $t = $tStmt->fetch();
        $stmt = $db->prepare("SELECT n.*, u.name AS from_name FROM notices n LEFT JOIN users u ON n.from_user=u.id WHERE n.to_tenant=? ORDER BY created_at DESC");
        $stmt->execute([$t['id'] ?? '']);
    } elseif ($user['role'] === 'landlord') {
        $stmt = $db->prepare("
            SELECT n.*, u.name AS from_name, t.name AS to_tenant_name
            FROM notices n
            LEFT JOIN users u  ON n.from_user=u.id
            LEFT JOIN tenants t ON n.to_tenant=t.id
            WHERE n.from_user=? ORDER BY n.created_at DESC");
        $stmt->execute([$user['id']]);
    } else {
        $stmt = $db->query("SELECT n.*, u.name AS from_name, t.name AS to_tenant_name FROM notices n LEFT JOIN users u ON n.from_user=u.id LEFT JOIN tenants t ON n.to_tenant=t.id ORDER BY n.created_at DESC LIMIT 100");
    }
    sendSuccess($stmt->fetchAll());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole(['admin','landlord']);
    $body      = getBody();
    $toTenant  = $body['to_tenant']  ?? '';
    $type      = $body['type']       ?? 'general';
    $message   = trim($body['message']  ?? '');
    $channel   = $body['channel']    ?? 'system';
    $amount    = floatval($body['amount'] ?? 0);
    if (!$toTenant || !$message) sendError('to_tenant and message are required');

    $noticeId = genId('N');
    $db->prepare("INSERT INTO notices (id,from_user,to_tenant,type,message,channel,status,amount)
                  VALUES (?,?,?,?,?,?,'pending',?)")
       ->execute([$noticeId, $user['id'], $toTenant, $type, $message, $channel, $amount]);

    // In production: trigger SMS/WhatsApp via Africa's Talking here
    $db->prepare("UPDATE notices SET status='sent', sent_at=NOW() WHERE id=?")->execute([$noticeId]);

    auditLog('send_notice', 'notices', "Sent $type notice to tenant $toTenant", $db);
    sendSuccess(['notice_id' => $noticeId], 'Notice sent');
}
