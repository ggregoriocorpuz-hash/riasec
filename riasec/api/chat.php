<?php
/**
 * RIASEC — POST /api/chat.php
 *
 * AI chat endpoint that proxies student questions to the OpenAI API.
 *
 * The student's RIASEC results are sent as context so the AI can
 * give personalised answers.  The API key is stored server-side
 * and NEVER exposed to the browser.
 *
 * Accepts JSON body:
 * {
 *   "message":  "What does my RIASEC result mean?",
 *   "context": {
 *     "holland_code": "ISA",
 *     "top_three":   ["I","S","A"],
 *     "scores":       { "R":12, "I":35, "A":28, "S":30, "E":18, "C":15 }
 *   },
 *   "history":  [ {"role":"user","content":"..."}, {"role":"assistant","content":"..."} ],
 *   "location": "Quezon City"
 * }
 *
 * Returns JSON:
 * {
 *   "success": true,
 *   "response": "AI text response ...",
 *   "schools":  [ {school_id, school_name, ...}, ... ]   // when school search was triggered
 * }
 *
 * When the AI service is not configured:
 * { "success": false, "configured": false, "error": "..." }
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
    sendJson(['success' => false, 'error' => 'Method not allowed.'], 405);
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/ai_config.php';

// ── Read & validate JSON body ─────────────────────────────
$raw  = (string) file_get_contents('php://input');
$data = json_decode($raw, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    sendJson(['success' => false, 'error' => 'Invalid JSON payload.'], 400);
}

$message  = trim((string)($data['message']  ?? ''));
$context  = $data['context']  ?? [];
$history  = $data['history']  ?? [];
$location = trim((string)($data['location'] ?? ''));

if ($message === '') {
    sendJson(['success' => false, 'error' => 'Message is required.'], 400);
}
if (mb_strlen($message) > 2000) {
    sendJson(['success' => false, 'error' => 'Message is too long (max 2000 characters).'], 400);
}

// ── Check API key ─────────────────────────────────────────
$apiKey = getOpenAIKey();
$model  = getOpenAIModel();

if ($apiKey === '') {
    sendJson([
        'success'    => false,
        'configured'  => false,
        'error'       => 'The AI service is not yet configured. '
                       . 'An administrator must set the AI API key in '
                       . 'Admin → Settings → AI Configuration.',
    ], 503);
}

// ── Verify cURL is available ──────────────────────────────
if (!function_exists('curl_init')) {
    error_log('RIASEC chat: cURL extension not available');
    sendJson(['success' => false, 'error' => 'Server does not support outbound HTTP requests.'], 500);
}

// ── Build system prompt ───────────────────────────────────
$systemPrompt = buildSystemPrompt($context, $location);

// ── Assemble messages array ──────────────────────────────
$messages = [
    ['role' => 'system', 'content' => $systemPrompt],
];

// Add conversation history (keep last 10 turns for context)
$history = is_array($history) ? $history : [];
$history = array_slice($history, -10);
foreach ($history as $h) {
    $role    = (string)($h['role']    ?? '');
    $content = (string)($h['content'] ?? '');
    if (($role === 'user' || $role === 'assistant') && $content !== '') {
        $messages[] = ['role' => $role, 'content' => $content];
    }
}

// Current user message
$messages[] = ['role' => 'user', 'content' => $message];

// ── Define tools (function calling) ───────────────────────
$tools = [
    [
        'type' => 'function',
        'function' => [
            'name'        => 'search_schools',
            'description' => 'Search for schools, colleges, and universities — the local directory '
                           . 'plus nearby colleges looked up live by location. '
                           . 'Use this when a student asks about schools, colleges, courses, '
                           . 'programs, or institutions near them. Always use this before '
                           . 'mentioning any school — never invent school information.',
            'parameters' => [
                'type'       => 'object',
                'properties' => [
                    'query' => [
                        'type'        => 'string',
                        'description' => 'General search keyword — a school name, course name, or any term to search for.',
                    ],
                    'course' => [
                        'type'        => 'string',
                        'description' => 'A specific course or degree program to search for, e.g. "BS Psychology".',
                    ],
                    'city' => [
                        'type'        => 'string',
                        'description' => 'A city or municipality to filter schools by location, e.g. "Quezon City".',
                    ],
                ],
            ],
        ],
    ],
];

// ── Call OpenAI API ────────────────────────────────────────
$apiResponse = callOpenAI($apiKey, $model, $messages, $tools);

if (!$apiResponse['success']) {
    sendJson([
        'success' => false,
        'error'   => $apiResponse['error'] ?? 'AI service is temporarily unavailable. Please try again later.',
    ], 502);
}

$choice = $apiResponse['data']['choices'][0] ?? null;
if (!$choice) {
    sendJson(['success' => false, 'error' => 'Unexpected AI response format.'], 502);
}

// ── Handle function calls (school search) ────────────────
$schoolsFound = [];

if (isset($choice['message']['tool_calls']) && is_array($choice['message']['tool_calls'])) {
    // Append the assistant's tool-call message
    $messages[] = $choice['message'];

    foreach ($choice['message']['tool_calls'] as $toolCall) {
        $fnName = $toolCall['function']['name'] ?? '';
        $fnArgs = $toolCall['function']['arguments'] ?? '{}';
        $toolId = $toolCall['id'] ?? '';

        if ($fnName === 'search_schools') {
            $args    = json_decode($fnArgs, true) ?? [];
            $schools = searchSchoolsDB($args, $location);
            $schoolsFound = $schools;

            $messages[] = [
                'role'         => 'tool',
                'tool_call_id' => $toolId,
                'content'      => json_encode($schools, JSON_UNESCAPED_UNICODE),
            ];
        }
    }

    // Second call — let the AI generate a natural-language response with the school data
    $apiResponse2 = callOpenAI($apiKey, $model, $messages, null);

    if (!$apiResponse2['success']) {
        // Still return the schools even if the follow-up call fails
        sendJson([
            'success'  => true,
            'response' => 'I found some schools that may be relevant. Here are the results:',
            'schools'  => $schoolsFound,
        ]);
    }

    $choice = $apiResponse2['data']['choices'][0] ?? null;
    if (!$choice) {
        sendJson([
            'success'  => true,
            'response' => 'I found some schools that may be relevant. See the details below.',
            'schools'  => $schoolsFound,
        ]);
    }
}

// ── Extract final response ────────────────────────────────
$content = $choice['message']['content'] ?? '';

$response = [
    'success'  => true,
    'response' => $content,
    'schools'  => $schoolsFound,
];

sendJson($response);


/* ═══════════════════════════════════════════════════════════
   Helper Functions
═══════════════════════════════════════════════════════════ */

