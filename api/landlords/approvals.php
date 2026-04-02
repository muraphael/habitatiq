<?php
// ============================================================
// /api/landlords/approvals.php — Admin manages landlord accounts
// GET  → list all landlord applications
// POST → { action: approve|reject|suspend, landlord_id, [reason] }
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';

handleOptions();
requireAdmin();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->query("
        SELECT la.*, l.company_name, l.phone, l.kra_pin, l.business_reg, l.address,
               u.email, u.status AS user_status, u.name
        FROM landlord_applications la
        JOIN landlords l ON la.landlord_id = l.id
        JOIN users u ON l.user_id = u.id
        ORDER BY la.submitted_at DESC
    ");
    sendSuccess($stmt->fetchAll());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body       = getBody();
    $action     = $body['action']      ?? '';
    $landlordId = $body['landlord_id'] ?? '';
    $reason     = $body['reason']      ?? '';

    if (!$landlordId) sendError('landlord_id required');

    $llStmt = $db->prepare("SELECT l.*, u.id AS user_id FROM landlords l JOIN users u ON l.user_id=u.id WHERE l.id=? LIMIT 1");
    $llStmt->execute([$landlordId]);
    $ll = $llStmt->fetch();
    if (!$ll) sendError('Landlord not found', 404);

    $user = getCurrentUser();

    switch ($action) {
        case 'approve':
            $db->prepare("UPDATE users SET status='active' WHERE id=?")->execute([$ll['user_id']]);
            $db->prepare("UPDATE landlord_applications SET status='approved', reviewed_by=?, decided_at=NOW() WHERE landlord_id=? AND status='pending'")
               ->execute([$user['id'], $landlordId]);
            auditLog('approve_landlord', 'landlords', "Approved landlord: {$ll['company_name']} ($landlordId)");
            sendSuccess([], 'Landlord approved. Full portal access granted.');

        case 'reject':
            if (!$reason) sendError('Rejection reason required');
            $db->prepare("UPDATE users SET status='rejected' WHERE id=?")->execute([$ll['user_id']]);
            $db->prepare("UPDATE landlord_applications SET status='rejected', reject_reason=?, reviewed_by=?, decided_at=NOW() WHERE landlord_id=?")
               ->execute([$reason, $user['id'], $landlordId]);
            auditLog('reject_landlord', 'landlords', "Rejected {$ll['company_name']}: $reason");
            sendSuccess([], 'Landlord registration rejected');

        case 'suspend':
            $db->prepare("UPDATE users SET status='suspended' WHERE id=?")->execute([$ll['user_id']]);
            $db->prepare("UPDATE landlord_applications SET status='suspended', reviewed_by=?, decided_at=NOW() WHERE landlord_id=?")
               ->execute([$user['id'], $landlordId]);
            // Reassign tenants to admin oversight
            $db->prepare("UPDATE tenants SET caretaker='Admin Oversight' WHERE property_id IN (SELECT id FROM properties WHERE landlord_id=?)")
               ->execute([$landlordId]);
            auditLog('suspend_landlord', 'landlords', "Suspended {$ll['company_name']} ($landlordId)");
            sendSuccess([], 'Landlord suspended. Tenants reassigned to admin oversight.');

        case 're_approve':
            $db->prepare("UPDATE users SET status='active' WHERE id=?")->execute([$ll['user_id']]);
            $db->prepare("UPDATE landlord_applications SET status='approved', reviewed_by=?, decided_at=NOW() WHERE landlord_id=?")
               ->execute([$user['id'], $landlordId]);
            auditLog('reapprove_landlord', 'landlords', "Re-approved {$ll['company_name']}");
            sendSuccess([], 'Landlord account re-approved');

        default:
            sendError("Unknown action: $action");
    }
}
