<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/anagrafica-options.php';
require_once __DIR__ . '/../includes/alloggiati.php';
require_once __DIR__ . '/../includes/prenotazioni-anagrafica-sync.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . admin_url('anagrafica.php'));
    exit;
}

verify_csrf();

function redirect_to(string $url, ?string $type = null, string $message = ''): never
{
    if ($type !== null && $message !== '' && function_exists('set_flash')) {
        set_flash($type, $message);
    }
    header('Location: ' . $url);
    exit;
}

function parse_date_input(?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    foreach (['d/m/Y', 'Y-m-d'] as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $value);
        if ($dt instanceof DateTimeImmutable) {
            return $dt->format('Y-m-d');
        }
    }

    return null;
}

function normalize_optional(?string $value): ?string
{
    $value = trim((string) $value);
    return $value === '' ? null : $value;
}

function build_form_redirect_url(int $recordId, bool $isEdit, string $month = '', string $day = ''): string
{
    $params = [];
    if ($month !== '') {
        $params['month'] = $month;
    }
    if ($day !== '') {
        $params['day'] = $day;
    }
    if ($isEdit) {
        $params['edit'] = (string) $recordId;
    } else {
        $params['new'] = '1';
    }

    $query = http_build_query($params);
    return admin_url('anagrafica.php' . ($query !== '' ? ('?' . $query) : ''));
}

function build_listing_redirect_url(string $month = '', string $day = '', string $extraKey = '', int $extraId = 0): string
{
    $params = [];
    if ($month !== '') {
        $params['month'] = $month;
    }
    if ($day !== '') {
        $params['day'] = $day;
    }
    if ($extraKey !== '' && $extraId > 0) {
        $params[$extraKey] = (string) $extraId;
    }

    $query = http_build_query($params);
    return admin_url('anagrafica.php' . ($query !== '' ? ('?' . $query) : ''));
}

function derive_guest_idswh(string $bookingIdswh, int $index): string
{
    $suffix = str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
    $base = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $bookingIdswh) ?? '');
    $base = substr($base, 0, 18);
    return str_pad($base . $suffix, 20, '0', STR_PAD_RIGHT);
}

function derive_booking_idswh(string $recordType, string $bookingReference): string
{
    $seed = $bookingReference !== '' ? $bookingReference : ($recordType . '-' . date('YmdHis'));
    $seed = preg_replace('/[^A-Za-z0-9]/', '', strtoupper($seed));
    if ($seed === null || $seed === '') {
        $seed = 'ANA' . date('YmdHis');
    }
    return substr($seed, 0, 20);
}

function add_field_error(array &$fieldErrors, string $field, string $message): void
{
    if (!isset($fieldErrors[$field])) {
        $fieldErrors[$field] = $message;
    }
}

function persist_form_state(array $postData, array $fieldErrors, array $messages): void
{
    $_SESSION['_anagrafica_form_state'] = [
        'data' => $postData,
        'field_errors' => $fieldErrors,
        'messages' => array_values(array_unique(array_filter($messages))),
    ];
}

function clear_form_state(): void
{
    unset($_SESSION['_anagrafica_form_state']);
}

