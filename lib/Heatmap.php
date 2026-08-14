<?php

declare(strict_types=1);

function app_heatmap_grid(array $feature, float $spacingKm): array
{
    $bbox = app_heatmap_bbox($feature['geometry'] ?? []);
    if ($bbox === null || $spacingKm <= 0) {
        return [];
    }

    [$minLon, $minLat, $maxLon, $maxLat] = $bbox;
    $middleLat = ($minLat + $maxLat) / 2;
    $latStep = $spacingKm / 111.32;
    $lonStep = $spacingKm / (111.32 * max(0.2, cos(deg2rad($middleLat))));
    $latStart = ceil($minLat / $latStep) * $latStep;
    $lonStart = ceil($minLon / $lonStep) * $lonStep;
    $points = [];

    for ($lat = $latStart; $lat <= $maxLat; $lat += $latStep) {
        for ($lon = $lonStart; $lon <= $maxLon; $lon += $lonStep) {
            if (!app_heatmap_point_in_geometry($lon, $lat, $feature['geometry'] ?? [])) {
                continue;
            }
            $points[] = [
                'id' => sprintf('grid-%05d', count($points) + 1),
                'lat' => round($lat, 7),
                'lon' => round($lon, 7),
            ];
        }
    }

    if ($points === []) {
        $fallback = app_heatmap_representative_point($feature['geometry'] ?? []);
        if ($fallback !== null) {
            $points[] = ['id' => 'grid-00001', 'lat' => $fallback[1], 'lon' => $fallback[0]];
        }
    }

    return $points;
}

function app_heatmap_bbox(array $geometry): ?array
{
    $points = [];
    app_heatmap_collect_coordinates($geometry['coordinates'] ?? [], $points);
    if ($points === []) {
        return null;
    }

    $lons = array_column($points, 0);
    $lats = array_column($points, 1);

    return [min($lons), min($lats), max($lons), max($lats)];
}

function app_heatmap_collect_coordinates(array $coordinates, array &$points): void
{
    if (isset($coordinates[0], $coordinates[1]) && is_numeric($coordinates[0]) && is_numeric($coordinates[1])) {
        $points[] = [(float) $coordinates[0], (float) $coordinates[1]];
        return;
    }
    foreach ($coordinates as $coordinate) {
        if (is_array($coordinate)) {
            app_heatmap_collect_coordinates($coordinate, $points);
        }
    }
}

function app_heatmap_point_in_geometry(float $lon, float $lat, array $geometry): bool
{
    $type = $geometry['type'] ?? null;
    $coordinates = $geometry['coordinates'] ?? [];
    if ($type === 'Polygon') {
        return app_heatmap_point_in_polygon($lon, $lat, $coordinates);
    }
    if ($type === 'MultiPolygon') {
        foreach ($coordinates as $polygon) {
            if (app_heatmap_point_in_polygon($lon, $lat, $polygon)) {
                return true;
            }
        }
    }

    return false;
}

function app_heatmap_point_in_polygon(float $lon, float $lat, array $rings): bool
{
    if ($rings === [] || !app_heatmap_point_in_ring($lon, $lat, $rings[0])) {
        return false;
    }
    foreach (array_slice($rings, 1) as $hole) {
        if (app_heatmap_point_in_ring($lon, $lat, $hole)) {
            return false;
        }
    }

    return true;
}

function app_heatmap_point_in_ring(float $lon, float $lat, array $ring): bool
{
    $inside = false;
    $count = count($ring);
    for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
        $xi = (float) ($ring[$i][0] ?? 0);
        $yi = (float) ($ring[$i][1] ?? 0);
        $xj = (float) ($ring[$j][0] ?? 0);
        $yj = (float) ($ring[$j][1] ?? 0);
        $crosses = (($yi > $lat) !== ($yj > $lat))
            && ($lon < (($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1.0e-12)) + $xi);
        if ($crosses) {
            $inside = !$inside;
        }
    }

    return $inside;
}

function app_heatmap_representative_point(array $geometry): ?array
{
    $polygons = ($geometry['type'] ?? null) === 'Polygon'
        ? [$geometry['coordinates'] ?? []]
        : ($geometry['coordinates'] ?? []);
    usort($polygons, static fn (array $a, array $b): int => count($b[0] ?? []) <=> count($a[0] ?? []));
    foreach ($polygons as $polygon) {
        $ring = $polygon[0] ?? [];
        if ($ring === []) {
            continue;
        }
        $lon = array_sum(array_map(static fn (array $point): float => (float) $point[0], $ring)) / count($ring);
        $lat = array_sum(array_map(static fn (array $point): float => (float) $point[1], $ring)) / count($ring);
        if (app_heatmap_point_in_polygon($lon, $lat, $polygon)) {
            return [round($lon, 7), round($lat, 7)];
        }
        foreach ($ring as $point) {
            if (isset($point[0], $point[1])) {
                return [(float) $point[0], (float) $point[1]];
            }
        }
    }

    return null;
}

function app_heatmap_distance_km(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earthRadius = 6371.0088;
    $latDelta = deg2rad($lat2 - $lat1);
    $lonDelta = deg2rad($lon2 - $lon1);
    $a = sin($latDelta / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lonDelta / 2) ** 2;

    return $earthRadius * 2 * atan2(sqrt($a), sqrt(max(0, 1 - $a)));
}

function app_heatmap_interpolated_calibration(array $point, array $config, int $neighbours = 4): array
{
    $set = $config['routing']['travel_time_calibration'];
    $default = $set['default'];
    $anchors = [];
    foreach ($config['origins'] as $id => $origin) {
        if (($origin['type'] ?? '') === 'boundary') {
            continue;
        }
        $calibration = $set['origins'][$id] ?? $default;
        $anchors[] = [
            'distance' => app_heatmap_distance_km((float) $point['lat'], (float) $point['lon'], (float) $origin['lat'], (float) $origin['lon']),
            'intercept' => (float) $calibration['intercept_seconds'],
            'slope' => (float) $calibration['slope'],
        ];
    }
    usort($anchors, static fn (array $a, array $b): int => $a['distance'] <=> $b['distance']);
    $anchors = array_slice($anchors, 0, max(1, $neighbours));
    if (($anchors[0]['distance'] ?? INF) < 0.05) {
        return ['intercept_seconds' => $anchors[0]['intercept'], 'slope' => $anchors[0]['slope']];
    }

    $weightTotal = 0.0;
    $intercept = 0.0;
    $slope = 0.0;
    foreach ($anchors as $anchor) {
        $weight = 1 / max(0.01, $anchor['distance'] ** 2);
        $weightTotal += $weight;
        $intercept += $anchor['intercept'] * $weight;
        $slope += $anchor['slope'] * $weight;
    }

    return [
        'intercept_seconds' => $intercept / $weightTotal,
        'slope' => $slope / $weightTotal,
    ];
}

