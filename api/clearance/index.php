<?php
// ============================================================
// /api/clearance/index.php
// GET  ?national_id=xxx → lookup clearance by NID
// GET  (no param)       → list all clearance records
// POST → issue certificate or update status
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/validate.php';

handleOptions();
$user = requireAuth();
$db   = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $nid = trim($_GET['national_id'] ?? '');

    if ($nid) {
        // Lookup by NID — any authenticated user can do this
        if (!validateNID($nid)) sendError('Invalid National ID format');
        $stmt = $db->prepare("
            SELECT t.id, t.name, t.national_id, t.status, t.arrears, t.clearance_cert,
                   t.kyc_status, t.property_id, t.kyc_reject_reason,
                   p.name AS property_name, cr.cert_number, cr.status AS cert_status,
                   cr.issued_at, cr.notes
            FROM tenants t
            LEFT JOIN properties p     ON t.property_id=p.id
            LEFT JOIN clearance_registry cr ON t.id=cr.tenant_id
            WHERE t.national_id=?
            ORDER BY cr.issued_at DESC
            LIMIT 1
        ");
        $stmt->execute([$nid]);
        $record = $stmt->fetch();

        if (!$record) {
            sendSuccess(['found' => false, 'national_id' => $nid],
                'No records found for this National ID. New tenant — proceed with KYC registration.');
        }

        auditLog('clearance_lookup', 'clearance', "Looked up NID: $nid", $db);
        sendSuccess(['found' => true, 'record' => $record]);
    }

    // List all
    $stmt = $db->query("
        SELECT t.id, t.name, t.national_id, t.status, t.arrears,
               t.kyc_status, t.clearance_cert, p.name AS property_name,
               cr.cert_number, cr.issued_at, cr.status AS cert_status
        FROM tenants t
        LEFT JOIN properties p ON t.property_id=p.id
        LEFT JOIN clearance_registry cr ON t.id=cr.tenant_id
        ORDER BY t.name
    ");
    sendSuccess($stmt->fetchAll());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();
    $body     = getBody();
    $action   = $body['action']    ?? 'issue';
    $tenantId = $body['tenant_id'] ?? '';
    if (!$tenantId) sendError('tenant_id required');

    $tStmt = $db->prepare("SELECT * FROM tenants WHERE id=? LIMIT 1");
    $tStmt->execute([$tenantId]);
    $tenant = $tStmt->fetch();
    if (!$tenant) sendError('Tenant not found', 404);

    if ($action === 'issue') {
        if ($tenant['arrears'] > 0) {
            sendError("Cannot issue clearance: KES " . number_format($tenant['arrears']) . " in unpaid arrears.");
        }
        $cert = 'CL-' . date('Y') . '-' . str_pad(rand(100,999),3,'0',STR_PAD_LEFT);
        $db->prepare("UPDATE tenants SET status='cleared', clearance_cert=? WHERE id=?")->execute([$cert, $tenantId]);
        $db->prepare("
            INSERT INTO clearance_registry (tenant_id,national_id,property_id,cert_number,status,issued_by,issued_at)
            VALUES (?,?,?,?,'cleared',?,NOW())
            ON DUPLICATE KEY UPDATE cert_number=VALUES(cert_number),status='cleared',issued_by=VALUES(issued_by),issued_at=NOW()
        ")->execute([$tenantId,$tenant['national_id'],$tenant['property_id'],$cert,$user['id']]);

        auditLog('issue_clearance','clearance',"Issued $cert for {$tenant['name']}", $db);
        sendSuccess(['cert' => $cert], "Certificate $cert issued for {$tenant['name']}");
    }

    if ($action === 'revoke') {
        $db->prepare("UPDATE tenants SET status='flagged', clearance_cert=NULL WHERE id=?")->execute([$tenantId]);
        $db->prepare("UPDATE clearance_registry SET status='flagged' WHERE tenant_id=?")->execute([$tenantId]);
        auditLog('revoke_clearance','clearance',"Revoked clearance for {$tenant['name']}", $db);
        sendSuccess([], "Clearance revoked for {$tenant['name']}");
    }

    sendError("Unknown action: $action");
}
