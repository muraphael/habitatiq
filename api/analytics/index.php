<?php
// ============================================================
// /api/analytics/index.php — Aggregated System Insights
// GET  → get metrics (scoped by role)
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';

handleOptions();
$user = requireAuth();
$db   = getDB();
$role = $user['role'];

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed', 405);
}

// ── Scoping Logic ──────────────────────────────────────────
$landlordId = null;
if ($role === 'landlord') {
    $stmt = $db->prepare("SELECT id FROM landlords WHERE user_id=? LIMIT 1");
    $stmt->execute([$user['id']]);
    $landlord = $stmt->fetch();
    if (!$landlord) sendError('Landlord profile not found', 404);
    $landlordId = $landlord['id'];
}

// ── 1. Occupancy Stats ─────────────────────────────────────
$propFilter = $landlordId ? "WHERE landlord_id = ?" : "";
$propParams = $landlordId ? [$landlordId] : [];

$stmt = $db->prepare("SELECT SUM(total_units) as total, SUM(occupied_units) as occupied FROM properties $propFilter");
$stmt->execute($propParams);
$occ = $stmt->fetch();
$totalUnits = (int)($occ['total'] ?? 0);
$occUnits   = (int)($occ['occupied'] ?? 0);
$occRate    = $totalUnits > 0 ? round(($occUnits / $totalUnits) * 100) : 0;

// ── 2. Rent Collection (Current Month) ──────────────────────
$monthStart = date('Y-m-01 00:00:00');
$monthEnd   = date('Y-m-t 23:59:59');

// Expected revenue (SUM of monthly_rent for active tenants in scoped properties)
$tenantFilter = $landlordId ? "JOIN properties p ON t.property_id = p.id WHERE p.landlord_id = ?" : "";
$tenantParams = $landlordId ? [$landlordId] : [];

$stmt = $db->prepare("SELECT SUM(t.monthly_rent) FROM tenants t $tenantFilter");
$stmt->execute($tenantParams);
$expected = (float)($stmt->fetchColumn() ?: 0);

// Collected revenue (confirmed payments within this month)
if ($landlordId) {
    $stmt = $db->prepare("
        SELECT SUM(pay.amount) 
        FROM payments pay
        JOIN tenants t ON pay.tenant_id = t.id
        JOIN properties p ON t.property_id = p.id
        WHERE p.landlord_id = ? AND pay.status = 'confirmed' AND pay.paid_at BETWEEN ? AND ?
    ");
    $stmt->execute([$landlordId, $monthStart, $monthEnd]);
} else {
    $stmt = $db->prepare("SELECT SUM(amount) FROM payments WHERE status = 'confirmed' AND paid_at BETWEEN ? AND ?");
    $stmt->execute([$monthStart, $monthEnd]);
}
$collected = (float)($stmt->fetchColumn() ?: 0);
$collRate  = $expected > 0 ? round(($collected / $expected) * 100) : 0;

// ── 3. KYC Compliance ──────────────────────────────────────
$stmt = $db->prepare("SELECT COUNT(*) FROM tenants t $tenantFilter");
$stmt->execute($tenantParams);
$totalTenants = (int)($stmt->fetchColumn() ?: 0);

$kycFilter = $landlordId 
    ? "JOIN properties p ON t.property_id = p.id WHERE p.landlord_id = ? AND t.kyc_status = 'verified'" 
    : "WHERE kyc_status = 'verified'";

$stmt = $db->prepare("SELECT COUNT(*) FROM tenants t $kycFilter");
$stmt->execute($tenantParams);
$verifiedTenants = (int)($stmt->fetchColumn() ?: 0);
$kycRate = $totalTenants > 0 ? round(($verifiedTenants / $totalTenants) * 100) : 0;

// ── 4. Arrears Ratio ───────────────────────────────────────
$arrFilter = $landlordId 
    ? "JOIN properties p ON t.property_id = p.id WHERE p.landlord_id = ? AND t.arrears > 0" 
    : "WHERE arrears > 0";
$stmt = $db->prepare("SELECT COUNT(*), SUM(arrears) FROM tenants t $arrFilter");
$stmt->execute($tenantParams);
$arrData = $stmt->fetch();
$arrCount = (int)($arrData[0] ?? 0);
$totalArrears = (float)($arrData[1] ?? 0);

// ── 5. Clearance Rate ──────────────────────────────────────
$clFilter = $landlordId 
    ? "JOIN properties p ON t.property_id = p.id WHERE p.landlord_id = ? AND t.status = 'cleared'" 
    : "WHERE status = 'cleared'";
$stmt = $db->prepare("SELECT COUNT(*) FROM tenants t $clFilter");
$stmt->execute($tenantParams);
$clearedCount = (int)($stmt->fetchColumn() ?: 0);

// ── Response Construction ──────────────────────────────────
$data = [
    'summary' => [
        'occupancy' => [
            'label' => 'Occupancy Rate',
            'value' => $occRate . '%',
            'sub'   => "$occUnits of $totalUnits units active",
            'trend' => $occRate >= 80 ? 'green' : 'warn',
            'raw'   => $occRate
        ],
        'collection' => [
            'label' => 'Rent Collection',
            'value' => $collRate . '%',
            'sub'   => "KES " . number_format($collected) . " of " . number_format($expected),
            'trend' => $collRate >= 90 ? 'green' : ($collRate >= 70 ? 'blue' : 'red'),
            'raw'   => $collRate
        ],
        'kyc' => [
            'label' => 'KYC Compliance',
            'value' => $kycRate . '%',
            'sub'   => "$verifiedTenants of $totalTenants tenants verified",
            'trend' => $kycRate >= 90 ? 'green' : 'blue',
            'raw'   => $kycRate
        ],
        'arrears' => [
            'label' => 'Arrears Ratio',
            'value' => $arrCount > 0 ? round(($arrCount / max(1, $totalTenants)) * 100) : 0 . '%',
            'sub'   => "$arrCount tenants with KES " . number_format($totalArrears),
            'trend' => $arrCount === 0 ? 'green' : ($arrCount < 3 ? 'warn' : 'red'),
            'raw'   => $totalArrears
        ],
        'clearance' => [
            'label' => 'Clearance Rate',
            'value' => $totalTenants > 0 ? round(($clearedCount / $totalTenants) * 100) : 0 . '%',
            'sub'   => "$clearedCount tenants cleared to move",
            'trend' => 'blue',
            'raw'   => $clearedCount
        ]
    ],
    'timestamp' => date('Y-m-d H:i:s'),
    'period'    => date('F Y')
];

sendSuccess($data);
