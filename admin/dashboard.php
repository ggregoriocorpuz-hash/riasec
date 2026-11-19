<?php
/**
 * RIASEC Admin — Dashboard
 * URL: admin/dashboard.php (relative — works on XAMPP and Railway)
 */

declare(strict_types=1);
require_once __DIR__ . '/auth.php';          // session guard + CSRF helpers
require_once __DIR__ . '/../config/db.php';

$pdo = getDB();

/* ════════════════════════════════════════════════════════════
   DELETE respondent (POST action, CSRF-protected)
════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_verify();

    if ($_POST['action'] === 'delete' && !empty($_POST['id'])) {
        $delStmt = $pdo->prepare('DELETE FROM respondents WHERE id = :id');
        $delStmt->execute([':id' => (int)$_POST['id']]);
    }

    header('Location: dashboard.php');
    exit;
}

/* ════════════════════════════════════════════════════════════
   Fetch individual answers for detail modal (AJAX)
════════════════════════════════════════════════════════════ */
if (isset($_GET['detail_id'])) {
    header('Content-Type: application/json; charset=utf-8');

    $rid  = (int)$_GET['detail_id'];
    $resp = $pdo->prepare(
        'SELECT id, full_name, student_id, section,
                score_r, score_i, score_a, score_s, score_e, score_c,
                DATE_FORMAT(submitted_at, "%Y-%m-%d %H:%i:%s") AS submitted_at
         FROM respondents WHERE id = :id LIMIT 1'
    );
    $resp->execute([':id' => $rid]);
    $respondent = $resp->fetch();

    if (!$respondent) {
        echo json_encode(['success' => false]);
        exit;
    }

    $ans = $pdo->prepare(
        'SELECT question_id, category, score
         FROM answers WHERE respondent_id = :id ORDER BY question_id'
    );
    $ans->execute([':id' => $rid]);
    $answers = $ans->fetchAll();

    echo json_encode(['success' => true, 'respondent' => $respondent, 'answers' => $answers]);
    exit;
}

/* ════════════════════════════════════════════════════════════
   Build query — search + filters
════════════════════════════════════════════════════════════ */
$search  = trim((string)($_GET['q']      ?? ''));
$section = trim((string)($_GET['section'] ?? ''));
$category = strtoupper(trim((string)($_GET['category'] ?? '')));
if (!in_array($category, ['R', 'I', 'A', 'S', 'E', 'C'], true)) {
    $category = '';
}
$from    = trim((string)($_GET['from']    ?? ''));
$to      = trim((string)($_GET['to']      ?? ''));
$page    = max(1, (int)($_GET['page']     ?? 1));
$perPage = (int)($_GET['per_page'] ?? 25);
if (!in_array($perPage, [10, 25, 50, 100], true)) {
    $perPage = 25;
}
$offset  = ($page - 1) * $perPage;

$where  = [];
$params = [];

