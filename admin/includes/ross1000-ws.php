<?php

declare(strict_types=1);

require_once __DIR__ . '/ross1000.php';
require_once __DIR__ . '/ws-http.php';
require_once __DIR__ . '/webservice-log.php';

function ross1000_ws_runtime_config(): array
{
    $config = ross1000_property_config();
    $config['simulate_send_without_ws'] = !empty($config['simulate_send_without_ws']);
    return $config;
}

function ross1000_ws_config_ready(array $config): bool
{
    return trim((string) ($config['wsdl'] ?? '')) !== ''
        && trim((string) ($config['username'] ?? '')) !== ''
        && trim((string) ($config['password'] ?? '')) !== ''
        && trim((string) ($config['codice_struttura'] ?? '')) !== '';
}

function ross1000_ws_endpoint(string $wsdl): string
{
    return preg_replace('/\?wsdl$/i', '', trim($wsdl)) ?: trim($wsdl);
}

function ross1000_ws_build_envelope(array $payload): string
{
    $xml = ross1000_build_xml($payload);
    $xml = preg_replace('/^<\?xml[^>]+>\s*/', '', $xml) ?: $xml;

    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns2="http://checkin.ws.service.turismo5.gies.it/">'
        . '<soapenv:Header/>'
        . '<soapenv:Body>'
        . '<ns2:inviaMovimentazione>'
        . '<movimentazione>' . $xml . '</movimentazione>'
        . '</ns2:inviaMovimentazione>'
        . '</soapenv:Body>'
        . '</soapenv:Envelope>';
}

function ross1000_ws_response_success(string $body): bool
{
    if (stripos($body, '<Fault>') !== false || stripos($body, '<soap:Fault') !== false || stripos($body, '<faultstring>') !== false) {
        return false;
    }
    return trim($body) !== '';
}

function ross1000_ws_send(PDO $pdo, array $payload, string $scopeType, string $scopeRef): array
{
    $config = ross1000_ws_runtime_config();
    $requestXml = ross1000_ws_build_envelope($payload);

    if (!ross1000_ws_config_ready($config)) {
        throw new RuntimeException('Configura prima endpoint WSDL, username e password di ROSS1000.');
    }

    if (!empty($config['simulate_send_without_ws'])) {
        webservice_log($pdo, [
            'service_name' => 'ross1000',
            'action_name' => 'inviaMovimentazione',
            'scope_type' => $scopeType,
            'scope_ref' => $scopeRef,
            'is_simulated' => true,
            'success' => true,
            'request_payload' => $requestXml,
            'response_payload' => 'SIMULATED_OK',
            'error_message' => '',
        ]);

        return ['success' => true, 'simulated' => true, 'request_xml' => $requestXml, 'response_body' => 'SIMULATED_OK'];
    }

    $response = ws_http_post_xml(ross1000_ws_endpoint((string) $config['wsdl']), $requestXml, [
        'headers' => [
            'Accept: text/xml, multipart/related',
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: ""',
        ],
        'basic_auth' => [
            'username' => (string) $config['username'],
            'password' => (string) $config['password'],
        ],
        'timeout' => 45,
    ]);

    $success = ($response['status_code'] ?? 0) >= 200 && ($response['status_code'] ?? 0) < 300 && ross1000_ws_response_success((string) ($response['body'] ?? ''));

    webservice_log($pdo, [
        'service_name' => 'ross1000',
        'action_name' => 'inviaMovimentazione',
        'scope_type' => $scopeType,
        'scope_ref' => $scopeRef,
        'is_simulated' => false,
        'success' => $success,
        'request_payload' => $requestXml,
        'response_payload' => (string) ($response['body'] ?? ''),
        'error_message' => $success ? '' : 'Risposta WS ROSS1000 non valida o fault SOAP.',
    ]);

    if (!$success) {
        throw new RuntimeException('ROSS1000 ha restituito una risposta non valida. Controlla i log del web service.');
    }

    return ['success' => true, 'simulated' => false, 'request_xml' => $requestXml, 'response_body' => (string) ($response['body'] ?? '')];
}
