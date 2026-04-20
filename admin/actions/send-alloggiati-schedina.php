<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/alloggiati-ws.php';
require_once __DIR__ . '/../includes/ross1000.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . admin_url('anagrafica.php'));
    exit;
}

verify_csrf();

function redirect_alloggiati_schedina(string $month, string $day, string $type, string $message): never
{
    set_flash($type, $message);
    header('Location: ' . admin_url('anagrafica.php?month=' . rawurlencode($month) . '&day=' . rawurlencode($day) . '#alloggiatiDaySection'));
    exit;
}

$month = trim((string) ($_POST['month'] ?? ''));
$day = trim((string) ($_POST['day'] ?? ''));
$schedinaId = max(0, (int) ($_POST['schedina_id'] ?? 0));
if (!preg_match('/^\d{4}-\d{2}$/', $month) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || $schedinaId <= 0) {
    header('Location: ' . admin_url('anagrafica.php'));
    exit;
}

try {
    if (!alloggiati_schedine_table_ready($pdo)) {
        redirect_alloggiati_schedina($month, $day, 'error', 'Esegui prima la migration della tabella alloggiati_schedine.');
    }

    $result = alloggiati_ws_send_schedina($pdo, $schedinaId);
    if (($result['sent'] ?? 0) > 0 && ross1000_day_status_table_ready($pdo)) {
        $state = ross1000_get_day_state($pdo, $day);
        $state['exported_alloggiati_at'] = date('Y-m-d H:i:s');
        ross1000_upsert_day_state($pdo, $day, $state);
    }

    if (($result['sent'] ?? 0) > 0) {
        redirect_alloggiati_schedina($month, $day, 'success', 'Schedina Alloggiati inviata correttamente.');
    }
    $errors = array_values(array_unique(array_filter(array_map(static fn($v): string => trim((string) $v), $result['errors'] ?? []))));
    redirect_alloggiati_schedina($month, $day, 'error', implode(' | ', $errors) ?: 'La schedina selezionata non è pronta per l’invio.');
} catch (Throwable $e) {
    redirect_alloggiati_schedina($month, $day, 'error', 'Errore durante l’invio della schedina: ' . $e->getMessage());
}
