<?php
/**
 * RIASEC Admin — Login Page
 * URL: admin/index.php (relative — works on XAMPP and Railway)
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Already logged in — go straight to dashboard
if (!empty($_SESSION['admin_id'])) {
    header('Location: dashboard.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';

/* ── CSRF token for login form ─────────────────────────────────────────── */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error   = '';
$success = '';

/* ── Handle POST ───────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF check
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $submittedToken)) {
        $error = 'Invalid request. Please refresh and try again.';
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $error = 'Username and password are required.';
        } else {
            $pdo  = getDB();
            $stmt = $pdo->prepare(
                'SELECT id, username, password_hash FROM admins WHERE username = :u LIMIT 1'
            );
            $stmt->execute([':u' => $username]);
            $admin = $stmt->fetch();

            if ($admin && password_verify($password, $admin['password_hash'])) {
                // Regenerate session ID on privilege escalation
                session_regenerate_id(true);

                $_SESSION['admin_id']       = $admin['id'];
                $_SESSION['admin_username'] = $admin['username'];
                // Rotate CSRF token after login
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                header('Location: dashboard.php');
                exit;
            } else {
                // Deliberate vague message — don't reveal which field was wrong
                $error = 'Invalid username or password.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Admin Login — RIASEC</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
  <link rel="stylesheet" href="../style.css" />
  <style>
    body { background: #ffffff; }
    .login-card { max-width: 420px; border-radius: 1.25rem; }
    .admin-badge { background: #800000; color: #fff; font-size: .75rem;
                   font-weight: 700; letter-spacing: .06em; text-transform: uppercase;
                   padding: .35rem .9rem; border-radius: 99px; }
  </style>
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100">

<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-12 login-card">

      <div class="text-center mb-4">
        <span class="admin-badge"><i class="bi bi-shield-lock-fill me-1"></i>Admin Portal</span>
      </div>

      <div class="card shadow-lg border-0" style="border-radius:1.25rem;">
        <div class="card-body p-4 p-md-5">

          <div class="text-center mb-4">
            <div style="width:56px;height:56px;background:#fdf2f2;border-radius:50%;
                        display:inline-flex;align-items:center;justify-content:center;
                        font-size:1.5rem;color:#800000;" class="mb-3">
              <i class="bi bi-person-lock"></i>
            </div>
            <h1 style="font-size:1.5rem;font-weight:800;color:#1f2937;">Administrator Login</h1>
            <p class="text-muted" style="font-size:.9rem;">RIASEC Career Assessment Dashboard</p>
          </div>

          <?php if ($error): ?>
          <div class="alert alert-danger alert-dismissible d-flex align-items-center gap-2 py-2" role="alert">
            <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
            <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
          <?php endif; ?>

          <form method="POST" action="index.php" novalidate>
            <?= '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') . '">' ?>

            <div class="mb-3">
              <label for="username" class="form-label fw-semibold">
                <i class="bi bi-person me-1 text-primary"></i>Username
              </label>
              <input type="text" id="username" name="username"
                     class="form-control form-control-lg"
                     value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                     autocomplete="username" required autofocus />
            </div>

            <div class="mb-4">
              <label for="password" class="form-label fw-semibold">
                <i class="bi bi-lock me-1 text-primary"></i>Password
              </label>
              <div class="input-group">
                <input type="password" id="password" name="password"
                       class="form-control form-control-lg"
                       autocomplete="current-password" required />
                <button type="button" class="btn btn-outline-secondary"
                        onclick="togglePw()" title="Show / hide password"
                        aria-label="Toggle password visibility">
                  <i class="bi bi-eye" id="pw-eye"></i>
                </button>
              </div>
            </div>

            <div class="d-grid">
              <button type="submit" class="btn btn-lg"
                      style="background:#800000;color:#fff;
                             font-weight:700;border:none;border-radius:.875rem;
                             box-shadow:0 2px 8px rgba(0,0,0,.08);">
                Sign In
              </button>
            </div>
          </form>

        </div>
      </div>

      <p class="text-center text-muted mt-3" style="font-size:.8rem;">
        <i class="bi bi-arrow-left me-1"></i>
        <a href="../index.html" class="text-muted text-decoration-none">Back to Assessment</a>
      </p>

    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function togglePw() {
  const pw  = document.getElementById('password');
  const eye = document.getElementById('pw-eye');
  if (pw.type === 'password') {
    pw.type = 'text';
    eye.className = 'bi bi-eye-slash';
  } else {
    pw.type = 'password';
    eye.className = 'bi bi-eye';
  }
}
</script>
</body>
</html>
