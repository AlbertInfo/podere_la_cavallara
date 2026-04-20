<?php

declare(strict_types=1);

function ws_http_normalize_headers(array $headers): array
{
    $normalized = [];
    foreach ($headers as $header) {
        $header = trim((string) $header);
        if ($header !== '') {
            $normalized[] = $header;
        }
    }
    return $normalized;
}

function ws_http_parse_response_headers(array $headerLines): array
{
    $headers = [];
    foreach ($headerLines as $headerLine) {
        if (!is_string($headerLine)) {
            continue;
        }
        $headerLine = trim($headerLine);
        if ($headerLine === '' || stripos($headerLine, 'HTTP/') === 0) {
            continue;
        }
        $parts = explode(':', $headerLine, 2);
        if (count($parts) !== 2) {
            continue;
        }
        $headers[strtolower(trim($parts[0]))][] = trim($parts[1]);
    }
    return $headers;
}

function ws_http_post_xml(string $url, string $xml, array $options = []): array
{
    $headers = ws_http_normalize_headers($options['headers'] ?? []);
    $timeout = max(1, (int) ($options['timeout'] ?? 30));
    $basicAuth = is_array($options['basic_auth'] ?? null) ? $options['basic_auth'] : null;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Impossibile inizializzare cURL.');
        }

        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $xml,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$responseHeaders): int {
                $len = strlen($headerLine);
                $parts = explode(':', $headerLine, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))][] = trim($parts[1]);
                }
                return $len;
            },
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'PodereLaCavallaraAdmin/1.0',
        ]);

        if ($basicAuth && trim((string) ($basicAuth['username'] ?? '')) !== '') {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, (string) $basicAuth['username'] . ':' . (string) ($basicAuth['password'] ?? ''));
        }

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new RuntimeException('Errore HTTP/cURL [' . $errno . ']: ' . ($error !== '' ? $error : 'errore sconosciuto'));
        }

        return [
            'status_code' => $status,
            'headers' => $responseHeaders,
            'body' => is_string($body) ? $body : '',
        ];
    }

    $contextHeaders = $headers;
    if ($basicAuth && trim((string) ($basicAuth['username'] ?? '')) !== '') {
        $contextHeaders[] = 'Authorization: Basic ' . base64_encode((string) $basicAuth['username'] . ':' . (string) ($basicAuth['password'] ?? ''));
    }

    $opts = [
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $contextHeaders),
            'content' => $xml,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ],
    ];

    $context = stream_context_create($opts);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        $error = error_get_last();
        throw new RuntimeException('Errore HTTP: impossibile contattare il servizio remoto.' . (!empty($error['message']) ? ' ' . $error['message'] : ''));
    }

    global $http_response_header;
    $headerLines = is_array($http_response_header ?? null) ? $http_response_header : [];
    $status = 0;
    if (!empty($headerLines[0]) && preg_match('/\s(\d{3})\s/', (string) $headerLines[0], $m)) {
        $status = (int) $m[1];
    }

    return [
        'status_code' => $status,
        'headers' => ws_http_parse_response_headers($headerLines),
        'body' => is_string($body) ? $body : '',
    ];
}
