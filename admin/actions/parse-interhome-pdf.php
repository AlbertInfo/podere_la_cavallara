<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/interhome_pdf_import.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

verify_csrf();

if (!isset($_FILES['pdf_file']) || !is_array($_FILES['pdf_file'])) {
    set_flash('error', 'Seleziona un file PDF da analizzare.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$file = $_FILES['pdf_file'];
if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    set_flash('error', 'Upload non riuscito. Riprova.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$name = (string)($file['name'] ?? '');
$tmpName = (string)($file['tmp_name'] ?? '');

if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf') {
    set_flash('error', 'Il file deve essere in formato PDF.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

try {
    $result = interhome_import_parse_pdf($tmpName, $pdo);

    $_SESSION['interhome_import'] = [
        'rows' => $result['rows'],
        'summary' => $result['summary'],
        'filename' => $name,
        'generated_at' => date('Y-m-d H:i:s'),
    ];

    if (empty($result['rows'])) {
        set_flash('success', 'Non ci sono nuove prenotazioni nel file PDF.');
    } else {
        set_flash('success', 'Analisi completata: ' . count($result['rows']) . ' nuove prenotazioni trovate.');
    }

    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
} catch (Throwable $e) {
    set_flash('error', 'Analisi PDF interrotta: ' . $e->getMessage());
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}
?>