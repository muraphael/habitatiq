<?php
// ============================================================
// /api/tenants/index.php — RBAC-aware tenant listing
// GET  → list tenants (scoped by role)
// POST → admin/landlord registers a tenant directly
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/validate.php';

handleOptions();
$user = requireAuth();
$db   = getDB();
$role = $user['role'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Build query based on role
    $baseSQL = "SELECT t.*, p.name AS property_name, u.label AS unit_label,
                       l.id AS landlord_id, l.company_name AS landlord_name
                FROM tenants t
                LEFT JOIN properties  p ON t.property_id = p.id
                LEFT JOIN units       u ON t.unit_id = u.id
                LEFT JOIN landlords   l ON p.landlord_id = l.id";

    if ($role === 'admin') {
        // Admin sees all
        $stmt = $db->query($baseSQL . " ORDER BY t.registered_at DESC");
    } elseif ($role === 'landlord') {
        // Landlord sees only tenants in their properties
        $stmt = $db->prepare($baseSQL . "
            WHERE l.user_id = ? ORDER BY t.registered_at DESC");
        $stmt->execute([$user['id']]);
    } else {
        // Tenant sees only themselves
        $stmt = $db->prepare($baseSQL . " WHERE t.user_id = ? LIMIT 1");
        $stmt->execute([$user['id']]);
    }

    $tenants = $stmt->fetchAll();

    // Attach KYC document info
    foreach ($tenants as &$t) {
        $docStmt = $db->prepare("SELECT doc_type, file_path, file_name, uploaded_at FROM kyc_documents WHERE tenant_id=?");
        $docStmt->execute([$t['id']]);
        $docs = [];
        foreach ($docStmt->fetchAll() as $doc) {
            $docs[$doc['doc_type']] = [
                'name'     => $doc['file_name'],
                'uploaded' => substr($doc['uploaded_at'], 0, 10),
                'url'      => '/uploads/' . $doc['file_path'],
            ];
        }
        $t['kyc_docs'] = $docs;
    }
    unset($t);

    sendSuccess($tenants);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole(['admin', 'landlord']);
    $body = getBody();

    $name     = sanitize($body['name']        ?? '');
    $nid      = sanitize($body['national_id'] ?? '');
    $email    = strtolower(trim($body['email']    ?? ''));
    $password = $body['password'] ?? 'tenant123';
    $phone    = sanitize($body['phone']       ?? '');
    $propId   = sanitize($body['property_id'] ?? '');
    $unitId   = sanitize($body['unit_id']     ?? '');

    if (!$name || !$nid || !$email || !$phone || !$propId || !$unitId)
        sendError('name, national_id, email, phone, property_id and unit_id are required');
    if (!validateNID($nid))    sendError('Invalid National ID');
    if (!validateEmail($email)) sendError('Invalid email');
    if (!validatePhone($phone)) sendError('Invalid phone');

    // Lockout check
    $lc = $db->prepare("SELECT id, name, status, arrears FROM tenants WHERE national_id=? LIMIT 1");
    $lc->execute([$nid]);
    $existing = $lc->fetch();
    if ($existing && $existing['status'] === 'flagged')
        sendError("Blocked. {$existing['name']} has KES " . number_format($existing['arrears']) . " unpaid arrears.", 403);

    // Email uniqueness
    $ec = $db->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
    $ec->execute([$email]);
    if ($ec->fetch()) sendError('Email already registered');

    // Unit check
    $uc = $db->prepare("SELECT * FROM units WHERE id=? AND property_id=? LIMIT 1");
    $uc->execute([$unitId, $propId]);
    $unit = $uc->fetch();
    if (!$unit || $unit['status'] !== 'vacant') sendError('Unit not available');

    $pc = $db->prepare("SELECT * FROM properties WHERE id=? LIMIT 1");
    $pc->execute([$propId]);
    $prop = $pc->fetch();
    if (!$prop) sendError('Property not found');

    $kycStatus = ($existing && $existing['kyc_status'] === 'verified') ? 'verified' : 'pending';

    $db->beginTransaction();
    try {
        $userId = genId('T');

        $db->prepare("INSERT INTO users (id,name,email,password,role,status) VALUES (?,?,?,?,'tenant','active')")
           ->execute([$userId, $name, $email, $password]);

        $db->prepare("
            INSERT INTO tenants (id,user_id,name,national_id,phone,email,property_id,unit_id,
                status,kyc_status,monthly_rent,caretaker,region,lease_end,emergency_contact)
            VALUES (?,?,?,?,?,?,?,?,'active',?,?,?,?,?,?)
        ")->execute([
            $userId,$userId,$name,$nid,$phone,$email,$propId,$unitId,
            $kycStatus, $unit['rent'], $prop['caretaker'] ?? '',
            $body['region'] ?? 'Nairobi',
            $body['lease_end'] ?? null,
            $body['emergency'] ?? ''
        ]);

        $db->prepare("UPDATE units SET status='occupied' WHERE id=?")->execute([$unitId]);
        $db->prepare("UPDATE properties SET occupied_units=occupied_units+1 WHERE id=?")->execute([$propId]);

        // Create approval (already approved since admin/landlord is doing it)
        $appId = genId('PA');
        $db->prepare("INSERT INTO tenant_applications (id,tenant_id,property_id,unit_id,landlord_id,status,decided_at)
                      VALUES (?,?,?,?,?,'approved',NOW())")
           ->execute([$appId, $userId, $propId, $unitId, $prop['landlord_id'] ?? null]);

        auditLog('register_tenant', 'tenants', "Registered $name ($userId) at {$prop['name']} {$unit['label']}", $db);
        $db->commit();

        sendSuccess(['tenant_id' => $userId, 'kyc_status' => $kycStatus], 'Tenant registered successfully');
    } catch (Throwable $e) {
        $db->rollBack();
        sendError('Registration failed: ' . $e->getMessage(), 500);
    }
}
