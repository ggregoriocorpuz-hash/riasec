<?php
/**
 * RIASEC — Database configuration
 * Supports XAMPP (local) and Railway (production).
 *
 * Variable resolution order (first non-empty value wins):
 *
 *  Host  : DB_HOST  → MYSQLHOST  → parsed from DATABASE_URL / MYSQL_URL  → 127.0.0.1
 *  Port  : DB_PORT  → MYSQLPORT  → parsed from DATABASE_URL / MYSQL_URL  → 3306
 *  Name  : DB_NAME  → MYSQLDATABASE → parsed from DATABASE_URL / MYSQL_URL → riasec_db
 *  User  : DB_USER  → MYSQLUSER  → parsed from DATABASE_URL / MYSQL_URL  → root
 *  Pass  : DB_PASSWORD → MYSQLPASSWORD → parsed from DATABASE_URL / MYSQL_URL → ''
 *
 * Railway's MySQL plugin exposes BOTH the individual variables
 * (MYSQLHOST, MYSQLPORT, etc.) AND a DATABASE_URL connection string.
 * We try every known variable name so the app works regardless of
 * which Railway plugin version or configuration is in use.
 */

declare(strict_types=1);

/* ── Helper: return first non-empty value from a list of env vars ── */
function _db_env(array $names, string $default): string {
    foreach ($names as $n) {
        $v = getenv($n);
        if ($v !== false && $v !== '') {
            return $v;
        }
    }
    return $default;
}

/* ── Parse DATABASE_URL / MYSQL_URL if individual vars are absent ── */
function _db_parse_url(): array {
    $url = '';
    foreach (['DATABASE_URL', 'MYSQL_URL', 'MYSQL_PUBLIC_URL'] as $k) {
        $v = getenv($k);
        if ($v !== false && $v !== '' && preg_match('/^mysql(?:2)?:\/\//i', $v)) {
            $url = $v;
            break;
        }
    }

    if ($url === '') {
        return [];
    }

    $p = parse_url($url);
    if ($p === false) {
        return [];
    }

    return [
        'host' => $p['host']                        ?? '',
        'port' => isset($p['port']) ? (string)$p['port'] : '3306',
        'user' => isset($p['user']) ? urldecode($p['user']) : '',
        'pass' => isset($p['pass']) ? urldecode($p['pass']) : '',
        'name' => isset($p['path']) ? ltrim($p['path'], '/') : '',
    ];
}

$_dbUrl = _db_parse_url();

define('DB_HOST',    _db_env(['DB_HOST', 'MYSQLHOST'],     $_dbUrl['host'] ?? '127.0.0.1'));
define('DB_PORT',    _db_env(['DB_PORT', 'MYSQLPORT'],     $_dbUrl['port'] ?? '3306'));
define('DB_NAME',    _db_env(['DB_NAME', 'MYSQLDATABASE'], $_dbUrl['name'] ?? 'riasec_db'));
define('DB_USER',    _db_env(['DB_USER', 'MYSQLUSER'],     $_dbUrl['user'] ?? 'root'));
define('DB_PASS',    _db_env(['DB_PASSWORD', 'MYSQLPASSWORD'], $_dbUrl['pass'] ?? ''));
define('DB_CHARSET', 'utf8mb4');

unset($_dbUrl);

/**
 * Returns a shared PDO connection (singleton).
 *
 * On connection failure the behaviour depends on context:
 *   - If this is an API endpoint (Content-Type is already JSON, or the
 *     request expects JSON), emit a JSON error and exit.
 *   - Otherwise (e.g. a full HTML admin page) emit a plain HTML error page
 *     so the browser shows something useful instead of raw JSON.
 *
 * The actual PDO error is written to the server error log in both cases
 * so it is visible in Railway's log panel without being exposed to users.
 */
function getDB(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 10,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log('RIASEC database connection error: ' . $e->getMessage()
                . ' | host=' . DB_HOST . ' port=' . DB_PORT . ' db=' . DB_NAME
                . ' user=' . DB_USER);

            http_response_code(500);

            // Detect whether the caller expects a JSON response.
            $wantsJson = (
                // API endpoints set Content-Type to JSON before calling getDB()
                (function_exists('headers_list') && in_array('Content-Type: application/json; charset=utf-8', headers_list(), true))
                // Or the request explicitly asks for JSON
                || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
                // Or the script path is under /api/
                || (isset($_SERVER['SCRIPT_FILENAME']) && strpos($_SERVER['SCRIPT_FILENAME'], DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR) !== false)
            );

            if ($wantsJson) {
                if (!headers_sent()) {
                    header('Content-Type: application/json; charset=utf-8');
                }
                echo json_encode(['success' => false, 'error' => 'Database connection failed.']);
            } else {
                // Full HTML error page for admin and student pages
                if (!headers_sent()) {
                    header('Content-Type: text/html; charset=utf-8');
                }
                echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
                    . '<meta name="viewport" content="width=device-width,initial-scale=1">'
                    . '<title>Service Unavailable — RIASEC</title>'
                    . '<style>body{font-family:system-ui,sans-serif;background:#fff;color:#1f2937;'
                    . 'display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}'
                    . '.box{max-width:480px;padding:2rem;text-align:center;}'
                    . 'h1{color:#800000;font-size:1.4rem;margin-bottom:.5rem;}'
                    . 'p{font-size:.95rem;color:#6b7280;line-height:1.6;}'
                    . '.badge{display:inline-block;background:#fdf2f2;color:#800000;'
                    . 'border:1px solid #f3d0d0;border-radius:.5rem;padding:.4rem .9rem;'
                    . 'font-size:.82rem;font-weight:600;margin-bottom:1.5rem;}'
                    . 'a{color:#800000;}</style></head><body>'
                    . '<div class="box">'
                    . '<div class="badge">Database Error</div>'
                    . '<h1>Unable to connect to the database</h1>'
                    . '<p>The system could not establish a database connection. '
                    . 'Please try again in a moment.</p>'
                    . '<p>If this problem persists, contact the system administrator. '
                    . 'The error has been recorded in the server log.</p>'
                    . '<p><a href="../index.html">&larr; Back to assessment</a></p>'
                    . '</div></body></html>';
            }

            exit;
        }
    }

    return $pdo;
}
