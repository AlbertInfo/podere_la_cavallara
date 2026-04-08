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

if (!isset($_FILES['interhome_pdf']) || !is_array($_FILES['interhome_pdf'])) {
    set_flash('error', 'Seleziona un file PDF da analizzare.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$file = $_FILES['interhome_pdf'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    set_flash('error', 'Upload del PDF non riuscito.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$originalName = trim((string) ($file['name'] ?? 'arrivi.pdf'));
$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
if ($ext !== 'pdf') {
    set_flash('error', 'Il file caricato deve essere un PDF.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$uploadDir = dirname(__DIR__) . '/uploads/interhome';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
    set_flash('error', 'Impossibile creare la cartella di lavoro per i PDF importati.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

if (!empty($_SESSION['interhome_import']['pdf_disk_path'] ?? '')) {
    $oldPdf = (string) $_SESSION['interhome_import']['pdf_disk_path'];
    if (is_file($oldPdf)) {
        @unlink($oldPdf);
    }
}

$safeBase = preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo($originalName, PATHINFO_FILENAME)) ?: 'interhome-import';
$finalFilename = $safeBase . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.pdf';
$finalDiskPath = $uploadDir . '/' . $finalFilename;

if (!move_uploaded_file((string) $file['tmp_name'], $finalDiskPath)) {
    set_flash('error', 'Impossibile preparare il file PDF per l’analisi.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

try {
    $result = interhome_import_parse_pdf($finalDiskPath, $pdo);
    $_SESSION['interhome_import'] = [
        'file_name' => $originalName,
        'display_name' => pathinfo($originalName, PATHINFO_FILENAME),
        'uploaded_at' => date('Y-m-d H:i:s'),
        'pdf_url' => admin_url('uploads/interhome/' . rawurlencode($finalFilename)),
        'pdf_disk_path' => $finalDiskPath,
        'rows' => $result['rows'],
        'summary' => $result['summary'],
        'pending_confirmation' => !empty($result['rows']),
    ];

    if (!empty($result['rows'])) {
        set_flash('success', 'PDF analizzato correttamente. Controlla il riepilogo e conferma il numero di prenotazioni trovate.');
    } else {
        set_flash('success', 'Analisi completata. Non ci sono nuove prenotazioni nel file PDF.');
    }
} catch (Throwable $e) {
    @unlink($finalDiskPath);
    set_flash('error', 'Analisi PDF interrotta: ' . $e->getMessage());
}

header('Location: ' . admin_url('import-interhome-pdf.php'));
exit;
