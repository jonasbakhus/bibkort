<?php

declare(strict_types=1);

final class ValhallaProvider
{
    public function __construct(private string $baseUrl)
    {
        $this->baseUrl = rtrim($this->baseUrl, '/');
    }

    public function name(): string
    {
        return 'Valhalla / OpenStreetMap';
    }

    public function isochrone(array $origin, int $minutes): array
    {
        $request = [
            'locations' => [['lat' => $origin['lat'], 'lon' => $origin['lon']]],
            'costing' => 'auto',
            'contours' => [['time' => $minutes, 'color' => '167d73']],
            'polygons' => true,
            'denoise' => 0.5,
            'generalize' => 180,
        ];
        $data = $this->request('/isochrone', $request);
        if (($data['type'] ?? null) !== 'FeatureCollection' || !isset($data['features'])) {
            throw new RuntimeException('Routingtjenesten returnerede ikke en gyldig isochrone.');
        }

        return $data;
    }

    public function matrix(array $origin, array $cities): array
    {
        $request = [
            'sources' => [['lat' => $origin['lat'], 'lon' => $origin['lon']]],
            'targets' => array_map(
                static fn (array $city): array => ['lat' => $city['lat'], 'lon' => $city['lon']],
                $cities
            ),
            'costing' => 'auto',
            'units' => 'kilometers',
        ];
        $data = $this->request('/sources_to_targets', $request);
        $rows = $data['sources_to_targets'][0] ?? null;
        if (!is_array($rows) || count($rows) !== count($cities)) {
            throw new RuntimeException('Routingtjenesten returnerede ikke en komplet køretidsmatrix.');
        }

        return array_map(
            static function (array $city, array $route): array {
                return array_merge($city, [
                    'travelSeconds' => isset($route['time']) ? (int) round((float) $route['time']) : null,
                    'distanceKm' => isset($route['distance']) ? round((float) $route['distance'], 1) : null,
                ]);
            },
            $cities,
            $rows
        );
    }

    private function request(string $path, array $payload): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Routingforespørgslen kunne ikke kodes.');
        }

        return app_json_decode(app_http_get($this->baseUrl . $path . '?json=' . rawurlencode($json), 45), 'Valhalla');
    }
}
