<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

verify_csrf();

$id = (int) ($_POST['import_row_id'] ?? -1);
$rows = $_SESSION['interhome_import']['rows'] ?? [];
if (!isset($rows[$id]) || !is_array($rows[$id])) {
    set_flash('error', 'Prenotazione importata non trovata in sessione.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$customerName = trim((string) ($_POST['customer_name'] ?? ''));
$customerEmail = trim((string) ($_POST['customer_email'] ?? ''));
$customerPhone = trim((string) ($_POST['customer_phone'] ?? ''));
$stayPeriod = trim((string) ($_POST['stay_period'] ?? ''));
$roomType = trim((string) ($_POST['room_type'] ?? ''));
$adults = max(0, (int) ($_POST['adults'] ?? 0));
$children = max(0, (int) ($_POST['children_count'] ?? 0));
$status = trim((string) ($_POST['status'] ?? 'confermata')) ?: 'confermata';
$notes = trim((string) ($_POST['notes'] ?? ''));
$externalReference = trim((string) ($_POST['external_reference'] ?? ''));
$source = trim((string) ($_POST['source'] ?? 'interhome_pdf')) ?: 'interhome_pdf';

if ($customerName === '' || $stayPeriod === '' || $roomType === '') {
    set_flash('error', 'Compila almeno nome cliente, soggiorno e camera.');
    header('Location: ' . admin_url('import-interhome-review.php?id=' . $id));
    exit;
}

if ($externalReference !== '') {
    $check = $pdo->prepare('SELECT id FROM prenotazioni WHERE external_reference = :external_reference LIMIT 1');
    $check->execute(['external_reference' => $externalReference]);
    if ($check->fetchColumn()) {
        set_flash('error', 'Questa prenotazione risulta già presente in archivio.');
        header('Location: ' . admin_url('import-interhome-pdf.php'));
        exit;
    }
}

$rawPayload = json_encode($rows[$id], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$sql = 'INSERT INTO prenotazioni (
    booking_request_id,
    customer_name,
    customer_email,
    customer_phone,
    stay_period,
    room_type,
    adults,
    children_count,
    status,
    source,
    external_reference,
    notes,
    raw_payload,
    created_at
) VALUES (
    NULL,
    :customer_name,
    :customer_email,
    :customer_phone,
    :stay_period,
    :room_type,
    :adults,
    :children_count,
    :status,
    :source,
    :external_reference,
    :notes,
    :raw_payload,
    NOW()
)';

$stmt = $pdo->prepare($sql);
$stmt->execute([
    'customer_name' => $customerName,
    'customer_email' => $customerEmail,
    'customer_phone' => $customerPhone,
    'stay_period' => $stayPeriod,
    'room_type' => $roomType,
    'adults' => $adults,
    'children_count' => $children,
    'status' => $status,
    'source' => $source,
    'external_reference' => $externalReference,
    'notes' => $notes !== '' ? $notes : null,
    'raw_payload' => $rawPayload,
]);

unset($_SESSION['interhome_import']['rows'][$id]);
$_SESSION['interhome_import']['rows'] = array_values($_SESSION['interhome_import']['rows']);
$_SESSION['interhome_import']['summary']['found_total'] = count($_SESSION['interhome_import']['rows']);

set_flash('success', 'Prenotazione Interhome importata correttamente.');
header('Location: ' . admin_url('index.php#registered-bookings'));
exit;
