<?php
/**
 * RIASEC Admin — Settings
 * URL: http://localhost/riasec/admin/settings.php
 *
 * Allows the admin to change their account password securely.
 */

declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/ai_config.php';

$pdo = getDB();

/* ════════════════════════════════════════════════════════════
   Test the provider connection with the currently saved/typed key
════════════════════════════════════════════════════════════ */
function testOpenAIConnection(string $apiKey, string $model, string $baseUrl = ''): array {
    if ($apiKey === '') {
        return [
            false,
            'No API key configured yet. Paste your provider key above (a Groq key starts with "gsk_"), '
            . 'then click Test Connection. Keys can live in Admin → Settings, in config/ai.php, '
            . 'or in an environment variable.',
        ];
    }

    if (!function_exists('curl_init')) {
        return [false, 'The PHP cURL extension is not enabled on this server, so the AI provider cannot be reached.'];
    }

    $url = rtrim($baseUrl !== '' ? $baseUrl : getOpenAIBaseURL(), '/') . '/chat/completions';
    $body = [
        'model'       => $model,
        'messages'    => [['role' => 'user', 'content' => 'Reply with the single word: OK']],
        'max_tokens'  => 5,
        'temperature' => 0,
    ];

    $startedAt = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
    $host = (string) parse_url($url, PHP_URL_HOST);

    if ($response === false) {
        return [false, 'Could not reach ' . $host . ' — ' . ($curlErr !== '' ? $curlErr : 'network error') . '.'];
    }

    $data = json_decode((string) $response, true);
    $apiMessage = is_array($data) ? (string)($data['error']['message'] ?? '') : '';

    if ($httpCode === 200) {
        $reply = is_array($data)
            ? trim((string)($data['choices'][0]['message']['content'] ?? ''))
            : '';
        return [
            true,
            'Connection OK — ' . $model . ' on ' . $host . ' replied in ' . $elapsedMs . ' ms'
            . ($reply !== '' ? ' ("' . $reply . '")' : '') . '.',
        ];
    }

    if ($httpCode === 401) {
        return [false, 'Connection failed (401) — the API key was rejected by ' . $host
            . '. Check that it is a valid key for the selected provider (Groq keys start with "gsk_", OpenAI keys with "sk-").'];
    }
    if ($httpCode === 429) {
        return [false, 'Connection failed (429) — rate limit or insufficient quota. ' . ($apiMessage !== '' ? $apiMessage : 'Check the plan limits for this provider.')];
    }
    if ($httpCode === 404) {
        return [false, 'Connection failed (404) — model "' . $model . '" was not found on ' . $host . '. Pick another model above.'];
    }

    return [false, 'Connection failed (HTTP ' . $httpCode . ') — ' . ($apiMessage !== '' ? $apiMessage : 'unexpected response.')];
}

