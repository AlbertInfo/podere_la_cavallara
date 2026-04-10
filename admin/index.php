<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_admin();

function dashboard_extract_check_in_iso(array $row): string
{
    $checkIn = trim((string) ($row['check_in'] ?? ''));
    if ($checkIn !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkIn)) {
        return $checkIn;
    }

    $stayPeriod = trim((string) ($row['stay_period'] ?? ''));
    if ($stayPeriod === '') {
        return '';
    }

    $normalized = str_replace(' al ', ' - ', $stayPeriod);
    $parts = array_values(array_filter(array_map('trim', explode(' - ', $normalized))));
    if (count($parts) < 2) {
        return '';
    }

    $date = DateTime::createFromFormat('d/m/Y', $parts[0]);
    return $date ? $date->format('Y-m-d') : '';
}

function dashboard_extract_check_out_iso(array $row): string
{
    $checkOut = trim((string) ($row['check_out'] ?? ''));
    if ($checkOut !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkOut)) {
        return $checkOut;
    }

    $stayPeriod = trim((string) ($row['stay_period'] ?? ''));
    if ($stayPeriod === '') {
        return '';
    }

    $normalized = str_replace(' al ', ' - ', $stayPeriod);
    $parts = array_values(array_filter(array_map('trim', explode(' - ', $normalized))));
    if (count($parts) < 2) {
        return '';
    }

    $date = DateTime::createFromFormat('d/m/Y', $parts[count($parts) - 1]);
    return $date ? $date->format('Y-m-d') : '';
}

$dashboardTodayIso = (new DateTimeImmutable('today'))->format('Y-m-d');

