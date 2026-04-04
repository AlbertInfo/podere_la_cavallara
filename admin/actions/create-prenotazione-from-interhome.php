<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

verify_csrf();

$key = (string) ($_POST['import_key'] ?? '');
$rows = $_SESSION['interhome_import_rows'] ?? [];

if ($key === '' || !isset($rows[$key])) {
    set_flash('error', 'Prenotazione Interhome non trovata o sessione scaduta.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$data = [
    'customer_name' => trim((string) ($_POST['customer_name'] ?? '')),
    'customer_email' => trim((string) ($_POST['customer_email'] ?? '')),
    'customer_phone' => trim((string) ($_POST['customer_phone'] ?? '')),
    'stay_period' => trim((string) ($_POST['stay_period'] ?? '')),
    'room_type' => trim((string) ($_POST['room_type'] ?? '')),
    'adults' => (int) ($_POST['adults'] ?? 0),
    'children_count' => (int) ($_POST['children_count'] ?? 0),
    'notes' => trim((string) ($_POST['notes'] ?? '')),
    'status' => trim((string) ($_POST['status'] ?? 'confermata')),
    'source' => trim((string) ($_POST['source'] ?? 'interhome_pdf')),
    'external_reference' => trim((string) ($_POST['external_reference'] ?? '')),
];

if ($data['customer_name'] === '' || $data['stay_period'] === '' || $data['room_type'] === '') {
    set_flash('error', 'Compila i campi obbligatori della prenotazione prima del salvataggio.');
    header('Location: ' . admin_url('import-interhome-review.php?row=' . urlencode($key)));
    exit;
}

if ($data['customer_email'] !== '' && !filter_var($data['customer_email'], FILTER_VALIDATE_EMAIL)) {
    set_flash('error', 'L’indirizzo email non è valido.');
    header('Location: ' . admin_url('import-interhome-review.php?row=' . urlencode($key)));
    exit;
}

if (!in_array($data['status'], ['confermata', 'in_attesa', 'annullata'], true)) {
    set_flash('error', 'Stato prenotazione non valido.');
    header('Location: ' . admin_url('import-interhome-review.php?row=' . urlencode($key)));
    exit;
}

if ($data['external_reference'] !== '') {
    $dup = $pdo->prepare('SELECT id FROM prenotazioni WHERE external_reference = :external_reference LIMIT 1');
    $dup->execute(['external_reference' => $data['external_reference']]);
    if ($dup->fetchColumn()) {
        set_flash('error', 'Questa prenotazione Interhome è già presente nel gestionale.');
        unset($_SESSION['interhome_import_rows'][$key]);
        header('Location: ' . admin_url('import-interhome-pdf.php'));
        exit;
    }
}

$stmt = $pdo->prepare('INSERT INTO prenotazioni (
    booking_request_id,
    customer_name,
    customer_email,
    customer_phone,
    stay_period,
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
    :customer_phone,
    :stay_period,
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
)');

$rawPayload = json_encode($rows[$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$stmt->execute([
    'customer_name' => $data['customer_name'],
    'customer_email' => $data['customer_email'] !== '' ? $data['customer_email'] : null,
    'customer_phone' => $data['customer_phone'] !== '' ? $data['customer_phone'] : null,
    'stay_period' => $data['stay_period'],
    'room_type' => $data['room_type'],
    'adults' => $data['adults'],
    'children_count' => $data['children_count'],
    'notes' => $data['notes'] !== '' ? $data['notes'] : null,
    'status' => $data['status'],
    'source' => $data['source'],
    'external_reference' => $data['external_reference'] !== '' ? $data['external_reference'] : null,
    'raw_payload' => $rawPayload,
]);

unset($_SESSION['interhome_import_rows'][$key]);

set_flash('success', 'Prenotazione Interhome salvata correttamente.');
header('Location: ' . admin_url('index.php#registered-bookings'));
exit;
