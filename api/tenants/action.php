<?php
// ============================================================
// POST /api/tenants/action.php
// Body: { action, tenant_id, [reason], [cert_no] }
// Actions: approve_application, reject_application, flag,
//          lift_flag, issue_clearance, approve_kyc, reject_kyc,
//          unlock_kyc
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';

handleOptions();
requireMethod('POST');

$user   = requireAuth();
$db     = getDB();
$body   = getBody();
$action = $body['action']    ?? '';
$tid    = $body['tenant_id'] ?? '';
$reason = $body['reason']    ?? '';

if (!$tid) sendError('tenant_id is required');

// Load tenant
$tStmt = $db->prepare("SELECT * FROM tenants WHERE id=? LIMIT 1");
$tStmt->execute([$tid]);
$tenant = $tStmt->fetch();
if (!$tenant) sendError('Tenant not found', 404);

switch ($action) {

    // ── Approve tenant application (landlord or admin) ──
    case 'approve_application':
        requireRole(['admin', 'landlord']);
        // Find pending application
        $appStmt = $db->prepare("SELECT * FROM tenant_applications WHERE tenant_id=? AND status='pending' LIMIT 1");
        $appStmt->execute([$tid]);
        $app = $appStmt->fetch();
        if (!$app) sendError('No pending application found for this tenant');

        $db->prepare("UPDATE tenant_applications SET status='approved', decided_at=NOW() WHERE id=?")->execute([$app['id']]);
        $db->prepare("UPDATE tenants SET status='active' WHERE id=?")->execute([$tid]);
        $db->prepare("UPDATE units SET status='occupied' WHERE id=?")->execute([$app['unit_id']]);
        $db->prepare("UPDATE properties SET occupied_units=occupied_units+1 WHERE id=?")->execute([$app['property_id']]);
        auditLog('approve_application', 'tenants', "Approved {$tenant['name']} for property {$app['property_id']}", $db);
        sendSuccess([], 'Application approved. Tenant is now active.');
        break;

    // ── Reject tenant application ──
    case 'reject_application':
        requireRole(['admin', 'landlord']);
        $appStmt = $db->prepare("SELECT * FROM tenant_applications WHERE tenant_id=? AND status='pending' LIMIT 1");
        $appStmt->execute([$tid]);
        $app = $appStmt->fetch();
        if (!$app) sendError('No pending application found');

        $db->prepare("UPDATE tenant_applications SET status='rejected', reject_reason=?, decided_at=NOW() WHERE id=?")->execute([$reason, $app['id']]);
        $db->prepare("UPDATE tenants SET status='flagged' WHERE id=?")->execute([$tid]);
        $db->prepare("UPDATE units SET status='vacant' WHERE id=?")->execute([$app['unit_id']]);
        auditLog('reject_application', 'tenants', "Rejected {$tenant['name']}: $reason", $db);
        sendSuccess([], 'Application rejected');
        break;

    // ── Flag tenant ──
    case 'flag':
        requireRole(['admin']);
        $db->prepare("UPDATE tenants SET status='flagged' WHERE id=?")->execute([$tid]);
        auditLog('flag_tenant', 'tenants', "Flagged {$tenant['name']}", $db);
        sendSuccess([], 'Tenant flagged');
        break;

    // ── Lift lockout ──
    case 'lift_flag':
        requireRole(['admin']);
        $db->prepare("UPDATE tenants SET status='active' WHERE id=?")->execute([$tid]);
        auditLog('lift_flag', 'tenants', "Lifted flag for {$tenant['name']}", $db);
        sendSuccess([], 'Flag lifted. Tenant is now active.');
        break;

    // ── Issue clearance certificate ──
    case 'issue_clearance':
        requireRole(['admin']);
        if ($tenant['arrears'] > 0) sendError("Cannot issue clearance: KES " . number_format($tenant['arrears']) . " in unpaid arrears.");
        $cert = 'CL-' . date('Y') . '-' . rand(100, 999);
        $db->prepare("UPDATE tenants SET status='cleared', clearance_cert=? WHERE id=?")->execute([$cert, $tid]);
        $db->prepare("INSERT INTO clearance_registry (tenant_id,national_id,property_id,cert_number,status,issued_by,issued_at) VALUES (?,?,?,?,'cleared',?,NOW())")
           ->execute([$tid, $tenant['national_id'], $tenant['property_id'], $cert, $user['id']]);
        auditLog('issue_clearance', 'clearance', "Issued $cert for {$tenant['name']}", $db);
        sendSuccess(['cert' => $cert], "Clearance certificate $cert issued");
        break;

    // ── Approve KYC ──
    case 'approve_kyc':
        requireRole(['admin', 'landlord']);
        // Check kyc_documents table directly — don't rely on kyc_submitted_at
        $docCheck = $db->prepare("SELECT COUNT(*) FROM kyc_documents WHERE tenant_id=?");
        $docCheck->execute([$tid]);
        if ($docCheck->fetchColumn() == 0) sendError('No KYC documents found for this tenant');
        // Auto-fix missing submitted_at if docs exist
        if (!$tenant['kyc_submitted_at']) {
            $db->prepare("UPDATE tenants SET kyc_submitted_at=CURDATE(), kyc_status='submitted' WHERE id=?")
               ->execute([$tid]);
        }
        $score = floatval($body['overall_score'] ?? 100);
        $db->prepare("UPDATE tenants SET kyc_status='verified', kyc_reviewed_at=CURDATE(), kyc_reviewed_by=? WHERE id=?")
           ->execute([$user['name'], $tid]);
        $db->prepare("INSERT INTO kyc_verifications (tenant_id,face_match_score,name_match_score,id_match_score,overall_score,face_match_result,name_match_result,id_match_result,verified_by,verified_at)
                      VALUES (?,?,?,?,?,'match','match','match',?,NOW())
                      ON DUPLICATE KEY UPDATE overall_score=VALUES(overall_score), verified_at=NOW()")
           ->execute([$tid, $score, $score, $score, $score, $user['id']]);
        if (in_array($tenant['status'], ['active', 'pending_approval'])) {
            $db->prepare("UPDATE tenants SET status='active' WHERE id=?")->execute([$tid]);
        }
        auditLog('kyc_approved', 'kyc', "KYC approved for {$tenant['name']} (score: {$score}%)", $db);
        sendSuccess([], 'KYC verified. Full portal access granted.');
        break;

    // ── Reject KYC ──
    case 'reject_kyc':
        requireRole(['admin', 'landlord']);
        if (!$reason) sendError('Rejection reason is required');
        $db->prepare("UPDATE tenants SET kyc_status='rejected', kyc_reject_reason=?, kyc_reviewed_at=CURDATE(), kyc_reviewed_by=?, status='flagged' WHERE id=?")
           ->execute([$reason, $user['name'], $tid]);
        auditLog('kyc_rejected', 'kyc', "KYC rejected for {$tenant['name']}: $reason", $db);
        sendSuccess([], 'KYC rejected. Tenant account flagged.');
        break;

    // ── Unlock KYC (reset for resubmission) ──
    case 'unlock_kyc':
        requireRole(['admin']);
        $db->prepare("UPDATE tenants SET kyc_status='pending', kyc_submitted_at=NULL, kyc_reviewed_at=NULL, kyc_reviewed_by=NULL, kyc_reject_reason=NULL, status='active' WHERE id=?")
           ->execute([$tid]);
        $db->prepare("DELETE FROM kyc_documents WHERE tenant_id=?")->execute([$tid]);
        auditLog('unlock_kyc', 'kyc', "KYC unlocked for {$tenant['name']}", $db);
        sendSuccess([], 'KYC unlocked. Tenant must resubmit documents.');
        break;

    default:
        sendError("Unknown action: $action");
}
