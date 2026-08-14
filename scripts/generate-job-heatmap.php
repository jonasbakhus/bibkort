<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/Heatmap.php';
require_once __DIR__ . '/../lib/Routing/TravelTimeProvider.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Scriptet må kun køres fra kommandolinjen.\n");
    exit(1);
}

$options = getopt('', [
    'source::', 'output::', 'resume::', 'spacing-km::', 'rural-spacing-km::',
    'batch-size::', 'delay-ms::', 'limit-batches::',
]);
$source = rtrim((string) ($options['source'] ?? 'https://bibkort.landogbyforeningen.dk'), '/');
$output = (string) ($options['output'] ?? (__DIR__ . '/../assets/data/job-heatmap.json'));
$resumeFile = (string) ($options['resume'] ?? (__DIR__ . '/../cache/job-heatmap-build.json'));
$spacingKm = max(0.25, (float) ($options['spacing-km'] ?? 0.5));
$ruralSpacingKm = max(4.0, (float) ($options['rural-spacing-km'] ?? 8.0));
$batchSize = max(1, min(10, (int) ($options['batch-size'] ?? 10)));
$delayMs = max(0, (int) ($options['delay-ms'] ?? 10500));
$limitBatches = max(0, (int) ($options['limit-batches'] ?? 0));

$config = require __DIR__ . '/../config/app.php';
$routing = $config['routing'];
if ($routing['traveltime_app_id'] === '' || $routing['traveltime_api_key'] === '') {
    throw new RuntimeException('TravelTime-nøgler mangler i config/secrets.php eller miljøvariabler.');
}

fwrite(STDERR, "Henter geografi og ERHV2-data…\n");
$geography = app_json_decode(app_http_get($source . '/api/geography.php', 90), 'Bibkort geografi');
$statistics = app_json_decode(app_http_get($source . '/api/statbank.php', 90), 'Bibkort Statistikbank');
if (($geography['ok'] ?? false) !== true || ($statistics['ok'] ?? false) !== true) {
    throw new RuntimeException('Bibkorts datagrundlag kunne ikke indlæses.');
}

$municipalityFeatures = [];
foreach ($geography['municipalities']['features'] ?? [] as $feature) {
    $code = (string) ($feature['properties']['code'] ?? '');
    if ($code !== '') {
        $municipalityFeatures[$code] = $feature;
    }
}
$lemvigFeature = $municipalityFeatures['665'] ?? null;
if (!is_array($lemvigFeature)) {
    throw new RuntimeException('Lemvig Kommunes geometri mangler.');
}

$minutes = range((int) $config['slider']['min'], (int) $config['slider']['max'], (int) $config['slider']['step']);
$origins = app_heatmap_grid($lemvigFeature, $spacingKm);
$calibrations = [];
foreach ($origins as $origin) {
    $calibrations[$origin['id']] = app_heatmap_interpolated_calibration($origin, $config);
}

$settlementsByMunicipality = [];
foreach ($geography['settlements'] ?? [] as $settlement) {
    $settlementsByMunicipality[(string) $settlement['municipalityCode']][] = $settlement;
}
$urbanShare = (float) ($geography['weights']['urban'] ?? $config['geography']['urban_weight']);
$ruralShare = (float) ($geography['weights']['rural'] ?? $config['geography']['rural_weight']);
$exponent = (float) ($geography['weights']['urbanPopulationExponent'] ?? $config['geography']['urban_population_exponent']);
$destinations = [];

foreach ($statistics['municipalities'] ?? [] as $code => $municipality) {
    $code = (string) $code;
    $jobs = max(0.0, (float) ($municipality['jobs'] ?? 0));
    $settlements = $settlementsByMunicipality[$code] ?? [];
    $weightTotal = array_sum(array_map(
        static fn (array $settlement): float => max(1, (float) ($settlement['population'] ?? 0)) ** $exponent,
        $settlements
    ));
    if ($weightTotal > 0) {
        foreach ($settlements as $settlement) {
            $weight = max(1, (float) ($settlement['population'] ?? 0)) ** $exponent;
            $destinations[] = [
                'id' => 'urban-' . $settlement['id'],
                'lat' => (float) $settlement['lat'],
                'lon' => (float) $settlement['lon'],
                'weight' => $jobs * $urbanShare * $weight / $weightTotal,
                'kind' => 'urban',
            ];
        }
    }

    $feature = $municipalityFeatures[$code] ?? null;
    if ($jobs <= 0 || !is_array($feature)) {
        continue;
    }
    $ruralPoints = app_heatmap_grid($feature, $ruralSpacingKm);
    $ruralWeight = $jobs * $ruralShare / max(1, count($ruralPoints));
    foreach ($ruralPoints as $index => $point) {
        $destinations[] = [
            'id' => sprintf('rural-%s-%03d', $code, $index + 1),
            'lat' => $point['lat'],
            'lon' => $point['lon'],
            'weight' => $ruralWeight,
            'kind' => 'rural',
        ];
    }
}

$buildHash = hash('sha256', json_encode([
    array_map(static fn (array $point): array => [$point['id'], $point['lat'], $point['lon']], $origins),
    array_map(static fn (array $point): array => [$point['id'], $point['lat'], $point['lon'], round($point['weight'], 6)], $destinations),
    $minutes,
    $routing['travel_time_calibration']['version'] ?? null,
]));
$build = null;
if (is_file($resumeFile)) {
    $candidate = json_decode((string) file_get_contents($resumeFile), true);
    if (is_array($candidate) && ($candidate['buildHash'] ?? '') === $buildHash) {
        $build = $candidate;
        fwrite(STDERR, "Fortsætter fra batch " . ((int) $build['completedBatches'] + 1) . "…\n");
    }
}
if ($build === null) {
    $build = [
        'buildHash' => $buildHash,
        'completedBatches' => 0,
        'skippedDestinations' => [],
        'values' => array_fill_keys(array_column($origins, 'id'), array_fill(0, count($minutes), 0.0)),
    ];
}
$build['skippedDestinations'] = is_array($build['skippedDestinations'] ?? null) ? $build['skippedDestinations'] : [];

