<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

verify_csrf();

$import = $_SESSION['interhome_import']['rows'] ?? [];
$rowKey = trim((string) ($_POST['row_key'] ?? ''));

$sourceRow = null;
foreach ($import as $row) {
    if (($row['row_key'] ?? '') === $rowKey) {
        $sourceRow = $row;
        break;
    }
}

if (!$sourceRow) {
    set_flash('error', 'La prenotazione selezionata non è più disponibile per l’import.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$stayPeriod = trim((string) ($_POST['stay_period'] ?? ''));
$roomType = trim((string) ($_POST['room_type'] ?? ''));
$adults = max(0, (int) ($_POST['adults'] ?? 0));
$children = max(0, (int) ($_POST['children_count'] ?? 0));
$customerName = trim((string) ($_POST['customer_name'] ?? ''));
$customerEmail = trim((string) ($_POST['customer_email'] ?? ''));
$customerPhone = trim((string) ($_POST['customer_phone'] ?? ''));
$status = trim((string) ($_POST['status'] ?? 'confermata'));
$source = 'interhome_pdf';
$externalReference = trim((string) ($_POST['external_reference'] ?? ''));
$notes = trim((string) ($_POST['notes'] ?? ''));

if ($stayPeriod === '' || $roomType === '' || $customerName === '' || $externalReference === '') {
    set_flash('error', 'Compila tutti i campi obbligatori della prenotazione Interhome.');
    header('Location: ' . admin_url('import-interhome-review.php?row=' . urlencode($rowKey)));
    exit;
}

if ($customerEmail === '' || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
    set_flash('error', 'Inserisci un indirizzo email valido prima di confermare l’import.');
    header('Location: ' . admin_url('import-interhome-review.php?row=' . urlencode($rowKey)));
    exit;
}

$stmt = $pdo->prepare('SELECT COUNT(*) FROM prenotazioni WHERE external_reference = :external_reference');
$stmt->execute(['external_reference' => $externalReference]);
if ((int) $stmt->fetchColumn() > 0) {
    set_flash('error', 'Questa prenotazione è già presente tra le prenotazioni registrate.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$allowedStatuses = ['confermata', 'in_attesa', 'annullata'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = 'confermata';
}

$rawPayload = json_encode([
    'import_type' => 'interhome_pdf',
    'imported_by' => current_admin()['email'] ?? null,
    'imported_at' => date('c'),
    'source_row' => $sourceRow,
], JSON_UNESCAPED_UNICODE);

$insert = $pdo->prepare(
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

$insert->execute([
    'customer_name' => $customerName,
    'customer_email' => $customerEmail,
    'customer_phone' => $customerPhone !== '' ? $customerPhone : null,
    'stay_period' => $stayPeriod,
    'room_type' => $roomType,
    'adults' => $adults,
    'children_count' => $children,
    'notes' => $notes !== '' ? $notes : null,
    'status' => $status,
    'source' => $source,
    'external_reference' => $externalReference,
    'raw_payload' => $rawPayload,
]);

$_SESSION['interhome_import']['rows'] = array_values(array_filter($import, static function (array $row) use ($rowKey): bool {
    return ($row['row_key'] ?? '') !== $rowKey;
}));
$_SESSION['interhome_import']['stats']['new_rows'] = count($_SESSION['interhome_import']['rows']);

set_flash('success', 'Prenotazione Interhome importata correttamente.');
header('Location: ' . admin_url('index.php#registered-bookings'));
exit;
