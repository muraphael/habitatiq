<?php
// ============================================================
// HabitatIQ — Input Validation Helpers
// ============================================================

function validateEmail(string $email): bool {
    return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
}

function validatePhone(string $phone): bool {
    // Kenyan phone: 07xxxxxxxx or 01xxxxxxxx (10 digits)
    return (bool)preg_match('/^(07|01)\d{8}$/', $phone);
}

function validateNID(string $nid): bool {
    // 7–8 digit Kenyan National ID
    return (bool)preg_match('/^\d{7,8}$/', $nid);
}

function sanitize(string $val): string {
    return trim(htmlspecialchars($val, ENT_QUOTES, 'UTF-8'));
}

function required(array $body, array $fields): ?string {
    foreach ($fields as $field) {
        if (empty($body[$field]) && $body[$field] !== '0') {
            return "$field is required";
        }
    }
    return null;
}

function validateImageUpload(array $file): array {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'File upload error: ' . $file['error']];
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'File too large. Maximum 2MB allowed.'];
    }
    $allowed = ['image/jpeg', 'image/png', 'image/jpg'];
    $finfo   = new finfo(FILEINFO_MIME_TYPE);
    $mime    = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowed)) {
        return ['ok' => false, 'error' => 'Only JPG and PNG images are allowed.'];
    }
    return ['ok' => true, 'mime' => $mime];
}
