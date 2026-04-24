<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_admin();
verify_csrf();

function imported_pdfs_table_ready(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM admin_imported_pdfs LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function create_imported_pdf_record(PDO $pdo, array $payload): ?int
{
    if (!imported_pdfs_table_ready($pdo)) {
        return null;
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO admin_imported_pdfs (
                source,
                original_name,
                display_name,
                stored_name,
                relative_path,
                mime_type,
                file_size,
                file_hash_sha1,
                parser_status,
                uploaded_by_admin_id
            ) VALUES (
                :source,
                :original_name,
                :display_name,
                :stored_name,
                :relative_path,
                :mime_type,
                :file_size,
                :file_hash_sha1,
                :parser_status,
                :uploaded_by_admin_id
            )'
        );

        $stmt->execute([
            'source' => $payload['source'],
            'original_name' => $payload['original_name'],
            'display_name' => $payload['display_name'],
            'stored_name' => $payload['stored_name'],
            'relative_path' => $payload['relative_path'],
            'mime_type' => $payload['mime_type'],
            'file_size' => $payload['file_size'],
            'file_hash_sha1' => $payload['file_hash_sha1'],
            'parser_status' => $payload['parser_status'],
            'uploaded_by_admin_id' => $payload['uploaded_by_admin_id'],
        ]);

        return (int) $pdo->lastInsertId();
    } catch (Throwable $e) {
        return null;
    }
}


function decode_parser_json_output(string $output): ?array
{
    $trimmed = trim($output);
    if ($trimmed === '') {
        return null;
    }

    $decoded = json_decode($trimmed, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    $startCandidates = [];
    foreach (['{', '['] as $char) {
        $pos = strpos($trimmed, $char);
        if ($pos !== false) {
            $startCandidates[] = $pos;
        }
    }

    if (!$startCandidates) {
        return null;
    }

    $start = min($startCandidates);
    for ($end = strlen($trimmed); $end > $start; $end--) {
        $candidate = substr($trimmed, $start, $end - $start);
        $decoded = json_decode($candidate, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return null;
}

function update_imported_pdf_record(PDO $pdo, ?int $recordId, array $payload): void
{
    if (!$recordId || !imported_pdfs_table_ready($pdo)) {
        return;
    }

    try {
        $fields = [];
        $params = ['id' => $recordId];

        foreach ($payload as $column => $value) {
            $fields[] = $column . ' = :' . $column;
            $params[$column] = $value;
        }

        if (!$fields) {
            return;
        }

        $sql = 'UPDATE admin_imported_pdfs SET ' . implode(', ', $fields) . ' WHERE id = :id LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    } catch (Throwable $e) {
        // Non bloccare il flusso di import se l'archivio PDF non è disponibile.
    }
}

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
$originalName = (string) ($_FILES['interhome_pdf']['name'] ?? 'interhome.pdf');
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

$currentAdmin = current_admin();
$fileRecordId = create_imported_pdf_record($pdo, [
    'source' => 'interhome_pdf',
    'original_name' => $originalName,
    'display_name' => pathinfo($originalName, PATHINFO_FILENAME),
    'stored_name' => $storedBasename,
    'relative_path' => 'storage/imports/' . $storedBasename,
    'mime_type' => function_exists('mime_content_type') ? (mime_content_type($storedPdfPath) ?: 'application/pdf') : 'application/pdf',
    'file_size' => is_file($storedPdfPath) ? filesize($storedPdfPath) : null,
    'file_hash_sha1' => is_file($storedPdfPath) ? sha1_file($storedPdfPath) : null,
    'parser_status' => 'uploaded',
    'uploaded_by_admin_id' => (int) ($currentAdmin['id'] ?? 0) ?: null,
]);

$pythonBin = '/home/u881781553/domains/poderelacavallara.it/public_html/venv/bin/python3';
$parserScript = realpath(__DIR__ . '/../python/interhome_parser.py');

if ($parserScript === false || !file_exists($parserScript)) {
    update_imported_pdf_record($pdo, $fileRecordId, [
        'parser_status' => 'failed',
        'parser_error' => 'Script parser Python non trovato.',
    ]);
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
    update_imported_pdf_record($pdo, $fileRecordId, [
        'parser_status' => 'failed',
        'parser_error' => 'Impossibile avviare il parser Python.',
    ]);
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
    update_imported_pdf_record($pdo, $fileRecordId, [
        'parser_status' => 'failed',
        'parser_exit_code' => $exitCode,
        'parser_error' => trim($stderr) !== '' ? trim($stderr) : 'Il parser Python ha restituito un errore.',
    ]);
    $userError = trim($stderr) !== '' ? trim($stderr) : 'Il parser Python ha restituito un errore.';
    set_flash('error', $userError);
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$data = decode_parser_json_output((string) $stdout);

if (!is_array($data) || empty($data['ok']) || !isset($data['rows']) || !is_array($data['rows'])) {
    update_imported_pdf_record($pdo, $fileRecordId, [
        'parser_status' => 'failed',
        'parser_exit_code' => $exitCode,
        'parser_error' => 'Output parser non valido. ' . substr(trim((string) $stdout), 0, 500),
    ]);
    set_flash('error', 'Output parser non valido. Controlla il log del parser.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$rows = [];
$duplicatesSkipped = 0;

$checkStmt = $pdo->prepare('SELECT id FROM prenotazioni WHERE external_reference = :external_reference LIMIT 1');

foreach ($data['rows'] as $row) {
    $externalReference = trim((string) ($row['external_reference'] ?? ''));

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
    $row['customer_email'] = trim((string) ($row['customer_email'] ?? ''));
    $row['customer_phone'] = trim((string) ($row['customer_phone'] ?? ''));
    $row['notes'] = trim((string) ($row['notes'] ?? '')) ?: null;
    $row['adults'] = (int) ($row['adults'] ?? 0);
    $row['children_count'] = (int) ($row['children_count'] ?? 0);

    $rows[] = $row;
}

update_imported_pdf_record($pdo, $fileRecordId, [
    'parser_status' => 'parsed',
    'parser_exit_code' => $exitCode,
    'parser_error' => null,
    'pages_read' => (int) ($data['summary']['pages_read'] ?? 0),
    'parsed_total' => (int) ($data['summary']['parsed_total'] ?? 0),
    'new_total' => count($rows),
    'duplicates_skipped' => $duplicatesSkipped,
]);

$_SESSION['interhome_import'] = [
    'file_name' => $originalName,
    'file_record_id' => $fileRecordId,
    'pdf_url' => admin_url('storage/imports/' . $storedBasename),
    'pending_confirmation' => !empty($rows),
    'summary' => [
        'pages_read' => (int) ($data['summary']['pages_read'] ?? 0),
        'parsed_total' => (int) ($data['summary']['parsed_total'] ?? 0),
        'new_total' => count($rows),
        'duplicates_skipped' => $duplicatesSkipped,
    ],
    'rows' => $rows,
];

set_flash('success', 'PDF analizzato correttamente.');
header('Location: ' . admin_url('import-interhome-pdf.php'));
exit;
