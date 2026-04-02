<?php
// POST /api/auth/logout.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';
handleOptions();
if (isLoggedIn()) {
    auditLog('logout', 'auth', 'User logged out');
    session_destroy();
}
sendSuccess([], 'Logged out');
