<?php
// ============================================================
// /api/payments/index.php
// GET  → list payments (role-scoped)
// POST → record payment
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';

handleOptions();
$user = requireAuth();
$db   = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $sql = "SELECT pay.*, t.name AS tenant_name, t.property_id
            FROM payments pay JOIN tenants t ON pay.tenant_id=t.id";
    if ($user['role'] === 'tenant') {
        $tStmt = $db->prepare("SELECT id FROM tenants WHERE user_id=? LIMIT 1");
        $tStmt->execute([$user['id']]);
        $t = $tStmt->fetch();
        $stmt = $db->prepare($sql . " WHERE pay.tenant_id=? ORDER BY paid_at DESC");
        $stmt->execute([$t['id'] ?? '']);
    } elseif ($user['role'] === 'landlord') {
        $stmt = $db->prepare($sql . "
            JOIN properties p ON t.property_id=p.id
            JOIN landlords l ON p.landlord_id=l.id
            WHERE l.user_id=? ORDER BY paid_at DESC");
        $stmt->execute([$user['id']]);
    } else {
        $stmt = $db->query($sql . " ORDER BY paid_at DESC LIMIT 200");
    }
    sendSuccess($stmt->fetchAll());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole(['admin','landlord']);
    $body     = getBody();
    $tenantId = $body['tenant_id'] ?? '';
    $amount   = floatval($body['amount'] ?? 0);
    $method   = $body['method'] ?? 'mpesa';
    $ref      = sanitize($body['reference'] ?? '');
    $type     = $body['type'] ?? 'rent';
    $status   = $body['status'] ?? 'confirmed';
    if (!$tenantId || $amount <= 0) sendError('tenant_id and amount required');

    $payId = genId('PAY');
    $db->prepare("INSERT INTO payments (id,tenant_id,amount,method,reference,type,status) VALUES (?,?,?,?,?,?,?)")
       ->execute([$payId, $tenantId, $amount, $method, $ref, $type, $status]);

    // If confirmed, reduce arrears
    if ($status === 'confirmed') {
        $db->prepare("UPDATE tenants SET arrears=GREATEST(0, arrears-?) WHERE id=?")->execute([$amount, $tenantId]);
    }

    auditLog('record_payment', 'payments', "Recorded KES " . number_format($amount) . " for tenant $tenantId via $method", $db);
    sendSuccess(['payment_id' => $payId], 'Payment recorded');
}

function sanitize(string $v): string { return trim(htmlspecialchars($v, ENT_QUOTES, 'UTF-8')); }
