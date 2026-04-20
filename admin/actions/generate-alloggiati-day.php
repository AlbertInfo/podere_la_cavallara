<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/alloggiati.php';
require_once __DIR__ . '/../includes/ross1000.php';
require_admin();

$day = trim((string) ($_GET['day'] ?? ''));
$month = trim((string) ($_GET['month'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || !preg_match('/^\d{4}-\d{2}$/', $month)) {
    header('Location: ' . admin_url('anagrafica.php'));
    exit;
}

try {
    if (!alloggiati_schedine_table_ready($pdo)) {
        set_flash('error', 'Esegui prima la migration della tabella alloggiati_schedine.');
        header('Location: ' . admin_url('anagrafica.php?month=' . rawurlencode($month) . '&day=' . rawurlencode($day) . '#alloggiatiDaySection'));
        exit;
    }

    $bundle = alloggiati_collect_day_export($pdo, $day);
    if (($bundle['line_count'] ?? 0) <= 0 || trim((string) ($bundle['content'] ?? '')) === '') {
        $message = implode(' ', array_values($bundle['errors'] ?? [])) ?: 'Nessuna schedina valida da esportare per il giorno selezionato.';
        set_flash('error', $message);
        header('Location: ' . admin_url('anagrafica.php?month=' . rawurlencode($month) . '&day=' . rawurlencode($day) . '#alloggiatiDaySection'));
        exit;
    }

    if (ross1000_day_status_table_ready($pdo)) {
        $state = ross1000_get_day_state($pdo, $day);
        $state['exported_alloggiati_at'] = date('Y-m-d H:i:s');
        ross1000_upsert_day_state($pdo, $day, $state);
    }

    $filename = alloggiati_build_download_filename_for_day($day);
    $content = (string) $bundle['content'];

    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($content));
    echo $content;
    exit;
} catch (Throwable $e) {
    set_flash('error', 'Errore durante la generazione del tracciato Alloggiati: ' . $e->getMessage());
    header('Location: ' . admin_url('anagrafica.php?month=' . rawurlencode($month) . '&day=' . rawurlencode($day) . '#alloggiatiDaySection'));
    exit;
}
