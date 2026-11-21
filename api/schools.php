<?php
/**
 * RIASEC — /api/schools.php
 *
 * Supports both GET and POST:
 *
 * GET actions (school finder on the results page):
 *   action=search&lat=X&lon=Y&radius=Z&courses=A|B|C
 *     → Searches OpenStreetMap Overpass API for nearby colleges and
 *       universities (plus any dynamically added directory rows), reads
 *       each school's actual course offerings from its tags or website,
 *       matches them against the student's recommended RIASEC courses,
 *       and returns ONLY institutions that offer at least one matching
 *       program, sorted by distance. total_in_area reports how many
 *       colleges were found in the area before program filtering so the
 *       page can explain an empty result honestly. Elementary and high
 *       schools are never returned. Returns 503 only when every source
 *       fails and the local database has no row, so the page can offer a
 *       retry instead of "none found".
 *   action=geocode&q=PlaceName[&details=1]
 *     → Proxies Nominatim geocoding to resolve a city/municipality
 *       name to latitude/longitude. With details=1 the response also
 *       reports kind ("province" for regions/provinces — OSM admin
 *       levels 3–4 — otherwise "place") and, for provinces, the list
 *       of cities/municipalities inside it (via Wikidata) so the page
 *       can offer a city dropdown before searching.
 *
 * POST actions (AI chat assistant, JSON body):
 *   action=search {query, course, city, latitude, longitude}
 *     → Searches local schools database by keyword/course/city.
 *   action=detail {school_id}
 *     → Returns a single school's full details from the database.
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

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST'], true)) {
    sendJson(['success' => false, 'error' => 'Method not allowed.'], 405);
}

require_once __DIR__ . '/../config/db.php';

// ── Determine action and parameters ──────────────────────
if ($method === 'GET') {
    $action = trim((string)($_GET['action'] ?? 'search'));
    $params = $_GET;
} else {
    $raw  = (string) file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        sendJson(['success' => false, 'error' => 'Invalid JSON payload.'], 400);
    }
    $action = trim((string)($data['action'] ?? 'search'));
    $params = $data;
}

/* ═══════════════════════════════════════════════════════════
   GEOCODE — proxy Nominatim to resolve a city or province name.
   With details=1 the answer also says whether the query is a
   province/region (kind=province) and lists its cities so the
   page can show a city dropdown before searching.
═══════════════════════════════════════════════════════════ */
if ($action === 'geocode') {
    $q = trim((string)($params['q'] ?? ''));
    if ($q === '') {
        sendJson(['success' => false, 'error' => 'Query is required.'], 400);
    }

    if (!function_exists('curl_init')) {
        sendJson(['success' => false, 'error' => 'Server cannot make outbound requests.'], 500);
    }

    $details = (string)($params['details'] ?? '') === '1';
    $url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&q=' . urlencode($q)
        . ($details ? '&extratags=1&addressdetails=1' : '');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'User-Agent: RIASEC-School-Finder/1.0',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        sendJson(['success' => false, 'error' => 'Geocoding service unavailable.'], 502);
    }

    $results = json_decode($response, true);
    if (!is_array($results) || empty($results)) {
        sendJson(['success' => true, 'found' => false]);
    }

    $r = $results[0];
    $out = [
        'success' => true,
        'found'   => true,
        'lat'     => (float)$r['lat'],
        'lon'     => (float)$r['lon'],
        'label'   => $r['display_name'] ?? $q,
    ];

    if ($details) {
        // OSM Philippines: regions are admin_level 3, provinces 4,
        // cities/municipalities 6 — levels 3–4 need a city choice.
        $adminLevel = trim((string)($r['extratags']['admin_level'] ?? ''));
        $isArea = in_array($adminLevel, ['3', '4'], true);

        $out['admin_level'] = $adminLevel;
        $out['kind']        = $isArea ? 'province' : 'place';

        if ($isArea) {
            $parts     = explode(', ', (string)($r['display_name'] ?? $q));
            $areaName  = ($parts[0] ?? '') !== '' ? $parts[0] : $q;
            $out['province'] = $areaName;
            $cities = wikidataCitiesInArea($areaName);
            if (!$cities && $areaName !== $q) {
                $cities = wikidataCitiesInArea($q);   // retry with the typed text
            }
            $out['cities'] = $cities;
        }
    }

    sendJson($out);
}

/* ── Wikidata helpers: list the cities inside a province ──── */

/**
 * Resolve a place name to a Wikidata QID (best match first:
 * exact label whose description mentions province/region).
 */
