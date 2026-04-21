<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/customer-sync.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}
verify_csrf();

$import = $_SESSION['interhome_import'] ?? null;
$rowId = trim((string) ($_POST['import_row_id'] ?? ''));
if (!$import || empty($import['rows']) || $rowId === '') {
    set_flash('error', 'Prenotazione importata non disponibile.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$row = null;
foreach ($import['rows'] as $candidate) {
    if (($candidate['import_row_id'] ?? '') === $rowId) {
        $row = $candidate;
        break;
    }
}
if (!$row) {
    set_flash('error', 'Riga importata non trovata.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$stayPeriod = trim((string) ($_POST['stay_period'] ?? ''));
$roomType = trim((string) ($_POST['room_type'] ?? ''));
$adults = (int) ($_POST['adults'] ?? 0);
$children = (int) ($_POST['children_count'] ?? 0);
$customerName = trim((string) ($_POST['customer_name'] ?? ''));
$customerEmail = trim((string) ($_POST['customer_email'] ?? ''));
$customerPhone = trim((string) ($_POST['customer_phone'] ?? ''));
$status = trim((string) ($_POST['status'] ?? 'confermata'));
$source = 'interhome_pdf';
$externalReference = trim((string) ($_POST['external_reference'] ?? ''));
$notes = trim((string) ($_POST['notes'] ?? ''));

if ($stayPeriod === '' || $roomType === '' || $customerName === '' || $externalReference === '') {
    set_flash('error', 'Compila tutti i campi obbligatori, incluso il riferimento prenotazione. L’email può restare vuota se nel PDF non è presente.');
    header('Location: ' . admin_url('import-interhome-review.php?row=' . urlencode($rowId)));
    exit;
}

$normalizedEmail = admin_normalize_optional_email($customerEmail);
if ($customerEmail !== '' && $normalizedEmail === null) {
    set_flash('error', 'Inserisci un indirizzo email valido oppure lascia il campo vuoto.');
    header('Location: ' . admin_url('import-interhome-review.php?row=' . urlencode($rowId)));
    exit;
}

$allowedStatuses = ['confermata', 'in_attesa', 'annullata'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = 'confermata';
}

$dates = admin_parse_stay_period_dates($stayPeriod);
$normalizedPhone = admin_normalize_optional_phone($customerPhone);
// $propertyCode = admin_extract_property_code(isset($row['_raw_property']) ? $row['_raw_property'] : '');
$propertyCode = extract_property_code(isset($row['_raw_property']) ? $row['_raw_property'] : '');
$guestLanguage = trim((string) ($row['_language'] ?? ''));
$guestCountryCode = admin_normalize_country_code(customer_language_to_country_code($guestLanguage));

$checkExisting = $pdo->prepare('SELECT id FROM prenotazioni WHERE external_reference = :external_reference LIMIT 1');
$checkExisting->execute(['external_reference' => $externalReference]);
if ($checkExisting->fetch(PDO::FETCH_ASSOC)) {
    $_SESSION['interhome_import']['rows'] = array_values(array_filter($import['rows'], function ($r) use ($rowId) {
        return ($r['import_row_id'] ?? '') !== $rowId;
    }));
    $_SESSION['interhome_import']['summary']['new_total'] = count($_SESSION['interhome_import']['rows']);
    set_flash('success', 'La prenotazione era già registrata ed è stata rimossa dall’elenco importato.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

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
        property_code,
        adults,
        children_count,
        notes,
        status,
        source,
        external_reference,
        guest_language,
        guest_country_code,
        raw_payload,
        imported_at,
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
        :property_code,
        :adults,
        :children_count,
        :notes,
        :status,
        :source,
        :external_reference,
        :guest_language,
        :guest_country_code,
        :raw_payload,
        NOW(),
        NOW(),
        NOW()
    )'
);

$rawPayload = json_encode([
    'imported_from_pdf' => true,
    'imported_by' => current_admin()['email'] ?? null,
    'original_row' => $row,
], JSON_UNESCAPED_UNICODE);

$success = $stmt->execute([
    'customer_name' => $customerName,
    'customer_email' => $normalizedEmail,
    'email_missing' => $normalizedEmail === null ? 1 : 0,
    'customer_phone' => $normalizedPhone,
    'stay_period' => $stayPeriod,
    'check_in' => $dates['check_in'],
    'check_out' => $dates['check_out'],
    'room_type' => $roomType,
    'property_code' => $propertyCode,
    'adults' => $adults,
    'children_count' => $children,
    'notes' => $notes !== '' ? $notes : null,
    'status' => $status,
    'source' => $source,
    'external_reference' => $externalReference,
    'guest_language' => $guestLanguage !== '' ? $guestLanguage : null,
    'guest_country_code' => $guestCountryCode,
    'raw_payload' => $rawPayload,
]);

if ($success) {
    $prenotazioneId = (int) $pdo->lastInsertId();
    customer_sync_booking_row($pdo, [
        'id' => $prenotazioneId,
        'customer_name' => $customerName,
        'customer_email' => $normalizedEmail,
        'customer_phone' => $normalizedPhone,
        'guest_language' => $guestLanguage,
        'guest_country_code' => $guestCountryCode,
        'raw_payload' => $rawPayload,
    ], 'interhome_pdf');

    $_SESSION['interhome_import']['rows'] = array_values(array_filter($import['rows'], function ($r) use ($rowId) {
        return ($r['import_row_id'] ?? '') !== $rowId;
    }));
    $_SESSION['interhome_import']['summary']['new_total'] = count($_SESSION['interhome_import']['rows']);
    set_flash('success', 'Prenotazione inserita correttamente tra quelle registrate.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

set_flash('error', 'Inserimento non riuscito. Controlla i dati e riprova.');
header('Location: ' . admin_url('import-interhome-review.php?row=' . urlencode($rowId)));
exit;