/**
 * Build the system prompt with the student's RIASEC context.
 *
 * When no results are supplied (the student has not completed the
 * assessment yet) a general-mode prompt is returned that explains
 * RIASEC, the test process, and career interests — and never
 * invents personalized results.
 */
function buildSystemPrompt(array $context, string $location): string {
    $hollandCode = trim((string)($context['holland_code'] ?? ''));
    $topThree    = is_array($context['top_three'] ?? null) ? $context['top_three'] : [];
    $scores      = is_array($context['scores'] ?? null) ? $context['scores'] : [];

    // ── No results yet → general mode ──────────────────────
    if ($hollandCode === '' || count($topThree) === 0) {
        return buildGeneralSystemPrompt($location);
    }

    // Category names
    $catNames = [
        'R' => 'Realistic',
        'I' => 'Investigative',
        'A' => 'Artistic',
        'S' => 'Social',
        'E' => 'Enterprising',
        'C' => 'Conventional',
    ];

    // Theoretical opposites in Holland's hexagon: R↔S, I↔E, A↔C
    $opposites = ['R' => 'S', 'I' => 'E', 'A' => 'C', 'S' => 'R', 'E' => 'I', 'C' => 'A'];

    $mainCat    = (string)($topThree[0] ?? '');
    $oppositeCat = $opposites[$mainCat] ?? '';
    $mainName    = $catNames[$mainCat]    ?? '';
    $oppositeName = $catNames[$oppositeCat] ?? '';
    $topThreeNames = array_map(fn($c) => $catNames[$c] ?? $c, $topThree);
    $topThreeStr   = implode(', ', $topThreeNames);

    // Score line
    $scoreParts = [];
    foreach (['R', 'I', 'A', 'S', 'E', 'C'] as $cat) {
        $val = (int)($scores[$cat] ?? 0);
        $scoreParts[] = "$cat=$val";
    }
    $scoreStr = implode(', ', $scoreParts);

    // Location line
    $locationLine = $location !== ''
        ? "Student's location: $location"
        : "Student's location: Not provided. If the student asks about nearby schools, ask them for their city or municipality and pass it to the search_schools function.";

    return <<<PROMPT
You are the RIASEC AI Assistant, a helpful career guidance chatbot for high school students in the Philippines who have completed the RIASEC Career Interest Assessment.

The student's RIASEC results:
- Holland Code: $hollandCode
- Top three interest areas: $topThreeStr
- Category scores (out of 40): $scoreStr
- Main (strongest) category: $mainCat — $mainName
- Opposite category: $oppositeCat — $oppositeName
$locationLine

RIASEC Categories:
- R (Realistic): Practical, hands-on work. Building, repairing, operating machinery, working outdoors. Careers: engineering, skilled trades, agriculture, technical work, architecture.
- I (Investigative): Research, analysis, problem-solving. Scientific inquiry, data analysis, understanding how things work. Careers: science, research, medicine, data analysis, software development, economics.
- A (Artistic): Creative expression, originality, imagination. Writing, art, music, design, performance. Careers: graphic design, writing, music, architecture, media, film, UX design.
- S (Social): Helping, teaching, supporting others. Counseling, guiding, mentoring, community service. Careers: education, counseling, social work, nursing, human resources, public health.
- E (Enterprising): Leadership, persuasion, initiative. Managing projects, selling ideas, organizing people. Careers: business, management, sales, law, politics, entrepreneurship, marketing.
- C (Conventional): Organization, accuracy, structure. Managing data, following procedures, keeping records. Careers: accounting, finance, administration, data management, compliance, logistics.

Guidelines — follow these strictly:
1. Help the student understand what their results mean and explore career and course options.
2. IMPORTANT: RIASEC results reflect interests, NOT abilities or intelligence. They do NOT limit career choices. Always encourage students to explore broadly and consider multiple factors (skills, values, circumstances) when choosing a path.
3. When discussing career paths, mention that interests can evolve over time.
4. When the student asks about schools, colleges, universities, or specific courses, use the search_schools function to find real schools (nearby colleges are looked up by location). ALWAYS call the function before mentioning any school.
5. NEVER invent or fabricate school names, addresses, courses, contact information, tuition fees, or admission requirements. If the search returns no results, say so honestly.
6. If school information is incomplete, clearly state which details are unavailable or unverified.
7. Be warm, encouraging, and clear. Use simple language appropriate for high school students.
8. Keep responses concise (2-4 short paragraphs) unless the student asks for more detail.
9. When listing courses or careers, use bullet points for readability.
10. If the student's location is available, mention it when recommending nearby schools. If not available and they ask about nearby schools, ask them for their city or municipality, then pass it to the search_schools function.
PROMPT;
}

