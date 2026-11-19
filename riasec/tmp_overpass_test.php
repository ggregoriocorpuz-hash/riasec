<?php
// Test Overpass mirrors the same way schools.php does (POST, curl).
$mirrors = [
    'overpass-api.de'   => 'https://overpass-api.de/api/interpreter',
    'kumi'              => 'https://overpass.kumi.systems/api/interpreter',
    'mail.ru'           => 'https://maps.mail.ru/osm/tools/overpass/api/interpreter',
    'private.coffee'    => 'https://overpass.private.coffee/api/interpreter',
];
$query = '[out:json][timeout:10];node[place~"city|town|municipality"](14.5,120.9,14.7,121.1);out tags 5;';
foreach ($mirrors as $name => $url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => 'data=' . urlencode($query),
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_USERAGENT      => 'RIASEC-School-Finder/1.0',
    ]);
    $start = microtime(true);
    $body  = curl_exec($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err   = curl_error($ch);
    curl_close($ch);
    $secs = round(microtime(true) - $start, 1);
    $ok = is_string($body) && $body !== '' && $code === 200;
    $len = is_string($body) ? strlen($body) : 0;
    echo "$name: HTTP $code in {$secs}s len=$len" . ($err ? " err=$err" : '') . "\n";
    if ($ok) {
        $j = json_decode($body, true);
        echo '   elements: ' . (is_array($j['elements'] ?? null) ? count($j['elements']) : 'n/a') . "\n";
        if (!empty($j['elements'][0]['tags'])) {
            echo '   sample: ' . json_encode($j['elements'][0]['tags']) . "\n";
        }
    } elseif (is_string($body) && $body !== '') {
        echo '   body head: ' . substr($body, 0, 150) . "\n";
    }
}
