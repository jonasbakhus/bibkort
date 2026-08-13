<?php

declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';
const APP_CACHE_DIR = APP_ROOT . '/cache';

function app_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        http_response_code(500);
        echo '{"ok":false,"error":"Svaret kunne ikke kodes som JSON."}';
        exit;
    }

    echo $json;
    exit;
}

function app_require_get(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        header('Allow: GET');
        app_json_response(['ok' => false, 'error' => 'Kun GET er tilladt.'], 405);
    }
}

function app_http_get(string $url, int $timeout = 30): string
{
    $userAgent = 'bibkort/1.0 (+https://github.com/jonasbakhus/bibkort)';

    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('HTTP-klienten kunne ikke startes.');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_HTTPHEADER => ['Accept: application/json, text/csv;q=0.9, */*;q=0.8'],
            CURLOPT_ENCODING => '',
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if (!is_string($body) || $status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf('Ekstern tjeneste svarede med HTTP %d%s.', $status, $error !== '' ? ': ' . $error : ''));
        }

        return $body;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeout,
            'ignore_errors' => true,
            'header' => "User-Agent: {$userAgent}\r\nAccept: application/json, text/csv;q=0.9, */*;q=0.8\r\n",
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches) === 1) {
            $status = (int) $matches[1];
        }
    }

    if (!is_string($body) || $status < 200 || $status >= 300) {
        throw new RuntimeException(sprintf('Ekstern tjeneste svarede med HTTP %d.', $status));
    }

    return $body;
}

function app_http_post_json(string $url, array $payload, array $headers = [], int $timeout = 45): string
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('HTTP-forespørgslen kunne ikke kodes som JSON.');
    }

    $userAgent = 'bibkort/1.0 (+https://github.com/jonasbakhus/bibkort)';
    $requestHeaders = array_merge(['Content-Type: application/json'], $headers);
    $hasAcceptHeader = false;
    foreach ($requestHeaders as $header) {
        if (stripos($header, 'Accept:') === 0) {
            $hasAcceptHeader = true;
            break;
        }
    }
    if (!$hasAcceptHeader) {
        $requestHeaders[] = 'Accept: application/json';
    }

    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('HTTP-klienten kunne ikke startes.');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_ENCODING => '',
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if (!is_string($body) || $status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf('Ekstern tjeneste svarede med HTTP %d%s.', $status, $error !== '' ? ': ' . $error : ''));
        }

        return $body;
    }

    $headerText = "User-Agent: {$userAgent}\r\n" . implode("\r\n", $requestHeaders) . "\r\n";
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'timeout' => $timeout,
            'ignore_errors' => true,
            'header' => $headerText,
            'content' => $json,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches) === 1) {
            $status = (int) $matches[1];
        }
    }

    if (!is_string($body) || $status < 200 || $status >= 300) {
        throw new RuntimeException(sprintf('Ekstern tjeneste svarede med HTTP %d.', $status));
    }

    return $body;
}

function app_cache_read(string $key, int $ttl, bool $allowExpired = false): ?array
{
    $file = app_cache_file($key);
    if (!is_file($file)) {
        return null;
    }

    $raw = @file_get_contents($file);
    $cached = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($cached) || !isset($cached['storedAt']) || !array_key_exists('data', $cached)) {
        return null;
    }

    $age = time() - (int) $cached['storedAt'];
    if (!$allowExpired && $age > $ttl) {
        return null;
    }

    return ['data' => $cached['data'], 'age' => max(0, $age), 'stale' => $age > $ttl];
}

function app_cache_write(string $key, mixed $data): void
{
    if (!is_dir(APP_CACHE_DIR) && !@mkdir(APP_CACHE_DIR, 0775, true) && !is_dir(APP_CACHE_DIR)) {
        return;
    }

    $json = json_encode(['storedAt' => time(), 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json !== false) {
        @file_put_contents(app_cache_file($key), $json, LOCK_EX);
    }
}

function app_cache_file(string $key): string
{
    return APP_CACHE_DIR . '/' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $key) . '.json';
}

function app_json_decode(string $body, string $service): array
{
    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException($service . ' returnerede ugyldig JSON.');
    }

    return $data;
}
