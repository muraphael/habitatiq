<?php
// ============================================================
// /api/properties/index.php — Properties CRUD
// GET  → list (scoped by role)
// POST → create new property (admin or full landlord)
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/validate.php';

handleOptions();
$user = requireAuth();
$db   = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $baseSQL = "
        SELECT p.*, l.company_name AS landlord_name,
               (SELECT COUNT(*) FROM units WHERE property_id=p.id AND status='vacant') AS vacant_units,
               (SELECT COUNT(*) FROM units WHERE property_id=p.id AND status='maintenance') AS maintenance_units
        FROM properties p
        LEFT JOIN landlords l ON p.landlord_id = l.id";

    if ($user['role'] === 'admin') {
        $stmt = $db->query($baseSQL . " ORDER BY p.name");
    } elseif ($user['role'] === 'landlord') {
        $stmt = $db->prepare($baseSQL . " WHERE l.user_id=? ORDER BY p.name");
        $stmt->execute([$user['id']]);
    } else {
        // Tenant sees only their property
        $tStmt = $db->prepare("SELECT property_id FROM tenants WHERE user_id=? LIMIT 1");
        $tStmt->execute([$user['id']]);
        $t = $tStmt->fetch();
        $stmt = $db->prepare($baseSQL . " WHERE p.id=? LIMIT 1");
        $stmt->execute([$t['property_id'] ?? '']);
    }

    $props = $stmt->fetchAll();
    // Attach units
    foreach ($props as &$p) {
        $uStmt = $db->prepare("SELECT * FROM units WHERE property_id=? ORDER BY label");
        $uStmt->execute([$p['id']]);
        $units = $uStmt->fetchAll();

        // Attach tenant name to occupied units
        foreach ($units as &$u) {
            if ($u['status'] === 'occupied') {
                $tStmt = $db->prepare("SELECT id, name, kyc_status, status AS tenant_status FROM tenants WHERE unit_id=? LIMIT 1");
                $tStmt->execute([$u['id']]);
                $u['tenant'] = $tStmt->fetch() ?: null;
            } else {
                $u['tenant'] = null;
            }
        }
        unset($u);
        $p['units_list'] = $units;
    }
    unset($p);

    sendSuccess($props);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireFullLandlord();
    $body = getBody();
    $err  = required($body, ['name','location','total_units','rent_per_unit']);
    if ($err) sendError($err);

    $name    = sanitize($body['name']);
    $loc     = sanitize($body['location']);
    $units   = max(1, intval($body['total_units']));
    $rent    = floatval($body['rent_per_unit']);
    $style   = in_array($body['unit_naming'] ?? '', ['alpha','numeric','floor']) ? $body['unit_naming'] : 'alpha';
    $caretaker = sanitize($body['caretaker'] ?? '');
    $type    = sanitize($body['property_type'] ?? 'Apartment');

    // Determine landlord ID
    if ($user['role'] === 'admin' && !empty($body['landlord_id'])) {
        $landlordId = $body['landlord_id'];
    } else {
        $llStmt = $db->prepare("SELECT id FROM landlords WHERE user_id=? LIMIT 1");
        $llStmt->execute([$user['id']]);
        $ll = $llStmt->fetch();
        if (!$ll) sendError('Landlord profile not found');
        $landlordId = $ll['id'];
    }

    $propId = genId('P');
    $db->beginTransaction();
    try {
        $db->prepare("
            INSERT INTO properties (id,landlord_id,name,location,property_type,total_units,occupied_units,rent_per_unit,caretaker,unit_naming)
            VALUES (?,?,?,?,?,?,0,?,?,?)
        ")->execute([$propId, $landlordId, $name, $loc, $type, $units, $rent, $caretaker, $style]);

        // Generate unit records
        $unitLabels = generateUnitLabelsServer($units, $style);
        $unitStmt = $db->prepare("INSERT INTO units (id,property_id,label,status,rent) VALUES (?,?,?,'vacant',?)");
        foreach ($unitLabels as $label) {
            $unitId = $propId . '-' . preg_replace('/\s+/','',$label);
            $unitStmt->execute([$unitId, $propId, $label, $rent]);
        }

        auditLog('register_property', 'properties',
            "Registered: $name ($propId) — $units units @ KES " . number_format($rent), $db);
        $db->commit();

        sendSuccess([
            'property_id' => $propId,
            'name'        => $name,
            'units'       => $units,
            'unit_labels' => array_slice($unitLabels, 0, 5),
        ], 'Property registered successfully');
    } catch (Throwable $e) {
        $db->rollBack();
        sendError('Failed to register property: ' . $e->getMessage(), 500);
    }
}

function generateUnitLabelsServer(int $n, string $style): array {
    $labels = [];
    for ($i = 0; $i < $n; $i++) {
        if ($style === 'alpha') {
            $letters = '';
            $idx = $i;
            do {
                $letters = chr(65 + ($idx % 26)) . $letters;
                $idx = intdiv($idx, 26) - 1;
            } while ($idx >= 0);
            $labels[] = 'Unit ' . $letters;
        } elseif ($style === 'numeric') {
            $labels[] = 'Unit ' . ($i + 1);
        } else { // floor
            $floor = intdiv($i, 4) + 1;
            $pos   = chr(65 + ($i % 4));
            $labels[] = $floor . $pos;
        }
    }
    return $labels;
}
