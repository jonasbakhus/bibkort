<?php

declare(strict_types=1);

final class GoogleIsochronesProvider
{
    public function __construct(
        private string $baseUrl,
        private string $apiKey,
        private string $routingPreference = 'TRAFFIC_UNAWARE',
        private string $polygonFidelity = 'MEDIUM'
    ) {
        $this->baseUrl = rtrim($this->baseUrl, '/');
        if ($this->apiKey === '') {
            throw new RuntimeException('Google Isochrones mangler en server-side API-nøgle.');
        }
    }

    public function name(): string
    {
        return 'GoogleIsochrones';
    }

    public function isochrone(array $origin, int $minutes): array
    {
        if ($minutes < 1 || $minutes > 60) {
            throw new InvalidArgumentException('Google DRIVE-zoner understøtter højst 60 minutter.');
        }

        $payload = [
            'location' => [
                'latitude' => (float) $origin['lat'],
                'longitude' => (float) $origin['lon'],
            ],
            'travelDuration' => ($minutes * 60) . 's',
            'travelMode' => 'DRIVE',
            'travelDirection' => 'FROM',
            'routingPreference' => $this->routingPreference,
            'enableSmoothing' => false,
            'polygonFidelity' => $this->polygonFidelity,
        ];
        $body = app_http_post_json(
            $this->baseUrl . '/v1/isochrones:generate',
            $payload,
            ['X-Goog-Api-Key: ' . $this->apiKey],
            60
        );
        $data = app_json_decode($body, 'Google Isochrones');
        $geoJson = $data['isochrone']['geoJson'] ?? null;

        if (!is_array($geoJson) || !is_string($geoJson['type'] ?? null)) {
            throw new RuntimeException('Google returnerede ikke en gyldig køretidsflade.');
        }

        return match ($geoJson['type']) {
            'FeatureCollection' => $geoJson,
            'Feature' => ['type' => 'FeatureCollection', 'features' => [$geoJson]],
            'Polygon', 'MultiPolygon' => [
                'type' => 'FeatureCollection',
                'features' => [[
                    'type' => 'Feature',
                    'properties' => ['provider' => 'Google Maps'],
                    'geometry' => $geoJson,
                ]],
            ],
            default => throw new RuntimeException('Google returnerede en ukendt GeoJSON-type.'),
        };
    }
}
