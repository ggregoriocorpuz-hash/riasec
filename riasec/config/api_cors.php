<?php
declare(strict_types=1);

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

function riasecApiOriginAllowed(string $origin): bool {
    // Origin headers are always scheme://host[:port] — never a path.
    // Same-origin requests (the normal case, e.g. http://localhost/riasec)
    // don't need CORS at all; this list covers dev servers and LAN access.
    return in_array($origin, [
        'http://localhost',
        'http://localhost:5500',
        'http://localhost:8000',
        'http://127.0.0.1',
        'http://127.0.0.1:5500',
        'http://127.0.0.1:8000',
    ], true);
}

if (riasecApiOriginAllowed($origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    if (!riasecApiOriginAllowed($origin)) {
        http_response_code(403);
        exit;
    }

    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 600');
    http_response_code(204);
    exit;
}
