<?php

declare(strict_types=1);

function app_fetch_analysis_geography(array $config): array
{
    $municipalities = [];
    foreach ($config['cities'] as $city) {
        $municipalities[$city['municipalityCode']] = $city['municipality'];
    }
    ksort($municipalities);

    $table = $config['geography']['population_table'];
    $metadata = app_json_decode(
        app_http_get('https://api.statbank.dk/v1/tableinfo/' . rawurlencode($table) . '?lang=da', 30),
        'Statistikbanken'
    );

    $latestYear = null;
    $areaValues = [];
    foreach ($metadata['variables'] ?? [] as $variable) {
        if (($variable['time'] ?? false) === true) {
            foreach ($variable['values'] ?? [] as $value) {
                if (preg_match('/^\d{4}$/', (string) ($value['id'] ?? '')) === 1) {
                    $year = (int) $value['id'];
                    $latestYear = $latestYear === null ? $year : max($latestYear, $year);
                }
            }
        }
        if (($variable['id'] ?? '') === 'BYER') {
            $areaValues = $variable['values'] ?? [];
        }
    }
    if ($latestYear === null || $areaValues === []) {
        throw new RuntimeException('BY3-metadata mangler byområder eller år.');
    }

    $selectedAreas = [];
    foreach ($areaValues as $value) {
        $id = (string) ($value['id'] ?? '');
        $code = substr($id, 0, 3);
        if (!isset($municipalities[$code]) || preg_match('/9999[79]$/', $id) === 1) {
            continue;
        }
        $name = preg_replace('/^\d{3}-\d+\s+/u', '', (string) ($value['text'] ?? ''));
        $selectedAreas[$id] = [
            'id' => $id,
            'name' => is_string($name) ? $name : $id,
            'municipalityCode' => $code,
        ];
    }

    $populationRows = [];
    // Statistikbankens GET-endpoint returnerer 404 ved meget lange URL'er, så byområderne hentes i blokke.
    foreach (array_chunk(array_keys($selectedAreas), 75) as $areaChunk) {
        $query = http_build_query([
            'valuePresentation' => 'Code',
            'BYER' => implode(',', $areaChunk),
            'FOLKARTAET' => 'FOLKETAL',
            'Tid' => (string) $latestYear,
        ], '', '&', PHP_QUERY_RFC3986);
        $query = str_replace('%2C', ',', $query);
        $populationRows = array_merge(
            $populationRows,
            app_parse_semicolon_csv(
                app_http_get('https://api.statbank.dk/v1/data/' . rawurlencode($table) . '/CSV?' . $query, 45)
            )
        );
    }
    $populationByArea = [];
    foreach ($populationRows as $row) {
        $value = str_replace(',', '.', trim((string) ($row['INDHOLD'] ?? '')));
        if (is_numeric($value)) {
            $populationByArea[(string) ($row['BYER'] ?? '')] = (int) round((float) $value);
        }
    }

    $boundaryFeatures = [];
    $places = [];
    foreach ($municipalities as $code => $name) {
        $code = (string) $code;
        $dawaCode = str_pad($code, 4, '0', STR_PAD_LEFT);
        $boundary = app_json_decode(
            app_http_get('https://api.dataforsyningen.dk/kommuner/' . rawurlencode($dawaCode) . '?format=geojson', 45),
            'Dataforsyningen'
        );
        if (($boundary['type'] ?? null) !== 'Feature' || !is_array($boundary['geometry'] ?? null)) {
            throw new RuntimeException('Dataforsyningen returnerede en ugyldig kommunegrænse.');
        }
        $boundary['properties'] = array_merge($boundary['properties'] ?? [], [
            'code' => $code,
            'name' => $name,
        ]);
        $boundary['geometry'] = app_simplify_geometry(
            $boundary['geometry'],
            (float) ($config['geography']['boundary_simplify_tolerance'] ?? 0.001)
        );
        $boundaryFeatures[] = $boundary;

        $placeData = app_json_decode(
            app_http_get(
                'https://api.dataforsyningen.dk/steder?hovedtype=Bebyggelse&kommunekode=' . rawurlencode($dawaCode) . '&format=geojson',
                60
            ),
            'Dataforsyningen'
        );
        foreach ($placeData['features'] ?? [] as $feature) {
            $properties = $feature['properties'] ?? [];
            $placeName = (string) ($properties['primærtnavn'] ?? '');
            if ($placeName === '') {
                continue;
            }
            $places[$code][$placeName] = [
                'lat' => isset($properties['visueltcenter_y']) ? (float) $properties['visueltcenter_y'] : null,
                'lon' => isset($properties['visueltcenter_x']) ? (float) $properties['visueltcenter_x'] : null,
            ];
        }
    }

    $settlements = [];
    foreach ($selectedAreas as $areaId => $area) {
        $population = $populationByArea[$areaId] ?? 0;
        if ($population <= 0) {
            continue;
        }
        $lookupName = preg_replace('/ \(del af flere kommuner\)$/u', '', $area['name']);
        $place = $places[$area['municipalityCode']][$area['name']]
            ?? $places[$area['municipalityCode']][$lookupName]
            ?? null;
        if (!is_array($place) || !is_float($place['lat']) || !is_float($place['lon'])) {
            continue;
        }
        $settlements[] = [
            'id' => $areaId,
            'name' => $area['name'],
            'lat' => $place['lat'],
            'lon' => $place['lon'],
            'population' => $population,
            'municipalityCode' => $area['municipalityCode'],
            'municipality' => $municipalities[$area['municipalityCode']],
        ];
    }

    return [
        'year' => $latestYear,
        'settlements' => $settlements,
        'municipalities' => [
            'type' => 'FeatureCollection',
            'features' => $boundaryFeatures,
        ],
        'weights' => [
            'urban' => (float) $config['geography']['urban_weight'],
            'rural' => (float) $config['geography']['rural_weight'],
            'urbanPopulationExponent' => (float) $config['geography']['urban_population_exponent'],
        ],
        'source' => 'Danmarks Statistik BY3 og Dataforsyningen',
    ];
}

