<?php
// Probe Nominatim responses (province vs city detection) and Wikidata
// SPARQL for "cities/municipalities within a province".
function getJson(string $url, array $headers, int $timeout = 15): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_USERAGENT      => 'RIASEC-School-Finder/1.0',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false || $code !== 200) {
        return ['__error' => "HTTP $code $err", '__body' => is_string($body) ? substr($body, 0, 200) : ''];
    }
    $j = json_decode((string) $body, true);
    return is_array($j) ? $j : ['__error' => 'bad json', '__body' => substr((string) $body, 0, 200)];
}

$nomHeaders = ['Accept: application/json', 'User-Agent: RIASEC-School-Finder/1.0'];

echo "=== Nominatim probes ===\n";
foreach (['Nueva Ecija', 'Cabanatuan', 'Quezon City', 'Cebu City', 'Manila'] as $q) {
    $url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&extratags=1&addressdetails=1&q=' . urlencode($q);
    $r = getJson($url, $nomHeaders);
    if (isset($r['__error'])) { echo "$q => ERROR " . $r['__error'] . ' ' . ($r['__body'] ?? '') . "\n"; continue; }
    $row = $r[0] ?? null;
    if (!$row) { echo "$q => not found\n"; continue; }
    echo "$q => class={$row['class']} type={$row['type']} osm_type={$row['osm_type']} osm_id={$row['osm_id']}\n";
    echo '    display=' . $row['display_name'] . "\n";
    $et = $row['extratags'] ?? [];
    echo '    admin_level=' . ($et['admin_level'] ?? '-')
        . ' place=' . ($et['place'] ?? '-')
        . ' wikipedia=' . ($et['wikipedia'] ?? '-') . "\n";
    $addr = $row['address'] ?? [];
    echo '    address=' . json_encode(array_intersect_key($addr, array_flip(['city','town','municipality','county','state','province','country'])), JSON_UNESCAPED_UNICODE) . "\n";
    echo '    boundingbox=' . json_encode($row['boundingbox'] ?? null) . "\n";
    usleep(1100000); // Nominatim rate limit: 1 req/s
}

echo "\n=== Wikidata SPARQL: municipalities in Nueva Ecija ===\n";
// Find the province QID first.
$srch = getJson('https://www.wikidata.org/w/api.php?action=wbsearchentities&search=' . urlencode('Nueva Ecija') . '&language=en&format=json&limit=5',
    ['Accept: application/json', 'User-Agent: RIASEC-School-Finder/1.0 (contact: local)']);
if (isset($srch['__error'])) { echo 'search ERROR ' . $srch['__error'] . "\n"; }
else {
    foreach (($srch['search'] ?? []) as $s) {
        echo "  candidate: {$s['id']} — " . ($s['label'] ?? '') . ' ' . ($s['description'] ?? '') . "\n";
    }
    $qid = $srch['search'][0]['id'] ?? null;
    if ($qid) {
        echo "\n  SPARQL for $qid:\n";
        $sparql = 'SELECT ?item ?itemLabel ?coord WHERE {'
            . '  ?item wdt:P31/wdt:P279* wd:Q1569387 .'
            . "  ?item wdt:P131+ wd:$qid ."
            . '  ?item wdt:P625 ?coord .'
            . '  SERVICE wikibase:label { bd:serviceParam wikibase:language "en". }'
            . '} LIMIT 40';
        // Q1569387 = "municipality of the Philippines"? verify via label below.
        $qurl = 'https://query.wikidata.org/sparql?format=json&query=' . urlencode($sparql);
        $res = getJson($qurl, ['Accept: application/sparql-results+json', 'User-Agent: RIASEC-School-Finder/1.0 (local test)'], 30);
        if (isset($res['__error'])) { echo '  SPARQL ERROR ' . $res['__error'] . ' ' . ($res['__body'] ?? '') . "\n"; }
        else {
            $rows = $res['results']['bindings'] ?? [];
            echo '  rows: ' . count($rows) . "\n";
            foreach (array_slice($rows, 0, 8) as $row) {
                echo '    - ' . ($row['itemLabel']['value'] ?? '?') . ' | ' . ($row['coord']['value'] ?? '?') . "\n";
            }
        }
    }
}
