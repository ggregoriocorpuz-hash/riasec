<?php
/**
 * RIASEC — Student inquiry submission and tracking API.
 */

declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(0);
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../config/api_cors.php';
require_once __DIR__ . '/../config/db.php';

function inquiryJson(array $data, int $status = 200): void {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        http_response_code(500);
        $json = '{"success":false,"error":"Unable to encode the inquiry response."}';
    }
    echo $json;
    exit;
}

function normalizeInquiryName(string $name): string {
    $normalized = preg_replace('/\s+/u', ' ', trim($name));
    return mb_strtolower($normalized ?? '', 'UTF-8');
}

function validateInquiryIdentity(PDO $pdo, string $name, string $lrn): array {
    if ($name === '' || mb_strlen($name, 'UTF-8') > 200) {
        inquiryJson(['success' => false, 'error' => 'Enter the name registered with your assessment.'], 422);
    }
    if (!preg_match('/\A[0-9]{12}\z/', $lrn)) {
        inquiryJson(['success' => false, 'error' => 'Enter your exact 12-digit LRN.'], 422);
    }

    $stmt = $pdo->prepare(
        'SELECT id, full_name, score_r, score_i, score_a, score_s, score_e, score_c, submitted_at
         FROM respondents WHERE student_id = :student_id ORDER BY submitted_at DESC'
    );
    $stmt->execute([':student_id' => $lrn]);
    $matches = [];
    $expectedName = normalizeInquiryName($name);
    foreach ($stmt->fetchAll() as $row) {
        if (normalizeInquiryName((string)$row['full_name']) === $expectedName) {
            $matches[] = $row;
        }
    }
    if (!$matches) {
        inquiryJson(['success' => false, 'error' => 'The name and LRN do not match a registered assessment.'], 403);
    }
    return $matches;
}

function requireInquirySameOrigin(): void {
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && riasecApiOriginAllowed($origin)) {
        return;
    }

    $fetchSite = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
    if ($fetchSite === 'cross-site') {
        inquiryJson(['success' => false, 'error' => 'Cross-site requests are not allowed.'], 403);
    }

    if ($origin === '') {
        return;
    }
    $originParts = parse_url($origin);
    $expectedScheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
    $expectedHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if (!$originParts || strtolower((string)($originParts['scheme'] ?? '')) !== $expectedScheme
        || strtolower((string)($originParts['host'] ?? '')) !== preg_replace('/:\d+$/', '', $expectedHost)
        || (isset($originParts['port']) && (int)$originParts['port'] !== (int)($_SERVER['SERVER_PORT'] ?? 0))) {
        inquiryJson(['success' => false, 'error' => 'Cross-origin requests are not allowed.'], 403);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    inquiryJson(['success' => false, 'error' => 'Method not allowed.'], 405);
}
requireInquirySameOrigin();

$raw = (string)file_get_contents('php://input');
$data = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    inquiryJson(['success' => false, 'error' => 'Invalid request.'], 400);
}

$action = (string)($data['action'] ?? '');
$name = trim((string)($data['full_name'] ?? ''));
$lrn = trim((string)($data['student_id'] ?? ''));

