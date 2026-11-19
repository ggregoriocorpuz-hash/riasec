<?php
// Live end-to-end tests for the province → city picker + search flow.
$pass = 0; $fail = 0;
function ck(string $label, bool $cond, $extra = null): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  $label\n"; }
    else { $fail++; echo "FAIL  $label\n"; if ($extra !== null) echo '      ' . json_encode($extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"; }
}
function httpGet(string $url, int $timeout = 60): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'RIASEC-E2E-Test/1.0',
    ]);
    $start = microtime(true);
    $body  = curl_exec($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err   = curl_error($ch);
    curl_close($ch);
    return ['body' => $body, 'code' => $code, 'error' => $err, 'secs' => round(microtime(true) - $start, 1)];
}

$base = 'http://localhost/riasec/api/schools.php';

function geocode(string $q, bool $details): array {
    $u = 'http://localhost/riasec/api/schools.php?action=geocode&q=' . urlencode($q) . ($details ? '&details=1' : '');
    $r = httpGet($u, 45);
    usleep(1100000);   // Nominatim 1 req/s
    return $r;
}

/* ── 1. Province (Nueva Ecija) → dropdown data ── */
echo "      geocode: Nueva Ecija (details=1)\n";
$r = geocode('Nueva Ecija', true);
ck('province: HTTP 200', $r['code'] === 200, $r);
$d = json_decode((string) $r['body'], true);
ck('province: found', is_array($d) && !empty($d['found']), $d);
ck('province: kind=province', is_array($d) && ($d['kind'] ?? '') === 'province', $d['kind'] ?? null);
ck('province: admin_level=4', is_array($d) && ($d['admin_level'] ?? '') === '4', $d['admin_level'] ?? null);
ck('province: province name', is_array($d) && ($d['province'] ?? '') === 'Nueva Ecija', $d['province'] ?? null);
$cities = is_array($d) && is_array($d['cities'] ?? null) ? $d['cities'] : [];
ck('province: >= 30 cities', count($cities) >= 30, count($cities));
$badCity = array_values(array_filter($cities, static function (array $c): bool {
    return ($c['name'] ?? '') === ''
        || !is_numeric($c['lat'] ?? null) || !is_numeric($c['lon'] ?? null)
        || $c['lat'] < 4 || $c['lat'] > 22 || $c['lon'] < 116 || $c['lon'] > 128; // Philippines bbox
}));
ck('province: every city has name + PH coords', !$badCity, array_slice($badCity, 0, 3));
$names = array_column($cities, 'name');
ck('province: has Cabanatuan', in_array('Cabanatuan', $names, true), $names);
$sorted = $names; sort($sorted, SORT_NATURAL | SORT_FLAG_CASE);
ck('province: cities sorted alphabetically', $names === $sorted, array_slice($names, 0, 8));
echo '      cities: ' . count($cities) . ' (' . implode(', ', array_slice($names, 0, 6)) . ", ...)\n";
echo '      total time: ' . $r['secs'] . "s\n";

/* ── 2. Region (Central Luzon, admin_level 3) → also a dropdown ── */
echo "      geocode: Central Luzon (details=1)\n";
$r = geocode('Central Luzon', true);
$d = json_decode((string) $r['body'], true);
ck('region: HTTP 200', $r['code'] === 200, $r);
ck('region: kind=province', is_array($d) && ($d['kind'] ?? '') === 'province', $d['kind'] ?? null);
ck('region: admin_level=3', is_array($d) && ($d['admin_level'] ?? '') === '3', $d['admin_level'] ?? null);
ck('region: has cities', is_array($d) && count($d['cities'] ?? []) >= 5, is_array($d) ? count($d['cities'] ?? []) : null);

/* ── 3. City queries → direct search, no dropdown ── */
foreach (['Cabanatuan', 'Quezon City'] as $q) {
    echo "      geocode: $q (details=1)\n";
    $r = geocode($q, true);
    $d = json_decode((string) $r['body'], true);
    ck("city $q: HTTP 200", $r['code'] === 200, $r);
    ck("city $q: found", is_array($d) && !empty($d['found']), $d);
    ck("city $q: kind=place", is_array($d) && ($d['kind'] ?? '') === 'place', $d['kind'] ?? null);
    ck("city $q: no cities list", is_array($d) && (empty($d['cities'])), is_array($d) ? ($d['cities'] ?? null) : null);
}

/* ── 4. Country (admin_level 2) → treated as a plain place ── */
echo "      geocode: Philippines (details=1)\n";
$r = geocode('Philippines', true);
$d = json_decode((string) $r['body'], true);
ck('country: kind=place (admin_level 2)', is_array($d) && ($d['kind'] ?? '') === 'place',
    is_array($d) ? [$d['kind'] ?? null, $d['admin_level'] ?? null] : null);

/* ── 5. Backward compatibility: plain geocode unchanged ── */
echo "      geocode: Nueva Ecija (no details)\n";
$r = geocode('Nueva Ecija', false);
$d = json_decode((string) $r['body'], true);
ck('plain: found + lat/lon/label', is_array($d) && !empty($d['found']) && isset($d['lat'], $d['lon'], $d['label']), $d);
ck('plain: no kind/cities fields', is_array($d) && !array_key_exists('kind', $d) && !array_key_exists('cities', $d),
    is_array($d) ? array_keys($d) : null);

/* ── 6. Search from a chosen city (Cabanatuan), 100 km, matched only ── */
echo "      search: Cabanatuan @100km (may take ~20-40 s)\n";
$courses = 'Psychology|Nursing|Accountancy|Computer Science|Education / Teacher Education|Mechanical Engineering';
$r = httpGet('http://localhost/riasec/api/schools.php?action=search&lat=15.4908&lon=120.9678'
    . '&radius=100000&courses=' . urlencode($courses), 90);
echo '      search HTTP ' . $r['code'] . ' in ' . $r['secs'] . "s\n";
$d = json_decode((string) $r['body'], true);
ck('search: HTTP 200 + success', $r['code'] === 200 && is_array($d) && !empty($d['success']),
    is_array($d) ? ($d['error'] ?? null) : $r);
$schools = is_array($d) && is_array($d['schools'] ?? null) ? $d['schools'] : [];
ck('search: total_in_area present', is_array($d) && array_key_exists('total_in_area', $d), is_array($d) ? array_keys($d) : null);
ck('search: schools returned', count($schools) >= 1, count($schools));
$bad = array_values(array_filter($schools, static fn (array $s): bool => empty($s['matched_courses']) || ($s['is_degree'] ?? true) === false || ($s['distance'] ?? 0) > 100000));
ck('search: every school matched + degree + within 100 km', !$bad,
    array_map(static fn (array $s) => [$s['name'] ?? '?', $s['distance'] ?? null], $bad));
echo '      matched: ' . count($schools) . ' of ' . ($d['total_in_area'] ?? '?') . " in area\n";
foreach (array_slice($schools, 0, 5) as $s) {
    echo '        - ' . ($s['name'] ?? '?') . ' (' . round(($s['distance'] ?? 0) / 1000, 1) . " km) → " . implode(', ', $s['matched_courses'] ?? []) . "\n";
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
