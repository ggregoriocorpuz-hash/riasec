<?php
/**
 * RIASEC — GET /api/get_result.php?id=7
 *
 * Returns the respondent's own result by ID.
 * Only returns data if the ID matches the one stored in the
 * current PHP session (set by submit_assessment.php), preventing
 * respondents from peeking at each other's results.
 *
 * Response:
 * {
 *   "success": true,
 *   "respondent": { id, full_name, student_id, section, holland_code,
 *                   score_r, score_i, score_a, score_s, score_e, score_c,
 *                   submitted_at }
 * }
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// Start session to verify ownership
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid respondent ID.']);
    exit;
}

// Ownership check — the session must contain this respondent's ID
if (!isset($_SESSION['respondent_id']) || (int)$_SESSION['respondent_id'] !== $id) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
    exit;
}

$pdo  = getDB();
$stmt = $pdo->prepare(
    'SELECT id, full_name, student_id, section,
            score_r, score_i, score_a, score_s, score_e, score_c,
            holland_code,
            DATE_FORMAT(submitted_at, "%Y-%m-%d %H:%i:%s") AS submitted_at
     FROM respondents
     WHERE id = :id
     LIMIT 1'
);
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Record not found.']);
    exit;
}

echo json_encode(['success' => true, 'respondent' => $row]);