/* ════════════════════════════════════════════════════════════
   Handle password change (POST, CSRF-protected)
════════════════════════════════════════════════════════════ */
$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $postAction = $_POST['action'] ?? 'password';

    if ($postAction === 'ai_config') {
        $providers = getAIProviders();
        $provider  = strtolower(trim((string)($_POST['ai_provider'] ?? '')));
        if (!isset($providers[$provider])) {
            $provider = 'groq';
        }

        $apiKey    = trim((string)($_POST['openai_api_key'] ?? ''));
        $model     = trim((string)($_POST['openai_model']  ?? ''));
        $baseRaw   = (string)($_POST['openai_base_url'] ?? '');
        $baseTyped = normalizeAIBaseURL($baseRaw);

        if ($provider === 'custom' && $baseTyped === '') {
            $error = 'A custom provider needs a valid API base URL (https://...).';
        } elseif ($baseRaw !== '' && $baseTyped === '') {
            $error = 'Invalid API base URL — use https:// (plain http:// is only allowed for localhost).';
        } else {
            saveSetting('ai_provider', $provider);

            if ($apiKey !== '') {
                saveSetting(aiKeySettingName($provider), $apiKey);
            }
            if ($model !== '') {
                saveSetting(aiModelSettingName($provider), $model);
            }

            // Known providers take their base URL from the provider list;
            // only a custom provider stores a URL of its own.
            saveSetting('openai_base_url', $provider === 'custom' ? $baseTyped : '');

            $providerLabel = $providers[$provider]['label'];
            if (getAIKeyForProvider($provider) !== '') {
                $success = 'AI configuration saved — ' . $providerLabel . ' is now active'
                         . ($apiKey !== '' ? ' and the API key was stored.' : '.');
            } else {
                $success = 'Settings for ' . $providerLabel . ' were saved, but NO API KEY WAS ENTERED — '
                         . 'the assistant stays disabled until you paste a key and click Save again.';
            }
        }
    } elseif ($postAction === 'test_ai') {
        // Test the values currently typed in the form.  Empty boxes fall
        // back to whatever is already saved for the selected provider.
        $providers = getAIProviders();
        $provider  = strtolower(trim((string)($_POST['ai_provider'] ?? '')));
        if (!isset($providers[$provider])) {
            $provider = getAIProvider();
        }

        $typedKey   = trim((string)($_POST['openai_api_key'] ?? ''));
        $typedModel = trim((string)($_POST['openai_model']  ?? ''));
        $typedBase  = normalizeAIBaseURL((string)($_POST['openai_base_url'] ?? ''));
        if ($typedBase === '') {
            $typedBase = normalizeAIBaseURL($providers[$provider]['base_url'] ?? '');
        }

        [$testOk, $testMsg] = testOpenAIConnection(
            $typedKey !== '' ? $typedKey : getAIKeyForProvider($provider),
            $typedModel !== '' ? $typedModel : getAIModelForProvider($provider),
            $typedBase
        );

        // A test never writes anything — say so, or the admin may assume
        // a green result already enabled the assistant.
        $testMsg .= $typedKey !== '' && $typedKey !== getAIKeyForProvider($provider)
            ? ' This key is NOT saved yet — click "Save AI Configuration" to apply it.'
            : ' Testing does not change your settings.';

        if ($testOk) {
            $success = $testMsg;
        } else {
            $error = $testMsg;
        }
    } else {
        $currentPw = (string)($_POST['current_password'] ?? '');
    $newPw     = (string)($_POST['new_password']     ?? '');
    $confirmPw = (string)($_POST['confirm_password'] ?? '');

    // 1. Validate current password
    $stmt = $pdo->prepare('SELECT password_hash FROM admins WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => (int)$_SESSION['admin_id']]);
    $admin = $stmt->fetch();

    if (!$admin || !password_verify($currentPw, $admin['password_hash'])) {
        $error = 'Current password is incorrect.';
    } elseif ($newPw === '') {
        $error = 'New password is required.';
    } elseif (strlen($newPw) < 6) {
        $error = 'New password must be at least 6 characters.';
    } elseif ($newPw !== $confirmPw) {
        $error = 'New password and confirmation do not match.';
    } elseif ($newPw === $currentPw) {
        $error = 'New password must be different from the current password.';
    } else {
        $hash = password_hash($newPw, PASSWORD_BCRYPT);
        $upd  = $pdo->prepare('UPDATE admins SET password_hash = :hash WHERE id = :id');
        $upd->execute([':hash' => $hash, ':id' => (int)$_SESSION['admin_id']]);
        $success = 'Password changed successfully.';
    }
    }
}

