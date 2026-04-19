<?php

declare(strict_types=1);

require_once __DIR__ . '/anagrafica-options.php';
require_once __DIR__ . '/alloggiati.php';

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

    $recordType = anagrafica_booking_record_type($booking);
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
        $update = $pdo->prepare('UPDATE anagrafica_records SET record_type = :record_type, booking_reference = :booking_reference, booking_received_date = :booking_received_date, arrival_date = :arrival_date, departure_date = :departure_date, expected_guests = :expected_guests, status = :status, updated_at = NOW() WHERE id = :id');
        $update->execute($recordData + ['id' => (int) $existingRecord['id']]);
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
        'tipo_alloggiato_code' => $recordType === 'single' ? '16' : '18',
        'tipoalloggiato_code' => $recordType === 'single' ? '16' : '18',
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

function anagrafica_fetch_prenotazioni_touching_day(PDO $pdo, string $day): array
{
    $sql = "
        SELECT p.*, ar.id AS linked_record_id,
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
    }
    unset($row);
    return $rows;
}
