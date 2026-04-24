<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();
verify_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response([
        'success' => false,
        'message' => 'Metodo non consentito.',
    ], 405);
}

$config = require __DIR__ . '/../includes/document-ocr-config.php';

if (empty($config['enabled'])) {
    json_response([
        'success' => false,
        'message' => 'Servizio OCR non attivo.',
    ], 503);
}

if (trim((string) ($config['endpoint'] ?? '')) === '') {
    json_response([
        'success' => false,
        'message' => 'Endpoint Document OCR non configurato.',
    ], 500);
}

$storageRoot = realpath(__DIR__ . '/../storage');
if ($storageRoot === false) {
    json_response([
        'success' => false,
        'message' => 'Cartella storage non trovata.',
    ], 500);
}

$uploadDir = $storageRoot . '/document-ocr/uploads';
$resultDir = $storageRoot . '/document-ocr/results';
$logDir = $storageRoot . '/parser-logs';

foreach ([$uploadDir, $resultDir, $logDir] as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        json_response([
            'success' => false,
            'message' => 'Impossibile preparare le cartelle OCR in storage.',
        ], 500);
    }
    if (!is_writable($dir)) {
        json_response([
            'success' => false,
            'message' => 'La cartella OCR non è scrivibile: ' . basename($dir),
        ], 500);
    }
}

function ocr_fail(string $message, int $status = 422): void
{
    json_response([
        'success' => false,
        'message' => $message,
    ], $status);
}

function ocr_safe_basename(string $prefix, string $originalName): string
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]/', '', (string) $ext);
    if ($ext === '') {
        $ext = 'bin';
    }

    return sprintf('%s_%s_%s.%s', $prefix, date('Ymd_His'), bin2hex(random_bytes(4)), $ext);
}

function ocr_store_upload(array $file, string $prefix, string $targetDir, array $acceptedMimeTypes, int $maxSize): array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Caricamento file non riuscito.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('Upload non valido.');
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > $maxSize) {
        throw new RuntimeException('Il file supera la dimensione massima consentita.');
    }

    $mime = function_exists('mime_content_type') ? (mime_content_type($tmpName) ?: '') : '';
    if ($mime === '' || !in_array($mime, $acceptedMimeTypes, true)) {
        throw new RuntimeException('Formato file non supportato. Usa JPG, PNG, WEBP o PDF.');
    }

    $originalName = (string) ($file['name'] ?? ($prefix . '.bin'));
    $storedName = ocr_safe_basename($prefix, $originalName);
    $targetPath = rtrim($targetDir, '/') . '/' . $storedName;

    if (!move_uploaded_file($tmpName, $targetPath)) {
        throw new RuntimeException('Impossibile salvare il file caricato.');
    }

    return [
        'path' => $targetPath,
        'stored_name' => $storedName,
        'mime' => $mime,
        'size' => $size,
        'original_name' => $originalName,
    ];
}

if (!isset($_FILES['document_front']) || !is_array($_FILES['document_front'])) {
    ocr_fail('Carica almeno il fronte del documento.');
}

$acceptedMimeTypes = array_values(array_filter(array_map('strval', (array) ($config['accepted_mime_types'] ?? []))));
$maxFileSize = max(1, (int) ($config['max_file_size_bytes'] ?? 10 * 1024 * 1024));

try {
    $frontFile = ocr_store_upload($_FILES['document_front'], 'front', $uploadDir, $acceptedMimeTypes, $maxFileSize);
} catch (Throwable $e) {
    ocr_fail('Fronte documento: ' . $e->getMessage());
}

