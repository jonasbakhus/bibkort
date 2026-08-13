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
        ],
        'source' => 'Danmarks Statistik BY3 og Dataforsyningen',
    ];
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
