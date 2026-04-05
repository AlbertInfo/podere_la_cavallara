<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

verify_csrf();

$rowKey = trim((string)($_POST['row_key'] ?? ''));
if ($rowKey === '' || !isset($_SESSION['interhome_import']['rows'])) {
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

foreach ($_SESSION['interhome_import']['rows'] as $idx => $row) {
    if ((string)$idx === $rowKey) {
        unset($_SESSION['interhome_import']['rows'][$idx]);
        $_SESSION['interhome_import']['rows'] = array_values($_SESSION['interhome_import']['rows']);
        $_SESSION['interhome_import']['summary']['found_total'] = count($_SESSION['interhome_import']['rows']);
        set_flash('success', 'Riga rimossa dall’elenco letto.');
        break;
    }
}

header('Location: ' . admin_url('import-interhome-pdf.php'));
exit;