try {
    $pdo = getDB();
    $respondents = validateInquiryIdentity($pdo, $name, $lrn);
    $respondentIds = array_map(static fn(array $row): int => (int)$row['id'], $respondents);

    if ($action === 'submit') {
        $concern = trim((string)($data['concern'] ?? ''));
        $preferredDate = (string)($data['preferred_date'] ?? '');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $preferredDate);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if ($concern === '' || mb_strlen($concern, 'UTF-8') > 3000) {
            inquiryJson(['success' => false, 'error' => 'Your question or concern is required (maximum 3,000 characters).'], 422);
        }
        if (!$date || ($dateErrors !== false && ($dateErrors['warning_count'] || $dateErrors['error_count']))
            || $date->format('Y-m-d') !== $preferredDate
            || $preferredDate < (new DateTimeImmutable('today'))->format('Y-m-d')) {
            inquiryJson(['success' => false, 'error' => 'Choose a valid preferred interview date that is not in the past.'], 422);
        }

        // Already submitted for this student/test result — do not insert a duplicate.
        $existingStmt = $pdo->prepare(
            'SELECT id FROM inquiries WHERE respondent_id = :respondent_id
             ORDER BY created_at DESC LIMIT 1'
        );
        $existingStmt->execute([':respondent_id' => $respondentIds[0]]);
        $existingInquiry = $existingStmt->fetch();
        if ($existingInquiry) {
            inquiryJson([
                'success' => true,
                'inquiry_id' => (int)$existingInquiry['id'],
                'already_submitted' => true,
            ]);
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'INSERT INTO inquiries (respondent_id, concern, preferred_date, status)
             VALUES (:respondent_id, :concern, :preferred_date, \'Pending\')'
        );
        $stmt->execute([
            ':respondent_id' => $respondentIds[0],
            ':concern' => $concern,
            ':preferred_date' => $preferredDate,
        ]);
        $inquiryId = (int)$pdo->lastInsertId();
        $history = $pdo->prepare(
            'INSERT INTO inquiry_updates
                (inquiry_id, actor, status, preferred_date, scheduled_at, message)
             VALUES (:inquiry_id, \'student\', \'Pending\', :preferred_date, NULL, :message)'
        );
        $history->execute([
            ':inquiry_id' => $inquiryId,
            ':preferred_date' => $preferredDate,
            ':message' => 'Inquiry submitted.',
        ]);
        $pdo->commit();
        inquiryJson(['success' => true, 'inquiry_id' => $inquiryId]);
    }

    if ($action === 'status') {
        $statusStmt = $pdo->prepare(
            'SELECT id FROM inquiries WHERE respondent_id = :respondent_id
             ORDER BY created_at DESC LIMIT 1'
        );
        $statusStmt->execute([':respondent_id' => $respondentIds[0]]);
        $statusRow = $statusStmt->fetch();
        inquiryJson([
            'success' => true,
            'has_inquiry' => (bool)$statusRow,
            'inquiry_id' => $statusRow ? (int)$statusRow['id'] : null,
        ]);
    }

    if ($action === 'track') {
        $placeholders = [];
        $params = [];
        foreach ($respondentIds as $index => $respondentId) {
            $key = ':respondent_' . $index;
            $placeholders[] = $key;
            $params[$key] = $respondentId;
        }
        $inquiriesStmt = $pdo->prepare(
            'SELECT id, concern, DATE_FORMAT(preferred_date, \'%Y-%m-%d\') AS preferred_date,
                    status, DATE_FORMAT(scheduled_at, \'%b %d, %Y %h:%i %p\') AS scheduled_at,
                    teacher_message,
                    DATE_FORMAT(updated_at, \'%Y-%m-%d %H:%i:%s\') AS updated_at,
                    (SELECT MAX(iu.id) FROM inquiry_updates iu WHERE iu.inquiry_id = inquiries.id) AS update_version
             FROM inquiries WHERE respondent_id IN (' . implode(', ', $placeholders) . ')
             ORDER BY created_at DESC'
        );
        $inquiriesStmt->execute($params);
        $inquiries = $inquiriesStmt->fetchAll();

        if ($inquiries) {
            $inquiryPlaceholders = [];
            $historyParams = [];
            foreach ($inquiries as $index => $inquiry) {
                $key = ':inquiry_' . $index;
                $inquiryPlaceholders[] = $key;
                $historyParams[$key] = (int)$inquiry['id'];
            }
            $historyStmt = $pdo->prepare(
                'SELECT inquiry_id, actor, status,
                        DATE_FORMAT(preferred_date, \'%Y-%m-%d\') AS preferred_date,
                        DATE_FORMAT(scheduled_at, \'%b %d, %Y %h:%i %p\') AS scheduled_at,
                        message,
                        DATE_FORMAT(created_at, \'%b %d, %Y %h:%i %p\') AS created_at
                 FROM inquiry_updates WHERE inquiry_id IN (' . implode(', ', $inquiryPlaceholders) . ')
                 ORDER BY created_at DESC'
            );
            $historyStmt->execute($historyParams);
            $historyByInquiry = [];
            foreach ($historyStmt->fetchAll() as $update) {
                $historyByInquiry[(int)$update['inquiry_id']][] = $update;
            }
            foreach ($inquiries as &$inquiry) {
                $inquiry['updates'] = $historyByInquiry[(int)$inquiry['id']] ?? [];
            }
            unset($inquiry);
        }
        inquiryJson(['success' => true, 'inquiries' => $inquiries]);
    }

    inquiryJson(['success' => false, 'error' => 'Unknown request action.'], 400);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        try {
            $pdo->rollBack();
        } catch (Throwable $rollbackError) {
            error_log('RIASEC inquiry rollback error: ' . $rollbackError->getMessage());
        }
    }
    error_log('RIASEC inquiry API error: ' . $e->getMessage());
    inquiryJson(['success' => false, 'error' => 'Unable to save or retrieve inquiries right now. Please try again later.'], 500);
}
