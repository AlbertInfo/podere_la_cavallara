<?php

declare(strict_types=1);

require_once __DIR__ . '/alloggiati.php';
require_once __DIR__ . '/ws-http.php';
require_once __DIR__ . '/webservice-log.php';

function alloggiati_ws_clean_xml(string $xml): string
{
    $xml = trim($xml);
    if ($xml === '') {
        return '';
    }

    $xml = preg_replace('/<\?xml[^>]*\?>/i', '', $xml) ?? $xml;
    $xml = preg_replace('/\s+xmlns(?::[A-Za-z0-9_-]+)?="[^"]*"/i', '', $xml) ?? $xml;
    $xml = preg_replace('/<(\/?)([A-Za-z_][A-Za-z0-9_.-]*):/i', '<$1', $xml) ?? $xml;
    return trim($xml);
}

function alloggiati_ws_extract_tag_block(string $xml, string $tag): string
{
    if ($xml === '') {
        return '';
    }

    if (preg_match('/<' . preg_quote($tag, '/') . '\b[^>]*>(.*?)<\/' . preg_quote($tag, '/') . '>/si', $xml, $matches)) {
        return (string) $matches[1];
    }

    return '';
}

function alloggiati_ws_extract_tag_blocks(string $xml, string $tag): array
{
    if ($xml === '') {
        return [];
    }

    if (!preg_match_all('/<' . preg_quote($tag, '/') . '\b[^>]*>(.*?)<\/' . preg_quote($tag, '/') . '>/si', $xml, $matches)) {
        return [];
    }

    return array_values(array_map('strval', $matches[1] ?? []));
}