/**
 * System prompt used BEFORE the student completes the assessment.
 *
 * The assistant answers general questions about RIASEC, the test
 * process, and career interests, but must never present or invent
 * personalized results, scores, or Holland codes.
 */
function buildGeneralSystemPrompt(string $location): string {
    $locationLine = $location !== ''
        ? "Student's location: $location"
        : "Student's location: Not provided.";

    return <<<PROMPT
You are the RIASEC AI Assistant, a friendly career exploration guide for high school students in the Philippines.

IMPORTANT: This student has NOT completed the RIASEC Career Interest Assessment yet. They are in general/pre-test mode.

Because no results exist yet:
- NEVER present, guess, or invent personalized results, category scores, Holland codes, or "your strongest interest is..." statements.
- If the student asks what their result is, explain that results only appear after they finish the 48-statement assessment, and invite them to take it.
- Personalized course, career, and school recommendations become available only after the assessment is completed. Say this honestly instead of guessing.

About the assessment:
- The RIASEC Career Interest Assessment is based on John Holland's six interest categories: Realistic (R), Investigative (I), Artistic (A), Social (S), Enterprising (E), and Conventional (C).
- It has 48 short statements shown one at a time. The student rates each statement from Strongly Dislike (1) to Strongly Like (5).
- There are no right or wrong answers — honesty matters more than any "correct" choice.
- It takes about 5 to 8 minutes. Scores are added per category (each category is out of 40), and the highest-scoring category becomes the main Holland code, together with the next two highest.
- The results suggest careers, college courses, and schools that match a student's interests. They are a starting point for exploration, not a test of ability.

RIASEC Categories:
- R (Realistic): Practical, hands-on work. Building, repairing, operating machinery, working outdoors. Careers: engineering, skilled trades, agriculture, technical work, architecture.
- I (Investigative): Research, analysis, problem-solving. Scientific inquiry, data analysis, understanding how things work. Careers: science, research, medicine, data analysis, software development, economics.
- A (Artistic): Creative expression, originality, imagination. Writing, art, music, design, performance. Careers: graphic design, writing, music, architecture, media, film, UX design.
- S (Social): Helping, teaching, supporting others. Counseling, guiding, mentoring, community service. Careers: education, counseling, social work, nursing, human resources, public health.
- E (Enterprising): Leadership, persuasion, initiative. Managing projects, selling ideas, organizing people. Careers: business, management, sales, law, politics, entrepreneurship, marketing.
- C (Conventional): Organization, accuracy, structure. Managing data, following procedures, keeping records. Careers: accounting, finance, administration, data management, compliance, logistics.

Guidelines — follow these strictly:
1. Answer general questions about RIASEC, how the test works, what each category means, and how interests relate to careers.
2. RIASEC reflects interests, NOT abilities or intelligence, and it never limits what a student can become.
3. When the student asks about schools, colleges, universities, or specific courses in general, you may use the search_schools function to find real schools (nearby colleges are looked up by location). ALWAYS call the function before mentioning any school.
4. NEVER invent or fabricate school names, addresses, courses, contact information, tuition fees, or admission requirements. If the search returns no results, say so honestly.
5. Be warm, encouraging, and clear. Use simple language appropriate for high school students.
6. Keep responses concise (2-4 short paragraphs) unless the student asks for more detail. Use bullet points when listing options.
7. $locationLine
PROMPT;
}