$bookingRequests = $pdo->query('SELECT * FROM booking_requests ORDER BY created_at DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
$contactRequests = $pdo->query('SELECT * FROM contact_requests ORDER BY created_at DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
$registeredBookings = $pdo->query('SELECT * FROM prenotazioni ORDER BY created_at DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);

$stats = [
    'booking_requests' => (int) $pdo->query('SELECT COUNT(*) FROM booking_requests')->fetchColumn(),
    'contact_requests' => (int) $pdo->query('SELECT COUNT(*) FROM contact_requests')->fetchColumn(),
    'registered_bookings' => (int) $pdo->query('SELECT COUNT(*) FROM prenotazioni')->fetchColumn(),
    'today_requests' => (int) $pdo->query('SELECT COUNT(*) FROM booking_requests WHERE DATE(created_at) = CURDATE()')->fetchColumn(),
];

$registeredBookingRoomOptions = [];
foreach ($registeredBookings as $bookingRow) {
    $roomName = trim((string) ($bookingRow['room_type'] ?? ''));
    if ($roomName !== '') {
        $registeredBookingRoomOptions[$roomName] = $roomName;
    }
}
if ($registeredBookingRoomOptions) {
    uksort($registeredBookingRoomOptions, 'strnatcasecmp');
}
function language_to_country_code(?string $language): string
{
    return match (trim((string) $language)) {
        'Italiano' => 'it',
        'Inglese' => 'gb',
        'Tedesco' => 'de',
        'Ceco' => 'cz',
        'Polacco' => 'pl',
        'Olandese' => 'nl',
        'Francese' => 'fr',
        'Spagnolo' => 'es',
        default => '',
    };
}

function country_code_to_language(?string $guest_country_code): string
{
    return match (trim((string) $guest_country_code)) {
        'it' => 'Italiano',
        'gb' => 'Inglese',
        'de' => 'Tedesco',
        'cz' => 'Ceco',
        'pl' => 'Polacco',
        'nl' => 'Olandese',
        'fr' => 'Francese',
        'es' => 'Spagnolo',
        default => '',
    };
}
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

<style>
.dashboard-filters-panel{
    margin-bottom:20px;
    padding:22px;
    border:1px solid rgba(219,228,240,.95);
    border-radius:20px;
    background:linear-gradient(180deg,#fbfdff 0%,#f6f9ff 100%);
}
.dashboard-filters-header{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:16px;
    flex-wrap:wrap;
    margin-bottom:18px;
}
.dashboard-filters-header h3{
    margin:0 0 6px;
    font-size:18px;
    color:var(--primary);
}
.dashboard-filters-count{
    display:inline-flex;
    align-items:center;
    min-height:40px;
    padding:10px 14px;
    border-radius:999px;
    background:#fff;
    border:1px solid rgba(191,219,254,.95);
    color:#1e3a8a;
    font-weight:700;
}
.dashboard-filters-grid{
    display:grid;
    grid-template-columns:minmax(260px,1.5fr) repeat(3,minmax(180px,1fr));
    gap:14px;
}
.dashboard-filter-field{
    display:grid;
    gap:8px;
    font-weight:700;
    color:#334155;
}
.dashboard-filter-field span{
    font-size:13px;
    color:var(--muted);
    font-weight:700;
}
.dashboard-filter-actions{
    display:flex;
    justify-content:flex-end;
    margin-top:14px;
}
.dashboard-empty-state{
    margin-top:16px;
    padding:18px;
    border-radius:18px;
    border:1px dashed #bfd0e6;
    background:#fff;
    color:var(--muted);
    text-align:center;
    font-weight:600;
}
.booking-time-badge{
    display:inline-flex;
    align-items:center;
    margin-top:8px;
    padding:6px 10px;
    border-radius:999px;
    font-size:12px;
    font-weight:800;
    letter-spacing:.01em;
    border:1px solid transparent;
}
.booking-time-badge.is-active{
    background:#ecfdf3;
    color:#166534;
    border-color:#bbf7d0;
}
.booking-time-badge.is-past{
    background:#fff1f2;
    color:#8d8b8b;
    border-color:#8d8b8b;
}
#registered-bookings-table tr.desktop-row.is-past td{
    background:linear-gradient(180deg,#fff8f8 0%,#fff2f4 100%);
}
#registered-bookings-table tr.desktop-row.is-past td:first-child{
    box-shadow:inset 4px 0 0 rgba(205, 205, 205, .95);
}
#registered-bookings-table tr.desktop-row.is-past strong{
    color:#8d8b8b;
}
#registered-bookings-table tr.desktop-row.is-past .small.muted{
    color:#8d8b8b;
}
#registered-bookings-table tr.mobile-summary-row.is-past td,
#registered-bookings-table tr.mobile-detail-row.is-past td{
    background:linear-gradient(180deg,#fff8f8 0%,#8d8b8b 100%);
}
#registered-bookings-table tr.mobile-summary-row.is-past .mobile-summary-card,
#registered-bookings-table tr.mobile-detail-row.is-past .mobile-detail-grid{
    border-color:#fecdd3;
}
#registered-bookings-table tr.mobile-summary-row.is-past .mobile-summary-head strong,
#registered-bookings-table tr.mobile-detail-row.is-past strong{
    color:#7f1d1d;
}
@media (max-width:1100px){
    .dashboard-filters-grid{
        grid-template-columns:1fr 1fr;
    }
}
@media (max-width:760px){
    .dashboard-filters-panel{
        padding:18px;
    }
    .dashboard-filters-grid{
        grid-template-columns:1fr;
    }
    .dashboard-filters-count,
    .dashboard-filter-actions .btn{
        width:100%;
        justify-content:center;
    }
}
</style>

