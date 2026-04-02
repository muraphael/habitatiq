<?php
// ============================================================
// /api/reports/index.php — Automated Report Generation
// Action-based report exporting (CSV/HTML)
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';

handleOptions();
$user = requireAuth();
$db   = getDB();
$role = $user['role'];

$action = $_GET['action'] ?? '';

if ($role === 'tenant' && $action !== 'my_statement') {
    sendError('Unauthorized', 403);
}

// ── Scoping ───────────────────────────────────────────────
$landlordId = null;
if ($role === 'landlord') {
    $stmt = $db->prepare("SELECT id FROM landlords WHERE user_id=? LIMIT 1");
    $stmt->execute([$user['id']]);
    $landlord = $stmt->fetch();
    $landlordId = $landlord['id'] ?? null;
}

switch ($action) {
    case 'tenant_report':
        generateTenantReport($db, $landlordId);
        break;
    case 'financial_summary':
        generateFinancialSummary($db, $landlordId);
        break;
    case 'kyc_audit':
        generateKYCAudit($db, $landlordId);
        break;
    default:
        sendError('Invalid or missing action', 400);
}

function generateTenantReport($db, $landlordId) {
    $sql = "SELECT t.id, t.name, t.national_id, t.phone, t.email, p.name as property, u.label as unit, t.status, t.kyc_status, t.arrears 
            FROM tenants t
            LEFT JOIN properties p ON t.property_id = p.id
            LEFT JOIN units u ON t.unit_id = u.id";
    $params = [];
    if ($landlordId) {
        $sql .= " WHERE p.landlord_id = ?";
        $params[] = $landlordId;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    exportCSV("tenant_report_" . date('Ymd'), $data);
}

function generateFinancialSummary($db, $landlordId) {
    $sql = "SELECT pay.id, t.name as tenant, p.name as property, pay.amount, pay.type, pay.method, pay.status, pay.paid_at 
            FROM payments pay
            JOIN tenants t ON pay.tenant_id = t.id
            JOIN properties p ON t.property_id = p.id";
    $params = [];
    if ($landlordId) {
        $sql .= " WHERE p.landlord_id = ?";
        $params[] = $landlordId;
    }
    $sql .= " ORDER BY pay.paid_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    exportCSV("financial_summary_" . date('Ymd'), $data);
}

function generateKYCAudit($db, $landlordId) {
    $sql = "SELECT t.id, t.name, t.national_id, t.kyc_status, t.kyc_submitted_at, t.kyc_reviewed_at, t.kyc_reviewed_by 
            FROM tenants t
            LEFT JOIN properties p ON t.property_id = p.id";
    $params = [];
    if ($landlordId) {
        $sql .= " WHERE p.landlord_id = ?";
        $params[] = $landlordId;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    exportCSV("kyc_audit_" . date('Ymd'), $data);
}

function exportCSV($filename, $data) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename . '.csv');
    
    $output = fopen('php://output', 'w');
    if (!empty($data)) {
        fputcsv($output, array_keys($data[0])); // headers
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
    }
    fclose($output);
    exit;
}
