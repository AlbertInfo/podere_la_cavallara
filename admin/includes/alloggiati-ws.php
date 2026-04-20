<?php

declare(strict_types=1);

require_once __DIR__ . '/alloggiati.php';
require_once __DIR__ . '/ws-http.php';
require_once __DIR__ . '/webservice-log.php';

function alloggiati_ws_simulation_enabled(array $config): bool
{
    return !empty($config['simulate_send_without_ws']);
}

function alloggiati_ws_generate_token_request(array $config): string
{
    $preview = alloggiati_build_ws_previews([]);
    return (string) ($preview['generate_token_xml'] ?? '');
}

function alloggiati_ws_list_request(array $traceRecords, string $method, string $token): string
{
    $preview = alloggiati_build_ws_previews($traceRecords);
    $xml = $method === 'Send' ? (string) ($preview['send_xml'] ?? '') : (string) ($preview['test_xml'] ?? '');
    return str_replace('TOKEN_DA_GENERARE', htmlspecialchars($token, ENT_XML1 | ENT_COMPAT, 'UTF-8'), $xml);
}

function alloggiati_ws_generate_token(array $config): array
{
    if (!alloggiati_ws_config_ready($config)) {
        return [
            'success' => false,
            'token' => '',
            'expires' => '',
            'request_xml' => '',
            'response_xml' => '',
            'error' => 'Configura utente, password e WSKEY del servizio Alloggiati Web.',
        ];
    }

    $requestXml = alloggiati_ws_generate_token_request($config);
    $response = ws_http_post_xml((string) $config['endpoint'], $requestXml, [
        'Content-Type: application/soap+xml; charset=utf-8',
        'Accept: application/soap+xml, text/xml, */*',
    ]);

    if (!$response['success']) {
        return [
            'success' => false,
            'token' => '',
            'expires' => '',
            'request_xml' => $requestXml,
            'response_xml' => (string) $response['body'],
            'error' => trim((string) ($response['error'] ?: ('HTTP ' . $response['status_code']))),
        ];
    }

    $xpath = ws_xml_xpath((string) $response['body']);
    if (!$xpath) {
        return [
            'success' => false,
            'token' => '',
            'expires' => '',
            'request_xml' => $requestXml,
            'response_xml' => (string) $response['body'],
            'error' => 'Risposta non valida da GenerateToken.',
        ];
    }

    $token = ws_xml_value($xpath, '//*[local-name()="GenerateTokenResult"]/*[local-name()="token"]');
    $expires = ws_xml_value($xpath, '//*[local-name()="GenerateTokenResult"]/*[local-name()="expires"]');
    $esito = strtolower(ws_xml_value($xpath, '//*[local-name()="result"]/*[local-name()="esito"]'));
    $errorDes = ws_xml_value($xpath, '//*[local-name()="result"]/*[local-name()="ErroreDes" or local-name()="errorDes"]');
    $errorDet = ws_xml_value($xpath, '//*[local-name()="result"]/*[local-name()="ErroreDettaglio" or local-name()="erroreDettaglio"]');

    if ($token === '' || ($esito !== '' && $esito !== 'true')) {
        return [
            'success' => false,
            'token' => '',
            'expires' => $expires,
            'request_xml' => $requestXml,
            'response_xml' => (string) $response['body'],
            'error' => trim($errorDes . ' ' . $errorDet) ?: 'GenerateToken non riuscito.',
        ];
    }

    return [
        'success' => true,
        'token' => $token,
        'expires' => $expires,
        'request_xml' => $requestXml,
        'response_xml' => (string) $response['body'],
        'error' => '',
    ];
}