function wikidataFindQid(string $name): ?string {
    if ($name === '') return null;

    $url = 'https://www.wikidata.org/w/api.php?action=wbsearchentities&search='
        . rawurlencode($name) . '&language=en&format=json&limit=8';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'User-Agent: RIASEC-School-Finder/1.0',
        ],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $code !== 200) return null;
    $j = json_decode((string) $body, true);
    if (!is_array($j) || empty($j['search']) || !is_array($j['search'])) return null;

    $query    = strtolower($name);
    $fallback = null;
    foreach ($j['search'] as $s) {
        $label = strtolower((string)($s['label'] ?? ''));
        $desc  = strtolower((string)($s['description'] ?? ''));
        if ($label === $query && (strpos($desc, 'province') !== false || strpos($desc, 'region') !== false)) {
            return (string) $s['id'];
        }
        if ($label === $query && $fallback === null) {
            $fallback = (string) $s['id'];
        }
    }
    return $fallback ?? (string) $j['search'][0]['id'];
}

/**
 * List the cities and municipalities inside a province or region
 * (Wikidata: entities located in the area whose class is a
 * municipality/city/town). Returns [{name, lat, lon}] sorted
 * alphabetically; [] when the lookup is unavailable.
 */
function wikidataCitiesInArea(string $areaName): array {
    $qid = wikidataFindQid($areaName);
    if ($qid === null || !preg_match('/^Q[1-9][0-9]*$/', $qid)) return [];

    $sparql = 'SELECT ?item ?itemLabel ?coord WHERE {'
        . '  ?item wdt:P131+ wd:' . $qid . ' .'
        . '  ?item wdt:P625 ?coord .'
        . '  ?item wdt:P31 ?type .'
        . '  ?type rdfs:label ?tl .'
        . '  FILTER(LANG(?tl) = "en")'
        . '  FILTER(CONTAINS(LCASE(?tl), "municipality") || CONTAINS(LCASE(?tl), "city") || LCASE(?tl) = "town")'
        . '  FILTER(!(CONTAINS(LCASE(?tl), "barangay")))'
        . '  ?item rdfs:label ?itemLabel .'
        . '  FILTER(LANG(?itemLabel) = "en")'
        . '} ORDER BY ?itemLabel LIMIT 100';

    $url = 'https://query.wikidata.org/sparql?format=json&query=' . rawurlencode($sparql);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/sparql-results+json',
            'User-Agent: RIASEC-School-Finder/1.0',
        ],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $code !== 200) return [];
    $j = json_decode((string) $body, true);
    if (!is_array($j) || !isset($j['results']['bindings']) || !is_array($j['results']['bindings'])) return [];

    $cities = [];
    foreach ($j['results']['bindings'] as $row) {
        $name = trim((string)($row['itemLabel']['value'] ?? ''));
        $coord = (string)($row['coord']['value'] ?? '');
        if ($name === '') continue;
        if (!preg_match('/Point\(\s*([-\d.]+)\s+([-\d.]+)\s*\)/', $coord, $m)) continue;
        $cities[] = [
            'name' => $name,
            'lat'  => (float) $m[2],
            'lon'  => (float) $m[1],
        ];
    }
    return $cities;
}

/* ═══════════════════════════════════════════════════════════
   DETAIL — return a single school by ID (POST, AI chat)
═══════════════════════════════════════════════════════════ */
if ($action === 'detail') {
    $schoolId = (int)($params['school_id'] ?? 0);
    if ($schoolId <= 0) {
        sendJson(['success' => false, 'error' => 'Invalid school ID.'], 400);
    }

    try {
        $pdo = getDB();
        $stmt = $pdo->prepare('SELECT * FROM schools WHERE school_id = :id LIMIT 1');
        $stmt->execute([':id' => $schoolId]);
        $school = $stmt->fetch();

        if (!$school) {
            sendJson(['success' => false, 'error' => 'School not found.'], 404);
        }

        sendJson(['success' => true, 'school' => formatSchoolDB($school)]);
    } catch (Throwable $e) {
        error_log('RIASEC schools detail error: ' . $e->getMessage());
        sendJson(['success' => false, 'error' => 'Failed to retrieve school.'], 500);
    }
}

