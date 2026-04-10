<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/customer-sync.php';
require_admin();
verify_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . admin_url('clienti.php'));
    exit;
}

if (!customer_sync_table_exists($pdo)) {
    set_flash('error', 'Tabella clienti non disponibile. Esegui prima la migration dello storico clienti.');
    header('Location: ' . admin_url('clienti.php'));
    exit;
}

$bookings = $pdo->query('SELECT * FROM prenotazioni ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);

$linked = 0;
foreach ($bookings as $booking) {
    $customerId = customer_sync_booking_row($pdo, $booking, 'prenotazioni_backfill');
    if ($customerId !== null) {
        $linked++;
    }
}

set_flash('success', 'Sincronizzazione completata: ' . $linked . ' prenotazioni collegate allo storico clienti.');
header('Location: ' . admin_url('clienti.php'));
exit;
