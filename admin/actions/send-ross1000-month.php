<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ross1000-ws.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . admin_url('anagrafica.php'));
    exit;
}

verify_csrf();

function redirect_ross_month_ws(string $month, string $type, string $message): never
{
    set_flash($type, $message);
    header('Location: ' . admin_url('anagrafica.php?month=' . rawurlencode($month)));
    exit;
}

$month = trim((string) ($_POST['month'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    header('Location: ' . admin_url('anagrafica.php'));
    exit;
}

$rangeStates = is_array($_POST['range_state'] ?? null) ? array_values($_POST['range_state']) : [];
$rangeFrom = is_array($_POST['range_from'] ?? null) ? array_values($_POST['range_from']) : [];
$rangeTo = is_array($_POST['range_to'] ?? null) ? array_values($_POST['range_to']) : [];
$ranges = [];
$max = max(count($rangeStates), count($rangeFrom), count($rangeTo));
for ($i = 0; $i < $max; $i++) {
    $state = (string) ($rangeStates[$i] ?? '');
    if (!in_array($state, ['open', 'closed'], true)) {
        continue;
    }
    $ranges[] = [
        'state' => $state,
        'from' => (int) ($rangeFrom[$i] ?? 0),
        'to' => (int) ($rangeTo[$i] ?? 0),
    ];
}

try {
    $result = ross1000_ws_send_month($pdo, $month, $ranges);
    if (!$result['success']) {
        redirect_ross_month_ws($month, 'error', 'Invio ROSS1000 mensile non riuscito: ' . implode(' ', $result['errors'] ?? []));
    }
    $message = (($result['mode'] ?? '') === 'simulation')
        ? 'Invio mensile ROSS1000 simulato con successo. Configura username/password e disattiva la simulazione per l’invio reale.'
        : 'Invio mensile ROSS1000 completato con successo.';
    redirect_ross_month_ws($month, 'success', $message);
} catch (Throwable $e) {
    redirect_ross_month_ws($month, 'error', 'Errore durante l’invio mensile ROSS1000: ' . $e->getMessage());
}
