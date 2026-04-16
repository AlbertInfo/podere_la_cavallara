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

function normalize_optional_string(mixed $value, int $maxLength = 0): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    if ($maxLength > 0) {
        $value = mb_substr($value, 0, $maxLength, 'UTF-8');
    }
    return $value;
}

function normalize_decimal_string(mixed $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    $value = str_replace(',', '.', $value);
    if (!is_numeric($value)) {
        return null;
    }
    return number_format((float) $value, 2, '.', '');
}

function fallback_tipo_alloggiato(string $recordType, int $index): string
{
    if ($index === 0) {
        return $recordType === 'group' ? '18' : '16';
    }
    return '20';
}

try {
    $tableReady = (bool) $pdo->query("SHOW TABLES LIKE 'anagrafica_records'")->fetchColumn();
    if (!$tableReady) {
        redirect_to(admin_url('anagrafica.php?new=1'), 'error', 'Esegui prima la migration SQL della sezione anagrafica.');
    }

    $recordId = max(0, (int) ($_POST['record_id'] ?? 0));
    $isEdit = $recordId > 0;
    $recordType = ($_POST['record_type'] ?? 'single') === 'group' ? 'group' : 'single';
    $bookingReference = normalize_optional_string($_POST['booking_reference'] ?? null, 80);
    $bookingReceivedDate = parse_date_input($_POST['booking_received_date'] ?? null) ?? date('Y-m-d');
    $arrivalDate = parse_date_input($_POST['arrival_date'] ?? null);
    $departureDate = parse_date_input($_POST['departure_date'] ?? null);
    $expectedGuests = max(1, (int) ($_POST['expected_guests'] ?? 1));
    $reservedRooms = max(1, (int) ($_POST['reserved_rooms'] ?? 1));
    $bookingChannel = normalize_optional_string($_POST['booking_channel'] ?? null, 60);
    $dailyPrice = normalize_decimal_string($_POST['daily_price'] ?? null);
    $bookingProvenienceStateCode = normalize_optional_string($_POST['booking_provenience_state_code'] ?? null, 20);
    $bookingProveniencePlaceCode = normalize_optional_string($_POST['booking_provenience_place_code'] ?? null, 30);
    $guests = $_POST['guests'] ?? [];

    if (!$arrivalDate || !$departureDate) {
        redirect_to(admin_url('anagrafica.php?' . ($isEdit ? 'edit=' . $recordId : 'new=1')), 'error', 'Inserisci date di arrivo e partenza valide.');
    }
    if (!is_array($guests) || count($guests) === 0) {
        redirect_to(admin_url('anagrafica.php?' . ($isEdit ? 'edit=' . $recordId : 'new=1')), 'error', 'Inserisci almeno un ospite.');
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
        $bookingIdswh = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string) ($bookingReference ?: ('ANA' . date('YmdHis')))), 0, 20));
        if ($bookingIdswh === '') {
            $bookingIdswh = 'ANA' . date('YmdHis');
        }
    }

    $pdo->beginTransaction();

    if ($isEdit) {
        $stmt = $pdo->prepare('UPDATE anagrafica_records SET record_type = :record_type, booking_reference = :booking_reference, booking_received_date = :booking_received_date, arrival_date = :arrival_date, departure_date = :departure_date, expected_guests = :expected_guests, reserved_rooms = :reserved_rooms, booking_channel = :booking_channel, booking_provenience_state_code = :booking_provenience_state_code, booking_provenience_place_code = :booking_provenience_place_code, daily_price = :daily_price, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            'id' => $recordId,
            'record_type' => $recordType,
            'booking_reference' => $bookingReference,
            'booking_received_date' => $bookingReceivedDate,
            'arrival_date' => $arrivalDate,
            'departure_date' => $departureDate,
            'expected_guests' => $expectedGuests,
            'reserved_rooms' => $reservedRooms,
            'booking_channel' => $bookingChannel,
            'booking_provenience_state_code' => $bookingProvenienceStateCode,
            'booking_provenience_place_code' => $bookingProveniencePlaceCode,
            'daily_price' => $dailyPrice,
        ]);

        $pdo->prepare('DELETE FROM anagrafica_guests WHERE record_id = :record_id')->execute(['record_id' => $recordId]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO anagrafica_records (uuid, record_type, booking_reference, booking_received_date, ross_prenotazione_idswh, arrival_date, departure_date, expected_guests, reserved_rooms, booking_channel, booking_provenience_state_code, booking_provenience_place_code, daily_price, status, created_at, updated_at) VALUES (:uuid, :record_type, :booking_reference, :booking_received_date, :ross_prenotazione_idswh, :arrival_date, :departure_date, :expected_guests, :reserved_rooms, :booking_channel, :booking_provenience_state_code, :booking_provenience_place_code, :daily_price, :status, NOW(), NOW())');
        $stmt->execute([
            'uuid' => $recordUuid,
            'record_type' => $recordType,
            'booking_reference' => $bookingReference,
            'booking_received_date' => $bookingReceivedDate,
            'ross_prenotazione_idswh' => $bookingIdswh,
            'arrival_date' => $arrivalDate,
            'departure_date' => $departureDate,
            'expected_guests' => $expectedGuests,
            'reserved_rooms' => $reservedRooms,
            'booking_channel' => $bookingChannel,
            'booking_provenience_state_code' => $bookingProvenienceStateCode,
            'booking_provenience_place_code' => $bookingProveniencePlaceCode,
            'daily_price' => $dailyPrice,
            'status' => 'draft',
        ]);
        $recordId = (int) $pdo->lastInsertId();
    }

    $guestStmt = $pdo->prepare('INSERT INTO anagrafica_guests (record_id, guest_idswh, is_group_leader, leader_idswh, tipoalloggiato_code, first_name, last_name, gender, birth_date, birth_state_code, birth_city_code, citizenship_label, citizenship_code, residence_province, residence_place, residence_state_code, residence_place_code, document_type, document_number, document_issue_date, document_expiry_date, document_issue_place, email, phone, tourism_type, transport_type, guest_booking_channel, education_level, profession, tax_exemption_code, created_at, updated_at) VALUES (:record_id, :guest_idswh, :is_group_leader, :leader_idswh, :tipoalloggiato_code, :first_name, :last_name, :gender, :birth_date, :birth_state_code, :birth_city_code, :citizenship_label, :citizenship_code, :residence_province, :residence_place, :residence_state_code, :residence_place_code, :document_type, :document_number, :document_issue_date, :document_expiry_date, :document_issue_place, :email, :phone, :tourism_type, :transport_type, :guest_booking_channel, :education_level, :profession, :tax_exemption_code, NOW(), NOW())');

    $index = 0;
    $leaderIdswh = null;

    foreach ($guests as $guest) {
        if (!is_array($guest)) {
            continue;
        }

        $firstName = trim((string) ($guest['first_name'] ?? ''));
        $lastName = trim((string) ($guest['last_name'] ?? ''));
        if ($firstName === '' || $lastName === '') {
            continue;
        }

        $existingGuestIdswh = strtoupper(substr(trim((string) ($guest['guest_idswh'] ?? '')), 0, 20));
        $guestIdswh = $existingGuestIdswh !== '' ? $existingGuestIdswh : strtoupper(substr($bookingIdswh . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT), 0, 20));
        if ($index === 0) {
            $leaderIdswh = $guestIdswh;
        }

        $tipoAlloggiatoCode = normalize_optional_string($guest['tipoalloggiato_code'] ?? null, 2) ?? fallback_tipo_alloggiato($recordType, $index);
        $leaderReference = $index === 0 ? null : ($leaderIdswh ?: null);

        $guestStmt->execute([
            'record_id' => $recordId,
            'guest_idswh' => $guestIdswh,
            'is_group_leader' => $index === 0 ? 1 : 0,
            'leader_idswh' => $leaderReference,
            'tipoalloggiato_code' => $tipoAlloggiatoCode,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'gender' => in_array(($guest['gender'] ?? 'M'), ['M', 'F'], true) ? $guest['gender'] : 'M',
            'birth_date' => parse_date_input($guest['birth_date'] ?? null),
            'birth_state_code' => normalize_optional_string($guest['birth_state_code'] ?? null, 20),
            'birth_city_code' => normalize_optional_string($guest['birth_city_code'] ?? null, 20),
            'citizenship_label' => normalize_optional_string($guest['citizenship_label'] ?? null, 100),
            'citizenship_code' => normalize_optional_string($guest['citizenship_code'] ?? null, 20),
            'residence_province' => normalize_optional_string($guest['residence_province'] ?? null, 100),
            'residence_place' => normalize_optional_string($guest['residence_place'] ?? null, 120),
            'residence_state_code' => normalize_optional_string($guest['residence_state_code'] ?? null, 20),
            'residence_place_code' => normalize_optional_string($guest['residence_place_code'] ?? null, 30),
            'document_type' => normalize_optional_string($guest['document_type'] ?? null, 30),
            'document_number' => normalize_optional_string($guest['document_number'] ?? null, 50),
            'document_issue_date' => parse_date_input($guest['document_issue_date'] ?? null),
            'document_expiry_date' => parse_date_input($guest['document_expiry_date'] ?? null),
            'document_issue_place' => normalize_optional_string($guest['document_issue_place'] ?? null, 120),
            'email' => normalize_optional_string($guest['email'] ?? null, 190),
            'phone' => normalize_optional_string($guest['phone'] ?? null, 40),
            'tourism_type' => normalize_optional_string($guest['tourism_type'] ?? null, 80),
            'transport_type' => normalize_optional_string($guest['transport_type'] ?? null, 80),
            'guest_booking_channel' => normalize_optional_string($guest['guest_booking_channel'] ?? null, 60),
            'education_level' => normalize_optional_string($guest['education_level'] ?? null, 80),
            'profession' => normalize_optional_string($guest['profession'] ?? null, 120),
            'tax_exemption_code' => normalize_optional_string($guest['tax_exemption_code'] ?? null, 30),
        ]);
        $index++;
    }

    if ($index === 0) {
        $pdo->rollBack();
        redirect_to(admin_url('anagrafica.php?' . ($isEdit ? 'edit=' . $recordId : 'new=1')), 'error', 'Inserisci almeno un ospite valido.');
    }

    $pdo->prepare('UPDATE anagrafica_records SET expected_guests = :expected_guests, updated_at = NOW() WHERE id = :id')->execute([
        'expected_guests' => $index,
        'id' => $recordId,
    ]);

    $pdo->commit();

    $query = $isEdit ? 'updated=' . $recordId : 'created=' . $recordId;
    redirect_to(admin_url('anagrafica.php?' . $query), 'success', $isEdit ? 'Anagrafica aggiornata correttamente.' : 'Nuova anagrafica salvata correttamente.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $target = admin_url('anagrafica.php?' . ((max(0, (int) ($_POST['record_id'] ?? 0)) > 0) ? 'edit=' . (int) $_POST['record_id'] : 'new=1'));
    redirect_to($target, 'error', "Errore durante il salvataggio dell'anagrafica: " . $e->getMessage());
}
