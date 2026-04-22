<?php

declare(strict_types=1);

require_once __DIR__ . '/anagrafica-options.php';
require_once __DIR__ . '/alloggiati.php';



if (!function_exists('derive_guest_idswh')) {
    function derive_guest_idswh(string $bookingIdswh, int $index): string
    {
        $suffix = str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
        $base = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $bookingIdswh) ?? '');
        $base = substr($base, 0, 18);
        return str_pad($base . $suffix, 20, '0', STR_PAD_RIGHT);
    }
}

if (!function_exists('derive_booking_idswh')) {
    function derive_booking_idswh(string $recordType, string $bookingReference): string
    {
        $seed = $bookingReference !== '' ? $bookingReference : ($recordType . '-' . date('YmdHis'));
        $seed = preg_replace('/[^A-Za-z0-9]/', '', strtoupper($seed));
        if ($seed === null || $seed === '') {
            $seed = 'ANA' . date('YmdHis');
        }
        return substr($seed, 0, 20);
    }
}

function anagrafica_sync_issue_message(array $booking, ?Throwable $error = null): string
{
    $checkIn = substr((string) ($booking['check_in'] ?? ''), 0, 10);
    $checkOut = substr((string) ($booking['check_out'] ?? ''), 0, 10);
    if ($checkIn === '' || $checkOut === '') {
        return 'Mancano le date di check-in o check-out della prenotazione.';
    }

    if (trim((string) ($booking['customer_name'] ?? '')) === '') {
        return 'Manca il nominativo principale della prenotazione.';
    }

    if ($error instanceof Throwable) {
        return 'Questa prenotazione richiede un controllo dei dati anagrafici prima della sincronizzazione.';
    }

    return 'La prenotazione richiede un controllo dei dati prima della sincronizzazione.';
}

function anagrafica_sync_prenotazioni_range_safe(PDO $pdo, string $from, string $to): array
{
    if (!anagrafica_prenotazione_link_column_ready($pdo)) {
        return ['record_ids' => [], 'issues' => []];
    }

    $stmt = $pdo->prepare('SELECT * FROM prenotazioni WHERE check_in <= :to_date AND check_out >= :from_date ORDER BY check_in ASC, id ASC');
    $stmt->execute(['from_date' => $from, 'to_date' => $to]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $recordIds = [];
    $issues = [];

    foreach ($rows as $booking) {
        $bookingId = (int) ($booking['id'] ?? 0);
        if ($bookingId <= 0) {
            continue;
        }
        try {
            $recordIds[$bookingId] = anagrafica_sync_booking_to_record($pdo, $booking);
        } catch (Throwable $e) {
            $issues[$bookingId] = [
                'booking_id' => $bookingId,
                'customer_name' => trim((string) ($booking['customer_name'] ?? '')) !== '' ? (string) $booking['customer_name'] : ('Prenotazione #' . $bookingId),
                'check_in' => substr((string) ($booking['check_in'] ?? ''), 0, 10),
                'check_out' => substr((string) ($booking['check_out'] ?? ''), 0, 10),
                'message' => anagrafica_sync_issue_message($booking, $e),
            ];
        }
    }

    return ['record_ids' => $recordIds, 'issues' => array_values($issues)];
}

function anagrafica_prenotazione_link_column_ready(PDO $pdo): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM anagrafica_records LIKE 'prenotazione_id'");
        $cache = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $cache = false;
    }
    return $cache;
}

function anagrafica_booking_total_guests(array $booking): int
{
    return max(1, (int) ($booking['adults'] ?? 0) + (int) ($booking['children_count'] ?? 0));
}

function anagrafica_booking_record_type(array $booking): string
{
    return anagrafica_booking_total_guests($booking) > 1 ? 'group' : 'single';
}

function anagrafica_booking_reference(array $booking): string
{
    $external = trim((string) ($booking['external_reference'] ?? ''));
    if ($external !== '') {
        return $external;
    }
    return 'PREN-' . (int) ($booking['id'] ?? 0);
}