function alloggiati_ws_call_list(array $config, array $traceRecords, string $method, string $token): array
{
    $requestXml = alloggiati_ws_list_request($traceRecords, $method, $token);
    $response = ws_http_post_xml((string) $config['endpoint'], $requestXml, [
        'Content-Type: application/soap+xml; charset=utf-8',
        'Accept: application/soap+xml, text/xml, */*',
    ]);

    if (!$response['success']) {
        return [
            'success' => false,
            'request_xml' => $requestXml,
            'response_xml' => (string) $response['body'],
            'overall_error' => trim((string) ($response['error'] ?: ('HTTP ' . $response['status_code']))),
            'details' => [],
        ];
    }

    $xpath = ws_xml_xpath((string) $response['body']);
    if (!$xpath) {
        return [
            'success' => false,
            'request_xml' => $requestXml,
            'response_xml' => (string) $response['body'],
            'overall_error' => 'Risposta XML non valida da ' . $method . '.',
            'details' => [],
        ];
    }

    $resultNode = $method === 'Send' ? 'SendResult' : 'TestResult';
    $esito = strtolower(ws_xml_value($xpath, '//*[local-name()="' . $resultNode . '"]/*[local-name()="esito"]'));
    $errorDes = ws_xml_value($xpath, '//*[local-name()="' . $resultNode . '"]/*[local-name()="ErroreDes" or local-name()="errorDes"]');
    $errorDet = ws_xml_value($xpath, '//*[local-name()="' . $resultNode . '"]/*[local-name()="ErroreDettaglio" or local-name()="erroreDettaglio"]');

    $details = [];
    $detailNodes = @$xpath->query('//*[local-name()="result"]/*[local-name()="Dettaglio"]/*[local-name()="EsitoOperazioneServizio"]');
    if ($detailNodes) {
        foreach ($detailNodes as $detailNode) {
            $values = [];
            foreach ($detailNode->childNodes as $childNode) {
                if ($childNode instanceof DOMElement) {
                    $values[$childNode->localName] = trim((string) $childNode->textContent);
                }
            }
            $details[] = [
                'success' => strtolower((string) ($values['esito'] ?? '')) === 'true',
                'error_code' => (string) ($values['ErroreCod'] ?? $values['errorCod'] ?? ''),
                'error_desc' => (string) ($values['ErroreDes'] ?? $values['errorDes'] ?? ''),
                'error_detail' => (string) ($values['ErroreDettaglio'] ?? $values['erroreDettaglio'] ?? ''),
            ];
        }
    }

    return [
        'success' => $esito === 'true',
        'request_xml' => $requestXml,
        'response_xml' => (string) $response['body'],
        'overall_error' => trim($errorDes . ' ' . $errorDet),
        'details' => $details,
    ];
}

function alloggiati_ws_mark_schedine(PDO $pdo, array $schedine, array $details, bool $markSent): array
{
    $sent = 0;
    $errors = [];

    foreach ($schedine as $index => $schedina) {
        $schedinaId = (int) ($schedina['id'] ?? 0);
        $detail = $details[$index] ?? null;
        $ok = $detail ? !empty($detail['success']) : $markSent;
        $errorMessage = '';
        if ($detail && !$ok) {
            $errorMessage = trim(((string) ($detail['error_desc'] ?? '')) . ' ' . ((string) ($detail['error_detail'] ?? '')));
        }

        if ($ok) {
            $stmt = $pdo->prepare('UPDATE alloggiati_schedine SET status = :status, sent_at = NOW(), last_attempt_at = NOW(), attempt_count = attempt_count + 1, last_error = NULL, validation_errors = NULL, updated_at = NOW() WHERE id = :id');
            $stmt->execute(['status' => 'inviata', 'id' => $schedinaId]);
            $sent++;
        } else {
            $stmt = $pdo->prepare('UPDATE alloggiati_schedine SET status = :status, last_attempt_at = NOW(), attempt_count = attempt_count + 1, last_error = :last_error, updated_at = NOW() WHERE id = :id');
            $stmt->execute(['status' => 'errore', 'last_error' => $errorMessage ?: 'Invio non riuscito.', 'id' => $schedinaId]);
            $errors[] = ((string) ($schedina['display_name'] ?? 'Schedina')) . ': ' . ($errorMessage ?: 'Invio non riuscito.');
        }
    }

    return ['sent' => $sent, 'errors' => $errors];
}

