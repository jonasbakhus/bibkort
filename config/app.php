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
    'name' => 'Arbejdsmarkedskort for Lemvig Kommune',
    'default_origin' => 'baekmarksbro',
    'comparison_origin' => 'lemvig',
    'origins' => [
        'lemvig' => [
            'id' => 'lemvig',
            'name' => 'Lemvig',
            'description' => 'Hovedby',
            'lat' => 56.5443443,
            'lon' => 8.3024871,
        ],
        'baekmarksbro' => [
            'id' => 'baekmarksbro',
            'name' => 'Bækmarksbro',
            'description' => 'By',
            'lat' => 56.42039773538234,
            'lon' => 8.30641623581447,
        ],
        'thyboroen' => [
            'id' => 'thyboroen',
            'name' => 'Thyborøn',
            'description' => 'By',
            'lat' => 56.69983,
            'lon' => 8.211547,
        ],
        'harbooere' => [
            'id' => 'harbooere',
            'name' => 'Harboøre',
            'description' => 'By',
            'lat' => 56.6184379,
            'lon' => 8.1819072,
        ],
        'noerre_nissum' => [
            'id' => 'noerre_nissum',
            'name' => 'Nørre Nissum',
            'description' => 'By',
            'lat' => 56.5558378,
            'lon' => 8.4122393,
        ],
        'ramme' => [
            'id' => 'ramme',
            'name' => 'Ramme',
            'description' => 'By',
            'lat' => 56.490206,
            'lon' => 8.2092069,
        ],
        'klinkby' => [
            'id' => 'klinkby',
            'name' => 'Klinkby',
            'description' => 'By',
            'lat' => 56.566942,
            'lon' => 8.2202109,
        ],
        'boevlingbjerg' => [
            'id' => 'boevlingbjerg',
            'name' => 'Bøvlingbjerg',
            'description' => 'By',
            'lat' => 56.435,
            'lon' => 8.2025659,
        ],
        'bonnet' => [
            'id' => 'bonnet', 'name' => 'Bonnet', 'description' => 'Landsby',
            'lat' => 56.521744, 'lon' => 8.223101,
        ],
        'dybe' => [
            'id' => 'dybe', 'name' => 'Dybe', 'description' => 'Landsby',
            'lat' => 56.512324, 'lon' => 8.169481,
        ],
        'fabjerg' => [
            'id' => 'fabjerg', 'name' => 'Fabjerg', 'description' => 'Landsby',
            'lat' => 56.525798, 'lon' => 8.38208,
        ],
        'ferring' => [
            'id' => 'ferring', 'name' => 'Ferring', 'description' => 'Kystlandsby',
            'lat' => 56.5256234, 'lon' => 8.1230918,
        ],
        'fjaltring' => [
            'id' => 'fjaltring', 'name' => 'Fjaltring', 'description' => 'Kystlandsby',
            'lat' => 56.474415, 'lon' => 8.139855,
        ],
        'faare' => [
            'id' => 'faare', 'name' => 'Fåre', 'description' => 'Landsby',
            'lat' => 56.453498, 'lon' => 8.262275,
        ],
        'gudum' => [
            'id' => 'gudum', 'name' => 'Gudum', 'description' => 'Landsby',
            'lat' => 56.520964, 'lon' => 8.4601799,
        ],
        'hove' => [
            'id' => 'hove', 'name' => 'Hove', 'description' => 'Lokalsamfund',
            'lat' => 56.558279, 'lon' => 8.2243359,
        ],
        'langerhuse' => [
            'id' => 'langerhuse', 'name' => 'Langerhuse', 'description' => 'Kystlandsby',
            'lat' => 56.626393, 'lon' => 8.1601079,
        ],
        'lomborg' => [
            'id' => 'lomborg', 'name' => 'Lomborg', 'description' => 'Landsby',
            'lat' => 56.50094, 'lon' => 8.2655259,
        ],
        'moeborg' => [
            'id' => 'moeborg', 'name' => 'Møborg', 'description' => 'Landsby',
            'lat' => 56.390842, 'lon' => 8.35037,
        ],
        'nees' => [
            'id' => 'nees', 'name' => 'Nees', 'description' => 'Landsby',
            'lat' => 56.394943, 'lon' => 8.212378,
        ],
        'remmerstrand' => [
            'id' => 'remmerstrand', 'name' => 'Remmerstrand', 'description' => 'Kystlandsby',
            'lat' => 56.549736, 'lon' => 8.489228,
        ],
        'rom' => [
            'id' => 'rom', 'name' => 'Rom', 'description' => 'Landsby',
            'lat' => 56.523101, 'lon' => 8.309392,
        ],
        'skalstrup' => [
            'id' => 'skalstrup', 'name' => 'Skalstrup', 'description' => 'Landsby',
            'lat' => 56.363244, 'lon' => 8.252967,
        ],
        'toerring_huse' => [
            'id' => 'toerring_huse', 'name' => 'Tørring Huse', 'description' => 'Lokalsamfund',
            'lat' => 56.553782, 'lon' => 8.2401768,
        ],
        'vandborg' => [
            'id' => 'vandborg', 'name' => 'Vandborg', 'description' => 'Landsby',
            'lat' => 56.546435, 'lon' => 8.175085,
        ],
        'vrist' => [
            'id' => 'vrist', 'name' => 'Vrist', 'description' => 'Kystlandsby',
            'lat' => 56.599485, 'lon' => 8.151058,
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
        // Standard: Bækmarksbro–Gødstrup, 2.640 faktiske sekunder / 2.804 modelsekunder.
        'travel_time_factor' => 2640 / 2804,
        // Google Directions-reference for udgangspunkter, hvor standardfaktoren ikke rammer lokalt.
        'origin_time_factors' => [
            'baekmarksbro' => 2640 / 2804,
            'thyboroen' => 2640 / 3296,
        ],
        'cache_ttl' => 30 * 24 * 60 * 60,
    ],
    'statbank' => [
        'table' => 'ERHV2',
        'cache_ttl' => 7 * 24 * 60 * 60,
    ],
    'geography' => [
        'population_table' => 'BY3',
        'urban_weight' => 0.90,
        'rural_weight' => 0.10,
        'cache_ttl' => 30 * 24 * 60 * 60,
    ],
    // Byer og deres kommune er samlet her, så listen kan udvides ét sted.
    'cities' => [
        ['id' => 'lemvig', 'name' => 'Lemvig', 'lat' => 56.5443443, 'lon' => 8.3024871, 'municipalityCode' => '665', 'municipality' => 'Lemvig'],
        ['id' => 'thyboroen', 'name' => 'Thyborøn', 'lat' => 56.69983, 'lon' => 8.211547, 'municipalityCode' => '665', 'municipality' => 'Lemvig'],
        ['id' => 'harbooere', 'name' => 'Harboøre', 'lat' => 56.6184379, 'lon' => 8.1819072, 'municipalityCode' => '665', 'municipality' => 'Lemvig'],
        ['id' => 'noerre_nissum', 'name' => 'Nørre Nissum', 'lat' => 56.5558378, 'lon' => 8.4122393, 'municipalityCode' => '665', 'municipality' => 'Lemvig'],
        ['id' => 'baekmarksbro', 'name' => 'Bækmarksbro', 'lat' => 56.42039773538234, 'lon' => 8.30641623581447, 'municipalityCode' => '665', 'municipality' => 'Lemvig'],
        ['id' => 'boevlingbjerg', 'name' => 'Bøvlingbjerg', 'lat' => 56.435, 'lon' => 8.2025659, 'municipalityCode' => '665', 'municipality' => 'Lemvig'],
        ['id' => 'ramme', 'name' => 'Ramme', 'lat' => 56.490206, 'lon' => 8.2092069, 'municipalityCode' => '665', 'municipality' => 'Lemvig'],
        ['id' => 'lomborg', 'name' => 'Lomborg', 'lat' => 56.50094, 'lon' => 8.2655259, 'municipalityCode' => '665', 'municipality' => 'Lemvig'],
        ['id' => 'klinkby', 'name' => 'Klinkby', 'lat' => 56.566942, 'lon' => 8.2202109, 'municipalityCode' => '665', 'municipality' => 'Lemvig'],
        ['id' => 'gudum', 'name' => 'Gudum', 'lat' => 56.520964, 'lon' => 8.4601799, 'municipalityCode' => '665', 'municipality' => 'Lemvig'],
        ['id' => 'holstebro', 'name' => 'Holstebro', 'lat' => 56.361534, 'lon' => 8.621727, 'municipalityCode' => '661', 'municipality' => 'Holstebro'],
        ['id' => 'struer', 'name' => 'Struer', 'lat' => 56.48493, 'lon' => 8.589933, 'municipalityCode' => '671', 'municipality' => 'Struer'],
        ['id' => 'ringkoebing', 'name' => 'Ringkøbing', 'lat' => 56.088054, 'lon' => 8.2576329, 'municipalityCode' => '760', 'municipality' => 'Ringkøbing-Skjern'],
        ['id' => 'skjern', 'name' => 'Skjern', 'lat' => 55.944128, 'lon' => 8.500266, 'municipalityCode' => '760', 'municipality' => 'Ringkøbing-Skjern'],
        ['id' => 'herning', 'name' => 'Herning', 'lat' => 56.138557, 'lon' => 8.967322, 'municipalityCode' => '657', 'municipality' => 'Herning'],
        ['id' => 'skive', 'name' => 'Skive', 'lat' => 56.5651232, 'lon' => 9.0309083, 'municipalityCode' => '779', 'municipality' => 'Skive'],
        ['id' => 'thisted', 'name' => 'Thisted', 'lat' => 56.959168, 'lon' => 8.7034921, 'municipalityCode' => '787', 'municipality' => 'Thisted'],
        ['id' => 'ikast', 'name' => 'Ikast', 'lat' => 56.136371, 'lon' => 9.1545969, 'municipalityCode' => '756', 'municipality' => 'Ikast-Brande'],
        ['id' => 'brande', 'name' => 'Brande', 'lat' => 55.9426864, 'lon' => 9.1287953, 'municipalityCode' => '756', 'municipality' => 'Ikast-Brande'],
        ['id' => 'silkeborg', 'name' => 'Silkeborg', 'lat' => 56.176362, 'lon' => 9.5549217, 'municipalityCode' => '740', 'municipality' => 'Silkeborg'],
        ['id' => 'viborg', 'name' => 'Viborg', 'lat' => 56.452027, 'lon' => 9.3963471, 'municipalityCode' => '791', 'municipality' => 'Viborg'],
        ['id' => 'bjerringbro', 'name' => 'Bjerringbro', 'lat' => 56.377449, 'lon' => 9.655211, 'municipalityCode' => '791', 'municipality' => 'Viborg'],
        ['id' => 'varde', 'name' => 'Varde', 'lat' => 55.623151, 'lon' => 8.48215, 'municipalityCode' => '573', 'municipality' => 'Varde'],
    ],
];
