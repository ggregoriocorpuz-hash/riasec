<?php
/**
 * RIASEC — AI Provider Configuration
 *
 * The assistant talks to any OpenAI-compatible /chat/completions API.
 * Groq (free tier, no credit card) is the default provider; OpenAI is
 * still fully supported and is only used when it is selected.
 *
 *   Provider   1. Environment variable  RIASEC_AI_PROVIDER
 *              2. Database              app_settings.ai_provider
 *              3. Default               'groq'
 *
 *   API key    (stored per provider, so switching providers keeps every key)
 *     groq     1. GROQ_API_KEY env      2. app_settings.groq_api_key
 *     openai   1. OPENAI_API_KEY env    2. config/ai.php
 *                                    3. app_settings.openai_api_key
 *     others   1. OPENAI_API_KEY env    2. app_settings.api_key_<provider>
 *
 *   Model      1. Environment variable  OPENAI_MODEL
 *              2. Database              app_settings.model_<provider>
 *                                       (openai → openai_model)
 *              3. Provider default
 *
 *   Base URL   1. Environment variable  OPENAI_BASE_URL
 *              2. Database              app_settings.openai_base_url
 *              3. Provider default
 *
 * API keys are NEVER sent to the browser.  All provider calls happen
 * server-side in api/chat.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Load the optional config/ai.php file exactly once so its
 * OPENAI_API_KEY / OPENAI_MODEL constants become available.
 *
 * The file is gitignored and blocked by config/.htaccess, so it is
 * safe to hold a real key there — but it may also be absent.
 */
function loadLocalAIConfigFile(): void {
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $file = __DIR__ . '/ai.php';
    if (is_readable($file)) {
        require_once $file;
    }
}

/**
 * The AI provider in use: 'groq' (default), 'openai', 'openrouter',
 * 'gemini' or 'custom'.
 */
function getAIProvider(): string {
    $envProvider = getenv('RIASEC_AI_PROVIDER');
    if ($envProvider !== false && trim($envProvider) !== '') {
        $candidate = strtolower(trim($envProvider));
        if (isset(getAIProviders()[$candidate])) {
            return $candidate;
        }
    }

    $dbProvider = strtolower(trim(getSetting('ai_provider')));
    if ($dbProvider !== '' && isset(getAIProviders()[$dbProvider])) {
        return $dbProvider;
    }

    return 'groq';
}

/**
 * app_settings key holding a provider's API key.
 * OpenAI keeps its legacy names so an existing OpenAI key keeps working.
 */
function aiKeySettingName(string $provider): string {
    if ($provider === 'groq') {
        return 'groq_api_key';
    }
    if ($provider === 'openai') {
        return 'openai_api_key';
    }
    return 'api_key_' . $provider;
}

/**
 * app_settings key holding a provider's model.
 * OpenAI keeps its legacy name (openai_model).
 */
function aiModelSettingName(string $provider): string {
    if ($provider === 'openai') {
        return 'openai_model';
    }
    return 'model_' . $provider;
}

/**
 * Environment variable holding a provider's API key.
 */
function aiKeyEnvName(string $provider): string {
    return $provider === 'groq' ? 'GROQ_API_KEY' : 'OPENAI_API_KEY';
}

/**
 * Get the API key for a specific provider.
 * Returns empty string when that provider has no key.
 */
function getAIKeyForProvider(string $provider): string {
    // 1. Environment variable
    $envKey = getenv(aiKeyEnvName($provider));
    if ($envKey !== false && trim($envKey) !== '') {
        return trim($envKey);
    }

    // 2. config/ai.php constant — OpenAI keys only (legacy local file)
    if ($provider === 'openai') {
        loadLocalAIConfigFile();
        if (defined('OPENAI_API_KEY') && is_string(OPENAI_API_KEY) && trim(OPENAI_API_KEY) !== '') {
            return trim(OPENAI_API_KEY);
        }
    }

    // 3. Database — one row per provider, so nothing is lost when switching
    return getSetting(aiKeySettingName($provider));
}

/**
 * Get the API key for the active provider.
 * Returns empty string when not configured.
 */
function getOpenAIKey(): string {
    return getAIKeyForProvider(getAIProvider());
}

/**
 * Returns a short label describing where the active API key comes from:
 * 'environment variable' | 'config/ai.php' | 'database' | 'not configured'.
 * Used by Admin → Settings so the admin knows which source wins.
 */
function getOpenAIKeySource(): string {
    $provider = getAIProvider();

    $envKey = getenv(aiKeyEnvName($provider));
    if ($envKey !== false && trim($envKey) !== '') {
        return 'environment variable';
    }

    if ($provider === 'openai') {
        loadLocalAIConfigFile();
        if (defined('OPENAI_API_KEY') && is_string(OPENAI_API_KEY) && trim(OPENAI_API_KEY) !== '') {
            return 'config/ai.php';
        }
    }

    if (getSetting(aiKeySettingName($provider)) !== '') {
        return 'database';
    }

    return 'not configured';
}

/**
 * Get the model for a specific provider.
 * Falls back to the provider's documented default.
 */
