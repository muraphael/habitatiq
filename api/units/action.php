<?php
// ============================================================
// /api/units/action.php — Unit Management Actions
// POST → { action, unit_id, [tenant_id], [status], [rent], [notes] }
// Actions: update_status, update_rent, assign_tenant,
//          unassign_tenant, set_maintenance, get_units
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/validate.php';

handleOptions();

$user   = requireRole(['admin','landlord']);
$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// GET — list units for a property
if ($method === 'GET') {
    $propId = $_GET['property_id'] ?? '';
    if (!$propId) sendError('property_id required');

    // Verify access
    if ($user['role'] === 'landlord') {
        $check = $db->prepare("SELECT 1 FROM properties p JOIN landlords l ON p.landlord_id=l.id WHERE p.id=? AND l.user_id=? LIMIT 1");
        $check->execute([$propId, $user['id']]);
        if (!$check->fetch()) sendError('Forbidden', 403);
    }

    $stmt = $db->prepare("
        SELECT u.*,
               t.id AS tenant_id, t.name AS tenant_name, t.phone AS tenant_phone,
               t.kyc_status, t.status AS tenant_status, t.arrears, t.lease_end
        FROM units u
        LEFT JOIN tenants t ON u.id = t.unit_id AND t.status IN ('active','cleared','pending_approval')
        WHERE u.property_id=?
        ORDER BY u.label
    ");
    $stmt->execute([$propId]);
    sendSuccess($stmt->fetchAll());
}

if ($method === 'POST') {
    $body   = getBody();
    $action = $body['action'] ?? '';
    $unitId = $body['unit_id'] ?? '';
    if (!$unitId) sendError('unit_id required');

    // Load unit
    $uStmt = $db->prepare("SELECT u.*, p.landlord_id FROM units u JOIN properties p ON u.property_id=p.id WHERE u.id=? LIMIT 1");
    $uStmt->execute([$unitId]);
    $unit = $uStmt->fetch();
    if (!$unit) sendError('Unit not found', 404);

    // Landlord ownership check
    if ($user['role'] === 'landlord') {
        $ownerCheck = $db->prepare("SELECT 1 FROM landlords WHERE id=? AND user_id=? LIMIT 1");
        $ownerCheck->execute([$unit['landlord_id'], $user['id']]);
        if (!$ownerCheck->fetch()) sendError('Forbidden — you do not own this unit', 403);
    }

    switch ($action) {

        case 'update_status':
            $status = $body['status'] ?? '';
            $allowed = ['vacant','occupied','maintenance'];
            if (!in_array($status, $allowed)) sendError('Invalid status');
            $db->prepare("UPDATE units SET status=? WHERE id=?")->execute([$status, $unitId]);
            auditLog('update_unit', 'units', "Unit $unitId status → $status", $db);
            sendSuccess([], "Unit status updated to $status");

        case 'update_rent':
            $rent = floatval($body['rent'] ?? 0);
            if ($rent <= 0) sendError('Rent must be greater than 0');
            $db->prepare("UPDATE units SET rent=? WHERE id=?")->execute([$rent, $unitId]);
            auditLog('update_unit', 'units', "Unit $unitId rent → KES " . number_format($rent), $db);
            sendSuccess([], 'Unit rent updated');

        case 'update_notes':
            $notes = trim($body['notes'] ?? '');
            $db->prepare("UPDATE units SET notes=? WHERE id=?")->execute([$notes, $unitId]);
            sendSuccess([], 'Notes updated');

        case 'set_maintenance':
            $notes    = sanitize($body['notes']    ?? '');
            $priority = $body['priority'] ?? 'medium';
            $tenantId = $body['tenant_id'] ?? null;

            // Create maintenance request
            $mId = genId('M');
            $db->prepare("
                INSERT INTO maintenance_requests (id, property_id, unit_id, tenant_id, issue, priority, status)
                VALUES (?, ?, ?, ?, ?, ?, 'pending')
            ")->execute([$mId, $unit['property_id'], $unitId, $tenantId, $notes, $priority]);

            $db->prepare("UPDATE units SET status='maintenance', notes=? WHERE id=?")->execute([$notes, $unitId]);

            auditLog('maintenance_request', 'maintenance', "Unit $unitId marked for maintenance: $notes", $db);
            sendSuccess(['request_id' => $mId], 'Maintenance request created');

        case 'resolve_maintenance':
            $db->prepare("UPDATE units SET status='vacant' WHERE id=? AND status='maintenance'")->execute([$unitId]);
            $db->prepare("UPDATE maintenance_requests SET status='resolved', resolved_at=NOW() WHERE unit_id=? AND status!='resolved'")->execute([$unitId]);
            auditLog('resolve_maintenance', 'maintenance', "Resolved maintenance for unit $unitId", $db);
            sendSuccess([], 'Maintenance resolved. Unit set to vacant.');

        case 'unassign_tenant':
            $tenantId = $body['tenant_id'] ?? '';
            if (!$tenantId) sendError('tenant_id required');
            $db->prepare("UPDATE tenants SET property_id=NULL, unit_id=NULL WHERE id=?")->execute([$tenantId]);
            $db->prepare("UPDATE units SET status='vacant' WHERE id=?")->execute([$unitId]);
            $db->prepare("UPDATE properties SET occupied_units=GREATEST(0,occupied_units-1) WHERE id=?")->execute([$unit['property_id']]);
            auditLog('unassign_tenant', 'units', "Unassigned tenant $tenantId from unit $unitId", $db);
            sendSuccess([], 'Tenant unassigned. Unit is now vacant.');

        default:
            sendError("Unknown action: $action");
    }
}
