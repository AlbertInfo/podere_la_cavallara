<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
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

$pageTitle = 'Dashboard amministrazione';
require_once __DIR__ . '/includes/header.php';
?>
<section id="overview" class="kpi-row">
    <article class="kpi-card">
        <div class="label">Richieste prenotazione</div>
        <div class="value"><?= $stats['booking_requests'] ?></div>
        <div class="small muted">Totale in archivio</div>
    </article>
    <article class="kpi-card">
        <div class="label">Richieste contatto</div>
        <div class="value"><?= $stats['contact_requests'] ?></div>
        <div class="small muted">Totale in archivio</div>
    </article>
    <article class="kpi-card">
        <div class="label">Prenotazioni registrate</div>
        <div class="value"><?= $stats['registered_bookings'] ?></div>
        <div class="small muted">Confermate dall’admin</div>
    </article>
    <article class="kpi-card">
        <div class="label">Nuove richieste oggi</div>
        <div class="value"><?= $stats['today_requests'] ?></div>
        <div class="small muted">Solo booking requests</div>
    </article>
</section>

<section id="registered-bookings" class="card section-registered" style="margin-top:20px;">
    <div class="section-title">
        <div>
            <h2>Prenotazioni registrate</h2>
            <p class="muted">Elenco delle prenotazioni trasferite dall’admin o inserite manualmente dal gestionale.</p>
        </div>
        <div class="toolbar">
            <input class="search-input" type="search" placeholder="Cerca prenotazioni..." data-table-filter="#registered-bookings-table">
            <a class="btn btn-primary" href="<?= e(admin_url('new-prenotazione.php')) ?>">Nuova prenotazione</a>
        </div>
    </div>
    <div class="table-wrap">
        <table id="registered-bookings-table">
            <thead>
                <tr>
                    <th>Data registrazione</th>
                    <th>Cliente</th>
                    <th>Soggiorno</th>
                    <th>Camera</th>
                    <th>Persone</th>
                    <th>Stato</th>
                    <th>Origine</th>
                    <th>Azioni</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($registeredBookings as $row): ?>
                <tr>
                    <td><?= e($row['created_at']) ?></td>
                    <td>
                        <strong><?= e($row['customer_name']) ?></strong><br>
                        <span class="small muted"><?= e($row['customer_email']) ?></span>
                    </td>
                    <td><?= e($row['stay_period']) ?></td>
                    <td><?= e($row['room_type']) ?></td>
                    <td><?= (int)$row['adults'] ?> adulti / <?= (int)$row['children_count'] ?> bambini</td>
                    <td><span class="badge success"><?= e($row['status']) ?></span></td>
                    <td><span class="badge info"><?= e($row['source']) ?></span></td>
                    <td>
                        <div class="actions">
                            <a class="btn btn-light btn-sm" href="<?= e(admin_url('edit-prenotazione.php?id=' . (int)$row['id'])) ?>">Modifica</a>
                            <form method="post" action="<?= e(admin_url('actions/delete-prenotazione.php')) ?>" data-confirm="Vuoi davvero eliminare questa prenotazione?">
                                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="prenotazione_id" value="<?= (int)$row['id'] ?>">
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

<section id="booking-requests" class="card section-booking" style="margin-top:20px;">
    <div class="section-title">
        <div>
            <h2>Richieste prenotazione</h2>
            <p class="muted">Da qui puoi eliminare una richiesta o trasformarla in prenotazione confermata.</p>
        </div>
        <div class="toolbar">
            <input class="search-input" type="search" placeholder="Cerca richieste prenotazione..." data-table-filter="#booking-requests-table">
        </div>
    </div>
    <div class="table-wrap">
        <table id="booking-requests-table">
            <thead>
                <tr>
                    <th>Data richiesta</th>
                    <th>Cliente</th>
                    <th>Soggiorno</th>
                    <th>Camera</th>
                    <th>Persone</th>
                    <th>Contatti</th>
                    <th>Origine</th>
                    <th>Azioni</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($bookingRequests as $row): ?>
                <tr>
                    <td><?= e($row['created_at']) ?></td>
                    <td>
                        <strong><?= e($row['name_booking']) ?></strong><br>
                        <span class="small muted"><?= e($row['message_booking'] ?? '') ?></span>
                    </td>
                    <td><?= e($row['date_booking']) ?></td>
                    <td><?= e($row['rooms_booking']) ?></td>
                    <td><?= (int)$row['adults_booking'] ?> adulti / <?= (int)$row['childs_booking'] ?> bambini</td>
                    <td>
                        <?= e($row['email_booking']) ?><br>
                        <span class="small muted"><?= e($row['phone_booking'] ?? '-') ?></span>
                    </td>
                    <td><span class="badge warning"><?= e($row['source'] ?? 'website_form') ?></span></td>
                    <td>
                        <div class="actions">
                            <form method="post" action="<?= e(admin_url('actions/register-booking.php')) ?>" data-confirm="Registrare questa richiesta come prenotazione confermata?">
                                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="booking_request_id" value="<?= (int)$row['id'] ?>">
                                <button class="btn btn-success btn-sm" type="submit">Registra prenotazione</button>
                            </form>
                            <form method="post" action="<?= e(admin_url('actions/delete-booking-request.php')) ?>" data-confirm="Vuoi davvero eliminare questa richiesta?">
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

<section id="contact-requests" class="card section-contacts" style="margin-top:20px;">
    <div class="section-title">
        <div>
            <h2>Richieste contatto</h2>
            <p class="muted">Storico dei messaggi arrivati dal form informazioni.</p>
        </div>
        <div class="toolbar">
            <input class="search-input" type="search" placeholder="Cerca richieste contatto..." data-table-filter="#contact-requests-table">
        </div>
    </div>
    <div class="table-wrap">
        <table id="contact-requests-table">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Nome</th>
                    <th>Email</th>
                    <th>Telefono</th>
                    <th>Messaggio</th>
                    <th>Azioni</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($contactRequests as $row): ?>
                <tr>
                    <td><?= e($row['created_at']) ?></td>
                    <td><?= e(($row['name_contact'] ?? '') . ' ' . ($row['lastname_contact'] ?? '')) ?></td>
                    <td><?= e($row['email_contact']) ?></td>
                    <td><?= e($row['phone_contact']) ?></td>
                    <td><?= nl2br(e($row['message_contact'])) ?></td>
                    <td>
                        <div class="actions">
                            <form method="post" action="<?= e(admin_url('actions/delete-contact-request.php')) ?>" data-confirm="Vuoi davvero eliminare questa richiesta di contatto?">
                                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="contact_request_id" value="<?= (int)$row['id'] ?>">
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
<?php require_once __DIR__ . '/includes/footer.php'; ?>
