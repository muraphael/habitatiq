<?php
// ============================================================
// POST /api/auth/register_tenant.php
// Public — no auth required
// Body: { name, national_id, email, password, phone, gender,
//         dob, property_id, unit_id, movein_date, lease_months,
//         message, emergency_contact }
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/validate.php';
require_once __DIR__ . '/../helpers/auth.php';

handleOptions();
requireMethod('POST');

$body = getBody();

// Validate required fields
$err = required($body, ['name','national_id','email','password','phone','property_id','unit_id']);
if ($err) sendError($err);

$name       = sanitize($body['name']);
$nid        = sanitize($body['national_id']);
$email      = strtolower(trim($body['email']));
$password   = $body['password'];
$phone      = sanitize($body['phone']);
$gender     = sanitize($body['gender'] ?? '');
$dob        = $body['dob'] ?? null;
$propId     = sanitize($body['property_id']);
$unitId     = sanitize($body['unit_id']);
$movein     = $body['movein_date'] ?? null;
$leaseMonths = intval($body['lease_months'] ?? 12);
$message    = sanitize($body['message'] ?? '');
$emergency  = sanitize($body['emergency_contact'] ?? '');

// Validations
if (!validateNID($nid))    sendError('Invalid National ID format. Must be 7–8 digits.');
if (!validateEmail($email)) sendError('Invalid email address.');
if (!validatePhone($phone)) sendError('Invalid phone number. Use format 07xxxxxxxx or 01xxxxxxxx.');
if (strlen($password) < 6) sendError('Password must be at least 6 characters.');

$db = getDB();

// LOCKOUT CHECK — check NID in tenants table
$lockCheck = $db->prepare("SELECT id, name, status, arrears, kyc_status FROM tenants WHERE national_id=? LIMIT 1");
$lockCheck->execute([$nid]);
$existing = $lockCheck->fetch();

if ($existing) {
    if ($existing['status'] === 'flagged') {
        sendError("Registration blocked. {$existing['name']} has KES " . number_format($existing['arrears'], 0) . " in unpaid arrears. Clear arrears before re-registering.", 403);
    }
    if ($existing['kyc_status'] === 'rejected') {
        sendError('KYC previously rejected. Admin review required before re-registration.', 403);
    }
}

// Check email not taken
$emailCheck = $db->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
$emailCheck->execute([$email]);
if ($emailCheck->fetch()) sendError('This email address is already registered.');

// Check unit is vacant
$unitCheck = $db->prepare("SELECT id, status, rent, property_id FROM units WHERE id=? LIMIT 1");
$unitCheck->execute([$unitId]);
$unit = $unitCheck->fetch();
if (!$unit) sendError('Selected unit not found.');
if (!in_array($unit['status'], ['vacant'])) sendError('This unit is no longer available. Please select another.');
if ($unit['property_id'] !== $propId) sendError('Unit does not belong to the selected property.');

// Get property info for caretaker, region, landlord
$propCheck = $db->prepare("SELECT p.*, l.id AS landlord_id FROM properties p LEFT JOIN landlords l ON p.landlord_id=l.id WHERE p.id=? LIMIT 1");
$propCheck->execute([$propId]);
$prop = $propCheck->fetch();
if (!$prop) sendError('Property not found.');

// Determine KYC path — returning verified tenant gets lighter KYC
$kycStatus = 'pending';
$kycPath   = 'new_full_kyc';
if ($existing && $existing['kyc_status'] === 'verified') {
    $kycStatus = 'verified';
    $kycPath   = 'returning_verified';
}

$db->beginTransaction();
try {
    $userId   = genId('T');
    $tenantId = $userId;

    // Create user account
    $db->prepare("INSERT INTO users (id,name,email,password,role,status) VALUES (?,?,?,?,'tenant','active')")
       ->execute([$userId, $name, $email, $password]);

    // Create tenant profile
    $db->prepare("
        INSERT INTO tenants (id,user_id,name,national_id,phone,email,gender,dob,
            property_id,unit_id,status,kyc_status,monthly_rent,movein_date,lease_months,
            emergency_contact,caretaker,message,prev_tenant_id)
        VALUES (?,?,?,?,?,?,?,?,?,?,'pending_approval',?,?,?,?,?,?,?,?)
    ")->execute([
        $tenantId, $userId, $name, $nid, $phone, $email, $gender, $dob ?: null,
        $propId, $unitId, $kycStatus, $unit['rent'],
        $movein ?: null, $leaseMonths, $emergency, $prop['caretaker'],
        $message, $existing['id'] ?? null
    ]);

    // Create pending approval for landlord
    $approvalId = genId('PA');
    $db->prepare("
        INSERT INTO tenant_applications (id,tenant_id,property_id,unit_id,landlord_id,kyc_path,message)
        VALUES (?,?,?,?,?,?,?)
    ")->execute([$approvalId, $tenantId, $propId, $unitId, $prop['landlord_id'], $kycPath, $message]);

    // Mark unit as pending
    $db->prepare("UPDATE units SET status='pending' WHERE id=?")->execute([$unitId]);

    auditLog('register_tenant', 'tenants',
        "Self-registered: $name ($tenantId) → {$prop['name']} $unit[label] | KYC: $kycStatus", $db);

    $db->commit();

    sendSuccess([
        'tenant_id'  => $tenantId,
        'kyc_status' => $kycStatus,
        'kyc_path'   => $kycPath,
        'property'   => $prop['name'],
        'unit'       => $unit['label'],
    ], 'Registration submitted. Awaiting landlord approval.');

} catch (Throwable $e) {
    $db->rollBack();
    sendError('Registration failed: ' . $e->getMessage(), 500);
}
