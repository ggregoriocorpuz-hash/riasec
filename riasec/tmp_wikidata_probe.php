<?php
// Diagnose Wikidata: what entities are inside Nueva Ecija (Q13865)?
function sparql(string $query): array {
    $url = 'https://query.wikidata.org/sparql?format=json&query=' . urlencode($query);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/sparql-results+json', 'User-Agent: RIASEC-School-Finder/1.0 (local test)'],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false || $code !== 200) return ['__error' => "HTTP $code $err", '__body' => is_string($body) ? substr($body, 0, 300) : ''];
    $j = json_decode((string) $body, true);
    return is_array($j) ? $j : ['__error' => 'bad json'];
}

// 1) Which P31 classes do places in Nueva Ecija have?
$q1 = 'SELECT ?type ?typeLabel (COUNT(?item) AS ?n) WHERE {'
   . '  ?item wdt:P131+ wd:Q13865 .'
   . '  ?item wdt:P31 ?type .'
   . '  SERVICE wikibase:label { bd:serviceParam wikibase:language "en". }'
   . '} GROUP BY ?type ?typeLabel ORDER BY DESC(?n) LIMIT 25';
$r1 = sparql($q1);
if (isset($r1['__error'])) { echo 'Q1 ERROR: ' . $r1['__error'] . ' ' . ($r1['__body'] ?? '') . "\n"; exit(1); }
echo "=== P31 classes of items with P131+ = Q13865 ===\n";
foreach (($r1['results']['bindings'] ?? []) as $b) {
    echo '  ' . ($b['typeLabel']['value'] ?? '?') . ' (' . ($b['type']['value'] ?? '?') . ') x ' . ($b['n']['value'] ?? '?') . "\n";
}

// 2) Sample items with coords
$q2 = 'SELECT ?item ?itemLabel ?coord ?typeLabel WHERE {'
    . '  ?item wdt:P131+ wd:Q13865 .'
    . '  ?item wdt:P625 ?coord .'
    . '  ?item wdt:P31 ?type .'
    . '  SERVICE wikibase:label { bd:serviceParam wikibase:language "en". }'
    . '} LIMIT 15';
$r2 = sparql($q2);
echo "\n=== sample items with coords ===\n";
if (isset($r2['__error'])) { echo 'Q2 ERROR: ' . $r2['__error'] . ' ' . ($r2['__body'] ?? '') . "\n"; }
else {
    foreach (($r2['results']['bindings'] ?? []) as $b) {
        echo '  ' . ($b['itemLabel']['value'] ?? '?') . ' [' . ($b['typeLabel']['value'] ?? '?') . '] ' . ($b['coord']['value'] ?? '?') . "\n";
    }
}
