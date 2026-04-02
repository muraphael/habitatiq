<?php
// ============================================================
// POST /api/auth/login.php
// Body: { email, password, role }
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';

handleOptions();
requireMethod('POST');

$body     = getBody();
$email    = strtolower(trim($body['email']    ?? ''));
$password = trim($body['password'] ?? '');
$role     = trim($body['role']     ?? '');

if (!$email || !$password || !$role) {
    sendError('Email, password and role are required');
}
if (!in_array($role, ['admin', 'landlord', 'tenant'])) {
    sendError('Invalid role');
}

// Rate limiting check (simple session-based)
if (!isset($_SESSION['login_attempts'])) $_SESSION['login_attempts'] = 0;
if ($_SESSION['login_attempts'] >= 5) {
    sendError('Too many failed attempts. Please wait and try again.', 429);
}

$db = getDB();

// Lookup user
$stmt = $db->prepare("SELECT * FROM users WHERE email=? AND role=? LIMIT 1");
$stmt->execute([$email, $role]);
$user = $stmt->fetch();

if (!$user || $user['password'] !== $password) {
    $_SESSION['login_attempts']++;
    sendError('Invalid email or password for the selected role', 401);
}

// Check status
if ($user['status'] === 'suspended') {
    sendError('Your account has been suspended. Contact the administrator.', 403);
}
if ($user['status'] === 'rejected') {
    sendError('Your registration was not approved. Contact support.', 403);
}

// For landlords, check if pending and set read_only flag
$readOnly = false;
$landlordData = null;
if ($role === 'landlord') {
    $llStmt = $db->prepare("SELECT * FROM landlords WHERE user_id=? LIMIT 1");
    $llStmt->execute([$user['id']]);
    $landlordData = $llStmt->fetch();
    $readOnly = ($user['status'] === 'pending_approval');
}

// For tenants, get their tenant profile
$tenantData = null;
if ($role === 'tenant') {
    $tStmt = $db->prepare("
        SELECT t.*, p.name AS property_name, u.label AS unit_label, l.id AS landlord_id
        FROM tenants t
        LEFT JOIN properties p ON t.property_id = p.id
        LEFT JOIN units u      ON t.unit_id = u.id
        LEFT JOIN landlords l  ON p.landlord_id = l.id
        WHERE t.user_id=? LIMIT 1
    ");
    $tStmt->execute([$user['id']]);
    $tenantData = $tStmt->fetch();
}

// Establish session
session_regenerate_id(true);
$_SESSION['user_id']        = $user['id'];
$_SESSION['role']           = $user['role'];
$_SESSION['name']           = $user['name'];
$_SESSION['email']          = $user['email'];
$_SESSION['read_only']      = $readOnly;
$_SESSION['login_attempts'] = 0;

auditLog('login', 'auth', "Logged in as {$role}" . ($readOnly ? ' [read-only]' : ''), $db);

sendSuccess([
    'user' => [
        'id'        => $user['id'],
        'name'      => $user['name'],
        'email'     => $user['email'],
        'role'      => $user['role'],
        'status'    => $user['status'],
        'read_only' => $readOnly,
    ],
    'landlord' => $landlordData,
    'tenant'   => $tenantData,
], 'Login successful');
