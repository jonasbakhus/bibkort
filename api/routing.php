<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/Routing/ValhallaProvider.php';
require_once __DIR__ . '/../lib/Routing/TravelTimeProvider.php';
require_once __DIR__ . '/../lib/Routing/GoogleIsochronesProvider.php';
require_once __DIR__ . '/../lib/Geography.php';

app_require_get();

$config = require __DIR__ . '/../config/app.php';
$routingConfig = $config['routing'];
$action = $_GET['action'] ?? '';
$ttl = (int) $config['routing']['cache_ttl'];
$originId = is_string($_GET['origin'] ?? null) ? $_GET['origin'] : '';
$scope = is_string($_GET['scope'] ?? null) ? $_GET['scope'] : 'references';

if (!isset($config['origins'][$originId])) {
    app_json_response(['ok' => false, 'error' => 'Ukendt udgangspunkt.'], 422);
}

$calibrationSet = $routingConfig['travel_time_calibration'];
$calibration = $config['variant'] === 'google'
    ? ['intercept_seconds' => 0, 'slope' => 1.0]
    : ($calibrationSet['origins'][$originId] ?? $calibrationSet['default']);
$calibrationVersion = $config['variant'] === 'google' ? 'identity' : $calibrationSet['version'];

$origin = $config['origins'][$originId];

try {
    if ($action === 'matrix') {
        $cities = $scope === 'settlements'
            ? app_routing_settlements($config)
            : $config['cities'];
        $provider = $routingConfig['provider'] === 'TravelTime'
            ? new TravelTimeProvider(
                $routingConfig['traveltime_base_url'],
                $routingConfig['traveltime_app_id'],
                $routingConfig['traveltime_api_key'],
                $calibration
            )
            : new ValhallaProvider($routingConfig['base_url']);
        $cacheKey = 'routing-matrix-v3-' . sha1(json_encode([$origin, $cities, $provider->name(), $calibrationVersion, $calibration]));
        $cached = app_cache_read($cacheKey, $ttl);
        if ($cached !== null) {
            app_json_response([
                'ok' => true,
                'provider' => $provider->name(),
                'origin' => $origin,
                'scope' => $scope,
                'cities' => $cached['data'],
                'cache' => ['hit' => true, 'stale' => false, 'ageSeconds' => $cached['age']],
            ]);
        }

        try {
            $routes = $provider->matrix($origin, $cities);
            if ($scope === 'settlements') {
                $maximumVisibleMinutes = (int) $config['slider']['max'] + (int) $routingConfig['near_margin_minutes'];
                $routes = array_values(array_filter(
                    $routes,
                    static fn (array $route): bool => ($route['routingRole'] ?? 'reference') === 'reference'
                        || (isset($route['travelSeconds']) && round((float) $route['travelSeconds'] / 60) <= $maximumVisibleMinutes)
                ));
            }
            app_cache_write($cacheKey, $routes);
            app_json_response([
                'ok' => true,
                'provider' => $provider->name(),
                'origin' => $origin,
                'scope' => $scope,
                'cities' => $routes,
                'cache' => ['hit' => false, 'stale' => false, 'ageSeconds' => 0],
            ]);
        } catch (Throwable $exception) {
            $stale = app_cache_read($cacheKey, $ttl, true);
            if ($stale !== null) {
                app_json_response([
                    'ok' => true,
                    'provider' => $provider->name(),
                    'origin' => $origin,
                    'scope' => $scope,
                    'cities' => $stale['data'],
                    'warning' => 'Routingtjenesten kunne ikke nås; senest gemte køretider vises.',
                    'cache' => ['hit' => true, 'stale' => true, 'ageSeconds' => $stale['age']],
                ]);
            }
            throw $exception;
        }
    }

    if ($action === 'isochrone') {
        if ($routingConfig['isochrone_provider'] === 'GoogleIsochrones' && $routingConfig['google_isochrones_api_key'] === '') {
            app_json_response([
                'ok' => false,
                'error' => 'Google-testmiljøet mangler endnu sin server-side Isochrones API-nøgle.',
            ], 424);
        }
        $provider = $routingConfig['isochrone_provider'] === 'GoogleIsochrones'
            ? new GoogleIsochronesProvider(
                $routingConfig['google_isochrones_base_url'],
                $routingConfig['google_isochrones_api_key']
            )
            : ($routingConfig['provider'] === 'TravelTime'
                ? new TravelTimeProvider(
                    $routingConfig['traveltime_base_url'],
                    $routingConfig['traveltime_app_id'],
                    $routingConfig['traveltime_api_key'],
                    $calibration
                )
                : new ValhallaProvider($routingConfig['base_url']));
        $minutes = filter_input(INPUT_GET, 'minutes', FILTER_VALIDATE_INT);
        $slider = $config['slider'];
        if ($minutes === false || $minutes === null || $minutes < $slider['min'] || $minutes > $slider['max'] || $minutes % $slider['step'] !== 0) {
            app_json_response(['ok' => false, 'error' => sprintf('minutes skal være %d–%d i trin på %d.', $slider['min'], $slider['max'], $slider['step'])], 422);
        }

        $cacheKey = 'routing-isochrone-' . sha1(json_encode([$origin, $minutes, $provider->name(), $calibrationVersion, $calibration]));
        // Google Maps-indhold gemmes ikke i vores filcache; hver brugerhandling henter en frisk zone.
        $cacheAllowed = $provider->name() !== 'GoogleIsochrones';
        $cached = $cacheAllowed ? app_cache_read($cacheKey, $ttl) : null;
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
            if ($cacheAllowed) app_cache_write($cacheKey, $geojson);
            app_json_response([
                'ok' => true,
                'provider' => $provider->name(),
                'minutes' => $minutes,
                'geojson' => $geojson,
                'cache' => ['hit' => false, 'stale' => false, 'ageSeconds' => 0],
            ]);
        } catch (Throwable $exception) {
            $stale = $cacheAllowed ? app_cache_read($cacheKey, $ttl, true) : null;
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

function app_routing_settlements(array $config): array
{
    $geography = app_load_analysis_geography($config);
    $settlements = is_array($geography['settlements'] ?? null) ? $geography['settlements'] : [];
    $year = isset($geography['year']) ? (int) $geography['year'] : null;
    $settlementsByKey = [];
    foreach ($settlements as $settlement) {
        $settlementsByKey[app_routing_place_key($settlement)] = $settlement;
    }

    $destinations = [];
    $usedKeys = [];
    foreach ($config['cities'] as $city) {
        $key = app_routing_place_key($city);
        $settlement = $settlementsByKey[$key] ?? [];
        $destinations[] = array_merge($settlement, $city, [
            'populationYear' => $year,
            'routingRole' => 'reference',
        ]);
        $usedKeys[$key] = true;
    }
    foreach ($settlements as $settlement) {
        $key = app_routing_place_key($settlement);
        if (isset($usedKeys[$key])) {
            continue;
        }
        $destinations[] = array_merge($settlement, [
            'id' => 'by3-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', (string) $settlement['id']),
            'populationYear' => $year,
            'routingRole' => 'settlement',
        ]);
    }

    return $destinations;
}

function app_routing_place_key(array $place): string
{
    $name = preg_replace('/ \(del af flere kommuner\)$/u', '', trim((string) ($place['name'] ?? '')));
    $name = function_exists('mb_strtolower')
        ? mb_strtolower((string) $name, 'UTF-8')
        : strtolower(strtr((string) $name, ['Æ' => 'æ', 'Ø' => 'ø', 'Å' => 'å']));

    return (string) ($place['municipalityCode'] ?? '') . '|' . $name;
}
