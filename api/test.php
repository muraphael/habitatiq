<?php
// ============================================================
// HabitatIQ — Backend Connection Test
// Visit: http://localhost/habitatiq/api/test.php
// Should return JSON with green ticks for everything
// ============================================================

require_once __DIR__ . '/config/db.php';

$results = [];

// 1. PHP version
$results['php_version'] = [
    'ok'    => version_compare(PHP_VERSION, '7.4.0', '>='),
    'value' => PHP_VERSION,
    'need'  => '>= 7.4'
];

// 2. Database connection
try {
    $db = getDB();
    $results['database'] = ['ok' => true, 'value' => 'Connected to ' . DB_NAME];
} catch (Exception $e) {
    $results['database'] = ['ok' => false, 'value' => $e->getMessage()];
}

// 3. Tables exist
if ($results['database']['ok']) {
    $tables_needed = [
        'users','landlords','landlord_applications','properties','units',
        'tenants','tenant_applications','kyc_documents','kyc_verifications',
        'payments','notices','maintenance_requests','audit_logs','clearance_registry'
    ];
    $existing = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $missing  = array_diff($tables_needed, $existing);
    $results['tables'] = [
        'ok'      => empty($missing),
        'value'   => empty($missing)
            ? 'All ' . count($tables_needed) . ' tables present'
            : 'MISSING: ' . implode(', ', $missing),
    ];

    // 4. Seed data check
    $adminCount   = $db->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
    $tenantCount  = $db->query("SELECT COUNT(*) FROM tenants")->fetchColumn();
    $propCount    = $db->query("SELECT COUNT(*) FROM properties")->fetchColumn();
    $results['seed_data'] = [
        'ok'    => $adminCount > 0 && $tenantCount > 0,
        'value' => "$adminCount admin(s), $tenantCount tenant(s), $propCount propert(ies)"
    ];
}

// 5. Session working
$_SESSION['test_habitatiq'] = true;
$results['session'] = [
    'ok'    => isset($_SESSION['test_habitatiq']),
    'value' => 'Session ID: ' . session_id()
];

// 6. Uploads directory
$uploadsOk = is_dir(UPLOAD_DIR) || mkdir(UPLOAD_DIR, 0755, true);
$kycOk     = is_dir(KYC_DIR)    || mkdir(KYC_DIR,    0755, true);
$results['uploads_dir'] = [
    'ok'    => $uploadsOk && $kycOk,
    'value' => $uploadsOk && $kycOk
        ? 'uploads/ and uploads/kyc/ exist'
        : 'Could not create upload directories'
];

// 7. PHP extensions
$exts = ['pdo', 'pdo_mysql', 'json', 'fileinfo', 'gd'];
$missing_ext = array_filter($exts, fn($e) => !extension_loaded($e));
$results['extensions'] = [
    'ok'    => empty($missing_ext),
    'value' => empty($missing_ext)
        ? 'All required: ' . implode(', ', $exts)
        : 'MISSING: ' . implode(', ', $missing_ext)
];

// Summary
$allOk = array_reduce($results, fn($carry, $r) => $carry && $r['ok'], true);

sendSuccess([
    'status'  => $allOk ? '✅ All systems go' : '⚠️ Some issues found',
    'checks'  => $results,
    'next'    => $allOk
        ? 'Backend is ready. Open http://localhost/habitatiq/ and log in.'
        : 'Fix the failing checks above, then reload this page.',
    'demo_credentials' => [
        'admin'    => ['email' => 'admin@habitatiq.co.ke',    'password' => 'admin123'],
        'landlord' => ['email' => 'wanjiku@holdings.co.ke',   'password' => 'demo123'],
        'tenant'   => ['email' => 'alice@gmail.com',          'password' => 'tenant123'],
    ]
], $allOk ? 'HabitatIQ backend is healthy' : 'Some checks failed — see details');
