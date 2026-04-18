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

    return false;
}

function ross1000_day_status_table_ready(PDO $pdo): bool
{
    try {
        return (bool) $pdo->query("SHOW TABLES LIKE 'ross1000_day_status'")->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function ross1000_default_day_state(array $config, string $date): array
{
    $isOpen = ross1000_is_open_on_date($config, $date);

    return [
        'day_date' => $date,
        'is_open' => $isOpen ? 1 : 0,
        'available_rooms' => $isOpen ? (int) ($config['camere_disponibili'] ?? 0) : 0,
        'available_beds' => $isOpen ? (int) ($config['letti_disponibili'] ?? 0) : 0,
        'is_finalized' => 0,
        'finalized_at' => null,
        'exported_ross_at' => null,
        'exported_alloggiati_at' => null,
    ];
}

function ross1000_get_day_states_for_range(PDO $pdo, string $from, string $to, ?array $config = null): array
{
    $config = $config ?: ross1000_property_config();
    $states = [];

    $cursor = new DateTimeImmutable($from);
    $end = new DateTimeImmutable($to);

    while ($cursor <= $end) {
        $date = $cursor->format('Y-m-d');
        $states[$date] = ross1000_default_day_state($config, $date);
        $cursor = $cursor->modify('+1 day');
    }

    if (!ross1000_day_status_table_ready($pdo)) {
        return $states;
    }

    $stmt = $pdo->prepare('SELECT * FROM ross1000_day_status WHERE day_date BETWEEN :from AND :to ORDER BY day_date ASC');
    $stmt->execute(['from' => $from, 'to' => $to]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $date = (string) ($row['day_date'] ?? '');
        if ($date === '') {
            continue;
        }

        $base = $states[$date] ?? ross1000_default_day_state($config, $date);
        $states[$date] = array_merge($base, [
            'day_date' => $date,
            'is_open' => (int) ($row['is_open'] ?? $base['is_open']),
            'available_rooms' => $row['available_rooms'] !== null ? (int) $row['available_rooms'] : $base['available_rooms'],
            'available_beds' => $row['available_beds'] !== null ? (int) $row['available_beds'] : $base['available_beds'],
            'is_finalized' => (int) ($row['is_finalized'] ?? 0),
            'finalized_at' => $row['finalized_at'] ?? null,
            'exported_ross_at' => $row['exported_ross_at'] ?? null,
            'exported_alloggiati_at' => $row['exported_alloggiati_at'] ?? null,
        ]);
    }

    return $states;
}

function ross1000_get_day_state(PDO $pdo, string $date, ?array $config = null): array
{
    $states = ross1000_get_day_states_for_range($pdo, $date, $date, $config);
    return $states[$date] ?? ross1000_default_day_state($config ?: ross1000_property_config(), $date);
}

function ross1000_fetch_records_for_range(PDO $pdo, string $from, string $to): array
{
    $sql = "
        SELECT
            ar.*,
            leader.first_name AS leader_first_name,
            leader.last_name AS leader_last_name
        FROM anagrafica_records ar
        LEFT JOIN anagrafica_guests leader
            ON leader.record_id = ar.id
           AND leader.is_group_leader = 1
        WHERE
            (
                ar.arrival_date <= :to_date
                AND ar.departure_date >= :from_date
            )
            OR (
                ar.booking_received_date BETWEEN :from_date AND :to_date
            )
        ORDER BY ar.arrival_date ASC, ar.id ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'from_date' => $from,
        'to_date' => $to,
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function ross1000_fetch_guests_grouped(PDO $pdo, array $recordIds): array
{
    $recordIds = array_values(array_filter(array_map('intval', $recordIds)));
    if (!$recordIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($recordIds), '?'));
    $stmt = $pdo->prepare("SELECT * FROM anagrafica_guests WHERE record_id IN ($placeholders) ORDER BY is_group_leader DESC, id ASC");
    $stmt->execute($recordIds);

    $grouped = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $grouped[(int) $row['record_id']][] = $row;
    }

    return $grouped;
}

function ross1000_record_is_present_on_day(array $record, string $date): bool
{
    $arrival = substr((string) ($record['arrival_date'] ?? ''), 0, 10);
    $departure = substr((string) ($record['departure_date'] ?? ''), 0, 10);

    return $arrival !== '' && $departure !== '' && $arrival <= $date && $departure > $date;
}

function ross1000_record_arrives_on_day(array $record, string $date): bool
{
    return substr((string) ($record['arrival_date'] ?? ''), 0, 10) === $date;
}

function ross1000_record_departs_on_day(array $record, string $date): bool
{
    return substr((string) ($record['departure_date'] ?? ''), 0, 10) === $date;
}

function ross1000_record_booked_on_day(array $record, string $date): bool
{
    return substr((string) ($record['booking_received_date'] ?? ''), 0, 10) === $date;
}

function ross1000_record_label(array $record): string
{
    $name = trim((string) (($record['leader_first_name'] ?? '') . ' ' . ($record['leader_last_name'] ?? '')));
    if ($name !== '') {
        return $name;
    }
    return trim((string) ($record['booking_reference'] ?? ('Record #' . ($record['id'] ?? ''))));
}

function ross1000_build_day_snapshot(string $date, array $records, array $dayState, array $config): array
{
    $presentRecords = [];
    $arrivalRecords = [];
    $departureRecords = [];
    $bookingRecords = [];
    $touchingRecords = [];

    foreach ($records as $record) {
        $touchesDay = false;
        $flags = [
            'arrival' => ross1000_record_arrives_on_day($record, $date),
            'departure' => ross1000_record_departs_on_day($record, $date),
            'booking' => ross1000_record_booked_on_day($record, $date),
            'present' => ross1000_record_is_present_on_day($record, $date),
        ];

        foreach ($flags as $flagValue) {
            if ($flagValue) {
                $touchesDay = true;
                break;
            }
        }

        if (!$touchesDay) {
            continue;
        }

        if ($flags['present']) {
            $presentRecords[] = $record;
        }
        if ($flags['arrival']) {
            $arrivalRecords[] = $record;
        }
        if ($flags['departure']) {
            $departureRecords[] = $record;
        }
        if ($flags['booking']) {
            $bookingRecords[] = $record;
        }

        $touchingRecords[] = [
            'record' => $record,
            'flags' => $flags,
            'label' => ross1000_record_label($record),
        ];
    }

    $isOpen = (int) ($dayState['is_open'] ?? 1) === 1;
    $occupiedRooms = 0;
    $presentGuests = 0;
    if ($isOpen) {
        foreach ($presentRecords as $record) {
            $occupiedRooms += (int) ($record['reserved_rooms'] ?? 0);
            $presentGuests += (int) ($record['expected_guests'] ?? 0);
        }
    }

    $arrivalsGuests = 0;
    foreach ($arrivalRecords as $record) {
        $arrivalsGuests += (int) ($record['expected_guests'] ?? 0);
    }

    $departuresGuests = 0;
    foreach ($departureRecords as $record) {
        $departuresGuests += (int) ($record['expected_guests'] ?? 0);
    }

    $availableRooms = $isOpen ? (int) ($dayState['available_rooms'] ?? ($config['camere_disponibili'] ?? 0)) : 0;
    $availableBeds = $isOpen ? (int) ($dayState['available_beds'] ?? ($config['letti_disponibili'] ?? 0)) : 0;

    $warnings = [];
    if (!$isOpen && ($occupiedRooms > 0 || $arrivalsGuests > 0 || $departuresGuests > 0 || count($bookingRecords) > 0)) {
        $warnings[] = 'Il giorno è impostato come chiuso ma contiene movimenti o occupazione.';
    }
    if ($availableRooms > 0 && $occupiedRooms > $availableRooms) {
        $warnings[] = 'Le camere occupate superano le camere disponibili impostate per il giorno.';
    }

    return [
        'date' => $date,
        'day_state' => $dayState,
        'is_open' => $isOpen,
        'available_rooms' => $availableRooms,
        'available_beds' => $availableBeds,
        'occupied_rooms' => $occupiedRooms,
        'present_guests' => $presentGuests,
        'arrivals_guests' => $arrivalsGuests,
        'departures_guests' => $departuresGuests,
        'booking_records_count' => count($bookingRecords),
        'presence_records_count' => count($presentRecords),
        'arrival_records' => $arrivalRecords,
        'departure_records' => $departureRecords,
        'booking_records' => $bookingRecords,
        'present_records' => $presentRecords,
        'touching_records' => $touchingRecords,
        'warnings' => $warnings,
    ];
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
        'canaleprenotazione' => (string) $bookingChannel,
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

function ross1000_validate_day_export(array $snapshot, array $config, array $guestsGrouped): array
{
    $errors = [];

    if (!ross1000_property_config_ready($config)) {
        $errors[] = 'Configura prima admin/includes/ross1000-config.php con codice struttura, camere e letti disponibili.';
    }

    foreach ((array) ($snapshot['warnings'] ?? []) as $warning) {
        $errors[] = (string) $warning;
    }

    foreach ((array) ($snapshot['arrival_records'] ?? []) as $record) {
        $recordId = (int) ($record['id'] ?? 0);
        $guests = $guestsGrouped[$recordId] ?? [];
        if (!$guests) {
            $errors[] = 'Record #' . $recordId . ': nessun ospite associato per l\'arrivo del giorno.';
            continue;
        }

        foreach ($guests as $index => $guest) {
            $prefix = 'Arrivo ' . ross1000_record_label($record) . ' · ospite #' . ($index + 1);
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
    }

    foreach ((array) ($snapshot['departure_records'] ?? []) as $record) {
        $recordId = (int) ($record['id'] ?? 0);
        $guests = $guestsGrouped[$recordId] ?? [];
        if (!$guests) {
            $errors[] = 'Record #' . $recordId . ': nessun ospite associato per la partenza del giorno.';
        }
    }

    foreach ((array) ($snapshot['booking_records'] ?? []) as $record) {
        if (trim((string) ($record['ross_prenotazione_idswh'] ?? '')) === '') {
            $errors[] = ross1000_record_label($record) . ': manca l\'idswh della prenotazione.';
        }
        if (trim((string) ($record['arrival_date'] ?? '')) === '' || trim((string) ($record['departure_date'] ?? '')) === '') {
            $errors[] = ross1000_record_label($record) . ': mancano arrivo o partenza previsti.';
        }
    }

    return array_values(array_unique($errors));
}



function ross1000_upsert_day_state(PDO $pdo, string $date, array $state): void
{
    if (!ross1000_day_status_table_ready($pdo)) {
        throw new RuntimeException('Esegui prima la migration della tabella ross1000_day_status.');
    }

    $base = ross1000_default_day_state(ross1000_property_config(), $date);
    $state = array_merge($base, $state);

    $sql = "
        INSERT INTO ross1000_day_status
            (day_date, is_open, available_rooms, available_beds, is_finalized, finalized_at, exported_ross_at, exported_alloggiati_at, created_at, updated_at)
        VALUES
            (:day_date, :is_open, :available_rooms, :available_beds, :is_finalized, :finalized_at, :exported_ross_at, :exported_alloggiati_at, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            is_open = VALUES(is_open),
            available_rooms = VALUES(available_rooms),
            available_beds = VALUES(available_beds),
            is_finalized = VALUES(is_finalized),
            finalized_at = VALUES(finalized_at),
            exported_ross_at = VALUES(exported_ross_at),
            exported_alloggiati_at = VALUES(exported_alloggiati_at),
            updated_at = NOW()
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'day_date' => $date,
        'is_open' => !empty($state['is_open']) ? 1 : 0,
        'available_rooms' => (int) ($state['available_rooms'] ?? 0),
        'available_beds' => (int) ($state['available_beds'] ?? 0),
        'is_finalized' => (int) ($state['is_finalized'] ?? 0),
        'finalized_at' => $state['finalized_at'] ?? null,
        'exported_ross_at' => $state['exported_ross_at'] ?? null,
        'exported_alloggiati_at' => $state['exported_alloggiati_at'] ?? null,
    ]);
}

function ross1000_month_range(string $month): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        throw new RuntimeException('Mese non valido.');
    }

    $start = new DateTimeImmutable($month . '-01');
    $end = $start->modify('last day of this month');

    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}

