<?php

declare(strict_types=1);

return [
    'name' => 'Bo i Bækmarksbro – arbejdsmarkedskort',
    'origin' => [
        'name' => 'Bækmarksbro',
        'lat' => 56.41647,
        'lon' => 8.30842,
    ],
    'slider' => [
        'min' => 15,
        'max' => 90,
        'step' => 5,
        'default' => 45,
    ],
    'routing' => [
        'provider' => 'Valhalla',
        'base_url' => getenv('BIBKORT_VALHALLA_URL') ?: 'https://valhalla1.openstreetmap.de',
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
        ['id' => 'herning', 'name' => 'Herning', 'lat' => 56.1362, 'lon' => 8.9766, 'municipalityCode' => '657', 'municipality' => 'Herning'],
        ['id' => 'skive', 'name' => 'Skive', 'lat' => 56.5669, 'lon' => 9.0271, 'municipalityCode' => '779', 'municipality' => 'Skive'],
        ['id' => 'ikast', 'name' => 'Ikast', 'lat' => 56.1388, 'lon' => 9.1577, 'municipalityCode' => '756', 'municipality' => 'Ikast-Brande'],
        ['id' => 'viborg', 'name' => 'Viborg', 'lat' => 56.4532, 'lon' => 9.4020, 'municipalityCode' => '791', 'municipality' => 'Viborg'],
        ['id' => 'varde', 'name' => 'Varde', 'lat' => 55.6211, 'lon' => 8.4807, 'municipalityCode' => '573', 'municipality' => 'Varde'],
    ],
];
