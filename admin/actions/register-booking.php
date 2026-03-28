<?php
require_once __DIR__ . '/../includes/auth.php';
require_admin();
verify_csrf();

$id = (int) ($_POST['booking_request_id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM booking_requests WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    if (wants_json_response()) json_response(['success' => false, 'message' => 'Richiesta di prenotazione non trovata.'], 404);
    set_flash('error', 'Richiesta di prenotazione non trovata.');
    header('Location: ' . admin_url('index.php#booking-requests'));
    exit;
}

$check = $pdo->prepare('SELECT id FROM prenotazioni WHERE booking_request_id = :booking_request_id LIMIT 1');
$check->execute(['booking_request_id' => $id]);
if ($check->fetchColumn()) {
    if (wants_json_response()) json_response(['success' => false, 'message' => 'Questa richiesta è già stata registrata come prenotazione.'], 409);
    set_flash('error', 'Questa richiesta è già stata registrata come prenotazione.');
    header('Location: ' . admin_url('index.php#registered-bookings'));
    exit;
}

$stmt = $pdo->prepare('INSERT INTO prenotazioni (
    booking_request_id, customer_name, customer_email, customer_phone, stay_period, room_type,
    adults, children_count, notes, status, source, external_reference, raw_payload
) VALUES (
    :booking_request_id, :customer_name, :customer_email, :customer_phone, :stay_period, :room_type,
    :adults, :children_count, :notes, :status, :source, :external_reference, :raw_payload
)');

$stmt->execute([
    'booking_request_id' => $row['id'],
    'customer_name' => $row['name_booking'],
    'customer_email' => $row['email_booking'],
    'customer_phone' => $row['phone_booking'] ?? null,
    'stay_period' => $row['date_booking'],
    'room_type' => $row['rooms_booking'],
    'adults' => $row['adults_booking'],
    'children_count' => $row['childs_booking'],
    'notes' => $row['message_booking'] ?? null,
    'status' => 'confermata',
    'source' => 'website_admin',
    'external_reference' => $row['external_reference'] ?? null,
    'raw_payload' => $row['raw_payload'] ?? null,
]);

if (wants_json_response()) {
    json_response(['success' => true, 'message' => 'Richiesta convertita in prenotazione confermata.']);
}

set_flash('success', 'Richiesta convertita in prenotazione confermata.');
header('Location: ' . admin_url('index.php#registered-bookings'));
exit;
