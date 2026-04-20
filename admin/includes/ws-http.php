<?php

declare(strict_types=1);

function ws_http_post_xml(string $url, string $xml, array $headers = [], array $options = []): array
{
    if (!function_exists('curl_init')) {
        return [
            'success' => false,
            'status_code' => 0,
            'body' => '',
            'error' => 'cURL non disponibile sul server.',
        ];
    }

    $timeout = (int) ($options['timeout'] ?? 45);
    $connectTimeout = (int) ($options['connect_timeout'] ?? 20);

    $ch = curl_init($url);
    $curlOptions = [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $xml,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_HTTPHEADER => array_merge([
            'Content-Length: ' . strlen($xml),
        ], $headers),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];

    if (!empty($options['basic_auth_user'])) {
        $curlOptions[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
        $curlOptions[CURLOPT_USERPWD] = (string) $options['basic_auth_user'] . ':' . (string) ($options['basic_auth_pass'] ?? '');
    }

    curl_setopt_array($ch, $curlOptions);
    $body = curl_exec($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'success' => $body !== false && $statusCode >= 200 && $statusCode < 300,
        'status_code' => $statusCode,
        'body' => is_string($body) ? $body : '',
        'error' => $error,
    ];
}

function ws_xml_xpath(string $xml): ?DOMXPath
{
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $loaded = $dom->loadXML($xml);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (!$loaded) {
        return null;
    }

    return new DOMXPath($dom);
}

function ws_xml_value(DOMXPath $xpath, string $query): string
{
    $nodeList = @$xpath->query($query);
    if (!$nodeList || !$nodeList->length) {
        return '';
    }

    return trim((string) $nodeList->item(0)->textContent);
}