function anagrafica_booking_idswh(array $booking): string
{
    return substr('PREN' . str_pad((string) ((int) ($booking['id'] ?? 0)), 8, '0', STR_PAD_LEFT), 0, 20);
}

function anagrafica_split_full_name(string $value): array
{
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    if ($value === '') {
        return ['', ''];
    }
    $parts = explode(' ', $value);
    if (count($parts) === 1) {
        return [$parts[0], $parts[0]];
    }
    $first = array_shift($parts);
    $last = implode(' ', $parts);
    return [trim((string) $first), trim((string) $last)];
}

function anagrafica_booking_received_date(array $booking): string
{
    foreach (['imported_at', 'created_at', 'updated_at', 'check_in'] as $field) {
        $value = substr((string) ($booking[$field] ?? ''), 0, 10);
        if ($value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }
    }
    return date('Y-m-d');
}

function anagrafica_booking_stay_period(?string $checkIn, ?string $checkOut): string
{
    $in = trim((string) $checkIn);
    $out = trim((string) $checkOut);
    $fmt = static function (string $date): string {
        $ts = strtotime($date);
        return $ts ? date('d/m/Y', $ts) : $date;
    };
    if ($in === '' || $out === '') {
        return trim($fmt($in) . ' al ' . $fmt($out));
    }
    return $fmt($in) . ' al ' . $fmt($out);
}

function anagrafica_find_state_by_iso2(?string $iso2): ?array
{
    $iso2 = strtoupper(trim((string) $iso2));
    if ($iso2 === '') {
        return null;
    }
    $eu = anagrafica_eu_citizenships();
    if (isset($eu[$iso2])) {
        return anagrafica_find_state_by_value($eu[$iso2]);
    }
    if ($iso2 === 'IT') {
        return anagrafica_find_state_by_value('ITALIA');
    }
    return null;
}

function anagrafica_booking_room_type(array $booking): string
{
    $roomType = trim((string) ($booking['room_type'] ?? ''));
    return $roomType !== '' ? $roomType : 'Da definire';
}


function anagrafica_infer_record_type_from_guest_rows(array $guests, string $fallback = 'single'): string
{
    $codes = [];
    foreach ($guests as $guest) {
        $code = trim((string) ($guest['tipoalloggiato_code'] ?? $guest['tipo_alloggiato_code'] ?? ''));
        if ($code !== '') {
            $codes[] = $code;
        }
    }

    foreach ($codes as $code) {
        if ($code === '17' || $code === '19') {
            return 'family';
        }
    }
    foreach ($codes as $code) {
        if ($code === '18' || $code === '20') {
            return 'group';
        }
    }
    foreach ($codes as $code) {
        if ($code === '16') {
            return 'single';
        }
    }

    return $fallback;
}

function anagrafica_guest_modal_payload_row(array $guest): array
{
    $italyCode = anagrafica_default_italy_state_code();
    $birthStateCode = (string) ($guest['birth_state_code'] ?? '');
    $residenceStateCode = (string) ($guest['residence_state_code'] ?? '');

    $birthPlaceValue = (string) ($guest['birth_city_code'] ?? $guest['birth_place_code'] ?? '');
    if ($birthPlaceValue === '') {
        $birthPlaceValue = (string) ($guest['birth_place'] ?? $guest['birth_place_label'] ?? '');
    }

    $residencePlaceValue = '';
    if ($residenceStateCode === $italyCode) {
        $residencePlaceValue = (string) ($guest['residence_place_code'] ?? $guest['residence_place'] ?? '');
    } else {
        $residencePlaceValue = (string) ($guest['residence_place'] ?? $guest['residence_place_label'] ?? $guest['residence_place_code'] ?? '');
    }

    return [
        'first_name' => (string) ($guest['first_name'] ?? ''),
        'last_name' => (string) ($guest['last_name'] ?? ''),
        'gender' => (string) ($guest['gender'] ?? 'M'),
        'birth_date' => substr((string) ($guest['birth_date'] ?? ''), 0, 10),
        'citizenship_label' => (string) ($guest['citizenship_code'] ?? ''),
        'birth_state_label' => $birthStateCode,
        'birth_province' => (string) ($guest['birth_province'] ?? ''),
        'birth_place_label' => $birthPlaceValue,
        'residence_state_label' => $residenceStateCode,
        'residence_province' => (string) ($guest['residence_province'] ?? ''),
        'residence_place_label' => $residencePlaceValue,
        'document_type_label' => (string) ($guest['document_type_label'] ?? $guest['document_type'] ?? ''),
        'document_number' => (string) ($guest['document_number'] ?? ''),
        'document_issue_place' => (string) ($guest['document_issue_place_code'] ?? $guest['document_issue_place'] ?? ''),
        'tourism_type' => (string) ($guest['tourism_type'] ?? ''),
        'transport_type' => (string) ($guest['transport_type'] ?? ''),
        'tipoalloggiato_code' => (string) ($guest['tipoalloggiato_code'] ?? $guest['tipo_alloggiato_code'] ?? ''),
    ];
}

