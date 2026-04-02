<?php
// ============================================================
// HabitatIQ — HTTP Response Helpers
// ============================================================

function sendJSON(mixed $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sendSuccess(mixed $data = [], string $message = 'OK'): void {
    sendJSON(['success' => true, 'message' => $message, 'data' => $data]);
}

function sendError(string $message, int $status = 400, array $extra = []): void {
    sendJSON(array_merge(['success' => false, 'error' => $message], $extra), $status);
}

function handleOptions(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

function getBody(): array {
    $raw = file_get_contents('php://input');
    if (empty($raw)) return $_POST; // fallback for form-encoded
    return json_decode($raw, true) ?? [];
}

function requireMethod(string $method): void {
    if ($_SERVER['REQUEST_METHOD'] !== strtoupper($method)) {
        sendError('Method not allowed. Expected: ' . $method, 405);
    }
}

function getPagination(): array {
    $page  = max(1, intval($_GET['page'] ?? 1));
    $limit = min(100, max(10, intval($_GET['limit'] ?? 50)));
    return ['page' => $page, 'limit' => $limit, 'offset' => ($page - 1) * $limit];
}