/* ═══════════════════════════════════════════════════════════
   SEARCH (GET) — school finder: Overpass API + local DB
═══════════════════════════════════════════════════════════ */
if ($action === 'search' && $method === 'GET') {
    $lat     = (float)($params['lat'] ?? 0);
    $lon     = (float)($params['lon'] ?? 0);
    $radius  = (int)($params['radius'] ?? 100000);   // default: 100 km
    $coursesStr = (string)($params['courses'] ?? '');
    $courses = $coursesStr !== ''
        ? array_values(array_filter(array_map('trim', explode('|', $coursesStr)), static fn (string $c): bool => $c !== ''))
        : [];

    if ($lat == 0 || $lon == 0) {
        sendJson(['success' => false, 'error' => 'Location coordinates are required.'], 400);
    }

    $allSchools = [];
    $seenNames  = [];  // deduplicate by lowercased name

    /* ── 1. Local database schools within radius ─────────── */
    try {
        $pdo = getDB();
        $stmt = $pdo->query('SELECT * FROM schools ORDER BY school_name ASC');
        $dbRows = $stmt->fetchAll();

        foreach ($dbRows as $row) {
            if (!isCollegeOrUniversity((string)$row['school_name'])) continue;
            if ($row['latitude'] !== null && $row['longitude'] !== null) {
                $distMeters = haversine($lat, $lon, (float)$row['latitude'], (float)$row['longitude']) * 1000;
                if ($distMeters <= $radius) {
                    $matched = matchCoursesFromDB($row['courses_offered'] ?? '', $courses);
                    $nameKey = strtolower(trim($row['school_name']));
                    $seenNames[$nameKey] = true;
                    $allSchools[] = formatSchoolFromDB($row, (int) round($distMeters), $matched);
                }
            }
        }
    } catch (Throwable $e) {
        // DB unavailable — continue with OSM only
    }

    /* ── 2. OpenStreetMap Overpass API search ────────────── */
    $osmSchools    = searchOverpass($lat, $lon, $radius);
    $overpassFailed = $osmSchools === null;
    $osmSchools    = $osmSchools ?? [];

    /* ── 2b. Nominatim place search — fallback used when Overpass is
          unreachable or found nothing, so the locator keeps working ── */
    $poiResult = null;
    if (count($osmSchools) === 0) {
        // null = the lookup itself failed; [] = the area really has none.
        $poiResult = searchNominatimPOI($lat, $lon, $radius);
    }

    if ($overpassFailed && $poiResult === null && empty($allSchools)) {
        sendJson([
            'success' => false,
            'error'   => 'The school directory is temporarily unavailable. Please try again in a few seconds.',
        ], 503);
    }
    $poiSchools = $poiResult ?? [];

    foreach ($osmSchools as $osm) {
        if (!isCollegeOrUniversity($osm['name'], $osm['tags']['amenity'] ?? '')) continue;

        $nameKey = strtolower(trim($osm['name']));
        if (isset($seenNames[$nameKey])) continue;  // skip duplicates
        $seenNames[$nameKey] = true;

        $distMeters = (int) round(haversine($lat, $lon, $osm['lat'], $osm['lon']) * 1000);
        $matched = matchCoursesByName($osm['name'], $courses, $osm['tags']);
        $allSchools[] = formatSchoolFromOSM($osm, $distMeters, $matched);
    }

    /* ── 2c. Merge the Nominatim fallback results ────────── */
    foreach ($poiSchools as $poi) {
        $nameKey = strtolower(trim($poi['name']));
        if (isset($seenNames[$nameKey])) continue;  // skip duplicates
        $seenNames[$nameKey] = true;

        $distMeters = (int) round(haversine($lat, $lon, $poi['lat'], $poi['lon']) * 1000);
        // namedetails (official_name, alt_name, short_name) plus extratags
        // (subjects, courses, description when the mapper added them) give
        // the matcher the widest honest view of the school's programs.
        $matchTags = array_merge($poi['row']['namedetails'] ?? [], $poi['row']['extratags'] ?? []);
        $matched = matchCoursesByName($poi['name'], $courses, $matchTags);
        $allSchools[] = formatSchoolFromNominatim($poi['row'], $poi['name'], $distMeters, $matched);
    }

    /* ── 3. How many colleges exist in the area (before program
          filtering) — reported so the page can explain an empty
          result as "none offer your programs" rather than "none found" ── */
    $totalInArea = count($allSchools);

    /* ── 4. Read each school's actual course offerings from its
          website when the directory/tags carry no program list ── */
    if ($courses) {
        scanSchoolWebsites($allSchools, $courses);
    }

    /* ── 5. Keep only institutions that offer a recommended program ── */
    if ($courses) {
        $allSchools = array_values(array_filter(
            $allSchools,
            static fn (array $school): bool => !empty($school['matched_courses'])
        ));
    }

    /* ── 6. Flag institutions that offer a recommended program ── */
    foreach ($allSchools as &$school) {
        $school['program_match'] = !empty($school['matched_courses']);
    }
    unset($school);

    /* ── 7. Sort: nearest first ── */
    usort($allSchools, function ($a, $b) {
        return ($a['distance'] ?? PHP_INT_MAX) <=> ($b['distance'] ?? PHP_INT_MAX);
    });
    $allSchools = array_slice($allSchools, 0, 30);

    sendJson(['success' => true, 'schools' => $allSchools, 'total_in_area' => $totalInArea]);
}

