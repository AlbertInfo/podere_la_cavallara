<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ross1000-ws.php';
require_admin();

$day = trim((string) ($_GET['day'] ?? ''));
$month = trim((string) ($_GET['month'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || !preg_match('/^\d{4}-\d{2}$/', $month)) {
    header('Location: ' . admin_url('anagrafica.php'));
    exit;
}

try {
    $state = ross1000_get_day_state($pdo, $day, ross1000_property_config());
    if ((int) ($state['is_finalized'] ?? 0) !== 1) {
        throw new RuntimeException('Chiudi il giorno prima di inviarlo a ROSS1000.');
    }

    $payload = ross1000_build_day_payload($pdo, $day);
    ross1000_ws_send($pdo, $payload, 'day', $day);

    if (ross1000_day_status_table_ready($pdo)) {
        $state['exported_ross_at'] = date('Y-m-d H:i:s');
        ross1000_upsert_day_state($pdo, $day, $state);
    }

    set_flash('success', 'Invio ROSS1000 del giorno completato correttamente.');
} catch (Throwable $e) {
    set_flash('error', 'Invio ROSS1000 non riuscito: ' . $e->getMessage());
}

header('Location: ' . admin_url('anagrafica.php?month=' . rawurlencode($month) . '&day=' . rawurlencode($day)));
exit;
