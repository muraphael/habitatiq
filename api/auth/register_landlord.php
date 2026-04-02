<?php
// ============================================================
// POST /api/auth/register_landlord.php
// Public — no auth required
// Body: { name, email, password, phone, kra_pin, business_reg, address }
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/validate.php';
require_once __DIR__ . '/../helpers/auth.php';

handleOptions();
requireMethod('POST');

$body = getBody();
$err  = required($body, ['name','email','password','phone']);
if ($err) sendError($err);

$name    = sanitize($body['name']);
$email   = strtolower(trim($body['email']));
$password = $body['password'];
$phone   = sanitize($body['phone']);
$kra     = sanitize($body['kra_pin']      ?? '');
$regno   = sanitize($body['business_reg'] ?? '');
$address = sanitize($body['address']      ?? '');

if (!validateEmail($email))  sendError('Invalid email address.');
if (!validatePhone($phone))  sendError('Invalid phone number.');
if (strlen($password) < 6)  sendError('Password must be at least 6 characters.');

$db = getDB();

// Check email not taken
$check = $db->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
$check->execute([$email]);
if ($check->fetch()) sendError('This email address is already registered.');

$db->beginTransaction();
try {
    $userId     = genId('LL');
    $landlordId = $userId;

    // Create user with pending_approval status
    $db->prepare("INSERT INTO users (id,name,email,password,role,status) VALUES (?,?,?,?,'landlord','pending_approval')")
       ->execute([$userId, $name, $email, $password]);

    // Create landlord profile
    $db->prepare("INSERT INTO landlords (id,user_id,company_name,phone,kra_pin,business_reg,address,joined_at) VALUES (?,?,?,?,?,?,?,CURDATE())")
       ->execute([$landlordId, $userId, $name, $phone, $kra, $regno, $address]);

    // Create admin approval request
    $appId = genId('LA');
    $db->prepare("INSERT INTO landlord_applications (id,landlord_id,status) VALUES (?,?,'pending')")
       ->execute([$appId, $landlordId]);

    auditLog('self_register_landlord', 'landlords', "Landlord self-registered: $name ($landlordId) — pending approval", $db);

    $db->commit();

    sendSuccess([
        'landlord_id' => $landlordId,
        'email'       => $email,
        'status'      => 'pending_approval',
    ], 'Registration submitted. Awaiting administrator approval. You may log in with read-only access.');

} catch (Throwable $e) {
    $db->rollBack();
    sendError('Registration failed: ' . $e->getMessage(), 500);
}
