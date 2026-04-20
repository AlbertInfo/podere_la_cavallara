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

function redirect_alloggiati_day(string $month, string $day, string $type, string $message): never
{
    set_flash($type, $message);
    header('Location: ' . admin_url('anagrafica.php?month=' . rawurlencode($month) . '&day=' . rawurlencode($day) . '#alloggiatiDaySection'));
    exit;
}

function alloggiati_flash_message(array $errors, int $maxItems = 3): string
{
    $errors = array_values(array_unique(array_filter(array_map(static fn($v): string => trim((string) $v), $errors))));
    if (!$errors) {
        return '';
    }

    $excerpt = array_slice($errors, 0, $maxItems);
    $message = implode(' | ', $excerpt);
    if (count($errors) > $maxItems) {
        $message .= ' | +' . (count($errors) - $maxItems) . ' altri errori';
    }
    return $message;
}

$month = trim((string) ($_POST['month'] ?? ''));
$day = trim((string) ($_POST['day'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $month) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
    header('Location: ' . admin_url('anagrafica.php'));
    exit;
}

try {
    if (!alloggiati_schedine_table_ready($pdo)) {
        redirect_alloggiati_day($month, $day, 'error', 'Esegui prima la migration della tabella alloggiati_schedine.');
    }

    $result = alloggiati_ws_send_day($pdo, $day);
    if (($result['sent'] ?? 0) > 0 && ross1000_day_status_table_ready($pdo)) {
        $state = ross1000_get_day_state($pdo, $day);
        $state['exported_alloggiati_at'] = date('Y-m-d H:i:s');
        ross1000_upsert_day_state($pdo, $day, $state);
    }

    $sent = (int) ($result['sent'] ?? 0);
    $errors = array_values($result['errors'] ?? []);
    $errorsMessage = alloggiati_flash_message($errors);

    if ($sent > 0 && !$errors) {
        redirect_alloggiati_day($month, $day, 'success', 'Schedine Alloggiati del giorno inviate correttamente: ' . $sent . '.');
    }
    if ($sent > 0) {
        redirect_alloggiati_day($month, $day, 'warning', 'Schedine inviate: ' . $sent . '. ' . ($errorsMessage !== '' ? $errorsMessage : 'Alcune schede richiedono attenzione.'));
    }
    redirect_alloggiati_day($month, $day, 'error', $errorsMessage !== '' ? $errorsMessage : 'Nessuna schedina pronta da inviare.');
} catch (Throwable $e) {
    redirect_alloggiati_day($month, $day, 'error', 'Errore durante l’invio delle schedine del giorno: ' . $e->getMessage());
}
