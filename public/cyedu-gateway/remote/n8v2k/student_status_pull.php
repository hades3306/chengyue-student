<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/lib/bootstrap.php';

$endpoint = trim((string) ($_REQUEST['endpoint'] ?? ''));

if ($endpoint === '') {
    json_out(['ok' => false, 'bridge' => 'student-status-pull', 'error' => 'parameter endpoint is required'], 400);
}

if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $endpoint)) {
    $endpoint = 'http://' . $endpoint;
}

$startedAt = microtime(true);
$body = false;
$httpCode = 0;
$contentType = '';
$effectiveUrl = $endpoint;
$fetchError = '';

if (function_exists('curl_init')) {
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => 'Chengyue-StudentStatusFetcher/3.2',
    ]);
    $body = curl_exec($ch);
    $fetchError = (string) curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
} else {
    $context = stream_context_create([
        'http' => [
            'timeout' => 8,
            'follow_location' => 1,
            'ignore_errors' => true,
            'user_agent' => 'Chengyue-StudentStatusFetcher/3.2',
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);
    $body = @file_get_contents($endpoint, false, $context);
    if (isset($http_response_header[0]) && preg_match('~HTTP/\S+\s+(\d{3})~', $http_response_header[0], $m)) {
        $httpCode = (int) $m[1];
    }
    if ($body === false) {
        $lastError = error_get_last();
        $fetchError = $lastError['message'] ?? 'fetch failed';
    }
}

$elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

if ($body === false) {
    json_out([
        'ok' => false,
        'bridge' => 'student-status-pull',
        'endpoint' => $endpoint,
        'effective_url' => $effectiveUrl,
        'error' => $fetchError !== '' ? $fetchError : 'remote fetch failed',
        'elapsed_ms' => $elapsedMs,
    ], 502);
}

$body = (string) $body;

json_out([
    'ok' => true,
    'bridge' => 'student-status-pull',
    'endpoint' => $endpoint,
    'effective_url' => $effectiveUrl,
    'http_code' => $httpCode,
    'content_type' => $contentType,
    'body_length' => strlen($body),
    'body' => mb_strcut($body, 0, 8000, 'UTF-8'),
    'elapsed_ms' => $elapsedMs,
]);
