<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_admin();
verify_csrf();

$import = $_SESSION['interhome_import'] ?? null;
if (!$import) {
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

if (!empty($_POST['clear_all'])) {
    unset($_SESSION['interhome_import']);
    set_flash('success', 'Elenco importato svuotato.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

if (!empty($_POST['dismiss_modal'])) {
    $_SESSION['interhome_import']['pending_confirmation'] = false;
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$rowId = trim((string) ($_POST['row_id'] ?? ''));
if ($rowId === '') {
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$_SESSION['interhome_import']['rows'] = array_values(array_filter($import['rows'], static fn(array $row): bool => ($row['import_row_id'] ?? '') !== $rowId));
$_SESSION['interhome_import']['summary']['new_total'] = count($_SESSION['interhome_import']['rows']);
$_SESSION['interhome_import']['pending_confirmation'] = false;
set_flash('success', 'Riga rimossa dall’elenco importato.');
header('Location: ' . admin_url('import-interhome-pdf.php'));
exit;
