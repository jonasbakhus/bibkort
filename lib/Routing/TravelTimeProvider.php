<?php

declare(strict_types=1);

final class TravelTimeProvider
{
    private float $interceptSeconds;
    private float $slope;

    public function __construct(
        private string $baseUrl,
        private string $appId,
        private string $apiKey,
        array $calibration = []
    ) {
        $this->baseUrl = rtrim($this->baseUrl, '/');
        $this->interceptSeconds = (float) ($calibration['intercept_seconds'] ?? 0.0);
        $this->slope = (float) ($calibration['slope'] ?? 1.0);
        if ($this->appId === '' || $this->apiKey === '') {
            throw new RuntimeException('TravelTime mangler Application ID eller Application Key.');
        }
        if ($this->slope < 0.5 || $this->slope > 1.5 || abs($this->interceptSeconds) > 1800) {
            throw new RuntimeException('TravelTime-kalibreringen er ugyldig.');
        }
    }

    public function name(): string
    {
        return 'TravelTime';
    }

    public function isochrone(array $origin, int $minutes): array
    {
        $payload = [
            'arrival_searches' => [
                'one_to_many' => [[
                    'id' => 'isochrone',
                    'coords' => $this->coords($origin),
                    'transportation' => ['type' => 'driving'],
                    // Vend den kalibrerede tidskurve om, så polygon og viste matrix bruger samme model.
                    'travel_time' => $this->rawSecondsForCalibrated($minutes * 60),
                    'arrival_time_period' => 'weekday_morning',
                ]],
            ],
        ];
        $data = $this->request('/time-map/fast', $payload, ['Accept: application/geo+json']);
        if (($data['type'] ?? null) !== 'FeatureCollection' || !is_array($data['features'] ?? null)) {
            throw new RuntimeException('TravelTime returnerede ikke en gyldig køretidsflade.');
        }

        return $data;
    }

    public function matrix(array $origin, array $cities): array
    {
        $routes = [];
        // Store /time-filter/fast-kald kan skifte beregningsadfærd tæt på tjenestens
        // praktiske lokationsgrænse. Behold derfor 50 destinationer pr. søgning, men saml
        // op til de dokumenterede 10 søgninger i ét HTTP-kald. Det undgår at ramme
        // kontoens request-rate, når en ny matrix endnu ikke findes i servercachen.
        $cityBatches = array_chunk($cities, 50);
        foreach (array_chunk($cityBatches, 10) as $searchGroup) {
            $routes = array_merge($routes, $this->matrixBatchGroup($origin, $searchGroup));
        }

        return $routes;
    }

    /**
     * Beregn mange lokale udgangspunkter mod nogle få destinationer i ét API-kald.
     *
     * Metoden bruges kun af den offline heatmap-generator. Den returnerer rå
     * TravelTime-sekunder, fordi hvert 500-meterpunkt efterfølgende får sin egen
     * geografisk interpolerede Google-kalibrering.
     *
     * @return array<string, array<string, int|null>> destination-id => origin-id => sekunder
     */
    public function rawManyToOneMatrix(array $origins, array $destinations): array
    {
        if ($origins === [] || $destinations === []) {
            return [];
        }
        if (count($destinations) > 10) {
            throw new InvalidArgumentException('TravelTime tillader højst 10 matrixsøgninger pr. kald.');
        }

        $locations = [];
        $originIds = [];
        foreach ($origins as $index => $origin) {
            $originId = 'heat-origin-' . $index;
            $originIds[$originId] = (string) $origin['id'];
            $locations[] = ['id' => $originId, 'coords' => $this->coords($origin)];
        }

        $searches = [];
        $destinationIds = [];
        foreach ($destinations as $index => $destination) {
            $locationId = 'heat-destination-' . $index;
            $searchId = 'heat-search-' . $index;
            $destinationIds[$searchId] = (string) $destination['id'];
            $locations[] = ['id' => $locationId, 'coords' => $this->coords($destination)];
            $searches[] = [
                'id' => $searchId,
                'departure_location_ids' => array_keys($originIds),
                'arrival_location_id' => $locationId,
                'transportation' => ['type' => 'driving'],
                'travel_time' => 3 * 60 * 60,
                'arrival_time_period' => 'weekday_morning',
                'properties' => ['travel_time'],
            ];
        }

        $data = $this->request('/time-filter/fast', [
            'locations' => $locations,
            'arrival_searches' => ['many_to_one' => $searches],
        ]);
        if (!is_array($data['results'] ?? null)) {
            throw new RuntimeException('TravelTime returnerede ikke en gyldig heatmap-matrix.');
        }

        $matrix = [];
        foreach ($data['results'] as $result) {
            $searchId = (string) ($result['search_id'] ?? '');
            $destinationId = $destinationIds[$searchId] ?? null;
            if ($destinationId === null || !is_array($result['locations'] ?? null)) {
                continue;
            }
            foreach ($result['locations'] as $route) {
                $originId = $originIds[(string) ($route['id'] ?? '')] ?? null;
                if ($originId === null) {
                    continue;
                }
                $seconds = $route['properties']['travel_time'] ?? null;
                $matrix[$destinationId][$originId] = is_numeric($seconds) ? (int) round((float) $seconds) : null;
            }
            foreach ($result['unreachable'] ?? [] as $unreachable) {
                $originId = $originIds[(string) ($unreachable['id'] ?? '')] ?? null;
                if ($originId !== null && !array_key_exists($originId, $matrix[$destinationId] ?? [])) {
                    $matrix[$destinationId][$originId] = null;
                }
            }
        }

        return $matrix;
    }

