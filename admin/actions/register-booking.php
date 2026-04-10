<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/booking-confirmation.php';
require_admin();
verify_csrf();

$bookingRequestId = (int) ($_POST['booking_request_id'] ?? 0);
if ($bookingRequestId <= 0) {
    set_flash('error', 'Richiesta non valida.');
    header('Location: ' . admin_url('index.php') . '#booking-requests');
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM booking_requests WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $bookingRequestId]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    set_flash('error', 'Richiesta non trovata.');
    header('Location: ' . admin_url('index.php') . '#booking-requests');
    exit;
}

$language = admin_infer_request_language($request);
[$checkInIso, $checkOutIso] = admin_extract_stay_dates((string) ($request['date_booking'] ?? ''));
$customerEmail = trim((string) ($request['email_booking'] ?? ''));
$customerPhone = trim((string) ($request['phone_booking'] ?? ''));
$adults = (int) ($request['adults_booking'] ?? 0);
$children = (int) ($request['childs_booking'] ?? 0);

$columns = [
    'booking_request_id',
    'customer_name',
    'customer_email',
    'customer_phone',
    'stay_period',
    'room_type',
    'adults',
    'children_count',
    'notes',
    'status',
    'source',
    'external_reference',
    'raw_payload',
];

$params = [
    'booking_request_id' => (int) $request['id'],
    'customer_name' => (string) ($request['name_booking'] ?? ''),
    'customer_email' => $customerEmail !== '' ? $customerEmail : null,
    'customer_phone' => $customerPhone !== '' ? $customerPhone : null,
    'stay_period' => (string) ($request['date_booking'] ?? ''),
    'room_type' => (string) ($request['rooms_booking'] ?? ''),
    'adults' => $adults,
    'children_count' => $children,
    'notes' => trim((string) ($request['message_booking'] ?? '')) !== '' ? (string) $request['message_booking'] : null,
    'status' => 'confermata',
    'source' => (string) ($request['source'] ?? 'website_form'),
    'external_reference' => null,
    'raw_payload' => json_encode([
        'booking_request' => $request,
        'registered_by' => current_admin()['email'] ?? null,
        'detected_language' => $language,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
];

$optionalColumns = [
    'guest_language' => $language,
    'check_in' => $checkInIso,
    'check_out' => $checkOutIso,
    'email_missing' => $customerEmail === '' ? 1 : 0,
    'occupancy_unknown' => ($adults === 0 && $children === 0) ? 1 : 0,
    'created_at' => date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s'),
];

foreach ($optionalColumns as $column => $value) {
    if (admin_db_has_column($pdo, 'prenotazioni', $column)) {
        $columns[] = $column;
        $params[$column] = $value;
    }
}

$placeholders = array_map(static fn(string $column): string => ':' . $column, $columns);
$sql = sprintf(
    'INSERT INTO prenotazioni (%s) VALUES (%s)',
    implode(', ', $columns),
    implode(', ', $placeholders)
);

try {
    $pdo->beginTransaction();

    $insert = $pdo->prepare($sql);
    $insert->execute($params);

    $prenotazioneId = (int) $pdo->lastInsertId();

    $delete = $pdo->prepare('DELETE FROM booking_requests WHERE id = :id');
    $delete->execute(['id' => $bookingRequestId]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    set_flash('error', 'Errore durante la registrazione della prenotazione: ' . $e->getMessage());
    header('Location: ' . admin_url('index.php') . '#booking-requests');
    exit;
}

$mailResult = admin_send_booking_confirmation_email([
    'customer_name' => (string) ($request['name_booking'] ?? ''),
    'customer_email' => $customerEmail,
    'stay_period' => (string) ($request['date_booking'] ?? ''),
    'room_type' => (string) ($request['rooms_booking'] ?? ''),
    'adults' => $adults,
    'children_count' => $children,
], $language);

$updateFields = [];
$updateParams = ['id' => $prenotazioneId];

if (admin_db_has_column($pdo, 'prenotazioni', 'booking_confirmation_language')) {
    $updateFields[] = 'booking_confirmation_language = :booking_confirmation_language';
    $updateParams['booking_confirmation_language'] = $mailResult['language'] ?? $language;
}

if (admin_db_has_column($pdo, 'prenotazioni', 'booking_confirmation_sent_at') && !empty($mailResult['success'])) {
    $updateFields[] = 'booking_confirmation_sent_at = NOW()';
}

if (admin_db_has_column($pdo, 'prenotazioni', 'booking_confirmation_error')) {
    $updateFields[] = 'booking_confirmation_error = :booking_confirmation_error';
    $updateParams['booking_confirmation_error'] = !empty($mailResult['success']) ? null : (string) ($mailResult['message'] ?? 'Invio email non riuscito.');
}

if ($updateFields !== []) {
    $updateSql = 'UPDATE prenotazioni SET ' . implode(', ', $updateFields) . ' WHERE id = :id LIMIT 1';
    $updateStmt = $pdo->prepare($updateSql);
    $updateStmt->execute($updateParams);
}

if (!empty($mailResult['success'])) {
    set_flash('success', 'Richiesta trasformata in prenotazione con successo. Email di conferma inviata al cliente in lingua ' . strtoupper($language) . '.');
} elseif (!empty($mailResult['skipped'])) {
    set_flash('success', 'Richiesta trasformata in prenotazione con successo. Nessuna email inviata al cliente perché l’indirizzo email non è disponibile o non è valido.');
} else {
    set_flash('success', 'Richiesta trasformata in prenotazione con successo, ma l’email di conferma non è stata inviata. Motivo: ' . ($mailResult['message'] ?? 'errore sconosciuto'));
}

header('Location: ' . admin_url('index.php') . '#registered-bookings');
exit;