/**
 * Search the schools database — called when the AI invokes search_schools.
 */
function searchSchoolsDB(array $args, string $defaultLocation): array {
    $query  = trim((string)($args['query']  ?? ''));
    $course = trim((string)($args['course'] ?? ''));
    $city   = trim((string)($args['city']   ?? ''));

    // Fall back to the student's provided location
    if ($city === '') {
        $city = $defaultLocation;
    }

    try {
        $pdo = getDB();

        $sql    = 'SELECT * FROM schools WHERE 1=1';
        $params = [];
        $where  = [];

        if ($query !== '') {
            $where[] = '(school_name LIKE ? OR courses_offered LIKE ? OR city LIKE ? OR province LIKE ?)';
            $like = "%$query%";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if ($course !== '') {
            $where[] = 'courses_offered LIKE ?';
            $params[] = "%$course%";
        }
        if ($city !== '') {
            $where[] = '(city LIKE ? OR province LIKE ?)';
            $params[] = "%$city%";
            $params[] = "%$city%";
        }

        if (!empty($where)) {
            $sql .= ' AND ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY school_name ASC LIMIT 15';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $courses = array_filter(
                array_map('trim', explode(',', $row['courses_offered'] ?? ''))
            );
            $result[] = [
                'school_id'            => (int)$row['school_id'],
                'school_name'          => $row['school_name'],
                'school_address'       => $row['school_address'],
                'city'                 => $row['city'],
                'province'             => $row['province'],
                'contact_number'       => $row['contact_number'] ?? '',
                'email'                => $row['email'] ?? '',
                'official_website'     => $row['official_website'] ?? '',
                'courses_offered'      => $courses,
                'admission_information' => $row['admission_information'] ?? '',
            ];
        }

        if ($result) {
            return $result;
        }

        // The directory table only stores dynamically added rows, so an
        // empty result falls back to the live OpenStreetMap/Nominatim
        // search — the chat keeps recommending real nearby colleges.
        return searchSchoolsDynamically($city, $query, $course);
    } catch (Throwable $e) {
        error_log('RIASEC chat searchSchoolsDB error: ' . $e->getMessage());
        // Even if the directory is unreachable, the live location
        // search can still answer with real nearby colleges.
        return searchSchoolsDynamically($city, $query, $course);
    }
}

/**
 * Fetch a JSON endpoint with cURL (used for internal schools.php calls).
 * Returns the decoded array, or null on any failure.
 */
function schoolHttpGet(string $url, int $timeoutSeconds): ?array {
    if (!function_exists('curl_init')) return null;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeoutSeconds,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'RIASEC-Chat/1.0',
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $code !== 200) return null;
    $data = json_decode((string) $body, true);
    return is_array($data) ? $data : null;
}

/**
 * Look up nearby colleges dynamically through schools.php (geocode the
 * city, then search within 100 km) when the local directory has no
 * matching row. Maps the finder response to the chat card format.
 */
