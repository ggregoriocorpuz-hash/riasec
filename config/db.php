
<?php
/**
 * RIASEC — Database configuration
 * Supports XAMPP (local) and Railway (production).
 * Never commit database credentials to GitHub.
 */

// Get environment variables.
// Local XAMPP values are used when Railway variables are unavailable.
define('DB_HOST', getenv('DB_HOST') ?: (getenv('MYSQLHOST') ?: '127.0.0.1'));

define('DB_PORT', getenv('DB_PORT') ?: (getenv('MYSQLPORT') ?: '3306'));

define('DB_NAME', getenv('DB_NAME') ?: (getenv('MYSQLDATABASE') ?: 'riasec_db'));

define('DB_USER', getenv('DB_USER') ?: (getenv('MYSQLUSER') ?: 'root'));

define('DB_PASS', getenv('DB_PASSWORD') ?: (getenv('MYSQLPASSWORD') ?: ''));

define('DB_CHARSET', 'utf8mb4');

/**
 * Returns a shared PDO connection (singleton).
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
        ];

        try {

            $pdo = new PDO(
                $dsn,
                DB_USER,
                DB_PASS,
                $options
            );

        } catch (PDOException $e) {

            error_log(
                'RIASEC database connection error: ' . $e->getMessage()
            );

            http_response_code(500);

            header('Content-Type: application/json');

            echo json_encode([
                'success' => false,
                'error' => 'Database connection failed.'
            ]);

            exit;
        }
    }

    return $pdo;
}
