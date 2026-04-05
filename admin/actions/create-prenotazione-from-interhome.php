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

$stayPeriod = trim((string)($_POST['stay_period'] ?? ''));
$roomType = trim((string)($_POST['room_type'] ?? ''));
$customerName = trim((string)($_POST['customer_name'] ?? ''));
$customerEmail = trim((string)($_POST['customer_email'] ?? ''));
$customerPhone = trim((string)($_POST['customer_phone'] ?? ''));
$adults = (int)($_POST['adults'] ?? 0);
$children = (int)($_POST['children_count'] ?? 0);
$externalReference = trim((string)($_POST['external_reference'] ?? ''));
$notes = trim((string)($_POST['notes'] ?? ''));
$status = trim((string)($_POST['status'] ?? 'confermata'));
$source = 'interhome_pdf';

if ($stayPeriod === '' || $roomType === '' || $customerName === '' || $externalReference === '') {
    set_flash('error', 'Periodo, casa, nominativo e riferimento prenotazione sono obbligatori.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$stmt = $pdo->prepare('SELECT COUNT(*) FROM prenotazioni WHERE external_reference = :ref');
$stmt->execute(['ref' => $externalReference]);
if ((int)$stmt->fetchColumn() > 0) {
    set_flash('error', 'Questa prenotazione è già registrata.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$payload = ['source' => 'interhome_pdf', 'created_from_review' => true];

$insert = $pdo->prepare('
    INSERT INTO prenotazioni
    (booking_request_id, customer_name, customer_email, customer_phone, stay_period, room_type, adults, children_count, notes, status, source, external_reference, raw_payload, created_at, updated_at)
    VALUES
    (NULL, :customer_name, :customer_email, :customer_phone, :stay_period, :room_type, :adults, :children_count, :notes, :status, :source, :external_reference, :raw_payload, NOW(), NOW())
');
$insert->execute([
    'customer_name'=>$customerName,
    'customer_email'=>$customerEmail,
    'customer_phone'=>$customerPhone,
    'stay_period'=>$stayPeriod,
    'room_type'=>$roomType,
    'adults'=>$adults,
    'children_count'=>$children,
    'notes'=>$notes,
    'status'=>$status,
    'source'=>$source,
    'external_reference'=>$externalReference,
    'raw_payload'=>json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
]);

$import = $_SESSION['interhome_import']['rows'] ?? [];
if (is_array($import)) {
    $_SESSION['interhome_import']['rows'] = array_values(array_filter($import, static function ($row) use ($externalReference) {
        return trim((string)($row['external_reference'] ?? '')) !== $externalReference;
    }));
    if (empty($_SESSION['interhome_import']['rows'])) unset($_SESSION['interhome_import']);
}

set_flash('success', 'Prenotazione salvata correttamente.');
header('Location: ' . admin_url('index.php#registered-bookings'));
exit;
?>