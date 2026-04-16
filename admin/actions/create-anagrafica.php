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

function redirect_to(string $url, string $type, string $message)
{
    if (function_exists('flash_set')) {
        flash_set($type, $message);
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

function clean_string(?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    $value = trim($value);
    return $value === '' ? null : $value;
}

function common_redirect_target(int $recordId, bool $isEdit): string
{
    return admin_url('anagrafica.php?' . ($isEdit ? 'edit=' . $recordId : 'new=1'));
}

try {
    $tableReady = (bool) $pdo->query("SHOW TABLES LIKE 'anagrafica_records'")->fetchColumn();
    if (!$tableReady) {
        redirect_to(admin_url('anagrafica.php?new=1'), 'error', 'Esegui prima la migration SQL della sezione anagrafica.');
    }

    $recordId = max(0, (int) ($_POST['record_id'] ?? 0));
    $isEdit = $recordId > 0;
    $recordType = ($_POST['record_type'] ?? 'single') === 'group' ? 'group' : 'single';
    $bookingReference = clean_string($_POST['booking_reference'] ?? null);
    $arrivalDate = parse_date_input($_POST['arrival_date'] ?? null);
    $departureDate = parse_date_input($_POST['departure_date'] ?? null);
    $expectedGuests = max(1, (int) ($_POST['expected_guests'] ?? 1));
    $reservedRooms = max(1, (int) ($_POST['reserved_rooms'] ?? 1));
    $bookingChannel = clean_string($_POST['booking_channel'] ?? null);
    $dailyPrice = clean_string($_POST['daily_price'] ?? null);
    $provenienceStateLabel = clean_string($_POST['provenience_state_label'] ?? null);
    $provenienceProvince = strtoupper(trim((string) ($_POST['provenience_province'] ?? '')));
    $proveniencePlace = clean_string($_POST['provenience_place'] ?? null);
    $guests = $_POST['guests'] ?? [];

    if (!$arrivalDate || !$departureDate) {
        redirect_to(common_redirect_target($recordId, $isEdit), 'error', 'Inserisci date di arrivo e partenza valide.');
    }
    if (!is_array($guests) || count($guests) === 0) {
        redirect_to(common_redirect_target($recordId, $isEdit), 'error', 'Inserisci almeno un ospite.');
    }

    $provenienceStateCode = $provenienceStateLabel ? anagrafica_map_state_code($provenienceStateLabel) : null;
    $proveniencePlaceCode = null;
    if ($provenienceStateLabel && !$provenienceStateCode) {
        redirect_to(common_redirect_target($recordId, $isEdit), 'error', 'Stato di provenienza prenotazione non riconosciuto nelle tabelle ufficiali.');
    }
    if ($provenienceStateCode === '100000100' && $proveniencePlace) {
        $match = anagrafica_find_comune($proveniencePlace, $provenienceProvince ?: null);
        if (!$match) {
            redirect_to(common_redirect_target($recordId, $isEdit), 'error', 'Comune di provenienza prenotazione non riconosciuto o ambiguo. Specifica anche la provincia corretta.');
        }
        $proveniencePlaceCode = (string) $match['Codice'];
        $proveniencePlace = (string) $match['Descrizione'];
        $provenienceProvince = (string) $match['Provincia'];
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
    } else {
        $recordUuid = bin2hex(random_bytes(16));
        $bookingIdswh = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $bookingReference ?: ('ANA' . date('YmdHis'))), 0, 20));
        if ($bookingIdswh === '') {
            $bookingIdswh = 'ANA' . date('YmdHis');
        }
    }

    $normalizedGuests = [];
    foreach ($guests as $index => $guest) {
        if (!is_array($guest)) {
            continue;
        }

        $firstName = clean_string($guest['first_name'] ?? null);
        $lastName = clean_string($guest['last_name'] ?? null);
        if ($firstName === null || $lastName === null) {
            continue;
        }

        $citizenshipLabel = clean_string($guest['citizenship_label'] ?? null);
        $citizenshipCode = $citizenshipLabel ? anagrafica_map_state_code($citizenshipLabel) : null;
        if (!$citizenshipCode) {
            redirect_to(common_redirect_target($recordId, $isEdit), 'error', 'Cittadinanza non riconosciuta per ' . $firstName . ' ' . $lastName . '.');
        }
        $citizenshipLabel = anagrafica_state_label_from_code($citizenshipCode) ?? $citizenshipLabel;

        $residenceStateLabel = clean_string($guest['residence_state_label'] ?? null) ?? 'Italia';
        $residenceStateCode = anagrafica_map_state_code($residenceStateLabel);
        if (!$residenceStateCode) {
            redirect_to(common_redirect_target($recordId, $isEdit), 'error', 'Stato di residenza non riconosciuto per ' . $firstName . ' ' . $lastName . '.');
        }
        $residenceStateLabel = anagrafica_state_label_from_code($residenceStateCode) ?? $residenceStateLabel;
        $residenceProvince = strtoupper(trim((string) ($guest['residence_province'] ?? '')));
        $residencePlace = clean_string($guest['residence_place'] ?? null);
        $residencePlaceCode = null;
        if ($residenceStateCode === '100000100') {
            if (!$residencePlace) {
                redirect_to(common_redirect_target($recordId, $isEdit), 'error', 'Comune di residenza obbligatorio per ' . $firstName . ' ' . $lastName . '.');
            }
            $residenceMatch = anagrafica_find_comune($residencePlace, $residenceProvince ?: null);
            if (!$residenceMatch) {
                redirect_to(common_redirect_target($recordId, $isEdit), 'error', 'Comune di residenza non riconosciuto o ambiguo per ' . $firstName . ' ' . $lastName . '.');
            }
            $residencePlaceCode = (string) $residenceMatch['Codice'];
            $residencePlace = (string) $residenceMatch['Descrizione'];
            $residenceProvince = (string) $residenceMatch['Provincia'];
        }

        $birthStateLabel = clean_string($guest['birth_state_label'] ?? null) ?? 'Italia';
        $birthStateCode = anagrafica_map_state_code($birthStateLabel);
        if (!$birthStateCode) {
            redirect_to(common_redirect_target($recordId, $isEdit), 'error', 'Stato di nascita non riconosciuto per ' . $firstName . ' ' . $lastName . '.');
        }
        $birthStateLabel = anagrafica_state_label_from_code($birthStateCode) ?? $birthStateLabel;
        $birthProvince = strtoupper(trim((string) ($guest['birth_province'] ?? '')));
        $birthPlace = clean_string($guest['birth_place'] ?? null);
        $birthPlaceCode = null;
        if ($birthStateCode === '100000100') {
            if (!$birthPlace) {
                redirect_to(common_redirect_target($recordId, $isEdit), 'error', 'Comune di nascita obbligatorio per ' . $firstName . ' ' . $lastName . '.');
            }
            $birthMatch = anagrafica_find_comune($birthPlace, $birthProvince ?: null);
            if (!$birthMatch) {
                redirect_to(common_redirect_target($recordId, $isEdit), 'error', 'Comune di nascita non riconosciuto o ambiguo per ' . $firstName . ' ' . $lastName . '.');
            }
            $birthPlaceCode = (string) $birthMatch['Codice'];
            $birthPlace = (string) $birthMatch['Descrizione'];
            $birthProvince = (string) $birthMatch['Provincia'];
        }

        $documentTypeCode = anagrafica_map_document_type_code($guest['document_type'] ?? null);
        if (!$documentTypeCode) {
            redirect_to(common_redirect_target($recordId, $isEdit), 'error', 'Tipologia documento non riconosciuta per ' . $firstName . ' ' . $lastName . '.');
        }

        $issueProvince = strtoupper(trim((string) ($guest['document_issue_province'] ?? '')));
        $issuePlace = clean_string($guest['document_issue_place'] ?? null);
        $issuePlaceCode = null;
        if ($issuePlace) {
            $issueMatch = anagrafica_find_comune($issuePlace, $issueProvince ?: null);
            if ($issueMatch) {
                $issuePlaceCode = (string) $issueMatch['Codice'];
                $issuePlace = (string) $issueMatch['Descrizione'];
                $issueProvince = (string) $issueMatch['Provincia'];
            }
        }

        $normalizedGuests[] = [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'gender' => in_array(($guest['gender'] ?? 'M'), ['M', 'F'], true) ? (string) $guest['gender'] : 'M',
            'birth_date' => parse_date_input($guest['birth_date'] ?? null),
            'citizenship_label' => $citizenshipLabel,
            'citizenship_code' => $citizenshipCode,
            'residence_state_label' => $residenceStateLabel,
            'residence_state_code' => $residenceStateCode,
            'residence_province' => $residenceProvince !== '' ? $residenceProvince : null,
            'residence_place' => $residencePlace,
            'residence_place_code' => $residencePlaceCode,
            'birth_state_label' => $birthStateLabel,
            'birth_state_code' => $birthStateCode,
            'birth_province' => $birthProvince !== '' ? $birthProvince : null,
            'birth_place' => $birthPlace,
            'birth_place_code' => $birthPlaceCode,
            'document_type' => $documentTypeCode,
            'document_number' => clean_string($guest['document_number'] ?? null),
            'document_issue_date' => parse_date_input($guest['document_issue_date'] ?? null),
            'document_expiry_date' => parse_date_input($guest['document_expiry_date'] ?? null),
            'document_issue_province' => $issueProvince !== '' ? $issueProvince : null,
            'document_issue_place' => $issuePlace,
            'document_issue_place_code' => $issuePlaceCode,
            'email' => clean_string($guest['email'] ?? null),
            'phone' => clean_string($guest['phone'] ?? null),
            'tourism_type' => clean_string($guest['tourism_type'] ?? null),
            'transport_type' => clean_string($guest['transport_type'] ?? null),
            'tipo_alloggiato_code' => anagrafica_map_tipo_alloggiato_code($recordType, count($normalizedGuests)),
        ];
    }

    if (count($normalizedGuests) === 0) {
        redirect_to(common_redirect_target($recordId, $isEdit), 'error', 'Inserisci almeno un ospite valido.');
    }

    $pdo->beginTransaction();

    if ($isEdit) {
        $stmt = $pdo->prepare('UPDATE anagrafica_records SET record_type = :record_type, booking_reference = :booking_reference, arrival_date = :arrival_date, departure_date = :departure_date, expected_guests = :expected_guests, reserved_rooms = :reserved_rooms, booking_channel = :booking_channel, daily_price = :daily_price, provenience_state_label = :provenience_state_label, provenience_state_code = :provenience_state_code, provenience_province = :provenience_province, provenience_place = :provenience_place, provenience_place_code = :provenience_place_code, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            'id' => $recordId,
            'record_type' => $recordType,
            'booking_reference' => $bookingReference,
            'arrival_date' => $arrivalDate,
            'departure_date' => $departureDate,
            'expected_guests' => count($normalizedGuests),
            'reserved_rooms' => $reservedRooms,
            'booking_channel' => $bookingChannel,
            'daily_price' => $dailyPrice,
            'provenience_state_label' => $provenienceStateLabel,
            'provenience_state_code' => $provenienceStateCode,
            'provenience_province' => $provenienceProvince !== '' ? $provenienceProvince : null,
            'provenience_place' => $proveniencePlace,
            'provenience_place_code' => $proveniencePlaceCode,
        ]);

        $pdo->prepare('DELETE FROM anagrafica_guests WHERE record_id = :record_id')->execute(['record_id' => $recordId]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO anagrafica_records (uuid, record_type, booking_reference, ross_prenotazione_idswh, arrival_date, departure_date, expected_guests, reserved_rooms, booking_channel, daily_price, provenience_state_label, provenience_state_code, provenience_province, provenience_place, provenience_place_code, status, created_at, updated_at) VALUES (:uuid, :record_type, :booking_reference, :ross_prenotazione_idswh, :arrival_date, :departure_date, :expected_guests, :reserved_rooms, :booking_channel, :daily_price, :provenience_state_label, :provenience_state_code, :provenience_province, :provenience_place, :provenience_place_code, :status, NOW(), NOW())');
        $stmt->execute([
            'uuid' => $recordUuid,
            'record_type' => $recordType,
            'booking_reference' => $bookingReference,
            'ross_prenotazione_idswh' => $bookingIdswh,
            'arrival_date' => $arrivalDate,
            'departure_date' => $departureDate,
            'expected_guests' => count($normalizedGuests),
            'reserved_rooms' => $reservedRooms,
            'booking_channel' => $bookingChannel,
            'daily_price' => $dailyPrice,
            'provenience_state_label' => $provenienceStateLabel,
            'provenience_state_code' => $provenienceStateCode,
            'provenience_province' => $provenienceProvince !== '' ? $provenienceProvince : null,
            'provenience_place' => $proveniencePlace,
            'provenience_place_code' => $proveniencePlaceCode,
            'status' => 'draft',
        ]);
        $recordId = (int) $pdo->lastInsertId();
    }

    $guestStmt = $pdo->prepare('INSERT INTO anagrafica_guests (record_id, guest_idswh, is_group_leader, leader_idswh, tipo_alloggiato_code, first_name, last_name, gender, birth_date, citizenship_label, citizenship_code, residence_state_label, residence_state_code, residence_province, residence_place, residence_place_code, birth_state_label, birth_state_code, birth_province, birth_place, birth_place_code, document_type, document_number, document_issue_date, document_expiry_date, document_issue_province, document_issue_place, document_issue_place_code, email, phone, tourism_type, transport_type, created_at, updated_at) VALUES (:record_id, :guest_idswh, :is_group_leader, :leader_idswh, :tipo_alloggiato_code, :first_name, :last_name, :gender, :birth_date, :citizenship_label, :citizenship_code, :residence_state_label, :residence_state_code, :residence_province, :residence_place, :residence_place_code, :birth_state_label, :birth_state_code, :birth_province, :birth_place, :birth_place_code, :document_type, :document_number, :document_issue_date, :document_expiry_date, :document_issue_province, :document_issue_place, :document_issue_place_code, :email, :phone, :tourism_type, :transport_type, NOW(), NOW())');

    $leaderIdswh = null;
    foreach ($normalizedGuests as $index => $guest) {
        $guestIdswh = strtoupper(substr($bookingIdswh . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT), 0, 20));
        if ($index === 0) {
            $leaderIdswh = $guestIdswh;
        }

        $guestStmt->execute([
            'record_id' => $recordId,
            'guest_idswh' => $guestIdswh,
            'is_group_leader' => $index === 0 ? 1 : 0,
            'leader_idswh' => $index === 0 ? null : $leaderIdswh,
            'tipo_alloggiato_code' => $guest['tipo_alloggiato_code'],
            'first_name' => $guest['first_name'],
            'last_name' => $guest['last_name'],
            'gender' => $guest['gender'],
            'birth_date' => $guest['birth_date'],
            'citizenship_label' => $guest['citizenship_label'],
            'citizenship_code' => $guest['citizenship_code'],
            'residence_state_label' => $guest['residence_state_label'],
            'residence_state_code' => $guest['residence_state_code'],
            'residence_province' => $guest['residence_province'],
            'residence_place' => $guest['residence_place'],
            'residence_place_code' => $guest['residence_place_code'],
            'birth_state_label' => $guest['birth_state_label'],
            'birth_state_code' => $guest['birth_state_code'],
            'birth_province' => $guest['birth_province'],
            'birth_place' => $guest['birth_place'],
            'birth_place_code' => $guest['birth_place_code'],
            'document_type' => $guest['document_type'],
            'document_number' => $guest['document_number'],
            'document_issue_date' => $guest['document_issue_date'],
            'document_expiry_date' => $guest['document_expiry_date'],
            'document_issue_province' => $guest['document_issue_province'],
            'document_issue_place' => $guest['document_issue_place'],
            'document_issue_place_code' => $guest['document_issue_place_code'],
            'email' => $guest['email'],
            'phone' => $guest['phone'],
            'tourism_type' => $guest['tourism_type'],
            'transport_type' => $guest['transport_type'],
        ]);
    }

    $pdo->commit();

    $query = $isEdit ? 'updated=' . $recordId : 'created=' . $recordId;
    redirect_to(admin_url('anagrafica.php?' . $query), 'success', $isEdit ? 'Anagrafica aggiornata correttamente.' : 'Nuova anagrafica salvata correttamente.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $target = common_redirect_target(max(0, (int) ($_POST['record_id'] ?? 0)), max(0, (int) ($_POST['record_id'] ?? 0)) > 0);
    redirect_to($target, 'error', "Errore durante il salvataggio dell'anagrafica: " . $e->getMessage());
}