function anagrafica_sync_booking_to_record(PDO $pdo, array $booking): int
{
    if (!anagrafica_prenotazione_link_column_ready($pdo)) {
        throw new RuntimeException('Esegui la migration della colonna prenotazione_id sulle anagrafiche.');
    }

    $bookingId = (int) ($booking['id'] ?? 0);
    if ($bookingId <= 0) {
        throw new InvalidArgumentException('Prenotazione non valida per la sincronizzazione.');
    }

    $stmt = $pdo->prepare('SELECT * FROM anagrafica_records WHERE prenotazione_id = :prenotazione_id LIMIT 1');
    $stmt->execute(['prenotazione_id' => $bookingId]);
    $existingRecord = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $existingGuestsForType = $existingRecord ? anagrafica_fetch_record_guests($pdo, (int) ($existingRecord['id'] ?? 0)) : [];
    $recordTypeFallback = $existingRecord ? (string) ($existingRecord['record_type'] ?? '') : '';
    if ($recordTypeFallback === '') {
        $recordTypeFallback = anagrafica_booking_record_type($booking);
    }
    $recordType = anagrafica_infer_record_type_from_guest_rows($existingGuestsForType, $recordTypeFallback);
    $bookingReference = anagrafica_booking_reference($booking);
    $bookingIdswh = $existingRecord['ross_prenotazione_idswh'] ?? anagrafica_booking_idswh($booking);
    $recordData = [
        'record_type' => $recordType,
        'booking_reference' => $bookingReference,
        'booking_received_date' => anagrafica_booking_received_date($booking),
        'arrival_date' => substr((string) ($booking['check_in'] ?? ''), 0, 10),
        'departure_date' => substr((string) ($booking['check_out'] ?? ''), 0, 10),
        'expected_guests' => anagrafica_booking_total_guests($booking),
        'reserved_rooms' => max(1, (int) ($existingRecord['reserved_rooms'] ?? 1)),
        'status' => (string) ($booking['status'] ?? 'draft'),
    ];

    if ($existingRecord) {
        $update = $pdo->prepare('UPDATE anagrafica_records SET record_type = :record_type, booking_reference = :booking_reference, booking_received_date = :booking_received_date, arrival_date = :arrival_date, departure_date = :departure_date, expected_guests = :expected_guests, reserved_rooms = :reserved_rooms, status = :status, updated_at = NOW() WHERE id = :id');
        $update->execute([
            'record_type' => $recordData['record_type'],
            'booking_reference' => $recordData['booking_reference'],
            'booking_received_date' => $recordData['booking_received_date'],
            'arrival_date' => $recordData['arrival_date'],
            'departure_date' => $recordData['departure_date'],
            'expected_guests' => $recordData['expected_guests'],
            'reserved_rooms' => $recordData['reserved_rooms'],
            'status' => $recordData['status'],
            'id' => (int) $existingRecord['id'],
        ]);
        $recordId = (int) $existingRecord['id'];
    } else {
        $insert = $pdo->prepare('INSERT INTO anagrafica_records (uuid, prenotazione_id, record_type, booking_reference, ross_prenotazione_idswh, booking_received_date, arrival_date, departure_date, expected_guests, reserved_rooms, booking_channel, daily_price, booking_provenience_state_label, booking_provenience_state_code, booking_provenience_province, booking_provenience_place_label, booking_provenience_place_code, status, created_at, updated_at) VALUES (:uuid, :prenotazione_id, :record_type, :booking_reference, :ross_prenotazione_idswh, :booking_received_date, :arrival_date, :departure_date, :expected_guests, :reserved_rooms, NULL, NULL, NULL, NULL, NULL, NULL, NULL, :status, NOW(), NOW())');
        $insert->execute([
            'uuid' => bin2hex(random_bytes(16)),
            'prenotazione_id' => $bookingId,
            'record_type' => $recordType,
            'booking_reference' => $bookingReference,
            'ross_prenotazione_idswh' => $bookingIdswh,
            'booking_received_date' => $recordData['booking_received_date'],
            'arrival_date' => $recordData['arrival_date'],
            'departure_date' => $recordData['departure_date'],
            'expected_guests' => $recordData['expected_guests'],
            'reserved_rooms' => $recordData['reserved_rooms'],
            'status' => $recordData['status'],
        ]);
        $recordId = (int) $pdo->lastInsertId();
    }

    $guestStmt = $pdo->prepare('SELECT * FROM anagrafica_guests WHERE record_id = :record_id AND is_group_leader = 1 LIMIT 1');
    $guestStmt->execute(['record_id' => $recordId]);
    $leader = $guestStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    [$firstName, $lastName] = anagrafica_split_full_name((string) ($booking['customer_name'] ?? ''));
    $state = anagrafica_find_state_by_iso2((string) ($booking['guest_country_code'] ?? ''));
    $citizenshipLabel = (string) ($leader['citizenship_label'] ?? ($state['description'] ?? ''));
    $citizenshipCode = (string) ($leader['citizenship_code'] ?? ($state['code'] ?? ''));

    $guestPayload = [
        'record_id' => $recordId,
        'guest_idswh' => $leader['guest_idswh'] ?? derive_guest_idswh($bookingIdswh, 0),
        'is_group_leader' => 1,
        'leader_idswh' => '',
        'tipo_alloggiato_code' => anagrafica_tipo_alloggiato_code_for_record_type($recordType, true),
        'tipoalloggiato_code' => anagrafica_tipo_alloggiato_code_for_record_type($recordType, true),
        'first_name' => trim((string) ($leader['first_name'] ?? $firstName)),
        'last_name' => trim((string) ($leader['last_name'] ?? $lastName)),
        'gender' => trim((string) ($leader['gender'] ?? 'M')),
        'birth_date' => $leader['birth_date'] ?? null,
        'birth_state_code' => $leader['birth_state_code'] ?? null,
        'birth_province' => $leader['birth_province'] ?? null,
        'birth_place_label' => $leader['birth_place_label'] ?? null,
        'birth_place' => $leader['birth_place'] ?? null,
        'birth_place_code' => $leader['birth_place_code'] ?? null,
        'birth_city_code' => $leader['birth_city_code'] ?? null,
        'citizenship_label' => $citizenshipLabel !== '' ? $citizenshipLabel : null,
        'citizenship_code' => $citizenshipCode !== '' ? $citizenshipCode : null,
        'residence_state_label' => $leader['residence_state_label'] ?? ($state['description'] ?? null),
        'residence_province' => $leader['residence_province'] ?? null,
        'residence_place' => $leader['residence_place'] ?? null,
        'residence_state_code' => $leader['residence_state_code'] ?? ($state['code'] ?? null),
        'residence_place_label' => $leader['residence_place_label'] ?? null,
        'residence_place_code' => $leader['residence_place_code'] ?? null,
        'birth_state_label' => $leader['birth_state_label'] ?? null,
        'document_type' => $leader['document_type'] ?? null,
        'document_type_label' => $leader['document_type_label'] ?? null,
        'document_type_code' => $leader['document_type_code'] ?? null,
        'document_number' => $leader['document_number'] ?? null,
        'document_issue_date' => $leader['document_issue_date'] ?? null,
        'document_expiry_date' => $leader['document_expiry_date'] ?? null,
        'document_issue_province' => $leader['document_issue_province'] ?? null,
        'document_issue_place' => $leader['document_issue_place'] ?? null,
        'document_issue_place_code' => $leader['document_issue_place_code'] ?? null,
        'email' => $booking['customer_email'] ?? ($leader['email'] ?? null),
        'phone' => $booking['customer_phone'] ?? ($leader['phone'] ?? null),
        'tourism_type' => $leader['tourism_type'] ?? null,
        'transport_type' => $leader['transport_type'] ?? null,
        'guest_booking_channel' => $leader['guest_booking_channel'] ?? null,
        'education_level' => $leader['education_level'] ?? null,
        'profession' => $leader['profession'] ?? null,
        'tax_exemption_code' => $leader['tax_exemption_code'] ?? null,
    ];

    if ($leader) {
        $sql = 'UPDATE anagrafica_guests SET guest_idswh = :guest_idswh, tipo_alloggiato_code = :tipo_alloggiato_code, tipoalloggiato_code = :tipoalloggiato_code, first_name = :first_name, last_name = :last_name, email = :email, phone = :phone, citizenship_label = :citizenship_label, citizenship_code = :citizenship_code, residence_state_label = :residence_state_label, residence_state_code = :residence_state_code, updated_at = NOW() WHERE id = :id';
        $pdo->prepare($sql)->execute([
            'guest_idswh' => $guestPayload['guest_idswh'],
            'tipo_alloggiato_code' => $guestPayload['tipo_alloggiato_code'],
            'tipoalloggiato_code' => $guestPayload['tipoalloggiato_code'],
            'first_name' => $guestPayload['first_name'],
            'last_name' => $guestPayload['last_name'],
            'email' => $guestPayload['email'],
            'phone' => $guestPayload['phone'],
            'citizenship_label' => $guestPayload['citizenship_label'],
            'citizenship_code' => $guestPayload['citizenship_code'],
            'residence_state_label' => $guestPayload['residence_state_label'],
            'residence_state_code' => $guestPayload['residence_state_code'],
            'id' => (int) $leader['id'],
        ]);
    } else {
        $sql = 'INSERT INTO anagrafica_guests (record_id, guest_idswh, is_group_leader, leader_idswh, tipo_alloggiato_code, tipoalloggiato_code, first_name, last_name, gender, birth_date, birth_state_code, birth_province, birth_place_label, birth_place, birth_place_code, birth_city_code, citizenship_label, citizenship_code, residence_state_label, residence_province, residence_place, residence_state_code, residence_place_label, residence_place_code, birth_state_label, document_type, document_type_label, document_type_code, document_number, document_issue_date, document_expiry_date, document_issue_province, document_issue_place, document_issue_place_code, email, phone, tourism_type, transport_type, guest_booking_channel, education_level, profession, tax_exemption_code, created_at, updated_at) VALUES (:record_id, :guest_idswh, 1, :leader_idswh, :tipo_alloggiato_code, :tipoalloggiato_code, :first_name, :last_name, :gender, :birth_date, :birth_state_code, :birth_province, :birth_place_label, :birth_place, :birth_place_code, :birth_city_code, :citizenship_label, :citizenship_code, :residence_state_label, :residence_province, :residence_place, :residence_state_code, :residence_place_label, :residence_place_code, :birth_state_label, :document_type, :document_type_label, :document_type_code, :document_number, :document_issue_date, :document_expiry_date, :document_issue_province, :document_issue_place, :document_issue_place_code, :email, :phone, :tourism_type, :transport_type, :guest_booking_channel, :education_level, :profession, :tax_exemption_code, NOW(), NOW())';
        $pdo->prepare($sql)->execute($guestPayload);
    }

    if (alloggiati_schedine_table_ready($pdo)) {
        alloggiati_sync_record($pdo, $recordId);
    }

    return $recordId;
}

