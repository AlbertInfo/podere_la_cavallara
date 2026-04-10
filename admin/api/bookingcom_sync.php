<?php
require_once __DIR__ . '/../includes/auth.php';
require_admin();

if (!BOOKINGCOM_ENABLED) {
    set_flash('error', 'Integrazione Booking.com non attiva. Imposta BOOKINGCOM_ENABLED=true e configura le credenziali.');
    header('Location: ' . admin_url('index.php#bookingcom'));
    exit;
}

$xmlRequest = '<?xml version="1.0" encoding="UTF-8"?>
<request>
    <username>' . htmlspecialchars(BOOKINGCOM_USERNAME, ENT_XML1) . '</username>
    <password>' . htmlspecialchars(BOOKINGCOM_PASSWORD, ENT_XML1) . '</password>
    <hotel_id>' . htmlspecialchars(BOOKINGCOM_HOTEL_ID, ENT_XML1) . '</hotel_id>
</request>';

$ch = curl_init(BOOKINGCOM_ENDPOINT);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/xml'],
    CURLOPT_POSTFIELDS => $xmlRequest,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
]);

$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($response === false || $status >= 400) {
    set_flash('error', 'Errore durante l’import da Booking.com: ' . ($error ?: 'HTTP ' . $status));
    header('Location: ' . admin_url('index.php#bookingcom'));
    exit;
}

libxml_use_internal_errors(true);
$xml = simplexml_load_string($response);
if (!$xml) {
    set_flash('error', 'La risposta di Booking.com non è stata interpretata correttamente.');
    header('Location: ' . admin_url('index.php#bookingcom'));
    exit;
}

$imported = 0;
foreach ($xml->reservation as $reservation) {
    $externalReference = (string) ($reservation->id ?? '');
    if (!$externalReference) {
        continue;
    }

    $exists = $pdo->prepare('SELECT id FROM prenotazioni WHERE external_reference = :external_reference LIMIT 1');
    $exists->execute(['external_reference' => $externalReference]);
    if ($exists->fetchColumn()) {
        continue;
    }

    $guestName = trim((string) ($reservation->customer->name ?? 'Guest Booking.com'));
    $email = normalize_optional_email((string) ($reservation->customer->email ?? ''));
    $phone = normalize_optional_phone((string) ($reservation->customer->telephone ?? ''));
    $roomType = trim((string) ($reservation->room->name ?? 'Camera Booking.com'));
    $checkin = parse_booking_date((string) ($reservation->checkin ?? ''));
    $checkout = parse_booking_date((string) ($reservation->checkout ?? ''));
    $adults = (int) ($reservation->room->numberofguests ?? 1);
    $children = (int) ($reservation->room->children ?? 0);

    $stmt = $pdo->prepare('INSERT INTO prenotazioni (
        customer_name, customer_email, email_missing, customer_phone, stay_period, check_in, check_out, room_type,
        adults, children_count, notes, status, source, external_reference, raw_payload
    ) VALUES (
        :customer_name, :customer_email, :email_missing, :customer_phone, :stay_period, :check_in, :check_out, :room_type,
        :adults, :children_count, :notes, :status, :source, :external_reference, :raw_payload
    )');

    $stmt->execute([
        'customer_name' => $guestName,
        'customer_email' => $email,
        'email_missing' => $email === null ? 1 : 0,
        'customer_phone' => $phone,
        'stay_period' => trim((string) ($reservation->checkin ?? '') . ' - ' . (string) ($reservation->checkout ?? '')),
        'check_in' => $checkin,
        'check_out' => $checkout,
        'room_type' => $roomType,
        'adults' => $adults,
        'children_count' => $children,
        'notes' => 'Importata da Booking.com',
        'status' => 'confermata',
        'source' => 'booking_com',
        'external_reference' => $externalReference,
        'raw_payload' => json_encode($reservation, JSON_UNESCAPED_UNICODE),
    ]);

    $imported++;
}

set_flash('success', 'Import Booking.com completato. Prenotazioni importate: ' . $imported);
header('Location: ' . admin_url('index.php#registered-bookings'));
exit;
