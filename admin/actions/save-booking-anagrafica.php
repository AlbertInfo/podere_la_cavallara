<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/anagrafica-options.php';
require_once __DIR__ . '/../includes/alloggiati.php';
require_once __DIR__ . '/../includes/prenotazioni-anagrafica-sync.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . admin_url('anagrafica.php'));
    exit;
}
verify_csrf();

function booking_redirect(string $month, string $day): string
{
    $params = [];
    if ($month !== '') $params['month'] = $month;
    if ($day !== '') $params['day'] = $day;
    return admin_url('anagrafica.php' . ($params ? ('?' . http_build_query($params)) : ''));
}

function booking_parse_date(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') return null;
    foreach (['d/m/Y', 'Y-m-d'] as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $value);
        if ($dt instanceof DateTimeImmutable) return $dt->format('Y-m-d');
    }
    return null;
}

function booking_error(array &$errors, string $field, string $message): void
{
    if (!isset($errors[$field])) $errors[$field] = $message;
}

$month = trim((string) ($_POST['month'] ?? ''));
$day = trim((string) ($_POST['day'] ?? ''));
$prenotazioneId = (int) ($_POST['prenotazione_id'] ?? 0);
$recordId = (int) ($_POST['linked_record_id'] ?? 0);
$data = [
    'prenotazione_id' => $prenotazioneId,
    'linked_record_id' => $recordId,
    'customer_name' => trim((string) ($_POST['customer_name'] ?? '')),
    'customer_email' => trim((string) ($_POST['customer_email'] ?? '')),
    'customer_phone' => trim((string) ($_POST['customer_phone'] ?? '')),
    'room_type' => trim((string) ($_POST['room_type'] ?? '')),
    'check_in' => trim((string) ($_POST['check_in'] ?? '')),
    'check_out' => trim((string) ($_POST['check_out'] ?? '')),
    'adults' => (int) ($_POST['adults'] ?? 1),
    'children_count' => max(0, (int) ($_POST['children_count'] ?? 0)),
    'status' => trim((string) ($_POST['status'] ?? 'confermata')),
    'notes' => trim((string) ($_POST['notes'] ?? '')),
    'first_name' => trim((string) ($_POST['first_name'] ?? '')),
    'last_name' => trim((string) ($_POST['last_name'] ?? '')),
    'gender' => trim((string) ($_POST['gender'] ?? 'M')),
    'birth_date' => trim((string) ($_POST['birth_date'] ?? '')),
    'citizenship_label' => trim((string) ($_POST['citizenship_label'] ?? '')),
    'birth_state_label' => trim((string) ($_POST['birth_state_label'] ?? '')),
    'birth_province' => trim((string) ($_POST['birth_province'] ?? '')),
    'birth_place_label' => trim((string) ($_POST['birth_place_label'] ?? '')),
    'residence_state_label' => trim((string) ($_POST['residence_state_label'] ?? '')),
    'residence_province' => trim((string) ($_POST['residence_province'] ?? '')),
    'residence_place_label' => trim((string) ($_POST['residence_place_label'] ?? '')),
    'document_type_label' => trim((string) ($_POST['document_type_label'] ?? '')),
    'document_number' => trim((string) ($_POST['document_number'] ?? '')),
    'document_issue_province' => trim((string) ($_POST['document_issue_province'] ?? '')),
    'document_issue_place' => trim((string) ($_POST['document_issue_place'] ?? '')),
];
$errors = [];
$messages = [];

$checkIn = booking_parse_date($data['check_in']);
$checkOut = booking_parse_date($data['check_out']);
$birthDate = booking_parse_date($data['birth_date']);