function anagrafica_sync_prenotazioni_range(PDO $pdo, string $from, string $to): array
{
    if (!anagrafica_prenotazione_link_column_ready($pdo)) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT * FROM prenotazioni WHERE check_in <= :to_date AND check_out >= :from_date ORDER BY check_in ASC, id ASC');
    $stmt->execute(['from_date' => $from, 'to_date' => $to]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $recordIds = [];
    foreach ($rows as $booking) {
        $recordIds[(int) ($booking['id'] ?? 0)] = anagrafica_sync_booking_to_record($pdo, $booking);
    }
    return $recordIds;
}


function anagrafica_fetch_record_guests(PDO $pdo, int $recordId): array
{
    if ($recordId <= 0) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT * FROM anagrafica_guests WHERE record_id = :record_id ORDER BY is_group_leader DESC, id ASC');
    $stmt->execute(['record_id' => $recordId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function anagrafica_build_record_modal_payload(PDO $pdo, array $record): array
{
    $recordId = (int) ($record['id'] ?? 0);
    $bookingReference = (string) ($record['booking_reference'] ?? '');
    $bookingReceived = (string) ($record['booking_received_date'] ?? date('Y-m-d'));
    $arrival = substr((string) ($record['arrival_date'] ?? ''), 0, 10);
    $departure = substr((string) ($record['departure_date'] ?? ''), 0, 10);
    $reservedRooms = max(1, (int) ($record['reserved_rooms'] ?? 1));

    $guestRows = anagrafica_fetch_record_guests($pdo, $recordId);
    $recordType = anagrafica_infer_record_type_from_guest_rows($guestRows, (string) ($record['record_type'] ?? 'single'));
    $guests = [];
    foreach ($guestRows as $guest) {
        $guests[] = anagrafica_guest_modal_payload_row($guest);
    }
    if (!$guests) {
        $guests[] = [];
    }

    return [
        'prenotazione_id' => (int) ($record['prenotazione_id'] ?? 0),
        'linked_record_id' => $recordId,
        'record_type' => $recordType,
        'booking_reference' => $bookingReference,
        'booking_received_date' => $bookingReceived,
        'arrival_date' => $arrival,
        'departure_date' => $departure,
        'reserved_rooms' => $reservedRooms,
        'guests' => $guests,
    ];
}

function anagrafica_fetch_standalone_records_touching_day(PDO $pdo, string $day): array
{
    $sql = "
        SELECT ar.*, leader.first_name AS leader_first_name,
               leader.last_name AS leader_last_name,
               leader.document_type_label AS leader_document_type_label,
               leader.document_number AS leader_document_number,
               leader.document_issue_place AS leader_document_issue_place,
               leader.birth_date AS leader_birth_date,
               leader.gender AS leader_gender,
               leader.citizenship_label AS leader_citizenship_label,
               leader.birth_state_label AS leader_birth_state_label,
               leader.birth_province AS leader_birth_province,
               leader.birth_place_label AS leader_birth_place_label,
               leader.residence_state_label AS leader_residence_state_label,
               leader.residence_province AS leader_residence_province,
               leader.residence_place_label AS leader_residence_place_label
        FROM anagrafica_records ar
        LEFT JOIN anagrafica_guests leader ON leader.record_id = ar.id AND leader.is_group_leader = 1
        WHERE (ar.prenotazione_id IS NULL OR ar.prenotazione_id = 0)
          AND ar.arrival_date <= :day AND ar.departure_date >= :day
        ORDER BY ar.arrival_date ASC, ar.id ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['day' => $day]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $arrival = substr((string) ($row['arrival_date'] ?? ''), 0, 10);
        $departure = substr((string) ($row['departure_date'] ?? ''), 0, 10);
        $customerName = trim((string) (($row['leader_first_name'] ?? '') . ' ' . ($row['leader_last_name'] ?? '')));
        $totalGuests = max(1, (int) ($row['expected_guests'] ?? 1));
        $recordRowId = (int) ($row['id'] ?? 0);
        $row['booking_id'] = 0;
        $row['row_source'] = 'manual_record';
        $row['customer_name'] = $customerName !== '' ? $customerName : ('Anagrafica #' . $recordRowId);
        $row['check_in'] = $arrival;
        $row['check_out'] = $departure;
        $row['adults'] = $totalGuests;
        $row['children_count'] = 0;
        $row['room_type'] = 'Anagrafica manuale';
        $row['status'] = (string) ($row['status'] ?? 'draft');
        $row['linked_record_id'] = $recordRowId;
        $row['id'] = 0;
        $row['flags'] = [
            'arrival' => $arrival === $day,
            'departure' => $departure === $day,
            'present' => $arrival !== '' && $departure !== '' && $arrival <= $day && $departure > $day,
        ];
        $row['total_guests'] = $totalGuests;
        $row['document_ready'] = trim((string) ($row['leader_document_number'] ?? '')) !== '';
        $row['modal_payload'] = anagrafica_build_record_modal_payload($pdo, $row);
    }
    unset($row);
    return $rows;
}

function anagrafica_fetch_day_entries(PDO $pdo, string $day): array
{
    $entries = anagrafica_fetch_prenotazioni_touching_day($pdo, $day);
    foreach (anagrafica_fetch_standalone_records_touching_day($pdo, $day) as $row) {
        $entries[] = $row;
    }
    usort($entries, static function (array $a, array $b): int {
        $aDate = substr((string) ($a['check_in'] ?? $a['arrival_date'] ?? ''), 0, 10);
        $bDate = substr((string) ($b['check_in'] ?? $b['arrival_date'] ?? ''), 0, 10);
        $cmp = strcmp($aDate, $bDate);
        if ($cmp !== 0) {
            return $cmp;
        }
        return ((int) ($a['linked_record_id'] ?? $a['id'] ?? 0)) <=> ((int) ($b['linked_record_id'] ?? $b['id'] ?? 0));
    });
    return $entries;
}

function anagrafica_build_booking_modal_payload(PDO $pdo, array $booking): array
{
    $bookingId = (int) ($booking['id'] ?? 0);
    $linkedRecordId = (int) ($booking['linked_record_id'] ?? 0);
    $recordTypeFallback = (string) ($booking['record_type'] ?? (anagrafica_booking_total_guests($booking) > 1 ? 'group' : 'single'));
    $bookingReference = (string) ($booking['booking_reference'] ?? anagrafica_booking_reference($booking));
    $bookingReceived = (string) ($booking['booking_received_date'] ?? anagrafica_booking_received_date($booking));
    $arrival = substr((string) ($booking['arrival_date'] ?? $booking['check_in'] ?? ''), 0, 10);
    $departure = substr((string) ($booking['departure_date'] ?? $booking['check_out'] ?? ''), 0, 10);
    $reservedRooms = max(1, (int) ($booking['reserved_rooms'] ?? 1));

    $guestRows = $linkedRecordId > 0 ? anagrafica_fetch_record_guests($pdo, $linkedRecordId) : [];
    $recordType = anagrafica_infer_record_type_from_guest_rows($guestRows, $recordTypeFallback);
    $guests = [];
    if ($guestRows) {
        foreach ($guestRows as $guest) {
            $guests[] = anagrafica_guest_modal_payload_row($guest);
        }
    }

    if (!$guests) {
        $leaderName = trim((string) ($booking['customer_name'] ?? ''));
        $firstName = '';
        $lastName = '';
        if ($leaderName !== '') {
            [$firstName, $lastName] = anagrafica_split_full_name($leaderName);
        }
        $guests[] = [
            'first_name' => (string) ($booking['leader_first_name'] ?? $firstName),
            'last_name' => (string) ($booking['leader_last_name'] ?? $lastName),
            'gender' => (string) ($booking['leader_gender'] ?? 'M'),
            'birth_date' => substr((string) ($booking['leader_birth_date'] ?? ''), 0, 10),
            'citizenship_label' => (string) ($booking['leader_citizenship_label'] ?? ''),
            'birth_state_label' => (string) ($booking['leader_birth_state_label'] ?? ''),
            'birth_province' => (string) ($booking['leader_birth_province'] ?? ''),
            'birth_place_label' => (string) ($booking['leader_birth_place_label'] ?? ''),
            'residence_state_label' => (string) ($booking['leader_residence_state_label'] ?? ''),
            'residence_province' => (string) ($booking['leader_residence_province'] ?? ''),
            'residence_place_label' => (string) ($booking['leader_residence_place_label'] ?? ''),
            'document_type_label' => (string) ($booking['leader_document_type_label'] ?? ''),
            'document_number' => (string) ($booking['leader_document_number'] ?? ''),
            'document_issue_place' => (string) ($booking['leader_document_issue_place'] ?? ''),
            'tourism_type' => '',
            'transport_type' => '',
        ];
        $totalGuests = max(1, anagrafica_booking_total_guests($booking));
        for ($i = 1; $i < $totalGuests; $i += 1) {
            $guests[] = [
                'first_name' => '',
                'last_name' => '',
                'gender' => 'M',
                'birth_date' => '',
                'citizenship_label' => '',
                'birth_state_label' => '',
                'birth_province' => '',
                'birth_place_label' => '',
                'residence_state_label' => '',
                'residence_province' => '',
                'residence_place_label' => '',
                'tourism_type' => '',
                'transport_type' => '',
            ];
        }
    }

    return [
        'prenotazione_id' => $bookingId,
        'linked_record_id' => $linkedRecordId,
        'record_type' => $recordType,
        'booking_reference' => $bookingReference,
        'booking_received_date' => $bookingReceived,
        'arrival_date' => $arrival,
        'departure_date' => $departure,
        'reserved_rooms' => $reservedRooms,
        'guests' => $guests,
    ];
}

function anagrafica_fetch_prenotazioni_touching_day(PDO $pdo, string $day): array
{
    $sql = "
        SELECT p.*, ar.id AS linked_record_id,
               ar.record_type AS record_type,
               ar.booking_reference AS booking_reference,
               ar.booking_received_date AS booking_received_date,
               ar.arrival_date AS arrival_date,
               ar.departure_date AS departure_date,
               ar.reserved_rooms AS reserved_rooms,
               leader.first_name AS leader_first_name,
               leader.last_name AS leader_last_name,
               leader.document_type_label AS leader_document_type_label,
               leader.document_number AS leader_document_number,
               leader.document_issue_place AS leader_document_issue_place,
               leader.document_issue_place_code AS leader_document_issue_place_code,
               leader.birth_date AS leader_birth_date,
               leader.gender AS leader_gender,
               leader.citizenship_label AS leader_citizenship_label,
               leader.citizenship_code AS leader_citizenship_code,
               leader.birth_state_label AS leader_birth_state_label,
               leader.birth_state_code AS leader_birth_state_code,
               leader.birth_province AS leader_birth_province,
               leader.birth_place_label AS leader_birth_place_label,
               leader.residence_state_label AS leader_residence_state_label,
               leader.residence_state_code AS leader_residence_state_code,
               leader.residence_province AS leader_residence_province,
               leader.residence_place_label AS leader_residence_place_label
        FROM prenotazioni p
        LEFT JOIN anagrafica_records ar ON ar.prenotazione_id = p.id
        LEFT JOIN anagrafica_guests leader ON leader.record_id = ar.id AND leader.is_group_leader = 1
        WHERE p.check_in <= :day AND p.check_out >= :day
        ORDER BY p.check_in ASC, p.id ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['day' => $day]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $checkIn = substr((string) ($row['check_in'] ?? ''), 0, 10);
        $checkOut = substr((string) ($row['check_out'] ?? ''), 0, 10);
        $row['flags'] = [
            'arrival' => $checkIn === $day,
            'departure' => $checkOut === $day,
            'present' => $checkIn !== '' && $checkOut !== '' && $checkIn <= $day && $checkOut > $day,
        ];
        $row['total_guests'] = anagrafica_booking_total_guests($row);
        $row['document_ready'] = trim((string) ($row['leader_document_number'] ?? '')) !== '';
        $row['modal_payload'] = anagrafica_build_booking_modal_payload($pdo, $row);
    }
    unset($row);
    return $rows;
}
