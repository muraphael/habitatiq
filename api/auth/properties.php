<?php
// ============================================================
// /api/auth/properties.php — Public Property List
// Used by tenant registration screen (no login required)
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/validate.php';

handleOptions();
requireMethod('GET');

$db = getDB();

$stmt = $db->query("
    SELECT id, name, location, caretaker, rent_per_unit, property_type
    FROM properties
    ORDER BY name
");

$props = $stmt->fetchAll();

foreach ($props as &$p) {
    // Only return VACANT units for public registration
    $uStmt = $db->prepare("SELECT id, label, status, rent FROM units WHERE property_id=? AND status='vacant' ORDER BY label");
    $uStmt->execute([$p['id']]);
    $p['units_list'] = $uStmt->fetchAll();
}
unset($p);

sendSuccess($props);