/* ═══════════════════════════════════════════════════════════
   SEARCH (POST) — AI chat: local DB keyword search
═══════════════════════════════════════════════════════════ */
if ($action === 'search' && $method === 'POST') {
    try {
        $pdo = getDB();

        $query  = trim((string)($params['query']  ?? ''));
        $course = trim((string)($params['course'] ?? ''));
        $city   = trim((string)($params['city']   ?? ''));
        $lat    = isset($params['latitude'])  ? (float)$params['latitude']  : null;
        $lon    = isset($params['longitude']) ? (float)$params['longitude'] : null;

        $sql    = 'SELECT * FROM schools WHERE 1=1';
        $bind   = [];
        $where  = [];

        if ($query !== '') {
            $where[] = '(school_name LIKE ? OR courses_offered LIKE ? OR city LIKE ? OR province LIKE ?)';
            $like = "%$query%";
            $bind[] = $like; $bind[] = $like; $bind[] = $like; $bind[] = $like;
        }
        if ($course !== '') {
            $where[] = 'courses_offered LIKE ?';
            $bind[] = "%$course%";
        }
        if ($city !== '') {
            $where[] = '(city LIKE ? OR province LIKE ?)';
            $bind[] = "%$city%"; $bind[] = "%$city%";
        }
        if (!empty($where)) {
            $sql .= ' AND ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY school_name ASC LIMIT 30';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        $schools = $stmt->fetchAll();

        $results = array_map('formatSchoolDB', $schools);
        $results = array_values(array_filter($results, static function (array $s) {
            return isCollegeOrUniversity((string)$s['school_name']);
        }));

        if ($lat !== null && $lon !== null) {
            foreach ($results as &$s) {
                if ($s['latitude'] !== null && $s['longitude'] !== null) {
                    $s['distance_km'] = round(haversine($lat, $lon, (float)$s['latitude'], (float)$s['longitude']), 1);
                } else {
                    $s['distance_km'] = null;
                }
            }
            unset($s);
            usort($results, function ($a, $b) {
                if ($a['distance_km'] === null && $b['distance_km'] === null) return 0;
                if ($a['distance_km'] === null) return 1;
                if ($b['distance_km'] === null) return -1;
                return $a['distance_km'] <=> $b['distance_km'];
            });
        }

        sendJson(['success' => true, 'schools' => $results, 'count' => count($results)]);
    } catch (Throwable $e) {
        error_log('RIASEC schools search error: ' . $e->getMessage());
        sendJson(['success' => false, 'error' => 'Failed to search schools.'], 500);
    }
}

// Unknown action
sendJson(['success' => false, 'error' => 'Unknown action.'], 400);


/* ═══════════════════════════════════════════════════════════
   Helper Functions
═══════════════════════════════════════════════════════════ */

/**
 * K-12 institution names — never returned by the school finder.
 */
function isCollegeOrUniversity(string $name, string $amenity = ''): bool {
    if (preg_match(
        '/elementary|kindergarten|nursery|grade\s*school|primary\s*school|'
        . 'high\s*school|senior\s*high|junior\s*high|k\s*[-\s]?\s*12|day\s*care/i',
        $name
    )) {
        return false;
    }

    if (in_array($amenity, ['university', 'college'], true)) return true;

    return (bool) preg_match(
        '/college|university|institute|polytechnic|tertiary|colegio|unibersidad|pamantasan/i',
        $name
    );
}

/**
 * Format a local database school row for the AI chat search response.
 */
function formatSchoolDB(array $row): array {
    $courses = array_filter(array_map('trim', explode(',', $row['courses_offered'] ?? '')));
    return [
        'school_id'            => (int)$row['school_id'],
        'school_name'          => $row['school_name'],
        'school_address'       => $row['school_address'],
        'city'                 => $row['city'],
        'province'             => $row['province'],
        'latitude'             => $row['latitude']  !== null ? (float)$row['latitude']  : null,
        'longitude'            => $row['longitude'] !== null ? (float)$row['longitude'] : null,
        'contact_number'       => $row['contact_number'] ?? '',
        'email'                => $row['email'] ?? '',
        'official_website'     => $row['official_website'] ?? '',
        'courses_offered'      => $courses,
        'courses_offered_text'  => $row['courses_offered'] ?? '',
        'admission_information' => $row['admission_information'] ?? '',
    ];
}

/**
 * Format a local DB school for the GET search response (school finder).
 */
function formatSchoolFromDB(array $row, int $distanceMeters, array $matchedCourses): array {
    return [
        'name'            => $row['school_name'],
        'address'         => $row['school_address'] . ', ' . $row['city'] . ', ' . $row['province'],
        'city'            => $row['city'],
        'province'        => $row['province'],
        'distance'        => $distanceMeters,
        'is_degree'       => true,
        'matched_courses' => $matchedCourses,
        'verified_by'     => 'school database',
        'verified_url'    => $row['official_website'] ?: null,
        'operator'        => null,
        'phone'           => $row['contact_number'] ?: null,
        'email'           => $row['email'] ?: null,
        'website'         => $row['official_website'] ?: null,
        'facebook'        => null,
        'instagram'       => null,
        'twitter'         => null,
        'description'     => null,
        'opening_hours'   => null,
        'fee'             => null,
        'wheelchair'      => null,
        'osm_url'         => null,
        'courses'         => $row['courses_offered'] ?: null,
    ];
}

/**
 * Format an OSM Overpass school for the GET search response.
 */
function formatSchoolFromOSM(array $school, int $distanceMeters, array $matchedCourses): array {
    $tags = $school['tags'];

    // Build address from OSM addr:* tags
    $addrParts = [];
    if (!empty($tags['addr:housenumber'])) $addrParts[] = $tags['addr:housenumber'];
    if (!empty($tags['addr:street']))     $addrParts[] = $tags['addr:street'];
    if (!empty($tags['addr:city']))       $addrParts[] = $tags['addr:city'];
    $address = implode(', ', $addrParts) ?: 'Not listed';

    $isDegree = isCollegeOrUniversity($school['name'], $tags['amenity'] ?? '');

    $osmUrl = 'https://www.openstreetmap.org/' . $school['type'] . '/' . $school['id'];

    return [
        'name'            => $school['name'],
        'address'         => $address,
        'city'            => $tags['addr:city'] ?? null,
        'province'        => null,
        'distance'        => $distanceMeters,
        'is_degree'       => $isDegree,
        'matched_courses' => $matchedCourses,
        'verified_by'     => 'OpenStreetMap directory — verify with school',
        'verified_url'    => $osmUrl,
        'operator'        => $tags['operator'] ?? null,
        'phone'           => $tags['phone'] ?? $tags['contact:phone'] ?? null,
        'email'           => $tags['email'] ?? $tags['contact:email'] ?? null,
        'website'         => $tags['website'] ?? $tags['contact:website'] ?? null,
        'facebook'        => $tags['contact:facebook'] ?? null,
        'instagram'       => $tags['contact:instagram'] ?? null,
        'twitter'         => $tags['contact:twitter'] ?? null,
        'description'     => $tags['description'] ?? null,
        'opening_hours'   => $tags['opening_hours'] ?? null,
        'fee'             => $tags['fee'] ?? null,
        'wheelchair'      => $tags['wheelchair'] ?? null,
        'osm_url'         => $osmUrl,
        'courses'         => null,
    ];
}

/**
 * Match requested courses against a local DB school's courses_offered field.
 */
function matchCoursesFromDB(string $coursesOffered, array $requestedCourses): array {
    if (empty($requestedCourses) || $coursesOffered === '') return [];

    // Split the school's course list into entries and drop degree prefixes
    // ("BS Psychology" → "psychology") so they compare cleanly with the
    // recommended course names, which carry no degree prefixes.
    $offered = [];
    foreach (explode(',', $coursesOffered) as $entry) {
        $entry = trim(preg_replace(
            '/^(bachelor of|master of|doctor of|bs|ab|ba|bsc|bse|bete|ma|ms|mba|msc|phd|associate in)\b[\s.]*/i',
            '', trim($entry)
        ));
        if ($entry !== '') $offered[] = strtolower($entry);
    }
    if (!$offered) return [];

    $matched = [];
    foreach ($requestedCourses as $req) {
        if (isset($matched[$req])) continue;
        // "Education / Teacher Education" → ["education", "teacher education"]
        $keywords = [];
        foreach (explode('/', $req) as $kw) {
            $kw = trim($kw);
            if (strlen($kw) > 3) $keywords[] = strtolower($kw);
        }
        foreach ($keywords as $kw) {
            foreach ($offered as $off) {
                if (strpos($off, $kw) !== false || (strlen($off) > 3 && strpos($kw, $off) !== false)) {
                    $matched[$req] = true;
                    break 2;
                }
            }
        }
    }
    return array_keys($matched);
}

/**
 * Match requested course names against a free-text haystack (school
 * name, tags, or a stripped website page). Course names are split on
 * "/" so "Education / Teacher Education" matches either phrase.
 */
function matchCourseKeywords(string $haystack, array $requestedCourses): array {
    if ($haystack === '' || empty($requestedCourses)) return [];

    $haystack = strtolower($haystack);
    $matched  = [];
    foreach ($requestedCourses as $req) {
        if (isset($matched[$req])) continue;
        foreach (explode('/', $req) as $kw) {
            $kw = trim($kw);
            if (strlen($kw) > 3 && strpos($haystack, strtolower($kw)) !== false) {
                $matched[$req] = true;
                break;
            }
        }
    }
    return array_keys($matched);
}

/**
 * Match requested courses against a school's name and description
 * (heuristic for OSM schools, which have no program list).
 */
function matchCoursesByName(string $schoolName, array $requestedCourses, array $tags = []): array {
    if (empty($requestedCourses)) return [];

    $haystack = strtolower($schoolName);
    foreach (['description', 'subjects', 'courses', 'official_name', 'alt_name', 'short_name', 'operator'] as $tag) {
        if (!empty($tags[$tag])) $haystack .= ' ' . strtolower((string)$tags[$tag]);
    }

    return matchCourseKeywords($haystack, $requestedCourses);
}

/**
 * Normalize a school's website tag into an absolute http(s) URL,
 * or null when it is missing or unusable.
 */
function normalizeWebsiteUrl(?string $url): ?string {
    $url = trim((string) $url);
    if ($url === '') return null;
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . ltrim($url, '/');
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)) return null;
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) return null;
    return $url;
}

