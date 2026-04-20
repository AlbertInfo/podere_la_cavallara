<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ross1000-ws.php';
require_admin();

function redirect_ross_day_ws(string $month, string $day, string $type, string $message): never
{
    set_flash($type, $message);
    header('Location: ' . admin_url('anagrafica.php?month=' . rawurlencode($month) . '&day=' . rawurlencode($day)));
    exit;
}

$day = trim((string) ($_GET['day'] ?? ''));
$month = trim((string) ($_GET['month'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || !preg_match('/^\d{4}-\d{2}$/', $month)) {
    header('Location: ' . admin_url('anagrafica.php'));
    exit;
}

try {
    $result = ross1000_ws_send_day($pdo, $day);
    if (!$result['success']) {
        redirect_ross_day_ws($month, $day, 'error', 'Invio ROSS1000 non riuscito: ' . implode(' ', $result['errors'] ?? []));
    }

    $message = (($result['mode'] ?? '') === 'simulation')
        ? 'Invio ROSS1000 simulato con successo. Configura username/password e disattiva la simulazione per l’invio reale.'
        : 'Invio ROSS1000 del giorno completato con successo.';
    redirect_ross_day_ws($month, $day, 'success', $message);
} catch (Throwable $e) {
    redirect_ross_day_ws($month, $day, 'error', 'Errore durante l’invio ROSS1000: ' . $e->getMessage());
}
