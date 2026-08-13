<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/Routing/GoogleRoutesMatrixClient.php';
require_once __DIR__ . '/../lib/Routing/TravelTimeProvider.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Værktøjet må kun køres fra kommandolinjen.\n");
    exit(1);
}

$config = require __DIR__ . '/../config/app.php';
$routing = $config['routing'];
$google = new GoogleRoutesMatrixClient(
    $routing['google_routes_base_url'],
    $routing['google_routes_api_key']
);

if (in_array('--probe', $argv, true)) {
    $elements = $google->matrix([$config['origins']['baekmarksbro']], [$config['cities'][0]]);
    echo json_encode([
        'ok' => count($elements) === 1 && $elements[0]['condition'] === 'ROUTE_EXISTS',
        'provider' => 'GoogleRoutes',
        'routes' => count($elements),
        'condition' => $elements[0]['condition'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
    exit(0);
}

$origins = array_values($config['origins']);
$cities = array_values($config['cities']);
$googleElements = $google->matrix($origins, $cities);
$googleByPair = [];
foreach ($googleElements as $element) {
    $googleByPair[$element['originIndex'] . ':' . $element['destinationIndex']] = $element;
}

$travelTime = new TravelTimeProvider(
    $routing['traveltime_base_url'],
    $routing['traveltime_app_id'],
    $routing['traveltime_api_key'],
    ['intercept_seconds' => 0, 'slope' => 1.0]
);
$pairs = [];
foreach ($origins as $originIndex => $origin) {
    fwrite(STDERR, sprintf("TravelTime %d/%d: %s\n", $originIndex + 1, count($origins), $origin['name']));
    $routes = $travelTime->matrix($origin, $cities);
    foreach ($routes as $destinationIndex => $route) {
        $googleRoute = $googleByPair[$originIndex . ':' . $destinationIndex] ?? null;
        $rawSeconds = isset($route['travelSeconds']) ? (int) $route['travelSeconds'] : null;
        $googleSeconds = is_array($googleRoute) ? $googleRoute['durationSeconds'] : null;
        if ($rawSeconds === null || $googleSeconds === null || ($googleRoute['condition'] ?? '') !== 'ROUTE_EXISTS') {
            continue;
        }
        $pairs[] = [
            'originId' => $origin['id'],
            'origin' => $origin['name'],
            'destinationId' => $route['id'],
            'destination' => $route['name'],
            'travelTimeSeconds' => $rawSeconds,
            'googleSeconds' => $googleSeconds,
            'googleDistanceMeters' => $googleRoute['distanceMeters'],
        ];
    }
}

$trainingPairs = array_values(array_filter(
    $pairs,
    static fn (array $pair): bool => $pair['travelTimeSeconds'] >= 300
        && $pair['googleSeconds'] >= 300
        && $pair['googleSeconds'] <= 7200
));
$models = fitOriginModels($trainingPairs);

echo json_encode([
    'generatedAt' => gmdate(DATE_ATOM),
    'reference' => [
        'provider' => 'Google Routes API',
        'routingPreference' => 'TRAFFIC_UNAWARE',
        'origins' => count($origins),
        'destinations' => count($cities),
        'matrixElements' => count($googleElements),
        'trainingPairs' => count($trainingPairs),
    ],
    'validation' => [
        'identityMaeMinutes' => meanAbsoluteError($trainingPairs, static fn (array $pair): float => $pair['travelTimeSeconds']),
        'calibratedMaeMinutes' => meanAbsoluteError(
            $trainingPairs,
            static function (array $pair) use ($models): float {
                $model = $models[$pair['originId']];
                return $pair['travelTimeSeconds'] * $model['slope'] + $model['intercept_seconds'];
            }
        ),
    ],
    'models' => $models,
    'pairs' => $pairs,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";

/** @return array<string,array{intercept_seconds:int,slope:float,samples:int,mae_minutes:float}> */
function fitOriginModels(array $pairs): array
{
    $byOrigin = [];
    foreach ($pairs as $pair) {
        $byOrigin[$pair['originId']][] = $pair;
    }

    $models = [];
    foreach ($byOrigin as $originId => $originPairs) {
        $count = count($originPairs);
        $meanX = array_sum(array_column($originPairs, 'travelTimeSeconds')) / $count;
        $meanY = array_sum(array_column($originPairs, 'googleSeconds')) / $count;
        $covariance = 0.0;
        $variance = 0.0;
        foreach ($originPairs as $pair) {
            $xDelta = $pair['travelTimeSeconds'] - $meanX;
            $covariance += $xDelta * ($pair['googleSeconds'] - $meanY);
            $variance += $xDelta * $xDelta;
        }
        if ($variance <= 0) {
            throw new RuntimeException('Kalibreringen mangler variation i ruterne for ' . $originId . '.');
        }
        $slope = $covariance / $variance;
        $intercept = $meanY - $slope * $meanX;
        $model = [
            'intercept_seconds' => (int) round($intercept),
            'slope' => round($slope, 6),
            'samples' => $count,
        ];
        $model['mae_minutes'] = meanAbsoluteError(
            $originPairs,
            static fn (array $pair): float => $pair['travelTimeSeconds'] * $model['slope'] + $model['intercept_seconds']
        );
        $models[$originId] = $model;
    }

    return $models;
}

function meanAbsoluteError(array $pairs, callable $predict): float
{
    if ($pairs === []) {
        return 0.0;
    }
    $absoluteSeconds = 0.0;
    foreach ($pairs as $pair) {
        $absoluteSeconds += abs($predict($pair) - $pair['googleSeconds']);
    }

    return round($absoluteSeconds / count($pairs) / 60, 2);
}
