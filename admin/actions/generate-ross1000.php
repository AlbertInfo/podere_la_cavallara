<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ross1000.php';
require_admin();

function redirect_with_flash(string $url, string $type, string $message): never
{
    if (function_exists('set_flash')) {
        set_flash($type, $message);
    }
    header('Location: ' . $url);
    exit;
}

$recordId = max(0, (int) ($_GET['record_id'] ?? 0));
if ($recordId <= 0) {
    redirect_with_flash(admin_url('anagrafica.php'), 'error', 'Record non valido per la generazione ROSS1000.');
}

try {
    $payload = ross1000_build_intermediate_array($pdo, $recordId);
    $xml = ross1000_build_xml($payload);

    $record = (array) ($payload['record'] ?? []);
    $safeReference = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($record['booking_reference'] ?? 'anagrafica-' . $recordId));
    $safeReference = trim((string) $safeReference, '-');
    if ($safeReference === '') {
        $safeReference = 'anagrafica-' . $recordId;
    }

    $filename = 'ross1000-' . $safeReference . '.xml';

    header('Content-Type: application/xml; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($xml));
    echo $xml;
    exit;
} catch (Throwable $e) {
    redirect_with_flash(admin_url('anagrafica.php?edit=' . $recordId), 'error', 'Generazione ROSS1000 non riuscita: ' . $e->getMessage());
}