/**
 * Read each unmatched school's homepage and match the requested RIASEC
 * courses against the program names printed on the page. This is what
 * makes the dynamic (OpenStreetMap/Nominatim) results accurate: those
 * sources carry no course list, but the school's own website does.
 * Nearest schools are scanned first, capped at 20 with an ~8 s budget.
 */
function scanSchoolWebsites(array &$schools, array $requestedCourses): void {
    if (empty($requestedCourses) || !function_exists('curl_multi_init')) return;

    $candidates = [];
    foreach ($schools as $i => $school) {
        if (!empty($school['matched_courses'])) continue;
        $url = normalizeWebsiteUrl($school['website'] ?? null);
        if ($url === null) continue;
        $candidates[] = [
            'index'    => $i,
            'url'      => $url,
            'distance' => (int) ($school['distance'] ?? PHP_INT_MAX),
        ];
    }
    if (!$candidates) return;

    usort($candidates, static fn (array $a, array $b): int => $a['distance'] <=> $b['distance']);
    $candidates = array_slice($candidates, 0, 20);

    $mh       = curl_multi_init();
    $handles  = [];
    $deadline = microtime(true) + 8.0;

    foreach ($candidates as $n => $candidate) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $candidate['url'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_USERAGENT      => 'RIASEC-SchoolFinder/1.0 (school program lookup)',
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$n] = ['ch' => $ch, 'index' => $candidate['index']];
    }

    do {
        $status = curl_multi_exec($mh, $active);
        if ($active && curl_multi_select($mh, 0.2) === -1) {
            usleep(50000);
        }
        if (microtime(true) > $deadline) break;
    } while ($active && $status === CURLM_OK);

    foreach ($handles as $item) {
        $body = (string) curl_multi_getcontent($item['ch']);
        $code = (int) curl_getinfo($item['ch'], CURLINFO_RESPONSE_CODE);
        if ($body !== '' && $code >= 200 && $code < 400) {
            $text = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $matched = matchCourseKeywords($text, $requestedCourses);
            if ($matched) {
                $idx = $item['index'];
                $schools[$idx]['matched_courses'] = array_values(array_unique(array_merge(
                    (array) ($schools[$idx]['matched_courses'] ?? []),
                    $matched
                )));
            }
        }
        curl_multi_remove_handle($mh, $item['ch']);
        curl_close($item['ch']);
    }
    curl_multi_close($mh);
}

