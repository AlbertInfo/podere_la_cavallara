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
    set_flash($type, $message);
    header('Location: ' . $url);
    exit;
}

$recordId = max(0, (int) ($_POST['record_id'] ?? 0));
$returnMonth = trim((string) ($_POST['return_month'] ?? ''));
$returnDay = trim((string) ($_POST['return_day'] ?? ''));
$params = [];
if ($returnMonth !== '') {
    $params['month'] = $returnMonth;
}
if ($returnDay !== '') {
    $params['day'] = $returnDay;
}
if ($recordId > 0) {
    $params['deleted'] = (string) $recordId;
}
$redirectUrl = admin_url('anagrafica.php' . ($params ? ('?' . http_build_query($params)) : ''));

if ($recordId <= 0) {
    redirect_to($redirectUrl, 'error', 'Record non valido.');
}

try {
    $tableReady = (bool) $pdo->query("SHOW TABLES LIKE 'anagrafica_records'")->fetchColumn();
    if (!$tableReady) {
        redirect_to($redirectUrl, 'error', 'Tabella anagrafiche non disponibile.');
    }

    $stmt = $pdo->prepare('DELETE FROM anagrafica_records WHERE id = :id');
    $stmt->execute(['id' => $recordId]);

    redirect_to($redirectUrl, 'success', 'Anagrafica eliminata correttamente.');
} catch (Throwable $e) {
    redirect_to($redirectUrl, 'error', "Errore durante l'eliminazione: " . $e->getMessage());
}
