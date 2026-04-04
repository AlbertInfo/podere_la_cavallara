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

if (empty($_FILES['interhome_pdf']) || !is_array($_FILES['interhome_pdf'])) {
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
$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
if ($ext !== 'pdf') {
    set_flash('error', 'Il file caricato deve essere un PDF.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$tmpPdf = sys_get_temp_dir() . '/interhome_upload_' . bin2hex(random_bytes(8)) . '.pdf';
if (!move_uploaded_file($file['tmp_name'], $tmpPdf)) {
    set_flash('error', 'Impossibile salvare temporaneamente il PDF caricato.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

try {
    $result = interhome_import_parse_pdf($tmpPdf, $pdo);

    $_SESSION['interhome_import_rows'] = [];
    foreach ($result['rows'] as $row) {
        $key = sha1(
            ($row['external_reference'] ?? '') . '|' .
            ($row['stay_period'] ?? '') . '|' .
            ($row['customer_name'] ?? '') . '|' .
            ($row['room_type'] ?? '') . '|' .
            random_int(1000, 9999)
        );
        $_SESSION['interhome_import_rows'][$key] = $row;
    }

    $_SESSION['interhome_import_meta'] = [
        'uploaded_name' => $originalName,
        'pages_total' => (int) ($result['summary']['pages'] ?? 0),
        'parsed_total' => (int) ($result['summary']['parsed_total'] ?? count($result['rows'])),
        'importable_total' => count($_SESSION['interhome_import_rows']),
        'cancelled_total' => (int) ($result['summary']['cancelled_total'] ?? 0),
        'duplicates_total' => (int) ($result['summary']['duplicates_total'] ?? 0),
    ];

    set_flash('success', 'PDF Interhome analizzato correttamente. Seleziona una prenotazione per confermarla.');
} catch (Throwable $e) {
    unset($_SESSION['interhome_import_rows'], $_SESSION['interhome_import_meta']);
    set_flash('error', 'Analisi PDF interrotta: ' . $e->getMessage());
} finally {
    if (file_exists($tmpPdf)) {
        @unlink($tmpPdf);
    }
}

header('Location: ' . admin_url('import-interhome-pdf.php'));
exit;