/**
 * Query the OpenStreetMap Overpass API for colleges and universities
 * within a given radius of the coordinates. Every public mirror is asked
 * at once and the first non-empty answer wins.
 * Returns null when every mirror failed (distinct from "nothing nearby").
 */
function searchOverpass(float $lat, float $lon, int $radiusMeters): ?array {
    if (!function_exists('curl_multi_init')) return null;

    $r = max(1000, min($radiusMeters, 100000));  // clamp 1km–100km

    $query = "[out:json][timeout:10];(";
    // Universities and colleges
    $query .= "node[\"amenity\"~\"university|college\"](around:{$r},{$lat},{$lon});";
    $query .= "way[\"amenity\"~\"university|college\"](around:{$r},{$lat},{$lon});";
    // Schools whose name contains "college" or "university" (many PH colleges are tagged amenity=school)
    $query .= "node[\"amenity\"=\"school\"][\"name\"~\"[Cc]ollege|[Uu]niversity\",i](around:{$r},{$lat},{$lon});";
    $query .= "way[\"amenity\"=\"school\"][\"name\"~\"[Cc]ollege|[Uu]niversity\",i](around:{$r},{$lat},{$lon});";
    $query .= ");out center 100;";

    $mirrors = [
        'https://overpass-api.de/api/interpreter',
        'https://lz4.overpass-api.de/api/interpreter',
        'https://overpass.kumi.systems/api/interpreter',
    ];

    // Mirrors answer in well under a second when healthy; a short budget
    // keeps a dead mirror from delaying the Nominatim fallback below.
    $deadline = microtime(true) + 10.0;
    $data = overpassRequestAll($mirrors, $query, $deadline);

    if ($data === null) return null;

    $schools = [];
    $seenOsmIds = [];
    foreach ($data as $el) {
        $elLat = $el['lat'] ?? ($el['center']['lat'] ?? null);
        $elLon = $el['lon'] ?? ($el['center']['lon'] ?? null);
        $tags  = $el['tags'] ?? [];
        $name  = $tags['name'] ?? $tags['official_name'] ?? null;
        $osmId = $el['type'] . '_' . $el['id'];

        if (!$name || !$elLat || !$elLon) continue;
        if (isset($seenOsmIds[$osmId])) continue;
        $seenOsmIds[$osmId] = true;

        $schools[] = [
            'name' => $name,
            'lat'  => (float)$elLat,
            'lon'  => (float)$elLon,
            'type' => $el['type'],
            'id'   => $el['id'],
            'tags' => $tags,
        ];
    }

    return $schools;
}