$aiConfigured = isAIConfigured();
$aiModel = getOpenAIModel();
$aiKeySource = getOpenAIKeySource();
$aiProviders = getAIProviders();
$aiProvider = getAIProvider();
$aiProviderLabel = $aiProviders[$aiProvider]['label'] ?? $aiProvider;
$aiBaseUrl = getOpenAIBaseURL();
$aiAllModels = [];
foreach ($aiProviders as $p) {
    foreach ($p['models'] as $m) {
        $aiAllModels[$m] = true;
    }
}
$aiAllModels = array_keys($aiAllModels);

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Settings — RIASEC Admin</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
  <link rel="stylesheet" href="../style.css" />
  <style>
    body { background: #ffffff; font-size: .92rem; }
    .dash-sidebar { width: 230px; min-height: 100vh; flex-shrink: 0;
      background: #800000; color: #e5e7eb;
      display: flex; flex-direction: column;
      position: sticky; top: 0; height: 100vh; overflow-y: auto; }
    .dash-sidebar .brand { padding: 1.5rem 1.25rem 1rem;
      font-size: 1rem; font-weight: 800; color: #fff;
      border-bottom: 1px solid rgba(255,255,255,.1); }
    .dash-sidebar .nav-link { color: #d1d5db; padding: .6rem 1.25rem;
      border-radius: .5rem; margin: .1rem .5rem;
      font-weight: 500; transition: background .15s, color .15s; }
    .dash-sidebar .nav-link:hover, .dash-sidebar .nav-link.active {
      background: rgba(255,255,255,.12); color: #fff; }
    .dash-sidebar .nav-link i { width: 20px; }
    .dash-main { flex: 1; min-width: 0; }
    .dash-topbar { background: #fff; border-bottom: 1px solid #e5e7eb;
      padding: .875rem 1.5rem;
      display: flex; align-items: center; justify-content: space-between;
      position: sticky; top: 0; z-index: 100; }
    .settings-card { background: #fff; border-radius: 1rem;
      box-shadow: 0 2px 8px rgba(0,0,0,.06); }
    .settings-card .form-control { border-radius: .5rem; border: 2px solid #e5e7eb;
      transition: border-color .2s, box-shadow .2s; }
    .settings-card .form-control:focus { border-color: #800000;
      box-shadow: 0 0 0 3px rgba(128,0,0,.12); }
    .btn-save { background: #800000; color: #fff; border: none;
      border-radius: .875rem; font-weight: 700; font-size: 1rem;
      padding: .75rem 2rem; transition: background .2s, transform .2s; }
    .btn-save:hover { background: #5d1414; transform: translateY(-1px); color: #fff; }
    .btn-test { background: #fff; color: #800000; border: 2px solid #800000;
      border-radius: .875rem; font-weight: 700; font-size: .95rem;
      padding: .7rem 2rem; transition: background .2s, transform .2s, color .2s; }
    .btn-test:hover { background: #fdf2f2; transform: translateY(-1px); color: #5d1414; }
    @media (max-width: 767px) { .dash-sidebar { display: none; } }
  </style>
</head>
<body>

<div class="d-flex">

  <!-- Sidebar -->
  <nav class="dash-sidebar">
    <div class="brand"><i class="bi bi-compass me-2"></i>RIASEC Admin</div>
    <ul class="nav flex-column mt-3 flex-grow-1">
      <li class="nav-item">
        <a class="nav-link" href="dashboard.php"><i class="bi bi-grid-fill me-2"></i>Dashboard</a>
      </li>
      <li class="nav-item">
        <a class="nav-link" href="inquiries.php"><i class="bi bi-chat-dots-fill me-2"></i>Inquiries</a>
      </li>
      <li class="nav-item">
        <a class="nav-link" href="report.php"><i class="bi bi-file-earmark-bar-graph me-2"></i>Evaluation Report</a>
      </li>
      <li class="nav-item">
        <a class="nav-link active" href="settings.php"><i class="bi bi-gear-fill me-2"></i>Settings</a>
      </li>
      <li class="nav-item mt-auto" style="margin-top:auto!important;">
        <a class="nav-link" href="../index.html" target="_blank"><i class="bi bi-box-arrow-up-right me-2"></i>View Assessment</a>
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

  <!-- Main -->
  <div class="dash-main">
    <div class="dash-topbar">
      <div>
        <span style="font-weight:700;">Settings</span>
        <span class="text-muted ms-2" style="font-size:.82rem;">
          Logged in as <strong><?= h($_SESSION['admin_username']) ?></strong>
        </span>
      </div>
      <a href="dashboard.php" class="btn btn-sm btn-outline-secondary">Dashboard</a>
    </div>

    <div class="p-3 p-md-4">
      <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-6">

          <!-- Success alert -->
          <?php if ($success): ?>
          <div class="alert d-flex align-items-center gap-2 mb-3" role="alert"
               style="background:#fdf2f2;border:1.5px solid #800000;border-radius:.875rem;color:#1f2937;">
            <i class="bi bi-check-circle-fill" style="color:#800000;font-size:1.2rem;"></i>
            <span><?= h($success) ?></span>
          </div>
          <?php endif; ?>

          <!-- Error alert -->
          <?php if ($error): ?>
          <div class="alert d-flex align-items-center gap-2 mb-3" role="alert"
               style="background:#fdf2f2;border:1.5px solid #800000;border-radius:.875rem;color:#1f2937;">
            <i class="bi bi-exclamation-triangle-fill" style="color:#800000;font-size:1.2rem;"></i>
            <span><?= h($error) ?></span>
          </div>
          <?php endif; ?>

          <div class="settings-card p-4 p-md-5">
            <div class="text-center mb-4">
              <div style="width:56px;height:56px;background:#fdf2f2;border-radius:50%;
                          display:inline-flex;align-items:center;justify-content:center;
                          font-size:1.5rem;color:#800000;" class="mb-2">
                <i class="bi bi-shield-lock-fill"></i>
              </div>
              <h2 style="font-size:1.25rem;font-weight:800;color:#1f2937;">Change Password</h2>
              <p class="text-muted mb-0" style="font-size:.88rem;">
                Enter your current password and a new password below.
              </p>
            </div>

            <form method="POST" action="settings.php" novalidate>
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="password" />

              <div class="mb-3">
                <label for="current_password" class="form-label fw-semibold" style="font-size:.88rem;">
                  <i class="bi bi-lock-fill me-1" style="color:#800000;"></i>Current Password
                </label>
                <input type="password" id="current_password" name="current_password"
                       class="form-control form-control-lg" autocomplete="current-password" required autofocus />
              </div>

              <div class="mb-3">
                <label for="new_password" class="form-label fw-semibold" style="font-size:.88rem;">
                  <i class="bi bi-key-fill me-1" style="color:#800000;"></i>New Password
                </label>
                <input type="password" id="new_password" name="new_password"
                       class="form-control form-control-lg" autocomplete="new-password"
                       required minlength="6" />
                <div class="form-text" style="font-size:.78rem;">Minimum 6 characters.</div>
              </div>

              <div class="mb-4">
                <label for="confirm_password" class="form-label fw-semibold" style="font-size:.88rem;">
                  <i class="bi bi-check2-all me-1" style="color:#800000;"></i>Confirm New Password
                </label>
                <input type="password" id="confirm_password" name="confirm_password"
                       class="form-control form-control-lg" autocomplete="new-password" required />
              </div>

              <div class="d-grid">
                <button type="submit" class="btn-save">
                  Save Changes
                </button>
              </div>
            </form>
          </div>

          <!-- ═══ AI Configuration ════════════════════════════ -->
          <div class="settings-card p-4 p-md-5 mt-4">
            <div class="text-center mb-4">
              <div style="width:56px;height:56px;background:#fdf2f2;border-radius:50%;
                          display:inline-flex;align-items:center;justify-content:center;
                          font-size:1.5rem;color:#800000;" class="mb-2">
                <i class="bi bi-robot"></i>
              </div>
              <h2 style="font-size:1.25rem;font-weight:800;color:#1f2937;">AI Configuration</h2>
              <p class="text-muted mb-0" style="font-size:.88rem;">
                Choose an AI provider — <strong>Groq (free, no card)</strong> by default — and paste its API key.
              </p>
            </div>

            <?php if ($aiConfigured): ?>
            <div class="alert d-flex align-items-center gap-2 mb-3" role="alert"
                 style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:.5rem;color:#1f2937;font-size:.85rem;">
              <i class="bi bi-check-circle-fill" style="color:#16a34a;"></i>
              <span>
                AI service is configured and ready.<br>
                <span style="font-size:.78rem;color:#6b7280;">
                  Provider: <strong><?= h($aiProviderLabel) ?></strong> ·
                  Model: <strong><?= h($aiModel) ?></strong><br>
                  Endpoint: <strong><?= h($aiBaseUrl) ?></strong><br>
                  Key source: <strong><?= h($aiKeySource) ?></strong>
                  (environment variable &gt; config/ai.php &gt; database)
                </span>
              </span>
            </div>
            <?php else: ?>
            <div class="alert d-flex align-items-center gap-2 mb-3" role="alert"
                 style="background:#fef3c7;border:1px solid #fde68a;border-radius:.5rem;color:#1f2937;font-size:.85rem;">
              <i class="bi bi-exclamation-triangle-fill" style="color:#d97706;"></i>
              <span>
                AI service is not configured. Pick a provider and paste its API key below to enable the
                chatbot — <strong>Groq's free plan needs no credit card</strong>.<br>
                <span style="font-size:.78rem;color:#92400e;">
                  Provider: <strong><?= h($aiProviderLabel) ?></strong> ·
                  Endpoint: <strong><?= h($aiBaseUrl) ?></strong><br>
                  Current key source: <strong><?= h($aiKeySource) ?></strong>
                  (searched environment variable &gt; config/ai.php &gt; database)
                </span>
              </span>
            </div>
            <?php endif; ?>

            <form method="POST" action="settings.php">
              <?= csrf_field() ?>

              <div class="mb-3" style="font-size:.8rem;background:#fdf2f2;border:1px solid #f3d2d2;
                   border-radius:.6rem;padding:.55rem .8rem;color:#5d1414;">
                <strong>How to enable the AI (3 steps):</strong>
                1) leave Provider on <strong>Groq</strong> &middot;
                2) paste your <code>gsk_</code> key &middot;
                3) click <strong>Save AI Configuration</strong>.
                <em>Test Connection only checks the key — it never saves it.</em>
                <?php if (!$aiConfigured): ?>
                <br><strong>Current status: NOT ENABLED</strong> (no key stored yet).
                <?php endif; ?>
              </div>

              <div class="mb-3">
                <label for="ai_provider" class="form-label fw-semibold" style="font-size:.88rem;">
                  <i class="bi bi-hdd-rack me-1" style="color:#800000;"></i>AI Provider
                </label>
                <select id="ai_provider" name="ai_provider" class="form-control form-control-lg">
                  <?php foreach ($aiProviders as $providerKey => $providerDef): ?>
                  <option value="<?= h($providerKey) ?>" <?= $aiProvider === $providerKey ? 'selected' : '' ?>>
                    <?= h($providerDef['label']) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
                <div class="form-text" style="font-size:.78rem;">
                  Groq (default) runs on its free plan: 30 requests/min and 1,000 requests/day, no billing setup.
                </div>
              </div>

              <div class="mb-3">
                <label for="openai_api_key" class="form-label fw-semibold" style="font-size:.88rem;">
                  <i class="bi bi-key-fill me-1" style="color:#800000;"></i>API Key
                </label>
                <input type="password" id="openai_api_key" name="openai_api_key"
                       class="form-control form-control-lg" placeholder="gsk_..."
                       autocomplete="off" />
                <div class="form-text" style="font-size:.78rem;">
                  Get it from
                  <a id="ai_key_link" href="https://console.groq.com/keys" target="_blank" rel="noopener">console.groq.com/keys</a>.
                  Keys are stored per provider in the database and never sent to the browser.
                  <?php if ($aiConfigured): ?>
                  <br><span style="color:#16a34a;"><i class="bi bi-check-circle me-1"></i>A key is already set for this provider. Enter a new key to replace it.</span>
                  <?php endif; ?>
                </div>
                <div id="ai-key-warn" class="form-text d-none" style="font-size:.78rem;color:#b45309;">
                  <i class="bi bi-exclamation-triangle-fill me-1"></i><span id="ai-key-warn-text"></span>
                </div>
              </div>

              <div class="mb-3">
                <label for="openai_base_url" class="form-label fw-semibold" style="font-size:.88rem;">
                  <i class="bi bi-link-45deg me-1" style="color:#800000;"></i>API Base URL
                </label>
                <input type="text" id="openai_base_url" name="openai_base_url"
                       class="form-control form-control-lg" value="<?= h($aiBaseUrl) ?>"
                       <?= $aiProvider === 'custom' ? '' : 'readonly tabindex="-1"' ?> />
                <div class="form-text" style="font-size:.78rem;">
                  OpenAI-compatible endpoint. Locked to the selected provider — switch to
                  <em>Custom</em> to type your own.
                </div>
              </div>

              <div class="mb-4">
                <label for="openai_model" class="form-label fw-semibold" style="font-size:.88rem;">
                  <i class="bi bi-cpu me-1" style="color:#800000;"></i>AI Model
                </label>
                <input type="text" id="openai_model" name="openai_model" list="ai_model_suggestions"
                       class="form-control form-control-lg" value="<?= h($aiModel) ?>"
                       placeholder="<?= h($aiProviders[$aiProvider]['model'] ?? '') ?>" />
                <datalist id="ai_model_suggestions">
                  <?php foreach ($aiAllModels as $modelSuggestion): ?>
                  <option value="<?= h($modelSuggestion) ?>"></option>
                  <?php endforeach; ?>
                </datalist>
                <div class="form-text" style="font-size:.78rem;">
                  Groq free-plan models: <code>openai/gpt-oss-120b</code> (default),
                  <code>openai/gpt-oss-20b</code>, <code>qwen/qwen3.8-27b</code>.
                  Model IDs change over time — type any current ID from your provider's docs.
                </div>
              </div>

              <div class="d-grid gap-2">
                <button type="submit" name="action" value="ai_config" class="btn-save">
                  Save AI Configuration
                </button>
                <button type="submit" name="action" value="test_ai" class="btn-test">
                  <i class="bi bi-lightning-charge-fill me-1"></i>Test Connection
                </button>
              </div>
              <div class="form-text mt-2" style="font-size:.76rem;">
                <i class="bi bi-info-circle me-1"></i>
                <strong>Test Connection</strong> sends a tiny 1-token request to the selected provider
                using the key typed above (or the saved one if the box is empty). Nothing is saved by testing.
              </div>
            </form>

            <hr class="my-4" />
            <p class="text-muted mb-0" style="font-size:.8rem;">
              <i class="bi bi-shield-lock me-1"></i>
              Keys are stored per provider and are never sent to the browser.
              Resolution order: the <code>GROQ_API_KEY</code> / <code>OPENAI_API_KEY</code>
              environment variable, <code>config/ai.php</code> (OpenAI only, local git-protected file),
              then the value saved here. Switching providers keeps every saved key —
              the existing OpenAI configuration still works if you switch back.
              Nearby colleges and universities are looked up automatically from OpenStreetMap
              using the student's location — there is no school list to manage here.
            </p>
          </div>

        </div>
      </div>
    </div>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
  var providers = <?= json_encode(
      array_map(
          static fn ($p) => ['base_url' => $p['base_url'], 'model' => $p['model']],
          $aiProviders
      ),
      JSON_UNESCAPED_SLASHES
  ) ?>;
  var keyUrls = {
    groq:       'https://console.groq.com/keys',
    openai:     'https://platform.openai.com/api-keys',
    openrouter: 'https://openrouter.ai/keys',
    gemini:     'https://aistudio.google.com/apikey',
    custom:     '#'
  };
  var keyPlaceholders = {
    groq: 'gsk_...',
    openai: 'sk-...',
    openrouter: 'sk-or-v1-...',
    gemini: 'AIza...',
    custom: 'provider API key'
  };

  var select    = document.getElementById('ai_provider');
  var baseInput = document.getElementById('openai_base_url');
  var modelInput = document.getElementById('openai_model');
  var keyInput  = document.getElementById('openai_api_key');
  var keyLink   = document.getElementById('ai_key_link');
  if (!select || !baseInput || !modelInput || !keyInput || !keyLink) { return; }

  var activeProvider = select.value;

  select.addEventListener('change', function () {
    var previous = providers[activeProvider] || {};
    var current  = providers[select.value] || {};

    // Base URL: locked to the provider's endpoint, editable only for Custom
    if (select.value === 'custom') {
      baseInput.readOnly = false;
      baseInput.removeAttribute('tabindex');
      baseInput.value = '';
      baseInput.focus();
    } else {
      baseInput.readOnly = true;
      baseInput.setAttribute('tabindex', '-1');
      baseInput.value = current.base_url || '';
    }

    // Model: swap the default when it was never customised
    if (!modelInput.value || modelInput.value === previous.model) {
      modelInput.value = current.model || '';
    }

    // Key link + placeholder.  The typed key is kept — clearing it would
    // silently lose a pasted key — but a warning explains which provider
    // it would be saved for.
    keyLink.href = keyUrls[select.value] || '#';
    keyInput.placeholder = keyPlaceholders[select.value] || 'provider API key';
    showKeyWarning(activeProvider, select.value);

    activeProvider = select.value;
  });

  keyInput.addEventListener('input', function () {
    if (!keyInput.value) { hideKeyWarning(); }
  });

  function optionLabel(value) {
    var opt = select.querySelector('option[value="' + value + '"]');
    return opt ? opt.textContent.trim() : value;
  }

  function showKeyWarning(fromKey, toKey) {
    if (!keyInput.value) { return; }
    var warn     = document.getElementById('ai-key-warn');
    var warnText = document.getElementById('ai-key-warn-text');
    if (!warn || !warnText) { return; }
    warnText.textContent =
      'The key in the box was entered while "' + optionLabel(fromKey) + '" was selected. ' +
      'Saving now stores it for "' + optionLabel(toKey) + '" — paste a different key first ' +
      'if that is not the provider you want.';
    warn.classList.remove('d-none');
  }

  function hideKeyWarning() {
    var warn = document.getElementById('ai-key-warn');
    if (warn) { warn.classList.add('d-none'); }
  }
})();
</script>
</body>
</html>
