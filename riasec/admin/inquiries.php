<?php
/**
 * RIASEC Admin — Student inquiries and interview requests.
 */

declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/db.php';

$pdo = getDB();
$success = '';
$error = '';
$selectedId = max(0, (int)($_GET['id'] ?? $_POST['id'] ?? 0));
$validStatuses = ['Pending', 'Accepted', 'Rescheduled'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $status = (string)($_POST['status'] ?? '');
    $message = trim((string)($_POST['teacher_message'] ?? ''));
    $scheduleInput = trim((string)($_POST['scheduled_at'] ?? ''));
    $scheduledAt = null;

    if (!in_array($status, $validStatuses, true)) {
        $error = 'Choose a valid request status.';
    } elseif ($message === '' || mb_strlen($message, 'UTF-8') > 3000) {
        $error = 'Enter a response message (maximum 3,000 characters).';
    } elseif (in_array($status, ['Accepted', 'Rescheduled'], true)) {
        $schedule = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $scheduleInput);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (!$schedule || ($dateErrors !== false && ($dateErrors['warning_count'] || $dateErrors['error_count']))
            || $schedule->format('Y-m-d\TH:i') !== $scheduleInput
            || $schedule < new DateTimeImmutable()) {
            $error = 'Set a valid future interview date and time for accepted or rescheduled requests.';
        } else {
            $scheduledAt = $schedule->format('Y-m-d H:i:s');
        }
    }

    if ($error === '') {
        try {
            $pdo->beginTransaction();
            $inquiryStmt = $pdo->prepare(
                'SELECT id, preferred_date, status FROM inquiries WHERE id = :id FOR UPDATE'
            );
            $inquiryStmt->execute([':id' => $selectedId]);
            $inquiry = $inquiryStmt->fetch();
            if (!$inquiry) {
                $pdo->rollBack();
                $error = 'The inquiry could not be found.';
            } else {
                $updateStmt = $pdo->prepare(
                    'UPDATE inquiries
                     SET status = :status, scheduled_at = :scheduled_at, teacher_message = :message
                     WHERE id = :id'
                );
                $updateStmt->execute([
                    ':status' => $status,
                    ':scheduled_at' => $scheduledAt,
                    ':message' => $message,
                    ':id' => $selectedId,
                ]);
                $historyStmt = $pdo->prepare(
                    'INSERT INTO inquiry_updates
                        (inquiry_id, actor, status, preferred_date, scheduled_at, message)
                     VALUES (:inquiry_id, \'admin\', :status, :preferred_date, :scheduled_at, :message)'
                );
                $historyStmt->execute([
                    ':inquiry_id' => $selectedId,
                    ':status' => $status,
                    ':preferred_date' => $inquiry['preferred_date'],
                    ':scheduled_at' => $scheduledAt,
                    ':message' => $message,
                ]);
                $pdo->commit();
                header('Location: inquiries.php?id=' . $selectedId . '&updated=1');
                exit;
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('RIASEC admin inquiry update error: ' . $e->getMessage());
            $error = 'The inquiry update could not be saved. Please try again.';
        }
    }
}

if (isset($_GET['updated'])) {
    $success = 'The student inquiry was updated and the response was saved.';
}

$listStmt = $pdo->query(
    'SELECT i.id, i.status, i.preferred_date, i.scheduled_at, i.updated_at,
            r.full_name, r.student_id, r.section
     FROM inquiries i
     JOIN respondents r ON r.id = i.respondent_id
     ORDER BY (i.status = \'Pending\') DESC, i.updated_at DESC'
);
$inquiries = $listStmt->fetchAll();

$selected = null;
$scores = [];
$answers = [];
$updates = [];
if ($selectedId > 0) {
    $selectedStmt = $pdo->prepare(
        'SELECT i.id, i.respondent_id, i.concern, i.preferred_date, i.status,
                i.scheduled_at, i.teacher_message, i.created_at, i.updated_at,
                r.full_name, r.student_id, r.section,
                r.score_r, r.score_i, r.score_a, r.score_s, r.score_e, r.score_c
         FROM inquiries i
         JOIN respondents r ON r.id = i.respondent_id
         WHERE i.id = :id LIMIT 1'
    );
    $selectedStmt->execute([':id' => $selectedId]);
    $selected = $selectedStmt->fetch() ?: null;
    if ($selected) {
        $scores = [
            'R' => (int)$selected['score_r'], 'I' => (int)$selected['score_i'],
            'A' => (int)$selected['score_a'], 'S' => (int)$selected['score_s'],
            'E' => (int)$selected['score_e'], 'C' => (int)$selected['score_c'],
        ];
        $answerStmt = $pdo->prepare(
            'SELECT question_id, category, score FROM answers
             WHERE respondent_id = :respondent_id ORDER BY question_id'
        );
        $answerStmt->execute([':respondent_id' => $selected['respondent_id']]);
        $answers = $answerStmt->fetchAll();

        $historyStmt = $pdo->prepare(
            'SELECT actor, status, preferred_date, scheduled_at, message, created_at
             FROM inquiry_updates WHERE inquiry_id = :id ORDER BY created_at DESC'
        );
        $historyStmt->execute([':id' => $selectedId]);
        $updates = $historyStmt->fetchAll();
    } elseif ($error === '') {
        $error = 'The selected inquiry could not be found.';
    }
}

$catNames = [
    'R' => 'Realistic', 'I' => 'Investigative', 'A' => 'Artistic',
    'S' => 'Social', 'E' => 'Enterprising', 'C' => 'Conventional',
];
$dominantLetters = [];
if ($scores) {
    $maxScore = max($scores);
    $dominantLetters = array_values(array_filter(
        ['R', 'I', 'A', 'S', 'E', 'C'],
        static fn (string $c): bool => $scores[$c] === $maxScore
    ));
}

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Inquiries — RIASEC Admin</title>
  <link rel="stylesheet" href="../vendor/bootstrap/css/bootstrap.min.css" />
  <link rel="stylesheet" href="../vendor/bootstrap-icons/font/bootstrap-icons.min.css" />
  <link rel="stylesheet" href="../style.css" />
  <style>
    body { background:#f8fafc; font-size:.92rem; }
    .dash-sidebar { width:230px; min-height:100vh; flex-shrink:0; background:#800000; color:#e5e7eb; display:flex; flex-direction:column; position:sticky; top:0; height:100vh; overflow-y:auto; }
    .dash-sidebar .brand { padding:1.5rem 1.25rem 1rem; font-size:1rem; font-weight:800; color:#fff; border-bottom:1px solid rgba(255,255,255,.1); }
    .dash-sidebar .nav-link { color:#d1d5db; padding:.6rem 1.25rem; border-radius:.5rem; margin:.1rem .5rem; font-weight:500; }
    .dash-sidebar .nav-link:hover,.dash-sidebar .nav-link.active { background:rgba(255,255,255,.12); color:#fff; }
    .dash-sidebar .nav-link i { width:20px; }
    .dash-main { flex:1; min-width:0; }
    .dash-topbar { background:#fff; border-bottom:1px solid #e5e7eb; padding:.875rem 1.5rem; display:flex; align-items:center; justify-content:space-between; position:sticky; top:0; z-index:100; }
    .panel-card { background:#fff; border:1px solid #e5e7eb; border-radius:.9rem; }
    @media (max-width:767px) { .dash-sidebar { display:none; } }
  </style>
</head>
<body>
<div class="d-flex">
  <nav class="dash-sidebar">
    <div class="brand"><i class="bi bi-compass me-2"></i>RIASEC Admin</div>
    <ul class="nav flex-column mt-3 flex-grow-1">
      <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="bi bi-grid-fill me-2"></i>Dashboard</a></li>
      <li class="nav-item"><a class="nav-link active" href="inquiries.php"><i class="bi bi-chat-dots-fill me-2"></i>Inquiries</a></li>
      <li class="nav-item"><a class="nav-link" href="report.php"><i class="bi bi-file-earmark-bar-graph me-2"></i>Evaluation Report</a></li>
      <li class="nav-item"><a class="nav-link" href="settings.php"><i class="bi bi-gear-fill me-2"></i>Settings</a></li>
      <li class="nav-item mt-auto" style="margin-top:auto!important;"><a class="nav-link" href="../index.html" target="_blank"><i class="bi bi-box-arrow-up-right me-2"></i>View Assessment</a></li>
    </ul>
    <div style="padding:1rem 1.25rem;border-top:1px solid rgba(255,255,255,.1);">
      <form method="POST" action="logout.php">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-sm w-100" style="background:rgba(255,255,255,.1);color:#e5e7eb;border:none;border-radius:.5rem;font-weight:600;">
          Sign Out
        </button>
      </form>
    </div>
  </nav>
  <main class="dash-main">
    <header class="dash-topbar">
      <div><strong>Student Inquiries</strong><span class="text-muted ms-2">Logged in as <strong><?= h((string)$_SESSION['admin_username']) ?></strong></span></div>
      <a href="dashboard.php" class="btn btn-sm btn-outline-secondary">Dashboard</a>
    </header>
    <div class="p-3 p-md-4">
      <?php if ($success !== ''): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
      <?php if ($error !== ''): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
      <div class="row g-3">
        <section class="<?= $selected ? 'col-12 col-xl-4' : 'col-12' ?>">
          <div class="panel-card p-3">
            <h1 class="h5 mb-3">Requests <span class="badge text-bg-secondary"><?= count($inquiries) ?></span></h1>
            <?php if (!$inquiries): ?>
              <p class="text-muted mb-0">No student inquiries have been submitted.</p>
            <?php else: ?>
              <div class="list-group list-group-flush">
                <?php foreach ($inquiries as $inquiry): ?>
                  <div class="list-group-item px-1 <?= (int)$inquiry['id'] === $selectedId ? 'active' : '' ?>">
                    <div class="d-flex justify-content-between gap-2 mb-1">
                      <strong><?= h($inquiry['full_name']) ?></strong>
                      <span class="badge <?= $inquiry['status'] === 'Pending' ? 'text-bg-warning' : ($inquiry['status'] === 'Rejected' ? 'text-bg-danger' : 'text-bg-success') ?>"><?= h($inquiry['status']) ?></span>
                    </div>
                    <small>LRN <?= h($inquiry['student_id']) ?> · <?= h($inquiry['section']) ?></small><br>
                    <small class="text-muted d-block mb-2">Preferred <?= h($inquiry['preferred_date']) ?> · Updated <?= h($inquiry['updated_at']) ?></small>
                    <?php if ($inquiry['status'] === 'Pending'): ?>
                      <a class="btn btn-sm btn-outline-primary"
                         href="inquiries.php?id=<?= (int)$inquiry['id'] ?>">
                        Review
                      </a>
                    <?php else: ?>
                      <a class="btn btn-sm btn-outline-secondary"
                         href="inquiries.php?id=<?= (int)$inquiry['id'] ?>">View</a>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </section>

        <?php if ($selected): ?>
        <section class="col-12 col-xl-8">
          <div class="panel-card p-3 p-md-4 mb-3">
            <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
              <div>
                <h2 class="h5 mb-1"><?= h($selected['full_name']) ?></h2>
                <div class="text-muted"><?= h($selected['student_id']) ?> · <?= h($selected['section']) ?></div>
              </div>
              <span class="badge align-self-start <?= $selected['status'] === 'Pending' ? 'text-bg-warning' : ($selected['status'] === 'Rejected' ? 'text-bg-danger' : 'text-bg-success') ?>"><?= h($selected['status']) ?></span>
            </div>
            <div class="mb-3"><strong>Student's question or concern</strong><p class="mt-1 mb-0"><?= nl2br(h($selected['concern'])) ?></p></div>
            <p><strong>Preferred interview date:</strong> <?= h($selected['preferred_date']) ?></p>
            <hr>
            <h3 class="h6 fw-bold">RIASEC Results</h3>
            <div class="d-flex flex-wrap gap-2 mb-3">
              <?php foreach ($scores as $category => $score): ?>
                <div class="border rounded p-2 text-center <?= in_array($category, $dominantLetters, true) ? 'border-2 border-danger fw-bold' : '' ?>">
                  <div><?= h($category) ?><?= in_array($category, $dominantLetters, true) ? ' · Main' : '' ?></div>
                  <div><?= $score ?>/40</div>
                  <small><?= h($catNames[$category]) ?></small>
                </div>
              <?php endforeach; ?>
            </div>
            <details class="mb-3">
              <summary class="fw-semibold">Review individual answers (<?= count($answers) ?>)</summary>
              <div class="table-responsive mt-2">
                <table class="table table-sm" id="answers-table">
                  <thead><tr><th style="width:44px;">#</th><th>Question</th><th>Category</th><th>Score</th></tr></thead>
                  <tbody>
                    <?php foreach ($answers as $i => $answer): ?>
                      <tr><td><?= $i + 1 ?></td><td><?= h($answer['question_id']) ?></td><td><?= h($catNames[$answer['category']] ?? $answer['category']) ?></td><td><?= (int)$answer['score'] ?></td></tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <div class="d-flex align-items-center gap-3 flex-wrap mt-2"
                   id="answers-pagination" style="display:none;">
                <div class="d-flex align-items-center gap-2">
                  <label for="answers-per-page" class="mb-0 text-muted" style="font-size:.8rem;">
                    Rows per page
                  </label>
                  <select id="answers-per-page" class="form-select form-select-sm" style="width:auto;"
                          aria-label="Rows per page">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                  </select>
                </div>
                <nav aria-label="Answers page navigation">
                  <ul class="pagination pagination-sm flex-wrap gap-1 mb-0" id="answers-pagination-list"></ul>
                </nav>
              </div>
            </details>
          </div>

          <div class="panel-card p-3 p-md-4 mb-3">
            <h3 class="h6 fw-bold">Interview and Teacher Response</h3>
            <form method="POST" action="inquiries.php?id=<?= (int)$selected['id'] ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int)$selected['id'] ?>">
              <div class="row g-3">
                <div class="col-12 col-md-5">
                  <label for="inquiry-status" class="form-label fw-semibold">Interview status</label>
                  <select id="inquiry-status" name="status" class="form-select" required>
                    <?php if (!in_array($selected['status'], $validStatuses, true)): ?>
                      <option value="" selected disabled>Select a status</option>
                    <?php endif; ?>
                    <?php foreach ($validStatuses as $status): ?>
                      <option value="<?= h($status) ?>" <?= $selected['status'] === $status ? 'selected' : '' ?>><?= h($status) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-12 col-md-7">
                  <label for="scheduled-at" class="form-label fw-semibold">Confirmed interview date and time</label>
                  <input type="datetime-local" id="scheduled-at" name="scheduled_at" class="form-control"
                         value="<?= $selected['scheduled_at'] ? h(date('Y-m-d\TH:i', strtotime($selected['scheduled_at']))) : '' ?>">
                  <div class="form-text">Required when accepting or rescheduling.</div>
                </div>
                <div class="col-12">
                  <label for="teacher-message" class="form-label fw-semibold">Message or explanation for the student</label>
                  <textarea id="teacher-message" name="teacher_message" class="form-control" rows="4" maxlength="3000" required><?= h((string)($selected['teacher_message'] ?? '')) ?></textarea>
                </div>
                <div class="col-12"><button class="btn btn-danger" type="submit">Save Response and Notify Student</button></div>
              </div>
            </form>
          </div>

          <div class="panel-card p-3 p-md-4">
            <h3 class="h6 fw-bold">Update History</h3>
            <?php if (!$updates): ?>
              <p class="text-muted mb-0">No updates recorded.</p>
            <?php else: ?>
              <ul class="list-group list-group-flush">
                <?php foreach ($updates as $update): ?>
                  <li class="list-group-item px-0">
                    <strong><?= h($update['status']) ?></strong> · <?= h($update['created_at']) ?>
                    <span class="text-muted">(<?= $update['actor'] === 'admin' ? 'Teacher/Admin' : 'Student' ?>)</span>
                    <?php if ($update['scheduled_at']): ?><div>Interview: <?= h($update['scheduled_at']) ?></div><?php endif; ?>
                    <div><?= nl2br(h($update['message'])) ?></div>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        </section>
        <?php endif; ?>
      </div>
    </div>
  </main>
</div>
<script>
/* ── Client-side table pagination ─────────────────────────── */
(function () {
  const table = document.getElementById('answers-table');
  const wrap  = document.getElementById('answers-pagination');
  if (!table || !wrap) return;
  const rows = Array.prototype.slice.call(table.tBodies[0].rows);
  if (!rows.length) return;
  wrap.style.display = '';
  const list = document.getElementById('answers-pagination-list');
  const sel  = document.getElementById('answers-per-page');
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
