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

function redirect_month(string $month, string $type, string $message): never
{
    set_flash($type, $message);
    header('Location: ' . admin_url('anagrafica.php?month=' . rawurlencode($month)));
    exit;
}

$month = trim((string) ($_POST['month'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    header('Location: ' . admin_url('anagrafica.php'));
    exit;
}

try {
    $config = ross1000_property_config();
    if (!ross1000_property_config_ready($config)) {
        redirect_month($month, 'error', 'Configura prima codice struttura, camere e letti disponibili in ross1000-config.php.');
    }

    if (!ross1000_day_status_table_ready($pdo)) {
        redirect_month($month, 'error', 'Esegui prima la migration della tabella ross1000_day_status.');
    }

    $pdo->beginTransaction();
    ross1000_prefill_open_month($pdo, $month, $config, true);
    $payload = ross1000_build_month_payload($pdo, $month);

    [$from, $to] = ross1000_month_range($month);
    $stmt = $pdo->prepare("UPDATE ross1000_day_status SET exported_ross_at = NOW(), updated_at = NOW() WHERE day_date BETWEEN :from_date AND :to_date");
    $stmt->execute([
        'from_date' => $from,
        'to_date' => $to,
    ]);
    $pdo->commit();

    $xml = ross1000_build_xml($payload);
    $filename = 'ross1000-' . $month . '.xml';
    header('Content-Type: application/xml; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($xml));
    echo $xml;
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    redirect_month($month, 'error', 'Esportazione mensile ROSS1000 non riuscita: ' . $e->getMessage());
}
