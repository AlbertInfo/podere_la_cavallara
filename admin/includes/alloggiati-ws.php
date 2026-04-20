<?php

declare(strict_types=1);

require_once __DIR__ . '/alloggiati.php';
require_once __DIR__ . '/ws-http.php';
require_once __DIR__ . '/webservice-log.php';

function alloggiati_ws_extract_simple_value(string $xml, string $tag): string
{
    if (preg_match('/<' . preg_quote($tag, '/') . '>(.*?)<\/' . preg_quote($tag, '/') . '>/si', $xml, $m)) {
        return html_entity_decode(trim((string) $m[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
    return '';
}

function alloggiati_ws_extract_row_results(string $xml): array
{
    $results = [];
    if (!preg_match_all('/<EsitoOperazioneServizio>(.*?)<\/EsitoOperazioneServizio>/si', $xml, $matches)) {
        return $results;
    }
    foreach ($matches[1] as $chunk) {
        $ok = strtolower(alloggiati_ws_extract_simple_value($chunk, 'esito')) === 'true';
        $results[] = [
            'success' => $ok,
            'error_code' => alloggiati_ws_extract_simple_value($chunk, 'ErroreCod'),
            'error_description' => alloggiati_ws_extract_simple_value($chunk, 'ErroreDes'),
            'error_detail' => alloggiati_ws_extract_simple_value($chunk, 'ErroreDettaglio'),
        ];
    }
    return $results;
}

function alloggiati_ws_envelope_generate_token(array $config): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope" xmlns:all="AlloggiatiService">'
        . '<soap:Header/>'
        . '<soap:Body>'
        . '<all:GenerateToken>'
        . '<all:Utente>' . htmlspecialchars((string) $config['utente'], ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</all:Utente>'
        . '<all:Password>' . htmlspecialchars((string) $config['password'], ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</all:Password>'
        . '<all:WsKey>' . htmlspecialchars((string) $config['wskey'], ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</all:WsKey>'
        . '</all:GenerateToken>'
        . '</soap:Body>'
        . '</soap:Envelope>';
}

function alloggiati_ws_envelope_lines(string $method, array $config, string $token, array $lines): string
{
    $items = '';
    foreach ($lines as $line) {
        $items .= '<all:string>' . htmlspecialchars($line, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</all:string>';
    }

    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope" xmlns:all="AlloggiatiService">'
        . '<soap:Header/>'
        . '<soap:Body>'
        . '<all:' . $method . '>'
        . '<all:Utente>' . htmlspecialchars((string) $config['utente'], ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</all:Utente>'
        . '<all:token>' . htmlspecialchars($token, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</all:token>'
        . '<all:ElencoSchedine>' . $items . '</all:ElencoSchedine>'
        . '</all:' . $method . '>'
        . '</soap:Body>'
        . '</soap:Envelope>';
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

function alloggiati_ws_generate_token_live(PDO $pdo, array $config): array
{
    $requestXml = alloggiati_ws_envelope_generate_token($config);
    $response = alloggiati_ws_post((string) $config['endpoint'], $requestXml);
    $body = (string) ($response['body'] ?? '');
    $token = alloggiati_ws_extract_simple_value($body, 'token');
    $success = $token !== '' && stripos($body, '<Fault>') === false;

    webservice_log($pdo, [
        'service_name' => 'alloggiati',
        'action_name' => 'GenerateToken',
        'scope_type' => 'auth',
        'scope_ref' => '',
        'is_simulated' => false,
        'success' => $success,
        'request_payload' => $requestXml,
        'response_payload' => $body,
        'error_message' => $success ? '' : 'GenerateToken non riuscito.',
    ]);

    if (!$success) {
        throw new RuntimeException('GenerateToken non riuscito. Controlla credenziali, WSKEY e log del web service Alloggiati.');
    }

    return ['token' => $token, 'response_body' => $body];
}

function alloggiati_ws_run_method(PDO $pdo, string $method, array $config, string $token, array $lines, string $scopeType, string $scopeRef): array
{
    $requestXml = alloggiati_ws_envelope_lines($method, $config, $token, $lines);
    $response = alloggiati_ws_post((string) $config['endpoint'], $requestXml);
    $body = (string) ($response['body'] ?? '');
    $success = stripos($body, '<Fault>') === false && stripos($body, '<esito>true</esito>') !== false;

    webservice_log($pdo, [
        'service_name' => 'alloggiati',
        'action_name' => $method,
        'scope_type' => $scopeType,
        'scope_ref' => $scopeRef,
        'is_simulated' => false,
        'success' => $success,
        'request_payload' => $requestXml,
        'response_payload' => $body,
        'error_message' => $success ? '' : ($method . ' ha restituito errori.'),
    ]);

    return [
        'success' => $success,
        'request_xml' => $requestXml,
        'response_body' => $body,
        'row_results' => alloggiati_ws_extract_row_results($body),
    ];
}

function alloggiati_ws_send_day(PDO $pdo, string $arrivalDate): array
{
    $bundle = alloggiati_collect_day_export($pdo, $arrivalDate);
    $schedine = [];
    $lines = [];
    foreach (($bundle['schedine'] ?? []) as $row) {
        if ((string) ($row['status'] ?? '') === 'pronta') {
            $schedine[] = $row;
            $lines[] = (string) ($row['trace_record'] ?? '');
        }
    }

    if (!$schedine) {
        return ['sent' => 0, 'errors' => array_values($bundle['errors'] ?? ['Nessuna schedina pronta da inviare.'])];
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
            'request_payload' => implode("
", $lines),
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

    $tokenInfo = alloggiati_ws_generate_token_live($pdo, $config);
    $test = alloggiati_ws_run_method($pdo, 'Test', $config, (string) $tokenInfo['token'], $lines, 'day', $arrivalDate);
    $testResults = $test['row_results'];

    $errors = [];
    $sendableRows = [];
    $sendableLines = [];
    foreach ($schedine as $index => $row) {
        $rowResult = $testResults[$index] ?? ['success' => true, 'error_detail' => ''];
        if (!empty($rowResult['success'])) {
            $sendableRows[] = $row;
            $sendableLines[] = $lines[$index];
        } else {
            $message = trim(($rowResult['error_description'] ?? '') . ' ' . ($rowResult['error_detail'] ?? '')) ?: 'Schedina non valida.';
            $errors[] = ($row['display_name'] ?? 'Schedina') . ': ' . $message;
            $stmt = $pdo->prepare("UPDATE alloggiati_schedine SET status = 'errore', last_attempt_at = NOW(), attempt_count = attempt_count + 1, last_error = :err, updated_at = NOW() WHERE id = :id");
            $stmt->execute(['err' => $message, 'id' => (int) $row['id']]);
        }
    }

    if (!$sendableRows) {
        return ['sent' => 0, 'errors' => $errors ?: ['Nessuna schedina valida dopo il test preliminare.']];
    }

    $send = alloggiati_ws_run_method($pdo, 'Send', $config, (string) $tokenInfo['token'], $sendableLines, 'day', $arrivalDate);
    $sendResults = $send['row_results'];
    $sent = 0;
    foreach ($sendableRows as $index => $row) {
        $rowResult = $sendResults[$index] ?? ['success' => false, 'error_detail' => 'Risposta incompleta del servizio'];
        if (!empty($rowResult['success'])) {
            $stmt = $pdo->prepare("UPDATE alloggiati_schedine SET status = 'inviata', sent_at = NOW(), last_attempt_at = NOW(), attempt_count = attempt_count + 1, last_error = NULL, validation_errors = NULL, updated_at = NOW() WHERE id = :id");
            $stmt->execute(['id' => (int) $row['id']]);
            $sent++;
        } else {
            $message = trim(($rowResult['error_description'] ?? '') . ' ' . ($rowResult['error_detail'] ?? '')) ?: 'Invio schedina fallito.';
            $errors[] = ($row['display_name'] ?? 'Schedina') . ': ' . $message;
            $stmt = $pdo->prepare("UPDATE alloggiati_schedine SET status = 'errore', last_attempt_at = NOW(), attempt_count = attempt_count + 1, last_error = :err, updated_at = NOW() WHERE id = :id");
            $stmt->execute(['err' => $message, 'id' => (int) $row['id']]);
        }
    }

    return ['sent' => $sent, 'errors' => array_values(array_unique($errors))];
}

function alloggiati_ws_send_schedina(PDO $pdo, int $schedinaId): array
{
    $bundle = alloggiati_collect_single_export($pdo, $schedinaId);
    $schedina = $bundle['schedina'] ?? null;
    $line = (string) ($bundle['content'] ?? '');
    if (!$schedina || trim($line) === '') {
        return ['sent' => 0, 'errors' => array_values($bundle['errors'] ?? ['Schedina non pronta.'])];
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
            'request_payload' => $line,
            'response_payload' => 'SIMULATED_OK',
            'error_message' => '',
        ]);
        $stmt = $pdo->prepare("UPDATE alloggiati_schedine SET status = 'inviata', sent_at = NOW(), last_attempt_at = NOW(), attempt_count = attempt_count + 1, last_error = NULL, validation_errors = NULL, updated_at = NOW() WHERE id = :id");
        $stmt->execute(['id' => $schedinaId]);
        return ['sent' => 1, 'errors' => [], 'arrival_date' => (string) ($schedina['arrival_date'] ?? '')];
    }

    $tokenInfo = alloggiati_ws_generate_token_live($pdo, $config);
    $test = alloggiati_ws_run_method($pdo, 'Test', $config, (string) $tokenInfo['token'], [$line], 'single_schedina', (string) $schedinaId);
    $testResult = $test['row_results'][0] ?? ['success' => false, 'error_detail' => 'Risposta incompleta del servizio'];
    if (empty($testResult['success'])) {
        $message = trim(($testResult['error_description'] ?? '') . ' ' . ($testResult['error_detail'] ?? '')) ?: 'Schedina non valida.';
        $stmt = $pdo->prepare("UPDATE alloggiati_schedine SET status = 'errore', last_attempt_at = NOW(), attempt_count = attempt_count + 1, last_error = :err, updated_at = NOW() WHERE id = :id");
        $stmt->execute(['err' => $message, 'id' => $schedinaId]);
        return ['sent' => 0, 'errors' => [$message], 'arrival_date' => (string) ($schedina['arrival_date'] ?? '')];
    }

    $send = alloggiati_ws_run_method($pdo, 'Send', $config, (string) $tokenInfo['token'], [$line], 'single_schedina', (string) $schedinaId);
    $sendResult = $send['row_results'][0] ?? ['success' => false, 'error_detail' => 'Risposta incompleta del servizio'];
    if (empty($sendResult['success'])) {
        $message = trim(($sendResult['error_description'] ?? '') . ' ' . ($sendResult['error_detail'] ?? '')) ?: 'Invio schedina fallito.';
        $stmt = $pdo->prepare("UPDATE alloggiati_schedine SET status = 'errore', last_attempt_at = NOW(), attempt_count = attempt_count + 1, last_error = :err, updated_at = NOW() WHERE id = :id");
        $stmt->execute(['err' => $message, 'id' => $schedinaId]);
        return ['sent' => 0, 'errors' => [$message], 'arrival_date' => (string) ($schedina['arrival_date'] ?? '')];
    }

    $stmt = $pdo->prepare("UPDATE alloggiati_schedine SET status = 'inviata', sent_at = NOW(), last_attempt_at = NOW(), attempt_count = attempt_count + 1, last_error = NULL, validation_errors = NULL, updated_at = NOW() WHERE id = :id");
    $stmt->execute(['id' => $schedinaId]);

    return ['sent' => 1, 'errors' => [], 'arrival_date' => (string) ($schedina['arrival_date'] ?? '')];
}
