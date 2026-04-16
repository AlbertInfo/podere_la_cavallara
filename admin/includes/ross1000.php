<?php

declare(strict_types=1);

require_once __DIR__ . '/ross1000-config.php';
require_once __DIR__ . '/anagrafica-options.php';

function ross1000_format_date(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '';
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('Ymd', $timestamp) : '';
}

function ross1000_normalize_decimal($value): string
{
    if ($value === null || trim((string) $value) === '') {
        return '';
    }

    $normalized = str_replace(',', '.', trim((string) $value));
    if (!is_numeric($normalized)) {
        return '';
    }

    return number_format((float) $normalized, 2, '.', '');
}

function ross1000_is_open_on_date(array $config, string $date): bool
{
    if (!empty($config['aperto_tutto_anno'])) {
        return true;
    }

    foreach ((array) ($config['giorni_chiusura'] ?? []) as $closedDate) {
        if ($closedDate === $date) {
            return false;
        }
    }

    foreach ((array) ($config['periodi_chiusura'] ?? []) as $period) {
        if (!is_array($period)) {
            continue;
        }
        $from = (string) ($period['from'] ?? '');
        $to = (string) ($period['to'] ?? '');
        if ($from !== '' && $to !== '' && $date >= $from && $date <= $to) {
            return false;
        }
    }

    return true;
}

function ross1000_fetch_record(PDO $pdo, int $recordId): array
{
    $stmt = $pdo->prepare('SELECT * FROM anagrafica_records WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $recordId]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$record) {
        throw new RuntimeException('Anagrafica non trovata.');
    }

    $guestStmt = $pdo->prepare('SELECT * FROM anagrafica_guests WHERE record_id = :record_id ORDER BY is_group_leader DESC, id ASC');
    $guestStmt->execute(['record_id' => $recordId]);
    $guests = $guestStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (!$guests) {
        throw new RuntimeException('Nessun ospite presente nell\'anagrafica selezionata.');
    }

    return [$record, $guests];
}

function ross1000_validate_required_data(array $config, array $record, array $guests): array
{
    $errors = [];

    if (!ross1000_property_config_ready($config)) {
        $errors[] = 'Configura prima admin/includes/ross1000-config.php con codice struttura, camere e letti disponibili.';
    }

    if (trim((string) ($record['ross_prenotazione_idswh'] ?? '')) === '') {
        $errors[] = 'Manca l\'idswh della prenotazione.';
    }
    if (trim((string) ($record['booking_received_date'] ?? '')) === '') {
        $errors[] = 'Compila la data registrazione prenotazione.';
    }
    if (trim((string) ($record['arrival_date'] ?? '')) === '' || trim((string) ($record['departure_date'] ?? '')) === '') {
        $errors[] = 'Date di arrivo e partenza obbligatorie.';
    }
    if ((int) ($record['reserved_rooms'] ?? 0) <= 0) {
        $errors[] = 'Numero camere non valido.';
    }

    foreach ($guests as $index => $guest) {
        $prefix = 'Ospite #' . ($index + 1) . ' (' . trim((string) (($guest['first_name'] ?? '') . ' ' . ($guest['last_name'] ?? ''))) . ')';
        $requiredFields = [
            'guest_idswh' => 'idswh ospite',
            'tipoalloggiato_code' => 'tipo alloggiato',
            'first_name' => 'nome',
            'last_name' => 'cognome',
            'gender' => 'sesso',
            'birth_date' => 'data di nascita',
            'citizenship_code' => 'codice cittadinanza',
            'residence_state_code' => 'codice stato residenza',
            'residence_place_code' => 'luogo residenza',
            'tourism_type' => 'tipo turismo',
            'transport_type' => 'mezzo di trasporto',
        ];

        foreach ($requiredFields as $field => $label) {
            if (trim((string) ($guest[$field] ?? '')) === '') {
                $errors[] = $prefix . ': manca ' . $label . '.';
            }
        }

        if (in_array((string) ($guest['tipoalloggiato_code'] ?? ''), ['19', '20'], true) && trim((string) ($guest['leader_idswh'] ?? '')) === '') {
            $errors[] = $prefix . ': i componenti gruppo/famiglia devono avere idcapo valorizzato.';
        }
    }

    return array_values(array_unique($errors));
}

function ross1000_collect_relevant_dates(array $record): array
{
    $dates = array_filter([
        substr((string) ($record['booking_received_date'] ?? ''), 0, 10),
        substr((string) ($record['arrival_date'] ?? ''), 0, 10),
        substr((string) ($record['departure_date'] ?? ''), 0, 10),
    ]);

    $dates = array_values(array_unique($dates));
    sort($dates);

    return $dates;
}

function ross1000_compute_occupied_rooms(PDO $pdo, string $date): int
{
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(reserved_rooms), 0) FROM anagrafica_records WHERE arrival_date <= :date AND departure_date > :date');
    $stmt->execute(['date' => $date]);
    return (int) $stmt->fetchColumn();
}