$provider = new TravelTimeProvider(
    $routing['traveltime_base_url'],
    $routing['traveltime_app_id'],
    $routing['traveltime_api_key']
);
$batches = array_chunk($destinations, $batchSize);
$startedBatch = (int) $build['completedBatches'];
$processedThisRun = 0;
fwrite(STDERR, sprintf(
    "%d heatmap-punkter · %d jobpunkter · %d batches.\n",
    count($origins),
    count($destinations),
    count($batches)
));

for ($batchIndex = $startedBatch; $batchIndex < count($batches); $batchIndex++) {
    if ($limitBatches > 0 && $processedThisRun >= $limitBatches) {
        fwrite(STDERR, "Stopper efter den aftalte testgrænse; checkpoint er gemt.\n");
        exit(2);
    }
    $batch = $batches[$batchIndex];
    try {
        $matrix = app_heatmap_matrix_with_retry($provider, $origins, $batch);
    } catch (Throwable $exception) {
        if (!str_contains($exception->getMessage(), 'HTTP 422')) {
            throw $exception;
        }
        // Et groft landzonepunkt kan ligge i vand, klit eller et andet sted,
        // som routingmotoren ikke kan koble til vejnettet. Isolér punktet,
        // så de øvrige ni destinationer i batchen ikke går tabt.
        $matrix = [];
        foreach ($batch as $destination) {
            try {
                $single = app_heatmap_matrix_with_retry($provider, $origins, [$destination]);
                $matrix += $single;
            } catch (Throwable $singleException) {
                if (!str_contains($singleException->getMessage(), 'HTTP 422')) {
                    throw $singleException;
                }
                $build['skippedDestinations'][] = (string) $destination['id'];
                $build['skippedDestinations'] = array_values(array_unique($build['skippedDestinations']));
                fwrite(STDERR, sprintf("Springer jobpunkt %s over; det kan ikke kobles til vejnettet.\n", $destination['id']));
            }
        }
    }

    foreach ($batch as $destination) {
        foreach ($origins as $origin) {
            $rawSeconds = $matrix[$destination['id']][$origin['id']] ?? null;
            if (!is_int($rawSeconds)) {
                continue;
            }
            $calibration = $calibrations[$origin['id']];
            $calibratedMinutes = (int) round(max(0, $rawSeconds * $calibration['slope'] + $calibration['intercept_seconds']) / 60);
            foreach ($minutes as $minuteIndex => $minute) {
                if ($calibratedMinutes <= $minute) {
                    $build['values'][$origin['id']][$minuteIndex] += (float) $destination['weight'];
                }
            }
        }
    }

    $build['completedBatches'] = $batchIndex + 1;
    app_heatmap_write_json($resumeFile, $build);
    $processedThisRun++;
    fwrite(STDERR, sprintf("Batch %d/%d færdig.\n", $batchIndex + 1, count($batches)));
    if ($batchIndex + 1 < count($batches) && $delayMs > 0) {
        usleep($delayMs * 1000);
    }
}

$points = [];
foreach ($origins as $origin) {
    $points[] = [
        $origin['lat'],
        $origin['lon'],
        array_map(static fn (float $value): int => (int) round($value), $build['values'][$origin['id']]),
    ];
}
$payload = [
    'ok' => true,
    'version' => 1,
    'generatedAt' => gmdate('c'),
    'minutes' => $minutes,
    'cellKm' => $spacingKm,
    'points' => $points,
    'model' => [
        'jobsYear' => (int) ($statistics['year'] ?? 0),
        'settlementsYear' => (int) ($geography['year'] ?? 0),
        'urbanShare' => $urbanShare,
        'ruralShare' => $ruralShare,
        'urbanDestinations' => count(array_filter($destinations, static fn (array $point): bool => $point['kind'] === 'urban')),
        'ruralDestinations' => count(array_filter($destinations, static fn (array $point): bool => $point['kind'] === 'rural')),
        'ruralSpacingKm' => $ruralSpacingKm,
        'routing' => 'TravelTime time-filter/fast',
        'calibration' => (string) ($routing['travel_time_calibration']['version'] ?? 'ukendt'),
        'calibrationMethod' => 'inverse-distance-weighted-4-nearest-local-origins',
        'skippedDestinations' => array_values(array_unique($build['skippedDestinations'])),
    ],
];
app_heatmap_write_json($output, $payload);
fwrite(STDERR, sprintf("Heatmap gemt i %s (%d punkter).\n", $output, count($points)));

function app_heatmap_write_json(string $file, array $payload): void
{
    $directory = dirname($file);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Kunne ikke oprette mappen ' . $directory);
    }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Kunne ikke kode heatmap-data som JSON.');
    }
    $temporary = $file . '.tmp';
    if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $file)) {
        throw new RuntimeException('Kunne ikke skrive ' . $file);
    }
}

function app_heatmap_matrix_with_retry(TravelTimeProvider $provider, array $origins, array $destinations): array
{
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        try {
            return $provider->rawManyToOneMatrix($origins, $destinations);
        } catch (Throwable $exception) {
            if (!str_contains($exception->getMessage(), 'HTTP 429') || $attempt === 5) {
                throw $exception;
            }
            fwrite(STDERR, "Rate limit ramt; venter 65 sekunder før nyt forsøg…\n");
            sleep(65);
        }
    }

    throw new RuntimeException('Heatmap-matricen kunne ikke beregnes.');
}
