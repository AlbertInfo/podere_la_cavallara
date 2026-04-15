<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_admin();
verify_csrf();

$id = (int) ($_POST['booking_request_id'] ?? 0);
$stmt = $pdo->prepare('DELETE FROM booking_requests WHERE id = :id');
$stmt->execute(['id' => $id]);

if (wants_json_response()) {
    json_response(['success' => true, 'message' => 'Richiesta prenotazione eliminata.']);
}

set_flash('success', 'Richiesta prenotazione eliminata.');
header('Location: ' . admin_url('index.php#booking-requests'));
exit;
