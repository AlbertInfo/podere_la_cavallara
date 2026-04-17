<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ross1000.php';
require_admin();

function redirect_with_flash(string $month, string $day, string $type, string $message): never
{
    set_flash($type, $message);
    header('Location: ' . admin_url('anagrafica.php?month=' . rawurlencode($month) . '&day=' . rawurlencode($day)));
    exit;
}

$day = trim((string) ($_GET['day'] ?? ''));
$month = trim((string) ($_GET['month'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || !preg_match('/^\d{4}-\d{2}$/', $month)) {
    header('Location: ' . admin_url('anagrafica.php'));
    exit;
}

try {
    $state = ross1000_get_day_state($pdo, $day, ross1000_property_config());
    if ((int) ($state['is_finalized'] ?? 0) !== 1) {
        redirect_with_flash($month, $day, 'error', 'Chiudi il giorno prima di esportare il file ROSS1000.');
    }

    $payload = ross1000_build_day_payload($pdo, $day);
    $xml = ross1000_build_xml($payload);

    if (ross1000_day_status_table_ready($pdo)) {
        $stmt = $pdo->prepare("
            INSERT INTO ross1000_day_status (day_date, is_open, available_rooms, available_beds, is_finalized, finalized_at, exported_ross_at, created_at, updated_at)
            VALUES (:day_date, :is_open, :available_rooms, :available_beds, :is_finalized, :finalized_at, NOW(), NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                exported_ross_at = NOW(),
                updated_at = NOW()
        ");
        $stmt->execute([
            'day_date' => $day,
            'is_open' => (int) ($state['is_open'] ?? 1),
            'available_rooms' => (int) ($state['available_rooms'] ?? 0),
            'available_beds' => (int) ($state['available_beds'] ?? 0),
            'is_finalized' => (int) ($state['is_finalized'] ?? 0),
            'finalized_at' => $state['finalized_at'] ?? null,
        ]);
    }

    $filename = 'ross1000-' . date('Ymd', strtotime($day)) . '.xml';
    header('Content-Type: application/xml; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($xml));
    echo $xml;
    exit;
} catch (Throwable $e) {
    redirect_with_flash($month, $day, 'error', 'Esportazione ROSS1000 non riuscita: ' . $e->getMessage());
}
