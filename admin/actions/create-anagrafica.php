<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_admin();
verify_csrf();

function redirect_to(string $url, string $type, string $message): never
{
    set_flash($type, $message);
    header('Location: ' . $url);
    exit;
}

function parse_date_input(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    $formats = ['d/m/Y', 'Y-m-d'];
    foreach ($formats as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $value);
        if ($dt instanceof DateTimeImmutable) {
            return $dt->format('Y-m-d');
        }
    }
    return null;
}

try {
    $tableReady = (bool) $pdo->query("SHOW TABLES LIKE 'anagrafica_records'")->fetchColumn();
    if (!$tableReady) {
        redirect_to(admin_url('anagrafica.php?new=1'), 'error', 'Esegui prima la migration SQL della sezione anagrafica.');
    }

    $recordType = $_POST['record_type'] ?? 'single';
    $bookingReference = trim((string) ($_POST['booking_reference'] ?? ''));
    $arrivalDate = parse_date_input($_POST['arrival_date'] ?? null);
    $departureDate = parse_date_input($_POST['departure_date'] ?? null);
    $expectedGuests = max(1, (int) ($_POST['expected_guests'] ?? 1));
    $reservedRooms = max(1, (int) ($_POST['reserved_rooms'] ?? 1));
    $bookingChannel = trim((string) ($_POST['booking_channel'] ?? ''));
    $dailyPrice = trim((string) ($_POST['daily_price'] ?? ''));
    $guests = $_POST['guests'] ?? [];

    if (!$arrivalDate || !$departureDate) {
        redirect_to(admin_url('anagrafica.php?new=1'), 'error', 'Inserisci date di arrivo e partenza valide.');
    }
    if (!is_array($guests) || count($guests) === 0) {
        redirect_to(admin_url('anagrafica.php?new=1'), 'error', 'Inserisci almeno un ospite.');
    }

    $pdo->beginTransaction();

    $recordUuid = bin2hex(random_bytes(16));
    $bookingIdswh = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $bookingReference ?: ('ANA' . date('YmdHis'))), 0, 20));
    $stmt = $pdo->prepare('INSERT INTO anagrafica_records (uuid, record_type, booking_reference, ross_prenotazione_idswh, arrival_date, departure_date, expected_guests, reserved_rooms, booking_channel, daily_price, status, created_at, updated_at) VALUES (:uuid, :record_type, :booking_reference, :ross_prenotazione_idswh, :arrival_date, :departure_date, :expected_guests, :reserved_rooms, :booking_channel, :daily_price, :status, NOW(), NOW())');
    $stmt->execute([
        'uuid' => $recordUuid,
        'record_type' => $recordType === 'group' ? 'group' : 'single',
        'booking_reference' => $bookingReference !== '' ? $bookingReference : null,
        'ross_prenotazione_idswh' => $bookingIdswh,
        'arrival_date' => $arrivalDate,
        'departure_date' => $departureDate,
        'expected_guests' => $expectedGuests,
        'reserved_rooms' => $reservedRooms,
        'booking_channel' => $bookingChannel !== '' ? $bookingChannel : null,
        'daily_price' => $dailyPrice !== '' ? $dailyPrice : null,
        'status' => 'draft',
    ]);

    $recordId = (int) $pdo->lastInsertId();
    $leaderIdswh = null;
    $guestStmt = $pdo->prepare('INSERT INTO anagrafica_guests (record_id, guest_idswh, is_group_leader, leader_idswh, first_name, last_name, gender, birth_date, citizenship_label, residence_province, residence_place, document_type, document_number, document_issue_date, document_expiry_date, document_issue_place, email, phone, tourism_type, transport_type, created_at, updated_at) VALUES (:record_id, :guest_idswh, :is_group_leader, :leader_idswh, :first_name, :last_name, :gender, :birth_date, :citizenship_label, :residence_province, :residence_place, :document_type, :document_number, :document_issue_date, :document_expiry_date, :document_issue_place, :email, :phone, :tourism_type, :transport_type, NOW(), NOW())');

    $index = 0;
    foreach ($guests as $guest) {
        if (!is_array($guest)) {
            continue;
        }
        $firstName = trim((string) ($guest['first_name'] ?? ''));
        $lastName = trim((string) ($guest['last_name'] ?? ''));
        if ($firstName === '' || $lastName === '') {
            continue;
        }
        $guestIdswh = strtoupper(substr($bookingIdswh . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT), 0, 20));
        if ($index === 0) {
            $leaderIdswh = $guestIdswh;
        }
        $guestStmt->execute([
            'record_id' => $recordId,
            'guest_idswh' => $guestIdswh,
            'is_group_leader' => $index === 0 ? 1 : 0,
            'leader_idswh' => $index === 0 ? null : $leaderIdswh,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'gender' => in_array(($guest['gender'] ?? 'M'), ['M', 'F'], true) ? $guest['gender'] : 'M',
            'birth_date' => parse_date_input($guest['birth_date'] ?? null),
            'citizenship_label' => trim((string) ($guest['citizenship_label'] ?? '')),
            'residence_province' => trim((string) ($guest['residence_province'] ?? '')),
            'residence_place' => trim((string) ($guest['residence_place'] ?? '')),
            'document_type' => trim((string) ($guest['document_type'] ?? '')),
            'document_number' => trim((string) ($guest['document_number'] ?? '')),
            'document_issue_date' => parse_date_input($guest['document_issue_date'] ?? null),
            'document_expiry_date' => parse_date_input($guest['document_expiry_date'] ?? null),
            'document_issue_place' => trim((string) ($guest['document_issue_place'] ?? '')),
            'email' => ($guest['email'] ?? '') !== '' ? trim((string) $guest['email']) : null,
            'phone' => ($guest['phone'] ?? '') !== '' ? trim((string) $guest['phone']) : null,
            'tourism_type' => trim((string) ($guest['tourism_type'] ?? '')),
            'transport_type' => trim((string) ($guest['transport_type'] ?? '')),
        ]);
        $index++;
    }

    if ($index === 0) {
        $pdo->rollBack();
        redirect_to(admin_url('anagrafica.php?new=1'), 'error', 'Inserisci almeno un ospite valido.');
    }

    $pdo->commit();
    redirect_to(admin_url('anagrafica.php?created=' . $recordId), 'success', 'Nuova anagrafica salvata correttamente.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    redirect_to(admin_url('anagrafica.php?new=1'), 'error', "Errore durante il salvataggio dell'anagrafica: " . $e->getMessage());
}
