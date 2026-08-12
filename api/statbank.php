<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';

app_require_get();

$config = require __DIR__ . '/../config/app.php';
$table = $config['statbank']['table'];
$ttl = (int) $config['statbank']['cache_ttl'];
$municipalities = [];
foreach ($config['cities'] as $city) {
    $municipalities[$city['municipalityCode']] = $city['municipality'];
}
$cacheKey = 'statbank-' . strtolower($table) . '-' . sha1(json_encode(array_keys($municipalities)));

try {
    $cached = app_cache_read($cacheKey, $ttl);
    if ($cached !== null) {
        $payload = $cached['data'];
        $payload['cache'] = ['hit' => true, 'stale' => false, 'ageSeconds' => $cached['age']];
        app_json_response($payload);
    }

    try {
        $payload = fetch_statbank_data($table, $municipalities);
        app_cache_write($cacheKey, $payload);
        $payload['cache'] = ['hit' => false, 'stale' => false, 'ageSeconds' => 0];
        app_json_response($payload);
    } catch (Throwable $exception) {
        $stale = app_cache_read($cacheKey, $ttl, true);
        if ($stale !== null) {
            $payload = $stale['data'];
            $payload['warning'] = 'Statistikbanken kunne ikke nås; senest gemte tal vises.';
            $payload['cache'] = ['hit' => true, 'stale' => true, 'ageSeconds' => $stale['age']];
            app_json_response($payload);
        }
        throw $exception;
    }
} catch (Throwable $exception) {
    error_log('bibkort statbank: ' . $exception->getMessage());
    app_json_response(['ok' => false, 'error' => 'Tal fra Statistikbanken kunne ikke hentes lige nu. Prøv igen senere.'], 502);
}

function fetch_statbank_data(string $table, array $municipalities): array
{
    $metadataUrl = 'https://api.statbank.dk/v1/tableinfo/' . rawurlencode($table) . '?lang=da';
    $metadata = app_json_decode(app_http_get($metadataUrl, 30), 'Statistikbanken');
    $variables = $metadata['variables'] ?? [];
    $latestYear = null;
    $branchLabels = [];

    foreach ($variables as $variable) {
        if (($variable['time'] ?? false) === true) {
            foreach ($variable['values'] ?? [] as $value) {
                if (preg_match('/^\d{4}$/', (string) ($value['id'] ?? '')) === 1) {
                    $year = (int) $value['id'];
                    $latestYear = $latestYear === null ? $year : max($latestYear, $year);
                }
            }
        }

        if (($variable['id'] ?? '') === 'BRANCHEDB0710') {
            foreach ($variable['values'] ?? [] as $value) {
                $code = (string) ($value['id'] ?? '');
                if ($code === '' || $code === 'TOT') {
                    continue;
                }
                $branchLabels[$code] = preg_replace('/^\d+\s+/u', '', (string) ($value['text'] ?? $code));
            }
        }
    }

    if ($latestYear === null || $branchLabels === []) {
        throw new RuntimeException('ERHV2-metadata mangler år eller brancher.');
    }

    $query = http_build_query([
        'valuePresentation' => 'Code',
        'OMRÅDE' => implode(',', array_keys($municipalities)),
        'BRANCHEDB0710' => implode(',', array_merge(['TOT'], array_keys($branchLabels))),
        'TAL' => 'ARBSTED,ANSATTE',
        'Tid' => (string) $latestYear,
    ], '', '&', PHP_QUERY_RFC3986);
    // Statistikbankens GET-API kræver kommaer som separatorer, ikke URL-kodede kommaer.
    $query = str_replace('%2C', ',', $query);
    $csvUrl = 'https://api.statbank.dk/v1/data/' . rawurlencode($table) . '/CSV?' . $query;
    $rows = parse_statbank_csv(app_http_get($csvUrl, 45));

    $result = [];
    foreach ($municipalities as $code => $name) {
        $result[$code] = [
            'code' => $code,
            'name' => $name,
            'jobs' => null,
            'workplaces' => null,
            'branches' => [],
        ];
        foreach ($branchLabels as $branchCode => $branchName) {
            $result[$code]['branches'][$branchCode] = ['code' => $branchCode, 'name' => $branchName, 'jobs' => null];
        }
    }

    foreach ($rows as $row) {
        $area = $row['OMRÅDE'] ?? '';
        $branch = $row['BRANCHEDB0710'] ?? '';
        $unit = $row['TAL'] ?? '';
        $value = statbank_number($row['INDHOLD'] ?? null);
        if (!isset($result[$area])) {
            continue;
        }
        if ($branch === 'TOT' && $unit === 'ANSATTE') {
            $result[$area]['jobs'] = $value;
        } elseif ($branch === 'TOT' && $unit === 'ARBSTED') {
            $result[$area]['workplaces'] = $value;
        } elseif ($branch !== 'TOT' && $unit === 'ANSATTE' && isset($result[$area]['branches'][$branch])) {
            $result[$area]['branches'][$branch]['jobs'] = $value;
        }
    }

    return [
        'ok' => true,
        'table' => $table,
        'year' => $latestYear,
        'updated' => $metadata['updated'] ?? null,
        'municipalities' => $result,
        'source' => 'Danmarks Statistik / Statistikbanken',
    ];
}

function parse_statbank_csv(string $csv): array
{
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
    $lines = preg_split('/\r\n|\n|\r/', trim($csv));
    if (!is_array($lines) || count($lines) < 2) {
        throw new RuntimeException('Statistikbanken returnerede ingen datarækker.');
    }

    $headers = str_getcsv((string) array_shift($lines), ';');
    $rows = [];
    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }
        $values = str_getcsv($line, ';');
        if (count($values) === count($headers)) {
            $row = array_combine($headers, $values);
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
    }

    return $rows;
}

function statbank_number(mixed $value): ?int
{
    $normalized = str_replace(['.', ' '], '', (string) $value);
    return preg_match('/^-?\d+$/', $normalized) === 1 ? (int) $normalized : null;
}
