<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_admin();
verify_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Metodo non consentito.');
}

if (!verify_csrf_token($_POST['_csrf'] ?? '')) {
    set_flash('error', 'Sessione scaduta. Riprova.');
    header('Location: ' . admin_url('clienti.php'));
    exit;
}

$clienteId = (int) ($_POST['cliente_id'] ?? 0);

if ($clienteId <= 0) {
    set_flash('error', 'Cliente non valido.');
    header('Location: ' . admin_url('clienti.php'));
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('UPDATE prenotazioni SET cliente_id = NULL WHERE cliente_id = :cliente_id');
    $stmt->execute(['cliente_id' => $clienteId]);

    $stmt = $pdo->prepare('DELETE FROM clienti WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $clienteId]);

    $pdo->commit();

    set_flash('success', 'Cliente eliminato correttamente.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    set_flash('error', 'Impossibile eliminare il cliente.');
}

header('Location: ' . admin_url('clienti.php'));
exit;