function getAIModelForProvider(string $provider): string {
    // 1. Environment variable
    $envModel = getenv('OPENAI_MODEL');
    if ($envModel !== false && trim($envModel) !== '') {
        return trim($envModel);
    }

    // 2. Database — the choice made in Admin → Settings wins
    $dbModel = getSetting(aiModelSettingName($provider));
    if ($dbModel !== '') {
        return $dbModel;
    }

    // 3. config/ai.php constant — OpenAI only
    if ($provider === 'openai') {
        loadLocalAIConfigFile();
        if (defined('OPENAI_MODEL') && is_string(OPENAI_MODEL) && trim(OPENAI_MODEL) !== '') {
            return trim(OPENAI_MODEL);
        }
    }

    // 4. Provider default (e.g. openai/gpt-oss-120b for Groq)
    $providerDefault = getAIProviders()[$provider]['model'] ?? '';
    if ($providerDefault !== '') {
        return $providerDefault;
    }

    return 'gpt-4o-mini';
}

/**
 * Get the model name for the active provider.
 */
function getOpenAIModel(): string {
    return getAIModelForProvider(getAIProvider());
}

/**
 * OpenAI-compatible providers offered in Admin → Settings.
 *
 * Any service that speaks the /chat/completions protocol works
 * (key + base URL + model).  Groq is first and is the default —
 * its free plan needs no credit card.
 *
 * 'model' is the default model; 'models' are the suggestions shown in
 * Admin → Settings (the field stays free-text so new model IDs can be
 * typed as providers retire old ones).
 *
 * @return array<string, array{label: string, base_url: string, model: string, models: array<int, string>}>
 */
function getAIProviders(): array {
    return [
        'groq' => [
            'label'    => 'Groq (free — default, no card needed)',
            'base_url' => 'https://api.groq.com/openai/v1',
            'model'    => 'openai/gpt-oss-120b',
            'models'   => ['openai/gpt-oss-120b', 'openai/gpt-oss-20b', 'qwen/qwen3.8-27b'],
        ],
        'openai' => [
            'label'    => 'OpenAI (paid — needs credits)',
            'base_url' => 'https://api.openai.com/v1',
            'model'    => 'gpt-4o-mini',
            'models'   => ['gpt-4o-mini', 'gpt-4o', 'gpt-4-turbo', 'gpt-3.5-turbo'],
        ],
        'openrouter' => [
            'label'    => 'OpenRouter (free :free models)',
            'base_url' => 'https://openrouter.ai/api/v1',
            'model'    => 'deepseek/deepseek-chat-v3-0324:free',
            'models'   => ['deepseek/deepseek-chat-v3-0324:free', 'meta-llama/llama-3.3-70b-instruct:free'],
        ],
        'gemini' => [
            'label'    => 'Google Gemini (free tier)',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
            'model'    => 'gemini-2.0-flash',
            'models'   => ['gemini-2.0-flash', 'gemini-1.5-flash'],
        ],
        'custom' => [
            'label'    => 'Custom (any OpenAI-compatible endpoint)',
            'base_url' => '',
            'model'    => '',
            'models'   => [],
        ],
    ];
}

/**
 * Normalise a base URL typed by the admin.
 * Returns '' when empty or invalid (https required, http only for localhost).
 */
function normalizeAIBaseURL(string $url): string {
    $url = rtrim(trim($url), '/');
    if ($url === '') {
        return '';
    }

    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    $host   = (string) parse_url($url, PHP_URL_HOST);

    if (!in_array($scheme, ['https', 'http'], true) || $host === '') {
        return '';
    }
    if ($scheme === 'http' && !in_array($host, ['localhost', '127.0.0.1'], true)) {
        return '';
    }

    return $url;
}

/**
 * Base URL of the OpenAI-compatible endpoint (no trailing slash).
 *
 *   1. Environment variable  OPENAI_BASE_URL
 *   2. Database              app_settings.openai_base_url  (Admin → Settings)
 *   3. config/ai.php         OPENAI_BASE_URL constant
 *   4. Default of the active provider (Groq when nothing is configured)
 */
function getOpenAIBaseURL(): string {
    $envBase = getenv('OPENAI_BASE_URL');
    if ($envBase !== false && trim($envBase) !== '') {
        $normalizedEnv = normalizeAIBaseURL($envBase);
        if ($normalizedEnv !== '') {
            return $normalizedEnv;
        }
    }

    $dbBase = normalizeAIBaseURL(getSetting('openai_base_url'));
    if ($dbBase !== '') {
        return $dbBase;
    }

    loadLocalAIConfigFile();
    if (defined('OPENAI_BASE_URL') && is_string(OPENAI_BASE_URL)) {
        $fileBase = normalizeAIBaseURL(OPENAI_BASE_URL);
        if ($fileBase !== '') {
            return $fileBase;
        }
    }

    $providerBase = normalizeAIBaseURL(getAIProviders()[getAIProvider()]['base_url'] ?? '');
    if ($providerBase !== '') {
        return $providerBase;
    }

    return 'https://api.openai.com/v1';
}

/**
 * Full chat-completions URL for the active provider.
 */
function getChatCompletionsURL(): string {
    return getOpenAIBaseURL() . '/chat/completions';
}

/**
 * Returns true when an API key is available.
 */
function isAIConfigured(): bool {
    return getOpenAIKey() !== '';
}

/**
 * Save a setting to the app_settings table (upsert).
 */
function saveSetting(string $key, string $value): bool {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare(
            'INSERT INTO app_settings (setting_key, setting_value)
             VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE setting_value = :v2'
        );
        return $stmt->execute([':k' => $key, ':v' => $value, ':v2' => $value]);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Read a setting from app_settings.
 */
function getSetting(string $key): string {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare(
            'SELECT setting_value FROM app_settings WHERE setting_key = :key LIMIT 1'
        );
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch();
        return ($row && $row['setting_value'] !== null) ? $row['setting_value'] : '';
    } catch (Throwable $e) {
        return '';
    }
}
