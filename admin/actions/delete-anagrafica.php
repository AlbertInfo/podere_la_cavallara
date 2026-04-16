<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . admin_url('anagrafica.php'));
    exit;
}

verify_csrf();

function redirect_to(string $url, string $type, string $message): never
{
    if (function_exists('flash_set')) {
        flash_set($type, $message);
    }
    header('Location: ' . $url);
    exit;
}

$recordId = max(0, (int) ($_POST['record_id'] ?? 0));
if ($recordId <= 0) {
    redirect_to(admin_url('anagrafica.php'), 'error', 'Record non valido.');
}

try {
    $tableReady = (bool) $pdo->query("SHOW TABLES LIKE 'anagrafica_records'")->fetchColumn();
    if (!$tableReady) {
        redirect_to(admin_url('anagrafica.php'), 'error', 'Tabella anagrafiche non disponibile.');
    }

    $stmt = $pdo->prepare('DELETE FROM anagrafica_records WHERE id = :id');
    $stmt->execute(['id' => $recordId]);

    redirect_to(admin_url('anagrafica.php?deleted=' . $recordId), 'success', 'Anagrafica eliminata correttamente.');
} catch (Throwable $e) {
    redirect_to(admin_url('anagrafica.php'), 'error', "Errore durante l'eliminazione: " . $e->getMessage());
}
