<?php

declare(strict_types=1);

// Genereret 13. august 2026 fra 602 gyldige ruter i en 27 x 23 Google Routes-kontrolmatrix.
// Modellen er en lineær tidskurve pr. udgangspunkt: vist tid = rå TravelTime-tid * slope + intercept_seconds.
return [
    'version' => 'google-routes-2026-08-14-v2',
    'reference' => [
        'provider' => 'Google Routes API',
        'routing_preference' => 'TRAFFIC_UNAWARE',
        'matrix_elements' => 621,
        'training_pairs' => 602,
        'mean_absolute_error_minutes' => 2.05,
    ],
    'default' => ['intercept_seconds' => -229, 'slope' => 0.991830],
    'origins' => [
        'lemvig' => ['intercept_seconds' => -225, 'slope' => 1.029540],
        // Hele kurven er forskudt med et lokalt kontrolanker; ingen destinationssærregel.
        'baekmarksbro' => ['intercept_seconds' => -91, 'slope' => 1.029568],
        'thyboroen' => ['intercept_seconds' => -422, 'slope' => 0.964114],
        'harbooere' => ['intercept_seconds' => -326, 'slope' => 0.991811],
        'noerre_nissum' => ['intercept_seconds' => -381, 'slope' => 1.017707],
        'ramme' => ['intercept_seconds' => -271, 'slope' => 1.008753],
        'klinkby' => ['intercept_seconds' => -288, 'slope' => 0.988687],
        'boevlingbjerg' => ['intercept_seconds' => -280, 'slope' => 1.001349],
        'bonnet' => ['intercept_seconds' => -244, 'slope' => 1.005462],
        'dybe' => ['intercept_seconds' => -325, 'slope' => 0.991035],
        'fabjerg' => ['intercept_seconds' => -165, 'slope' => 1.003040],
        'ferring' => ['intercept_seconds' => -213, 'slope' => 0.984184],
        'fjaltring' => ['intercept_seconds' => -326, 'slope' => 0.996465],
        'faare' => ['intercept_seconds' => -274, 'slope' => 1.030369],
        'gudum' => ['intercept_seconds' => -210, 'slope' => 1.001919],
        'hove' => ['intercept_seconds' => -254, 'slope' => 0.997756],
        'langerhuse' => ['intercept_seconds' => -265, 'slope' => 0.978419],
        'lomborg' => ['intercept_seconds' => -256, 'slope' => 1.018283],
        'moeborg' => ['intercept_seconds' => -207, 'slope' => 1.032074],
        'nees' => ['intercept_seconds' => -297, 'slope' => 1.041214],
        'remmerstrand' => ['intercept_seconds' => -245, 'slope' => 0.995118],
        'rom' => ['intercept_seconds' => -193, 'slope' => 1.036919],
        'skalstrup' => ['intercept_seconds' => -213, 'slope' => 0.976353],
        'toerring_huse' => ['intercept_seconds' => -222, 'slope' => 1.019012],
        'vandborg' => ['intercept_seconds' => -295, 'slope' => 0.979602],
        'vrist' => ['intercept_seconds' => -206, 'slope' => 0.979941],
        'vilhelmsborgvej' => ['intercept_seconds' => -242, 'slope' => 1.015885],
        // Kommunegrænsepunkterne ligger tæt og bruger samme lokale kurve indtil næste brede genkalibrering.
        'donskaervej' => ['intercept_seconds' => -242, 'slope' => 1.015885],
        'damhusvej' => ['intercept_seconds' => -242, 'slope' => 1.015885],
        'lemvigvej' => ['intercept_seconds' => -242, 'slope' => 1.015885],
        'remmerstrandvej' => ['intercept_seconds' => -242, 'slope' => 1.015885],
    ],
];