function ross1000_build_guest_arrivo(array $guest, array $record): array
{
    $bookingChannel = trim((string) ($guest['guest_booking_channel'] ?? '')) !== ''
        ? (string) $guest['guest_booking_channel']
        : (string) ($record['booking_channel'] ?? '');

    return [
        'idswh' => (string) ($guest['guest_idswh'] ?? ''),
        'tipoalloggiato' => (string) ($guest['tipoalloggiato_code'] ?? ''),
        'idcapo' => (int) ($guest['is_group_leader'] ?? 0) === 1 ? '' : (string) ($guest['leader_idswh'] ?? ''),
        'cognome' => (string) ($guest['last_name'] ?? ''),
        'nome' => (string) ($guest['first_name'] ?? ''),
        'sesso' => (string) ($guest['gender'] ?? ''),
        'cittadinanza' => (string) ($guest['citizenship_code'] ?? ''),
        'statoresidenza' => (string) ($guest['residence_state_code'] ?? ''),
        'luogoresidenza' => (string) ($guest['residence_place_code'] ?? ''),
        'datanascita' => ross1000_format_date((string) ($guest['birth_date'] ?? '')),
        'statonascita' => (string) ($guest['birth_state_code'] ?? ''),
        'comunenascita' => (string) ($guest['birth_city_code'] ?? ''),
        'tipoturismo' => (string) ($guest['tourism_type'] ?? ''),
        'mezzotrasporto' => (string) ($guest['transport_type'] ?? ''),
        'canaleprenotazione' => $bookingChannel,
        'titolostudio' => (string) ($guest['education_level'] ?? ''),
        'professione' => (string) ($guest['profession'] ?? ''),
        'esenzioneimposta' => (string) ($guest['tax_exemption_code'] ?? ''),
    ];
}

function ross1000_build_guest_partenza(array $guest, array $record): array
{
    return [
        'idswh' => (string) ($guest['guest_idswh'] ?? ''),
        'tipoalloggiato' => (string) ($guest['tipoalloggiato_code'] ?? ''),
        'arrivo' => ross1000_format_date((string) ($record['arrival_date'] ?? '')),
    ];
}

function ross1000_build_prenotazione(array $record): array
{
    return [
        'idswh' => (string) ($record['ross_prenotazione_idswh'] ?? ''),
        'arrivo' => ross1000_format_date((string) ($record['arrival_date'] ?? '')),
        'partenza' => ross1000_format_date((string) ($record['departure_date'] ?? '')),
        'ospiti' => (string) ((int) ($record['expected_guests'] ?? 0)),
        'camere' => (string) ((int) ($record['reserved_rooms'] ?? 0)),
        'prezzo' => ross1000_normalize_decimal($record['daily_price'] ?? null),
        'canaleprenotazione' => (string) ($record['booking_channel'] ?? ''),
        'statoprovenienza' => (string) ($record['booking_provenience_state_code'] ?? ''),
        'comuneprovenienza' => (string) ($record['booking_provenience_place_code'] ?? ''),
    ];
}

function ross1000_build_intermediate_array(PDO $pdo, int $recordId): array
{
    list($record, $guests) = ross1000_fetch_record($pdo, $recordId);
    $config = ross1000_property_config();

    $errors = ross1000_validate_required_data($config, $record, $guests);
    if ($errors) {
        throw new RuntimeException(implode("\n", $errors));
    }

    $dates = ross1000_collect_relevant_dates($record);
    $movements = [];

    foreach ($dates as $date) {
        $isOpen = ross1000_is_open_on_date($config, $date);
        $movements[$date] = [
            'data' => ross1000_format_date($date),
            'struttura' => [
                'apertura' => $isOpen ? 'SI' : 'NO',
                'camereoccupate' => $isOpen ? (string) ross1000_compute_occupied_rooms($pdo, $date) : '0',
                'cameredisponibili' => $isOpen ? (string) ((int) ($config['camere_disponibili'] ?? 0)) : '0',
                'lettidisponibili' => $isOpen ? (string) ((int) ($config['letti_disponibili'] ?? 0)) : '0',
            ],
            'arrivi' => [],
            'partenze' => [],
            'prenotazioni' => [],
            'rettifiche' => [],
        ];
    }

    $bookingReceivedDate = substr((string) ($record['booking_received_date'] ?? ''), 0, 10);
    $arrivalDate = substr((string) ($record['arrival_date'] ?? ''), 0, 10);
    $departureDate = substr((string) ($record['departure_date'] ?? ''), 0, 10);

    if (isset($movements[$bookingReceivedDate])) {
        $movements[$bookingReceivedDate]['prenotazioni'][] = ross1000_build_prenotazione($record);
    }
    if (isset($movements[$arrivalDate])) {
        foreach ($guests as $guest) {
            $movements[$arrivalDate]['arrivi'][] = ross1000_build_guest_arrivo($guest, $record);
        }
    }
    if (isset($movements[$departureDate])) {
        foreach ($guests as $guest) {
            $movements[$departureDate]['partenze'][] = ross1000_build_guest_partenza($guest, $record);
        }
    }

    return [
        'codice' => (string) ($config['codice_struttura'] ?? ''),
        'prodotto' => (string) ($config['prodotto'] ?? (defined('ADMIN_APP_NAME') ? ADMIN_APP_NAME : 'Admin')),
        'record' => $record,
        'guests' => $guests,
        'movimenti' => array_values($movements),
    ];
}

function ross1000_xml_write_fields(XMLWriter $xml, array $data, array $orderedKeys): void
{
    foreach ($orderedKeys as $key) {
        $xml->writeElement($key, (string) ($data[$key] ?? ''));
    }
}

function ross1000_build_xml(array $payload): string
{
    $xml = new XMLWriter();
    $xml->openMemory();
    $xml->setIndent(true);
    $xml->setIndentString('  ');
    $xml->startDocument('1.0', 'UTF-8');

    $xml->startElement('movimenti');
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
