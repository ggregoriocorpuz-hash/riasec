<?php
/**
 * RIASEC — POST /api/submit_assessment.php
 *
 * Accepts JSON body:
 * {
 *   "full_name":   "Juan dela Cruz",
 *   "student_id":  "2024-00123",
 *   "section":     "12-STEM-A",
 *   "answers": { "R1": 4, "R2": 3, ... (all 48 question IDs) }
 * }
 *
 * Returns JSON:
 * { "success": true, "respondent_id": 7, "holland_code": "ISA", "scores": {...} }
 */

declare(strict_types=1);

/*
 * Suppress PHP notices/warnings from leaking into the JSON response body.
 * Errors are caught explicitly and returned as JSON error objects instead.
 */
ini_set('display_errors', '0');
error_reporting(0);

// Buffer output so any accidental whitespace / BOM doesn't corrupt JSON
ob_start();

// Session must be started before any output
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// All responses from this endpoint are JSON
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
require_once __DIR__ . '/../config/api_cors.php';

// ── Helper: send JSON and exit cleanly ────────────────────
function sendJson(array $data, int $status = 200): void {
    // Discard any accidental output before our JSON
    ob_end_clean();
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Only allow POST ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJson(['success' => false, 'error' => 'Method not allowed.'], 405);
}

require_once __DIR__ . '/../config/db.php';

// ── Read & decode JSON body ───────────────────────────────
$raw = (string) file_get_contents('php://input');

if ($raw === '') {
    sendJson(['success' => false, 'error' => 'Empty request body.'], 400);
}

$data = json_decode($raw, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    sendJson(['success' => false, 'error' => 'Invalid JSON payload: ' . json_last_error_msg()], 400);
}

// ── Validate respondent fields ────────────────────────────
$full_name  = trim((string)($data['full_name']  ?? ''));
$student_id = trim((string)($data['student_id'] ?? ''));
$section    = trim((string)($data['section']    ?? ''));

$errors = [];
if ($full_name === '' || mb_strlen($full_name) > 200) {
    $errors[] = 'full_name is required (max 200 characters).';
}
if (!preg_match('/\A[0-9]{12}\z/', $student_id)) {
    $errors[] = 'LRN must contain exactly 12 digits.';
}
if ($section === '' || mb_strlen($section) > 100) {
    $errors[] = 'section is required (max 100 characters).';
}
if (!empty($errors)) {
    sendJson(['success' => false, 'errors' => $errors], 422);
}

// ── Validate answers ──────────────────────────────────────
$VALID_IDS = [
    'R1','R2','R3','R4','R5','R6','R7','R8',
    'I1','I2','I3','I4','I5','I6','I7','I8',
    'A1','A2','A3','A4','A5','A6','A7','A8',
    'S1','S2','S3','S4','S5','S6','S7','S8',
    'E1','E2','E3','E4','E5','E6','E7','E8',
    'C1','C2','C3','C4','C5','C6','C7','C8',
];

$CATEGORY_MAP = [
    'R' => ['R1','R2','R3','R4','R5','R6','R7','R8'],
    'I' => ['I1','I2','I3','I4','I5','I6','I7','I8'],
    'A' => ['A1','A2','A3','A4','A5','A6','A7','A8'],
    'S' => ['S1','S2','S3','S4','S5','S6','S7','S8'],
    'E' => ['E1','E2','E3','E4','E5','E6','E7','E8'],
    'C' => ['C1','C2','C3','C4','C5','C6','C7','C8'],
];

$answers = $data['answers'] ?? null;
if (!is_array($answers)) {
    sendJson(['success' => false, 'error' => 'answers must be an object.'], 422);
}

$answerErrors = [];
foreach ($VALID_IDS as $qid) {
    if (!array_key_exists($qid, $answers)) {
        $answerErrors[] = "Missing answer for $qid.";
        continue;
    }
    $val = (int)$answers[$qid];
    if ($val < 1 || $val > 5) {
        $answerErrors[] = "Answer for $qid must be 1-5 (got $val).";
    }
}
if (!empty($answerErrors)) {
    sendJson(['success' => false, 'errors' => $answerErrors], 422);
}

