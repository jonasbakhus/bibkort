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
        $destinationIds = [];
        $locations = [['id' => 'origin', 'coords' => $this->coords($origin)]];
        foreach ($cities as $index => $city) {
            $destinationId = 'destination-' . $index;
            $destinationIds[] = $destinationId;
            $locations[] = ['id' => $destinationId, 'coords' => $this->coords($city)];
        }

        $payload = [
            'locations' => $locations,
            'arrival_searches' => [
                'one_to_many' => [[
                    'id' => 'matrix',
                    'departure_location_id' => 'origin',
                    'arrival_location_ids' => $destinationIds,
                    'transportation' => ['type' => 'driving'],
                    'travel_time' => 3 * 60 * 60,
                    'arrival_time_period' => 'weekday_morning',
                    'properties' => ['travel_time', 'distance'],
                ]],
            ],
        ];
        $data = $this->request('/time-filter/fast', $payload);
        $results = $data['results'] ?? null;
        if (!is_array($results) || !is_array($results[0]['locations'] ?? null)) {
            throw new RuntimeException('TravelTime returnerede ikke en gyldig køretidsmatrix.');
        }

        $routesById = [];
        foreach ($results[0]['locations'] as $route) {
            if (is_array($route) && is_string($route['id'] ?? null)) {
                $routesById[$route['id']] = $route['properties'] ?? [];
            }
        }

        return array_map(
            function (array $city, string $destinationId) use ($routesById): array {
                $properties = $routesById[$destinationId] ?? [];

                return array_merge($city, [
                    'travelSeconds' => isset($properties['travel_time'])
                        ? $this->calibratedSeconds((float) $properties['travel_time'])
                        : null,
                    'distanceKm' => isset($properties['distance']) ? round((float) $properties['distance'] / 1000, 1) : null,
                ]);
            },
            $cities,
            $destinationIds
        );
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
        $body = app_http_post_json($this->baseUrl . $path, $payload, array_merge($authHeaders, $headers), 60);

        return app_json_decode($body, 'TravelTime');
    }
}
