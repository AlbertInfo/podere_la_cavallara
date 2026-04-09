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
    <!-- <article class="kpi-card">
        <div class="label">Nuove richieste oggi</div>
        <div class="value"> inserire il php today request se serve</div>
        <div class="small muted">Solo booking requests</div>
    </article> -->

</section>

<section id="registered-bookings" class="card section-registered" style="margin-top:20px;">
    <div class="section-title">
        <div>
            <h2>Prenotazioni confermate</h2>
            <p class="muted">Elenco delle prenotazioni trasferite dall’admin o inserite manualmente dal gestionale.</p>
        </div>
        <div class="toolbar">
            <input class="search-input" type="search" placeholder="Cerca prenotazioni..." data-table-filter="#registered-bookings-table">
            <a class="btn btn-light" href="<?= e(admin_url('import-interhome-pdf.php')) ?>">Importa PDF Interhome</a>
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
            <tbody>
                <?php foreach ($registeredBookings as $row): ?>
                    <!-- DESKTOP ROW -->
                    <tr class="desktop-row">
                        <td><?= e($row['created_at']) ?></td>
                        <td>
                            <strong><?= e($row['customer_name']) ?></strong><br>
                            <span class="small muted"><?= e($row['customer_email']) ?></span>
                        </td>
                        <td><?= e($row['stay_period']) ?></td>
                        <td><?= e($row['room_type']) ?></td>
                        <td><?= (int)$row['adults'] ?> adulti / <?= (int)$row['children_count'] ?> bambini</td>
                        <td><span class="badge success"><?= e($row['status']) ?></span></td>
                        <td><span class="badge"><?= e($row['source']) ?></span></td>
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

                    <!-- MOBILE SUMMARY -->
                    <tr class="mobile-summary-row" data-mobile-expand-row>
                        <td>
                            <div class="mobile-summary-card">
                                <div class="mobile-summary-head">
                                    <strong><?= e($row['customer_name']) ?></strong>
                                    <span class="mobile-chevron">▾</span>
                                </div>

                                <div class="mobile-summary-grid">
                                    <div>
                                        <span>Cliente</span>
                                        <strong><?= e($row['customer_name']) ?></strong>
                                    </div>
                                    <div>
                                        <span>Soggiorno</span>
                                        <strong><?= e($row['stay_period']) ?></strong>
                                    </div>
                                    <div>
                                        <span>Camera</span>
                                        <strong><?= e($row['room_type']) ?></strong>
                                    </div>
                                    <div>
                                        <span>Persone</span>
                                        <strong><?= (int)$row['adults'] ?> adulti / <?= (int)$row['children_count'] ?> bambini</strong>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>

                    <!-- MOBILE DETAIL -->
                    <tr class="mobile-detail-row">
                        <td>
                            <div class="mobile-detail-grid">
                                <div>
                                    <span>Data registrazione</span>
                                    <strong><?= e($row['created_at']) ?></strong>
                                </div>
                                <div>
                                    <span>Email</span>
                                    <strong><?= e($row['customer_email']) ?></strong>
                                </div>
                                <div>
                                    <span>Telefono</span>
                                    <strong><?= e($row['customer_phone'] ?? '-') ?></strong>
                                </div>
                                <div>
                                    <span>Stato</span>
                                    <strong><?= e($row['status']) ?></strong>
                                </div>
                                <div>
                                    <span>Origine</span>
                                    <strong><?= e($row['source']) ?></strong>
                                </div>
                                <?php if (!empty($row['external_reference'])): ?>
                                    <div>
                                        <span>Riferimento esterno</span>
                                        <strong><?= e($row['external_reference']) ?></strong>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($row['notes'])): ?>
                                    <div class="full">
                                        <span>Note</span>
                                        <strong><?= nl2br(e($row['notes'])) ?></strong>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="mobile-detail-actions">
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
                    <!-- DESKTOP ROW -->
                    <tr class="desktop-row">
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
                        <td><span class="badge <?= ($row['source'] ?? '') !== 'website_form' ? 'warning' : '' ?>"><?= e($row['source'] ?? 'website_form') ?></span></td>
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

                    <!-- MOBILE SUMMARY -->
                    <tr class="mobile-summary-row" data-mobile-expand-row>
                        <td>
                            <div class="mobile-summary-card">
                                <div class="mobile-summary-head">
                                    <strong><?= e($row['name_booking']) ?></strong>
                                    <span class="mobile-chevron">▾</span>
                                </div>

                                <div class="mobile-summary-grid">
                                    <div>
                                        <span>Cliente</span>
                                        <strong><?= e($row['name_booking']) ?></strong>
                                    </div>
                                    <div>
                                        <span>Soggiorno</span>
                                        <strong><?= e($row['date_booking']) ?></strong>
                                    </div>
                                    <div>
                                        <span>Camera</span>
                                        <strong><?= e($row['rooms_booking']) ?></strong>
                                    </div>
                                    <div>
                                        <span>Persone</span>
                                        <strong><?= (int)$row['adults_booking'] ?> adulti / <?= (int)$row['childs_booking'] ?> bambini</strong>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>

                    <!-- MOBILE DETAIL -->
                    <tr class="mobile-detail-row">
                        <td>
                            <div class="mobile-detail-grid">
                                <div>
                                    <span>Data richiesta</span>
                                    <strong><?= e($row['created_at']) ?></strong>
                                </div>
                                <div>
                                    <span>Email</span>
                                    <strong><?= e($row['email_booking']) ?></strong>
                                </div>
                                <div>
                                    <span>Telefono</span>
                                    <strong><?= e($row['phone_booking'] ?? '-') ?></strong>
                                </div>
                                <div>
                                    <span>Origine</span>
                                    <strong><?= e($row['source'] ?? 'website_form') ?></strong>
                                </div>
                                <?php if (!empty($row['message_booking'])): ?>
                                    <div class="full">
                                        <span>Messaggio</span>
                                        <strong><?= e($row['message_booking']) ?></strong>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="mobile-detail-actions">
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
                    <!-- DESKTOP ROW -->
                    <tr class="desktop-row">
                        <td><?= e($row['created_at']) ?></td>
                        <td><?= e(($row['name_contact'] ?? '') . ' ' . ($row['lastname_contact'] ?? '')) ?></td>
                        <td>
                            <a class="contact-link" href="mailto:<?= e($row['email_contact']) ?>">
                                <?= e($row['email_contact']) ?>
                            </a>
                        </td>
                        <td>
                            <a class="contact-link" href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string)$row['phone_contact'])) ?>">
                                <?= e($row['phone_contact']) ?>
                            </a>
                        </td>
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

                    <!-- MOBILE SUMMARY -->
                    <tr class="mobile-summary-row" data-mobile-expand-row>
                        <td>
                            <div class="mobile-summary-card">
                                <div class="mobile-summary-head">
                                    <strong><?= e(($row['name_contact'] ?? '') . ' ' . ($row['lastname_contact'] ?? '')) ?></strong>
                                    <span class="mobile-chevron">▾</span>
                                </div>

                                <div class="mobile-summary-grid">
                                    <div>
                                        <span>Nome</span>
                                        <strong><?= e(($row['name_contact'] ?? '') . ' ' . ($row['lastname_contact'] ?? '')) ?></strong>
                                    </div>
                                    <div>
                                        <span>Email</span>
                                        <strong>
                                            <a class="contact-link" href="mailto:<?= e($row['email_contact'])?>">
                                                <?= e($row['email_contact']) ?>
                                            </a>
                                        </strong>
                                    </div>
                                    <div>
                                        <span>Telefono</span>
                                        <strong>
                                            <a class="contact-link" href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string)$row['phone_contact'])) ?>">
                                                <?= e($row['phone_contact']) ?>
                                            </a>
                                        </strong>
                                    </div>
                                    <div>
                                        <span>Data</span>
                                        <strong><?= e($row['created_at']) ?></strong>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>

                    <!-- MOBILE DETAIL -->
                    <tr class="mobile-detail-row">
                        <td>
                            <div class="mobile-detail-grid">
                                <div>
                                    <span>Data</span>
                                    <strong><?= e($row['created_at']) ?></strong>
                                </div>
                                <div>
                                    <span>Email</span>
                                    <strong><?= e($row['email_contact']) ?></strong>
                                </div>
                                <div>
                                    <span>Telefono</span>
                                    <strong><?= e($row['phone_contact']) ?></strong>
                                </div>
                                <div class="full">
                                    <span>Messaggio</span>
                                    <strong><?= nl2br(e($row['message_contact'])) ?></strong>
                                </div>
                            </div>

                            <div class="mobile-detail-actions">
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