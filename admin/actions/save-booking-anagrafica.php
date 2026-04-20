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

function booking_redirect(string $month, string $day): string
{
    $params = [];
    if ($month !== '') $params['month'] = $month;
    if ($day !== '') $params['day'] = $day;
    return admin_url('anagrafica.php' . ($params ? ('?' . http_build_query($params)) : ''));
}

function booking_parse_date(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') return null;
    foreach (['d/m/Y', 'Y-m-d'] as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $value);
        if ($dt instanceof DateTimeImmutable) return $dt->format('Y-m-d');
    }
    return null;
}

function booking_error(array &$errors, string $field, string $message): void
{
    if (!isset($errors[$field])) $errors[$field] = $message;
}


function booking_upsert_prenotazione_from_record(PDO $pdo, int $recordId, array $recordData, array $leaderGuest): int
{
    $customerName = trim(((string) ($leaderGuest['first_name'] ?? '')) . ' ' . ((string) ($leaderGuest['last_name'] ?? '')));
    $customerName = $customerName !== '' ? $customerName : ('Prenotazione ' . $recordId);
    $stayPeriod = anagrafica_booking_stay_period((string) ($recordData['arrival_date'] ?? ''), (string) ($recordData['departure_date'] ?? ''));

    $stmt = $pdo->prepare('SELECT prenotazione_id FROM anagrafica_records WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $recordId]);
    $prenotazioneId = (int) ($stmt->fetchColumn() ?: 0);

    if ($prenotazioneId > 0) {
        $update = $pdo->prepare('UPDATE prenotazioni SET customer_name = :customer_name, stay_period = :stay_period, check_in = :check_in, check_out = :check_out, room_type = COALESCE(room_type, :room_type), adults = :adults, children_count = :children_count, status = :status, external_reference = :external_reference, updated_at = NOW() WHERE id = :id LIMIT 1');
        $update->execute([
            'customer_name' => $customerName,
            'stay_period' => $stayPeriod,
            'check_in' => $recordData['arrival_date'],
            'check_out' => $recordData['departure_date'],
            'room_type' => 'Da definire',
            'adults' => max(1, (int) ($recordData['expected_guests'] ?? 1)),
            'children_count' => 0,
            'status' => 'confermata',
            'external_reference' => trim((string) ($recordData['booking_reference'] ?? '')) !== '' ? (string) $recordData['booking_reference'] : null,
            'id' => $prenotazioneId,
        ]);
        return $prenotazioneId;
    }

    $insert = $pdo->prepare('INSERT INTO prenotazioni (booking_request_id, customer_name, customer_email, email_missing, customer_phone, stay_period, check_in, check_out, room_type, adults, children_count, notes, status, source, external_reference, raw_payload, created_at, updated_at) VALUES (NULL, :customer_name, NULL, 1, NULL, :stay_period, :check_in, :check_out, :room_type, :adults, :children_count, NULL, :status, :source, :external_reference, :raw_payload, NOW(), NOW())');
    $insert->execute([
        'customer_name' => $customerName,
        'stay_period' => $stayPeriod,
        'check_in' => $recordData['arrival_date'],
        'check_out' => $recordData['departure_date'],
        'room_type' => 'Da definire',
        'adults' => max(1, (int) ($recordData['expected_guests'] ?? 1)),
        'children_count' => 0,
        'status' => 'confermata',
        'source' => 'anagrafica_admin',
        'external_reference' => trim((string) ($recordData['booking_reference'] ?? '')) !== '' ? (string) $recordData['booking_reference'] : null,
        'raw_payload' => json_encode(['created_from_booking_modal' => true], JSON_UNESCAPED_UNICODE),
    ]);
    $prenotazioneId = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE anagrafica_records SET prenotazione_id = :prenotazione_id WHERE id = :id')->execute(['prenotazione_id' => $prenotazioneId, 'id' => $recordId]);
    return $prenotazioneId;
}

$month = trim((string) ($_POST['month'] ?? ''));
$day = trim((string) ($_POST['day'] ?? ''));
$prenotazioneId = (int) ($_POST['prenotazione_id'] ?? 0);
$recordId = (int) ($_POST['linked_record_id'] ?? 0);
$allowedRecordTypes = ['single', 'family', 'group'];
$recordType = (string) ($_POST['record_type'] ?? 'single');
if (!in_array($recordType, $allowedRecordTypes, true)) {
    $recordType = 'single';
}

$data = [
    'prenotazione_id' => $prenotazioneId,
    'linked_record_id' => $recordId,
    'record_type' => $recordType,
    'booking_reference' => trim((string) ($_POST['booking_reference'] ?? '')),
    'booking_received_date' => trim((string) ($_POST['booking_received_date'] ?? '')),
    'arrival_date' => trim((string) ($_POST['arrival_date'] ?? '')),
    'departure_date' => trim((string) ($_POST['departure_date'] ?? '')),
    'reserved_rooms' => max(1, (int) ($_POST['reserved_rooms'] ?? 1)),
    'guests' => is_array($_POST['guests'] ?? null) ? array_values(array_filter($_POST['guests'], 'is_array')) : [],
];
$errors = [];
$messages = [];

$bookingReceivedDate = booking_parse_date($data['booking_received_date']);
$arrivalDate = booking_parse_date($data['arrival_date']);
$departureDate = booking_parse_date($data['departure_date']);

if ($prenotazioneId <= 0 && $recordId <= 0) booking_error($errors, 'booking_reference', 'Record non valido.');
if (!$bookingReceivedDate) booking_error($errors, 'booking_received_date', 'Inserisci una data di registrazione valida.');
if (!$arrivalDate) booking_error($errors, 'arrival_date', 'Inserisci una data di arrivo valida.');
if (!$departureDate) booking_error($errors, 'departure_date', 'Inserisci una data di partenza valida.');
if ($arrivalDate && $departureDate && $arrivalDate > $departureDate) booking_error($errors, 'departure_date', 'La partenza non può essere precedente all’arrivo.');
if ($data['reserved_rooms'] < 1) booking_error($errors, 'reserved_rooms', 'Indica almeno una camera.');
if (!$data['guests']) {
    $messages[] = 'Inserisci almeno un ospite.';
}
if ($recordType !== 'single' && count($data['guests']) < 2) {
    $messages[] = 'Per Famiglia o Gruppo aggiungi almeno un componente oltre al capogruppo.';
}

$normalizedGuests = [];
$existingGuestIds = [];

try {
    $bookingStmt = $pdo->prepare('SELECT * FROM prenotazioni WHERE id = :id LIMIT 1');
    $bookingStmt->execute(['id' => $prenotazioneId]);
    $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);
    if (!$booking) {
        if ($recordId <= 0) {
            throw new RuntimeException('Prenotazione non trovata.');
        }
        $booking = ['id' => 0];
    }

    $existing = null;
    if ($recordId > 0) {
        $existingStmt = $pdo->prepare('SELECT id, uuid, ross_prenotazione_idswh FROM anagrafica_records WHERE id = :id LIMIT 1');
        $existingStmt->execute(['id' => $recordId]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$existing) {
        $existingStmt = $pdo->prepare('SELECT id, uuid, ross_prenotazione_idswh FROM anagrafica_records WHERE prenotazione_id = :id LIMIT 1');
        $existingStmt->execute(['id' => $prenotazioneId]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($existing) {
            $recordId = (int) $existing['id'];
            $data['linked_record_id'] = $recordId;
        }
    }
    $bookingIdswh = (string) ($existing['ross_prenotazione_idswh'] ?? anagrafica_booking_idswh($booking));

    if ($recordId > 0) {
        $existingGuestStmt = $pdo->prepare('SELECT guest_idswh FROM anagrafica_guests WHERE record_id = :record_id ORDER BY is_group_leader DESC, id ASC');
        $existingGuestStmt->execute(['record_id' => $recordId]);
        $existingGuestIds = array_values(array_filter(array_map(static function ($row) {
            return (string) ($row['guest_idswh'] ?? '');
        }, $existingGuestStmt->fetchAll(PDO::FETCH_ASSOC) ?: [])));
    }

    foreach ($data['guests'] as $index => $guest) {
        if ($recordType === 'single' && $index > 0) {
            continue;
        }

        $fieldPrefix = 'guests.' . $index . '.';
        $isLeader = $index === 0;
        $firstName = trim((string) ($guest['first_name'] ?? ''));
        $lastName = trim((string) ($guest['last_name'] ?? ''));
        $gender = in_array((string) ($guest['gender'] ?? ''), ['M', 'F'], true) ? (string) $guest['gender'] : '';
        $birthDate = booking_parse_date($guest['birth_date'] ?? null);

        if ($firstName === '') booking_error($errors, $fieldPrefix . 'first_name', 'Inserisci il nome.');
        if ($lastName === '') booking_error($errors, $fieldPrefix . 'last_name', 'Inserisci il cognome.');
        if ($gender === '') booking_error($errors, $fieldPrefix . 'gender', 'Seleziona il sesso.');
        if (!$birthDate) booking_error($errors, $fieldPrefix . 'birth_date', 'Inserisci una data di nascita valida.');

        $citizenshipLabel = trim((string) ($guest['citizenship_label'] ?? ''));
        $citizenship = $citizenshipLabel !== '' ? anagrafica_find_state_by_value($citizenshipLabel) : null;
        if (!$citizenship) booking_error($errors, $fieldPrefix . 'citizenship_label', 'Seleziona una cittadinanza valida.');

        $birthStateLabel = trim((string) ($guest['birth_state_label'] ?? ''));
        $birthState = $birthStateLabel !== '' ? anagrafica_find_state_by_value($birthStateLabel) : null;
        if (!$birthState) booking_error($errors, $fieldPrefix . 'birth_state_label', 'Seleziona lo stato di nascita.');

        $birthProvinceInput = trim((string) ($guest['birth_province'] ?? ''));
        $birthProvinceCode = anagrafica_find_province_code($birthProvinceInput);
        $birthPlaceLabel = trim((string) ($guest['birth_place_label'] ?? ''));
        $birthCity = null;
        if ($birthState && $birthState['code'] === anagrafica_default_italy_state_code()) {
            if ($birthProvinceCode === null) booking_error($errors, $fieldPrefix . 'birth_province', 'Seleziona la provincia di nascita.');
            if ($birthPlaceLabel === '') {
                booking_error($errors, $fieldPrefix . 'birth_place_label', 'Seleziona il comune di nascita.');
            } else {
                $birthCity = anagrafica_find_comune_by_value($birthPlaceLabel, (string) ($birthProvinceCode ?? ''));
                if (!$birthCity) booking_error($errors, $fieldPrefix . 'birth_place_label', 'Comune di nascita non riconosciuto.');
            }
        }

        $residenceStateLabel = trim((string) ($guest['residence_state_label'] ?? ''));
        $residenceState = $residenceStateLabel !== '' ? anagrafica_find_state_by_value($residenceStateLabel) : null;
        if (!$residenceState) booking_error($errors, $fieldPrefix . 'residence_state_label', 'Seleziona lo stato di residenza.');

        $residenceProvinceInput = trim((string) ($guest['residence_province'] ?? ''));
        $residenceProvinceCode = anagrafica_find_province_code($residenceProvinceInput);
        $residencePlaceLabel = trim((string) ($guest['residence_place_label'] ?? ''));
        $residencePlace = null;
        if ($residenceState && $residenceState['code'] === anagrafica_default_italy_state_code()) {
            if ($residenceProvinceCode === null) booking_error($errors, $fieldPrefix . 'residence_province', 'Seleziona la provincia di residenza.');
            if ($residencePlaceLabel === '') {
                booking_error($errors, $fieldPrefix . 'residence_place_label', 'Seleziona il comune di residenza.');
            }
        } elseif ($residencePlaceLabel === '') {
            booking_error($errors, $fieldPrefix . 'residence_place_label', 'Indica la località o il codice NUTS di residenza.');
        }
        if ($residenceState) {
            $residencePlace = anagrafica_resolve_place_value($residenceState['code'], $residencePlaceLabel, (string) ($residenceProvinceCode ?? ''));
            if (!$residencePlace && $residencePlaceLabel !== '') booking_error($errors, $fieldPrefix . 'residence_place_label', 'Luogo di residenza non riconosciuto.');
        }

        $documentTypeLabel = trim((string) ($guest['document_type_label'] ?? ''));
        $documentType = $documentTypeLabel !== '' ? anagrafica_find_document_by_value($documentTypeLabel) : null;
        $documentNumber = trim((string) ($guest['document_number'] ?? ''));
        $documentIssuePlace = trim((string) ($guest['document_issue_place'] ?? ''));
        if ($isLeader) {
            if (!$documentType) booking_error($errors, $fieldPrefix . 'document_type_label', 'Seleziona il tipo documento.');
            if ($documentNumber === '') booking_error($errors, $fieldPrefix . 'document_number', 'Inserisci il numero documento.');
            if ($documentIssuePlace === '') booking_error($errors, $fieldPrefix . 'document_issue_place', 'Inserisci il luogo di rilascio del documento.');
        }
        $documentIssuePlaceCode = null;
        if ($documentIssuePlace !== '') {
            $issueComune = anagrafica_find_comune_by_value($documentIssuePlace, '');
            $documentIssuePlaceCode = $issueComune['code'] ?? null;
        }

        $tourismType = trim((string) ($guest['tourism_type'] ?? ''));
        if ($tourismType === '') booking_error($errors, $fieldPrefix . 'tourism_type', 'Seleziona il tipo di turismo.');
        $transportType = trim((string) ($guest['transport_type'] ?? ''));
        if ($transportType === '') booking_error($errors, $fieldPrefix . 'transport_type', 'Seleziona il mezzo di trasporto.');

        if ($firstName === '' || $lastName === '' || $gender === '' || !$birthDate || !$citizenship || !$birthState || !$residenceState || !$residencePlace || $tourismType === '' || $transportType === '') {
            continue;
        }
        if ($birthState['code'] === anagrafica_default_italy_state_code() && !$birthCity) continue;
        if ($isLeader && (!$documentType || $documentNumber === '' || $documentIssuePlace === '')) continue;

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
            'document_type_label' => $isLeader ? ($documentType['description'] ?? null) : null,
            'document_type_code' => $isLeader ? ($documentType['code'] ?? null) : null,
            'document_number' => $isLeader ? $documentNumber : null,
            'document_issue_place' => $isLeader ? $documentIssuePlace : null,
            'document_issue_place_code' => $isLeader ? $documentIssuePlaceCode : null,
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

    if ($errors || $messages) {
        $_SESSION['_anagrafica_booking_modal_state'] = [
            'open' => true,
            'data' => $data,
            'field_errors' => $errors,
            'messages' => array_values(array_unique(array_filter($messages ?: ['Controlla i campi evidenziati.']))),
        ];
        header('Location: ' . booking_redirect($month, $day));
        exit;
    }

    $pdo->beginTransaction();

    if ($recordId > 0) {
        $updateRecord = $pdo->prepare('UPDATE anagrafica_records SET prenotazione_id = :prenotazione_id, record_type = :record_type, booking_reference = :booking_reference, booking_received_date = :booking_received_date, arrival_date = :arrival_date, departure_date = :departure_date, expected_guests = :expected_guests, reserved_rooms = :reserved_rooms, updated_at = NOW() WHERE id = :id');
        $updateRecord->execute([
            'prenotazione_id' => $prenotazioneId,
            'record_type' => $recordType,
            'booking_reference' => $data['booking_reference'] !== '' ? $data['booking_reference'] : null,
            'booking_received_date' => $bookingReceivedDate,
            'arrival_date' => $arrivalDate,
            'departure_date' => $departureDate,
            'expected_guests' => count($normalizedGuests),
            'reserved_rooms' => $data['reserved_rooms'],
            'id' => $recordId,
        ]);
        $pdo->prepare('DELETE FROM anagrafica_guests WHERE record_id = :record_id')->execute(['record_id' => $recordId]);
    } else {
        $insertRecord = $pdo->prepare('INSERT INTO anagrafica_records (uuid, prenotazione_id, record_type, booking_reference, ross_prenotazione_idswh, booking_received_date, arrival_date, departure_date, expected_guests, reserved_rooms, status, created_at, updated_at) VALUES (:uuid, :prenotazione_id, :record_type, :booking_reference, :ross_prenotazione_idswh, :booking_received_date, :arrival_date, :departure_date, :expected_guests, :reserved_rooms, :status, NOW(), NOW())');
        $insertRecord->execute([
            'uuid' => bin2hex(random_bytes(16)),
            'prenotazione_id' => $prenotazioneId,
            'record_type' => $recordType,
            'booking_reference' => $data['booking_reference'] !== '' ? $data['booking_reference'] : null,
            'ross_prenotazione_idswh' => $bookingIdswh,
            'booking_received_date' => $bookingReceivedDate,
            'arrival_date' => $arrivalDate,
            'departure_date' => $departureDate,
            'expected_guests' => count($normalizedGuests),
            'reserved_rooms' => $data['reserved_rooms'],
            'status' => 'draft',
        ]);
        $recordId = (int) $pdo->lastInsertId();
    }

    $guestStmt = $pdo->prepare('INSERT INTO anagrafica_guests (record_id, guest_idswh, is_group_leader, leader_idswh, tipoalloggiato_code, first_name, last_name, gender, birth_date, citizenship_label, citizenship_code, birth_state_label, birth_state_code, birth_province, birth_place_label, birth_city_code, residence_state_label, residence_state_code, residence_province, residence_place_label, residence_place_code, document_type, document_type_label, document_type_code, document_number, document_issue_date, document_expiry_date, document_issue_place, document_issue_place_code, email, phone, tourism_type, transport_type, education_level, profession, tax_exemption_code, created_at, updated_at) VALUES (:record_id, :guest_idswh, :is_group_leader, :leader_idswh, :tipoalloggiato_code, :first_name, :last_name, :gender, :birth_date, :citizenship_label, :citizenship_code, :birth_state_label, :birth_state_code, :birth_province, :birth_place_label, :birth_city_code, :residence_state_label, :residence_state_code, :residence_province, :residence_place_label, :residence_place_code, :document_type, :document_type_label, :document_type_code, :document_number, NULL, NULL, :document_issue_place, :document_issue_place_code, NULL, NULL, :tourism_type, :transport_type, NULL, NULL, NULL, NOW(), NOW())');
    $leaderIdswh = '';
    foreach ($normalizedGuests as $index => $guest) {
        $guestIdswh = $existingGuestIds[$index] ?? derive_guest_idswh($bookingIdswh, $index);
        if ($index === 0) $leaderIdswh = $guestIdswh;
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

    $leaderGuest = $normalizedGuests[0];
    $prenotazioneId = booking_upsert_prenotazione_from_record($pdo, $recordId, [
        'arrival_date' => $arrivalDate,
        'departure_date' => $departureDate,
        'expected_guests' => count($normalizedGuests),
        'booking_reference' => $data['booking_reference'],
    ], $leaderGuest);

    if (alloggiati_schedine_table_ready($pdo)) {
        alloggiati_sync_record($pdo, $recordId);
    }

    $pdo->commit();
    unset($_SESSION['_anagrafica_booking_modal_state']);
    if (function_exists('set_flash')) {
        set_flash('success', 'Prenotazione e scheda anagrafica aggiornate correttamente.');
    }
    header('Location: ' . booking_redirect($month, $arrivalDate ?: $day));
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['_anagrafica_booking_modal_state'] = [
        'open' => true,
        'data' => $data,
        'field_errors' => $errors,
        'messages' => ['Impossibile salvare la scheda. Riprova oppure verifica i dati inseriti.'],
    ];
    header('Location: ' . booking_redirect($month, $day));
    exit;
}
