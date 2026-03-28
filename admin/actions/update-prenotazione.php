<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_admin();
verify_csrf();

$id = (int)($_POST['prenotazione_id'] ?? 0);
if ($id <= 0) {
    set_flash('error', 'Prenotazione non valida.');
    header('Location: ' . admin_url('index.php') . '#registered-bookings');
    exit;
}

$data = [
    'customer_name' => trim((string)($_POST['customer_name'] ?? '')),
    'customer_email' => trim((string)($_POST['customer_email'] ?? '')),
    'customer_phone' => trim((string)($_POST['customer_phone'] ?? '')),
    'stay_period' => trim((string)($_POST['stay_period'] ?? '')),
    'room_type' => trim((string)($_POST['room_type'] ?? '')),
    'adults' => (int)($_POST['adults'] ?? 0),
    'children_count' => (int)($_POST['children_count'] ?? 0),
    'notes' => trim((string)($_POST['notes'] ?? '')),
    'status' => trim((string)($_POST['status'] ?? 'confermata')),
    'source' => trim((string)($_POST['source'] ?? 'website_admin')),
    'external_reference' => trim((string)($_POST['external_reference'] ?? '')),
];

if ($data['customer_name'] === '' || $data['stay_period'] === '' || $data['room_type'] === '' || $data['customer_email'] === '') {
    set_flash('error', 'Compila tutti i campi obbligatori della prenotazione.');
    header('Location: ' . admin_url('edit-prenotazione.php?id=' . $id));
    exit;
}

if (!filter_var($data['customer_email'], FILTER_VALIDATE_EMAIL)) {
    set_flash('error', 'Inserisci un indirizzo email valido.');
    header('Location: ' . admin_url('edit-prenotazione.php?id=' . $id));
    exit;
}

if (!in_array($data['status'], ['confermata', 'in_attesa', 'annullata'], true)) {
    set_flash('error', 'Stato prenotazione non valido.');
    header('Location: ' . admin_url('edit-prenotazione.php?id=' . $id));
    exit;
}

$stmt = $pdo->prepare('UPDATE prenotazioni SET
    customer_name = :customer_name,
    customer_email = :customer_email,
    customer_phone = :customer_phone,
    stay_period = :stay_period,
    room_type = :room_type,
    adults = :adults,
    children_count = :children_count,
    notes = :notes,
    status = :status,
    source = :source,
    external_reference = :external_reference,
    updated_at = NOW()
WHERE id = :id LIMIT 1');

$stmt->execute([
    'customer_name' => $data['customer_name'],
    'customer_email' => $data['customer_email'],
    'customer_phone' => $data['customer_phone'] !== '' ? $data['customer_phone'] : null,
    'stay_period' => $data['stay_period'],
    'room_type' => $data['room_type'],
    'adults' => $data['adults'],
    'children_count' => $data['children_count'],
    'notes' => $data['notes'] !== '' ? $data['notes'] : null,
    'status' => $data['status'],
    'source' => $data['source'],
    'external_reference' => $data['external_reference'] !== '' ? $data['external_reference'] : null,
    'id' => $id,
]);

set_flash('success', 'Prenotazione aggiornata correttamente.');
header('Location: ' . admin_url('index.php') . '#registered-bookings');
exit;