if ($search !== '') {
    $where[]             = '(LOWER(r.full_name) LIKE LOWER(:search_name) OR LOWER(r.student_id) LIKE LOWER(:search_id))';
    $params[':search_name'] = '%' . $search . '%';
    $params[':search_id']   = '%' . $search . '%';
}
if ($section !== '') {
    $where[]           = 'r.section LIKE :section';
    $params[':section'] = '%' . $section . '%';
}
// Tie-inclusive: a respondent matches the category filter when their score
// for it equals their highest score (so tied categories are all included).
$scoreCols = ['R' => 'score_r', 'I' => 'score_i', 'A' => 'score_a', 'S' => 'score_s', 'E' => 'score_e', 'C' => 'score_c'];
if ($category !== '') {
    $col = $scoreCols[$category];
    $where[] = "r.$col = GREATEST(r.score_r, r.score_i, r.score_a, r.score_s, r.score_e, r.score_c)";
}
if ($from !== '') {
    $where[]        = 'DATE(r.submitted_at) >= :from';
    $params[':from'] = $from;
}
if ($to !== '') {
    $where[]      = 'DATE(r.submitted_at) <= :to';
    $params[':to'] = $to;
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Total count
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM respondents r $whereSQL");
$countStmt->execute($params);
$totalRows  = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page       = min($page, $totalPages);

// Paginated rows
$dataStmt = $pdo->prepare(
    "SELECT r.id, r.full_name, r.student_id, r.section,
            r.score_r, r.score_i, r.score_a, r.score_s, r.score_e, r.score_c,
            DATE_FORMAT(r.submitted_at, '%b %d, %Y %h:%i %p') AS submitted_at
     FROM respondents r
     $whereSQL
     ORDER BY r.submitted_at DESC
     LIMIT :limit OFFSET :offset"
);
foreach ($params as $k => $v) { $dataStmt->bindValue($k, $v); }
$dataStmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$dataStmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
$dataStmt->execute();
$rows = $dataStmt->fetchAll();

// Summary stats
$stats = $pdo->query(
    'SELECT COUNT(*) AS total,
            AVG(score_r) AS avg_r, AVG(score_i) AS avg_i, AVG(score_a) AS avg_a,
            AVG(score_s) AS avg_s, AVG(score_e) AS avg_e, AVG(score_c) AS avg_c
     FROM respondents'
)->fetch();

// Distinct sections for filter dropdown
$sections = $pdo->query(
    'SELECT DISTINCT section FROM respondents ORDER BY section'
)->fetchAll(PDO::FETCH_COLUMN);

/* ════════════════════════════════════════════════════════════
   Helper: build pagination URL
════════════════════════════════════════════════════════════ */
function pageUrl(int $p): string {
    $q = $_GET;
    $q['page'] = $p;
    return 'dashboard.php?' . http_build_query($q);
}

/* ════════════════════════════════════════════════════════════
   Category colour map for badges
════════════════════════════════════════════════════════════ */
$CAT_COLORS = [
    'R' => '#b91c1c', 'I' => '#991b1b', 'A' => '#800000',
    'S' => '#7f1d1d', 'E' => '#6b0f1a', 'C' => '#5d1414',
];
$CAT_NAMES = [
    'R' => 'Realistic', 'I' => 'Investigative', 'A' => 'Artistic',
    'S' => 'Social',    'E' => 'Enterprising',  'C' => 'Conventional',
];

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Admin Dashboard — RIASEC</title>
  <link rel="stylesheet" href="../vendor/bootstrap/css/bootstrap.min.css" />
  <link rel="stylesheet" href="../vendor/bootstrap-icons/font/bootstrap-icons.min.css" />
  <link rel="stylesheet" href="../style.css" />
  <style>
    /* ── Dashboard-specific styles ─────────────────────── */
    body { background: #ffffff; font-size: .92rem; }

    .dash-sidebar {
      width: 230px; min-height: 100vh; flex-shrink: 0;
      background: #800000; color: #e5e7eb;
      display: flex; flex-direction: column;
      position: sticky; top: 0; height: 100vh; overflow-y: auto;
    }
    .dash-sidebar .brand {
      padding: 1.5rem 1.25rem 1rem;
      font-size: 1rem; font-weight: 800; color: #fff;
      border-bottom: 1px solid rgba(255,255,255,.1);
    }
    .dash-sidebar .nav-link {
      color: #d1d5db; padding: .6rem 1.25rem;
      border-radius: .5rem; margin: .1rem .5rem;
      font-weight: 500; transition: background .15s, color .15s;
    }
    .dash-sidebar .nav-link:hover,
    .dash-sidebar .nav-link.active { background: rgba(255,255,255,.12); color: #fff; }
    .dash-sidebar .nav-link i { width: 20px; }

    .dash-main { flex: 1; min-width: 0; }
    .dash-topbar {
      background: #fff; border-bottom: 1px solid #e5e7eb;
      padding: .875rem 1.5rem;
      display: flex; align-items: center; justify-content: space-between;
      position: sticky; top: 0; z-index: 100;
    }

    .stat-card {
      background: #fff; border-radius: 1rem;
      padding: 1.25rem 1.5rem; box-shadow: 0 2px 8px rgba(0,0,0,.06);
      display: flex; align-items: center; gap: 1rem;
    }
    .stat-icon {
      width: 48px; height: 48px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.25rem; flex-shrink: 0;
    }
    .stat-value { font-size: 1.75rem; font-weight: 800; color: #1f2937; line-height: 1; }
    .stat-label { font-size: .8rem; color: #6b7280; font-weight: 500; }

    .filter-bar {
      background: #fff; border-radius: 1rem;
      padding: 1rem 1.25rem; box-shadow: 0 2px 8px rgba(0,0,0,.05);
    }

    .results-table { background: #fff; border-radius: 1rem; overflow: hidden;
                     box-shadow: 0 2px 8px rgba(0,0,0,.06); }
    .results-table table { margin: 0; font-size: .88rem; }
    .results-table thead th {
      background: #f9fafb; font-weight: 700; color: #374151;
      border-bottom: 2px solid #e5e7eb; white-space: nowrap;
      padding: .75rem 1rem;
    }
    .results-table tbody td { padding: .7rem 1rem; vertical-align: middle; }
    .results-table tbody tr:hover { background: #f9fafb; }

    .holland-badge {
      font-size: .85rem; font-weight: 800; letter-spacing: .06em;
      padding: .25rem .65rem; border-radius: .5rem;
      background: transparent; color: #800000;
    }
    .score-mini { font-size: .75rem; color: #6b7280; }

    .btn-detail {
      background: #fdf2f2; color: #800000; border: none;
      font-size: .8rem; font-weight: 600; border-radius: .5rem;
      padding: .3rem .7rem; transition: background .15s;
    }
    .btn-detail:hover { background: #f3f0f0; color: #5d1414; }
    .btn-detail:focus-visible { background: #f3f0f0; color: #5d1414; }
    .results-table .btn-detail {
      width: 2rem; height: 2rem; padding: 0;
      display: inline-flex; align-items: center; justify-content: center;
    }

    .modal-scores-grid {
      display: grid; grid-template-columns: repeat(3, 1fr); gap: .75rem;
    }
    .modal-detail-section { margin-bottom: 1.5rem; }
    .modal-detail-section:last-child { margin-bottom: 0; }
    .modal-section-title {
      font-size: .95rem; font-weight: 700; color: #1f2937;
      margin-bottom: .75rem;
    }
    .modal-personal-grid {
      display: grid; grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: .75rem 1.5rem; padding: 1rem;
      border: 1px solid #e5e7eb; border-radius: .75rem;
    }
    .modal-personal-label {
      display: block; color: #6b7280; font-size: .75rem;
      font-weight: 600; margin-bottom: .15rem;
    }
    .modal-personal-value { color: #1f2937; font-size: .9rem; font-weight: 600; }
    .modal-main-result {
      display: flex; align-items: center; gap: 1rem;
      padding: 1rem 1.25rem; border: 1px solid #e5e7eb;
      border-left: 4px solid #800000; border-radius: .75rem;
      background: #fff;
    }
    .modal-main-letter {
      color: #800000; font-size: 2rem; font-weight: 800;
      line-height: 1; min-width: 2rem; text-align: center;
    }
    .modal-main-name { color: #1f2937; font-weight: 700; }
    .modal-main-caption { color: #6b7280; font-size: .78rem; }
    .modal-score-cell {
      padding: .75rem; border: 1px solid #e5e7eb;
      border-radius: .65rem; background: #fff;
    }
    .modal-score-cell.is-dominant {
      border: 2px solid #800000; background: #fdf2f2;
    }
    .modal-score-heading {
      display: flex; align-items: baseline; justify-content: space-between;
      gap: .5rem; margin-bottom: .5rem;
    }
    .modal-score-name { color: #4b5563; font-size: .82rem; font-weight: 600; }
    .modal-score-letter { color: #800000; font-size: .82rem; font-weight: 800; }
    .modal-score-value { color: #1f2937; font-size: 1.2rem; font-weight: 800; }
    .modal-score-max { color: #6b7280; font-size: .75rem; font-weight: 500; }
    .modal-score-track {
      height: 5px; margin-top: .5rem; overflow: hidden;
      border-radius: 99px; background: #e5e7eb;
    }
    .modal-score-fill { height: 100%; border-radius: inherit; background: #800000; }

    .answer-grid {
      display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
      gap: .5rem; max-height: 380px; overflow-y: auto;
    }
    .answer-item {
      display: flex; align-items: center; gap: .5rem;
      border: 1px solid #e5e7eb; border-radius: .5rem;
      padding: .55rem .75rem; font-size: .82rem;
    }
    .answer-score-pip {
      margin-left: auto; font-weight: 700; font-size: .78rem;
      color: #800000; white-space: nowrap; flex-shrink: 0;
    }

    /* ── Single-student print sheet ────────────────────── */
    #student-print { display: none; }

    @media print {
      /* Only the selected student's sheet prints */
      body.printing-student > *:not(#student-print) { display: none !important; }
      body.printing-student #student-print { display: block; }

      #student-print {
        color: #000; font-size: 10pt;
      }
      #student-print .sp-header {
        border-bottom: 2px solid #800000;
        padding-bottom: 3mm; margin-bottom: 5mm;
      }
      #student-print .sp-header h1 {
        font-size: 15pt; font-weight: 800; color: #800000; margin: 0 0 1mm;
      }
      #student-print .sp-header p { font-size: 8pt; color: #6b7280; margin: 0; }
      #student-print h2 {
        font-size: 10.5pt; font-weight: 700; color: #800000;
        margin: 6mm 0 2.5mm; padding-bottom: 1.5mm;
        border-bottom: 1px solid #d1d5db;
      }
      #student-print .sp-grid {
        display: grid; grid-template-columns: repeat(2, 1fr);
        gap: 3mm 8mm;
      }
      #student-print .sp-label {
        display: block; font-size: 7.5pt; font-weight: 600;
        color: #6b7280; text-transform: uppercase; letter-spacing: .03em;
      }
      #student-print .sp-value { font-size: 10pt; font-weight: 600; color: #000; }
      #student-print .sp-main {
        display: flex; align-items: center; gap: 5mm;
        border: 1px solid #d1d5db; border-left: 4px solid #800000;
        border-radius: 2mm; padding: 3.5mm 5mm; margin-bottom: 4mm;
      }
      #student-print .sp-main-letter {
        font-size: 22pt; font-weight: 800; color: #800000; line-height: 1;
      }
      #student-print .sp-scores {
        width: 100%; border-collapse: collapse; font-size: 9.5pt;
      }
      #student-print .sp-scores th,
      #student-print .sp-scores td {
        border: 0.5pt solid #d1d5db; padding: 2mm 3mm; text-align: left;
      }
      #student-print .sp-scores thead tr {
        background: #800000; color: #fff;
        -webkit-print-color-adjust: exact; print-color-adjust: exact;
      }
      #student-print .sp-scores thead th { font-size: 8.5pt; text-transform: uppercase; }
      #student-print .sp-scores tr.sp-dominant td {
        font-weight: 700;
      }
      #student-print .sp-scores td:last-child,
      #student-print .sp-scores th:last-child { text-align: right; }
      #student-print .sp-footer {
        margin-top: 8mm; font-size: 7.5pt; color: #6b7280;
        border-top: 1px solid #d1d5db; padding-top: 2mm;
      }
    }

    @media (max-width: 767px) {
      .dash-sidebar { display: none; }
      .modal-scores-grid { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 575px) {
      .modal-personal-grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>

<div class="d-flex">

  <!-- ── Sidebar ───────────────────────────────────────── -->
  <nav class="dash-sidebar">
    <div class="brand">
      <i class="bi bi-compass me-2"></i>RIASEC Admin
    </div>
    <ul class="nav flex-column mt-3 flex-grow-1">
      <li class="nav-item">
        <a class="nav-link active" href="dashboard.php">
          <i class="bi bi-grid-fill me-2"></i>Dashboard
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link" href="inquiries.php">
          <i class="bi bi-chat-dots-fill me-2"></i>Inquiries
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link" href="report.php">
          <i class="bi bi-file-earmark-bar-graph me-2"></i>Evaluation Report
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link" href="settings.php">
          <i class="bi bi-gear-fill me-2"></i>Settings
        </a>
      </li>
      <li class="nav-item mt-auto" style="margin-top:auto!important;">
        <a class="nav-link" href="../index.html" target="_blank">
          <i class="bi bi-box-arrow-up-right me-2"></i>View Assessment
        </a>
      </li>
    </ul>
    <div style="padding:1rem 1.25rem;border-top:1px solid rgba(255,255,255,.1);">
      <form method="POST" action="logout.php">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-sm w-100"
                style="background:rgba(255,255,255,.1);color:#e5e7eb;border:none;border-radius:.5rem;font-weight:600;">
          Sign Out
        </button>
      </form>
    </div>
  </nav>

  <!-- ── Main Content ──────────────────────────────────── -->
  <div class="dash-main">

    <!-- Top Bar -->
    <div class="dash-topbar">
      <div>
        <span class="fw-700 text-dark" style="font-weight:700;">Assessment Results</span>
        <span class="text-muted ms-2" style="font-size:.82rem;">
          Logged in as <strong><?= h($_SESSION['admin_username']) ?></strong>
        </span>
      </div>
      <div class="d-flex gap-2">
        <form method="POST" action="logout.php" class="d-inline">
          <?= csrf_field() ?>
          <button class="btn btn-sm btn-outline-secondary">Logout</button>
        </form>
      </div>
    </div>

    <div class="p-3 p-md-4">

      <!-- ── Stats Row ─────────────────────────────────── -->
      <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
          <div class="stat-card">
            <div class="stat-icon" style="background:#fdf2f2;">
              <i class="bi bi-people-fill" style="color:#800000;"></i>
            </div>
            <div>
              <div class="stat-value"><?= number_format((int)$stats['total']) ?></div>
              <div class="stat-label">Total Respondents</div>
            </div>
          </div>
        </div>
        <?php
        $topCat = 'R'; $topAvg = 0;
        foreach (['r','i','a','s','e','c'] as $c) {
            $avg = (float)($stats["avg_$c"] ?? 0);
            if ($avg > $topAvg) { $topAvg = $avg; $topCat = strtoupper($c); }
        }
        ?>
        <div class="col-6 col-md-3">
          <div class="stat-card">
            <div class="stat-icon" style="background:<?= $CAT_COLORS[$topCat] ?>22;">
              <i class="bi bi-bar-chart-fill" style="color:<?= $CAT_COLORS[$topCat] ?>;"></i>
            </div>
            <div>
              <div class="stat-value" style="color:<?= $CAT_COLORS[$topCat] ?>;"><?= $topCat ?></div>
              <div class="stat-label">Highest Avg Category</div>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="stat-card">
            <div class="stat-icon" style="background:#fdf2f2;">
              <i class="bi bi-calendar-check" style="color:#800000;"></i>
            </div>
            <div>
              <?php
              $todayStmt = $pdo->query(
                  "SELECT COUNT(*) FROM respondents WHERE DATE(submitted_at) = CURDATE()"
              );
              $todayCount = (int)$todayStmt->fetchColumn();
              ?>
              <div class="stat-value"><?= $todayCount ?></div>
              <div class="stat-label">Submitted Today</div>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="stat-card">
            <div class="stat-icon" style="background:#fdf2f2;">
              <i class="bi bi-diagram-3-fill" style="color:#b91c1c;"></i>
            </div>
            <div>
              <?php
              $categoriesStmt = $pdo->query(
                  "SELECT COUNT(DISTINCT CASE
                      WHEN score_a >= GREATEST(score_c, score_e, score_i, score_r, score_s) THEN 'A'
                      WHEN score_c >= GREATEST(score_e, score_i, score_r, score_s) THEN 'C'
                      WHEN score_e >= GREATEST(score_i, score_r, score_s) THEN 'E'
                      WHEN score_i >= GREATEST(score_r, score_s) THEN 'I'
                      WHEN score_r >= score_s THEN 'R'
                      ELSE 'S'
                  END) FROM respondents"
              );
              $uniqueCategories = (int)$categoriesStmt->fetchColumn();
              ?>
              <div class="stat-value"><?= $uniqueCategories ?></div>
              <div class="stat-label">Main RIASEC Categories</div>
            </div>
          </div>
        </div>
      </div>

      <!-- ── Filter Bar ─────────────────────────────────── -->
      <div class="filter-bar mb-3">
        <form method="GET" action="dashboard.php" class="row g-2 align-items-end">
          <input type="hidden" name="per_page" value="<?= $perPage ?>" />
          <!-- Search -->
          <div class="col-12 col-md-3">
            <label class="form-label fw-semibold mb-1" style="font-size:.8rem;">
              <i class="bi bi-search me-1"></i>Search
            </label>
            <input type="text" name="q" class="form-control form-control-sm"
                   placeholder="Name or Student ID"
                   value="<?= h($search) ?>" />
          </div>
          <!-- Section -->
          <div class="col-6 col-md-2">
            <label class="form-label fw-semibold mb-1" style="font-size:.8rem;">
              <i class="bi bi-mortarboard me-1"></i>Section
            </label>
            <input type="text" name="section" class="form-control form-control-sm"
                   placeholder="Any section"
                   value="<?= h($section) ?>" list="section-list" />
            <datalist id="section-list">
              <?php foreach ($sections as $s): ?>
                <option value="<?= h($s) ?>">
              <?php endforeach; ?>
            </datalist>
          </div>
          <!-- Main RIASEC Category -->
          <div class="col-6 col-md-2">
            <label class="form-label fw-semibold mb-1" style="font-size:.8rem;">
              <i class="bi bi-hash me-1"></i>Main Category
            </label>
            <select name="category" class="form-select form-select-sm">
              <option value="">All categories</option>
              <?php foreach (['R'=>'Realistic','I'=>'Investigative','A'=>'Artistic','S'=>'Social','E'=>'Enterprising','C'=>'Conventional'] as $letter => $name): ?>
                <option value="<?= $letter ?>" <?= $category === $letter ? 'selected' : '' ?>>
                  <?= $letter ?> — <?= h($name) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <!-- Date From -->
          <div class="col-6 col-md-2">
            <label class="form-label fw-semibold mb-1" style="font-size:.8rem;">
              <i class="bi bi-calendar me-1"></i>From
            </label>
            <input type="date" name="from" class="form-control form-control-sm"
                   value="<?= h($from) ?>" />
          </div>
          <!-- Date To -->
          <div class="col-6 col-md-2">
            <label class="form-label fw-semibold mb-1" style="font-size:.8rem;">
              <i class="bi bi-calendar me-1"></i>To
            </label>
            <input type="date" name="to" class="form-control form-control-sm"
                   value="<?= h($to) ?>" />
          </div>
          <!-- Buttons -->
          <div class="col-12 col-md-1 d-flex gap-1">
            <button type="submit" class="btn btn-primary btn-sm flex-fill" aria-label="Search">
              <i class="bi bi-search"></i>
            </button>
            <a href="dashboard.php" class="btn btn-outline-secondary btn-sm flex-fill" title="Clear filters" aria-label="Clear filters">
              <i class="bi bi-x-lg"></i>
            </a>
          </div>
        </form>
      </div>

      <!-- ── Results Count ──────────────────────────────── -->
      <div class="d-flex align-items-center justify-content-between mb-2">
        <p class="text-muted mb-0" style="font-size:.82rem;">
          Showing
          <strong><?= number_format(min($offset + 1, $totalRows)) ?>–<?= number_format(min($offset + $perPage, $totalRows)) ?></strong>
          of <strong><?= number_format($totalRows) ?></strong> result<?= $totalRows !== 1 ? 's' : '' ?>
        </p>
      </div>

      <!-- ── Table ─────────────────────────────────────── -->
      <div class="results-table mb-4">
        <?php if (empty($rows)): ?>
          <div class="text-center py-5 text-muted">
            <i class="bi bi-inbox" style="font-size:2.5rem;"></i>
            <p class="mt-2 mb-0">No results found.</p>
            <?php if ($search || $section || $category || $from || $to): ?>
              <a href="dashboard.php" class="btn btn-sm btn-outline-secondary mt-2">Clear filters</a>
            <?php endif; ?>
          </div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead>
              <tr>
                <th>#</th>
                <th>Full Name</th>
                <th>Student ID</th>
                <th>Section</th>
                <th>Main RIASEC Category</th>
                <th>Scores (R·I·A·S·E·C)</th>
                <th>Submitted</th>
                <th class="text-center">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $i => $r):
                $rowScores = [
                  'R' => (int)$r['score_r'], 'I' => (int)$r['score_i'],
                  'A' => (int)$r['score_a'], 'S' => (int)$r['score_s'],
                  'E' => (int)$r['score_e'], 'C' => (int)$r['score_c'],
                ];
                $maxScore = max($rowScores);
                $tiedLetters = array_values(array_filter(
                  ['R', 'I', 'A', 'S', 'E', 'C'],
                  static fn (string $c): bool => $rowScores[$c] === $maxScore
                ));
                $dominantLetter = implode('', $tiedLetters);
                $dominantName = implode(', ', array_map(
                  static fn (string $c): string => $CAT_NAMES[$c],
                  $tiedLetters
                ));
              ?>
              <tr>
                <td class="text-muted"><?= $offset + $i + 1 ?></td>
                <td><span class="fw-semibold"><?= h($r['full_name']) ?></span></td>
                <td><?= h($r['student_id']) ?></td>
                <td><?= h($r['section']) ?></td>
                <td>
                  <span class="holland-badge">
                    <?= h($dominantLetter) ?> — <?= h($dominantName) ?>
                  </span>
                </td>
                <td>
                  <span class="score-mini">
                    <?php
                    foreach (['r','i','a','s','e','c'] as $c):
                        $cat = strtoupper($c);
                        $isDominant = in_array($cat, $tiedLetters, true);
                        $col = $isDominant ? $CAT_COLORS[$cat] : '#4b5563';
                    ?>
                    <span style="color:<?= $col ?>;font-weight:<?= $isDominant ? '700' : '400' ?>;"><?= (int)$r["score_$c"] ?></span><span style="color:#9ca3af;">·</span>
                    <?php endforeach; ?>
                  </span>
                </td>
                <td class="text-muted" style="white-space:nowrap;"><?= h($r['submitted_at']) ?></td>
                <td class="text-center">
                  <div class="d-flex gap-1 justify-content-center">
                    <button class="btn-detail"
                            aria-label="View details"
                            onclick="openDetail(<?= (int)$r['id'] ?>)"
                            title="View details">
                      <i class="bi bi-eye" aria-hidden="true"></i>
                    </button>
                    <button class="btn-detail"
                            aria-label="Print record"
                            title="Print record"
                            onclick="printStudent(this)"
                            data-name="<?= h($r['full_name']) ?>"
                            data-sid="<?= h($r['student_id']) ?>"
                            data-section="<?= h($r['section']) ?>"
                            data-submitted="<?= h($r['submitted_at']) ?>"
                            data-r="<?= (int)$r['score_r'] ?>"
                            data-i="<?= (int)$r['score_i'] ?>"
                            data-a="<?= (int)$r['score_a'] ?>"
                            data-s="<?= (int)$r['score_s'] ?>"
                            data-e="<?= (int)$r['score_e'] ?>"
                            data-c="<?= (int)$r['score_c'] ?>"
                            data-dominant="<?= h($dominantLetter) ?>">
                      <i class="bi bi-printer" aria-hidden="true"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-danger"
                            aria-label="Delete record"
                            onclick="confirmDelete(<?= (int)$r['id'] ?>, <?= htmlspecialchars(json_encode($r['full_name']), ENT_QUOTES, 'UTF-8') ?>)"
                            title="Delete record">
                      <i class="bi bi-trash" aria-hidden="true"></i>
                    </button>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

      <!-- ── Pagination controls (below table, left) ────── -->
      <?php if ($totalRows > 0): ?>
      <div class="d-flex align-items-center gap-3 flex-wrap mt-3">
        <div class="d-flex align-items-center gap-2">
          <label for="per-page-select" class="mb-0 text-muted" style="font-size:.8rem;">
            Rows per page
          </label>
          <select id="per-page-select" class="form-select form-select-sm" style="width:auto;"
                  aria-label="Rows per page">
            <?php foreach ([10, 25, 50, 100] as $opt): ?>
              <option value="<?= $opt ?>" <?= $perPage === $opt ? 'selected' : '' ?>><?= $opt ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <nav aria-label="Page navigation">
          <ul class="pagination pagination-sm justify-content-start flex-wrap gap-1 mb-0">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
              <a class="page-link" href="<?= h(pageUrl(max(1, $page - 1))) ?>">Previous</a>
            </li>
            <?php
            $start = max(1, $page - 2);
            $end   = min($totalPages, $page + 2);
            if ($start > 1): ?>
              <li class="page-item"><a class="page-link" href="<?= h(pageUrl(1)) ?>">1</a></li>
              <?php if ($start > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
            <?php endif; ?>

            <?php for ($p = $start; $p <= $end; $p++): ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
              <a class="page-link" href="<?= h(pageUrl($p)) ?>"><?= $p ?></a>
            </li>
            <?php endfor; ?>

            <?php if ($end < $totalPages): ?>
              <?php if ($end < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
              <li class="page-item"><a class="page-link" href="<?= h(pageUrl($totalPages)) ?>"><?= $totalPages ?></a></li>
            <?php endif; ?>

            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
              <a class="page-link" href="<?= h(pageUrl(min($totalPages, $page + 1))) ?>">Next</a>
            </li>
          </ul>
        </nav>
      </div>
      <?php endif; ?>

    </div><!-- /.p-4 -->
  </div><!-- /.dash-main -->
</div><!-- /.d-flex -->

<!-- ═══════════════════════════════════════════════════════
     Detail Modal
════════════════════════════════════════════════════════ -->
<div class="modal fade" id="detailModal" tabindex="-1" aria-labelledby="detailModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content border-0 shadow">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-700" id="detailModalLabel" style="font-weight:700;">
          <i class="bi bi-person-lines-fill me-2 text-primary"></i>Respondent Detail
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body pt-2" id="detail-body">
        <div class="text-center py-5">
          <div class="spinner-border text-primary"></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title" id="deleteModalLabel">
          <i class="bi bi-trash-fill text-danger me-2"></i>Delete Record?
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="mb-0">Delete the record for <strong id="delete-name"></strong>?
           This action cannot be undone.</p>
      </div>
      <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <form method="POST" id="delete-form" action="dashboard.php">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete" />
          <input type="hidden" name="id" id="delete-id" value="" />
          <button type="submit" class="btn btn-danger">Yes, Delete</button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     Single-student print sheet (hidden on screen)
════════════════════════════════════════════════════════ -->
<div id="student-print">
  <div id="student-print-content"></div>
</div>

<script src="../vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
/* ── Category colours (mirrors PHP) ─────────────────────── */
const CAT_COLORS = {
  R:'#b91c1c', I:'#991b1b', A:'#800000',
  S:'#7f1d1d', E:'#6b0f1a', C:'#5d1414'
};
const CAT_NAMES = {
  R:'Realistic', I:'Investigative', A:'Artistic',
  S:'Social',    E:'Enterprising',  C:'Conventional'
};
const SCORE_LABELS = {
  1:'Strongly Dislike', 2:'Dislike', 3:'Neutral',
  4:'Like', 5:'Strongly Like'
};

/* ── Open detail modal ───────────────────────────────────── */
function openDetail(id) {
  const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('detailModal'));
  document.getElementById('detail-body').innerHTML =
    '<div class="text-center py-5"><div class="spinner-border text-primary"></div></div>';
  modal.show();

  fetch(`dashboard.php?detail_id=${id}`)
    .then(r => r.json())
    .then(data => {
      if (!data.success) {
        document.getElementById('detail-body').innerHTML =
          '<p class="text-danger text-center py-4">Failed to load data.</p>';
        return;
      }
      renderDetail(data.respondent, data.answers);
    })
    .catch(() => {
      document.getElementById('detail-body').innerHTML =
        '<p class="text-danger text-center py-4">Network error. Please try again.</p>';
    });
}

function renderDetail(r, answers) {
  const scores = {
    R: +r.score_r, I: +r.score_i, A: +r.score_a,
    S: +r.score_s, E: +r.score_e, C: +r.score_c
  };
  const dominantLetters = getDominantLetters(scores);
  const dominantCode = dominantLetters.join('');
  const dominantNames = dominantLetters.map(c => CAT_NAMES[c]).join(', ');
  const dominantScore = scores[dominantLetters[0]];

  /* Score grid */
  const scoreGrid = Object.entries(scores)
    .sort((a, b) => (b[1] - a[1]) || a[0].localeCompare(b[0]))
    .map(([cat, score]) => {
      const pct  = Math.round((score / 40) * 100);
      const isDominant = dominantLetters.includes(cat);
      return `
        <div class="modal-score-cell ${isDominant ? 'is-dominant' : ''}">
          <div class="modal-score-heading">
            <span class="modal-score-name">${CAT_NAMES[cat]}</span>
            <span class="modal-score-letter">${cat}${isDominant ? ' · Main' : ''}</span>
          </div>
          <div><span class="modal-score-value">${score}</span><span class="modal-score-max"> / 40 · ${pct}%</span></div>
          <div class="modal-score-track" aria-label="${CAT_NAMES[cat]} score ${score} out of 40">
            <div class="modal-score-fill" style="width:${pct}%;"></div>
          </div>
        </div>`;
    }).join('');

  /* Answer list — grouped by category */
  const byCategory = {};
  answers.forEach(a => {
    if (!byCategory[a.category]) byCategory[a.category] = [];
    byCategory[a.category].push(a);
  });

  const answerHtml = Object.entries(byCategory).map(([cat, items]) => `
    <div class="mb-4">
      <div class="fw-semibold mb-2" style="font-size:.85rem;color:#800000;">
        ${CAT_NAMES[cat]} (${cat})
      </div>
      <div class="answer-grid">
        ${items.map(a => `
          <div class="answer-item">
            <span style="flex:1;">${escHtml(a.question_id)}</span>
            <span class="answer-score-pip">${a.score} — ${SCORE_LABELS[a.score] || a.score}</span>
          </div>`).join('')}
      </div>
    </div>`).join('');

  document.getElementById('detail-body').innerHTML = `
    <section class="modal-detail-section" aria-labelledby="student-info-heading">
      <h6 id="student-info-heading" class="modal-section-title">Student Information</h6>
      <div class="modal-personal-grid">
        <div><span class="modal-personal-label">Full Name</span><span class="modal-personal-value">${escHtml(r.full_name)}</span></div>
        <div><span class="modal-personal-label">LRN (Learner Reference Number)</span><span class="modal-personal-value">${escHtml(r.student_id)}</span></div>
        <div><span class="modal-personal-label">Section</span><span class="modal-personal-value">${escHtml(r.section)}</span></div>
        <div><span class="modal-personal-label">Assessment Submitted</span><span class="modal-personal-value">${escHtml(r.submitted_at)}</span></div>
      </div>
    </section>

    <section class="modal-detail-section" aria-labelledby="ria-sec-results-heading">
      <h6 id="ria-sec-results-heading" class="modal-section-title">RIASEC Results</h6>
      <div class="modal-main-result mb-3">
        <div class="modal-main-letter">${dominantCode}</div>
        <div>
          <div class="modal-main-caption">Highest-Scoring Category · ${dominantScore} / 40</div>
          <div class="modal-main-name">${dominantNames}</div>
        </div>
      </div>
      <div class="modal-scores-grid">${scoreGrid}</div>
    </section>

    <section class="modal-detail-section" aria-labelledby="individual-answers-heading">
      <h6 id="individual-answers-heading" class="modal-section-title">Individual Answers</h6>
      ${answerHtml}
    </section>
  `;
}

function getDominantLetters(scores) {
  return ['R', 'I', 'A', 'S', 'E', 'C'].filter(c => scores[c] === Math.max(...Object.values(scores)));
}

function escHtml(str) {
  const d = document.createElement('div');
  d.appendChild(document.createTextNode(String(str)));
  return d.innerHTML;
}

/* ── Print a single student's record ──────────────────────── */
function printStudent(btn) {
  const d = btn.dataset;
  const scores = {
    R: +d.r, I: +d.i, A: +d.a,
    S: +d.s, E: +d.e, C: +d.c
  };
  const tied = d.dominant.split('');

  const scoreRows = ['R','I','A','S','E','C'].map(c => `
    <tr class="${tied.includes(c) ? 'sp-dominant' : ''}">
      <td>${c}</td>
      <td>${CAT_NAMES[c]}${tied.includes(c) ? ' · Main' : ''}</td>
      <td>${scores[c]} / 40</td>
    </tr>`).join('');

  document.getElementById('student-print-content').innerHTML = `
    <div class="sp-header">
      <h1>RIASEC Student Record</h1>
      <p>Generated: ${escHtml(new Date().toLocaleString())}</p>
    </div>

    <h2>Student Information</h2>
    <div class="sp-grid">
      <div><span class="sp-label">Full Name</span><span class="sp-value">${escHtml(d.name)}</span></div>
      <div><span class="sp-label">LRN (Learner Reference Number)</span><span class="sp-value">${escHtml(d.sid)}</span></div>
      <div><span class="sp-label">Section</span><span class="sp-value">${escHtml(d.section)}</span></div>
      <div><span class="sp-label">Assessment Submitted</span><span class="sp-value">${escHtml(d.submitted)}</span></div>
    </div>

    <h2>RIASEC Results</h2>
    <div class="sp-main">
      <span class="sp-main-letter">${escHtml(d.dominant)}</span>
      <div>
        <span class="sp-label">Main RIASEC Category</span>
        <span class="sp-value">${tied.map(c => CAT_NAMES[c] || c).join(', ')}</span>
        <span class="sp-label" style="margin-top:1mm;">Highest Score · ${scores[tied[0]]} / 40</span>
      </div>
    </div>
    <table class="sp-scores">
      <thead>
        <tr><th>Code</th><th>Category</th><th>Score</th></tr>
      </thead>
      <tbody>${scoreRows}</tbody>
    </table>

    <div class="sp-footer">
      Printed from the RIASEC Admin Dashboard · Only this student's record is included.
    </div>
  `;

  document.body.classList.add('printing-student');
  window.print();
}

window.addEventListener('afterprint', () => {
  document.body.classList.remove('printing-student');
});

/* ── Delete confirmation ─────────────────────────────────── */
function confirmDelete(id, name) {
  document.getElementById('delete-id').value   = id;
  document.getElementById('delete-name').textContent = name;
  bootstrap.Modal.getOrCreateInstance(document.getElementById('deleteModal')).show();
}

/* ── Rows-per-page dropdown (keeps search/filters) ───────── */
(function () {
  const sel = document.getElementById('per-page-select');
  if (!sel) return;
  sel.addEventListener('change', function () {
    const params = new URLSearchParams(window.location.search);
    params.set('per_page', sel.value);
    params.set('page', '1');
    window.location.search = params.toString();
  });
})();
</script>
</body>
</html>