/**
 * POST one Overpass query to several mirrors at the same time.
 *
 * Returns the first non-empty elements array, an empty array when an
 * answering mirror reported nothing in the area, or null when no mirror
 * answered (unreachable, overloaded, or an invalid payload).
 */
function overpassRequestAll(array $endpoints, string $query, float $deadline): ?array {
    $mh      = curl_multi_init();
    $handles = [];

    foreach ($endpoints as $endpoint) {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $query,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: text/plain',
                'Accept: */*',
                'User-Agent: RIASEC-School-Finder/1.0',
            ],
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[] = $ch;
    }

    $running = null;
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) curl_multi_select($mh, 1.0);
    } while ($running && $status === CURLM_OK && microtime(true) < $deadline);

    $answer = null;
    $answeredEmpty = false;

    foreach ($handles as $ch) {
        $response = curl_multi_getcontent($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $failed   = curl_errno($ch) !== 0;

        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);

        if ($failed || $httpCode !== 200 || !is_string($response) || $response === '') continue;

        $data = json_decode($response, true);
        if (!is_array($data) || !isset($data['elements']) || !is_array($data['elements'])) continue;

        if (!empty($data['elements'])) { $answer = $data['elements']; break; }
        $answeredEmpty = true;
    }

    curl_multi_close($mh);

    if ($answer !== null) return $answer;
    return $answeredEmpty ? [] : null;
}

/**
 * Simple JSON GET helper used by the place-search fallback.
 * Returns the decoded body, or null when the request itself failed.
 */
function httpGetJson(string $url): ?array {
    if (!function_exists('curl_init')) return null;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'User-Agent: RIASEC-School-Finder/1.0',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) return null;

    $data = json_decode($response, true);
    return is_array($data) ? $data : null;
}

/**
 * Nominatim place search — the fallback used when every Overpass mirror is
 * unreachable (or the mirrors reported nothing in the area). Searches the
 * bounded box that covers the requested radius for colleges/universities
 * and returns them in the same shape the Overpass loop consumes:
 *   [ [ 'name' => …, 'lat' => …, 'lon' => …, 'row' => <nominatim result> ], … ]
 * Returns null when every lookup failed (distinct from "nothing nearby").
 */