function alloggiati_ws_send_day(PDO $pdo, string $arrivalDate): array
{
    $bundle = alloggiati_collect_day_export($pdo, $arrivalDate);
    $schedine = array_values(array_filter((array) ($bundle['schedine'] ?? []), static function (array $row): bool {
        return (string) ($row['status'] ?? '') === 'pronta';
    }));
    $traceRecords = array_map(static fn(array $row): string => (string) ($row['trace_record'] ?? ''), $schedine);

    if (!$schedine || !$traceRecords) {
        return ['sent' => 0, 'errors' => ['Nessuna schedina pronta da inviare per il giorno selezionato.'], 'mode' => 'none'];
    }

    $config = alloggiati_ws_config();
    if (alloggiati_ws_simulation_enabled($config)) {
        $result = alloggiati_ws_mark_schedine($pdo, $schedine, [], true);
        webservice_log_event($pdo, [
            'service_name' => 'alloggiati',
            'scope_type' => 'day',
            'scope_ref' => $arrivalDate,
            'action_name' => 'simulate_send_day',
            'status' => 'simulated',
            'request_payload' => implode("\n", $traceRecords),
            'response_payload' => '',
            'error_message' => '',
        ]);
        return $result + ['mode' => 'simulation'];
    }

    $tokenData = alloggiati_ws_generate_token($config);
    webservice_log_event($pdo, [
        'service_name' => 'alloggiati',
        'scope_type' => 'day',
        'scope_ref' => $arrivalDate,
        'action_name' => 'GenerateToken',
        'status' => $tokenData['success'] ? 'sent' : 'failed',
        'request_payload' => (string) ($tokenData['request_xml'] ?? ''),
        'response_payload' => (string) ($tokenData['response_xml'] ?? ''),
        'error_message' => (string) ($tokenData['error'] ?? ''),
    ]);
    if (!$tokenData['success']) {
        return ['sent' => 0, 'errors' => [(string) $tokenData['error']], 'mode' => 'ws'];
    }

    $testData = alloggiati_ws_call_list($config, $traceRecords, 'Test', (string) $tokenData['token']);
    webservice_log_event($pdo, [
        'service_name' => 'alloggiati',
        'scope_type' => 'day',
        'scope_ref' => $arrivalDate,
        'action_name' => 'Test',
        'status' => $testData['success'] ? 'sent' : 'failed',
        'request_payload' => (string) ($testData['request_xml'] ?? ''),
        'response_payload' => (string) ($testData['response_xml'] ?? ''),
        'error_message' => (string) ($testData['overall_error'] ?? ''),
    ]);
    if (!$testData['success']) {
        $marked = alloggiati_ws_mark_schedine($pdo, $schedine, $testData['details'] ?? [], false);
        $errors = $marked['errors'] ?: [(string) ($testData['overall_error'] ?: 'Test Alloggiati non riuscito.')];
        return ['sent' => 0, 'errors' => $errors, 'mode' => 'ws'];
    }

    $sendData = alloggiati_ws_call_list($config, $traceRecords, 'Send', (string) $tokenData['token']);
    webservice_log_event($pdo, [
        'service_name' => 'alloggiati',
        'scope_type' => 'day',
        'scope_ref' => $arrivalDate,
        'action_name' => 'Send',
        'status' => $sendData['success'] ? 'sent' : 'failed',
        'request_payload' => (string) ($sendData['request_xml'] ?? ''),
        'response_payload' => (string) ($sendData['response_xml'] ?? ''),
        'error_message' => (string) ($sendData['overall_error'] ?? ''),
    ]);

    $marked = alloggiati_ws_mark_schedine($pdo, $schedine, $sendData['details'] ?? [], $sendData['success']);
    if (!$sendData['success'] && !$marked['errors']) {
        $marked['errors'][] = (string) ($sendData['overall_error'] ?: 'Invio Alloggiati non riuscito.');
    }
    return $marked + ['mode' => 'ws'];
}

