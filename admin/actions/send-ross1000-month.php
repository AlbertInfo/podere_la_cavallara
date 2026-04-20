<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ross1000-ws.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . admin_url('anagrafica.php'));
    exit;
}

verify_csrf();

$month = trim((string) ($_POST['month'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    header('Location: ' . admin_url('anagrafica.php'));
    exit;
}

try {
    $config = ross1000_property_config();
    if (!ross1000_day_status_table_ready($pdo)) {
        throw new RuntimeException('Esegui prima la migration della tabella ross1000_day_status.');
    }

    [$fromDate, $toDate] = ross1000_month_range($month);
    $monthEnd = new DateTimeImmutable($toDate);
    $daysInMonth = (int) $monthEnd->format('j');

    $rangeStates = is_array($_POST['range_state'] ?? null) ? array_values($_POST['range_state']) : [];
    $rangeFrom = is_array($_POST['range_from'] ?? null) ? array_values($_POST['range_from']) : [];
    $rangeTo = is_array($_POST['range_to'] ?? null) ? array_values($_POST['range_to']) : [];

    $ranges = [];
    $max = max(count($rangeStates), count($rangeFrom), count($rangeTo));
    for ($i = 0; $i < $max; $i++) {
        $state = (string) ($rangeStates[$i] ?? '');
        $fromDay = (int) ($rangeFrom[$i] ?? 0);
        $toDay = (int) ($rangeTo[$i] ?? 0);
        if (!in_array($state, ['open', 'closed'], true) || $fromDay < 1 || $toDay < 1) {
            continue;
        }
        $fromDay = max(1, min($daysInMonth, $fromDay));
        $toDay = max(1, min($daysInMonth, $toDay));
        if ($fromDay > $toDay) {
            [$fromDay, $toDay] = [$toDay, $fromDay];
        }
        $ranges[] = ['state' => $state, 'from' => $fromDay, 'to' => $toDay];
    }

    $pdo->beginTransaction();
    ross1000_prefill_open_month($pdo, $month, $config, true);
    if ($ranges) {
        $states = ross1000_get_day_states_for_range($pdo, $fromDate, $toDate, $config);
        foreach ($ranges as $range) {
            for ($day = (int) $range['from']; $day <= (int) $range['to']; $day++) {
                $date = sprintf('%s-%02d', $month, $day);
                $current = $states[$date] ?? ross1000_default_day_state($config, $date);
                if ((int) ($current['is_finalized'] ?? 0) === 1) {
                    continue;
                }
                $isOpen = $range['state'] === 'open';
                ross1000_upsert_day_state($pdo, $date, [
                    'day_date' => $date,
                    'is_open' => $isOpen ? 1 : 0,
                    'available_rooms' => $isOpen ? (int) ($config['camere_disponibili'] ?? 0) : 0,
                    'available_beds' => $isOpen ? (int) ($config['letti_disponibili'] ?? 0) : 0,
                    'is_finalized' => (int) ($current['is_finalized'] ?? 0),
                    'finalized_at' => $current['finalized_at'] ?? null,
                    'exported_ross_at' => $current['exported_ross_at'] ?? null,
                    'exported_alloggiati_at' => $current['exported_alloggiati_at'] ?? null,
                ]);
            }
        }
    }
    $pdo->commit();

    $payload = ross1000_build_month_payload($pdo, $month);
    ross1000_ws_send($pdo, $payload, 'month', $month);

    $pdo->beginTransaction();
    $stmt = $pdo->prepare("UPDATE ross1000_day_status SET exported_ross_at = NOW(), updated_at = NOW() WHERE day_date BETWEEN :from_date AND :to_date");
    $stmt->execute(['from_date' => $fromDate, 'to_date' => $toDate]);
    $pdo->commit();

    set_flash('success', 'Invio ROSS1000 del mese completato correttamente.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    set_flash('error', 'Invio ROSS1000 del mese non riuscito: ' . $e->getMessage());
}

header('Location: ' . admin_url('anagrafica.php?month=' . rawurlencode($month)));
exit;