if ($prenotazioneId <= 0) booking_error($errors, 'customer_name', 'Prenotazione non valida.');
if ($data['customer_name'] === '') booking_error($errors, 'customer_name', 'Inserisci il nominativo della prenotazione.');
if ($data['room_type'] === '') booking_error($errors, 'room_type', 'Inserisci l’alloggio.');
if (!$checkIn) booking_error($errors, 'check_in', 'Inserisci una data di check-in valida.');
if (!$checkOut) booking_error($errors, 'check_out', 'Inserisci una data di check-out valida.');
if ($checkIn && $checkOut && $checkIn > $checkOut) booking_error($errors, 'check_out', 'Il check-out non può essere precedente al check-in.');
if ($data['adults'] < 1) booking_error($errors, 'adults', 'Indica almeno un adulto.');
if ($data['first_name'] === '') booking_error($errors, 'first_name', 'Inserisci il nome del referente.');
if ($data['last_name'] === '') booking_error($errors, 'last_name', 'Inserisci il cognome del referente.');
if (!$birthDate) booking_error($errors, 'birth_date', 'Inserisci una data di nascita valida.');
$citizenship = $data['citizenship_label'] !== '' ? anagrafica_find_state_by_value($data['citizenship_label']) : null;
if (!$citizenship) booking_error($errors, 'citizenship_label', 'Seleziona una cittadinanza valida.');
$birthState = $data['birth_state_label'] !== '' ? anagrafica_find_state_by_value($data['birth_state_label']) : null;
if (!$birthState) booking_error($errors, 'birth_state_label', 'Seleziona lo stato di nascita.');
$resState = $data['residence_state_label'] !== '' ? anagrafica_find_state_by_value($data['residence_state_label']) : null;
if (!$resState) booking_error($errors, 'residence_state_label', 'Seleziona lo stato di residenza.');
$birthProv = anagrafica_find_province_code($data['birth_province']);
$resProv = anagrafica_find_province_code($data['residence_province']);
$doc = $data['document_type_label'] !== '' ? anagrafica_find_document_by_value($data['document_type_label']) : null;
$docIssueProv = anagrafica_find_province_code($data['document_issue_province']);
$birthCity = null;
$resCity = null;
if ($birthState && $birthState['code'] === anagrafica_default_italy_state_code()) {
    if (!$birthProv) booking_error($errors, 'birth_province', 'Seleziona la provincia di nascita.');
    $birthCity = $data['birth_place_label'] !== '' ? anagrafica_find_comune_by_value($data['birth_place_label'], (string) $birthProv) : null;
    if (!$birthCity) booking_error($errors, 'birth_place_label', 'Seleziona un comune di nascita valido.');
}
if ($resState && $resState['code'] === anagrafica_default_italy_state_code()) {
    if (!$resProv) booking_error($errors, 'residence_province', 'Seleziona la provincia di residenza.');
    $resCity = $data['residence_place_label'] !== '' ? anagrafica_find_comune_by_value($data['residence_place_label'], (string) $resProv) : null;
    if (!$resCity) booking_error($errors, 'residence_place_label', 'Seleziona un comune di residenza valido.');
}
if ($data['document_type_label'] !== '' && !$doc) booking_error($errors, 'document_type_label', 'Tipo documento non riconosciuto.');
if ($data['document_number'] !== '' && $data['document_issue_place'] === '') booking_error($errors, 'document_issue_place', 'Indica il luogo di rilascio del documento.');

if ($errors) {
    $_SESSION['_anagrafica_booking_modal_state'] = [
        'open' => true,
        'data' => $data,
        'field_errors' => $errors,
        'messages' => array_values(array_unique(array_filter($messages ?: ['Controlla i campi evidenziati.']))),
    ];
    header('Location: ' . booking_redirect($month, $day));
    exit;
}

