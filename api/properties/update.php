<?php
// ============================================================
// POST /api/properties/update.php — Update property details
// Body: { property_id, caretaker, rent_per_unit, name, location }
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';

handleOptions();
requireMethod('POST');
$user = requireFullLandlord();
$db   = getDB();
$body = getBody();

$propId    = $body['property_id'] ?? '';
$caretaker = trim($body['caretaker']    ?? '');
$rent      = floatval($body['rent_per_unit'] ?? 0);
$name      = trim($body['name']     ?? '');
$location  = trim($body['location'] ?? '');

if (!$propId) sendError('property_id required');

// Verify ownership
if ($user['role'] === 'landlord') {
    $check = $db->prepare("
        SELECT 1 FROM properties p JOIN landlords l ON p.landlord_id=l.id
        WHERE p.id=? AND l.user_id=? LIMIT 1
    ");
    $check->execute([$propId, $user['id']]);
    if (!$check->fetch()) sendError('Forbidden — you do not own this property', 403);
}

$sets = []; $params = [];
if ($caretaker) { $sets[]='caretaker=?';    $params[]=$caretaker; }
if ($rent>0)    { $sets[]='rent_per_unit=?';$params[]=$rent; }
if ($name)      { $sets[]='name=?';         $params[]=$name; }
if ($location)  { $sets[]='location=?';     $params[]=$location; }

if (empty($sets)) sendError('Nothing to update');

$params[] = $propId;
$db->prepare("UPDATE properties SET ".implode(',',$sets)." WHERE id=?")->execute($params);

// Update unit rents if rent changed
if ($rent > 0) {
    $db->prepare("UPDATE units SET rent=? WHERE property_id=?")->execute([$rent, $propId]);
}

auditLog('update_property','properties',"Updated property $propId: ".implode(', ',$sets), $db);
sendSuccess([], 'Property updated successfully');
