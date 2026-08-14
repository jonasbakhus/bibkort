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
$googleIsochronesApiKey = getenv('BIBKORT_GOOGLE_ISOCHRONES_API_KEY') ?: ($secrets['google_isochrones_api_key'] ?? '');
$googleRoutesApiKey = getenv('BIBKORT_GOOGLE_ROUTES_API_KEY') ?: ($secrets['google_routes_api_key'] ?? $googleIsochronesApiKey);
$variant = getenv('BIBKORT_VARIANT') ?: ($secrets['variant'] ?? 'standard');
$isGoogleVariant = $variant === 'google';
$preferredRoutingProvider = $travelTimeAppId !== '' && $travelTimeApiKey !== '' ? 'TravelTime' : 'Valhalla';
$travelTimeCalibration = require __DIR__ . '/routing-calibration.php';

return [
    'name' => 'Joboplandskort for Lemvig Kommune',
    'variant' => $isGoogleVariant ? 'google' : 'standard',
    'default_origin' => null,
    'comparison_origin' => null,
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
            'description' => 'Kommunegrænse',
            'type' => 'boundary',
            'lat' => 56.42927620506989,
            'lon' => 8.409006874293725,
        ],
        'donskaervej' => [
            'id' => 'donskaervej',
            'name' => 'Donskærvej',
            'description' => 'Kommunegrænse',
            'type' => 'boundary',
            'lat' => 56.39165837986222,
            'lon' => 8.432911422327615,
        ],
        'damhusvej' => [
            'id' => 'damhusvej',
            'name' => 'Damhusvej',
            'description' => 'Kommunegrænse',
            'type' => 'boundary',
            'lat' => 56.368129964056465,
            'lon' => 8.333982962546616,
        ],
        'lemvigvej' => [
            'id' => 'lemvigvej',
            'name' => 'Lemvigvej',
            'description' => 'Kommunegrænse',
            'type' => 'boundary',
            'lat' => 56.51410456614017,
            'lon' => 8.494187047148959,
        ],
        'remmerstrandvej' => [
            'id' => 'remmerstrandvej',
            'name' => 'Remmerstrandvej',
            'description' => 'Kommunegrænse',
            'type' => 'boundary',
            'lat' => 56.54012287888499,
            'lon' => 8.523202120825987,
        ],
    ],
    'slider' => [
        'min' => 15,
        'max' => $isGoogleVariant ? 60 : 90,
        'step' => 5,
        'default' => 45,
    ],
    'routing' => [
        'provider' => getenv('BIBKORT_ROUTING_PROVIDER') ?: $preferredRoutingProvider,
        'isochrone_provider' => $isGoogleVariant ? 'GoogleIsochrones' : (getenv('BIBKORT_ROUTING_PROVIDER') ?: $preferredRoutingProvider),
        'reachability' => $isGoogleVariant ? 'zone' : 'matrix',
        'base_url' => getenv('BIBKORT_VALHALLA_URL') ?: 'https://valhalla1.openstreetmap.de',
        'traveltime_base_url' => 'https://api.traveltimeapp.com/v4',
        'traveltime_app_id' => (string) $travelTimeAppId,
        'traveltime_api_key' => (string) $travelTimeApiKey,
        'google_isochrones_base_url' => 'https://isochrones.googleapis.com',
        'google_isochrones_api_key' => (string) $googleIsochronesApiKey,
        'google_routes_base_url' => 'https://routes.googleapis.com',
        // Bruges kun af scripts/calibrate-routing.php, aldrig ved almindelige sidevisninger.
        'google_routes_api_key' => (string) $googleRoutesApiKey,
        // Bred, reproducerbar Google Routes-kalibrering. Google-varianten bruger identitet her.
        'travel_time_calibration' => $travelTimeCalibration,
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
        // Mild størrelsesvægt: større byer antages at have lidt flere job pr. indbygger.
        'urban_population_exponent' => 1.10,
        // Ca. 100 meter. Landzoneandelen er kun 10 %, så denne præcision er rigelig og langt hurtigere.
        'boundary_simplify_tolerance' => 0.001,
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
        ['id' => 'bonnet', 'name' => 'Bonnet', 'lat' => 56.521744, 'lon' => 8.223101, 'municipalityCode' => '665', 'municipality' => 'Lemvig'],
        ['id' => 'dybe', 'name' => 'Dybe', 'lat' => 56.512324, 'lon' => 8.169481, 'municipalityCode' => '665', 'municipality' => 'Lemvig'],
        ['id' => 'fabjerg', 'name' => 'Fabjerg', 'lat' => 56.525798, 'lon' => 8.38208, 'municipalityCode' => '665', 'municipality' => 'Lemvig'],
        ['id' => 'ferring', 'name' => 'Ferring', 'lat' => 56.5256234, 'lon' => 8.1230918, 'municipalityCode' => '665', 'municipality' => 'Lemvig'],
        ['id' => 'fjaltring', 'name' => 'Fjaltring', 'lat' => 56.474415, 'lon' => 8.139855, 'municipalityCode' => '665', 'municipality' => 'Lemvig'],
        ['id' => 'faare', 'name' => 'Fåre', 'lat' => 56.453498, 'lon' => 8.262275, 'municipalityCode' => '665', 'municipality' => 'Lemvig'],
        ['id' => 'hove', 'name' => 'Hove', 'lat' => 56.558279, 'lon' => 8.2243359, 'municipalityCode' => '665', 'municipality' => 'Lemvig'],
        ['id' => 'langerhuse', 'name' => 'Langerhuse', 'lat' => 56.626393, 'lon' => 8.1601079, 'municipalityCode' => '665', 'municipality' => 'Lemvig'],
        ['id' => 'moeborg', 'name' => 'Møborg', 'lat' => 56.390842, 'lon' => 8.35037, 'municipalityCode' => '665', 'municipality' => 'Lemvig'],
        ['id' => 'nees', 'name' => 'Nees', 'lat' => 56.394943, 'lon' => 8.212378, 'municipalityCode' => '665', 'municipality' => 'Lemvig'],
        ['id' => 'remmerstrand', 'name' => 'Remmerstrand', 'lat' => 56.549736, 'lon' => 8.489228, 'municipalityCode' => '665', 'municipality' => 'Lemvig'],
        ['id' => 'rom', 'name' => 'Rom', 'lat' => 56.523101, 'lon' => 8.309392, 'municipalityCode' => '665', 'municipality' => 'Lemvig'],
        ['id' => 'skalstrup', 'name' => 'Skalstrup', 'lat' => 56.363244, 'lon' => 8.252967, 'municipalityCode' => '665', 'municipality' => 'Lemvig'],
        ['id' => 'toerring_huse', 'name' => 'Tørring Huse', 'lat' => 56.553782, 'lon' => 8.2401768, 'municipalityCode' => '665', 'municipality' => 'Lemvig'],
        ['id' => 'vandborg', 'name' => 'Vandborg', 'lat' => 56.546435, 'lon' => 8.175085, 'municipalityCode' => '665', 'municipality' => 'Lemvig'],
        ['id' => 'vrist', 'name' => 'Vrist', 'lat' => 56.599485, 'lon' => 8.151058, 'municipalityCode' => '665', 'municipality' => 'Lemvig'],
        ['id' => 'holstebro', 'name' => 'Holstebro', 'lat' => 56.361534, 'lon' => 8.621727, 'municipalityCode' => '661', 'municipality' => 'Holstebro'],
        ['id' => 'vinderup', 'name' => 'Vinderup', 'lat' => 56.4778422, 'lon' => 8.7828263, 'municipalityCode' => '661', 'municipality' => 'Holstebro'],
        ['id' => 'ulfborg', 'name' => 'Ulfborg', 'lat' => 56.2723603, 'lon' => 8.3166504, 'municipalityCode' => '661', 'municipality' => 'Holstebro'],
        ['id' => 'vemb', 'name' => 'Vemb', 'lat' => 56.3439338, 'lon' => 8.3386659, 'municipalityCode' => '661', 'municipality' => 'Holstebro'],
        ['id' => 'struer', 'name' => 'Struer', 'lat' => 56.48493, 'lon' => 8.589933, 'municipalityCode' => '671', 'municipality' => 'Struer'],
        ['id' => 'hvidbjerg', 'name' => 'Hvidbjerg', 'lat' => 56.6342179, 'lon' => 8.5393535, 'municipalityCode' => '671', 'municipality' => 'Struer'],
        ['id' => 'ringkoebing', 'name' => 'Ringkøbing', 'lat' => 56.088054, 'lon' => 8.2576329, 'municipalityCode' => '760', 'municipality' => 'Ringkøbing-Skjern'],
        ['id' => 'skjern', 'name' => 'Skjern', 'lat' => 55.944128, 'lon' => 8.500266, 'municipalityCode' => '760', 'municipality' => 'Ringkøbing-Skjern'],
        ['id' => 'videbaek', 'name' => 'Videbæk', 'lat' => 56.0893891, 'lon' => 8.6295216, 'municipalityCode' => '760', 'municipality' => 'Ringkøbing-Skjern'],
        ['id' => 'tarm', 'name' => 'Tarm', 'lat' => 55.9065375, 'lon' => 8.5192054, 'municipalityCode' => '760', 'municipality' => 'Ringkøbing-Skjern'],
        ['id' => 'hvide_sande', 'name' => 'Hvide Sande', 'lat' => 56.0057884, 'lon' => 8.1288435, 'municipalityCode' => '760', 'municipality' => 'Ringkøbing-Skjern'],
        ['id' => 'spjald', 'name' => 'Spjald', 'lat' => 56.1276147, 'lon' => 8.5069209, 'municipalityCode' => '760', 'municipality' => 'Ringkøbing-Skjern'],
        ['id' => 'herning', 'name' => 'Herning', 'lat' => 56.138557, 'lon' => 8.967322, 'municipalityCode' => '657', 'municipality' => 'Herning'],
        ['id' => 'aulum', 'name' => 'Aulum', 'lat' => 56.2649053, 'lon' => 8.7869814, 'municipalityCode' => '657', 'municipality' => 'Herning'],
        ['id' => 'vildbjerg', 'name' => 'Vildbjerg', 'lat' => 56.1965014, 'lon' => 8.767696, 'municipalityCode' => '657', 'municipality' => 'Herning'],
        ['id' => 'sunds', 'name' => 'Sunds', 'lat' => 56.2043516, 'lon' => 9.0148142, 'municipalityCode' => '657', 'municipality' => 'Herning'],
        ['id' => 'kibaek', 'name' => 'Kibæk', 'lat' => 56.0349566, 'lon' => 8.8583992, 'municipalityCode' => '657', 'municipality' => 'Herning'],
        ['id' => 'skive', 'name' => 'Skive', 'lat' => 56.5651232, 'lon' => 9.0309083, 'municipalityCode' => '779', 'municipality' => 'Skive'],
        ['id' => 'roslev', 'name' => 'Roslev', 'lat' => 56.7040127, 'lon' => 8.9841309, 'municipalityCode' => '779', 'municipality' => 'Skive'],
        ['id' => 'thisted', 'name' => 'Thisted', 'lat' => 56.959168, 'lon' => 8.7034921, 'municipalityCode' => '787', 'municipality' => 'Thisted'],
        ['id' => 'hurup', 'name' => 'Hurup Thy', 'lat' => 56.7511648, 'lon' => 8.4178998, 'municipalityCode' => '787', 'municipality' => 'Thisted'],
        ['id' => 'hanstholm', 'name' => 'Hanstholm', 'lat' => 57.1150165, 'lon' => 8.6144947, 'municipalityCode' => '787', 'municipality' => 'Thisted'],
        ['id' => 'ikast', 'name' => 'Ikast', 'lat' => 56.136371, 'lon' => 9.1545969, 'municipalityCode' => '756', 'municipality' => 'Ikast-Brande'],
        ['id' => 'bording', 'name' => 'Bording', 'lat' => 56.14503203, 'lon' => 9.27352163, 'municipalityCode' => '756', 'municipality' => 'Ikast-Brande'],
        ['id' => 'brande', 'name' => 'Brande', 'lat' => 55.9426864, 'lon' => 9.1287953, 'municipalityCode' => '756', 'municipality' => 'Ikast-Brande'],
        ['id' => 'engesvang', 'name' => 'Engesvang', 'lat' => 56.1703216, 'lon' => 9.3511945, 'municipalityCode' => '756', 'municipality' => 'Ikast-Brande'],
        ['id' => 'silkeborg', 'name' => 'Silkeborg', 'lat' => 56.176362, 'lon' => 9.5549217, 'municipalityCode' => '740', 'municipality' => 'Silkeborg'],
        ['id' => 'kjellerup', 'name' => 'Kjellerup', 'lat' => 56.2834926, 'lon' => 9.4400132, 'municipalityCode' => '740', 'municipality' => 'Silkeborg'],
        ['id' => 'viborg', 'name' => 'Viborg', 'lat' => 56.452027, 'lon' => 9.3963471, 'municipalityCode' => '791', 'municipality' => 'Viborg'],
        ['id' => 'bjerringbro', 'name' => 'Bjerringbro', 'lat' => 56.377449, 'lon' => 9.655211, 'municipalityCode' => '791', 'municipality' => 'Viborg'],
        ['id' => 'karup', 'name' => 'Karup', 'lat' => 56.3111225, 'lon' => 9.1706768, 'municipalityCode' => '791', 'municipality' => 'Viborg'],
        ['id' => 'varde', 'name' => 'Varde', 'lat' => 55.623151, 'lon' => 8.48215, 'municipalityCode' => '573', 'municipality' => 'Varde'],
        ['id' => 'oelgod', 'name' => 'Ølgod', 'lat' => 55.8058276, 'lon' => 8.6181307, 'municipalityCode' => '573', 'municipality' => 'Varde'],
        ['id' => 'nykoebing_mors', 'name' => 'Nykøbing Mors', 'lat' => 56.7943811, 'lon' => 8.8594834, 'municipalityCode' => '773', 'municipality' => 'Morsø'],
        ['id' => 'grindsted', 'name' => 'Grindsted', 'lat' => 55.7570833, 'lon' => 8.9277576, 'municipalityCode' => '530', 'municipality' => 'Billund'],
        ['id' => 'billund', 'name' => 'Billund', 'lat' => 55.7247018, 'lon' => 9.1195835, 'municipalityCode' => '530', 'municipality' => 'Billund'],
        ['id' => 'esbjerg', 'name' => 'Esbjerg', 'lat' => 55.4664892, 'lon' => 8.4520751, 'municipalityCode' => '561', 'municipality' => 'Esbjerg'],
    ],
];
