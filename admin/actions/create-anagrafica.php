<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . admin_url('anagrafica.php'));
    exit;
}

verify_csrf();

function redirect_to(string $url, string $type, string $message): never
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

try {
    $tableReady = (bool) $pdo->query("SHOW TABLES LIKE 'anagrafica_records'")->fetchColumn();
    if (!$tableReady) {
        redirect_to(admin_url('anagrafica.php?new=1'), 'error', 'Esegui prima la migration SQL della sezione anagrafica.');
    }

    $recordId = max(0, (int) ($_POST['record_id'] ?? 0));
    $isEdit = $recordId > 0;
    $recordType = ($_POST['record_type'] ?? 'single') === 'group' ? 'group' : 'single';
    $bookingReference = trim((string) ($_POST['booking_reference'] ?? ''));
    $arrivalDate = parse_date_input($_POST['arrival_date'] ?? null);
    $departureDate = parse_date_input($_POST['departure_date'] ?? null);
    $expectedGuests = max(1, (int) ($_POST['expected_guests'] ?? 1));
    $reservedRooms = max(1, (int) ($_POST['reserved_rooms'] ?? 1));
    $bookingChannel = trim((string) ($_POST['booking_channel'] ?? ''));
    $dailyPrice = trim((string) ($_POST['daily_price'] ?? ''));
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
        $bookingIdswh = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $bookingReference ?: ('ANA' . date('YmdHis'))), 0, 20));
        if ($bookingIdswh === '') {
            $bookingIdswh = 'ANA' . date('YmdHis');
        }
    }

    $pdo->beginTransaction();

    if ($isEdit) {
        $stmt = $pdo->prepare('UPDATE anagrafica_records SET record_type = :record_type, booking_reference = :booking_reference, arrival_date = :arrival_date, departure_date = :departure_date, expected_guests = :expected_guests, reserved_rooms = :reserved_rooms, booking_channel = :booking_channel, daily_price = :daily_price, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            'id' => $recordId,
            'record_type' => $recordType,
            'booking_reference' => $bookingReference !== '' ? $bookingReference : null,
            'arrival_date' => $arrivalDate,
            'departure_date' => $departureDate,
            'expected_guests' => $expectedGuests,
            'reserved_rooms' => $reservedRooms,
            'booking_channel' => $bookingChannel !== '' ? $bookingChannel : null,
            'daily_price' => $dailyPrice !== '' ? $dailyPrice : null,
        ]);

        $pdo->prepare('DELETE FROM anagrafica_guests WHERE record_id = :record_id')->execute(['record_id' => $recordId]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO anagrafica_records (uuid, record_type, booking_reference, ross_prenotazione_idswh, arrival_date, departure_date, expected_guests, reserved_rooms, booking_channel, daily_price, status, created_at, updated_at) VALUES (:uuid, :record_type, :booking_reference, :ross_prenotazione_idswh, :arrival_date, :departure_date, :expected_guests, :reserved_rooms, :booking_channel, :daily_price, :status, NOW(), NOW())');
        $stmt->execute([
            'uuid' => $recordUuid,
            'record_type' => $recordType,
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
    }

    $guestStmt = $pdo->prepare('INSERT INTO anagrafica_guests (record_id, guest_idswh, is_group_leader, leader_idswh, first_name, last_name, gender, birth_date, citizenship_label, residence_province, residence_place, document_type, document_number, document_issue_date, document_expiry_date, document_issue_place, email, phone, tourism_type, transport_type, created_at, updated_at) VALUES (:record_id, :guest_idswh, :is_group_leader, :leader_idswh, :first_name, :last_name, :gender, :birth_date, :citizenship_label, :residence_province, :residence_place, :document_type, :document_number, :document_issue_date, :document_expiry_date, :document_issue_place, :email, :phone, :tourism_type, :transport_type, NOW(), NOW())');

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
