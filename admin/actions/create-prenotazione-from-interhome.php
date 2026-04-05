<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

verify_csrf();

$stayPeriod = trim((string)($_POST['stay_period'] ?? ''));
$roomType = trim((string)($_POST['room_type'] ?? ''));
$adults = (int)($_POST['adults'] ?? 0);
$children = (int)($_POST['children_count'] ?? 0);
$customerName = trim((string)($_POST['customer_name'] ?? ''));
$customerEmail = trim((string)($_POST['customer_email'] ?? ''));
$customerPhone = trim((string)($_POST['customer_phone'] ?? ''));
$status = trim((string)($_POST['status'] ?? 'confermata'));
$externalReference = trim((string)($_POST['external_reference'] ?? ''));
$notes = trim((string)($_POST['notes'] ?? ''));
$rowKey = trim((string)($_POST['row_key'] ?? ''));

if ($stayPeriod === '' || $roomType === '' || $customerName === '' || $externalReference === '') {
    set_flash('error', 'Compila tutti i campi obbligatori e verifica il riferimento prenotazione.');
    header('Location: ' . admin_url('import-interhome-review.php?row=' . urlencode($rowKey)));
    exit;
}

if ($customerEmail !== '' && !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
    set_flash('error', 'Inserisci un indirizzo email valido oppure lascia il campo vuoto.');
    header('Location: ' . admin_url('import-interhome-review.php?row=' . urlencode($rowKey)));
    exit;
}

if ($adults < 0 || $children < 0) {
    set_flash('error', 'Controlla il numero di adulti e bambini.');
    header('Location: ' . admin_url('import-interhome-review.php?row=' . urlencode($rowKey)));
    exit;
}

$stmt = $pdo->prepare(
    'INSERT INTO prenotazioni (
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
    )'
);

$source = 'interhome_pdf';
$rawPayload = json_encode([
    'import_type' => 'interhome_pdf',
    'row_key' => $rowKey,
    'imported_by' => current_admin()['email'] ?? null,
], JSON_UNESCAPED_UNICODE);

$stmt->execute([
    'customer_name' => $customerName,
    'customer_email' => $customerEmail !== '' ? $customerEmail : null,
    'customer_phone' => $customerPhone !== '' ? $customerPhone : null,
    'stay_period' => $stayPeriod,
    'room_type' => $roomType,
    'adults' => $adults,
    'children_count' => $children,
    'notes' => $notes !== '' ? $notes : null,
    'status' => $status !== '' ? $status : 'confermata',
    'source' => $source,
    'external_reference' => $externalReference,
    'raw_payload' => $rawPayload,
]);

if (isset($_SESSION['interhome_import']['rows']) && $rowKey !== '') {
    foreach ($_SESSION['interhome_import']['rows'] as $idx => $row) {
        if ((string)$idx === $rowKey) {
            unset($_SESSION['interhome_import']['rows'][$idx]);
            $_SESSION['interhome_import']['rows'] = array_values($_SESSION['interhome_import']['rows']);
            $_SESSION['interhome_import']['summary']['found_total'] = count($_SESSION['interhome_import']['rows']);
            break;
        }
    }
}

set_flash('success', 'Prenotazione Interhome salvata correttamente.');
header('Location: ' . admin_url('index.php#registered-bookings'));
exit;