<section id="registered-bookings" class="card section-registered" style="margin-top:20px;">
    <div class="section-title">
        <div>
            <h2>Prenotazioni confermate</h2>
            <p class="muted">Elenco delle prenotazioni trasferite dall’admin o inserite manualmente dal gestionale.</p>
        </div>
        <div class="toolbar">
            <a class="btn btn-light" href="<?= e(admin_url('import-interhome-pdf.php')) ?>">Importa PDF Interhome</a>
            <a class="btn btn-primary" href="<?= e(admin_url('new-prenotazione.php')) ?>">Nuova prenotazione</a>
        </div>
    </div>

    <div class="dashboard-filters-panel" aria-label="Filtri prenotazioni confermate">
        <div class="dashboard-filters-header">
            <div>
                <h3>Filtri prenotazioni</h3>
                <p class="muted">Cerca per nome e cognome, filtra per casa e stato del soggiorno, poi ordina le prenotazioni per check-in o data di registrazione.</p>
            </div>
            <div class="dashboard-filters-count" data-visible-bookings-count>
                <?= count($registeredBookings) ?> prenotazioni visibili
            </div>
        </div>

        <div class="dashboard-filters-grid">
            <label class="dashboard-filter-field" for="registeredBookingsSearch">
                <span>Ricerca cliente</span>
                <input id="registeredBookingsSearch" class="search-input" type="search" placeholder="Cerca nome, cognome, email o riferimento...">
            </label>

            <label class="dashboard-filter-field" for="registeredBookingsRoomFilter">
                <span>Tipologia casa</span>
                <select id="registeredBookingsRoomFilter">
                    <option value="">Tutte le case</option>
                    <?php foreach ($registeredBookingRoomOptions as $roomOption): ?>
                        <option value="<?= e($roomOption) ?>"><?= e($roomOption) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="dashboard-filter-field" for="registeredBookingsPhaseFilter">
                <span>Stato soggiorno</span>
                <select id="registeredBookingsPhaseFilter">
                    <option value="">Tutte</option>
                    <option value="active">Prenotazioni attive</option>
                    <option value="past">Prenotazioni passate</option>
                </select>
            </label>

            <label class="dashboard-filter-field" for="registeredBookingsSort">
                <span>Ordina per</span>
                <select id="registeredBookingsSort">
                    <option value="created_desc">Data registrazione: più recente</option>
                    <option value="checkin_asc">Check-in: più vicino → più lontano</option>
                    <option value="checkin_desc">Check-in: più lontano → più vicino</option>
                    <option value="name_asc">Cliente: A → Z</option>
                </select>
            </label>
        </div>

        <div class="dashboard-filter-actions">
            <button class="btn btn-light btn-sm" type="button" id="registeredBookingsReset">Reset filtri</button>
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
                    <?php
                        $bookingCheckIn = dashboard_extract_check_in_iso($row);
                        $bookingCheckOut = dashboard_extract_check_out_iso($row);
                        $bookingIsPast = $bookingCheckOut !== '' && $bookingCheckOut < $dashboardTodayIso;
                        $bookingPhase = $bookingIsPast ? 'past' : 'active';
                    ?>
                    <!-- DESKTOP ROW -->
                    <tr class="desktop-row<?= $bookingIsPast ? ' is-past' : '' ?>"
                        data-booking-id="<?= (int)$row['id'] ?>"
                        data-customer-name="<?= e((string)$row['customer_name']) ?>"
                        data-room-type="<?= e((string)$row['room_type']) ?>"
                        data-check-in="<?= e($bookingCheckIn) ?>"
                        data-check-out="<?= e($bookingCheckOut) ?>"
                        data-booking-phase="<?= e($bookingPhase) ?>"
                        data-created-at="<?= e((string)$row['created_at']) ?>">
                        <td><?= e($row['created_at']) ?></td>
                        <td>
                            <strong><?= e($row['customer_name']) ?></strong><br>
                            <span class="fi fi-<?= e($row['guest_country_code']) ?> interhome-review-flag" title="<?= country_code_to_language($row['guest_country_code']) ?? '' ?>"></span> 
                            <span class="small muted"><?= country_code_to_language($row['guest_country_code']) ?? '' ?> </span>  <br>             
                            <span class="small muted"><?= e($row['customer_email'] ?: 'Email non disponibile') ?></span>
                        </td>
                        <td>
                            <?= e($row['stay_period']) ?><br>
                            <span class="booking-time-badge <?= $bookingIsPast ? 'is-past' : 'is-active' ?>">
                                <?= $bookingIsPast ? 'Prenotazione passata' : 'Prenotazione attiva' ?>
                            </span>
                        </td>
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
                    <tr class="mobile-summary-row<?= $bookingIsPast ? ' is-past' : '' ?>" data-mobile-expand-row>
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
                    <tr class="mobile-detail-row<?= $bookingIsPast ? ' is-past' : '' ?>">
                        <td>
                            <div class="mobile-detail-grid">
                                <div>
                                    <span>Data registrazione</span>
                                    <strong><?= e($row['created_at']) ?></strong>
                                </div>
                                <div>
                                    <span>Email</span>
                                    <strong><?= e($row['customer_email'] ?: 'Email non disponibile') ?></strong>
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
                                <div>
                                    <span>Stato soggiorno</span>
                                    <strong><?= $bookingIsPast ? 'Passata' : 'Attiva' ?></strong>
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
    <div id="registeredBookingsEmptyState" class="dashboard-empty-state" hidden>
        Nessuna prenotazione trovata con i filtri selezionati.
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
<script>
document.addEventListener('DOMContentLoaded', function () {
    const table = document.getElementById('registered-bookings-table');
    const searchInput = document.getElementById('registeredBookingsSearch');
    const roomFilter = document.getElementById('registeredBookingsRoomFilter');
    const phaseFilter = document.getElementById('registeredBookingsPhaseFilter');
    const sortSelect = document.getElementById('registeredBookingsSort');
    const resetButton = document.getElementById('registeredBookingsReset');
    const countLabel = document.querySelector('[data-visible-bookings-count]');
    const emptyState = document.getElementById('registeredBookingsEmptyState');

    if (!table || !searchInput || !roomFilter || !phaseFilter || !sortSelect || !resetButton) {
        return;
    }

    const tbody = table.querySelector('tbody');
    if (!tbody) {
        return;
    }

    const desktopRows = Array.from(tbody.querySelectorAll('tr.desktop-row'));
    if (!desktopRows.length) {
        if (countLabel) {
            countLabel.textContent = '0 prenotazioni visibili';
        }
        if (emptyState) {
            emptyState.hidden = false;
        }
        return;
    }

    function parseIsoDate(value) {
        if (!value || !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
            return Number.POSITIVE_INFINITY;
        }

        const parts = value.split('-').map(Number);
        return new Date(parts[0], parts[1] - 1, parts[2]).getTime();
    }

    function parseDateTime(value) {
        if (!value) {
            return 0;
        }

        const normalized = String(value).replace(' ', 'T');
        const timestamp = Date.parse(normalized);
        return Number.isNaN(timestamp) ? 0 : timestamp;
    }

    const groups = desktopRows.map(function (desktopRow, index) {
        const summaryRow = desktopRow.nextElementSibling && desktopRow.nextElementSibling.classList.contains('mobile-summary-row')
            ? desktopRow.nextElementSibling
            : null;
        const detailRow = summaryRow && summaryRow.nextElementSibling && summaryRow.nextElementSibling.classList.contains('mobile-detail-row')
            ? summaryRow.nextElementSibling
            : null;

        return {
            originalIndex: index,
            desktopRow: desktopRow,
            summaryRow: summaryRow,
            detailRow: detailRow,
            customerName: (desktopRow.dataset.customerName || '').toLowerCase(),
            roomType: (desktopRow.dataset.roomType || '').toLowerCase(),
            bookingPhase: (desktopRow.dataset.bookingPhase || 'active').toLowerCase(),
            checkInValue: desktopRow.dataset.checkIn || '',
            checkInTimestamp: parseIsoDate(desktopRow.dataset.checkIn || ''),
            checkOutTimestamp: parseIsoDate(desktopRow.dataset.checkOut || ''),
            createdAtTimestamp: parseDateTime(desktopRow.dataset.createdAt || ''),
            searchText: [
                desktopRow.textContent,
                summaryRow ? summaryRow.textContent : '',
                detailRow ? detailRow.textContent : ''
            ].join(' ').toLowerCase()
        };
    });

    function appendGroup(group) {
        tbody.appendChild(group.desktopRow);
        if (group.summaryRow) {
            tbody.appendChild(group.summaryRow);
        }
        if (group.detailRow) {
            tbody.appendChild(group.detailRow);
        }
    }

    function compareWithFallback(first, second, fallbackKey) {
        if (first === second) {
            return fallbackKey;
        }
        return first < second ? -1 : 1;
    }

    function sortGroups(list, sortValue) {
        return list.slice().sort(function (a, b) {
            const fallback = a.originalIndex - b.originalIndex;

            if (sortValue === 'checkin_asc') {
                const aMissing = !Number.isFinite(a.checkInTimestamp);
                const bMissing = !Number.isFinite(b.checkInTimestamp);
                if (aMissing && bMissing) return fallback;
                if (aMissing) return 1;
                if (bMissing) return -1;
                return compareWithFallback(a.checkInTimestamp, b.checkInTimestamp, fallback);
            }

            if (sortValue === 'checkin_desc') {
                const aMissing = !Number.isFinite(a.checkInTimestamp);
                const bMissing = !Number.isFinite(b.checkInTimestamp);
                if (aMissing && bMissing) return fallback;
                if (aMissing) return 1;
                if (bMissing) return -1;
                return compareWithFallback(b.checkInTimestamp, a.checkInTimestamp, fallback);
            }

            if (sortValue === 'name_asc') {
                const compare = a.customerName.localeCompare(b.customerName, 'it', { sensitivity: 'base' });
                return compare === 0 ? fallback : compare;
            }

            return compareWithFallback(b.createdAtTimestamp, a.createdAtTimestamp, fallback);
        });
    }

    function syncDetailVisibility() {
        groups.forEach(function (group) {
            if (!group.detailRow) {
                return;
            }

            const visible = !group.desktopRow.hidden;
            const isOpen = group.summaryRow && group.summaryRow.classList.contains('is-open');
            group.detailRow.hidden = !visible || !isOpen;
        });
    }

    function updateVisibleCount(total) {
        if (!countLabel) {
            return;
        }
        countLabel.textContent = total === 1
            ? '1 prenotazione visibile'
            : total + ' prenotazioni visibili';
    }

    function applyRegisteredBookingsFilters() {
        const query = searchInput.value.trim().toLowerCase();
        const selectedRoom = roomFilter.value.trim().toLowerCase();
        const selectedPhase = phaseFilter.value.trim().toLowerCase();
        const sortValue = sortSelect.value;
        const orderedGroups = sortGroups(groups, sortValue);
        let visibleCount = 0;

        orderedGroups.forEach(function (group) {
            const matchesQuery = query === '' || group.searchText.indexOf(query) !== -1;
            const matchesRoom = selectedRoom === '' || group.roomType === selectedRoom;
            const matchesPhase = selectedPhase === '' || group.bookingPhase === selectedPhase;
            const isVisible = matchesQuery && matchesRoom && matchesPhase;

            group.desktopRow.hidden = !isVisible;
            if (group.summaryRow) {
                group.summaryRow.hidden = !isVisible;
            }
            if (group.detailRow && !isVisible) {
                group.detailRow.hidden = true;
            }

            if (isVisible) {
                visibleCount += 1;
            }

            appendGroup(group);
        });

        syncDetailVisibility();
        updateVisibleCount(visibleCount);

        if (emptyState) {
            emptyState.hidden = visibleCount !== 0;
        }
    }

    groups.forEach(function (group) {
        if (!group.summaryRow) {
            return;
        }

        group.summaryRow.addEventListener('click', function () {
            window.setTimeout(syncDetailVisibility, 0);
        });
    });

    [searchInput, roomFilter, phaseFilter, sortSelect].forEach(function (element) {
        element.addEventListener('input', applyRegisteredBookingsFilters);
        element.addEventListener('change', applyRegisteredBookingsFilters);
    });

    resetButton.addEventListener('click', function () {
        searchInput.value = '';
        roomFilter.value = '';
        phaseFilter.value = '';
        sortSelect.value = 'created_desc';
        applyRegisteredBookingsFilters();
    });

    applyRegisteredBookingsFilters();
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>