function upsert_prenotazione_from_record(PDO $pdo, int $recordId, array $recordData, array $leaderGuest): int
{
    $customerName = trim(((string) ($leaderGuest['first_name'] ?? '')) . ' ' . ((string) ($leaderGuest['last_name'] ?? '')));
    $customerName = trim($customerName) !== '' ? trim($customerName) : 'Prenotazione ' . $recordId;
    $customerEmail = normalize_optional($leaderGuest['email'] ?? null);
    $customerPhone = normalize_optional($leaderGuest['phone'] ?? null);
    $stayPeriod = anagrafica_booking_stay_period((string) ($recordData['arrival_date'] ?? ''), (string) ($recordData['departure_date'] ?? ''));

    $stmt = $pdo->prepare('SELECT prenotazione_id FROM anagrafica_records WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $recordId]);
    $prenotazioneId = (int) ($stmt->fetchColumn() ?: 0);

    if ($prenotazioneId > 0) {
        $update = $pdo->prepare('UPDATE prenotazioni SET customer_name = :customer_name, customer_email = :customer_email, email_missing = :email_missing, customer_phone = :customer_phone, stay_period = :stay_period, check_in = :check_in, check_out = :check_out, room_type = COALESCE(room_type, :room_type), adults = :adults, children_count = :children_count, status = :status, external_reference = :external_reference, updated_at = NOW() WHERE id = :id LIMIT 1');
        $update->execute([
            'customer_name' => $customerName,
            'customer_email' => $customerEmail,
            'email_missing' => $customerEmail === null ? 1 : 0,
            'customer_phone' => $customerPhone,
            'stay_period' => $stayPeriod,
            'check_in' => $recordData['arrival_date'],
            'check_out' => $recordData['departure_date'],
            'room_type' => 'Da definire',
            'adults' => max(1, (int) ($recordData['expected_guests'] ?? 1)),
            'children_count' => 0,
            'status' => 'confermata',
            'external_reference' => normalize_optional($recordData['booking_reference'] ?? null),
            'id' => $prenotazioneId,
        ]);
        return $prenotazioneId;
    }

    $insert = $pdo->prepare('INSERT INTO prenotazioni (booking_request_id, customer_name, customer_email, email_missing, customer_phone, stay_period, check_in, check_out, room_type, adults, children_count, notes, status, source, external_reference, raw_payload, created_at, updated_at) VALUES (NULL, :customer_name, :customer_email, :email_missing, :customer_phone, :stay_period, :check_in, :check_out, :room_type, :adults, :children_count, NULL, :status, :source, :external_reference, :raw_payload, NOW(), NOW())');
    $insert->execute([
        'customer_name' => $customerName,
        'customer_email' => $customerEmail,
        'email_missing' => $customerEmail === null ? 1 : 0,
        'customer_phone' => $customerPhone,
        'stay_period' => $stayPeriod,
        'check_in' => $recordData['arrival_date'],
        'check_out' => $recordData['departure_date'],
        'room_type' => 'Da definire',
        'adults' => max(1, (int) ($recordData['expected_guests'] ?? 1)),
        'children_count' => 0,
        'status' => 'confermata',
        'source' => 'anagrafica_admin',
        'external_reference' => normalize_optional($recordData['booking_reference'] ?? null),
        'raw_payload' => json_encode(['created_from_anagrafica' => true], JSON_UNESCAPED_UNICODE),
    ]);
    $prenotazioneId = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE anagrafica_records SET prenotazione_id = :prenotazione_id WHERE id = :id')->execute(['prenotazione_id' => $prenotazioneId, 'id' => $recordId]);
    return $prenotazioneId;
}

try {
    $tableReady = (bool) $pdo->query("SHOW TABLES LIKE 'anagrafica_records'")->fetchColumn();
    if (!$tableReady) {
        redirect_to(admin_url('anagrafica.php?new=1'), 'error', 'Esegui prima la migration SQL della sezione anagrafica.');
    }

    $recordId = max(0, (int) ($_POST['record_id'] ?? 0));
    $isEdit = $recordId > 0;
    $returnMonth = trim((string) ($_POST['return_month'] ?? ''));
    $returnDay = trim((string) ($_POST['return_day'] ?? ''));
    $allowedRecordTypes = ['single', 'family', 'group'];
    $recordType = (string) ($_POST['record_type'] ?? 'single');
    if (!in_array($recordType, $allowedRecordTypes, true)) {
        $recordType = 'single';
    }

    $bookingReference = trim((string) ($_POST['booking_reference'] ?? ''));
    $bookingReceivedDate = parse_date_input($_POST['booking_received_date'] ?? null);
    $arrivalDate = parse_date_input($_POST['arrival_date'] ?? null);
    $departureDate = parse_date_input($_POST['departure_date'] ?? null);
    $reservedRooms = max(1, (int) ($_POST['reserved_rooms'] ?? 1));
    $guestsInput = $_POST['guests'] ?? [];
    $guests = is_array($guestsInput) ? array_values(array_filter($guestsInput, 'is_array')) : [];

    $fieldErrors = [];
    $messages = [];

    if (!$bookingReceivedDate) {
        add_field_error($fieldErrors, 'booking_received_date', 'Inserisci una data di registrazione valida.');
    }
    if (!$arrivalDate) {
        add_field_error($fieldErrors, 'arrival_date', 'Inserisci una data di arrivo valida.');
    }
    if (!$departureDate) {
        add_field_error($fieldErrors, 'departure_date', 'Inserisci una data di partenza valida.');
    }
    if ($arrivalDate && $departureDate && $arrivalDate > $departureDate) {
        add_field_error($fieldErrors, 'departure_date', 'La partenza non può essere precedente all’arrivo.');
    }
    if ($reservedRooms < 1) {
        add_field_error($fieldErrors, 'reserved_rooms', 'Indica almeno una camera.');
    }
    if ($reservedRooms > 6) {
        add_field_error($fieldErrors, 'reserved_rooms', 'Puoi indicare al massimo 6 camere.');
    }
    if (!$guests) {
        $messages[] = 'Inserisci almeno un ospite.';
    }
    if ($recordType !== 'single' && count($guests) < 2) {
        $messages[] = 'Per Famiglia o Gruppo aggiungi almeno un componente oltre al capogruppo.';
    }

    $normalizedGuests = [];
    $existingGuestIds = [];
    $recordUuid = '';
    $bookingIdswh = '';

    if ($isEdit) {
        $existing = $pdo->prepare('SELECT id, uuid, ross_prenotazione_idswh FROM anagrafica_records WHERE id = :id LIMIT 1');
        $existing->execute(['id' => $recordId]);
        $existingRecord = $existing->fetch(PDO::FETCH_ASSOC);
        if (!$existingRecord) {
            redirect_to(build_listing_redirect_url($returnMonth, $returnDay), 'error', 'Anagrafica non trovata.');
        }
        $recordUuid = (string) $existingRecord['uuid'];
        $bookingIdswh = (string) $existingRecord['ross_prenotazione_idswh'];

        $existingGuestStmt = $pdo->prepare('SELECT guest_idswh FROM anagrafica_guests WHERE record_id = :record_id ORDER BY is_group_leader DESC, id ASC');
        $existingGuestStmt->execute(['record_id' => $recordId]);
        $existingGuestIds = array_values(array_filter(array_map(static function ($row) {
            return (string) ($row['guest_idswh'] ?? '');
        }, $existingGuestStmt->fetchAll(PDO::FETCH_ASSOC) ?: [])));
    } else {
        $recordUuid = bin2hex(random_bytes(16));
        $bookingIdswh = derive_booking_idswh($recordType, $bookingReference);
    }

    $documentRows = anagrafica_document_types();

    foreach ($guests as $index => $guest) {
        if ($recordType === 'single' && $index > 0) {
            continue;
        }

        $fieldPrefix = 'guests.' . $index . '.';
        $isLeader = $index === 0;
        $firstName = trim((string) ($guest['first_name'] ?? ''));
        $lastName = trim((string) ($guest['last_name'] ?? ''));
        $gender = in_array((string) ($guest['gender'] ?? ''), ['M', 'F'], true) ? (string) $guest['gender'] : '';
        $birthDate = parse_date_input($guest['birth_date'] ?? null);

        if ($firstName === '') {
            add_field_error($fieldErrors, $fieldPrefix . 'first_name', 'Inserisci il nome.');
        }
        if ($lastName === '') {
            add_field_error($fieldErrors, $fieldPrefix . 'last_name', 'Inserisci il cognome.');
        }
        if ($gender === '') {
            add_field_error($fieldErrors, $fieldPrefix . 'gender', 'Seleziona il sesso.');
        }
        if (!$birthDate) {
            add_field_error($fieldErrors, $fieldPrefix . 'birth_date', 'Inserisci una data di nascita valida.');
        }

        $citizenshipLabel = trim((string) ($guest['citizenship_label'] ?? ''));
        $citizenship = $citizenshipLabel !== '' ? anagrafica_find_state_by_value($citizenshipLabel) : null;
        if (!$citizenship) {
            add_field_error($fieldErrors, $fieldPrefix . 'citizenship_label', 'Seleziona una cittadinanza valida.');
        }

        $birthStateLabel = trim((string) ($guest['birth_state_label'] ?? ''));
        $birthState = $birthStateLabel !== '' ? anagrafica_find_state_by_value($birthStateLabel) : null;
        if (!$birthState) {
            add_field_error($fieldErrors, $fieldPrefix . 'birth_state_label', 'Seleziona lo stato di nascita.');
        }

        $birthProvinceInput = trim((string) ($guest['birth_province'] ?? ''));
        $birthProvinceCode = anagrafica_find_province_code($birthProvinceInput);
        $birthPlaceLabel = trim((string) ($guest['birth_place_label'] ?? ''));
        $birthCity = null;
        if ($birthState && $birthState['code'] === anagrafica_default_italy_state_code()) {
            if ($birthProvinceCode === null) {
                add_field_error($fieldErrors, $fieldPrefix . 'birth_province', 'Seleziona la provincia di nascita.');
            }
            if ($birthPlaceLabel === '') {
                add_field_error($fieldErrors, $fieldPrefix . 'birth_place_label', 'Seleziona il comune di nascita.');
            } else {
                $birthCity = anagrafica_find_comune_by_value($birthPlaceLabel, (string) ($birthProvinceCode ?? ''));
                if (!$birthCity) {
                    add_field_error($fieldErrors, $fieldPrefix . 'birth_place_label', 'Comune di nascita non riconosciuto.');
                }
            }
        }

        $residenceStateLabel = trim((string) ($guest['residence_state_label'] ?? ''));
        $residenceState = $residenceStateLabel !== '' ? anagrafica_find_state_by_value($residenceStateLabel) : null;
        if (!$residenceState) {
            add_field_error($fieldErrors, $fieldPrefix . 'residence_state_label', 'Seleziona lo stato di residenza.');
        }

        $residenceProvinceInput = trim((string) ($guest['residence_province'] ?? ''));
        $residenceProvinceCode = anagrafica_find_province_code($residenceProvinceInput);
        $residencePlaceLabel = trim((string) ($guest['residence_place_label'] ?? ''));
        $residencePlace = null;
        if ($residenceState && $residenceState['code'] === anagrafica_default_italy_state_code()) {
            if ($residenceProvinceCode === null) {
                add_field_error($fieldErrors, $fieldPrefix . 'residence_province', 'Seleziona la provincia di residenza.');
            }
            if ($residencePlaceLabel === '') {
                add_field_error($fieldErrors, $fieldPrefix . 'residence_place_label', 'Seleziona il comune di residenza.');
            }
        } elseif ($residencePlaceLabel === '') {
            add_field_error($fieldErrors, $fieldPrefix . 'residence_place_label', 'Indica la località o il codice NUTS di residenza.');
        }

        if ($residenceState) {
            $residencePlace = anagrafica_resolve_place_value($residenceState['code'], $residencePlaceLabel, (string) ($residenceProvinceCode ?? ''));
            if (!$residencePlace && $residencePlaceLabel !== '') {
                add_field_error($fieldErrors, $fieldPrefix . 'residence_place_label', 'Luogo di residenza non riconosciuto.');
            }
        }

        $documentTypeLabel = trim((string) ($guest['document_type_label'] ?? ''));
        $documentType = $documentTypeLabel !== '' ? anagrafica_find_document_by_value($documentTypeLabel) : null;
        $documentNumber = trim((string) ($guest['document_number'] ?? ''));
        $documentIssuePlace = trim((string) ($guest['document_issue_place'] ?? ''));

        if (!$documentType) {
            add_field_error($fieldErrors, $fieldPrefix . 'document_type_label', 'Seleziona il tipo documento.');
        }
        if ($documentNumber === '') {
            add_field_error($fieldErrors, $fieldPrefix . 'document_number', 'Inserisci il numero documento.');
        }
        if ($documentIssuePlace === '') {
            add_field_error($fieldErrors, $fieldPrefix . 'document_issue_place', 'Inserisci il luogo di rilascio del documento.');
        }

        $documentIssuePlaceResolved = $documentIssuePlace !== '' ? anagrafica_resolve_document_issue_place_value($documentIssuePlace) : null;
        $documentIssuePlaceCode = $documentIssuePlaceResolved['code'] ?? null;
        if ($documentIssuePlace !== '' && !$documentIssuePlaceResolved) {
            add_field_error($fieldErrors, $fieldPrefix . 'document_issue_place', 'Luogo di rilascio del documento non riconosciuto.');
        }

        $tourismType = trim((string) ($guest['tourism_type'] ?? ''));
        if ($tourismType === '') {
            add_field_error($fieldErrors, $fieldPrefix . 'tourism_type', 'Seleziona il tipo di turismo.');
        }

        $transportType = trim((string) ($guest['transport_type'] ?? ''));
        if ($transportType === '') {
            add_field_error($fieldErrors, $fieldPrefix . 'transport_type', 'Seleziona il mezzo di trasporto.');
        }

        if ($firstName === '' || $lastName === '' || $gender === '' || !$birthDate || !$citizenship || !$birthState || !$residenceState || !$residencePlace || $tourismType === '' || $transportType === '') {
            continue;
        }

        if ($birthState['code'] === anagrafica_default_italy_state_code() && !$birthCity) {
            continue;
        }

        if (!$documentType || $documentNumber === '' || !$documentIssuePlaceResolved) {
            continue;
        }

        $normalizedGuests[] = [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'gender' => $gender,
            'birth_date' => $birthDate,
            'citizenship_label' => $citizenship['description'],
            'citizenship_code' => $citizenship['code'],
            'birth_state_label' => $birthState['description'],
            'birth_state_code' => $birthState['code'],
            'birth_province' => $birthProvinceCode,
            'birth_place_label' => $birthCity['label'] ?? null,
            'birth_city_code' => $birthCity['code'] ?? null,
            'residence_state_label' => $residenceState['description'],
            'residence_state_code' => $residenceState['code'],
            'residence_province' => $residenceProvinceCode,
            'residence_place_label' => $residencePlace['label'],
            'residence_place_code' => $residencePlace['code'],
            'document_type_label' => $documentType['description'] ?? null,
            'document_type_code' => $documentType['code'] ?? null,
            'document_number' => $documentNumber,
            'document_issue_place' => $documentIssuePlaceResolved['label'] ?? $documentIssuePlace,
            'document_issue_place_code' => $documentIssuePlaceCode,
            'tourism_type' => $tourismType,
            'transport_type' => $transportType,
        ];
    }

    if (!$normalizedGuests) {
        $messages[] = 'Completa correttamente almeno il capogruppo / primo ospite.';
    }

    if ($recordType !== 'single' && count($normalizedGuests) < 2) {
        $messages[] = 'Per Famiglia o Gruppo è necessario almeno un componente aggiuntivo.';
    }

    if ($fieldErrors || $messages) {
        persist_form_state($_POST, $fieldErrors, $messages);
        redirect_to(build_form_redirect_url($recordId, $isEdit, $returnMonth, $returnDay));
    }

    $pdo->beginTransaction();

    if ($isEdit) {
        $stmt = $pdo->prepare('UPDATE anagrafica_records SET record_type = :record_type, booking_reference = :booking_reference, booking_received_date = :booking_received_date, arrival_date = :arrival_date, departure_date = :departure_date, expected_guests = :expected_guests, reserved_rooms = :reserved_rooms, booking_channel = NULL, daily_price = NULL, booking_provenience_state_label = NULL, booking_provenience_state_code = NULL, booking_provenience_province = NULL, booking_provenience_place_label = NULL, booking_provenience_place_code = NULL, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            'id' => $recordId,
            'record_type' => $recordType,
            'booking_reference' => $bookingReference !== '' ? $bookingReference : null,
            'booking_received_date' => $bookingReceivedDate,
            'arrival_date' => $arrivalDate,
            'departure_date' => $departureDate,
            'expected_guests' => count($normalizedGuests),
            'reserved_rooms' => $reservedRooms,
        ]);

        $pdo->prepare('DELETE FROM anagrafica_guests WHERE record_id = :record_id')->execute(['record_id' => $recordId]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO anagrafica_records (uuid, record_type, booking_reference, ross_prenotazione_idswh, booking_received_date, arrival_date, departure_date, expected_guests, reserved_rooms, booking_channel, daily_price, booking_provenience_state_label, booking_provenience_state_code, booking_provenience_province, booking_provenience_place_label, booking_provenience_place_code, status, created_at, updated_at) VALUES (:uuid, :record_type, :booking_reference, :ross_prenotazione_idswh, :booking_received_date, :arrival_date, :departure_date, :expected_guests, :reserved_rooms, NULL, NULL, NULL, NULL, NULL, NULL, NULL, :status, NOW(), NOW())');
        $stmt->execute([
            'uuid' => $recordUuid,
            'record_type' => $recordType,
            'booking_reference' => $bookingReference !== '' ? $bookingReference : null,
            'ross_prenotazione_idswh' => $bookingIdswh,
            'booking_received_date' => $bookingReceivedDate,
            'arrival_date' => $arrivalDate,
            'departure_date' => $departureDate,
            'expected_guests' => count($normalizedGuests),
            'reserved_rooms' => $reservedRooms,
            'status' => 'draft',
        ]);
        $recordId = (int) $pdo->lastInsertId();
    }

    $guestStmt = $pdo->prepare('INSERT INTO anagrafica_guests (record_id, guest_idswh, is_group_leader, leader_idswh, tipoalloggiato_code, first_name, last_name, gender, birth_date, citizenship_label, citizenship_code, birth_state_label, birth_state_code, birth_province, birth_place_label, birth_city_code, residence_state_label, residence_state_code, residence_province, residence_place_label, residence_place_code, document_type, document_type_label, document_type_code, document_number, document_issue_date, document_expiry_date, document_issue_place, document_issue_place_code, email, phone, tourism_type, transport_type, education_level, profession, tax_exemption_code, created_at, updated_at) VALUES (:record_id, :guest_idswh, :is_group_leader, :leader_idswh, :tipoalloggiato_code, :first_name, :last_name, :gender, :birth_date, :citizenship_label, :citizenship_code, :birth_state_label, :birth_state_code, :birth_province, :birth_place_label, :birth_city_code, :residence_state_label, :residence_state_code, :residence_province, :residence_place_label, :residence_place_code, :document_type, :document_type_label, :document_type_code, :document_number, NULL, NULL, :document_issue_place, :document_issue_place_code, NULL, NULL, :tourism_type, :transport_type, NULL, NULL, NULL, NOW(), NOW())');

    $leaderIdswh = '';
    $usedGuestIdswh = [];
    foreach ($normalizedGuests as $index => $guest) {
        $derivedGuestIdswh = derive_guest_idswh($bookingIdswh, $index);
        $existingGuestIdswh = trim((string) ($existingGuestIds[$index] ?? ''));
        $guestIdswh = $existingGuestIdswh;
        if ($guestIdswh === '' || isset($usedGuestIdswh[$guestIdswh])) {
            $guestIdswh = $derivedGuestIdswh;
        }
        $usedGuestIdswh[$guestIdswh] = true;
        if ($index === 0) {
            $leaderIdswh = $guestIdswh;
        }

        $guestStmt->execute([
            'record_id' => $recordId,
            'guest_idswh' => $guestIdswh,
            'is_group_leader' => $index === 0 ? 1 : 0,
            'leader_idswh' => $index === 0 ? null : $leaderIdswh,
            'tipoalloggiato_code' => anagrafica_tipo_alloggiato_code_for_record_type($recordType, $index === 0),
            'first_name' => $guest['first_name'],
            'last_name' => $guest['last_name'],
            'gender' => $guest['gender'],
            'birth_date' => $guest['birth_date'],
            'citizenship_label' => $guest['citizenship_label'],
            'citizenship_code' => $guest['citizenship_code'],
            'birth_state_label' => $guest['birth_state_label'],
            'birth_state_code' => $guest['birth_state_code'],
            'birth_province' => $guest['birth_province'],
            'birth_place_label' => $guest['birth_place_label'],
            'birth_city_code' => $guest['birth_city_code'],
            'residence_state_label' => $guest['residence_state_label'],
            'residence_state_code' => $guest['residence_state_code'],
            'residence_province' => $guest['residence_province'],
            'residence_place_label' => $guest['residence_place_label'],
            'residence_place_code' => $guest['residence_place_code'],
            'document_type' => $guest['document_type_label'],
            'document_type_label' => $guest['document_type_label'],
            'document_type_code' => $guest['document_type_code'],
            'document_number' => $guest['document_number'],
            'document_issue_place' => $guest['document_issue_place'],
            'document_issue_place_code' => $guest['document_issue_place_code'],
            'tourism_type' => $guest['tourism_type'],
            'transport_type' => $guest['transport_type'],
        ]);
    }

    $pdo->prepare('UPDATE anagrafica_records SET expected_guests = :expected_guests, updated_at = NOW() WHERE id = :id')->execute([
        'expected_guests' => count($normalizedGuests),
        'id' => $recordId,
    ]);

    upsert_prenotazione_from_record($pdo, $recordId, [
        'arrival_date' => $arrivalDate,
        'departure_date' => $departureDate,
        'expected_guests' => count($normalizedGuests),
        'booking_reference' => $bookingReference,
    ], $normalizedGuests[0]);

    if (alloggiati_schedine_table_ready($pdo)) {
        alloggiati_sync_record($pdo, $recordId);
    }

    $pdo->commit();
    clear_form_state();

    redirect_to(
        build_listing_redirect_url($returnMonth, $returnDay !== '' ? $returnDay : $arrivalDate, $isEdit ? 'updated' : 'created', $recordId),
        'success',
        $isEdit ? 'Anagrafica aggiornata correttamente.' : 'Nuova anagrafica salvata correttamente.'
    );
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    persist_form_state($_POST, [], ['Errore durante il salvataggio dell\'anagrafica. Controlla i dati e riprova.']);
    redirect_to(build_form_redirect_url(max(0, (int) ($_POST['record_id'] ?? 0)), max(0, (int) ($_POST['record_id'] ?? 0)) > 0, trim((string) ($_POST['return_month'] ?? '')), trim((string) ($_POST['return_day'] ?? ''))));
}