function app_simplify_geometry(array $geometry, float $tolerance): array
{
    if ($tolerance <= 0) {
        return $geometry;
    }

    $type = $geometry['type'] ?? null;
    $coordinates = $geometry['coordinates'] ?? null;
    if (!is_array($coordinates)) {
        return $geometry;
    }

    if ($type === 'Polygon') {
        $geometry['coordinates'] = array_map(
            static fn (array $ring): array => app_simplify_ring($ring, $tolerance),
            $coordinates
        );
    } elseif ($type === 'MultiPolygon') {
        $geometry['coordinates'] = array_map(
            static fn (array $polygon): array => array_map(
                static fn (array $ring): array => app_simplify_ring($ring, $tolerance),
                $polygon
            ),
            $coordinates
        );
    }

    return $geometry;
}

function app_simplify_ring(array $ring, float $tolerance): array
{
    if (count($ring) <= 5) {
        return $ring;
    }

    $points = $ring;
    if ($points[0] === $points[count($points) - 1]) {
        array_pop($points);
    }
    if (count($points) <= 4) {
        return $ring;
    }

    $anchor = $points[0];
    $furthestIndex = 1;
    $furthestDistance = -1.0;
    foreach ($points as $index => $point) {
        $distance = app_coordinate_distance_squared($anchor, $point);
        if ($distance > $furthestDistance) {
            $furthestDistance = $distance;
            $furthestIndex = $index;
        }
    }

    $firstPath = array_slice($points, 0, $furthestIndex + 1);
    $secondPath = array_merge(array_slice($points, $furthestIndex), [$points[0]]);
    $firstSimplified = app_simplify_line($firstPath, $tolerance);
    $secondSimplified = app_simplify_line($secondPath, $tolerance);
    $simplified = array_merge(
        array_slice($firstSimplified, 0, -1),
        array_slice($secondSimplified, 0, -1)
    );

    if (count($simplified) < 3) {
        return $ring;
    }
    $simplified[] = $simplified[0];

    return $simplified;
}

function app_simplify_line(array $points, float $tolerance): array
{
    $lastIndex = count($points) - 1;
    if ($lastIndex < 2) {
        return $points;
    }

    $keep = [0 => true, $lastIndex => true];
    $stack = [[0, $lastIndex]];
    $toleranceSquared = $tolerance * $tolerance;

    while ($stack !== []) {
        [$start, $end] = array_pop($stack);
        $maximumDistance = 0.0;
        $maximumIndex = null;
        for ($index = $start + 1; $index < $end; $index += 1) {
            $distance = app_segment_distance_squared($points[$index], $points[$start], $points[$end]);
            if ($distance > $maximumDistance) {
                $maximumDistance = $distance;
                $maximumIndex = $index;
            }
        }
        if ($maximumIndex !== null && $maximumDistance > $toleranceSquared) {
            $keep[$maximumIndex] = true;
            $stack[] = [$start, $maximumIndex];
            $stack[] = [$maximumIndex, $end];
        }
    }

    ksort($keep);
    return array_map(static fn (int $index): array => $points[$index], array_keys($keep));
}

function app_segment_distance_squared(array $point, array $start, array $end): float
{
    $dx = (float) $end[0] - (float) $start[0];
    $dy = (float) $end[1] - (float) $start[1];
    if ($dx === 0.0 && $dy === 0.0) {
        return app_coordinate_distance_squared($point, $start);
    }

    $projection = (((float) $point[0] - (float) $start[0]) * $dx
        + ((float) $point[1] - (float) $start[1]) * $dy) / ($dx * $dx + $dy * $dy);
    $projection = max(0.0, min(1.0, $projection));
    $nearest = [
        (float) $start[0] + $projection * $dx,
        (float) $start[1] + $projection * $dy,
    ];

    return app_coordinate_distance_squared($point, $nearest);
}

function app_coordinate_distance_squared(array $first, array $second): float
{
    $dx = (float) $first[0] - (float) $second[0];
    $dy = (float) $first[1] - (float) $second[1];
    return $dx * $dx + $dy * $dy;
}

function app_parse_semicolon_csv(string $csv): array
{
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
    $lines = preg_split('/\r\n|\n|\r/', trim($csv));
    if (!is_array($lines) || count($lines) < 2) {
        throw new RuntimeException('Datakilden returnerede ingen datarækker.');
    }

    $headers = str_getcsv((string) array_shift($lines), ';');
    $rows = [];
    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }
        $values = str_getcsv($line, ';');
        if (count($values) === count($headers)) {
            $row = array_combine($headers, $values);
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
    }

    return $rows;
}
