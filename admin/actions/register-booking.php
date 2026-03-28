<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_admin();
verify_csrf();

$bookingRequestId = (int)($_POST['booking_request_id'] ?? 0);
if ($bookingRequestId <= 0) {
    set_flash('error', 'Richiesta non valida.');
    header('Location: ' . admin_url('index.php') . '#booking-requests');
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM booking_requests WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $bookingRequestId]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    set_flash('error', 'Richiesta non trovata.');
    header('Location: ' . admin_url('index.php') . '#booking-requests');
    exit;
}

try {
    $pdo->beginTransaction();

    $insert = $pdo->prepare('INSERT INTO prenotazioni (
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
        raw_payload
    ) VALUES (
        :booking_request_id,
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
        :raw_payload
    )');

    $insert->execute([
        'booking_request_id' => (int)$request['id'],
        'customer_name' => (string)$request['name_booking'],
        'customer_email' => (string)$request['email_booking'],
        'customer_phone' => null,
        'stay_period' => (string)$request['date_booking'],
        'room_type' => (string)$request['rooms_booking'],
        'adults' => (int)$request['adults_booking'],
        'children_count' => (int)$request['childs_booking'],
        'notes' => null,
        'status' => 'confermata',
        'source' => (string)($request['source'] ?? 'website_admin'),
        'external_reference' => null,
        'raw_payload' => json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    $delete = $pdo->prepare('DELETE FROM booking_requests WHERE id = :id');
    $delete->execute(['id' => $bookingRequestId]);

    $pdo->commit();
    set_flash('success', 'Richiesta trasformata in prenotazione con successo.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    set_flash('error', 'Errore durante la registrazione della prenotazione: ' . $e->getMessage());
}

header('Location: ' . admin_url('index.php') . '#registered-bookings');
exit;
