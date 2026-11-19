<?php
/**
 * RIASEC Admin — Evaluation Report
 * URL: http://localhost/riasec/admin/report.php
 *
 * Displays all submitted RIASEC evaluation records in a
 * spreadsheet-style table with RIASEC type / section / date filter controls.
 * A single "Print Report" button opens the browser print dialog
 * which renders a clean landscape-oriented printout.
 */

declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/db.php';

$pdo = getDB();

/* ════════════════════════════════════════════════════════════
   Category helpers
════════════════════════════════════════════════════════════ */
$CAT_NAMES = [
    'R' => 'Realistic',    'I' => 'Investigative',
    'A' => 'Artistic',     'S' => 'Social',
    'E' => 'Enterprising', 'C' => 'Conventional',
];

$CAT_COLORS = [
    'R' => '#b91c1c', 'I' => '#991b1b', 'A' => '#800000',
    'S' => '#7f1d1d', 'E' => '#6b0f1a', 'C' => '#5d1414',
];

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/* ════════════════════════════════════════════════════════════
   Filters
════════════════════════════════════════════════════════════ */
$category = strtoupper(trim((string)($_GET['category'] ?? '')));
if (!in_array($category, ['R', 'I', 'A', 'S', 'E', 'C'], true)) {
    $category = '';
}
$section = trim((string)($_GET['section'] ?? ''));
$from    = trim((string)($_GET['from']    ?? ''));
$to      = trim((string)($_GET['to']      ?? ''));

$where  = [];
$params = [];

