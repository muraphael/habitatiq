<?php
// ============================================================
// GET /api/auth/session.php
// Returns current session user if logged in, 401 if not
// Used by frontend init() to auto-restore sessions on page reload
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';

handleOptions();

if (!isLoggedIn()) {
    sendError('No active session', 401);
}

$db   = getDB();
$user = getCurrentUser();

$landlordData = null;
$tenantData   = null;

if ($user['role'] === 'landlord') {
    $stmt = $db->prepare("SELECT * FROM landlords WHERE user_id=? LIMIT 1");
    $stmt->execute([$user['id']]);
    $landlordData = $stmt->fetch();
}

if ($user['role'] === 'tenant') {
    $stmt = $db->prepare("
        SELECT t.*, p.name AS property_name, u.label AS unit_label, l.id AS landlord_id
        FROM tenants t
        LEFT JOIN properties p ON t.property_id = p.id
        LEFT JOIN units u      ON t.unit_id = u.id
        LEFT JOIN landlords l  ON p.landlord_id = l.id
        WHERE t.user_id=? LIMIT 1
    ");
    $stmt->execute([$user['id']]);
    $tenantData = $stmt->fetch();
}

// Get fresh user status from DB
$uStmt = $db->prepare("SELECT status FROM users WHERE id=? LIMIT 1");
$uStmt->execute([$user['id']]);
$freshUser = $uStmt->fetch();

sendSuccess([
    'user' => [
        'id'        => $user['id'],
        'name'      => $user['name'],
        'email'     => $user['email'],
        'role'      => $user['role'],
        'status'    => $freshUser['status'] ?? 'active',
        'read_only' => $user['read_only'],
    ],
    'landlord' => $landlordData,
    'tenant'   => $tenantData,
], 'Session active');
