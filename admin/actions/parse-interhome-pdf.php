<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/interhome_pdf_import.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

verify_csrf();

if (empty($_FILES['pdf_file']) || !is_uploaded_file($_FILES['pdf_file']['tmp_name'])) {
    set_flash('error', 'Seleziona un PDF valido.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$file = $_FILES['pdf_file'];
$originalName = (string)($file['name'] ?? 'documento.pdf');
$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
if ($ext !== 'pdf') {
    set_flash('error', 'Il file deve essere in formato PDF.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$tmpPath = sys_get_temp_dir() . '/interhome_upload_' . bin2hex(random_bytes(6)) . '.pdf';
if (!move_uploaded_file($file['tmp_name'], $tmpPath)) {
    set_flash('error', 'Impossibile caricare il PDF.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

try {
    $parsed = interhome_import_parse_pdf($tmpPath, $pdo);
    $_SESSION['interhome_import'] = [
        'document_name' => $originalName,
        'summary' => $parsed['summary'],
        'rows' => array_values($parsed['rows']),
        'parsed_at' => date('Y-m-d H:i:s'),
    ];
    set_flash('success', 'PDF analizzato correttamente.');
} catch (Throwable $e) {
    set_flash('error', 'Analisi PDF interrotta: ' . $e->getMessage());
}

@unlink($tmpPath);
header('Location: ' . admin_url('import-interhome-pdf.php'));
exit;
