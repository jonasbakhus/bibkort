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
            'lat' => 56.54516663,
            'lon' => 8.30043077,
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
            'lat' => 56.69727851,
            'lon' => 8.21018201,
        ],
        'harbooere' => [
            'id' => 'harbooere',
            'name' => 'Harboøre',
            'description' => 'By',
            'lat' => 56.6180628,
            'lon' => 8.18067098,
        ],
        'noerre_nissum' => [
            'id' => 'noerre_nissum',
            'name' => 'Nørre Nissum',
            'description' => 'By',
            'lat' => 56.5468343,
            'lon' => 8.41716308,
        ],
        'ramme' => [
            'id' => 'ramme',
            'name' => 'Ramme',
            'description' => 'By',
            'lat' => 56.49114501,
            'lon' => 8.21006789,
        ],
        'klinkby' => [
            'id' => 'klinkby',
            'name' => 'Klinkby',
            'description' => 'By',
            'lat' => 56.55605474,
            'lon' => 8.22784376,
        ],
        'boevlingbjerg' => [
            'id' => 'boevlingbjerg',
            'name' => 'Bøvlingbjerg',
            'description' => 'By',
            'lat' => 56.43527463,
            'lon' => 8.20138091,
        ],
        'bonnet' => [
            'id' => 'bonnet', 'name' => 'Bonnet', 'description' => 'Landsby',
            'lat' => 56.52187067, 'lon' => 8.22286754,
        ],
        'dybe' => [
            'id' => 'dybe', 'name' => 'Dybe', 'description' => 'Landsby',
            'lat' => 56.51050898, 'lon' => 8.17228095,
        ],
        'fabjerg' => [
            'id' => 'fabjerg', 'name' => 'Fabjerg', 'description' => 'Landsby',
            'lat' => 56.52644301, 'lon' => 8.37975792,
        ],
        'ferring' => [
            'id' => 'ferring', 'name' => 'Ferring', 'description' => 'Kystlandsby',
            'lat' => 56.5270481, 'lon' => 8.12315097,
        ],
        'fjaltring' => [
            'id' => 'fjaltring', 'name' => 'Fjaltring', 'description' => 'Kystlandsby',
            'lat' => 56.47377459, 'lon' => 8.13939838,
        ],
        'faare' => [
            'id' => 'faare', 'name' => 'Fåre', 'description' => 'Landsby',
            'lat' => 56.45338788, 'lon' => 8.2630005,
        ],
        'gudum' => [
            'id' => 'gudum', 'name' => 'Gudum', 'description' => 'Landsby',
            'lat' => 56.52070425, 'lon' => 8.45911841,
        ],
        'hove' => [
            'id' => 'hove', 'name' => 'Hove', 'description' => 'Lokalsamfund',
            'lat' => 56.556178, 'lon' => 8.22752438,
        ],
        'langerhuse' => [
            'id' => 'langerhuse', 'name' => 'Langerhuse', 'description' => 'Kystlandsby',
            'lat' => 56.62959516, 'lon' => 8.15733857,
        ],
        'lomborg' => [
            'id' => 'lomborg', 'name' => 'Lomborg', 'description' => 'Landsby',
            'lat' => 56.50171906, 'lon' => 8.27093689,
        ],
        'moeborg' => [
            'id' => 'moeborg', 'name' => 'Møborg', 'description' => 'Landsby',
            'lat' => 56.3901954, 'lon' => 8.34791192,
        ],
        'nees' => [
            'id' => 'nees', 'name' => 'Nees', 'description' => 'Landsby',
            'lat' => 56.39540059, 'lon' => 8.21291525,
        ],
        'remmerstrand' => [
            'id' => 'remmerstrand', 'name' => 'Remmerstrand', 'description' => 'Kystlandsby',
            'lat' => 56.55013393, 'lon' => 8.48900437,
        ],
        'rom' => [
            'id' => 'rom', 'name' => 'Rom', 'description' => 'Landsby',
            'lat' => 56.51560618, 'lon' => 8.30829894,
        ],
        'skalstrup' => [
            'id' => 'skalstrup', 'name' => 'Skalstrup', 'description' => 'Landsby',
            'lat' => 56.36717449, 'lon' => 8.27335514,
        ],
        'toerring_huse' => [
            'id' => 'toerring_huse', 'name' => 'Tørring Huse', 'description' => 'Lokalsamfund',
            'lat' => 56.55327076, 'lon' => 8.24186013,
        ],
        'vandborg' => [
            'id' => 'vandborg', 'name' => 'Vandborg', 'description' => 'Landsby',
            'lat' => 56.54629775, 'lon' => 8.17474638,
        ],
        'vrist' => [
            'id' => 'vrist', 'name' => 'Vrist', 'description' => 'Kystlandsby',
            'lat' => 56.614636, 'lon' => 8.15678561,
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
        // Kalibreret mod Bækmarksbro–Gødstrup: 2.640 faktiske sekunder / 2.804 modelsekunder.
        'travel_time_factor' => 2640 / 2804,
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
        ['id' => 'lemvig', 'name' => 'Lemvig', 'lat' => 56.54516663, 'lon' => 8.30043077, 'municipalityCode' => '665', 'municipality' => 'Lemvig'],
        ['id' => 'thyboroen', 'name' => 'Thyborøn', 'lat' => 56.69727851, 'lon' => 8.21018201, 'municipalityCode' => '665', 'municipality' => 'Lemvig'],
        ['id' => 'harbooere', 'name' => 'Harboøre', 'lat' => 56.6180628, 'lon' => 8.18067098, 'municipalityCode' => '665', 'municipality' => 'Lemvig'],
        ['id' => 'noerre_nissum', 'name' => 'Nørre Nissum', 'lat' => 56.5468343, 'lon' => 8.41716308, 'municipalityCode' => '665', 'municipality' => 'Lemvig'],
        ['id' => 'baekmarksbro', 'name' => 'Bækmarksbro', 'lat' => 56.42039773538234, 'lon' => 8.30641623581447, 'municipalityCode' => '665', 'municipality' => 'Lemvig'],
        ['id' => 'boevlingbjerg', 'name' => 'Bøvlingbjerg', 'lat' => 56.43527463, 'lon' => 8.20138091, 'municipalityCode' => '665', 'municipality' => 'Lemvig'],
        ['id' => 'ramme', 'name' => 'Ramme', 'lat' => 56.49114501, 'lon' => 8.21006789, 'municipalityCode' => '665', 'municipality' => 'Lemvig'],
        ['id' => 'lomborg', 'name' => 'Lomborg', 'lat' => 56.50171906, 'lon' => 8.27093689, 'municipalityCode' => '665', 'municipality' => 'Lemvig'],
        ['id' => 'klinkby', 'name' => 'Klinkby', 'lat' => 56.55605474, 'lon' => 8.22784376, 'municipalityCode' => '665', 'municipality' => 'Lemvig'],
        ['id' => 'gudum', 'name' => 'Gudum', 'lat' => 56.52070425, 'lon' => 8.45911841, 'municipalityCode' => '665', 'municipality' => 'Lemvig'],
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
