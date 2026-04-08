<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_admin();
verify_csrf();

$import = $_SESSION['interhome_import'] ?? null;
if (!$import) {
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$displayName = trim((string) ($_POST['display_name'] ?? ''));
if ($displayName === '') {
    $displayName = pathinfo((string) ($import['file_name'] ?? 'Import PDF'), PATHINFO_FILENAME);
}

$_SESSION['interhome_import']['display_name'] = mb_substr($displayName, 0, 120);
set_flash('success', 'Nome file aggiornato.');
header('Location: ' . admin_url('import-interhome-pdf.php'));
exit;
