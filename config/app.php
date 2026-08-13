<?php

declare(strict_types=1);

$secrets = [];
$secretsFile = __DIR__ . '/secrets.php';
if (is_file($secretsFile)) {
    $loadedSecrets = require $secretsFile;
    if (is_array($loadedSecrets)) {
        $secrets = $loadedSecrets;
    }
}

$travelTimeAppId = getenv('BIBKORT_TRAVELTIME_APP_ID') ?: ($secrets['traveltime_app_id'] ?? '');
$travelTimeApiKey = getenv('BIBKORT_TRAVELTIME_API_KEY') ?: ($secrets['traveltime_api_key'] ?? '');
$preferredRoutingProvider = $travelTimeAppId !== '' && $travelTimeApiKey !== '' ? 'TravelTime' : 'Valhalla';

return [
    'name' => 'Bo i Bækmarksbro – arbejdsmarkedskort',
    'default_origin' => 'baekmarksbro',
    'origins' => [
        'baekmarksbro' => [
            'id' => 'baekmarksbro',
            'name' => 'Bækmarksbro',
            'description' => 'Primær',
            'lat' => 56.42039773538234,
            'lon' => 8.30641623581447,
        ],
        'thyboroen' => [
            'id' => 'thyboroen',
            'name' => 'Thyborøn',
            'description' => 'Nord',
            'lat' => 56.7038,
            'lon' => 8.2127,
        ],
        'lemvig' => [
            'id' => 'lemvig',
            'name' => 'Lemvig',
            'description' => 'Byen',
            'lat' => 56.5484,
            'lon' => 8.3102,
        ],
        'vilhelmsborgvej' => [
            'id' => 'vilhelmsborgvej',
            'name' => 'Vilhelmsborgvej',
            'description' => 'Kommunegrænsen',
            'lat' => 56.4292803589424,
            'lon' => 8.409031699370793,
        ],
    ],
    'slider' => [
        'min' => 15,
        'max' => 90,
        'step' => 5,
        'default' => 45,
    ],
    'routing' => [
        'provider' => getenv('BIBKORT_ROUTING_PROVIDER') ?: $preferredRoutingProvider,
        'base_url' => getenv('BIBKORT_VALHALLA_URL') ?: 'https://valhalla1.openstreetmap.de',
        'traveltime_base_url' => 'https://api.traveltimeapp.com/v4',
        'traveltime_app_id' => (string) $travelTimeAppId,
        'traveltime_api_key' => (string) $travelTimeApiKey,
        'cache_ttl' => 30 * 24 * 60 * 60,
    ],
    'statbank' => [
        'table' => 'ERHV2',
        'cache_ttl' => 7 * 24 * 60 * 60,
    ],
    // Byer og deres kommune er samlet her, så listen kan udvides ét sted.
    'cities' => [
        ['id' => 'lemvig', 'name' => 'Lemvig', 'lat' => 56.5484, 'lon' => 8.3102, 'municipalityCode' => '665', 'municipality' => 'Lemvig'],
        ['id' => 'holstebro', 'name' => 'Holstebro', 'lat' => 56.3601, 'lon' => 8.6161, 'municipalityCode' => '661', 'municipality' => 'Holstebro'],
        ['id' => 'struer', 'name' => 'Struer', 'lat' => 56.4929, 'lon' => 8.5935, 'municipalityCode' => '671', 'municipality' => 'Struer'],
        ['id' => 'ringkoebing', 'name' => 'Ringkøbing', 'lat' => 56.0901, 'lon' => 8.2440, 'municipalityCode' => '760', 'municipality' => 'Ringkøbing-Skjern'],
        ['id' => 'skjern', 'name' => 'Skjern', 'lat' => 55.9469339, 'lon' => 8.4909186, 'municipalityCode' => '760', 'municipality' => 'Ringkøbing-Skjern'],
        ['id' => 'herning', 'name' => 'Herning', 'lat' => 56.1362, 'lon' => 8.9766, 'municipalityCode' => '657', 'municipality' => 'Herning'],
        ['id' => 'skive', 'name' => 'Skive', 'lat' => 56.5669, 'lon' => 9.0271, 'municipalityCode' => '779', 'municipality' => 'Skive'],
        ['id' => 'thisted', 'name' => 'Thisted', 'lat' => 56.9562093, 'lon' => 8.6848749, 'municipalityCode' => '787', 'municipality' => 'Thisted'],
        ['id' => 'ikast', 'name' => 'Ikast', 'lat' => 56.1388, 'lon' => 9.1577, 'municipalityCode' => '756', 'municipality' => 'Ikast-Brande'],
        ['id' => 'brande', 'name' => 'Brande', 'lat' => 55.9445468, 'lon' => 9.1282868, 'municipalityCode' => '756', 'municipality' => 'Ikast-Brande'],
        ['id' => 'silkeborg', 'name' => 'Silkeborg', 'lat' => 56.1694530, 'lon' => 9.5495141, 'municipalityCode' => '740', 'municipality' => 'Silkeborg'],
        ['id' => 'viborg', 'name' => 'Viborg', 'lat' => 56.4532, 'lon' => 9.4020, 'municipalityCode' => '791', 'municipality' => 'Viborg'],
        ['id' => 'bjerringbro', 'name' => 'Bjerringbro', 'lat' => 56.3758414, 'lon' => 9.6565760, 'municipalityCode' => '791', 'municipality' => 'Viborg'],
        ['id' => 'varde', 'name' => 'Varde', 'lat' => 55.6211, 'lon' => 8.4807, 'municipalityCode' => '573', 'municipality' => 'Varde'],
    ],
];
