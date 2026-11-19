<?php
// Validate: region admin_level + final cities SPARQL query shape.
function httpGetJson(string $url, array $headers, int $timeout = 25): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_USERAGENT      => 'RIASEC-School-Finder/1.0',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false || $code !== 200) return ['__error' => "HTTP $code $err", '__body' => is_string($body) ? substr($body, 0, 300) : ''];
    $j = json_decode((string) $body, true);
    return is_array($j) ? $j : ['__error' => 'bad json'];
}

$nomH = ['Accept: application/json', 'User-Agent: RIASEC-School-Finder/1.0'];

// 1) Region detection
foreach (['Central Luzon', 'Nueva Ecija'] as $q) {
    $r = httpGetJson('https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&extratags=1&q=' . urlencode($q), $nomH);
    $row = $r[0] ?? null;
    echo "$q => type=" . ($row['type'] ?? '?') . " admin_level=" . (($row['extratags']['admin_level'] ?? '-')) . "\n";
    usleep(1100000);
}

// 2) Final SPARQL for cities in Nueva Ecija (Q13865)
$sparql = 'SELECT ?item ?itemLabel ?coord WHERE {'
    . '  ?item wdt:P131+ wd:Q13865 .'
    . '  ?item wdt:P625 ?coord .'
    . '  ?item wdt:P31 ?type .'
    . '  ?type rdfs:label ?tl .'
    . '  FILTER(LANG(?tl) = "en")'
    . '  FILTER(CONTAINS(LCASE(?tl), "municipality") || CONTAINS(LCASE(?tl), "city") || LCASE(?tl) = "town")'
    . '  FILTER(!(CONTAINS(LCASE(?tl), "barangay")))'
    . '  ?item rdfs:label ?itemLabel .'
    . '  FILTER(LANG(?itemLabel) = "en")'
    . '} ORDER BY ?itemLabel LIMIT 100';
$r = httpGetJson('https://query.wikidata.org/sparql?format=json&query=' . urlencode($sparql),
    ['Accept: application/sparql-results+json', 'User-Agent: RIASEC-School-Finder/1.0 (local test)'], 30);
if (isset($r['__error'])) { echo 'SPARQL ERROR: ' . $r['__error'] . ' ' . ($r['__body'] ?? '') . "\n"; exit(1); }
$rows = $r['results']['bindings'] ?? [];
echo 'cities found: ' . count($rows) . "\n";
$bad = 0;
foreach ($rows as $b) {
    $name = $b['itemLabel']['value'] ?? '';
    $coord = $b['coord']['value'] ?? '';
    if (!preg_match('/Point\(([-\d.]+)\s+([-\d.]+)\)/', $coord)) { $bad++; echo "  BAD coord: $name $coord\n"; }
}
echo "bad coords: $bad\n";
foreach (array_slice($rows, 0, 5) as $b) {
    preg_match('/Point\(([-\d.]+)\s+([-\d.]+)\)/', $b['coord']['value'] ?? '', $m);
    echo '  - ' . $b['itemLabel']['value'] . " => lat={$m[2]}, lon={$m[1]}\n";
}
echo '  ... last: ' . ($rows ? $rows[count($rows) - 1]['itemLabel']['value'] : '-') . "\n";
