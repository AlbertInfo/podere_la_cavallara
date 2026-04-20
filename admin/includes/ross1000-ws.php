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
    $movimentiXml = ross1000_build_xml($payload);
    $movimentiXml = preg_replace('/^<\?xml[^>]+>\s*/', '', $movimentiXml) ?: $movimentiXml;

    $source = new DOMDocument('1.0', 'UTF-8');
    if (!@$source->loadXML($movimentiXml, LIBXML_NOBLANKS)) {
        throw new RuntimeException('Impossibile costruire il payload SOAP ROSS1000.');
    }

    $root = $source->documentElement;
    if (!$root || $root->nodeName !== 'movimenti') {
        throw new RuntimeException('Il payload ROSS1000 deve avere radice <movimenti>.');
    }

    $codice = '';
    $prodotto = '';
    $movimentoNodes = [];

    foreach ($root->childNodes as $child) {
        if (!$child instanceof DOMElement) {
            continue;
        }

        if ($child->tagName === 'codice') {
            $codice = trim($child->textContent);
            continue;
        }

        if ($child->tagName === 'prodotto') {
            $prodotto = trim($child->textContent);
            continue;
        }

        if ($child->tagName === 'movimento') {
            $movimentoNodes[] = $child;
        }
    }

    if ($codice === '' || $prodotto === '' || !$movimentoNodes) {
        throw new RuntimeException('Il payload ROSS1000 non contiene codice, prodotto o movimenti validi.');
    }

    $soap = new DOMDocument('1.0', 'UTF-8');
    $soap->preserveWhiteSpace = false;
    $soap->formatOutput = false;

    $envelope = $soap->createElementNS('http://schemas.xmlsoap.org/soap/envelope/', 'soapenv:Envelope');
    $soap->appendChild($envelope);
    $envelope->setAttribute('xmlns:ns2', 'http://checkin.ws.service.turismo5.gies.it/');

    $header = $soap->createElement('soapenv:Header');
    $body = $soap->createElement('soapenv:Body');
    $envelope->appendChild($header);
    $envelope->appendChild($body);

    $request = $soap->createElement('ns2:inviaMovimentazione');
    $body->appendChild($request);

    $movimentazione = $soap->createElement('movimentazione');
    $request->appendChild($movimentazione);
    $movimentazione->appendChild($soap->createElement('codice', $codice));
    $movimentazione->appendChild($soap->createElement('prodotto', $prodotto));

    foreach ($movimentoNodes as $node) {
        $movimentazione->appendChild($soap->importNode($node, true));
    }

    return $soap->saveXML() ?: '';
}

function ross1000_ws_response_success(string $body): bool
{
    if (trim($body) === '') {
        return false;
    }

    return stripos($body, '<Fault') === false
        && stripos($body, ':Fault') === false
        && stripos($body, '<faultstring>') === false;
}

function ross1000_ws_response_error_message(array $response): string
{
    $statusCode = (int) ($response['status_code'] ?? 0);
    $body = (string) ($response['body'] ?? '');

    if ($body !== '') {
        if (preg_match('/<faultstring>(.*?)<\/faultstring>/is', $body, $m)) {
            return 'Fault SOAP ROSS1000 (HTTP ' . $statusCode . '): ' . trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_XML1, 'UTF-8'));
        }
        if (preg_match('/<faultcode>(.*?)<\/faultcode>/is', $body, $m)) {
            return 'Fault SOAP ROSS1000 (HTTP ' . $statusCode . '): ' . trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_XML1, 'UTF-8'));
        }
    }

    if ($statusCode > 0) {
        $excerpt = trim(preg_replace('/\s+/', ' ', strip_tags($body)));
        if ($excerpt !== '') {
            return 'Risposta HTTP ' . $statusCode . ' da ROSS1000: ' . mb_substr($excerpt, 0, 300);
        }
        return 'Risposta HTTP ' . $statusCode . ' da ROSS1000.';
    }

    return 'ROSS1000 ha restituito una risposta non valida.';
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
    $errorMessage = $success ? '' : ross1000_ws_response_error_message($response);
    $responsePayload = 'HTTP ' . (int) ($response['status_code'] ?? 0) . "\n\n" . (string) ($response['body'] ?? '');

    webservice_log($pdo, [
        'service_name' => 'ross1000',
        'action_name' => 'inviaMovimentazione',
        'scope_type' => $scopeType,
        'scope_ref' => $scopeRef,
        'is_simulated' => false,
        'success' => $success,
        'request_payload' => $requestXml,
        'response_payload' => $responsePayload,
        'error_message' => $errorMessage,
    ]);

    if (!$success) {
        throw new RuntimeException($errorMessage);
    }

    return ['success' => true, 'simulated' => false, 'request_xml' => $requestXml, 'response_body' => (string) ($response['body'] ?? '')];
}
