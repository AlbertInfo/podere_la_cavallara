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

if (empty($_FILES['interhome_pdf']) || !is_array($_FILES['interhome_pdf'])) {
    set_flash('error', 'Nessun file PDF caricato.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$file = $_FILES['interhome_pdf'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    set_flash('error', 'Errore durante l\'upload del PDF.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$tmpName = (string) ($file['tmp_name'] ?? '');
$originalName = (string) ($file['name'] ?? 'interhome.pdf');
$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
if ($extension !== 'pdf') {
    set_flash('error', 'Carica un file PDF valido.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$sessionDir = sys_get_temp_dir() . '/interhome_admin_uploads';
if (!is_dir($sessionDir) && !mkdir($sessionDir, 0775, true) && !is_dir($sessionDir)) {
    set_flash('error', 'Impossibile creare la cartella temporanea di lavoro.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$target = $sessionDir . '/interhome_' . session_id() . '_' . bin2hex(random_bytes(6)) . '.pdf';
if (!move_uploaded_file($tmpName, $target)) {
    set_flash('error', 'Impossibile salvare il PDF caricato.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

try {
    $result = interhome_import_parse_pdf($target, $pdo);
    $_SESSION['interhome_import'] = [
        'file' => $target,
        'original_name' => $originalName,
        'rows' => $result['rows'],
        'summary' => $result['summary'],
        'created_at' => time(),
    ];

    set_flash('success', 'PDF analizzato correttamente.');
} catch (Throwable $e) {
    @unlink($target);
    unset($_SESSION['interhome_import']);
    set_flash('error', 'Analisi PDF interrotta: ' . $e->getMessage());
}

header('Location: ' . admin_url('import-interhome-pdf.php'));
exit;
