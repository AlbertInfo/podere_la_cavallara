<?php

declare(strict_types=1);

require_once __DIR__ . '/ross1000.php';
require_once __DIR__ . '/ws-http.php';
require_once __DIR__ . '/webservice-log.php';

function ross1000_ws_simulation_enabled(array $config): bool
{
    return !empty($config['simulate_send_without_ws']);
}

function ross1000_ws_config_ready(array $config): bool
{
    return ross1000_property_config_ready($config)
        && trim((string) ($config['wsdl'] ?? '')) !== ''
        && trim((string) ($config['username'] ?? '')) !== ''
        && trim((string) ($config['password'] ?? '')) !== '';
}

function ross1000_ws_endpoint(array $config): string
{
    $wsdl = trim((string) ($config['wsdl'] ?? ''));
    if ($wsdl === '') {
        return '';
    }
    return preg_replace('/\?wsdl$/i', '', $wsdl) ?: $wsdl;
}

function ross1000_build_movimentazione_xml(array $payload): string
{
    $xml = new XMLWriter();
    $xml->openMemory();
    $xml->setIndent(true);
    $xml->setIndentString('  ');
    $xml->startDocument('1.0', 'UTF-8');

    $xml->startElement('movimentazione');
    $xml->writeElement('codice', (string) ($payload['codice'] ?? ''));
    $xml->writeElement('prodotto', (string) ($payload['prodotto'] ?? ''));

    foreach ((array) ($payload['movimenti'] ?? []) as $movimento) {
        $xml->startElement('movimento');
        $xml->writeElement('data', (string) ($movimento['data'] ?? ''));

        $xml->startElement('struttura');
        ross1000_xml_write_fields($xml, (array) ($movimento['struttura'] ?? []), [
            'apertura',
            'camereoccupate',
            'cameredisponibili',
            'lettidisponibili',
        ]);
        $xml->endElement();

        if (!empty($movimento['arrivi'])) {
            $xml->startElement('arrivi');
            foreach ((array) $movimento['arrivi'] as $arrivo) {
                $xml->startElement('arrivo');
                ross1000_xml_write_fields($xml, (array) $arrivo, [
                    'idswh', 'tipoalloggiato', 'idcapo', 'cognome', 'nome', 'sesso', 'cittadinanza',
                    'statoresidenza', 'luogoresidenza', 'datanascita', 'statonascita', 'comunenascita',
                    'tipoturismo', 'mezzotrasporto', 'canaleprenotazione', 'titolostudio', 'professione', 'esenzioneimposta',
                ]);
                $xml->endElement();
            }
            $xml->endElement();
        }

        if (!empty($movimento['partenze'])) {
            $xml->startElement('partenze');
            foreach ((array) $movimento['partenze'] as $partenza) {
                $xml->startElement('partenza');
                ross1000_xml_write_fields($xml, (array) $partenza, ['idswh', 'tipoalloggiato', 'arrivo']);
                $xml->endElement();
            }
            $xml->endElement();
        }

        if (!empty($movimento['prenotazioni'])) {
            $xml->startElement('prenotazioni');
            foreach ((array) $movimento['prenotazioni'] as $prenotazione) {
                $xml->startElement('prenotazione');
                ross1000_xml_write_fields($xml, (array) $prenotazione, [
                    'idswh', 'arrivo', 'partenza', 'ospiti', 'camere', 'prezzo', 'canaleprenotazione', 'statoprovenienza', 'comuneprovenienza',
                ]);
                $xml->endElement();
            }
            $xml->endElement();
        }

        if (!empty($movimento['rettifiche'])) {
            $xml->startElement('rettifiche');
            foreach ((array) $movimento['rettifiche'] as $rettifica) {
                $type = (string) ($rettifica['type'] ?? 'eliminazione');
                $xml->startElement($type);
                ross1000_xml_write_fields($xml, (array) ($rettifica['data'] ?? []), array_keys((array) ($rettifica['data'] ?? [])));
                $xml->endElement();
            }
            $xml->endElement();
        }

        $xml->endElement();
    }

    $xml->endElement();
    $xml->endDocument();

    return $xml->outputMemory();
}