function searchNominatimPOI(float $lat, float $lon, int $radiusMeters): ?array {
    $r = max(1000, min($radiusMeters, 100000));  // clamp 1km–100km

    // Bounding box that fully contains the search circle.
    $dLat  = $r / 111320.0;
    $dLon  = $r / (111320.0 * max(0.05, cos(deg2rad($lat))));
    $viewbox = sprintf('%F,%F,%F,%F', $lon - $dLon, $lat + $dLat, $lon + $dLon, $lat - $dLat);

    $schools   = [];
    $anyAnswer = false;

    foreach (['university', 'college'] as $term) {
        $url = 'https://nominatim.openstreetmap.org/search'
            . '?format=jsonv2&limit=50&addressdetails=1&extratags=1&namedetails=1'
            . '&bounded=1&viewbox=' . urlencode($viewbox)
            . '&q=' . urlencode($term);

        $started = microtime(true);
        $rows    = httpGetJson($url);
        $wait    = 1.1 - (microtime(true) - $started);   // Nominatim: 1 request/second
        if ($wait > 0) usleep((int)($wait * 1000000));

        if ($rows === null) continue;   // network/HTTP failure — try the next term
        $anyAnswer = true;
        if (!is_array($rows)) continue;

        foreach ($rows as $row) {
            if (!is_array($row)) continue;

            $type     = (string)($row['type']     ?? '');
            $category = (string)($row['category'] ?? '');
            if ($category !== 'amenity') continue;
            if (!in_array($type, ['university', 'college', 'school', 'academy', 'institute', 'institution', 'campus'], true)) {
                continue;
            }

            $name = trim((string)(($row['namedetails']['name'] ?? '') ?: ($row['name'] ?? '')));
            if ($name === '') {
                $parts = explode(', ', (string)($row['display_name'] ?? ''));
                $name  = trim((string)($parts[0] ?? ''));
            }
            if ($name === '') continue;

            // Amenity types are trusted; anything else (e.g. "school") must
            // pass the college/university check so K-12 entries are dropped.
            $amenity = in_array($type, ['university', 'college'], true) ? $type : '';
            if (!isCollegeOrUniversity($name, $amenity)) continue;

            $rowLat = (float)($row['lat'] ?? 0);
            $rowLon = (float)($row['lon'] ?? 0);
            if ($rowLat == 0.0 || $rowLon == 0.0) continue;

            // The box is a square — enforce the circular radius.
            if (haversine($lat, $lon, $rowLat, $rowLon) * 1000 > $radiusMeters) continue;

            $schools[] = [
                'name' => $name,
                'lat'  => $rowLat,
                'lon'  => $rowLon,
                'row'  => $row,
            ];
        }
    }

    return $anyAnswer ? $schools : null;
}

/**
 * Format a Nominatim place for the GET search response (school finder).
 */
function formatSchoolFromNominatim(array $row, string $name, int $distanceMeters, array $matchedCourses): array {
    // Address from the structured address object, display_name as fallback.
    $address = '';
    $addr    = is_array($row['address'] ?? null) ? $row['address'] : [];
    $parts   = [];
    foreach ([
        $addr['road'] ?? $addr['pedestrian'] ?? '',
        $addr['suburb'] ?? $addr['quarter'] ?? $addr['neighbourhood'] ?? '',
        $addr['city'] ?? $addr['town'] ?? $addr['municipality'] ?? $addr['village'] ?? '',
        $addr['postcode'] ?? '',
    ] as $part) {
        $part = trim((string)$part);
        if ($part !== '' && !in_array($part, $parts, true)) $parts[] = $part;
    }
    $address = implode(', ', $parts);
    if ($address === '') {
        $dnParts = explode(', ', (string)($row['display_name'] ?? ''));
        array_shift($dnParts);                     // drop the POI name itself
        $address = implode(', ', $dnParts);
    }

    $extra = is_array($row['extratags'] ?? null) ? $row['extratags'] : [];
    $osmTypeMap = ['N' => 'node', 'W' => 'way', 'R' => 'relation',
                   'n' => 'node', 'w' => 'way', 'r' => 'relation'];
    $osmType = $osmTypeMap[(string)($row['osm_type'] ?? '')] ?? 'node';
    $osmUrl  = 'https://www.openstreetmap.org/' . $osmType . '/' . (string)($row['osm_id'] ?? '');

    return [
        'name'            => $name,
        'address'         => $address !== '' ? $address : 'Not listed',
        'city'            => $addr['city'] ?? $addr['town'] ?? $addr['municipality'] ?? $addr['village'] ?? null,
        'province'        => $addr['state'] ?? $addr['county'] ?? null,
        'distance'        => $distanceMeters,
        'is_degree'       => true,
        'matched_courses' => $matchedCourses,
        'verified_by'     => 'OpenStreetMap directory — verify with school',
        'verified_url'    => $osmUrl,
        'operator'        => $extra['operator'] ?? null,
        'phone'           => $extra['phone'] ?? $extra['contact:phone'] ?? null,
        'email'           => $extra['email'] ?? $extra['contact:email'] ?? null,
        'website'         => $extra['website'] ?? $extra['contact:website'] ?? null,
        'facebook'        => $extra['contact:facebook'] ?? null,
        'instagram'       => $extra['contact:instagram'] ?? null,
        'twitter'         => $extra['contact:twitter'] ?? null,
        'description'     => $extra['description'] ?? null,
        'opening_hours'   => $extra['opening_hours'] ?? null,
        'fee'             => $extra['fee'] ?? null,
        'wheelchair'      => $extra['wheelchair'] ?? null,
        'osm_url'         => $osmUrl,
        'courses'         => $extra['courses'] ?? $extra['subjects'] ?? null,
    ];
}

/**
 * Haversine formula — great-circle distance in km.
 */
function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $R = 6371; // Earth radius in km
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $R * $c;
}