function alloggiati_ws_extract_simple_value(string $xml, string $tag): string
{
    if ($xml === '') {
        return '';
    }

    if (preg_match('/<' . preg_quote($tag, '/') . '\b[^>]*>(.*?)<\/' . preg_quote($tag, '/') . '>/si', $xml, $matches)) {
        return html_entity_decode(trim(strip_tags((string) $matches[1])), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    if (preg_match('/<' . preg_quote($tag, '/') . '\b[^>]*\/\s*>/si', $xml)) {
        return '';
    }

    return '';
}

function alloggiati_ws_parse_operation_result_block(string $xml): array
{
    return [
        'success' => strtolower(alloggiati_ws_extract_simple_value($xml, 'esito')) === 'true',
        'error_code' => alloggiati_ws_extract_simple_value($xml, 'ErroreCod'),
        'error_description' => alloggiati_ws_extract_simple_value($xml, 'ErroreDes'),
        'error_detail' => alloggiati_ws_extract_simple_value($xml, 'ErroreDettaglio'),
    ];
}

function alloggiati_ws_compose_error_message(array $result, string $fallback): string
{
    $parts = [];
    foreach (['error_code', 'error_description', 'error_detail'] as $key) {
        $value = trim((string) ($result[$key] ?? ''));
        if ($value !== '') {
            $parts[] = $value;
        }
    }

    $message = trim(implode(' - ', array_unique($parts)));
    return $message !== '' ? $message : $fallback;
}

function alloggiati_ws_extract_fault_info(string $body): array
{
    $xml = alloggiati_ws_clean_xml($body);
    $faultBlock = alloggiati_ws_extract_tag_block($xml, 'Fault');
    if ($faultBlock === '') {
        return ['has_fault' => false, 'code' => '', 'string' => '', 'detail' => ''];
    }

    return [
        'has_fault' => true,
        'code' => alloggiati_ws_extract_simple_value($faultBlock, 'faultcode') ?: alloggiati_ws_extract_simple_value($faultBlock, 'Code'),
        'string' => alloggiati_ws_extract_simple_value($faultBlock, 'faultstring') ?: alloggiati_ws_extract_simple_value($faultBlock, 'Text'),
        'detail' => trim(preg_replace('/\s+/', ' ', alloggiati_ws_extract_simple_value($faultBlock, 'detail') ?: alloggiati_ws_extract_simple_value($faultBlock, 'Detail')) ?? ''),
    ];
}

function alloggiati_ws_parse_response_body(string $method, string $body): array
{
    $xml = alloggiati_ws_clean_xml($body);
    $parsed = [
        'method' => $method,
        'http_success' => false,
        'method_success' => false,
        'service_result' => [
            'success' => false,
            'error_code' => '',
            'error_description' => '',
            'error_detail' => '',
        ],
        'row_results' => [],
        'schedine_valide' => 0,
        'token' => '',
        'issued' => '',
        'expires' => '',
        'fault' => alloggiati_ws_extract_fault_info($body),
        'raw_body' => $body,
        'xml_valid' => $xml !== '',
    ];

    if ($xml === '') {
        return $parsed;
    }

    $responseBlock = alloggiati_ws_extract_tag_block($xml, $method . 'Response');
    if ($method === 'GenerateToken') {
        $serviceBlock = alloggiati_ws_extract_tag_block($responseBlock, 'result');
        $tokenBlock = alloggiati_ws_extract_tag_block($responseBlock, 'GenerateTokenResult');
        $parsed['service_result'] = alloggiati_ws_parse_operation_result_block($serviceBlock);
        $parsed['token'] = alloggiati_ws_extract_simple_value($tokenBlock, 'token');
        $parsed['issued'] = alloggiati_ws_extract_simple_value($tokenBlock, 'issued');
        $parsed['expires'] = alloggiati_ws_extract_simple_value($tokenBlock, 'expires');
        $parsed['method_success'] = !$parsed['fault']['has_fault'] && !empty($parsed['service_result']['success']) && $parsed['token'] !== '';
        return $parsed;
    }

    if ($method === 'Authentication_Test') {
        $serviceBlock = alloggiati_ws_extract_tag_block($responseBlock, 'Authentication_TestResult');
        $parsed['service_result'] = alloggiati_ws_parse_operation_result_block($serviceBlock);
        $parsed['method_success'] = !$parsed['fault']['has_fault'] && !empty($parsed['service_result']['success']);
        return $parsed;
    }

    if (in_array($method, ['Test', 'Send'], true)) {
        $serviceBlock = alloggiati_ws_extract_tag_block($responseBlock, $method . 'Result');
        $resultBlock = alloggiati_ws_extract_tag_block($responseBlock, 'result');
        $detailBlock = alloggiati_ws_extract_tag_block($resultBlock, 'Dettaglio');

        $parsed['service_result'] = alloggiati_ws_parse_operation_result_block($serviceBlock);
        $parsed['schedine_valide'] = (int) alloggiati_ws_extract_simple_value($resultBlock, 'SchedineValide');
        foreach (alloggiati_ws_extract_tag_blocks($detailBlock, 'EsitoOperazioneServizio') as $rowBlock) {
            $parsed['row_results'][] = alloggiati_ws_parse_operation_result_block($rowBlock);
        }

        $parsed['method_success'] = !$parsed['fault']['has_fault'] && !empty($parsed['service_result']['success']);
        return $parsed;
    }

    $parsed['method_success'] = !$parsed['fault']['has_fault'];
    return $parsed;
}

function alloggiati_ws_response_payload(array $response): string
{
    return 'HTTP ' . (int) ($response['status_code'] ?? 0) . "\n\n" . (string) ($response['body'] ?? '');
}

function alloggiati_ws_response_error_message(string $method, array $response, array $parsed): string
{
    $statusCode = (int) ($response['status_code'] ?? 0);
    $body = (string) ($response['body'] ?? '');

    if (!empty($parsed['fault']['has_fault'])) {
        $parts = [];
        foreach (['code', 'string', 'detail'] as $key) {
            $value = trim((string) ($parsed['fault'][$key] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }
        $message = trim(implode(' - ', array_unique($parts)));
        return 'Fault SOAP Alloggiati' . ($statusCode > 0 ? ' (HTTP ' . $statusCode . ')' : '') . ': ' . ($message !== '' ? $message : 'risposta non valida.');
    }

    $serviceMessage = alloggiati_ws_compose_error_message($parsed['service_result'] ?? [], '');
    if ($serviceMessage !== '') {
        return $method . ' non riuscito' . ($statusCode > 0 ? ' (HTTP ' . $statusCode . ')' : '') . ': ' . $serviceMessage;
    }

    if ($statusCode > 0 && ($statusCode < 200 || $statusCode >= 300)) {
        $excerpt = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '');
        if ($excerpt !== '') {
            return 'Risposta HTTP ' . $statusCode . ' da Alloggiati: ' . mb_substr($excerpt, 0, 300);
        }
        return 'Risposta HTTP ' . $statusCode . ' da Alloggiati.';
    }

    if (!$parsed['xml_valid']) {
        return $method . ' ha restituito XML non valido o vuoto.';
    }

    if (in_array($method, ['Test', 'Send'], true) && empty($parsed['row_results'])) {
        return $method . ' non ha restituito il dettaglio riga-per-riga previsto dal servizio.';
    }

    return $method . ' ha restituito una risposta non valida.';
}

function alloggiati_ws_normalize_row_results(array $rowResults, int $expectedCount, string $fallbackMessage): array
{
    $normalized = [];
    for ($i = 0; $i < $expectedCount; $i++) {
        $rowResult = $rowResults[$i] ?? null;
        if (is_array($rowResult)) {
            $normalized[] = [
                'success' => !empty($rowResult['success']),
                'error_code' => (string) ($rowResult['error_code'] ?? ''),
                'error_description' => (string) ($rowResult['error_description'] ?? ''),
                'error_detail' => (string) ($rowResult['error_detail'] ?? ''),
            ];
            continue;
        }

        $normalized[] = [
            'success' => false,
            'error_code' => '',
            'error_description' => '',
            'error_detail' => $fallbackMessage,
        ];
    }
    return $normalized;
}

function alloggiati_ws_mark_schedine_failed(PDO $pdo, array $schedine, string $message): void
{
    $message = trim($message);
    if ($message === '') {
        $message = 'Errore di comunicazione con il servizio Alloggiati.';
    }

    $stmt = $pdo->prepare("UPDATE alloggiati_schedine SET status = 'errore', last_attempt_at = NOW(), attempt_count = attempt_count + 1, last_error = :err, updated_at = NOW() WHERE id = :id");
    foreach ($schedine as $schedina) {
        $schedinaId = (int) ($schedina['id'] ?? 0);
        if ($schedinaId <= 0) {
            continue;
        }
        $stmt->execute([
            'err' => $message,
            'id' => $schedinaId,
        ]);
    }
}

function alloggiati_ws_build_envelope(string $method, string $innerXml): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope" xmlns:all="AlloggiatiService">'
        . '<soap:Header/>'
        . '<soap:Body>'
        . '<all:' . $method . '>'
        . $innerXml
        . '</all:' . $method . '>'
        . '</soap:Body>'
        . '</soap:Envelope>';
}

function alloggiati_ws_envelope_generate_token(array $config): string
{
    $innerXml = '<all:Utente>' . htmlspecialchars((string) $config['utente'], ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</all:Utente>'
        . '<all:Password>' . htmlspecialchars((string) $config['password'], ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</all:Password>'
        . '<all:WsKey>' . htmlspecialchars((string) $config['wskey'], ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</all:WsKey>';

    return alloggiati_ws_build_envelope('GenerateToken', $innerXml);
}

function alloggiati_ws_envelope_authentication_test(array $config, string $token): string
{
    $innerXml = '<all:Utente>' . htmlspecialchars((string) $config['utente'], ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</all:Utente>'
        . '<all:token>' . htmlspecialchars($token, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</all:token>';

    return alloggiati_ws_build_envelope('Authentication_Test', $innerXml);
}

function alloggiati_ws_envelope_lines(string $method, array $config, string $token, array $lines): string
{
    $items = '';
    foreach ($lines as $line) {
        $items .= '<all:string>' . htmlspecialchars((string) $line, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</all:string>';
    }

    $innerXml = '<all:Utente>' . htmlspecialchars((string) $config['utente'], ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</all:Utente>'
        . '<all:token>' . htmlspecialchars($token, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</all:token>'
        . '<all:ElencoSchedine>' . $items . '</all:ElencoSchedine>';

    return alloggiati_ws_build_envelope($method, $innerXml);
}

function alloggiati_ws_post(string $endpoint, string $xml): array
{
    return ws_http_post_xml($endpoint, $xml, [
        'headers' => [
            'Content-Type: application/soap+xml; charset=utf-8',
            'Accept: application/soap+xml, text/xml',
        ],
        'timeout' => 45,
    ]);
}

function alloggiati_ws_run_request(PDO $pdo, string $method, string $requestXml, array $response, string $scopeType, string $scopeRef, bool $isSimulated = false): array
{
    $parsed = alloggiati_ws_parse_response_body($method, (string) ($response['body'] ?? ''));
    $statusCode = (int) ($response['status_code'] ?? 0);
    $parsed['http_success'] = $statusCode >= 200 && $statusCode < 300;
    $success = $parsed['http_success'] && !empty($parsed['method_success']);
    $errorMessage = $success ? '' : alloggiati_ws_response_error_message($method, $response, $parsed);

    webservice_log($pdo, [
        'service_name' => 'alloggiati',
        'action_name' => $method,
        'scope_type' => $scopeType,
        'scope_ref' => $scopeRef,
        'is_simulated' => $isSimulated,
        'success' => $success,
        'status' => $isSimulated ? 'simulated' : ($success ? 'success' : 'error'),
        'request_payload' => $requestXml,
        'response_payload' => alloggiati_ws_response_payload($response),
        'error_message' => $errorMessage,
    ]);

    return [
        'success' => $success,
        'request_xml' => $requestXml,
        'response' => $response,
        'response_body' => (string) ($response['body'] ?? ''),
        'parsed' => $parsed,
        'error_message' => $errorMessage,
        'row_results' => $parsed['row_results'] ?? [],
    ];
}

function alloggiati_ws_generate_token_live(PDO $pdo, array $config): array
{
    $requestXml = alloggiati_ws_envelope_generate_token($config);
    $response = alloggiati_ws_post((string) $config['endpoint'], $requestXml);
    $run = alloggiati_ws_run_request($pdo, 'GenerateToken', $requestXml, $response, 'auth', 'generate_token');
    if (empty($run['success'])) {
        throw new RuntimeException($run['error_message'] ?: 'GenerateToken non riuscito.');
    }

    $token = (string) ($run['parsed']['token'] ?? '');
    if ($token === '') {
        throw new RuntimeException('GenerateToken non ha restituito un token valido.');
    }

    $authRequestXml = alloggiati_ws_envelope_authentication_test($config, $token);
    $authResponse = alloggiati_ws_post((string) $config['endpoint'], $authRequestXml);
    $authRun = alloggiati_ws_run_request($pdo, 'Authentication_Test', $authRequestXml, $authResponse, 'auth', 'authentication_test');
    if (empty($authRun['success'])) {
        throw new RuntimeException($authRun['error_message'] ?: 'Authentication_Test non riuscito.');
    }

    return [
        'token' => $token,
        'issued' => (string) ($run['parsed']['issued'] ?? ''),
        'expires' => (string) ($run['parsed']['expires'] ?? ''),
        'response_body' => (string) ($run['response_body'] ?? ''),
    ];
}

function alloggiati_ws_run_method(PDO $pdo, string $method, array $config, string $token, array $lines, string $scopeType, string $scopeRef): array
{
    $requestXml = alloggiati_ws_envelope_lines($method, $config, $token, $lines);
    $response = alloggiati_ws_post((string) $config['endpoint'], $requestXml);
    return alloggiati_ws_run_request($pdo, $method, $requestXml, $response, $scopeType, $scopeRef);
}

function alloggiati_ws_send_day(PDO $pdo, string $arrivalDate): array
{
    $bundle = alloggiati_collect_day_export($pdo, $arrivalDate);
    $schedine = [];
    $lines = [];
    foreach (($bundle['schedine'] ?? []) as $row) {
        if (alloggiati_is_ws_sendable_status((string) ($row['status'] ?? ''))) {
            $schedine[] = $row;
            $lines[] = (string) ($row['trace_record'] ?? '');
        }
    }

    if (!$schedine) {
        return ['sent' => 0, 'errors' => array_values($bundle['errors'] ?? ['Nessuna schedina pronta o ritentabile da inviare.'])];
    }

    $config = alloggiati_ws_config();
    if (!alloggiati_ws_config_ready($config)) {
        throw new RuntimeException('Configura utente, password e WSKEY del web service Alloggiati.');
    }

    if (!empty($config['simulate_send_without_ws'])) {
        webservice_log($pdo, [
            'service_name' => 'alloggiati',
            'action_name' => 'Send',
            'scope_type' => 'day',
            'scope_ref' => $arrivalDate,
            'is_simulated' => true,
            'success' => true,
            'status' => 'simulated',
            'request_payload' => alloggiati_join_trace_records($lines),
            'response_payload' => 'SIMULATED_OK',
            'error_message' => '',
        ]);
        $ids = array_map(static fn(array $r): int => (int) $r['id'], $schedine);
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("UPDATE alloggiati_schedine SET status = 'inviata', sent_at = NOW(), last_attempt_at = NOW(), attempt_count = attempt_count + 1, last_error = NULL, validation_errors = NULL, updated_at = NOW() WHERE id IN ($placeholders)");
            $stmt->execute($ids);
        }
        return ['sent' => count($ids), 'errors' => []];
    }

    try {
        $tokenInfo = alloggiati_ws_generate_token_live($pdo, $config);
    } catch (Throwable $e) {
        alloggiati_ws_mark_schedine_failed($pdo, $schedine, $e->getMessage());
        throw $e;
    }

    $errors = array_values($bundle['errors'] ?? []);
    $sendableRows = [];
    $sendableLines = [];

    $test = alloggiati_ws_run_method($pdo, 'Test', $config, (string) $tokenInfo['token'], $lines, 'day', $arrivalDate);
    $testFallback = $test['error_message'] ?: 'Test preliminare non concluso correttamente.';
    $testResults = alloggiati_ws_normalize_row_results((array) ($test['row_results'] ?? []), count($schedine), $testFallback);

    foreach ($schedine as $index => $row) {
        $rowResult = $testResults[$index];
        if (!empty($rowResult['success'])) {
            $sendableRows[] = $row;
            $sendableLines[] = $lines[$index];
            continue;
        }

        $message = alloggiati_ws_compose_error_message($rowResult, $testFallback ?: 'Schedina non valida.');
        $errors[] = ($row['display_name'] ?? 'Schedina') . ': ' . $message;
        alloggiati_ws_mark_schedine_failed($pdo, [$row], $message);
    }

    if (!$sendableRows) {
        return ['sent' => 0, 'errors' => array_values(array_unique($errors ?: ['Nessuna schedina valida dopo il test preliminare.']))];
    }

    $send = alloggiati_ws_run_method($pdo, 'Send', $config, (string) $tokenInfo['token'], $sendableLines, 'day', $arrivalDate);
    $sendFallback = $send['error_message'] ?: 'Invio non concluso correttamente.';
    $sendResults = alloggiati_ws_normalize_row_results((array) ($send['row_results'] ?? []), count($sendableRows), $sendFallback);

    $sent = 0;
    $successStmt = $pdo->prepare("UPDATE alloggiati_schedine SET status = 'inviata', sent_at = NOW(), last_attempt_at = NOW(), attempt_count = attempt_count + 1, last_error = NULL, validation_errors = NULL, updated_at = NOW() WHERE id = :id");

    foreach ($sendableRows as $index => $row) {
        $rowResult = $sendResults[$index];
        if (!empty($rowResult['success'])) {
            $successStmt->execute(['id' => (int) $row['id']]);
            $sent++;
            continue;
        }

        $message = alloggiati_ws_compose_error_message($rowResult, $sendFallback ?: 'Invio schedina fallito.');
        $errors[] = ($row['display_name'] ?? 'Schedina') . ': ' . $message;
        alloggiati_ws_mark_schedine_failed($pdo, [$row], $message);
    }

    return ['sent' => $sent, 'errors' => array_values(array_unique($errors))];
}

function alloggiati_ws_send_schedina(PDO $pdo, int $schedinaId): array
{
    $bundle = alloggiati_collect_single_export($pdo, $schedinaId, ['pronta', 'errore']);
    $schedina = $bundle['schedina'] ?? null;
    $line = (string) ($bundle['content'] ?? '');
    if (!$schedina || trim($line) === '') {
        return ['sent' => 0, 'errors' => array_values($bundle['errors'] ?? ['Schedina non pronta.']), 'arrival_date' => (string) (($schedina['arrival_date'] ?? '') ?: '')];
    }

    $config = alloggiati_ws_config();
    if (!alloggiati_ws_config_ready($config)) {
        throw new RuntimeException('Configura utente, password e WSKEY del web service Alloggiati.');
    }

    if (!empty($config['simulate_send_without_ws'])) {
        webservice_log($pdo, [
            'service_name' => 'alloggiati',
            'action_name' => 'Send',
            'scope_type' => 'single_schedina',
            'scope_ref' => (string) $schedinaId,
            'is_simulated' => true,
            'success' => true,
            'status' => 'simulated',
            'request_payload' => $line,
            'response_payload' => 'SIMULATED_OK',
            'error_message' => '',
        ]);
        $stmt = $pdo->prepare("UPDATE alloggiati_schedine SET status = 'inviata', sent_at = NOW(), last_attempt_at = NOW(), attempt_count = attempt_count + 1, last_error = NULL, validation_errors = NULL, updated_at = NOW() WHERE id = :id");
        $stmt->execute(['id' => $schedinaId]);
        return ['sent' => 1, 'errors' => [], 'arrival_date' => (string) ($schedina['arrival_date'] ?? '')];
    }

    try {
        $tokenInfo = alloggiati_ws_generate_token_live($pdo, $config);
    } catch (Throwable $e) {
        alloggiati_ws_mark_schedine_failed($pdo, [$schedina], $e->getMessage());
        throw $e;
    }

    $test = alloggiati_ws_run_method($pdo, 'Test', $config, (string) $tokenInfo['token'], [$line], 'single_schedina', (string) $schedinaId);
    $testResult = alloggiati_ws_normalize_row_results((array) ($test['row_results'] ?? []), 1, $test['error_message'] ?: 'Test preliminare non concluso correttamente.')[0];
    if (empty($testResult['success'])) {
        $message = alloggiati_ws_compose_error_message($testResult, $test['error_message'] ?: 'Schedina non valida.');
        alloggiati_ws_mark_schedine_failed($pdo, [$schedina], $message);
        return ['sent' => 0, 'errors' => [$message], 'arrival_date' => (string) ($schedina['arrival_date'] ?? '')];
    }

    $send = alloggiati_ws_run_method($pdo, 'Send', $config, (string) $tokenInfo['token'], [$line], 'single_schedina', (string) $schedinaId);
    $sendResult = alloggiati_ws_normalize_row_results((array) ($send['row_results'] ?? []), 1, $send['error_message'] ?: 'Invio non concluso correttamente.')[0];
    if (empty($sendResult['success'])) {
        $message = alloggiati_ws_compose_error_message($sendResult, $send['error_message'] ?: 'Invio schedina fallito.');
        alloggiati_ws_mark_schedine_failed($pdo, [$schedina], $message);
        return ['sent' => 0, 'errors' => [$message], 'arrival_date' => (string) ($schedina['arrival_date'] ?? '')];
    }

    $stmt = $pdo->prepare("UPDATE alloggiati_schedine SET status = 'inviata', sent_at = NOW(), last_attempt_at = NOW(), attempt_count = attempt_count + 1, last_error = NULL, validation_errors = NULL, updated_at = NOW() WHERE id = :id");
    $stmt->execute(['id' => $schedinaId]);

    return ['sent' => 1, 'errors' => [], 'arrival_date' => (string) ($schedina['arrival_date'] ?? '')];
}
