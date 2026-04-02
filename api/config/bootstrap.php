<?php
// ============================================================
// HabitatIQ — Global Bootstrap
// Included automatically via config/db.php
// Handles: CORS, sessions, PHP error formatting, timezone
// ============================================================

// ── Timezone ──────────────────────────────────────────────
date_default_timezone_set('Africa/Nairobi');

// ── Session config (must be before session_start) ─────────
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.gc_maxlifetime', 86400); // 24 hours
    session_start();
}

// ── CORS headers ─────────────────────────────────────────
// Allow requests from the same origin (localhost)
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed_origins = [
    'http://localhost',
    'http://localhost:80',
    'http://127.0.0.1',
    'http://127.0.0.1:80',
];

if (in_array($origin, $allowed_origins) || empty($origin)) {
    header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
} else {
    header('Access-Control-Allow-Origin: http://localhost');
}

header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization');
header('Content-Type: application/json; charset=utf-8');

// ── Handle OPTIONS preflight immediately ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ── Convert PHP errors to JSON responses ─────────────────
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    $file = basename($errfile);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => "PHP Error [$errno]: $errstr in $file line $errline"
    ]);
    exit;
});

set_exception_handler(function(Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
        'file'    => basename($e->getFile()),
        'line'    => $e->getLine()
    ]);
    exit;
});