// Tie-inclusive: a respondent matches the category filter when their score
// for it equals their highest score (so tied categories are all included).
$scoreCols = ['R' => 'score_r', 'I' => 'score_i', 'A' => 'score_a', 'S' => 'score_s', 'E' => 'score_e', 'C' => 'score_c'];
if ($category !== '') {
    $col = $scoreCols[$category];
    $where[] = "r.$col = GREATEST(r.score_r, r.score_i, r.score_a, r.score_s, r.score_e, r.score_c)";
}
if ($section !== '') {
    $where[]            = 'r.section LIKE :section';
    $params[':section'] = '%' . $section . '%';
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

/* ════════════════════════════════════════════════════════════
   Data — all matching rows (no pagination; report shows all)
════════════════════════════════════════════════════════════ */
$dataStmt = $pdo->prepare(
    "SELECT r.id,
            r.full_name,
            r.student_id,
            r.section,
            r.score_r, r.score_i, r.score_a,
            r.score_s, r.score_e, r.score_c,
            DATE_FORMAT(r.submitted_at, '%Y-%m-%d') AS assessment_date
     FROM respondents r
     $whereSQL
     ORDER BY r.submitted_at ASC"
);
foreach ($params as $k => $v) {
    $dataStmt->bindValue($k, $v);
}
$dataStmt->execute();
$rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

$totalRows = count($rows);

/* Total (unfiltered) count for the summary line */
$totalAll = (int)$pdo->query('SELECT COUNT(*) FROM respondents')->fetchColumn();

/* Distinct sections for the filter datalist */
$sections = $pdo->query(
    'SELECT DISTINCT section FROM respondents ORDER BY section'
)->fetchAll(PDO::FETCH_COLUMN);

/* Generated-at timestamp */
$generatedAt = date('F j, Y  g:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Evaluation Report — RIASEC</title>

  <!-- Bootstrap 5 -->
  <link rel="stylesheet" href="../vendor/bootstrap/css/bootstrap.min.css" />
  <!-- Bootstrap Icons -->
  <link rel="stylesheet" href="../vendor/bootstrap-icons/font/bootstrap-icons.min.css" />

  <style>
    /* ══════════════════════════════════════════════════════
       CSS VARIABLES
    ══════════════════════════════════════════════════════ */
    :root {
      --primary:      #800000;
      --primary-dark: #5d1414;
      --text:         #1f2937;
      --muted:        #6b7280;
      --border:       #d1d5db;
      --bg:           #ffffff;
      --surface:      #ffffff;
    }

    *, *::before, *::after { box-sizing: border-box; }

    body {
      background: var(--bg);
      color: var(--text);
      font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
      font-size: .92rem;
      min-height: 100vh;
    }

    /* ══════════════════════════════════════════════════════
       SIDEBAR  (mirrors dashboard.php exactly)
    ══════════════════════════════════════════════════════ */
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

    /* ══════════════════════════════════════════════════════
       MAIN CONTENT AREA
    ══════════════════════════════════════════════════════ */
    .dash-main { flex: 1; min-width: 0; }

    .dash-topbar {
      background: #fff; border-bottom: 1px solid #e5e7eb;
      padding: .875rem 1.5rem;
      display: flex; align-items: center; justify-content: space-between;
      gap: 1rem;
      position: sticky; top: 0; z-index: 100;
    }

    .btn-print {
      background: #800000;
      color: #fff;
      border: none;
      border-radius: .625rem;
      font-weight: 700;
      font-size: .85rem;
      padding: .45rem 1.1rem;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: .4rem;
      transition: opacity .15s, transform .15s;
      text-decoration: none;
    }
    .btn-print:hover,
    .btn-print:focus-visible { opacity: 1; background: #5d1414; transform: translateY(-1px); color: #fff; }
    .btn-print:active { background: #4a1010; color: #fff; opacity: 1; }

    .btn-primary {
      --bs-btn-color: #fff;
      --bs-btn-bg: #800000;
      --bs-btn-border-color: #800000;
      --bs-btn-hover-color: #fff;
      --bs-btn-hover-bg: #5d1414;
      --bs-btn-hover-border-color: #5d1414;
      --bs-btn-active-color: #fff;
      --bs-btn-active-bg: #5d1414;
      --bs-btn-active-border-color: #5d1414;
    }
    .btn.btn-primary:hover,
    .btn.btn-primary:focus-visible {
      color: #fff !important;
      background-color: #5d1414 !important;
      border-color: #5d1414 !important;
    }
    .btn.btn-primary:active,
    .btn.btn-primary.active {
      color: #fff !important;
      background-color: #4a1010 !important;
      border-color: #4a1010 !important;
    }
    .dash-sidebar form button.btn:hover,
    .dash-sidebar form button.btn:focus-visible,
    .dash-sidebar form button.btn:active {
      color: #fff !important;
      background: rgba(255,255,255,.2) !important;
    }

    /* ── Page wrapper ─────────────────────────────────── */
    .report-wrapper {
      padding: 1.5rem 1.25rem 3rem;
    }

    /* ── Report header ────────────────────────────────── */
    .report-header-card {
      background: var(--surface);
      border-radius: 1rem;
      padding: 1.5rem 1.75rem;
      box-shadow: 0 2px 10px rgba(79,70,229,.08);
      margin-bottom: 1.25rem;
    }

    .report-title {
      font-size: 1.35rem;
      font-weight: 800;
      color: var(--text);
      margin: 0 0 .2rem;
    }

    .report-meta { font-size: .8rem; color: var(--muted); }

    .report-count-badge {
      display: inline-flex;
      align-items: center;
      gap: .35rem;
      background: #fdf2f2;
      color: var(--primary-dark);
      font-weight: 700;
      font-size: .82rem;
      padding: .3rem .85rem;
      border-radius: 99px;
    }

    /* ── Filter bar ───────────────────────────────────── */
    .filter-card {
      background: var(--surface);
      border-radius: 1rem;
      padding: 1rem 1.25rem;
      box-shadow: 0 2px 8px rgba(0,0,0,.05);
      margin-bottom: 1.25rem;
    }
    .filter-card .form-control,
    .filter-card .form-select {
      border-radius: .5rem;
      border: 1.5px solid var(--border);
      font-size: .85rem;
      transition: border-color .15s, box-shadow .15s;
    }
    .filter-card .form-control:focus,
    .filter-card .form-select:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(79,70,229,.12);
    }

    /* ── Report table ─────────────────────────────────── */
    .report-table-card {
      background: var(--surface);
      border-radius: 1rem;
      overflow: hidden;
      box-shadow: 0 2px 10px rgba(0,0,0,.06);
    }

    .report-table {
      width: 100%;
      border-collapse: collapse;
      font-size: .84rem;
    }
    .report-table thead tr { background: #800000; color: #fff; }
    .report-table thead th {
      padding: .7rem .85rem;
      font-weight: 700; font-size: .78rem;
      text-transform: uppercase; letter-spacing: .04em;
      white-space: nowrap;
      border: 1px solid rgba(255,255,255,.1);
    }
    .report-table thead th.score-col { text-align: center; }
    .report-table tbody tr:nth-child(even) { background: #f9fafb; }
    .report-table tbody tr:hover { background: #f3f0f0; }
    .report-table tbody td {
      padding: .6rem .85rem;
      border: 1px solid var(--border);
      vertical-align: middle; color: var(--text);
    }
    .report-table tbody td.score-col {
      text-align: center; font-weight: 700;
      font-variant-numeric: tabular-nums;
    }
    .report-table tbody td.num-col {
      text-align: center; color: var(--muted); font-size: .78rem;
    }

    .dominant-pill {
      display: inline-block; font-weight: 700; font-size: .78rem;
      padding: .2rem .7rem; border-radius: 99px;
      background: #fff; color: #000; white-space: nowrap;
    }

    .empty-state { padding: 3.5rem 1rem; text-align: center; color: var(--muted); }
    .empty-state i { font-size: 2.5rem; display: block; margin-bottom: .75rem; }

    /* ══════════════════════════════════════════════════════
       PRINT STYLES
    ══════════════════════════════════════════════════════ */
    @media print {
      @page {
        size: landscape;
        margin: 12mm 10mm;
      }

      /* Hide everything except the print block */
      body * { visibility: hidden; }
      #print-region, #print-region * { visibility: visible; }

      #print-region {
        position: absolute;
        inset: 0;
        padding: 0;
      }

      /* ── Hide sidebar + topbar entirely (reclaim space) ── */
      .dash-sidebar,
      .dash-topbar { display: none !important; }

      .dash-main { width: 100% !important; }

      /* Print header */
      .print-header {
        display: block !important;
        margin-bottom: 6mm;
        padding-bottom: 3mm;
        border-bottom: 2px solid #800000;
      }
      .print-header h1 {
        font-size: 14pt; font-weight: 800;
        color: #800000; margin: 0 0 1mm;
      }
      .print-header p { font-size: 8pt; color: #6b7280; margin: 0; }

      /* Table */
      .report-table {
        width: 100%; border-collapse: collapse;
        font-size: 8pt; page-break-inside: auto;
      }
      .report-table thead { display: table-header-group; }
      .report-table thead tr {
        background: #800000 !important;
        -webkit-print-color-adjust: exact; print-color-adjust: exact;
      }
      .report-table thead th {
        color: #fff !important; padding: 3mm 2.5mm; font-size: 7.5pt;
        -webkit-print-color-adjust: exact; print-color-adjust: exact;
      }
      .report-table tbody tr { page-break-inside: avoid; }
      /* Print shows every row regardless of on-screen pagination */
      .report-table tbody tr { display: table-row !important; }
      .report-table tbody tr:nth-child(even) {
        background: #f9fafb !important;
        -webkit-print-color-adjust: exact; print-color-adjust: exact;
      }
      .report-table tbody td {
        padding: 2mm 2.5mm; border: 0.5pt solid #d1d5db; color: #000;
      }

      .dominant-pill {
        background: none !important;
        color: #000 !important;
        padding: 0 2.5mm; border-radius: 99pt;
      }
      .report-table tbody td:last-child {
        background: #fff !important;
      }

      /* Hide remaining screen-only elements */
      .filter-card,
      .report-count-badge,
      .screen-only { display: none !important; }
      .print-only { display: block !important; }
    }

    @media (max-width: 767px) {
      .dash-sidebar { display: none; }
    }
  </style>
</head>
<body>

<div class="d-flex">

  <!-- ══════════════════════════════════════════════════════
       SIDEBAR  (identical layout to dashboard.php)
  ═════════════════════════════════════════════════════════ -->
  <nav class="dash-sidebar">
    <div class="brand">
      <i class="bi bi-compass me-2"></i>RIASEC Admin
    </div>
    <ul class="nav flex-column mt-3 flex-grow-1">
      <li class="nav-item">
        <a class="nav-link" href="dashboard.php">
          <i class="bi bi-grid-fill me-2"></i>Dashboard
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link" href="inquiries.php">
          <i class="bi bi-chat-dots-fill me-2"></i>Inquiries
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link active" href="report.php">
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

  <!-- ══════════════════════════════════════════════════════
       MAIN CONTENT AREA
  ═════════════════════════════════════════════════════════ -->
  <div class="dash-main">

    <!-- Top bar -->
    <div class="dash-topbar">
      <div>
        <span class="fw-700 text-dark" style="font-weight:700;">Evaluation Report</span>
        <span class="text-muted ms-2" style="font-size:.82rem;">
          Logged in as <strong><?= h($_SESSION['admin_username']) ?></strong>
        </span>
      </div>
      <div class="d-flex gap-2 align-items-center">
        <button class="btn-print" onclick="window.print()">Print Report</button>
        <a href="dashboard.php" class="btn btn-sm btn-outline-secondary">Dashboard</a>
      </div>
    </div>

    <div class="p-3 p-md-4">

      <!-- ══════════════════════════════════════════════════
           PRINT REGION  (only this area prints)
      ═════════════════════════════════════════════════════ -->
      <div id="print-region">

        <!-- Print-only document header -->
        <div class="print-header print-only" style="display:none;">
          <h1>RIASEC Evaluation Report</h1>
          <p>
            Total Submissions: <?= $totalAll ?>
            <?php if ($totalRows !== $totalAll): ?>
              &nbsp;|&nbsp; Filtered Records: <?= $totalRows ?>
            <?php endif; ?>
            &nbsp;|&nbsp; Generated: <?= h($generatedAt) ?>
          </p>
        </div>

        <!-- Report header card -->
        <div class="report-header-card screen-only">
          <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
            <div>
              <h1 class="report-title">
                <i class="bi bi-file-earmark-bar-graph-fill text-primary me-2"></i>
                RIASEC Evaluation Report
              </h1>
              <p class="report-meta mb-0">
                Generated: <?= h($generatedAt) ?>
              </p>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
              <span class="report-count-badge">
                <i class="bi bi-people-fill"></i>
                <?= $totalAll ?> Total Submission<?= $totalAll !== 1 ? 's' : '' ?>
              </span>
              <?php if ($totalRows !== $totalAll): ?>
              <span class="report-count-badge" style="background:#fdf2f2;color:#800000;">
                <i class="bi bi-funnel-fill"></i>
                <?= $totalRows ?> Filtered
              </span>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Filter bar -->
        <div class="filter-card screen-only">
          <form method="GET" action="report.php" class="row g-2 align-items-end">
            <!-- RIASEC Type -->
            <div class="col-12 col-md-4">
              <label class="form-label fw-semibold mb-1" style="font-size:.78rem;">
                <i class="bi bi-funnel me-1 text-primary"></i>RIASEC Type
              </label>
              <select name="category" class="form-select form-select-sm">
                <option value="">All RIASEC types</option>
                <?php foreach ($CAT_NAMES as $letter => $name): ?>
                  <option value="<?= $letter ?>" <?= $category === $letter ? 'selected' : '' ?>>
                    <?= $letter ?> — <?= h($name) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <!-- Section -->
            <div class="col-6 col-md-2">
              <label class="form-label fw-semibold mb-1" style="font-size:.78rem;">
                <i class="bi bi-mortarboard me-1 text-primary"></i>Section
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
            <!-- Date From -->
            <div class="col-6 col-md-2">
              <label class="form-label fw-semibold mb-1" style="font-size:.78rem;">
                <i class="bi bi-calendar me-1 text-primary"></i>From
              </label>
              <input type="date" name="from" class="form-control form-control-sm"
                     value="<?= h($from) ?>" />
            </div>
            <!-- Date To -->
            <div class="col-6 col-md-2">
              <label class="form-label fw-semibold mb-1" style="font-size:.78rem;">
                <i class="bi bi-calendar me-1 text-primary"></i>To
              </label>
              <input type="date" name="to" class="form-control form-control-sm"
                     value="<?= h($to) ?>" />
            </div>
            <!-- Buttons -->
            <div class="col-6 col-md-2 d-flex gap-2">
              <button type="submit" class="btn btn-primary btn-sm flex-fill">
                Filter
              </button>
              <a href="report.php" class="btn btn-outline-secondary btn-sm flex-fill" title="Clear filters">
                Clear
              </a>
            </div>
          </form>
        </div>

        <!-- ── Report table ────────────────────────────── -->
        <div class="report-table-card">
          <?php if (empty($rows)): ?>
            <div class="empty-state">
              <i class="bi bi-inbox text-muted"></i>
              <p class="mb-1 fw-semibold">No records found.</p>
              <?php if ($category || $section || $from || $to): ?>
                <p class="text-muted" style="font-size:.85rem;">
                  Try adjusting your filters or
                  <a href="report.php">clear all filters</a>.
                </p>
              <?php else: ?>
                <p class="text-muted" style="font-size:.85rem;">
                  No evaluations have been submitted yet.
                </p>
              <?php endif; ?>
            </div>
          <?php else: ?>
          <div class="table-responsive">
            <table class="report-table" id="report-table">
              <thead>
                <tr>
                  <th style="width:42px;">No.</th>
                  <th>Student Name</th>
                  <th>Student ID</th>
                  <th>Section</th>
                  <th style="white-space:nowrap;">Assessment Date</th>
                  <th class="score-col" title="Realistic">R</th>
                  <th class="score-col" title="Investigative">I</th>
                  <th class="score-col" title="Artistic">A</th>
                  <th class="score-col" title="Social">S</th>
                  <th class="score-col" title="Enterprising">E</th>
                  <th class="score-col" title="Conventional">C</th>
                  <th style="white-space:nowrap;">Main RIASEC Category</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $i => $r):
                  $scores = [
                    'R' => (int)$r['score_r'], 'I' => (int)$r['score_i'],
                    'A' => (int)$r['score_a'], 'S' => (int)$r['score_s'],
                    'E' => (int)$r['score_e'], 'C' => (int)$r['score_c'],
                  ];
                  $rankedMax = max($scores);
                  $tiedLetters = array_values(array_filter(
                    ['R', 'I', 'A', 'S', 'E', 'C'],
                    static fn (string $c): bool => $scores[$c] === $rankedMax
                  ));
                  $dominantLetter = implode('', $tiedLetters);
                  $dominantName   = implode(', ', array_map(
                    static fn (string $c): string => $CAT_NAMES[$c],
                    $tiedLetters
                  ));
                ?>
                <tr>
                  <td class="num-col"><?= $i + 1 ?></td>
                  <td><span style="font-weight:600;"><?= h($r['full_name']) ?></span></td>
                  <td><?= h($r['student_id']) ?></td>
                  <td><?= h($r['section']) ?></td>
                  <td style="white-space:nowrap;"><?= h($r['assessment_date']) ?></td>
                  <?php foreach (['R','I','A','S','E','C'] as $cat): ?>
                  <td class="score-col" style="color:<?= in_array($cat, $tiedLetters, true) ? $CAT_COLORS[$cat] : '#4b5563' ?>;font-weight:<?= in_array($cat, $tiedLetters, true) ? '700' : '400' ?>;">
                    <?= $scores[$cat] ?>
                  </td>
                  <?php endforeach; ?>
                  <td>
                    <span class="dominant-pill">
                      <?= h($dominantLetter) ?> — <?= h($dominantName) ?>
                    </span>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr style="background:#f9fafb;font-weight:700;font-size:.8rem;">
                  <td colspan="5" style="padding:.6rem .85rem;border:1px solid var(--border);color:var(--muted);">
                    <?= $totalRows ?> record<?= $totalRows !== 1 ? 's' : '' ?>
                    <?= ($totalRows !== $totalAll) ? "(filtered from $totalAll total)" : '' ?>
                  </td>
                  <?php
                  $totals = ['R'=>0,'I'=>0,'A'=>0,'S'=>0,'E'=>0,'C'=>0];
                  foreach ($rows as $r2) {
                      $totals['R'] += (int)$r2['score_r'];
                      $totals['I'] += (int)$r2['score_i'];
                      $totals['A'] += (int)$r2['score_a'];
                      $totals['S'] += (int)$r2['score_s'];
                      $totals['E'] += (int)$r2['score_e'];
                      $totals['C'] += (int)$r2['score_c'];
                  }
                  foreach (['R','I','A','S','E','C'] as $cat): ?>
                  <td class="score-col" style="padding:.6rem .85rem;border:1px solid var(--border);color:<?= $CAT_COLORS[$cat] ?>;">
                    <?= $totals[$cat] ?>
                  </td>
                  <?php endforeach; ?>
                  <td style="padding:.6rem .85rem;border:1px solid var(--border);
                             font-size:.75rem;color:var(--muted);">
                    Sum of scores
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>
          <?php endif; ?>
        </div><!-- /.report-table-card -->

        <!-- Table pagination controls (screen only, bottom-left) -->
        <div class="screen-only d-flex align-items-center gap-3 flex-wrap mt-3"
             id="report-pagination" style="display:none;">
          <div class="d-flex align-items-center gap-2">
            <label for="report-per-page" class="mb-0 text-muted" style="font-size:.8rem;">
              Rows per page
            </label>
            <select id="report-per-page" class="form-select form-select-sm" style="width:auto;"
                    aria-label="Rows per page">
              <option value="10">10</option>
              <option value="25" selected>25</option>
              <option value="50">50</option>
              <option value="100">100</option>
            </select>
          </div>
          <nav aria-label="Report page navigation">
            <ul class="pagination pagination-sm flex-wrap gap-1 mb-0" id="report-pagination-list"></ul>
          </nav>
        </div>

        <!-- Screen-only bottom note -->
        <p class="screen-only text-center text-muted mt-3" style="font-size:.78rem;">
          <i class="bi bi-info-circle me-1"></i>
          Use the <strong>Print Report</strong> button above to print or save as PDF.
          The printout uses landscape orientation and repeats headers on every page.
        </p>

      </div><!-- /#print-region -->

    </div><!-- /.p-3 p-md-4 -->

  </div><!-- /.dash-main -->

</div><!-- /.d-flex -->

<script src="../vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
/* ── Client-side table pagination (screen only) ───────────── */
(function () {
  const table = document.getElementById('report-table');
  const wrap  = document.getElementById('report-pagination');
  if (!table || !wrap) return;
  const rows = Array.prototype.slice.call(table.tBodies[0].rows);
  if (!rows.length) return;
  wrap.style.display = '';
  const list = document.getElementById('report-pagination-list');
  const sel  = document.getElementById('report-per-page');
  let perPage = parseInt(sel.value, 10) || 25;
  let current = 1;

  function totalPages() {
    return Math.max(1, Math.ceil(rows.length / perPage));
  }

  function items() {
    const tp = totalPages();
    const out = [];
    out.push({ label: 'Previous', page: current - 1, disabled: current <= 1 });
    const start = Math.max(1, current - 2);
    const end   = Math.min(tp, current + 2);
    if (start > 1) {
      out.push({ label: '1', page: 1 });
      if (start > 2) out.push({ label: '&hellip;', disabled: true });
    }
    for (let p = start; p <= end; p++) out.push({ label: String(p), page: p, active: p === current });
    if (end < tp) {
      if (end < tp - 1) out.push({ label: '&hellip;', disabled: true });
      out.push({ label: String(tp), page: tp });
    }
    out.push({ label: 'Next', page: current + 1, disabled: current >= tp });
    return out;
  }

  function render() {
    const tp = totalPages();
    if (current > tp) current = tp;
    rows.forEach(function (row, i) {
      row.style.display = (Math.floor(i / perPage) + 1 === current) ? '' : 'none';
    });
    list.innerHTML = items().map(function (it) {
      if (it.disabled) {
        return '<li class="page-item disabled"><span class="page-link">' + it.label + '</span></li>';
      }
      return '<li class="page-item' + (it.active ? ' active' : '') + '">' +
             '<a class="page-link" href="#" data-page="' + it.page + '">' + it.label + '</a></li>';
    }).join('');
  }

  list.addEventListener('click', function (e) {
    const a = e.target.closest('a[data-page]');
    if (!a) return;
    e.preventDefault();
    const p = parseInt(a.getAttribute('data-page'), 10);
    if (p >= 1 && p <= totalPages() && p !== current) { current = p; render(); }
  });

  sel.addEventListener('change', function () {
    perPage = parseInt(sel.value, 10) || 25;
    current = 1;
    render();
  });

  render();
})();
</script>
</body>
</html>
