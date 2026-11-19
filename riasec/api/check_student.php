<?php
/**
 * RIASEC — POST /api/check_student.php
 *
 * Checks whether a student has already completed the RIASEC assessment
 * using all available information: full name, student ID, and section.
 *
 * Matching tiers:
 *   1. EXACT  — same Student ID, or same normalised name + section
 *   2. SIMILAR — close name match (SOUNDEX or partial LIKE), different ID
 *   3. NONE   — no match at all
 *
 * Accepts JSON body:
 *   { "full_name": "Juan Dela Cruz", "student_id": "2024-00123", "section": "Grade 12 - STEM-A" }
 *
 * Returns JSON:
 *   exact:   { "match_type": "exact",   "respondent": {...}, "scores": {...}, "submitted_at": "..." }
 *   similar: { "match_type": "similar", "candidates": [{...}] }
 *   none:    { "match_type": "none" }
 */

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(0);

ob_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
require_once __DIR__ . '/../config/api_cors.php';

function sendJson(array $data, int $status = 200): void {
    ob_end_clean();
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJson(['error' => 'Method not allowed.'], 405);
}

require_once __DIR__ . '/../config/db.php';

$raw = (string) file_get_contents('php://input');

if ($raw === '') {
    sendJson(['error' => 'Empty request body.'], 400);
}

$data = json_decode($raw, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    sendJson(['error' => 'Invalid JSON payload.'], 400);
}

// ── Read & validate inputs ────────────────────────────────
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
    sendJson(['error' => implode(' ', $errors)], 422);
}

// ── Helpers ───────────────────────────────────────────────
function rowToScores(array $row): array {
    return [
        'R' => (int)$row['score_r'],
        'I' => (int)$row['score_i'],
        'A' => (int)$row['score_a'],
        'S' => (int)$row['score_s'],
        'E' => (int)$row['score_e'],
        'C' => (int)$row['score_c'],
    ];
}

function rowToRespondent(array $row): array {
    return [
        'full_name'  => $row['full_name'],
        'student_id' => $row['student_id'],
        'section'    => $row['section'],
    ];
}

try {
    $pdo = getDB();

    // Normalised values used for case/whitespace-insensitive matching
    $normName    = mb_strtolower(preg_replace('/\s+/', ' ', $full_name), 'UTF-8');
    $normSection = mb_strtolower(preg_replace('/\s+/', ' ', $section), 'UTF-8');

    /* ═══ TIER 1 — EXACT MATCH ════════════════════════════════
     *
     * Match by Student ID  OR  normalised name + normalised section.
     *
     * Positional placeholders keep parameter binding unambiguous with
     * MySQL native prepared statements.
     */
    $exactSQL = "
        SELECT id, full_name, student_id, section,
               score_r, score_i, score_a, score_s, score_e, score_c,
               holland_code,
               DATE_FORMAT(submitted_at, '%Y-%m-%d %H:%i:%s') AS submitted_at
        FROM respondents
        WHERE student_id = ?
           OR (
                LOWER(TRIM(REGEXP_REPLACE(full_name, '\\\\s+', ' '))) = ?
                AND LOWER(TRIM(REGEXP_REPLACE(section,    '\\\\s+', ' '))) = ?
              )
        ORDER BY submitted_at DESC
        LIMIT 1
    ";

    $exactStmt = $pdo->prepare($exactSQL);
    $exactStmt->execute([$student_id, $normName, $normSection]);
    $exactRow = $exactStmt->fetch();

    if ($exactRow) {
        sendJson([
            'match_type'   => 'exact',
            'respondent'   => rowToRespondent($exactRow),
            'holland_code' => $exactRow['holland_code'],
            'scores'       => rowToScores($exactRow),
            'submitted_at' => $exactRow['submitted_at'],
        ]);
    }

    /* ═══ TIER 2 — SIMILAR MATCH ══════════════════════════════
     *
     * Fuzzy name match via SOUNDEX and LIKE. Positional placeholders avoid
     * native-PDO named placeholder parsing differences across drivers.
     */
    $likePattern = '%' . $full_name . '%';

    $simSQL = "
        SELECT id, full_name, student_id, section,
               score_r, score_i, score_a, score_s, score_e, score_c,
               holland_code,
               DATE_FORMAT(submitted_at, '%Y-%m-%d %H:%i:%s') AS submitted_at
        FROM respondents
        WHERE student_id <> ?
          AND (
                SOUNDEX(full_name) = SOUNDEX(?)
                OR full_name LIKE ?
                OR ? LIKE CONCAT('%', full_name, '%')
              )
        ORDER BY submitted_at DESC
        LIMIT 5
    ";

    $simStmt = $pdo->prepare($simSQL);
    $simStmt->execute([$student_id, $full_name, $likePattern, $full_name]);
    $similarRows = $simStmt->fetchAll();

    if (!empty($similarRows)) {
        $candidates = [];
        foreach ($similarRows as $sr) {
            $candidates[] = [
                'id'           => (int)$sr['id'],
                'full_name'    => $sr['full_name'],
                'student_id'   => $sr['student_id'],
                'section'      => $sr['section'],
                'holland_code' => $sr['holland_code'],
                'scores'       => rowToScores($sr),
                'submitted_at' => $sr['submitted_at'],
            ];
        }
        sendJson(['match_type' => 'similar', 'candidates' => $candidates]);
    }

    /* ═══ TIER 3 — NO MATCH ══════════════════════════════════ */
    sendJson(['match_type' => 'none']);

} catch (Throwable $e) {
    error_log('RIASEC check_student error: ' . $e->getMessage());
    sendJson(['error' => 'Failed to check student records. Please try again.'], 500);
}
