<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . admin_url('index.php') . '#contact-requests');
    exit;
}

verify_csrf();

$id = (int) ($_POST['contact_request_id'] ?? 0);

if ($id <= 0) {
    set_flash('error', 'Richiesta contatto non valida.');
    header('Location: ' . admin_url('index.php') . '#contact-requests');
    exit;
}

$stmt = $pdo->prepare('DELETE FROM contact_requests WHERE id = :id LIMIT 1');
$stmt->execute([
    'id' => $id,
]);

if ($stmt->rowCount() > 0) {
    set_flash('success', 'Richiesta contatto eliminata correttamente.');
} else {
    set_flash('error', 'Richiesta contatto non trovata o già eliminata.');
}

header('Location: ' . admin_url('index.php') . '#contact-requests');
exit;