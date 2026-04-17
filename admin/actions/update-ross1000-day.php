<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ross1000.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . admin_url('anagrafica.php'));
    exit;
}

verify_csrf();

function redirect_day(string $month, string $day, string $type, string $message): never
{
    set_flash($type, $message);
    header('Location: ' . admin_url('anagrafica.php?month=' . rawurlencode($month) . '&day=' . rawurlencode($day)));
    exit;
}

$day = trim((string) ($_POST['day'] ?? ''));
$month = trim((string) ($_POST['month'] ?? ''));
$intent = trim((string) ($_POST['intent'] ?? 'save'));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || !preg_match('/^\d{4}-\d{2}$/', $month)) {
    header('Location: ' . admin_url('anagrafica.php'));
    exit;
}

if (!ross1000_day_status_table_ready($pdo)) {
    redirect_day($month, $day, 'error', 'Esegui prima la migration della tabella ross1000_day_status.');
}

$config = ross1000_property_config();
$isOpen = isset($_POST['is_open']) ? 1 : 0;
$availableRoomsRaw = trim((string) ($_POST['available_rooms'] ?? ''));
$availableBedsRaw = trim((string) ($_POST['available_beds'] ?? ''));

$availableRooms = $availableRoomsRaw === '' ? ($isOpen ? (int) ($config['camere_disponibili'] ?? 0) : 0) : max(0, (int) $availableRoomsRaw);
$availableBeds = $availableBedsRaw === '' ? ($isOpen ? (int) ($config['letti_disponibili'] ?? 0) : 0) : max(0, (int) $availableBedsRaw);

$state = [
    'day_date' => $day,
    'is_open' => $isOpen,
    'available_rooms' => $availableRooms,
    'available_beds' => $availableBeds,
    'is_finalized' => 0,
];

$records = ross1000_fetch_records_for_range($pdo, $day, $day);
$snapshot = ross1000_build_day_snapshot($day, $records, $state, $config);

if (!$isOpen && ($snapshot['occupied_rooms'] > 0 || $snapshot['arrivals_guests'] > 0 || $snapshot['departures_guests'] > 0 || $snapshot['booking_records_count'] > 0)) {
    redirect_day($month, $day, 'error', 'Non puoi impostare il giorno come chiuso perché contiene movimenti o occupazione.');
}

$isFinalized = 0;
$finalizedAt = null;
$message = 'Impostazioni del giorno salvate.';

if ($intent === 'close') {
    $isFinalized = 1;
    $finalizedAt = date('Y-m-d H:i:s');
    $message = 'Giorno chiuso correttamente. Ora puoi esportare il file ROSS1000.';
} elseif ($intent === 'reopen') {
    $isFinalized = 0;
    $finalizedAt = null;
    $message = 'Giorno riaperto correttamente.';
} else {
    $current = ross1000_get_day_state($pdo, $day, $config);
    $isFinalized = (int) ($current['is_finalized'] ?? 0);
    $finalizedAt = $current['finalized_at'] ?? null;
}

$sql = "
    INSERT INTO ross1000_day_status
        (day_date, is_open, available_rooms, available_beds, is_finalized, finalized_at, created_at, updated_at)
    VALUES
        (:day_date, :is_open, :available_rooms, :available_beds, :is_finalized, :finalized_at, NOW(), NOW())
    ON DUPLICATE KEY UPDATE
        is_open = VALUES(is_open),
        available_rooms = VALUES(available_rooms),
        available_beds = VALUES(available_beds),
        is_finalized = VALUES(is_finalized),
        finalized_at = VALUES(finalized_at),
        updated_at = NOW()
";
$stmt = $pdo->prepare($sql);
$stmt->execute([
    'day_date' => $day,
    'is_open' => $isOpen,
    'available_rooms' => $availableRooms,
    'available_beds' => $availableBeds,
    'is_finalized' => $isFinalized,
    'finalized_at' => $finalizedAt,
]);

redirect_day($month, $day, 'success', $message);
