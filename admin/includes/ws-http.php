<?php

declare(strict_types=1);

function ws_http_post_xml(string $url, string $xml, array $options = []): array
{
    $headers = $options['headers'] ?? [];
    $timeout = (int) ($options['timeout'] ?? 30);
    $basicAuth = $options['basic_auth'] ?? null;

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
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if (is_array($basicAuth) && !empty($basicAuth['username'])) {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, (string) $basicAuth['username'] . ':' . (string) ($basicAuth['password'] ?? ''));
        }

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new RuntimeException('Errore HTTP/cURL: ' . $error);
        }

        return [
            'status_code' => $status,
            'headers' => $responseHeaders,
            'body' => is_string($body) ? $body : '',
        ];
    }

    $contextHeaders = implode("
", $headers);
    $opts = [
        'http' => [
            'method' => 'POST',
            'header' => $contextHeaders,
            'content' => $xml,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ],
    ];
    $context = stream_context_create($opts);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        throw new RuntimeException('Errore HTTP: impossibile contattare il servizio remoto.');
    }

    $status = 0;
    global $http_response_header;
    if (!empty($http_response_header[0]) && preg_match('/\s(\d{3})\s/', (string) $http_response_header[0], $m)) {
        $status = (int) $m[1];
    }

    return [
        'status_code' => $status,
        'headers' => [],
        'body' => $body,
    ];
}
