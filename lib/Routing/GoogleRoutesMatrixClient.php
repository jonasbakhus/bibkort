<?php

declare(strict_types=1);

final class GoogleRoutesMatrixClient
{
    public function __construct(
        private string $baseUrl,
        private string $apiKey
    ) {
        $this->baseUrl = rtrim($this->baseUrl, '/');
        if ($this->apiKey === '') {
            throw new RuntimeException('Google Routes mangler en server-side API-nøgle.');
        }
    }

    /**
     * @return list<array{originIndex:int,destinationIndex:int,durationSeconds:?int,distanceMeters:?int,condition:string,status:array}>
     */
    public function matrix(array $origins, array $destinations): array
    {
        if ($origins === [] || $destinations === []) {
            throw new InvalidArgumentException('Google Routes kræver mindst ét udgangspunkt og ét mål.');
        }
        if (count($origins) * count($destinations) > 625) {
            throw new InvalidArgumentException('Google Routes-matricen må højst indeholde 625 ruter.');
        }

        $payload = [
            'origins' => array_map(fn (array $location): array => [
                'waypoint' => ['location' => ['latLng' => $this->latLng($location)]],
            ], array_values($origins)),
            'destinations' => array_map(fn (array $location): array => [
                'waypoint' => ['location' => ['latLng' => $this->latLng($location)]],
            ], array_values($destinations)),
            'travelMode' => 'DRIVE',
            // Stabil kontrolreference uden et tidspunkt, der ændrer modellen fra dag til dag.
            'routingPreference' => 'TRAFFIC_UNAWARE',
            'regionCode' => 'dk',
        ];
        $fieldMask = 'originIndex,destinationIndex,status,condition,distanceMeters,duration';
        $body = app_http_post_json(
            $this->baseUrl . '/distanceMatrix/v2:computeRouteMatrix',
            $payload,
            [
                'X-Goog-Api-Key: ' . $this->apiKey,
                'X-Goog-FieldMask: ' . $fieldMask,
            ],
            120
        );
        $data = app_json_decode($body, 'Google Routes');
        if ($data !== [] && !isset($data[0])) {
            throw new RuntimeException('Google Routes returnerede ikke en gyldig matrix.');
        }

        $elements = [];
        foreach ($data as $element) {
            if (!is_array($element)) {
                continue;
            }
            $elements[] = [
                'originIndex' => (int) ($element['originIndex'] ?? 0),
                'destinationIndex' => (int) ($element['destinationIndex'] ?? 0),
                'durationSeconds' => $this->durationSeconds($element['duration'] ?? null),
                'distanceMeters' => isset($element['distanceMeters']) ? (int) $element['distanceMeters'] : null,
                'condition' => (string) ($element['condition'] ?? ''),
                'status' => is_array($element['status'] ?? null) ? $element['status'] : [],
            ];
        }

        return $elements;
    }

    private function latLng(array $location): array
    {
        return [
            'latitude' => (float) $location['lat'],
            'longitude' => (float) $location['lon'],
        ];
    }

    private function durationSeconds(mixed $duration): ?int
    {
        if (!is_string($duration) || preg_match('/^([0-9]+(?:\.[0-9]+)?)s$/', $duration, $matches) !== 1) {
            return null;
        }

        return (int) round((float) $matches[1]);
    }
}
