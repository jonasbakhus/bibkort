<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/Routing/ValhallaProvider.php';
require_once __DIR__ . '/../lib/Routing/TravelTimeProvider.php';

app_require_get();

$config = require __DIR__ . '/../config/app.php';
$routingConfig = $config['routing'];
$action = $_GET['action'] ?? '';
$ttl = (int) $config['routing']['cache_ttl'];
$originId = is_string($_GET['origin'] ?? null) ? $_GET['origin'] : $config['default_origin'];

if (!isset($config['origins'][$originId])) {
    app_json_response(['ok' => false, 'error' => 'Ukendt udgangspunkt.'], 422);
}

$timeFactor = (float) ($routingConfig['origin_time_factors'][$originId] ?? $routingConfig['travel_time_factor']);
$provider = $routingConfig['provider'] === 'TravelTime'
    ? new TravelTimeProvider(
        $routingConfig['traveltime_base_url'],
        $routingConfig['traveltime_app_id'],
        $routingConfig['traveltime_api_key'],
        $timeFactor
    )
    : new ValhallaProvider($routingConfig['base_url']);

$origin = $config['origins'][$originId];

try {
    if ($action === 'matrix') {
        $cacheKey = 'routing-matrix-' . sha1(json_encode([$origin, $config['cities'], $provider->name(), $timeFactor]));
        $cached = app_cache_read($cacheKey, $ttl);
        if ($cached !== null) {
            app_json_response([
                'ok' => true,
                'provider' => $provider->name(),
                'origin' => $origin,
                'cities' => $cached['data'],
                'cache' => ['hit' => true, 'stale' => false, 'ageSeconds' => $cached['age']],
            ]);
        }

        try {
            $cities = $provider->matrix($origin, $config['cities']);
            app_cache_write($cacheKey, $cities);
            app_json_response([
                'ok' => true,
                'provider' => $provider->name(),
                'origin' => $origin,
                'cities' => $cities,
                'cache' => ['hit' => false, 'stale' => false, 'ageSeconds' => 0],
            ]);
        } catch (Throwable $exception) {
            $stale = app_cache_read($cacheKey, $ttl, true);
            if ($stale !== null) {
                app_json_response([
                    'ok' => true,
                    'provider' => $provider->name(),
                    'origin' => $origin,
                    'cities' => $stale['data'],
                    'warning' => 'Routingtjenesten kunne ikke nås; senest gemte køretider vises.',
                    'cache' => ['hit' => true, 'stale' => true, 'ageSeconds' => $stale['age']],
                ]);
            }
            throw $exception;
        }
    }

    if ($action === 'isochrone') {
        $minutes = filter_input(INPUT_GET, 'minutes', FILTER_VALIDATE_INT);
        $slider = $config['slider'];
        if ($minutes === false || $minutes === null || $minutes < $slider['min'] || $minutes > $slider['max'] || $minutes % $slider['step'] !== 0) {
            app_json_response(['ok' => false, 'error' => 'minutes skal være 15–90 i trin på 5.'], 422);
        }

        $cacheKey = 'routing-isochrone-' . sha1(json_encode([$origin, $minutes, $provider->name(), $timeFactor]));
        $cached = app_cache_read($cacheKey, $ttl);
        if ($cached !== null) {
            app_json_response([
                'ok' => true,
                'provider' => $provider->name(),
                'minutes' => $minutes,
                'geojson' => $cached['data'],
                'cache' => ['hit' => true, 'stale' => false, 'ageSeconds' => $cached['age']],
            ]);
        }

        try {
            $geojson = $provider->isochrone($origin, $minutes);
            app_cache_write($cacheKey, $geojson);
            app_json_response([
                'ok' => true,
                'provider' => $provider->name(),
                'minutes' => $minutes,
                'geojson' => $geojson,
                'cache' => ['hit' => false, 'stale' => false, 'ageSeconds' => 0],
            ]);
        } catch (Throwable $exception) {
            $stale = app_cache_read($cacheKey, $ttl, true);
            if ($stale !== null) {
                app_json_response([
                    'ok' => true,
                    'provider' => $provider->name(),
                    'minutes' => $minutes,
                    'geojson' => $stale['data'],
                    'warning' => 'Routingtjenesten kunne ikke nås; senest gemte køretidsområde vises.',
                    'cache' => ['hit' => true, 'stale' => true, 'ageSeconds' => $stale['age']],
                ]);
            }
            throw $exception;
        }
    }

    app_json_response(['ok' => false, 'error' => 'Ukendt action. Brug matrix eller isochrone.'], 400);
} catch (Throwable $exception) {
    error_log('bibkort routing: ' . $exception->getMessage());
    app_json_response(['ok' => false, 'error' => 'Routingdata kunne ikke hentes lige nu. Prøv igen senere.'], 502);
}
