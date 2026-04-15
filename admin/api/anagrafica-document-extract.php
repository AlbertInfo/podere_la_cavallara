<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/anagrafica-options.php';

require_admin();

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metodo non consentito.'], JSON_UNESCAPED_UNICODE);
    exit;
}

verify_csrf();

function json_error(string $message, int $status = 422, array $extra = []): never
{
    http_response_code($status);
    echo json_encode(array_merge(['success' => false, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function upload_table_ready(PDO $pdo): bool
{
    try {
        return (bool) $pdo->query("SHOW TABLES LIKE 'anagrafica_document_uploads'")->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function allowed_image_extension(string $name): ?string
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) ? $ext : null;
}

function normalize_citizenship_label(?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $map = [
        'IT' => 'Italia', 'ITA' => 'Italia', 'ITALY' => 'Italia',
        'FR' => 'Francia', 'FRA' => 'Francia',
        'DE' => 'Germania', 'DEU' => 'Germania',
        'ES' => 'Spagna', 'ESP' => 'Spagna',
        'PT' => 'Portogallo', 'PRT' => 'Portogallo',
        'NL' => 'Paesi Bassi', 'NLD' => 'Paesi Bassi',
        'BE' => 'Belgio', 'BEL' => 'Belgio',
        'AT' => 'Austria', 'AUT' => 'Austria',
        'RO' => 'Romania', 'ROU' => 'Romania',
        'PL' => 'Polonia', 'POL' => 'Polonia',
        'HR' => 'Croazia', 'HRV' => 'Croazia',
        'SI' => 'Slovenia', 'SVN' => 'Slovenia',
        'SK' => 'Slovacchia', 'SVK' => 'Slovacchia',
        'CZ' => 'Cechia', 'CZE' => 'Cechia',
        'HU' => 'Ungheria', 'HUN' => 'Ungheria',
        'IE' => 'Irlanda', 'IRL' => 'Irlanda',
        'SE' => 'Svezia', 'SWE' => 'Svezia',
        'FI' => 'Finlandia', 'FIN' => 'Finlandia',
        'DK' => 'Danimarca', 'DNK' => 'Danimarca',
        'BG' => 'Bulgaria', 'BGR' => 'Bulgaria',
        'GR' => 'Grecia', 'GRC' => 'Grecia',
        'CY' => 'Cipro', 'CYP' => 'Cipro',
        'MT' => 'Malta', 'MLT' => 'Malta',
        'EE' => 'Estonia', 'EST' => 'Estonia',
        'LV' => 'Lettonia', 'LVA' => 'Lettonia',
        'LT' => 'Lituania', 'LTU' => 'Lituania',
        'LU' => 'Lussemburgo', 'LUX' => 'Lussemburgo',
    ];

    $upper = strtoupper($value);
    return $map[$upper] ?? ucfirst(mb_strtolower($value, 'UTF-8'));
}

function normalize_document_type(?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    $upper = strtoupper(trim($value));
    if ($upper === '') {
        return null;
    }

    if (str_starts_with($upper, 'P')) {
        return 'passaporto';
    }

    if (str_starts_with($upper, 'I')) {
        return 'carta_identita';
    }

    return 'altro';
}

function sanitize_filename(string $base): string
{
    $base = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $base) ?: 'document';
    return trim($base, '-') ?: 'document';
}

$front = $_FILES['document_front'] ?? null;
$back = $_FILES['document_back'] ?? null;

if ((!is_array($front) || ($front['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) && (!is_array($back) || ($back['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)) {
    json_error('Carica almeno una foto del documento.');
}

$storageRoot = realpath(__DIR__ . '/../storage');
if ($storageRoot === false) {
    json_error('Cartella storage non trovata.', 500);
}

$documentsDir = $storageRoot . '/anagrafica-documents';
if (!is_dir($documentsDir) && !mkdir($documentsDir, 0775, true) && !is_dir($documentsDir)) {
    json_error('Impossibile creare la cartella storage/anagrafica-documents.', 500);
}

if (!is_writable($documentsDir)) {
    json_error('La cartella storage/anagrafica-documents non è scrivibile.', 500);
}

$monthDir = $documentsDir . '/' . date('Y-m');
if (!is_dir($monthDir) && !mkdir($monthDir, 0775, true) && !is_dir($monthDir)) {
    json_error('Impossibile creare la sottocartella mensile dei documenti.', 500);
}

$guestSlot = max(0, (int) ($_POST['guest_slot'] ?? 0));
$stored = [];

foreach (['front' => $front, 'back' => $back] as $side => $file) {
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        continue;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        json_error('Caricamento immagine non riuscito per il lato ' . $side . '.');
    }

    $ext = allowed_image_extension((string) ($file['name'] ?? ''));
    if ($ext === null) {
        json_error('Sono supportati solo file JPG, PNG o WEBP.');
    }

    $basename = sanitize_filename('guest-' . $guestSlot . '-' . $side . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)));
    $target = $monthDir . '/' . $basename . '.' . $ext;

    if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
        json_error('Impossibile salvare l\'immagine caricata.', 500);
    }

    $stored[$side] = [
        'absolute' => $target,
        'relative' => 'storage/anagrafica-documents/' . date('Y-m') . '/' . basename($target),
        'original_name' => (string) ($file['name'] ?? ($side . '.' . $ext)),
    ];
}

$pythonCandidates = ['/usr/bin/python3', '/usr/local/bin/python3', 'python3'];
$pythonBin = null;
foreach ($pythonCandidates as $candidate) {
    if ($candidate === 'python3') {
        $pythonBin = 'python3';
        break;
    }
    if (is_file($candidate) && is_executable($candidate)) {
        $pythonBin = $candidate;
        break;
    }
}

$parserScript = realpath(__DIR__ . '/../python/document_reader.py');
if ($parserScript === false || !is_file($parserScript)) {
    json_error('Script Python document_reader.py non trovato.', 500);
}

if ($pythonBin === null) {
    json_error('Python 3 non disponibile sul server.', 500);
}

$command = [$pythonBin, $parserScript];
if (isset($stored['front'])) {
    $command[] = '--front';
    $command[] = $stored['front']['absolute'];
}
if (isset($stored['back'])) {
    $command[] = '--back';
    $command[] = $stored['back']['absolute'];
}

$descriptorspec = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$process = proc_open($command, $descriptorspec, $pipes, dirname(__DIR__));
if (!is_resource($process)) {
    json_error('Impossibile avviare il processo Python.', 500);
}

fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
fclose($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[2]);
$exitCode = proc_close($process);

$payload = json_decode((string) $stdout, true);
if (!is_array($payload)) {
    json_error('Il parser Python non ha restituito un JSON valido.', 500, ['stderr' => trim((string) $stderr)]);
}

if (($payload['success'] ?? false) !== true) {
    json_error((string) ($payload['message'] ?? 'Nessun dato utile estratto.'), 422, [
        'warnings' => $payload['warnings'] ?? [],
        'diagnostics' => $payload['diagnostics'] ?? [],
    ]);
}

$normalizedFields = [];
foreach (($payload['fields'] ?? []) as $field => $meta) {
    if (!is_array($meta)) {
        continue;
    }

    $value = $meta['value'] ?? null;
    if ($field === 'citizenship_label') {
        $value = normalize_citizenship_label(is_string($value) ? $value : null);
    }
    if ($field === 'document_type') {
        $value = normalize_document_type(is_string($value) ? $value : null);
    }

    if ($value === null || $value === '') {
        continue;
    }

    $normalizedFields[$field] = [
        'value' => $value,
        'confidence' => (float) ($meta['confidence'] ?? 0),
        'source' => (string) ($meta['source'] ?? 'ocr'),
    ];
}

$uploadId = null;
if (upload_table_ready($pdo)) {
    try {
        $pdo->beginTransaction();

        $uploadStmt = $pdo->prepare('INSERT INTO anagrafica_document_uploads (guest_slot, front_relative_path, back_relative_path, status, engine, uploaded_by_admin_id, created_at, updated_at) VALUES (:guest_slot, :front_relative_path, :back_relative_path, :status, :engine, :uploaded_by_admin_id, NOW(), NOW())');
        $uploadStmt->execute([
            'guest_slot' => $guestSlot,
            'front_relative_path' => $stored['front']['relative'] ?? null,
            'back_relative_path' => $stored['back']['relative'] ?? null,
            'status' => 'processed',
            'engine' => implode(', ', (array) ($payload['diagnostics']['engines'] ?? [])),
            'uploaded_by_admin_id' => (int) (current_admin()['id'] ?? 0) ?: null,
        ]);
        $uploadId = (int) $pdo->lastInsertId();

        $extractStmt = $pdo->prepare('INSERT INTO anagrafica_document_extractions (upload_id, status, raw_payload_json, normalized_fields_json, error_message, created_at, updated_at) VALUES (:upload_id, :status, :raw_payload_json, :normalized_fields_json, :error_message, NOW(), NOW())');
        $extractStmt->execute([
            'upload_id' => $uploadId,
            'status' => 'success',
            'raw_payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'normalized_fields_json' => json_encode($normalizedFields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'error_message' => trim((string) $stderr) !== '' ? trim((string) $stderr) : null,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}

echo json_encode([
    'success' => true,
    'message' => 'Analisi completata.',
    'upload_id' => $uploadId,
    'fields' => $normalizedFields,
    'warnings' => $payload['warnings'] ?? [],
    'diagnostics' => $payload['diagnostics'] ?? [],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
