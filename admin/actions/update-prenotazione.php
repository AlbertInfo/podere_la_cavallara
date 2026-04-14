<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/customer-sync.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . admin_url('index.php#registered-bookings'));
    exit;
}

verify_csrf();

$id = (int) ($_POST['prenotazione_id'] ?? 0);
if ($id <= 0) {
    set_flash('error', 'Prenotazione non valida.');
    header('Location: ' . admin_url('index.php#registered-bookings'));
    exit;
}

$data = [
    'customer_name' => trim((string) ($_POST['customer_name'] ?? '')),
    'customer_email' => trim((string) ($_POST['customer_email'] ?? '')),
    'customer_phone' => trim((string) ($_POST['customer_phone'] ?? '')),
    'stay_period' => trim((string) ($_POST['stay_period'] ?? '')),
    'room_type' => trim((string) ($_POST['room_type'] ?? '')),
    'adults' => max(0, (int) ($_POST['adults'] ?? 0)),
    'children_count' => max(0, (int) ($_POST['children_count'] ?? 0)),
    'notes' => trim((string) ($_POST['notes'] ?? '')),
    'status' => trim((string) ($_POST['status'] ?? 'confermata')),
    'source' => trim((string) ($_POST['source'] ?? 'website_admin')),
    'external_reference' => trim((string) ($_POST['external_reference'] ?? '')),
];

if ($data['customer_name'] === '' || $data['stay_period'] === '' || $data['room_type'] === '') {
    set_flash('error', 'Compila i campi obbligatori. Email e telefono possono restare vuoti.');
    header('Location: ' . admin_url('edit-prenotazione.php?id=' . $id));
    exit;
}

$normalizedEmail = admin_normalize_optional_email($data['customer_email']);
if ($data['customer_email'] !== '' && $normalizedEmail === null) {
    set_flash('error', 'Inserisci un indirizzo email valido oppure lascia il campo vuoto.');
    header('Location: ' . admin_url('edit-prenotazione.php?id=' . $id));
    exit;
}

$normalizedPhone = admin_normalize_optional_phone($data['customer_phone']);

if (!in_array($data['status'], ['confermata', 'in_attesa', 'annullata'], true)) {
    set_flash('error', 'Stato prenotazione non valido.');
    header('Location: ' . admin_url('edit-prenotazione.php?id=' . $id));
    exit;
}

$dates = admin_parse_stay_period_dates($data['stay_period']);

try {
    $stmt = $pdo->prepare('UPDATE prenotazioni SET
        customer_name = :customer_name,
        customer_email = :customer_email,
        email_missing = :email_missing,
        customer_phone = :customer_phone,
        stay_period = :stay_period,
        check_in = :check_in,
        check_out = :check_out,
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
        'customer_email' => $normalizedEmail,
        'email_missing' => $normalizedEmail === null ? 1 : 0,
        'customer_phone' => $normalizedPhone,
        'stay_period' => $data['stay_period'],
        'check_in' => $dates['check_in'],
        'check_out' => $dates['check_out'],
        'room_type' => $data['room_type'],
        'adults' => $data['adults'],
        'children_count' => $data['children_count'],
        'notes' => $data['notes'] !== '' ? $data['notes'] : null,
        'status' => $data['status'],
        'source' => $data['source'] !== '' ? $data['source'] : 'website_admin',
        'external_reference' => $data['external_reference'] !== '' ? $data['external_reference'] : null,
        'id' => $id,
    ]);

    // Sync cliente non bloccante
    try {
        $bookingStmt = $pdo->prepare('SELECT * FROM prenotazioni WHERE id = :id LIMIT 1');
        $bookingStmt->execute(['id' => $id]);
        $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);

        if ($booking) {
            customer_sync_booking_row($pdo, $booking, 'prenotazione_update');
        }
    } catch (Throwable $syncError) {
        error_log('customer_sync_booking_row failed for booking #' . $id . ': ' . $syncError->getMessage());
    }

    set_flash('success', 'Prenotazione aggiornata correttamente.');
} catch (Throwable $e) {
    error_log('update-prenotazione failed for booking #' . $id . ': ' . $e->getMessage());
    set_flash('error', 'Errore durante il salvataggio della prenotazione.');
    header('Location: ' . admin_url('edit-prenotazione.php?id=' . $id));
    exit;
}

header('Location: ' . admin_url('index.php#registered-bookings'));
exit;