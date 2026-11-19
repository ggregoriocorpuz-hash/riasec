<?php
/**
 * RIASEC Admin — Logout
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verify CSRF if posted (GET logout links are acceptable here since we destroy the session)
session_unset();
session_destroy();

// Prevent caching of the now-invalidated session
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Location: index.php');
exit;
