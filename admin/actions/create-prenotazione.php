<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . admin_url('new-prenotazione.php'));
    exit;
}

verify_csrf();

$stayPeriod = trim((string) ($_POST['stay_period'] ?? ''));
$roomType = trim((string) ($_POST['room_type'] ?? ''));
$adults = (int) ($_POST['adults'] ?? 0);
$children = (int) ($_POST['children_count'] ?? 0);
$customerName = trim((string) ($_POST['customer_name'] ?? ''));
$customerEmail = trim((string) ($_POST['customer_email'] ?? ''));
$customerPhone = trim((string) ($_POST['customer_phone'] ?? ''));
$status = trim((string) ($_POST['status'] ?? 'confermata'));
$source = trim((string) ($_POST['source'] ?? 'manual_admin'));
$externalReference = trim((string) ($_POST['external_reference'] ?? ''));
$notes = trim((string) ($_POST['notes'] ?? ''));

$allowedStatuses = ['confermata', 'in_attesa', 'annullata'];

if ($stayPeriod === '' || $roomType === '' || $customerName === '') {
    set_flash('error', 'Compila tutti i campi obbligatori. L’email può restare vuota se non disponibile.');
    header('Location: ' . admin_url('new-prenotazione.php'));
    exit;
}

$normalizedEmail = normalize_optional_email($customerEmail);
if ($customerEmail !== '' && $normalizedEmail === null) {
    set_flash('error', 'Inserisci un indirizzo email valido oppure lascia il campo vuoto.');
    header('Location: ' . admin_url('new-prenotazione.php'));
    exit;
}

if ($adults < 1 || $children < 0) {
    set_flash('error', 'Controlla il numero di adulti e bambini.');
    header('Location: ' . admin_url('new-prenotazione.php'));
    exit;
}

if (!in_array($status, $allowedStatuses, true)) {
    $status = 'confermata';
}

if ($source === '') {
    $source = 'manual_admin';
}

$dates = parse_stay_period_dates($stayPeriod);

$stmt = $pdo->prepare(
    'INSERT INTO prenotazioni (
        booking_request_id,
        customer_name,
        customer_email,
        email_missing,
        customer_phone,
        stay_period,
        check_in,
        check_out,
        room_type,
        adults,
        children_count,
        notes,
        status,
        source,
        external_reference,
        raw_payload,
        created_at,
        updated_at
    ) VALUES (
        NULL,
        :customer_name,
        :customer_email,
        :email_missing,
        :customer_phone,
        :stay_period,
        :check_in,
        :check_out,
        :room_type,
        :adults,
        :children_count,
        :notes,
        :status,
        :source,
        :external_reference,
        :raw_payload,
        NOW(),
        NOW()
    )'
);

$rawPayload = json_encode([
    'created_manually' => true,
    'created_by' => current_admin()['email'] ?? null,
], JSON_UNESCAPED_UNICODE);

$stmt->execute([
    'customer_name' => $customerName,
    'customer_email' => $normalizedEmail,
    'email_missing' => $normalizedEmail === null ? 1 : 0,
    'customer_phone' => normalize_optional_phone($customerPhone),
    'stay_period' => $stayPeriod,
    'check_in' => $dates['check_in'],
    'check_out' => $dates['check_out'],
    'room_type' => $roomType,
    'adults' => $adults,
    'children_count' => $children,
    'notes' => $notes !== '' ? $notes : null,
    'status' => $status,
    'source' => $source,
    'external_reference' => $externalReference !== '' ? $externalReference : null,
    'raw_payload' => $rawPayload,
]);

set_flash('success', 'Prenotazione aggiunta correttamente.');
header('Location: ' . admin_url('index.php#registered-bookings'));
exit;