function ross1000_prefill_open_month(PDO $pdo, string $month, ?array $config = null, bool $preserveFinalized = true): void
{
    if (!ross1000_day_status_table_ready($pdo)) {
        throw new RuntimeException('Esegui prima la migration della tabella ross1000_day_status.');
    }

    $config = $config ?: ross1000_property_config();
    [$from, $to] = ross1000_month_range($month);
    $states = ross1000_get_day_states_for_range($pdo, $from, $to, $config);

    $sql = "
        INSERT INTO ross1000_day_status
            (day_date, is_open, available_rooms, available_beds, is_finalized, finalized_at, exported_ross_at, exported_alloggiati_at, created_at, updated_at)
        VALUES
            (:day_date, :is_open, :available_rooms, :available_beds, :is_finalized, :finalized_at, :exported_ross_at, :exported_alloggiati_at, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            is_open = VALUES(is_open),
            available_rooms = VALUES(available_rooms),
            available_beds = VALUES(available_beds),
            is_finalized = VALUES(is_finalized),
            finalized_at = VALUES(finalized_at),
            exported_ross_at = VALUES(exported_ross_at),
            exported_alloggiati_at = VALUES(exported_alloggiati_at),
            updated_at = NOW()
    ";
    $stmt = $pdo->prepare($sql);

    $cursor = new DateTimeImmutable($from);
    $end = new DateTimeImmutable($to);
    while ($cursor <= $end) {
        $date = $cursor->format('Y-m-d');
        $state = $states[$date] ?? ross1000_default_day_state($config, $date);

        if ($preserveFinalized && (int) ($state['is_finalized'] ?? 0) === 1) {
            $cursor = $cursor->modify('+1 day');
            continue;
        }

        $stmt->execute([
            'day_date' => $date,
            'is_open' => 1,
            'available_rooms' => (int) ($config['camere_disponibili'] ?? 0),
            'available_beds' => (int) ($config['letti_disponibili'] ?? 0),
            'is_finalized' => (int) ($state['is_finalized'] ?? 0),
            'finalized_at' => $state['finalized_at'] ?? null,
            'exported_ross_at' => $state['exported_ross_at'] ?? null,
            'exported_alloggiati_at' => $state['exported_alloggiati_at'] ?? null,
        ]);

        $cursor = $cursor->modify('+1 day');
    }
}

function ross1000_build_movement_from_snapshot(string $date, array $snapshot, array $guestsGrouped): array
{
    $movement = [
        'data' => ross1000_format_date($date),
        'struttura' => [
            'apertura' => $snapshot['is_open'] ? 'SI' : 'NO',
            'camereoccupate' => $snapshot['is_open'] ? (string) $snapshot['occupied_rooms'] : '0',
            'cameredisponibili' => $snapshot['is_open'] ? (string) $snapshot['available_rooms'] : '0',
            'lettidisponibili' => $snapshot['is_open'] ? (string) $snapshot['available_beds'] : '0',
        ],
        'arrivi' => [],
        'partenze' => [],
        'prenotazioni' => [],
        'rettifiche' => [],
    ];

    foreach ((array) $snapshot['arrival_records'] as $record) {
        foreach (($guestsGrouped[(int) $record['id']] ?? []) as $guest) {
            $movement['arrivi'][] = ross1000_build_guest_arrivo($guest, $record);
        }
    }

    foreach ((array) $snapshot['departure_records'] as $record) {
        foreach (($guestsGrouped[(int) $record['id']] ?? []) as $guest) {
            $movement['partenze'][] = ross1000_build_guest_partenza($guest, $record);
        }
    }

    foreach ((array) $snapshot['booking_records'] as $record) {
        $movement['prenotazioni'][] = ross1000_build_prenotazione($record);
    }

    return $movement;
}

function ross1000_build_month_payload(PDO $pdo, string $month): array
{
    $config = ross1000_property_config();
    [$from, $to] = ross1000_month_range($month);
    $records = ross1000_fetch_records_for_range($pdo, $from, $to);
    $dayStates = ross1000_get_day_states_for_range($pdo, $from, $to, $config);
    $recordIds = array_map(static function (array $row): int {
        return (int) ($row['id'] ?? 0);
    }, $records);
    $guestsGrouped = ross1000_fetch_guests_grouped($pdo, $recordIds);

    $movements = [];
    $errors = [];
    $cursor = new DateTimeImmutable($from);
    $end = new DateTimeImmutable($to);
    while ($cursor <= $end) {
        $date = $cursor->format('Y-m-d');
        $snapshot = ross1000_build_day_snapshot($date, $records, $dayStates[$date] ?? ross1000_default_day_state($config, $date), $config);
        foreach (ross1000_validate_day_export($snapshot, $config, $guestsGrouped) as $err) {
            $errors[] = $date . ': ' . $err;
        }
        $movements[] = ross1000_build_movement_from_snapshot($date, $snapshot, $guestsGrouped);
        $cursor = $cursor->modify('+1 day');
    }

    $errors = array_values(array_unique($errors));
    if ($errors) {
        throw new RuntimeException(implode("
", $errors));
    }

    return [
        'codice' => (string) ($config['codice_struttura'] ?? ''),
        'prodotto' => (string) ($config['prodotto'] ?? (defined('ADMIN_APP_NAME') ? ADMIN_APP_NAME : 'Admin')),
        'month' => $month,
        'movimenti' => $movements,
    ];
}

function ross1000_build_day_payload(PDO $pdo, string $date): array
{
    $config = ross1000_property_config();
    $dayState = ross1000_get_day_state($pdo, $date, $config);
    $records = ross1000_fetch_records_for_range($pdo, $date, $date);
    $snapshot = ross1000_build_day_snapshot($date, $records, $dayState, $config);

    $recordIds = array_map(static function (array $row): int {
        return (int) ($row['id'] ?? 0);
    }, $records);
    $guestsGrouped = ross1000_fetch_guests_grouped($pdo, $recordIds);

    $errors = ross1000_validate_day_export($snapshot, $config, $guestsGrouped);
    if ($errors) {
        throw new RuntimeException(implode("\n", $errors));
    }

    $movement = [
        'data' => ross1000_format_date($date),
        'struttura' => [
            'apertura' => $snapshot['is_open'] ? 'SI' : 'NO',
            'camereoccupate' => $snapshot['is_open'] ? (string) $snapshot['occupied_rooms'] : '0',
            'cameredisponibili' => $snapshot['is_open'] ? (string) $snapshot['available_rooms'] : '0',
            'lettidisponibili' => $snapshot['is_open'] ? (string) $snapshot['available_beds'] : '0',
        ],
        'arrivi' => [],
        'partenze' => [],
        'prenotazioni' => [],
        'rettifiche' => [],
    ];

    foreach ((array) $snapshot['arrival_records'] as $record) {
        foreach (($guestsGrouped[(int) $record['id']] ?? []) as $guest) {
            $movement['arrivi'][] = ross1000_build_guest_arrivo($guest, $record);
        }
    }

    foreach ((array) $snapshot['departure_records'] as $record) {
        foreach (($guestsGrouped[(int) $record['id']] ?? []) as $guest) {
            $movement['partenze'][] = ross1000_build_guest_partenza($guest, $record);
        }
    }

    foreach ((array) $snapshot['booking_records'] as $record) {
        $movement['prenotazioni'][] = ross1000_build_prenotazione($record);
    }

    return [
        'codice' => (string) ($config['codice_struttura'] ?? ''),
        'prodotto' => (string) ($config['prodotto'] ?? (defined('ADMIN_APP_NAME') ? ADMIN_APP_NAME : 'Admin')),
        'day' => $date,
        'snapshot' => $snapshot,
        'movimenti' => [$movement],
    ];
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
