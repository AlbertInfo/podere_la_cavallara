<?php
require_once __DIR__ . '/includes/auth.php';
require_admin();

$bookingRequests = $pdo->query('SELECT * FROM booking_requests ORDER BY created_at DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
$contactRequests = $pdo->query('SELECT * FROM contact_requests ORDER BY created_at DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
$registeredBookings = $pdo->query('SELECT * FROM prenotazioni ORDER BY created_at DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);

$stats = [
    'booking_requests' => (int) $pdo->query('SELECT COUNT(*) FROM booking_requests')->fetchColumn(),
    'contact_requests' => (int) $pdo->query('SELECT COUNT(*) FROM contact_requests')->fetchColumn(),
    'registered_bookings' => (int) $pdo->query('SELECT COUNT(*) FROM prenotazioni')->fetchColumn(),
    'today_requests' => (int) $pdo->query('SELECT COUNT(*) FROM booking_requests WHERE DATE(created_at) = CURDATE()')->fetchColumn(),
];

require_once __DIR__ . '/includes/header.php';
?>
<section id="overview" class="grid stats">
    <article class="card"><div class="muted">Richieste prenotazione</div><div class="stat-value"><?= $stats['booking_requests'] ?></div><div class="small muted">Totale in archivio</div></article>
    <article class="card"><div class="muted">Richieste contatto</div><div class="stat-value"><?= $stats['contact_requests'] ?></div><div class="small muted">Totale in archivio</div></article>
    <article class="card"><div class="muted">Prenotazioni registrate</div><div class="stat-value"><?= $stats['registered_bookings'] ?></div><div class="small muted">Confermate dall’admin</div></article>
    <article class="card"><div class="muted">Nuove richieste oggi</div><div class="stat-value"><?= $stats['today_requests'] ?></div><div class="small muted">Solo booking requests</div></article>
</section>

<section id="booking-requests" class="card" style="margin-top:20px;">
    <div class="section-title">
        <div>
            <h2>Richieste prenotazione</h2>
            <p class="muted">Da qui puoi eliminare una richiesta o trasformarla in prenotazione confermata.</p>
        </div>
        <div class="toolbar">
            <input class="search-input" type="search" placeholder="Cerca richieste prenotazione..." data-table-filter="#booking-requests-table">
            <a class="btn btn-light" href="<?= e(admin_url('api/bookingcom_sync.php')) ?>">Importa Booking.com</a>
        </div>
    </div>
    <div class="table-wrap">
        <table id="booking-requests-table">
            <thead>
                <tr><th>Data richiesta</th><th>Cliente</th><th>Soggiorno</th><th>Camera</th><th>Persone</th><th>Contatti</th><th>Origine</th><th>Azioni</th></tr>
            </thead>
            <tbody>
            <?php foreach ($bookingRequests as $row): ?>
                <tr data-row-id="<?= (int)$row['id'] ?>">
                    <td><?= e($row['created_at']) ?></td>
                    <td><strong><?= e($row['name_booking']) ?></strong><?php if (!empty($row['message_booking'])): ?><br><span class="small muted"><?= e($row['message_booking']) ?></span><?php endif; ?></td>
                    <td><?= e($row['date_booking']) ?></td>
                    <td><?= e($row['rooms_booking']) ?></td>
                    <td><?= (int)$row['adults_booking'] ?> adulti / <?= (int)$row['childs_booking'] ?> bambini</td>
                    <td><?= e($row['email_booking']) ?><br><span class="small muted"><?= e($row['phone_booking'] ?? '-') ?></span></td>
                    <td><span class="badge <?= ($row['source'] ?? 'website_form') !== 'website_form' ? 'warning' : '' ?>"><?= e($row['source'] ?? 'website_form') ?></span></td>
                    <td>
                        <div class="actions">
                            <form method="post" action="<?= e(admin_url('actions/register-booking.php')) ?>" data-ajax-action="booking" data-success-remove-row="1" data-confirm="Registrare questa richiesta come prenotazione confermata?">
                                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="booking_request_id" value="<?= (int)$row['id'] ?>">
                                <button class="btn btn-success btn-sm" type="submit">Registra prenotazione</button>
                            </form>
                            <form method="post" action="<?= e(admin_url('actions/delete-booking-request.php')) ?>" data-ajax-action="booking" data-success-remove-row="1" data-confirm="Vuoi davvero eliminare questa richiesta?">
                                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="booking_request_id" value="<?= (int)$row['id'] ?>">
                                <button class="btn btn-danger btn-sm" type="submit">Cancella</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section id="contact-requests" class="card" style="margin-top:20px;">
    <div class="section-title">
        <div><h2>Richieste contatto</h2><p class="muted">Storico dei messaggi arrivati dal form informazioni.</p></div>
        <input class="search-input" type="search" placeholder="Cerca richieste contatto..." data-table-filter="#contact-requests-table">
    </div>
    <div class="table-wrap">
        <table id="contact-requests-table">
            <thead><tr><th>Data</th><th>Nome</th><th>Email</th><th>Telefono</th><th>Messaggio</th></tr></thead>
            <tbody>
            <?php foreach ($contactRequests as $row): ?>
                <tr>
                    <td><?= e($row['created_at']) ?></td>
                    <td><?= e(trim(($row['name_contact'] ?? '') . ' ' . ($row['lastname_contact'] ?? ''))) ?></td>
                    <td><?= e($row['email_contact']) ?></td>
                    <td><?= e($row['phone_contact']) ?></td>
                    <td><?= nl2br(e($row['message_contact'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section id="registered-bookings" class="card" style="margin-top:20px;">
    <div class="section-title">
        <div><h2>Prenotazioni registrate</h2><p class="muted">Elenco delle prenotazioni trasferite dall’admin o importate da Booking.com.</p></div>
        <input class="search-input" type="search" placeholder="Cerca prenotazioni..." data-table-filter="#registered-bookings-table">
    </div>
    <div class="table-wrap">
        <table id="registered-bookings-table">
            <thead><tr><th>Data registrazione</th><th>Cliente</th><th>Soggiorno</th><th>Camera</th><th>Persone</th><th>Stato</th><th>Origine</th></tr></thead>
            <tbody>
            <?php foreach ($registeredBookings as $row): ?>
                <tr>
                    <td><?= e($row['created_at']) ?></td>
                    <td><?= e($row['customer_name']) ?><br><span class="small muted"><?= e($row['customer_email']) ?></span></td>
                    <td><?= e($row['stay_period']) ?></td>
                    <td><?= e($row['room_type']) ?></td>
                    <td><?= (int)$row['adults'] ?> adulti / <?= (int)$row['children_count'] ?> bambini</td>
                    <td><span class="badge success"><?= e($row['status']) ?></span></td>
                    <td><span class="badge"><?= e($row['source']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section id="bookingcom" class="card" style="margin-top:20px;">
    <div class="section-title"><div><h2>Predisposizione Booking.com</h2><p class="muted">Endpoint pronto per importare prenotazioni esterne e salvarle nel database locale.</p></div></div>
    <div class="grid two-cols">
        <div><div class="small muted">Endpoint previsto</div><div class="code">POST <?= e(BOOKINGCOM_ENDPOINT) ?></div></div>
        <div><div class="small muted">Configurazione</div><div class="code">BOOKINGCOM_ENABLED / BOOKINGCOM_USERNAME / BOOKINGCOM_PASSWORD / BOOKINGCOM_HOTEL_ID</div></div>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
