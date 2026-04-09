<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_admin();
verify_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Metodo non consentito.');
}

if (
    !isset($_FILES['interhome_pdf']) ||
    !is_array($_FILES['interhome_pdf']) ||
    ($_FILES['interhome_pdf']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
) {
    set_flash('error', 'Caricamento PDF non riuscito.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$tmpFile = $_FILES['interhome_pdf']['tmp_name'];
$originalName = (string)($_FILES['interhome_pdf']['name'] ?? 'interhome.pdf');
$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

if ($extension !== 'pdf') {
    set_flash('error', 'Il file caricato deve essere un PDF.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$storageRoot = realpath(__DIR__ . '/../storage');
if ($storageRoot === false) {
    set_flash('error', 'Cartella storage non trovata.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$importsDir = $storageRoot . '/imports';
$logsDir = $storageRoot . '/parser-logs';

if (!is_dir($importsDir) || !is_writable($importsDir)) {
    set_flash('error', 'La cartella storage/imports non è disponibile in scrittura.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

if (!is_dir($logsDir) || !is_writable($logsDir)) {
    set_flash('error', 'La cartella storage/parser-logs non è disponibile in scrittura.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$storedBasename = 'interhome_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';
$storedPdfPath = $importsDir . '/' . $storedBasename;

if (!move_uploaded_file($tmpFile, $storedPdfPath)) {
    set_flash('error', 'Impossibile salvare il PDF caricato.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$pythonBin = '/usr/bin/python3';
$parserScript = realpath(__DIR__ . '/../python/interhome_parser.py');

if ($parserScript === false || !file_exists($parserScript)) {
    set_flash('error', 'Script parser Python non trovato.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$descriptorspec = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$process = proc_open(
    [$pythonBin, $parserScript, $storedPdfPath],
    $descriptorspec,
    $pipes,
    dirname(__DIR__)
);

if (!is_resource($process)) {
    set_flash('error', 'Impossibile avviare il parser Python.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

fclose($pipes[0]);

$stdout = stream_get_contents($pipes[1]);
fclose($pipes[1]);

$stderr = stream_get_contents($pipes[2]);
fclose($pipes[2]);

$exitCode = proc_close($process);

$logPayload = [
    'time' => date('c'),
    'file' => $storedBasename,
    'exit_code' => $exitCode,
    'stdout' => $stdout,
    'stderr' => $stderr,
];

file_put_contents(
    $logsDir . '/interhome_parser_' . date('Ymd_His') . '.log',
    json_encode($logPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

if ($exitCode !== 0) {
    set_flash('error', 'Il parser Python ha restituito un errore.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$data = json_decode($stdout, true);

if (!is_array($data) || empty($data['ok']) || !isset($data['rows']) || !is_array($data['rows'])) {
    set_flash('error', 'Output parser non valido.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$rows = [];
$duplicatesSkipped = 0;

$checkStmt = $pdo->prepare('SELECT id FROM prenotazioni WHERE external_reference = :external_reference LIMIT 1');

foreach ($data['rows'] as $row) {
    $externalReference = trim((string)($row['external_reference'] ?? ''));

    if ($externalReference === '') {
        continue;
    }

    $checkStmt->execute([
        'external_reference' => $externalReference,
    ]);

    if ($checkStmt->fetch(PDO::FETCH_ASSOC)) {
        $duplicatesSkipped++;
        continue;
    }

    $row['import_row_id'] = 'imp_' . bin2hex(random_bytes(8));
    $row['source'] = 'interhome_pdf';
    $row['status'] = $row['status'] ?? 'confermata';
    $row['customer_email'] = trim((string)($row['customer_email'] ?? ''));
    $row['customer_phone'] = trim((string)($row['customer_phone'] ?? ''));
    $row['notes'] = trim((string)($row['notes'] ?? '')) ?: null;
    $row['adults'] = (int)($row['adults'] ?? 0);
    $row['children_count'] = (int)($row['children_count'] ?? 0);

    $rows[] = $row;
}

$_SESSION['interhome_import'] = [
    'file_name' => $originalName,
    'pdf_url' => admin_url('storage/imports/' . $storedBasename),
    'pending_confirmation' => !empty($rows),
    'summary' => [
        'pages_read' => (int)($data['summary']['pages_read'] ?? 0),
        'parsed_total' => (int)($data['summary']['parsed_total'] ?? 0),
        'new_total' => count($rows),
        'duplicates_skipped' => $duplicatesSkipped,
    ],
    'rows' => $rows,
];

set_flash('success', 'PDF analizzato correttamente.');
header('Location: ' . admin_url('import-interhome-pdf.php'));
exit;