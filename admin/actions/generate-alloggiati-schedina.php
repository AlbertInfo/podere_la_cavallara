<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/alloggiati.php';
require_admin();

$day = trim((string) ($_GET['day'] ?? ''));
$month = trim((string) ($_GET['month'] ?? ''));
$schedinaId = max(0, (int) ($_GET['schedina_id'] ?? 0));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || !preg_match('/^\d{4}-\d{2}$/', $month) || $schedinaId <= 0) {
    header('Location: ' . admin_url('anagrafica.php'));
    exit;
}

try {
    if (!alloggiati_schedine_table_ready($pdo)) {
        set_flash('error', 'Esegui prima la migration della tabella alloggiati_schedine.');
        header('Location: ' . admin_url('anagrafica.php?month=' . rawurlencode($month) . '&day=' . rawurlencode($day) . '#alloggiatiDaySection'));
        exit;
    }

    $bundle = alloggiati_collect_single_export($pdo, $schedinaId);
    if (($bundle['line_count'] ?? 0) <= 0 || trim((string) ($bundle['content'] ?? '')) === '') {
        $message = implode(' ', array_values($bundle['errors'] ?? [])) ?: 'La schedina selezionata non è esportabile.';
        set_flash('error', $message);
        header('Location: ' . admin_url('anagrafica.php?month=' . rawurlencode($month) . '&day=' . rawurlencode($day) . '#alloggiatiDaySection'));
        exit;
    }

    $schedina = $bundle['schedina'];
    $filename = alloggiati_build_download_filename_for_schedina(is_array($schedina) ? $schedina : []);
    $content = (string) $bundle['content'];

    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($content));
    echo $content;
    exit;
} catch (Throwable $e) {
    set_flash('error', 'Errore durante la generazione della schedina Alloggiati: ' . $e->getMessage());
    header('Location: ' . admin_url('anagrafica.php?month=' . rawurlencode($month) . '&day=' . rawurlencode($day) . '#alloggiatiDaySection'));
    exit;
}
