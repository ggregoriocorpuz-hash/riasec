<?php
/**
 * RIASEC — Health check
 * GET http://localhost/riasec/api/health.php
 * Returns JSON confirming PHP is running and the DB is reachable.
 * DELETE this file before deploying to production.
 */

declare(strict_types=1);
ini_set('display_errors', '0');
ob_start();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';

$result = ['php' => PHP_VERSION, 'db' => false, 'error' => null];

try {
    $pdo = getDB();
    $pdo->query('SELECT 1');
    $result['db'] = true;
} catch (Throwable $e) {
    $result['error'] = $e->getMessage();
}

ob_end_clean();
echo json_encode($result, JSON_PRETTY_PRINT);