    /**
     * @param array<int, array<int, array<string, mixed>>> $cityBatches
     * @return array<int, array<string, mixed>>
     */
    private function matrixBatchGroup(array $origin, array $cityBatches): array
    {
        $locations = [['id' => 'origin', 'coords' => $this->coords($origin)]];
        $searches = [];
        $definitions = [];
        foreach ($cityBatches as $searchIndex => $cities) {
            $searchId = 'matrix-' . $searchIndex;
            $destinationIds = [];
            foreach ($cities as $destinationIndex => $city) {
                $destinationId = 'destination-' . $searchIndex . '-' . $destinationIndex;
                $destinationIds[] = $destinationId;
                $locations[] = ['id' => $destinationId, 'coords' => $this->coords($city)];
            }
            $searches[] = [
                'id' => $searchId,
                'departure_location_id' => 'origin',
                'arrival_location_ids' => $destinationIds,
                'transportation' => ['type' => 'driving'],
                'travel_time' => 3 * 60 * 60,
                'arrival_time_period' => 'weekday_morning',
                'properties' => ['travel_time', 'distance'],
            ];
            $definitions[] = [
                'searchId' => $searchId,
                'cities' => $cities,
                'destinationIds' => $destinationIds,
            ];
        }

        $payload = [
            'locations' => $locations,
            'arrival_searches' => ['one_to_many' => $searches],
        ];
        $data = $this->request('/time-filter/fast', $payload);
        $results = $data['results'] ?? null;
        if (!is_array($results)) {
            throw new RuntimeException('TravelTime returnerede ikke en gyldig køretidsmatrix.');
        }

        $resultsBySearch = [];
        foreach ($results as $result) {
            if (is_array($result) && is_string($result['search_id'] ?? null)) {
                $resultsBySearch[$result['search_id']] = $result;
            }
        }

        $routes = [];
        foreach ($definitions as $definition) {
            $result = $resultsBySearch[$definition['searchId']] ?? null;
            if (!is_array($result) || !is_array($result['locations'] ?? null)) {
                throw new RuntimeException('TravelTime returnerede ikke en komplet køretidsmatrix.');
            }
            $routesById = [];
            foreach ($result['locations'] as $route) {
                if (is_array($route) && is_string($route['id'] ?? null)) {
                    $routesById[$route['id']] = $route['properties'] ?? [];
                }
            }
            foreach ($definition['cities'] as $index => $city) {
                $properties = $routesById[$definition['destinationIds'][$index]] ?? [];
                $routes[] = array_merge($city, [
                    'travelSeconds' => isset($properties['travel_time'])
                        ? $this->calibratedSeconds((float) $properties['travel_time'])
                        : null,
                    'distanceKm' => isset($properties['distance']) ? round((float) $properties['distance'] / 1000, 1) : null,
                ]);
            }
        }

        return $routes;
    }

    private function coords(array $location): array
    {
        return ['lat' => (float) $location['lat'], 'lng' => (float) $location['lon']];
    }

    private function calibratedSeconds(float $rawSeconds): int
    {
        return max(0, (int) round($rawSeconds * $this->slope + $this->interceptSeconds));
    }

    private function rawSecondsForCalibrated(int $calibratedSeconds): int
    {
        return max(1, (int) round(($calibratedSeconds - $this->interceptSeconds) / $this->slope));
    }

    private function request(string $path, array $payload, array $headers = []): array
    {
        $authHeaders = [
            'X-Application-Id: ' . $this->appId,
            'X-Api-Key: ' . $this->apiKey,
        ];
        $attempts = 3;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $body = app_http_post_json($this->baseUrl . $path, $payload, array_merge($authHeaders, $headers), 60);

                return app_json_decode($body, 'TravelTime');
            } catch (RuntimeException $exception) {
                $retryable = preg_match('/HTTP (429|5\d\d)\b/', $exception->getMessage()) === 1;
                if (!$retryable || $attempt === $attempts) {
                    throw $exception;
                }
                usleep($attempt * 1000000);
            }
        }

        throw new RuntimeException('TravelTime kunne ikke kontaktes.');
    }
}
