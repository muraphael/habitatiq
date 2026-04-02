<?php
// ============================================================
// /api/approvals/index.php — Tenant application queue
// GET  → list pending applications (landlord or admin)
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';

handleOptions();
$user = requireRole(['admin','landlord']);
$db   = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($user['role'] === 'admin') {
        $stmt = $db->query("
            SELECT ta.*, t.name AS tenant_name, t.national_id, t.phone, t.email,
                   t.kyc_status, t.status AS tenant_status,
                   p.name AS property_name, u.label AS unit_label,
                   l.company_name AS landlord_name
            FROM tenant_applications ta
            JOIN tenants t     ON ta.tenant_id   = t.id
            JOIN properties p  ON ta.property_id = p.id
            JOIN units u       ON ta.unit_id     = u.id
            LEFT JOIN landlords l ON ta.landlord_id = l.id
            ORDER BY ta.submitted_at DESC
        ");
    } else {
        // Landlord sees only their properties
        $stmt = $db->prepare("
            SELECT ta.*, t.name AS tenant_name, t.national_id, t.phone, t.email,
                   t.kyc_status, t.status AS tenant_status,
                   p.name AS property_name, u.label AS unit_label
            FROM tenant_applications ta
            JOIN tenants t    ON ta.tenant_id   = t.id
            JOIN properties p ON ta.property_id = p.id
            JOIN units u      ON ta.unit_id     = u.id
            JOIN landlords l  ON p.landlord_id  = l.id
            WHERE l.user_id = ?
            ORDER BY ta.submitted_at DESC
        ");
        $stmt->execute([$user['id']]);
    }
    sendSuccess($stmt->fetchAll());
}
