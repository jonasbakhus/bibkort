<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/Geography.php';

app_require_get();

$config = require __DIR__ . '/../config/app.php';
$ttl = (int) $config['geography']['cache_ttl'];
$codes = [];
foreach ($config['cities'] as $city) {
    $codes[$city['municipalityCode']] = true;
}
ksort($codes);
$simplifyTolerance = (float) ($config['geography']['boundary_simplify_tolerance'] ?? 0.001);
$cacheKey = 'analysis-geography-v3-' . sha1(json_encode([
    array_keys($codes),
    $simplifyTolerance,
    (float) $config['geography']['urban_weight'],
    (float) $config['geography']['rural_weight'],
    (float) $config['geography']['urban_population_exponent'],
]));

try {
    $cached = app_cache_read($cacheKey, $ttl);
    if ($cached !== null) {
        app_json_response(array_merge(['ok' => true], $cached['data'], [
            'cache' => ['hit' => true, 'stale' => false, 'ageSeconds' => $cached['age']],
        ]));
    }

    try {
        $payload = app_fetch_analysis_geography($config);
        app_cache_write($cacheKey, $payload);
        app_json_response(array_merge(['ok' => true], $payload, [
            'cache' => ['hit' => false, 'stale' => false, 'ageSeconds' => 0],
        ]));
    } catch (Throwable $exception) {
        $stale = app_cache_read($cacheKey, $ttl, true);
        if ($stale !== null) {
            app_json_response(array_merge(['ok' => true], $stale['data'], [
                'warning' => 'Geografidata kunne ikke opdateres; senest gemte data vises.',
                'cache' => ['hit' => true, 'stale' => true, 'ageSeconds' => $stale['age']],
            ]));
        }
        throw $exception;
    }
} catch (Throwable $exception) {
    error_log('bibkort geography: ' . $exception->getMessage());
    app_json_response(['ok' => false, 'error' => 'Geografidata kunne ikke hentes lige nu. Prøv igen senere.'], 502);
}