// ── Calculate scores ──────────────────────────────────────
$scores = ['R' => 0, 'I' => 0, 'A' => 0, 'S' => 0, 'E' => 0, 'C' => 0];
foreach ($CATEGORY_MAP as $cat => $ids) {
    foreach ($ids as $qid) {
        $scores[$cat] += (int)$answers[$qid];
    }
}

// ── Holland Code (top 3, ties broken alphabetically) ──────
$catEntries = [];
foreach ($scores as $cat => $score) {
    $catEntries[] = ['cat' => $cat, 'score' => $score];
}
usort($catEntries, function ($a, $b) {
    if ($b['score'] !== $a['score']) return $b['score'] - $a['score'];
    return strcmp($a['cat'], $b['cat']);
});
$holland_code = $catEntries[0]['cat'] . $catEntries[1]['cat'] . $catEntries[2]['cat'];

// ── Duplicate-submission check ────────────────────────────
try {
    $pdo = getDB();

    $dupStmt = $pdo->prepare(
        'SELECT id, score_r, score_i, score_a, score_s, score_e, score_c, holland_code
         FROM respondents
         WHERE student_id = :sid
         ORDER BY submitted_at DESC
         LIMIT 1'
    );
    $dupStmt->execute([':sid' => $student_id]);
    $existing = $dupStmt->fetch();

    if ($existing) {
        $prevScores = [
            'R' => (int)$existing['score_r'],
            'I' => (int)$existing['score_i'],
            'A' => (int)$existing['score_a'],
            'S' => (int)$existing['score_s'],
            'E' => (int)$existing['score_e'],
            'C' => (int)$existing['score_c'],
        ];
        sendJson([
            'success'      => false,
            'duplicate'    => true,
            'respondent_id'=> (int)$existing['id'],
            'holland_code' => $existing['holland_code'],
            'scores'       => $prevScores,
            'message'      => 'A submission for LRN "' . $student_id . '" already exists. '
                            . 'Your previous results are shown below.',
        ], 409);
    }
} catch (PDOException $e) {
    error_log('RIASEC duplicate-check PDO error: ' . $e->getMessage());
    sendJson(['success' => false, 'error' => 'Failed to validate submission. Please try again.'], 500);
}

// ── Persist to database ───────────────────────────────────
try {
    $pdo = getDB();
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'INSERT INTO respondents
            (full_name, student_id, section,
             score_r, score_i, score_a, score_s, score_e, score_c,
             holland_code, submitted_at)
         VALUES
            (:full_name, :student_id, :section,
             :sr, :si, :sa, :ss, :se, :sc,
             :holland_code, NOW())'
    );
    $stmt->execute([
        ':full_name'    => $full_name,
        ':student_id'   => $student_id,
        ':section'      => $section,
        ':sr'           => $scores['R'],
        ':si'           => $scores['I'],
        ':sa'           => $scores['A'],
        ':ss'           => $scores['S'],
        ':se'           => $scores['E'],
        ':sc'           => $scores['C'],
        ':holland_code' => $holland_code,
    ]);

    $respondent_id = (int)$pdo->lastInsertId();

    $ansStmt = $pdo->prepare(
        'INSERT INTO answers (respondent_id, question_id, category, score)
         VALUES (:rid, :qid, :cat, :score)'
    );
    foreach ($VALID_IDS as $qid) {
        $ansStmt->execute([
            ':rid'   => $respondent_id,
            ':qid'   => $qid,
            ':cat'   => $qid[0],
            ':score' => (int)$answers[$qid],
        ]);
    }

    $pdo->commit();

    $_SESSION['respondent_id'] = $respondent_id;

    sendJson([
        'success'       => true,
        'respondent_id' => $respondent_id,
        'holland_code'  => $holland_code,
        'scores'        => $scores,
    ], 200);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Log the real error server-side; never expose it to the browser
    error_log('RIASEC submit_assessment PDO error: ' . $e->getMessage());
    sendJson(['success' => false, 'error' => 'Failed to save assessment. Please try again.'], 500);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('RIASEC submit_assessment error: ' . $e->getMessage());
    sendJson(['success' => false, 'error' => 'An unexpected error occurred. Please try again.'], 500);
}
