<?php
// ============================================================
// HabitatIQ — Auth & RBAC Helpers
// ============================================================

require_once __DIR__ . '/../helpers/response.php';

// Pages that require full landlord activation (not read-only)
const LANDLORD_WRITE_ACTIONS = [
    'register_tenant', 'approve_application', 'reject_application',
    'record_payment',  'send_notice',         'update_kyc',
    'assign_unit',     'manage_clearance'
];

function isLoggedIn(): bool {
    return isset($_SESSION['user_id'], $_SESSION['role']);
}

function getCurrentUser(): ?array {
    if (!isLoggedIn()) return null;
    return [
        'id'        => $_SESSION['user_id'],
        'role'      => $_SESSION['role'],
        'name'      => $_SESSION['name'],
        'email'     => $_SESSION['email'],
        'read_only' => $_SESSION['read_only'] ?? false,
    ];
}

function requireAuth(): array {
    if (!isLoggedIn()) sendError('Unauthenticated — please log in', 401);
    return getCurrentUser();
}

function requireRole(string|array $roles): array {
    $user  = requireAuth();
    $roles = is_array($roles) ? $roles : [$roles];
    if (!in_array($user['role'], $roles)) {
        sendError('Forbidden — insufficient role. Required: ' . implode(' or ', $roles), 403);
    }
    return $user;
}

function requireAdmin(): array {
    return requireRole('admin');
}

function requireLandlord(): array {
    $user = requireRole(['admin', 'landlord']);
    return $user;
}

function requireFullLandlord(): array {
    $user = requireRole(['admin', 'landlord']);
    if ($user['role'] === 'landlord' && !empty($user['read_only'])) {
        sendError('Your account is pending admin approval. This action requires full access.', 403);
    }
    return $user;
}

function requireTenant(): array {
    return requireRole('tenant');
}

/**
 * Log action to audit_logs table
 */
function auditLog(string $action, string $entity, string $detail, ?PDO $db = null): void {
    try {
        $user = getCurrentUser();
        if (!$db) {
            require_once __DIR__ . '/../config/db.php';
            $db = getDB();
        }
        $stmt = $db->prepare("
            INSERT INTO audit_logs (user_id, user_name, role, action, entity, detail, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $user['id'] ?? 'system',
            $user['name'] ?? 'System',
            $user['role'] ?? 'system',
            $action,
            $entity,
            $detail,
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
        ]);
    } catch (Throwable $e) {
        // Audit log failure should never break the request
        error_log('Audit log failed: ' . $e->getMessage());
    }
}

/**
 * Validate that a landlord owns a given property
 */
function landlordOwnsProperty(string $landlordId, string $propertyId, PDO $db): bool {
    $stmt = $db->prepare("SELECT 1 FROM properties WHERE id=? AND landlord_id=? LIMIT 1");
    $stmt->execute([$propertyId, $landlordId]);
    return (bool)$stmt->fetch();
}
