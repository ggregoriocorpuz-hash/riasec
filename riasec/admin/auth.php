<?php
/**
 * RIASEC Admin — Authentication Guard
 * Include at the top of every protected admin page.
 * Redirects to login if the session is not authenticated.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_username'])) {
    header('Location: index.php');
    exit;
}

/* ── CSRF helpers ──────────────────────────────────────────────────────── */

/**
 * Generate (or reuse) a CSRF token for this session.
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Emit a hidden CSRF input field — call inside any admin <form>.
 */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Verify the CSRF token submitted with a POST request.
 * Terminates with 403 on failure.
 */
function csrf_verify(): void {
    $submitted = $_POST['csrf_token'] ?? '';
    if (!hash_equals(csrf_token(), $submitted)) {
        http_response_code(403);
        exit('403 Forbidden — CSRF token mismatch.');
    }
}
