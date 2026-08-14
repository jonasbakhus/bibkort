<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/Geography.php';

app_require_get();

$config = require __DIR__ . '/../config/app.php';
$ttl = (int) $config['geography']['cache_ttl'];
$tolerance = (float) ($config['geography']['boundary_simplify_tolerance'] ?? 0.001);
$cacheKey = 'lemvig-municipality-boundary-v1-' . sha1((string) $tolerance);
$cached = app_cache_read($cacheKey, $ttl);

if ($cached !== null) {
    app_json_response(['ok' => true, 'geojson' => $cached['data'], 'cache' => ['hit' => true, 'ageSeconds' => $cached['age']]]);
}

try {
    $boundary = app_json_decode(
        app_http_get('https://api.dataforsyningen.dk/kommuner/0665?format=geojson', 45),
        'Dataforsyningen'
    );
    if (($boundary['type'] ?? null) !== 'Feature' || !is_array($boundary['geometry'] ?? null)) {
        throw new RuntimeException('Dataforsyningen returnerede en ugyldig kommunegrænse.');
    }
    $boundary['properties'] = ['code' => '665', 'name' => 'Lemvig Kommune'];
    $boundary['geometry'] = app_simplify_geometry($boundary['geometry'], $tolerance);
    app_cache_write($cacheKey, $boundary);
    app_json_response(['ok' => true, 'geojson' => $boundary, 'cache' => ['hit' => false, 'ageSeconds' => 0]]);
} catch (Throwable $exception) {
    error_log('bibkort boundary: ' . $exception->getMessage());
    app_json_response(['ok' => false, 'error' => 'Kommunegrænsen kunne ikke hentes lige nu.'], 502);
}