function ross1000_ws_build_envelope(array $payload): string
{
    $movimentazioneXml = ross1000_build_movimentazione_xml($payload);
    $movimentazioneXml = preg_replace('/^<\?xml[^>]+>\s*/', '', $movimentazioneXml) ?: $movimentazioneXml;

    return '<?xml version="1.0" encoding="UTF-8"?>' .
        '<S:Envelope xmlns:S="http://schemas.xmlsoap.org/soap/envelope/">' .
        '<S:Body>' .
        '<ns2:inviaMovimentazione xmlns:ns2="http://checkin.ws.service.turismo5.gies.it/">' .
        $movimentazioneXml .
        '</ns2:inviaMovimentazione>' .
        '</S:Body>' .
        '</S:Envelope>';
}

function ross1000_ws_send_payload(array $config, array $payload): array
{
    if (!ross1000_ws_config_ready($config)) {
        return ['success' => false, 'request_xml' => '', 'response_xml' => '', 'error' => 'Configura WSDL, username e password del web service ROSS1000.'];
    }

    $requestXml = ross1000_ws_build_envelope($payload);
    $response = ws_http_post_xml(ross1000_ws_endpoint($config), $requestXml, [
        'Content-Type: text/xml; charset=utf-8',
        'Accept: text/xml, multipart/related, */*',
        'SOAPAction: ""',
    ], [
        'basic_auth_user' => (string) ($config['username'] ?? ''),
        'basic_auth_pass' => (string) ($config['password'] ?? ''),
    ]);

    if (!$response['success']) {
        return ['success' => false, 'request_xml' => $requestXml, 'response_xml' => (string) $response['body'], 'error' => trim((string) ($response['error'] ?: ('HTTP ' . $response['status_code'])) )];
    }

    $xpath = ws_xml_xpath((string) $response['body']);
    if ($xpath) {
        $faultString = ws_xml_value($xpath, '//*[local-name()="Fault"]/*[local-name()="faultstring" or local-name()="Reason"]');
        if ($faultString !== '') {
            return ['success' => false, 'request_xml' => $requestXml, 'response_xml' => (string) $response['body'], 'error' => $faultString];
        }
    }

    return ['success' => true, 'request_xml' => $requestXml, 'response_xml' => (string) $response['body'], 'error' => ''];
}

function ross1000_ws_mark_exported_day(PDO $pdo, string $day, array $state): void
{
    if (!ross1000_day_status_table_ready($pdo)) {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO ross1000_day_status (day_date, is_open, available_rooms, available_beds, is_finalized, finalized_at, exported_ross_at, created_at, updated_at)
         VALUES (:day_date, :is_open, :available_rooms, :available_beds, :is_finalized, :finalized_at, NOW(), NOW(), NOW())
         ON DUPLICATE KEY UPDATE exported_ross_at = NOW(), updated_at = NOW()'
    );
    $stmt->execute([
        'day_date' => $day,
        'is_open' => (int) ($state['is_open'] ?? 1),
        'available_rooms' => (int) ($state['available_rooms'] ?? 0),
        'available_beds' => (int) ($state['available_beds'] ?? 0),
        'is_finalized' => (int) ($state['is_finalized'] ?? 0),
        'finalized_at' => $state['finalized_at'] ?? null,
    ]);
}

function ross1000_ws_send_day(PDO $pdo, string $day): array
{
    $config = ross1000_property_config();
    $state = ross1000_get_day_state($pdo, $day, $config);
    if ((int) ($state['is_finalized'] ?? 0) !== 1) {
        return ['success' => false, 'errors' => ['Chiudi il giorno prima di inviarlo al web service ROSS1000.'], 'mode' => 'none'];
    }

    $payload = ross1000_build_day_payload($pdo, $day);
    if (ross1000_ws_simulation_enabled($config)) {
        ross1000_ws_mark_exported_day($pdo, $day, $state);
        webservice_log_event($pdo, [
            'service_name' => 'ross1000',
            'scope_type' => 'day',
            'scope_ref' => $day,
            'action_name' => 'simulate_send_day',
            'status' => 'simulated',
            'request_payload' => ross1000_ws_build_envelope($payload),
            'response_payload' => '',
            'error_message' => '',
        ]);
        return ['success' => true, 'errors' => [], 'mode' => 'simulation'];
    }

    $result = ross1000_ws_send_payload($config, $payload);
    webservice_log_event($pdo, [
        'service_name' => 'ross1000',
        'scope_type' => 'day',
        'scope_ref' => $day,
        'action_name' => 'send_day',
        'status' => $result['success'] ? 'sent' : 'failed',
        'request_payload' => (string) ($result['request_xml'] ?? ''),
        'response_payload' => (string) ($result['response_xml'] ?? ''),
        'error_message' => (string) ($result['error'] ?? ''),
    ]);

    if (!$result['success']) {
        return ['success' => false, 'errors' => [(string) $result['error']], 'mode' => 'ws'];
    }

    ross1000_ws_mark_exported_day($pdo, $day, $state);
    return ['success' => true, 'errors' => [], 'mode' => 'ws'];
}

