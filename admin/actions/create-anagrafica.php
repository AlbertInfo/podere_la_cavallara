<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/anagrafica-options.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . admin_url('anagrafica.php'));
    exit;
}

verify_csrf();

function redirect_to(string $url, string $type, string $message): never
{
    if (function_exists('set_flash')) {
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

function first_non_empty(array $values): string
{
    foreach ($values as $value) {
        $value = trim((string) $value);
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function normalize_optional(?string $value): ?string
{
    $value = trim((string) $value);
    return $value === '' ? null : $value;
}

function build_record_redirect_url(int $recordId, bool $isEdit): string
{
    return admin_url('anagrafica.php?' . ($isEdit ? 'edit=' . $recordId : 'new=1'));
}

function derive_guest_idswh(string $bookingIdswh, int $index): string
{
    return strtoupper(substr($bookingIdswh . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT), 0, 20));
}

try {
    $tableReady = (bool) $pdo->query("SHOW TABLES LIKE 'anagrafica_records'")->fetchColumn();
    if (!$tableReady) {
        redirect_to(admin_url('anagrafica.php?new=1'), 'error', 'Esegui prima la migration SQL della sezione anagrafica.');
    }

    $recordId = max(0, (int) ($_POST['record_id'] ?? 0));
    $isEdit = $recordId > 0;
    $allowedRecordTypes = ['single', 'family', 'group'];
    $recordType = (string) ($_POST['record_type'] ?? 'single');
    if (!in_array($recordType, $allowedRecordTypes, true)) {
        $recordType = 'single';
    }

    $bookingReference = trim((string) ($_POST['booking_reference'] ?? ''));
    $bookingReceivedDate = parse_date_input($_POST['booking_received_date'] ?? null);
    $arrivalDate = parse_date_input($_POST['arrival_date'] ?? null);
    $departureDate = parse_date_input($_POST['departure_date'] ?? null);
    $expectedGuests = max(1, (int) ($_POST['expected_guests'] ?? 1));
    $reservedRooms = max(1, (int) ($_POST['reserved_rooms'] ?? 1));
    $bookingChannel = trim((string) ($_POST['booking_channel'] ?? ''));
    $dailyPrice = trim((string) ($_POST['daily_price'] ?? ''));
    $bookingProvenienceStateLabel = trim((string) ($_POST['booking_provenience_state_label'] ?? ''));
    $bookingProvenienceProvince = trim((string) ($_POST['booking_provenience_province'] ?? ''));
    $bookingProveniencePlaceLabel = trim((string) ($_POST['booking_provenience_place_label'] ?? ''));
    $guests = $_POST['guests'] ?? [];

    $errors = [];
    if (!$bookingReceivedDate) {
        $errors[] = 'Inserisci una data registrazione prenotazione valida.';
    }
    if (!$arrivalDate || !$departureDate) {
        $errors[] = 'Inserisci date di arrivo e partenza valide.';
    }
    if ($arrivalDate && $departureDate && $arrivalDate > $departureDate) {
        $errors[] = 'La data di partenza non può essere precedente alla data di arrivo.';
    }
    if (!is_array($guests) || count($guests) === 0) {
        $errors[] = 'Inserisci almeno un ospite.';
    }
    if ($errors) {
        redirect_to(build_record_redirect_url($recordId, $isEdit), 'error', implode(' ', $errors));
    }

    $bookingProvenienceState = null;
    $bookingProveniencePlace = null;
    if ($bookingProvenienceStateLabel !== '') {
        $bookingProvenienceState = anagrafica_find_state_by_value($bookingProvenienceStateLabel);
        if (!$bookingProvenienceState) {
            $errors[] = 'Stato provenienza prenotazione non riconosciuto.';
        } elseif ($bookingProveniencePlaceLabel !== '') {
            $bookingProveniencePlace = anagrafica_resolve_place_value($bookingProvenienceState['code'], $bookingProveniencePlaceLabel, $bookingProvenienceProvince);
            if (!$bookingProveniencePlace) {
                $errors[] = 'Luogo provenienza prenotazione non riconosciuto.';
            }
        }
    } elseif ($bookingProveniencePlaceLabel !== '') {
        $errors[] = 'Se compili il luogo provenienza prenotazione devi indicare anche lo stato.';
    }

    if ($isEdit) {
        $existing = $pdo->prepare('SELECT id, uuid, ross_prenotazione_idswh FROM anagrafica_records WHERE id = :id LIMIT 1');
        $existing->execute(['id' => $recordId]);
        $existingRecord = $existing->fetch(PDO::FETCH_ASSOC);
        if (!$existingRecord) {
            redirect_to(admin_url('anagrafica.php'), 'error', 'Anagrafica non trovata.');
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
        $bookingIdswh = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $bookingReference !== '' ? $bookingReference : ('ANA' . date('YmdHis'))), 0, 20));
        if ($bookingIdswh === '') {
            $bookingIdswh = 'ANA' . date('YmdHis');
        }
        $existingGuestIds = [];
    }

    $normalizedGuests = [];
    foreach ($guests as $index => $guest) {
        if (!is_array($guest)) {
            continue;
        }

        $firstName = trim((string) ($guest['first_name'] ?? ''));
        $lastName = trim((string) ($guest['last_name'] ?? ''));
        if ($firstName === '' || $lastName === '') {
            continue;
        }

        if ($recordType === 'single' && $index > 0) {
            continue;
        }

        $citizenshipLabel = trim((string) ($guest['citizenship_label'] ?? ''));
        $residenceStateLabel = first_non_empty([
            (string) ($guest['residence_state_label'] ?? ''),
            anagrafica_default_state_label(),
        ]);
        $birthStateLabel = trim((string) ($guest['birth_state_label'] ?? ''));
        $birthProvince = trim((string) ($guest['birth_province'] ?? ''));
        $birthPlaceLabel = trim((string) ($guest['birth_place_label'] ?? ''));
        $residenceProvince = trim((string) ($guest['residence_province'] ?? ''));
        $residencePlaceLabel = first_non_empty([
            (string) ($guest['residence_place_label'] ?? ''),
            (string) ($guest['residence_place'] ?? ''),
        ]);
        $documentTypeLabel = first_non_empty([
            (string) ($guest['document_type_label'] ?? ''),
            (string) ($guest['document_type'] ?? ''),
        ]);
        $documentIssuePlace = trim((string) ($guest['document_issue_place'] ?? ''));

        $citizenship = anagrafica_find_state_by_value($citizenshipLabel);
        if (!$citizenship) {
            $errors[] = 'Cittadinanza non riconosciuta per ' . $firstName . ' ' . $lastName . '.';
            continue;
        }

        $residenceState = anagrafica_find_state_by_value($residenceStateLabel);
        if (!$residenceState) {
            $errors[] = 'Stato di residenza non riconosciuto per ' . $firstName . ' ' . $lastName . '.';
            continue;
        }

        $residencePlace = anagrafica_resolve_place_value($residenceState['code'], $residencePlaceLabel, $residenceProvince);
        if (!$residencePlace) {
            $errors[] = 'Luogo di residenza non riconosciuto per ' . $firstName . ' ' . $lastName . '.';
            continue;
        }

        $birthState = null;
        if ($birthStateLabel !== '' || $birthPlaceLabel !== '' || $birthProvince !== '') {
            $birthState = anagrafica_find_state_by_value($birthStateLabel !== '' ? $birthStateLabel : anagrafica_default_state_label());
            if (!$birthState) {
                $errors[] = 'Stato di nascita non riconosciuto per ' . $firstName . ' ' . $lastName . '.';
                continue;
            }
        }

        $birthCity = null;
        if ($birthState && $birthState['code'] === anagrafica_default_italy_state_code() && $birthPlaceLabel !== '') {
            $birthCity = anagrafica_find_comune_by_value($birthPlaceLabel, $birthProvince);
            if (!$birthCity) {
                $errors[] = 'Comune di nascita non riconosciuto per ' . $firstName . ' ' . $lastName . '.';
                continue;
            }
        }

        $documentType = anagrafica_find_document_by_value($documentTypeLabel);
        if (!$documentType) {
            $errors[] = 'Tipologia documento non riconosciuta per ' . $firstName . ' ' . $lastName . '.';
            continue;
        }

        $documentIssuePlaceCode = null;
        if ($documentIssuePlace !== '') {
            $issueComune = anagrafica_find_comune_by_value($documentIssuePlace, '');
            $documentIssuePlaceCode = $issueComune ? $issueComune['code'] : null;
        }

        $normalizedGuests[] = [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'gender' => in_array((string) ($guest['gender'] ?? 'M'), ['M', 'F'], true) ? (string) $guest['gender'] : 'M',
            'birth_date' => parse_date_input($guest['birth_date'] ?? null),
            'citizenship_label' => $citizenship['description'],
            'citizenship_code' => $citizenship['code'],
            'birth_state_label' => $birthState['description'] ?? null,
            'birth_state_code' => $birthState['code'] ?? null,
            'birth_province' => normalize_optional($birthProvince),
            'birth_place_label' => normalize_optional($birthCity['label'] ?? $birthPlaceLabel),
            'birth_city_code' => $birthCity['code'] ?? null,
            'residence_state_label' => $residenceState['description'],
            'residence_state_code' => $residenceState['code'],
            'residence_province' => normalize_optional($residenceProvince),
            'residence_place_label' => $residencePlace['label'],
            'residence_place_code' => $residencePlace['code'],
            'document_type_label' => $documentType['description'],
            'document_type_code' => $documentType['code'],
            'document_number' => trim((string) ($guest['document_number'] ?? '')),
            'document_issue_date' => parse_date_input($guest['document_issue_date'] ?? null),
            'document_expiry_date' => parse_date_input($guest['document_expiry_date'] ?? null),
            'document_issue_place' => normalize_optional($documentIssuePlace),
            'document_issue_place_code' => $documentIssuePlaceCode,
            'email' => normalize_optional((string) ($guest['email'] ?? '')),
            'phone' => normalize_optional((string) ($guest['phone'] ?? '')),
            'tourism_type' => trim((string) ($guest['tourism_type'] ?? '')),
            'transport_type' => trim((string) ($guest['transport_type'] ?? '')),
            'education_level' => normalize_optional((string) ($guest['education_level'] ?? '')),
            'profession' => normalize_optional((string) ($guest['profession'] ?? '')),
            'tax_exemption_code' => normalize_optional((string) ($guest['tax_exemption_code'] ?? '')),
        ];
    }

    if (!$normalizedGuests) {
        $errors[] = 'Inserisci almeno un ospite valido.';
    }

    if ($errors) {
        redirect_to(build_record_redirect_url($recordId, $isEdit), 'error', implode(' ', array_unique($errors)));
    }

    $pdo->beginTransaction();

    if ($isEdit) {
        $stmt = $pdo->prepare('UPDATE anagrafica_records SET record_type = :record_type, booking_reference = :booking_reference, booking_received_date = :booking_received_date, arrival_date = :arrival_date, departure_date = :departure_date, expected_guests = :expected_guests, reserved_rooms = :reserved_rooms, booking_channel = :booking_channel, daily_price = :daily_price, booking_provenience_state_label = :booking_provenience_state_label, booking_provenience_state_code = :booking_provenience_state_code, booking_provenience_province = :booking_provenience_province, booking_provenience_place_label = :booking_provenience_place_label, booking_provenience_place_code = :booking_provenience_place_code, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            'id' => $recordId,
            'record_type' => $recordType,
            'booking_reference' => $bookingReference !== '' ? $bookingReference : null,
            'booking_received_date' => $bookingReceivedDate,
            'arrival_date' => $arrivalDate,
            'departure_date' => $departureDate,
            'expected_guests' => count($normalizedGuests),
            'reserved_rooms' => $reservedRooms,
            'booking_channel' => $bookingChannel !== '' ? $bookingChannel : null,
            'daily_price' => $dailyPrice !== '' ? $dailyPrice : null,
            'booking_provenience_state_label' => $bookingProvenienceState['description'] ?? null,
            'booking_provenience_state_code' => $bookingProvenienceState['code'] ?? null,
            'booking_provenience_province' => $bookingProvenienceProvince !== '' ? $bookingProvenienceProvince : null,
            'booking_provenience_place_label' => $bookingProveniencePlace['label'] ?? ($bookingProveniencePlaceLabel !== '' ? $bookingProveniencePlaceLabel : null),
            'booking_provenience_place_code' => $bookingProveniencePlace['code'] ?? null,
        ]);

        $pdo->prepare('DELETE FROM anagrafica_guests WHERE record_id = :record_id')->execute(['record_id' => $recordId]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO anagrafica_records (uuid, record_type, booking_reference, ross_prenotazione_idswh, booking_received_date, arrival_date, departure_date, expected_guests, reserved_rooms, booking_channel, daily_price, booking_provenience_state_label, booking_provenience_state_code, booking_provenience_province, booking_provenience_place_label, booking_provenience_place_code, status, created_at, updated_at) VALUES (:uuid, :record_type, :booking_reference, :ross_prenotazione_idswh, :booking_received_date, :arrival_date, :departure_date, :expected_guests, :reserved_rooms, :booking_channel, :daily_price, :booking_provenience_state_label, :booking_provenience_state_code, :booking_provenience_province, :booking_provenience_place_label, :booking_provenience_place_code, :status, NOW(), NOW())');
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
            'booking_channel' => $bookingChannel !== '' ? $bookingChannel : null,
            'daily_price' => $dailyPrice !== '' ? $dailyPrice : null,
            'booking_provenience_state_label' => $bookingProvenienceState['description'] ?? null,
            'booking_provenience_state_code' => $bookingProvenienceState['code'] ?? null,
            'booking_provenience_province' => $bookingProvenienceProvince !== '' ? $bookingProvenienceProvince : null,
            'booking_provenience_place_label' => $bookingProveniencePlace['label'] ?? ($bookingProveniencePlaceLabel !== '' ? $bookingProveniencePlaceLabel : null),
            'booking_provenience_place_code' => $bookingProveniencePlace['code'] ?? null,
            'status' => 'draft',
        ]);
        $recordId = (int) $pdo->lastInsertId();
    }

    $guestStmt = $pdo->prepare('INSERT INTO anagrafica_guests (record_id, guest_idswh, is_group_leader, leader_idswh, tipoalloggiato_code, first_name, last_name, gender, birth_date, citizenship_label, citizenship_code, birth_state_label, birth_state_code, birth_province, birth_place_label, birth_city_code, residence_state_label, residence_state_code, residence_province, residence_place_label, residence_place_code, document_type, document_type_label, document_type_code, document_number, document_issue_date, document_expiry_date, document_issue_place, document_issue_place_code, email, phone, tourism_type, transport_type, education_level, profession, tax_exemption_code, created_at, updated_at) VALUES (:record_id, :guest_idswh, :is_group_leader, :leader_idswh, :tipoalloggiato_code, :first_name, :last_name, :gender, :birth_date, :citizenship_label, :citizenship_code, :birth_state_label, :birth_state_code, :birth_province, :birth_place_label, :birth_city_code, :residence_state_label, :residence_state_code, :residence_province, :residence_place_label, :residence_place_code, :document_type, :document_type_label, :document_type_code, :document_number, :document_issue_date, :document_expiry_date, :document_issue_place, :document_issue_place_code, :email, :phone, :tourism_type, :transport_type, :education_level, :profession, :tax_exemption_code, NOW(), NOW())');

    $leaderIdswh = '';
    foreach ($normalizedGuests as $index => $guest) {
        $guestIdswh = $existingGuestIds[$index] ?? derive_guest_idswh($bookingIdswh, $index);
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
            'document_issue_date' => $guest['document_issue_date'],
            'document_expiry_date' => $guest['document_expiry_date'],
            'document_issue_place' => $guest['document_issue_place'],
            'document_issue_place_code' => $guest['document_issue_place_code'],
            'email' => $guest['email'],
            'phone' => $guest['phone'],
            'tourism_type' => $guest['tourism_type'],
            'transport_type' => $guest['transport_type'],
            'education_level' => $guest['education_level'],
            'profession' => $guest['profession'],
            'tax_exemption_code' => $guest['tax_exemption_code'],
        ]);
    }

    $pdo->prepare('UPDATE anagrafica_records SET expected_guests = :expected_guests, updated_at = NOW() WHERE id = :id')->execute([
        'expected_guests' => count($normalizedGuests),
        'id' => $recordId,
    ]);

    $pdo->commit();

    $query = $isEdit ? 'updated=' . $recordId : 'created=' . $recordId;
    redirect_to(admin_url('anagrafica.php?' . $query), 'success', $isEdit ? 'Anagrafica aggiornata correttamente.' : 'Nuova anagrafica salvata correttamente.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $target = build_record_redirect_url(max(0, (int) ($_POST['record_id'] ?? 0)), max(0, (int) ($_POST['record_id'] ?? 0)) > 0);
    redirect_to($target, 'error', 'Errore durante il salvataggio dell\'anagrafica: ' . $e->getMessage());
}
