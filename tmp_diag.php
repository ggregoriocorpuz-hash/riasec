<?php
/**
 * RIASEC — Railway connection diagnostic
 * Access: https://cnhs-riasec.up.railway.app/tmp_diag.php
 * DELETE or rename this file after diagnosing.
 * It is in .gitignore (tmp_*) but IS deployed to Railway.
 */
header('Content-Type: text/plain; charset=utf-8');

echo "=== RIASEC Railway Diagnostic ===\n\n";

// --- All known Railway MySQL variable names ---
$vars = [
    'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER',
    'MYSQLHOST', 'MYSQLPORT', 'MYSQLDATABASE', 'MYSQLUSER',
    'DATABASE_URL', 'MYSQL_URL', 'MYSQL_PUBLIC_URL',
    'RAILWAY_ENVIRONMENT', 'RAILWAY_PROJECT_ID',
];
foreach ($vars as $v) {
    $val = getenv($v);
    if ($v === 'DB_PASSWORD' || $v === 'DB_PASS' || $v === 'MYSQLPASSWORD') {
        echo "$v=" . ($val !== false && $val !== '' ? '***SET***' : '(not set)') . "\n";
    } elseif (str_contains($v, 'URL')) {
        // Mask password in URLs
        if ($val !== false && $val !== '') {
            $masked = preg_replace('/(:\/\/[^:]+:)([^@]+)@/', '$1***@', $val);
            echo "$v=$masked\n";
        } else {
            echo "$v=(not set)\n";
        }
    } else {
        echo "$v=" . ($val !== false ? var_export($val, true) : '(not set)') . "\n";
    }
}

// DB_PASSWORD separately (not in list above to avoid accidental print)
$pw = getenv('DB_PASSWORD');
echo "DB_PASSWORD=" . ($pw !== false && $pw !== '' ? '***SET***' : '(not set)') . "\n";
$pw2 = getenv('MYSQLPASSWORD');
echo "MYSQLPASSWORD=" . ($pw2 !== false && $pw2 !== '' ? '***SET***' : '(not set)') . "\n";

echo "\n--- Resolved by db.php logic ---\n";
require_once __DIR__ . '/config/db.php';
echo "DB_HOST constant  = " . DB_HOST . "\n";
echo "DB_PORT constant  = " . DB_PORT . "\n";
echo "DB_NAME constant  = " . DB_NAME . "\n";
echo "DB_USER constant  = " . DB_USER . "\n";
echo "DB_PASS constant  = " . (DB_PASS !== '' ? '***SET***' : '(empty)') . "\n";

echo "\n--- TCP connectivity test ---\n";
$fp = @fsockopen(DB_HOST, (int)DB_PORT, $errno, $errstr, 5);
if ($fp) {
    echo "TCP " . DB_HOST . ":" . DB_PORT . " => OPEN (connected)\n";
    fclose($fp);
} else {
    echo "TCP " . DB_HOST . ":" . DB_PORT . " => FAILED ($errno: $errstr)\n";
}

echo "\n--- PDO connection test ---\n";
try {
    $testPdo = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_TIMEOUT => 8, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $ver = $testPdo->query('SELECT VERSION()')->fetchColumn();
    echo "PDO connection  => OK\n";
    echo "MySQL version   => $ver\n";

    // Check if tables exist
    $tables = $testPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "Tables found    => " . implode(', ', $tables) . "\n";
    echo "admins rows     => " . $testPdo->query("SELECT COUNT(*) FROM admins")->fetchColumn() . "\n";
} catch (Throwable $e) {
    echo "PDO connection  => FAILED\n";
    echo "Error           => " . $e->getMessage() . "\n";
}

echo "\n=== End diagnostic ===\n";
echo "(Delete tmp_diag.php after reviewing)\n";