function ross1000_ws_send_month(PDO $pdo, string $month, array $ranges = []): array
{
    $config = ross1000_property_config();
    if (!ross1000_day_status_table_ready($pdo)) {
        return ['success' => false, 'errors' => ['Esegui prima la migration della tabella ross1000_day_status.'], 'mode' => 'none'];
    }

    [$fromDate, $toDate] = ross1000_month_range($month);
    $monthEnd = new DateTimeImmutable($toDate);
    $daysInMonth = (int) $monthEnd->format('j');

    $pdo->beginTransaction();
    try {
        ross1000_prefill_open_month($pdo, $month, $config, true);
        foreach ($ranges as $range) {
            $state = (string) ($range['state'] ?? '');
            $fromDay = max(1, min($daysInMonth, (int) ($range['from'] ?? 0)));
            $toDay = max(1, min($daysInMonth, (int) ($range['to'] ?? 0)));
            if (!in_array($state, ['open', 'closed'], true) || !$fromDay || !$toDay) {
                continue;
            }
            if ($fromDay > $toDay) {
                [$fromDay, $toDay] = [$toDay, $fromDay];
            }
            $states = ross1000_get_day_states_for_range($pdo, $fromDate, $toDate, $config);
            for ($day = $fromDay; $day <= $toDay; $day++) {
                $date = sprintf('%s-%02d', $month, $day);
                $current = $states[$date] ?? ross1000_default_day_state($config, $date);
                if ((int) ($current['is_finalized'] ?? 0) === 1) {
                    continue;
                }
                $isOpen = $state === 'open';
                ross1000_upsert_day_state($pdo, $date, [
                    'day_date' => $date,
                    'is_open' => $isOpen ? 1 : 0,
                    'available_rooms' => $isOpen ? (int) ($config['camere_disponibili'] ?? 0) : 0,
                    'available_beds' => $isOpen ? (int) ($config['letti_disponibili'] ?? 0) : 0,
                    'is_finalized' => (int) ($current['is_finalized'] ?? 0),
                    'finalized_at' => $current['finalized_at'] ?? null,
                    'exported_ross_at' => $current['exported_ross_at'] ?? null,
                    'exported_alloggiati_at' => $current['exported_alloggiati_at'] ?? null,
                ]);
            }
        }

        $payload = ross1000_build_month_payload($pdo, $month);

        if (ross1000_ws_simulation_enabled($config)) {
            $stmt = $pdo->prepare('UPDATE ross1000_day_status SET exported_ross_at = NOW(), updated_at = NOW() WHERE day_date BETWEEN :from_date AND :to_date');
            $stmt->execute(['from_date' => $fromDate, 'to_date' => $toDate]);
            webservice_log_event($pdo, [
                'service_name' => 'ross1000',
                'scope_type' => 'month',
                'scope_ref' => $month,
                'action_name' => 'simulate_send_month',
                'status' => 'simulated',
                'request_payload' => ross1000_ws_build_envelope($payload),
                'response_payload' => '',
                'error_message' => '',
            ]);
            $pdo->commit();
            return ['success' => true, 'errors' => [], 'mode' => 'simulation'];
        }

        $result = ross1000_ws_send_payload($config, $payload);
        webservice_log_event($pdo, [
            'service_name' => 'ross1000',
            'scope_type' => 'month',
            'scope_ref' => $month,
            'action_name' => 'send_month',
            'status' => $result['success'] ? 'sent' : 'failed',
            'request_payload' => (string) ($result['request_xml'] ?? ''),
            'response_payload' => (string) ($result['response_xml'] ?? ''),
            'error_message' => (string) ($result['error'] ?? ''),
        ]);
        if (!$result['success']) {
            $pdo->rollBack();
            return ['success' => false, 'errors' => [(string) $result['error']], 'mode' => 'ws'];
        }

        $stmt = $pdo->prepare('UPDATE ross1000_day_status SET exported_ross_at = NOW(), updated_at = NOW() WHERE day_date BETWEEN :from_date AND :to_date');
        $stmt->execute(['from_date' => $fromDate, 'to_date' => $toDate]);
        $pdo->commit();
        return ['success' => true, 'errors' => [], 'mode' => 'ws'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'errors' => [$e->getMessage()], 'mode' => 'none'];
    }
}
