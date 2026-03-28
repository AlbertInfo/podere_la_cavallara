<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_admin();
verify_csrf();

$id = (int)($_POST['prenotazione_id'] ?? 0);
if ($id <= 0) {
    set_flash('error', 'Prenotazione non valida.');
    header('Location: ' . admin_url('index.php') . '#registered-bookings');
    exit;
}

$stmt = $pdo->prepare('DELETE FROM prenotazioni WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $id]);

set_flash('success', 'Prenotazione cancellata correttamente.');
header('Location: ' . admin_url('index.php') . '#registered-bookings');
exit;