try {
    $pdo->beginTransaction();
    $bookingStmt = $pdo->prepare('SELECT * FROM prenotazioni WHERE id = :id LIMIT 1');
    $bookingStmt->execute(['id' => $prenotazioneId]);
    $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);
    if (!$booking) throw new RuntimeException('Prenotazione non trovata.');

    $stayPeriod = anagrafica_booking_stay_period($checkIn, $checkOut);
    $upd = $pdo->prepare('UPDATE prenotazioni SET customer_name = :customer_name, customer_email = :customer_email, email_missing = :email_missing, customer_phone = :customer_phone, stay_period = :stay_period, check_in = :check_in, check_out = :check_out, room_type = :room_type, adults = :adults, children_count = :children_count, notes = :notes, status = :status, updated_at = NOW() WHERE id = :id LIMIT 1');
    $upd->execute([
        'customer_name' => $data['customer_name'],
        'customer_email' => $data['customer_email'] !== '' ? $data['customer_email'] : null,
        'email_missing' => $data['customer_email'] === '' ? 1 : 0,
        'customer_phone' => $data['customer_phone'] !== '' ? $data['customer_phone'] : null,
        'stay_period' => $stayPeriod,
        'check_in' => $checkIn,
        'check_out' => $checkOut,
        'room_type' => $data['room_type'],
        'adults' => $data['adults'],
        'children_count' => $data['children_count'],
        'notes' => $data['notes'] !== '' ? $data['notes'] : null,
        'status' => in_array($data['status'], ['confermata', 'in_attesa', 'annullata'], true) ? $data['status'] : 'confermata',
        'id' => $prenotazioneId,
    ]);

    $bookingStmt->execute(['id' => $prenotazioneId]);
    $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $linkedRecordId = anagrafica_sync_booking_to_record($pdo, $booking);

    $leaderStmt = $pdo->prepare('SELECT * FROM anagrafica_guests WHERE record_id = :record_id AND is_group_leader = 1 LIMIT 1');
    $leaderStmt->execute(['record_id' => $linkedRecordId]);
    $leader = $leaderStmt->fetch(PDO::FETCH_ASSOC);
    if (!$leader) throw new RuntimeException('Scheda anagrafica referente non trovata.');

    $updGuest = $pdo->prepare('UPDATE anagrafica_guests SET first_name = :first_name, last_name = :last_name, gender = :gender, birth_date = :birth_date, citizenship_label = :citizenship_label, citizenship_code = :citizenship_code, birth_state_label = :birth_state_label, birth_state_code = :birth_state_code, birth_province = :birth_province, birth_place_label = :birth_place_label, birth_place = :birth_place, birth_place_code = :birth_place_code, birth_city_code = :birth_city_code, residence_state_label = :residence_state_label, residence_state_code = :residence_state_code, residence_province = :residence_province, residence_place_label = :residence_place_label, residence_place = :residence_place, residence_place_code = :residence_place_code, document_type_label = :document_type_label, document_type_code = :document_type_code, document_number = :document_number, document_issue_province = :document_issue_province, document_issue_place = :document_issue_place, document_issue_place_code = :document_issue_place_code, email = :email, phone = :phone, updated_at = NOW() WHERE id = :id');
    $updGuest->execute([
        'first_name' => $data['first_name'],
        'last_name' => $data['last_name'],
        'gender' => $data['gender'] === 'F' ? 'F' : 'M',
        'birth_date' => $birthDate,
        'citizenship_label' => $citizenship['description'],
        'citizenship_code' => $citizenship['code'],
        'birth_state_label' => $birthState['description'],
        'birth_state_code' => $birthState['code'],
        'birth_province' => $birthProv,
        'birth_place_label' => $data['birth_place_label'] !== '' ? $data['birth_place_label'] : null,
        'birth_place' => $birthCity['label'] ?? null,
        'birth_place_code' => $birthCity['code'] ?? null,
        'birth_city_code' => $birthCity['code'] ?? null,
        'residence_state_label' => $resState['description'],
        'residence_state_code' => $resState['code'],
        'residence_province' => $resProv,
        'residence_place_label' => $data['residence_place_label'] !== '' ? $data['residence_place_label'] : null,
        'residence_place' => $resCity['label'] ?? ($data['residence_place_label'] !== '' ? $data['residence_place_label'] : null),
        'residence_place_code' => $resCity['code'] ?? null,
        'document_type_label' => $doc['description'] ?? null,
        'document_type_code' => $doc['code'] ?? null,
        'document_number' => $data['document_number'] !== '' ? $data['document_number'] : null,
        'document_issue_province' => $docIssueProv,
        'document_issue_place' => $data['document_issue_place'] !== '' ? $data['document_issue_place'] : null,
        'document_issue_place_code' => null,
        'email' => $data['customer_email'] !== '' ? $data['customer_email'] : null,
        'phone' => $data['customer_phone'] !== '' ? $data['customer_phone'] : null,
        'id' => (int) $leader['id'],
    ]);

    if (alloggiati_schedine_table_ready($pdo)) {
        alloggiati_sync_record($pdo, $linkedRecordId);
    }
    $pdo->commit();
    set_flash('success', 'Prenotazione e anagrafica collegate aggiornate correttamente.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['_anagrafica_booking_modal_state'] = [
        'open' => true,
        'data' => $data,
        'field_errors' => [],
        'messages' => ['Non è stato possibile salvare la prenotazione in questo momento. Controlla i dati inseriti e riprova.'],
    ];
}

header('Location: ' . booking_redirect($month, $day));
exit;