$backFile = null;
if (isset($_FILES['document_back']) && is_array($_FILES['document_back']) && (int) ($_FILES['document_back']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    try {
        $backFile = ocr_store_upload($_FILES['document_back'], 'back', $uploadDir, $acceptedMimeTypes, $maxFileSize);
    } catch (Throwable $e) {
        ocr_fail('Retro documento: ' . $e->getMessage());
    }
}

$pythonScript = realpath(__DIR__ . '/../python/google_document_ocr.py');
if ($pythonScript === false || !is_file($pythonScript)) {
    ocr_fail('Script Python OCR non trovato.', 500);
}

$credentialsPath = trim((string) ($config['credentials_path'] ?? ''));
if ($credentialsPath !== '' && !preg_match('/^(\/|[A-Za-z]:\\)/', $credentialsPath)) {
    $resolvedRelative = realpath(dirname(__DIR__) . '/' . ltrim($credentialsPath, '/'));
    if ($resolvedRelative !== false) {
        $credentialsPath = $resolvedRelative;
    }
}

if ($credentialsPath === '' && trim((string) ($config['bearer_token'] ?? '')) === '') {
    ocr_fail('Credenziali OCR mancanti: configura il percorso del service account JSON o un bearer token valido.', 500);
}

if ($credentialsPath !== '') {
    if (!file_exists($credentialsPath)) {
        ocr_fail('Il file credenziali OCR non esiste nel percorso configurato.', 500);
    }
    if (!is_readable($credentialsPath)) {
        ocr_fail('Il file credenziali OCR non è leggibile dal server.', 500);
    }
}

$runtimeConfig = [
    'endpoint' => (string) ($config['endpoint'] ?? ''),
    'credentials_path' => $credentialsPath,
    'bearer_token' => (string) ($config['bearer_token'] ?? ''),
    'timeout_seconds' => (int) ($config['timeout_seconds'] ?? 90),
];

$stdinPayload = [
    'front_path' => $frontFile['path'],
    'back_path' => $backFile['path'] ?? '',
    'data_dir' => dirname(__DIR__) . '/data/alloggiati',
    'config' => $runtimeConfig,
];

$descriptorspec = [
    0 => ['pipe', 'w'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$process = proc_open(
    ['/home/u881781553/domains/poderelacavallara.it/public_html/venv/bin/python3', $pythonScript, '--stdin-json'],
    $descriptorspec,
    $pipes,
    dirname(__DIR__)
);

if (!is_resource($process)) {
    ocr_fail('Impossibile avviare il processo OCR Python.', 500);
}

fwrite($pipes[0], json_encode($stdinPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
fclose($pipes[0]);

$stdout = stream_get_contents($pipes[1]);
fclose($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[2]);
$exitCode = proc_close($process);

$logId = 'document_ocr_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
$logPath = $logDir . '/' . $logId . '.log';
file_put_contents($logPath, json_encode([
    'time' => date('c'),
    'front' => $frontFile,
    'back' => $backFile,
    'exit_code' => $exitCode,
    'stdout' => $stdout,
    'stderr' => $stderr,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

if ($exitCode !== 0) {
    ocr_fail(trim($stderr) !== '' ? trim($stderr) : 'Il parser OCR ha restituito un errore.', 500);
}

$parsed = json_decode((string) $stdout, true);
if (!is_array($parsed) || empty($parsed['ok']) || !isset($parsed['result']) || !is_array($parsed['result'])) {
    ocr_fail('La risposta OCR non è valida.', 500);
}

if (!empty($config['store_raw_responses'])) {
    $resultPath = $resultDir . '/' . $logId . '.json';
    file_put_contents($resultPath, json_encode($parsed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $parsed['stored_result'] = 'storage/document-ocr/results/' . basename($resultPath);
}

json_response([
    'success' => true,
    'message' => 'Documento analizzato. Controlla il riepilogo e applica i dati al form.',
    'result' => [
        'form_payload' => (array) ($parsed['result']['form_payload'] ?? []),
        'display_payload' => (array) ($parsed['result']['display_payload'] ?? []),
        'warnings' => array_values(array_filter(array_map('strval', (array) ($parsed['result']['warnings'] ?? [])))),
        'documents' => array_values(array_filter((array) ($parsed['result']['documents'] ?? []))),
        'raw' => (array) ($parsed['result']['raw'] ?? []),
        'stored_result' => $parsed['stored_result'] ?? null,
        'log_file' => 'storage/parser-logs/' . basename($logPath),
    ],
]);