function alloggiati_ws_send_schedina(PDO $pdo, int $schedinaId): array
{
    $bundle = alloggiati_collect_single_export($pdo, $schedinaId);
    $schedina = $bundle['schedina'] ?? null;
    if (!$schedina || !empty($bundle['errors'])) {
        return ['sent' => 0, 'errors' => array_values($bundle['errors'] ?? ['Schedina non disponibile.']), 'mode' => 'none'];
    }

    $config = alloggiati_ws_config();
    $traceRecord = (string) ($schedina['trace_record'] ?? '');
    if ($traceRecord === '') {
        return ['sent' => 0, 'errors' => ['Schedina senza tracciato record valido.'], 'mode' => 'none'];
    }

    if (alloggiati_ws_simulation_enabled($config)) {
        $result = alloggiati_ws_mark_schedine($pdo, [$schedina], [], true);
        webservice_log_event($pdo, [
            'service_name' => 'alloggiati',
            'scope_type' => 'schedina',
            'scope_ref' => (string) $schedinaId,
            'action_name' => 'simulate_send_single',
            'status' => 'simulated',
            'request_payload' => $traceRecord,
            'response_payload' => '',
            'error_message' => '',
        ]);
        return $result + ['mode' => 'simulation'];
    }

    $tokenData = alloggiati_ws_generate_token($config);
    webservice_log_event($pdo, [
        'service_name' => 'alloggiati',
        'scope_type' => 'schedina',
        'scope_ref' => (string) $schedinaId,
        'action_name' => 'GenerateToken',
        'status' => $tokenData['success'] ? 'sent' : 'failed',
        'request_payload' => (string) ($tokenData['request_xml'] ?? ''),
        'response_payload' => (string) ($tokenData['response_xml'] ?? ''),
        'error_message' => (string) ($tokenData['error'] ?? ''),
    ]);
    if (!$tokenData['success']) {
        return ['sent' => 0, 'errors' => [(string) $tokenData['error']], 'mode' => 'ws'];
    }

    $testData = alloggiati_ws_call_list($config, [$traceRecord], 'Test', (string) $tokenData['token']);
    webservice_log_event($pdo, [
        'service_name' => 'alloggiati',
        'scope_type' => 'schedina',
        'scope_ref' => (string) $schedinaId,
        'action_name' => 'Test',
        'status' => $testData['success'] ? 'sent' : 'failed',
        'request_payload' => (string) ($testData['request_xml'] ?? ''),
        'response_payload' => (string) ($testData['response_xml'] ?? ''),
        'error_message' => (string) ($testData['overall_error'] ?? ''),
    ]);
    if (!$testData['success']) {
        $marked = alloggiati_ws_mark_schedine($pdo, [$schedina], $testData['details'] ?? [], false);
        $errors = $marked['errors'] ?: [(string) ($testData['overall_error'] ?: 'Test Alloggiati non riuscito.')];
        return ['sent' => 0, 'errors' => $errors, 'mode' => 'ws'];
    }

    $sendData = alloggiati_ws_call_list($config, [$traceRecord], 'Send', (string) $tokenData['token']);
    webservice_log_event($pdo, [
        'service_name' => 'alloggiati',
        'scope_type' => 'schedina',
        'scope_ref' => (string) $schedinaId,
        'action_name' => 'Send',
        'status' => $sendData['success'] ? 'sent' : 'failed',
        'request_payload' => (string) ($sendData['request_xml'] ?? ''),
        'response_payload' => (string) ($sendData['response_xml'] ?? ''),
        'error_message' => (string) ($sendData['overall_error'] ?? ''),
    ]);

    $marked = alloggiati_ws_mark_schedine($pdo, [$schedina], $sendData['details'] ?? [], $sendData['success']);
    if (!$sendData['success'] && !$marked['errors']) {
        $marked['errors'][] = (string) ($sendData['overall_error'] ?: 'Invio Alloggiati non riuscito.');
    }
    return $marked + ['mode' => 'ws'];
}
