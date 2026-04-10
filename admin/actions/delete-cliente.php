<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_admin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: ' . admin_url('clienti.php'));
    exit;
}

$postedToken = (string) ($_POST['_csrf'] ?? '');
$sessionToken = function_exists('csrf_token') ? (string) csrf_token() : '';

if ($postedToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $postedToken)) {
    if (function_exists('set_flash')) {
        set_flash('error', 'Token di sicurezza non valido. Riprova.');
    }
    header('Location: ' . admin_url('clienti.php'));
    exit;
}

$clienteId = (int) ($_POST['cliente_id'] ?? 0);
if ($clienteId <= 0) {
    if (function_exists('set_flash')) {
        set_flash('error', 'Cliente non valido.');
    }
    header('Location: ' . admin_url('clienti.php'));
    exit;
}

try {
    $pdo->beginTransaction();

    $unlinkStmt = $pdo->prepare('UPDATE prenotazioni SET cliente_id = NULL WHERE cliente_id = :cliente_id');
    $unlinkStmt->execute(['cliente_id' => $clienteId]);

    $deleteStmt = $pdo->prepare('DELETE FROM clienti WHERE id = :id LIMIT 1');
    $deleteStmt->execute(['id' => $clienteId]);

    if ($deleteStmt->rowCount() < 1) {
        $pdo->rollBack();
        if (function_exists('set_flash')) {
            set_flash('error', 'Cliente non trovato o già eliminato.');
        }
        header('Location: ' . admin_url('clienti.php'));
        exit;
    }

    $pdo->commit();

    if (function_exists('set_flash')) {
        set_flash('success', 'Cliente eliminato correttamente.');
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if (function_exists('set_flash')) {
        set_flash('error', 'Si è verificato un errore durante l\'eliminazione del cliente.');
    }
}

header('Location: ' . admin_url('clienti.php'));
exit;