function searchSchoolsDynamically(string $city, string $query, string $course): array {
    $place = $city !== '' ? $city : $query;
    if ($place === '' || !function_exists('curl_init')) return [];

    @set_time_limit(60);

    $scriptDir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/api/chat.php')));
    $baseUrl   = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http')
        . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $scriptDir . '/schools.php';

    // 1. Resolve the city/municipality to coordinates.
    $geo = schoolHttpGet($baseUrl . '?action=geocode&q=' . urlencode($place), 15);
    if (!$geo || empty($geo['found'])) return [];

    // 2. Search colleges within 100 km of that location.
    $searchUrl = $baseUrl . '?action=search'
        . '&lat=' . rawurlencode((string) $geo['lat'])
        . '&lon=' . rawurlencode((string) $geo['lon'])
        . '&radius=100000';
    if ($course !== '') {
        $searchUrl .= '&courses=' . rawurlencode($course);
    }

    $data = schoolHttpGet($searchUrl, 40);
    if (!$data || empty($data['success']) || !is_array($data['schools'] ?? null)) return [];

    $needle = strtolower($query);
    $all    = [];
    foreach ($data['schools'] as $school) {
        if (isset($school['is_degree']) && $school['is_degree'] === false) continue;

        $matched = is_array($school['matched_courses'] ?? null) ? $school['matched_courses'] : [];
        if ($course !== '' && !$matched) continue;

        $offered = $matched;
        if (!$offered && !empty($school['courses'])) {
            $offered = array_values(array_filter(array_map('trim', explode(',', (string) $school['courses']))));
        }

        // The chat card always renders "address, city, province" — make
        // sure the two location parts are present for dynamic results.
        $city = trim((string) ($school['city'] ?? ''));
        $prov = trim((string) ($school['province'] ?? ''));
        if ($city === '' && $prov !== '') { $city = $prov; $prov = 'Philippines'; }
        elseif ($prov === '') { $prov = 'Philippines'; }
        if ($city === '') { $city = 'Philippines'; }

        $all[] = [
            'school_id'             => 0,  // dynamic lookup — not a directory row
            'school_name'           => (string) ($school['name'] ?? ''),
            'school_address'        => (string) ($school['address'] ?? ''),
            'city'                  => $city,
            'province'              => $prov,
            'contact_number'        => (string) ($school['phone'] ?? ''),
            'email'                 => (string) ($school['email'] ?? ''),
            'official_website'      => (string) ($school['website'] ?? ''),
            'courses_offered'       => $offered,
            'admission_information' => '',
        ];
        if (count($all) >= 15) break;
    }

    if ($needle === '') return $all;

    // 3. Keep the keyword matches; if the keyword was generic (e.g.
    //    "colleges"), fall back to the honest nearby list instead of
    //    reporting nothing.
    $filtered = array_values(array_filter($all, static function (array $s) use ($needle): bool {
        $haystack = strtolower($s['school_name'] . ' ' . $s['school_address'] . ' ' . $s['city']
            . ' ' . implode(' ', $s['courses_offered']));
        return strpos($haystack, $needle) !== false;
    }));
    return $filtered ?: $all;
}

/**
 * Call the active provider's Chat Completions endpoint via cURL.
 *
 * Works with any OpenAI-compatible API (Groq by default, OpenAI, ...).
 * The endpoint comes from getChatCompletionsURL().
 *
 * @param string     $apiKey   Provider API key
 * @param string     $model    Model name (e.g. openai/gpt-oss-120b)
 * @param array      $messages Messages array
 * @param array|null $tools    Tool definitions (function calling), or null to omit
 * @return array { success: bool, data?: array, error?: string }
 */
function callOpenAI(string $apiKey, string $model, array $messages, ?array $tools): array {
    $url = getChatCompletionsURL();

    $body = [
        'model'       => $model,
        'messages'    => $messages,
        'max_tokens'  => 1500,
        'temperature' => 0.7,
    ];

    if ($tools !== null) {
        $body['tools']       = $tools;
        $body['tool_choice'] = 'auto';
    }

    $post = static function (array $payload) use ($apiKey, $url): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        return ['response' => $response, 'code' => $httpCode, 'error' => $curlErr];
    };

    $result = $post($body);

    // Some models reject the tool-calling parameter — retry once without it
    // so the assistant still answers (school search then falls back to the
    // "never invent schools" rule in the system prompt).
    if ($tools !== null && $result['code'] === 400 && $result['response'] !== false) {
        unset($body['tools'], $body['tool_choice']);
        $result = $post($body);
    }

    $response = $result['response'];
    $httpCode = $result['code'];
    $curlErr  = $result['error'];

    if ($response === false) {
        return ['success' => false, 'error' => 'Unable to connect to the AI service. ' . $curlErr];
    }

    $data = json_decode($response, true);

    if ($httpCode === 429) {
        return ['success' => false, 'error' => 'The AI service is busy. Please try again in a moment.'];
    }
    if ($httpCode === 401) {
        return ['success' => false, 'error' => 'AI service authentication failed. The API key may be invalid.'];
    }
    if ($httpCode !== 200) {
        $msg = $data['error']['message'] ?? 'AI service error (HTTP ' . $httpCode . ')';
        return ['success' => false, 'error' => $msg];
    }

    return ['success' => true, 'data' => $data];
}
