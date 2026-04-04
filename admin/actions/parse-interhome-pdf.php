<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/interhome_pdf_import.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

verify_csrf();

if (!isset($_FILES['interhome_pdf']) || !is_array($_FILES['interhome_pdf'])) {
    set_flash('error', 'Seleziona un PDF Interhome da analizzare.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$file = $_FILES['interhome_pdf'];

if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    set_flash('error', 'Upload del PDF non riuscito.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$originalName = (string) ($file['name'] ?? 'interhome.pdf');
$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
$mimeType = mime_content_type($file['tmp_name']) ?: '';

if ($extension !== 'pdf' && $mimeType !== 'application/pdf') {
    set_flash('error', 'Carica un file PDF valido.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$tempPath = sys_get_temp_dir() . '/interhome_upload_' . bin2hex(random_bytes(8)) . '.pdf';
if (!move_uploaded_file($file['tmp_name'], $tempPath)) {
    set_flash('error', 'Impossibile salvare temporaneamente il PDF sul server.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

try {
    $result = interhome_pdf_parse($tempPath, $pdo);
    $_SESSION['interhome_import'] = [
        'file_name' => $originalName,
        'generated_at' => date('Y-m-d H:i:s'),
        'rows' => $result['rows'],
        'stats' => $result['stats'],
    ];

    set_flash('success', 'PDF Interhome analizzato correttamente.');
} catch (Throwable $e) {
    set_flash('error', 'Analisi PDF non riuscita: ' . $e->getMessage());
}

@unlink($tempPath);

header('Location: ' . admin_url('import-interhome-pdf.php'));
exit;
