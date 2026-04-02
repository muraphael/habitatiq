<?php
// ============================================================
// HabitatIQ — Database Configuration
// Place this file at: htdocs/habitatiq/api/config/db.php
// ============================================================

// Bootstrap: CORS, sessions, error handling, timezone
require_once __DIR__ . '/bootstrap.php';

define('DB_HOST', 'localhost');
define('DB_NAME', 'habitatiq');
define('DB_USER', 'root');
define('DB_PASS', '');          // XAMPP default — change in production
define('DB_CHARSET', 'utf8mb4');

define('UPLOAD_DIR', __DIR__ . '/../../uploads/');
define('KYC_DIR',    UPLOAD_DIR . 'kyc/');
define('MAX_FILE_SIZE', 2 * 1024 * 1024); // 2MB

/**
 * Get PDO database connection (singleton)
 */
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                DB_HOST, DB_NAME, DB_CHARSET
            );
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]));
        }
    }
    return $pdo;
}

/**
 * Generate a unique ID with prefix
 * e.g. genId('T') → 'T174312345671'
 */
function genId(string $prefix): string {
    return $prefix . time() . rand(1, 9);
}
