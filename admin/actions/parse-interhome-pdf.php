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

$privateDir = sys_get_temp_dir() . '/interhome_pdf_uploads';
if (!is_dir($privateDir)) {
    mkdir($privateDir, 0775, true);
}
$tempPath = $privateDir . '/interhome_' . bin2hex(random_bytes(8)) . '.pdf';

if (!move_uploaded_file($file['tmp_name'], $tempPath)) {
    set_flash('error', 'Impossibile preparare il file PDF per l’analisi.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$publicDir = dirname(__DIR__) . '/uploads/interhome';
if (!is_dir($publicDir)) {
    mkdir($publicDir, 0775, true);
}
$publicName = 'interhome_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';
$publicPath = $publicDir . '/' . $publicName;
$publicUrl = admin_url('uploads/interhome/' . $publicName);

try {
    if (!copy($tempPath, $publicPath)) {
        $publicUrl = null;
        $publicName = null;
    }

    $result = interhome_import_parse_pdf($tempPath, $pdo);

    $_SESSION['interhome_import'] = [
        'file_name' => $originalName,
        'display_name' => pathinfo($originalName, PATHINFO_FILENAME),
        'uploaded_at' => date('Y-m-d H:i:s'),
        'file_url' => $publicUrl,
        'file_storage_name' => $publicName,
        'viewer_open' => true,
        'rows' => $result['rows'],
        'summary' => $result['summary'],
        'pending_confirmation' => !empty($result['rows']),
    ];

    if (!empty($result['rows'])) {
        set_flash('success', 'PDF analizzato correttamente. Verifica il riepilogo e conferma il numero di prenotazioni trovate.');
    } else {
        set_flash('success', 'Analisi completata. Non ci sono nuove prenotazioni nel file PDF.');
    }
} catch (Throwable $e) {
    set_flash('error', 'Analisi PDF interrotta: ' . $e->getMessage());
}

@unlink($tempPath);
header('Location: ' . admin_url('import-interhome-pdf.php'));
exit;
