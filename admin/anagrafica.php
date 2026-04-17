<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/anagrafica-options.php';
require_once __DIR__ . '/includes/ross1000-config.php';
require_once __DIR__ . '/includes/ross1000.php';
require_admin();

$pageTitle = 'Sezione anagrafica';

function anagrafica_form_date(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '';
    }
    $ts = strtotime($value);
    return $ts ? date('d/m/Y', $ts) : $value;
}

function anagrafica_safe_month(?string $value): string
{
    $value = trim((string) $value);
    if (preg_match('/^\d{4}-\d{2}$/', $value)) {
        return $value;
    }
    return date('Y-m');
}

function anagrafica_month_label(DateTimeImmutable $monthStart): string
{
    if (class_exists('IntlDateFormatter')) {
        $formatter = new IntlDateFormatter('it_IT', IntlDateFormatter::LONG, IntlDateFormatter::NONE, date_default_timezone_get(), IntlDateFormatter::GREGORIAN, 'MMMM yyyy');
        $label = $formatter->format($monthStart);
    } else {
        $label = strftime('%B %Y', $monthStart->getTimestamp());
    }
    if (!$label) {
        return $monthStart->format('F Y');
    }
    return mb_convert_case((string) $label, MB_CASE_TITLE, 'UTF-8');
}

function anagrafica_weekday_label(string $date): string
{
    if (class_exists('IntlDateFormatter')) {
        $formatter = new IntlDateFormatter('it_IT', IntlDateFormatter::FULL, IntlDateFormatter::NONE, date_default_timezone_get(), IntlDateFormatter::GREGORIAN, 'EEE');
        $label = $formatter->format(new DateTimeImmutable($date));
    } else {
        $label = strftime('%a', strtotime($date));
    }
    return $label ? mb_strtoupper((string) $label, 'UTF-8') : strtoupper(date('D', strtotime($date)));
}

$recordTableReady = false;
$dayStatusTableReady = false;
$editingRecord = null;
$editingGuests = [];

$selectedMonth = anagrafica_safe_month($_GET['month'] ?? null);
$monthStart = new DateTimeImmutable($selectedMonth . '-01');
$monthEnd = $monthStart->modify('last day of this month');
$today = date('Y-m-d');
$selectedDay = trim((string) ($_GET['day'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDay) || $selectedDay < $monthStart->format('Y-m-d') || $selectedDay > $monthEnd->format('Y-m-d')) {
    $selectedDay = ($today >= $monthStart->format('Y-m-d') && $today <= $monthEnd->format('Y-m-d'))
        ? $today
        : $monthStart->format('Y-m-d');
}

$editRecordId = max(0, (int) ($_GET['edit'] ?? 0));
$createdRecordId = max(0, (int) ($_GET['created'] ?? 0));
$updatedRecordId = max(0, (int) ($_GET['updated'] ?? 0));
$deletedRecordId = max(0, (int) ($_GET['deleted'] ?? 0));
$rowHighlightId = max($createdRecordId, $updatedRecordId);

$config = ross1000_property_config();
$monthRecords = [];
$dayStates = [];
$days = [];
$selectedSnapshot = null;
$selectedRecords = [];

try {
    $recordTableReady = (bool) $pdo->query("SHOW TABLES LIKE 'anagrafica_records'")->fetchColumn();
    $dayStatusTableReady = ross1000_day_status_table_ready($pdo);

    if ($recordTableReady) {
        $monthRecords = ross1000_fetch_records_for_range($pdo, $monthStart->format('Y-m-d'), $monthEnd->format('Y-m-d'));
        $dayStates = ross1000_get_day_states_for_range($pdo, $monthStart->format('Y-m-d'), $monthEnd->format('Y-m-d'), $config);

        $cursor = $monthStart;
        while ($cursor <= $monthEnd) {
            $date = $cursor->format('Y-m-d');
            $snapshot = ross1000_build_day_snapshot($date, $monthRecords, $dayStates[$date] ?? ross1000_default_day_state($config, $date), $config);
            $days[$date] = $snapshot;
            $cursor = $cursor->modify('+1 day');
        }

        $selectedSnapshot = $days[$selectedDay] ?? null;
        $selectedRecords = (array) ($selectedSnapshot['touching_records'] ?? []);

        if ($editRecordId > 0) {
            $stmt = $pdo->prepare('SELECT * FROM anagrafica_records WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $editRecordId]);
            $editingRecord = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($editingRecord) {
                $guestStmt = $pdo->prepare('SELECT * FROM anagrafica_guests WHERE record_id = :record_id ORDER BY is_group_leader DESC, id ASC');
                $guestStmt->execute(['record_id' => $editRecordId]);
                $editingGuests = $guestStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $selectedDay = substr((string) ($editingRecord['arrival_date'] ?? $selectedDay), 0, 10) ?: $selectedDay;
            }
        }
    }
} catch (Throwable $e) {
    $recordTableReady = false;
    $dayStatusTableReady = false;
    $monthRecords = [];
    $dayStates = [];
    $days = [];
    $selectedSnapshot = null;
    $selectedRecords = [];
    $editingRecord = null;
    $editingGuests = [];
}

$stateOptions = anagrafica_state_options();
$province = anagrafica_province_italiane();
$documentTypes = anagrafica_document_types();
$cityOptions = anagrafica_comune_option_labels();
$placeOptions = anagrafica_place_option_labels();
$channels = anagrafica_booking_channels();
$tourismTypes = anagrafica_tourism_types();
$transportTypes = anagrafica_transport_types();
$educationLevels = anagrafica_titoli_studio();
$recordTypeOptions = anagrafica_record_type_options();
$rossConfigReady = ross1000_property_config_ready($config);

$formIsEdit = $editingRecord !== null;
$defaultNewDay = isset($_GET['new']) && !$formIsEdit ? $selectedDay : '';
$defaultBookingReceived = $defaultNewDay !== '' ? $defaultNewDay : date('Y-m-d');
$defaultArrivalDate = $defaultNewDay !== '' ? $defaultNewDay : '';

$formRecord = [
    'id' => $editingRecord['id'] ?? 0,
    'record_type' => $editingRecord['record_type'] ?? 'single',
    'booking_reference' => $editingRecord['booking_reference'] ?? '',
    'booking_received_date' => anagrafica_form_date($editingRecord['booking_received_date'] ?? $defaultBookingReceived),
    'arrival_date' => anagrafica_form_date($editingRecord['arrival_date'] ?? $defaultArrivalDate),
    'departure_date' => anagrafica_form_date($editingRecord['departure_date'] ?? ''),
    'expected_guests' => (string) ($editingRecord['expected_guests'] ?? 1),
    'reserved_rooms' => (string) ($editingRecord['reserved_rooms'] ?? 1),
    'booking_channel' => $editingRecord['booking_channel'] ?? '',
    'daily_price' => $editingRecord['daily_price'] ?? '',
    'booking_provenience_state_label' => $editingRecord['booking_provenience_state_label'] ?? '',
    'booking_provenience_province' => $editingRecord['booking_provenience_province'] ?? '',
    'booking_provenience_place_label' => $editingRecord['booking_provenience_place_label'] ?? '',
];

$leaderGuest = $editingGuests[0] ?? [];
$additionalGuests = $editingGuests ? array_slice($editingGuests, 1) : [];

$forceOpenForm = isset($_GET['new']) || $formIsEdit;
$basePageUrl = admin_url('anagrafica.php?month=' . rawurlencode($selectedMonth) . '&day=' . rawurlencode($selectedDay));
$newPageUrl = admin_url('anagrafica.php?month=' . rawurlencode($selectedMonth) . '&day=' . rawurlencode($selectedDay) . '&new=1');
$prevMonthUrl = admin_url('anagrafica.php?month=' . rawurlencode($monthStart->modify('-1 month')->format('Y-m')));
$nextMonthUrl = admin_url('anagrafica.php?month=' . rawurlencode($monthStart->modify('+1 month')->format('Y-m')));
$selectedDayState = $dayStates[$selectedDay] ?? ross1000_default_day_state($config, $selectedDay);
$selectedDayOpen = (int) ($selectedDayState['is_open'] ?? 1) === 1;
$selectedDayAvailableRooms = (int) ($selectedDayState['available_rooms'] ?? ($selectedDayOpen ? (int) ($config['camere_disponibili'] ?? 0) : 0));
$selectedDayAvailableBeds = (int) ($selectedDayState['available_beds'] ?? ($selectedDayOpen ? (int) ($config['letti_disponibili'] ?? 0) : 0));
$selectedDayFinalized = (int) ($selectedDayState['is_finalized'] ?? 0) === 1;
$monthOpenDaysCount = 0;
$monthFinalizedDaysCount = 0;
foreach ($days as $snapshot) {
    if (!empty($snapshot['is_open'])) {
        $monthOpenDaysCount++;
    }
    if (!empty($snapshot['day_state']['is_finalized'])) {
        $monthFinalizedDaysCount++;
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="booking-page anagrafica-shell">
    <section class="booking-hero anagrafica-hero">
        <div class="booking-hero-copy">
            <span class="eyebrow">Sezione anagrafica</span>
            <h1>Pianificazione giornaliera ROSS1000</h1>
            <p class="muted">Seleziona il mese, controlla il calendario orizzontale e chiudi la giornata quando il dato è definitivo. L’XML ROSS1000 viene generato come fotografia completa del giorno, in linea con il tracciato e con l’import gestionale di ROSS1000.<?php /* docs cite in response */ ?></p>
        </div>
        <div class="toolbar anagrafica-hero__actions">
            <a class="btn btn-primary" href="<?= e($newPageUrl) ?>" data-anagrafica-open-link>Nuova anagrafica</a>
            <a class="btn btn-light" href="<?= e(admin_url('index.php#registered-bookings')) ?>">Torna alle prenotazioni</a>
        </div>
    </section>

    <?php if (!$recordTableReady): ?>
        <section class="anagrafica-alert-card">
            <h2>Attivazione database richiesta</h2>
            <p class="muted">Prima di usare la pianificazione giornaliera esegui le migration SQL della sezione anagrafica e del calendario giornaliero ROSS1000.</p>
            <div class="code">admin/database/2026-04-17_ross1000_day_status.sql</div>
        </section>
    <?php endif; ?>

    <section class="card ross-month-card">
        <div class="ross-month-toolbar ross-surface">
            <div class="ross-month-toolbar__title">
                <h2><?= e(anagrafica_month_label($monthStart)) ?></h2>
                <p class="muted">Ogni card rappresenta una giornata della struttura. Clicca un giorno per gestire apertura, riepilogo e export.</p>
                <div class="ross-month-toolbar__meta">
                    <span><?= (int) $monthOpenDaysCount ?> giorni aperti</span>
                    <span><?= (int) $monthFinalizedDaysCount ?> giorni chiusi</span>
                    <span><?= count($days) ?> giorni nel mese</span>
                </div>
            </div>
            <div class="ross-month-toolbar__controls">
                <form class="ross-month-picker" method="get" action="<?= e(admin_url('anagrafica.php')) ?>">
                    <a class="btn btn-light btn-sm" href="<?= e($prevMonthUrl) ?>" aria-label="Mese precedente">‹</a>
                    <label class="ross-month-picker__field">
                        <span>Mese</span>
                        <input type="month" name="month" value="<?= e($selectedMonth) ?>" max="2099-12">
                    </label>
                    <button class="btn btn-primary btn-sm" type="submit">Apri mese</button>
                    <a class="btn btn-light btn-sm" href="<?= e($nextMonthUrl) ?>" aria-label="Mese successivo">›</a>
                </form>
                <form class="ross-month-quick-export" method="post" action="<?= e(admin_url('actions/generate-ross1000-month.php')) ?>" data-month-export-form>
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="month" value="<?= e($selectedMonth) ?>">
                    <button class="btn btn-primary btn-sm ross-month-quick-export__button<?= $rossConfigReady ? '' : ' is-disabled' ?>" type="submit" <?= $rossConfigReady ? '' : 'disabled' ?>>
                        <span>Apri tutto il mese + esporta ROSS1000</span>
                    </button>
                    <small>Precompila i giorni non chiusi come aperti con la disponibilità standard e scarica l'XML mensile.</small>
                </form>
            </div>
        </div>

        <div class="ross-day-carousel" data-day-carousel>
            <button class="ross-day-carousel__nav ross-day-carousel__nav--prev" type="button" data-day-carousel-prev aria-label="Scorri ai giorni precedenti">
                <span aria-hidden="true">‹</span>
            </button>

            <div class="ross-day-carousel__viewport" data-day-carousel-viewport>
                <div class="ross-day-strip" data-day-strip aria-label="Calendario giornaliero del mese">
                    <?php foreach ($days as $date => $snapshot): ?>
                        <?php
                        $isSelected = $date === $selectedDay;
                        $isOpen = (bool) $snapshot['is_open'];
                        $dayClass = 'ross-day-card';
                        if ($isSelected) {
                            $dayClass .= ' is-selected';
                        }
                        if (!$isOpen) {
                            $dayClass .= ' is-closed';
                        } elseif ((int) ($snapshot['occupied_rooms'] ?? 0) > 0) {
                            $dayClass .= ' is-busy';
                        } else {
                            $dayClass .= ' is-zero';
                        }
                        if ((int) (($snapshot['day_state']['is_finalized'] ?? 0)) === 1) {
                            $dayClass .= ' is-finalized';
                        }
                        ?>
                        <a class="<?= e($dayClass) ?>" href="<?= e(admin_url('anagrafica.php?month=' . rawurlencode($selectedMonth) . '&day=' . rawurlencode($date))) ?>" data-day-card="<?= e($date) ?>">
                            <div class="ross-day-card__top">
                                <span class="ross-day-card__weekday"><?= e(anagrafica_weekday_label($date)) ?></span>
                                <span class="ross-day-card__number"><?= e((new DateTimeImmutable($date))->format('d')) ?></span>
                            </div>
                            <div class="ross-day-card__status">
                                <strong><?= $isOpen ? 'Aperta' : 'Chiusa' ?></strong>
                                <span><?= ((int) ($snapshot['day_state']['is_finalized'] ?? 0) === 1) ? 'Giorno chiuso' : 'In lavorazione' ?></span>
                            </div>
                            <dl class="ross-day-card__metrics">
                                <div><dt>Cam.</dt><dd><?= (int) ($snapshot['occupied_rooms'] ?? 0) ?></dd></div>
                                <div><dt>Pers.</dt><dd><?= (int) ($snapshot['present_guests'] ?? 0) ?></dd></div>
                                <div><dt>Arr.</dt><dd><?= (int) ($snapshot['arrivals_guests'] ?? 0) ?></dd></div>
                                <div><dt>Part.</dt><dd><?= (int) ($snapshot['departures_guests'] ?? 0) ?></dd></div>
                            </dl>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <button class="ross-day-carousel__nav ross-day-carousel__nav--next" type="button" data-day-carousel-next aria-label="Scorri ai giorni successivi">
                <span aria-hidden="true">›</span>
            </button>
        </div>
    </section>

    <section class="card ross-day-detail">
        <div class="ross-day-detail__head ross-surface">
            <div>
                <span class="eyebrow">Giorno selezionato</span>
                <h2><?= e((new DateTimeImmutable($selectedDay))->format('d/m/Y')) ?></h2>
                <p class="muted">Riepilogo completo della giornata: apertura, camere, arrivi, partenze e prenotazioni registrate.</p>
            </div>
            <div class="ross-day-detail__head-actions">
                <a class="btn btn-primary" href="<?= e($newPageUrl) ?>" data-anagrafica-open-link>Nuova anagrafica per questo giorno</a>
            </div>
        </div>

        <div class="ross-day-stats">
            <article class="ross-day-stat">
                <span>Stato struttura</span>
                <strong><?= $selectedDayOpen ? 'Aperta' : 'Chiusa' ?></strong>
                <small><?= $selectedDayFinalized ? 'Giornata chiusa' : 'Giornata ancora modificabile' ?></small>
            </article>
            <article class="ross-day-stat">
                <span>Camere occupate</span>
                <strong><?= (int) ($selectedSnapshot['occupied_rooms'] ?? 0) ?></strong>
                <small>su <?= $selectedDayOpen ? $selectedDayAvailableRooms : 0 ?> disponibili</small>
            </article>
            <article class="ross-day-stat">
                <span>Persone presenti</span>
                <strong><?= (int) ($selectedSnapshot['present_guests'] ?? 0) ?></strong>
                <small>persone in struttura nel giorno</small>
            </article>
            <article class="ross-day-stat">
                <span>Movimenti del giorno</span>
                <strong><?= (int) ($selectedSnapshot['arrivals_guests'] ?? 0) ?> / <?= (int) ($selectedSnapshot['departures_guests'] ?? 0) ?></strong>
                <small>arrivi / partenze</small>
            </article>
        </div>

        <?php if (!empty($selectedSnapshot['warnings'])): ?>
            <div class="ross-day-warning">
                <?php foreach ($selectedSnapshot['warnings'] as $warning): ?>
                    <div><?= e((string) $warning) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="ross-day-panels">
            <form class="ross-day-settings" method="post" action="<?= e(admin_url('actions/update-ross1000-day.php')) ?>">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="month" value="<?= e($selectedMonth) ?>">
                <input type="hidden" name="day" value="<?= e($selectedDay) ?>">

                <div class="section-title">
                    <h3>Impostazioni giornata</h3>
                    <p class="muted">Di default la struttura è aperta. Puoi chiudere il giorno oppure correggere disponibilità camere/letti.</p>
                </div>

                <label class="ross-switch">
                    <input type="checkbox" name="is_open" value="1" <?= $selectedDayOpen ? 'checked' : '' ?>>
                    <span>Struttura aperta</span>
                </label>

                <div class="ross-day-settings__grid">
                    <label>
                        <span>Camere disponibili</span>
                        <input type="number" min="0" name="available_rooms" value="<?= e((string) $selectedDayAvailableRooms) ?>">
                    </label>
                    <label>
                        <span>Letti disponibili</span>
                        <input type="number" min="0" name="available_beds" value="<?= e((string) $selectedDayAvailableBeds) ?>">
                    </label>
                </div>

                <div class="ross-day-settings__actions">
                    <button class="btn btn-light" type="submit" name="intent" value="save">Salva impostazioni</button>
                    <?php if ($selectedDayFinalized): ?>
                        <button class="btn btn-light" type="submit" name="intent" value="reopen">Riapri giorno</button>
                    <?php else: ?>
                        <button class="btn btn-primary" type="submit" name="intent" value="close">Chiudi giorno</button>
                    <?php endif; ?>
                </div>
            </form>

            <div class="ross-day-export card-lite">
                <div class="section-title">
                    <h3>Export della giornata</h3>
                    <p class="muted">Quando il giorno è chiuso definitivamente puoi esportare il file giornaliero.</p>
                </div>
                <div class="ross-day-export__buttons">
                    <a class="btn btn-primary<?= $selectedDayFinalized ? '' : ' is-disabled' ?>" href="<?= $selectedDayFinalized ? e(admin_url('actions/generate-ross1000-day.php?month=' . rawurlencode($selectedMonth) . '&day=' . rawurlencode($selectedDay))) : '#' ?>" data-day-export-link data-confirm-message="Confermare l'esportazione del file ROSS1000 del giorno selezionato?"<?= $selectedDayFinalized ? '' : ' aria-disabled="true" tabindex="-1"' ?>>Esporta ROSS1000</a>
                    <a class="btn btn-light<?= $selectedDayFinalized ? '' : ' is-disabled' ?>" href="<?= $selectedDayFinalized ? e(admin_url('actions/generate-alloggiati-day.php?month=' . rawurlencode($selectedMonth) . '&day=' . rawurlencode($selectedDay))) : '#' ?>" data-day-export-link data-confirm-message="Confermare l'esportazione del file Alloggiati del giorno selezionato?"<?= $selectedDayFinalized ? '' : ' aria-disabled="true" tabindex="-1"' ?>>Alloggiati (prossimo step)</a>
                </div>
                <dl class="ross-day-export__meta">
                    <div><dt>ROSS</dt><dd><?= !empty($selectedDayState['exported_ross_at']) ? e(date('d/m/Y H:i', strtotime((string) $selectedDayState['exported_ross_at']))) : 'Non esportato' ?></dd></div>
                    <div><dt>Alloggiati</dt><dd><?= !empty($selectedDayState['exported_alloggiati_at']) ? e(date('d/m/Y H:i', strtotime((string) $selectedDayState['exported_alloggiati_at']))) : 'Non esportato' ?></dd></div>
                </dl>
            </div>
        </div>

        <div class="card-lite ross-day-records">
            <div class="section-title">
                <h3>Prenotazioni che toccano il giorno</h3>
                <p class="muted">Ogni riga indica se il record genera arrivo, partenza, prenotazione registrata o semplice presenza nel giorno selezionato.</p>
            </div>

            <?php if (!$selectedRecords): ?>
                <div class="anagrafica-empty-state">
                    <strong>Nessuna prenotazione rilevata</strong>
                    <p class="muted">Puoi creare una nuova anagrafica cliccando sul pulsante in alto.</p>
                </div>
            <?php else: ?>
                <div class="ross-record-list">
                    <?php foreach ($selectedRecords as $row): ?>
                        <?php
                        $record = $row['record'];
                        $flags = $row['flags'];
                        $label = $row['label'];
                        $editUrl = admin_url('anagrafica.php?month=' . rawurlencode($selectedMonth) . '&day=' . rawurlencode($selectedDay) . '&edit=' . (int) $record['id']);
                        ?>
                        <article class="ross-record-row" tabindex="0" data-record-row data-edit-url="<?= e($editUrl) ?>">
                            <div class="ross-record-row__main">
                                <strong><?= e($label) ?></strong>
                                <div class="ross-record-row__subline">
                                    <?php if (!empty($record['booking_reference'])): ?><span>Rif. <?= e((string) $record['booking_reference']) ?></span><?php endif; ?>
                                    <span><?= e(date('d/m/Y', strtotime((string) $record['arrival_date']))) ?> → <?= e(date('d/m/Y', strtotime((string) $record['departure_date']))) ?></span>
                                    <span><?= (int) ($record['expected_guests'] ?? 0) ?> ospiti · <?= (int) ($record['reserved_rooms'] ?? 0) ?> camere</span>
                                </div>
                            </div>
                            <div class="ross-record-row__badges" data-row-ignore>
                                <?php if ($flags['booking']): ?><span class="ross-badge">Prenotazione</span><?php endif; ?>
                                <?php if ($flags['arrival']): ?><span class="ross-badge ross-badge--green">Arrivo</span><?php endif; ?>
                                <?php if ($flags['departure']): ?><span class="ross-badge ross-badge--amber">Partenza</span><?php endif; ?>
                                <?php if ($flags['present']): ?><span class="ross-badge ross-badge--blue">Presenza</span><?php endif; ?>
                            </div>
                            <div class="ross-record-row__actions" data-row-ignore>
                                <a class="btn btn-light btn-sm" href="<?= e($editUrl) ?>">Modifica</a>
                                <form method="post" action="<?= e(admin_url('actions/delete-anagrafica.php')) ?>" data-delete-form>
                                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>">
                                    <input type="hidden" name="return_month" value="<?= e($selectedMonth) ?>">
                                    <input type="hidden" name="return_day" value="<?= e($selectedDay) ?>">
                                    <button class="btn btn-light btn-sm btn-danger-soft" type="submit">Elimina</button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="card anagrafica-form-card<?= $forceOpenForm ? ' is-open' : '' ?>" id="anagraficaFormCard"<?= $forceOpenForm ? '' : ' hidden' ?> data-force-open="<?= $forceOpenForm ? '1' : '0' ?>" data-base-url="<?= e($basePageUrl) ?>">
        <div class="section-title section-title--split">
            <div>
                <h2><?= $formIsEdit ? 'Modifica anagrafica' : 'Nuova anagrafica' ?></h2>
                <p class="muted"><?= $formIsEdit ? 'Aggiorna il record selezionato e ricalcola le giornate coinvolte.' : 'Compila il form solo quando devi registrare una nuova anagrafica o prenotazione.' ?></p>
            </div>
            <button class="btn btn-light" type="button" id="closeAnagraficaForm">Chiudi modulo</button>
        </div>

        <form class="anagrafica-form" method="post" action="<?= e(admin_url('actions/create-anagrafica.php')) ?>" id="anagraficaForm">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="record_id" value="<?= (int) $formRecord['id'] ?>">
            <input type="hidden" name="return_month" value="<?= e($selectedMonth) ?>">
            <input type="hidden" name="return_day" value="<?= e($selectedDay) ?>">

            <div class="anagrafica-section">
                <div class="anagrafica-section__header">
                    <div>
                        <h3>Dati soggiorno / testata</h3>
                        <p class="muted">Questi valori alimentano la parte prenotazione e la timeline giornaliera.</p>
                    </div>
                </div>

                <div class="anagrafica-grid">
                    <label>
                        <span>Tipologia record</span>
                        <select name="record_type" id="recordType">
                            <?php foreach ($recordTypeOptions as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= $formRecord['record_type'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <span>Riferimento prenotazione</span>
                        <input type="text" name="booking_reference" maxlength="100" value="<?= e((string) $formRecord['booking_reference']) ?>" placeholder="Es. PLC-2026-0012">
                    </label>
                    <label>
                        <span>Data registrazione prenotazione</span>
                        <input type="text" name="booking_received_date" class="js-date" data-date-role="booking-received" value="<?= e((string) $formRecord['booking_received_date']) ?>" placeholder="Seleziona la data" autocomplete="off" required>
                    </label>
                    <label>
                        <span>Data arrivo prevista</span>
                        <input type="text" name="arrival_date" class="js-date" data-date-role="arrival" value="<?= e((string) $formRecord['arrival_date']) ?>" placeholder="Seleziona la data" autocomplete="off" required>
                    </label>

                    <label>
                        <span>Data partenza prevista</span>
                        <input type="text" name="departure_date" class="js-date" data-date-role="departure" value="<?= e((string) $formRecord['departure_date']) ?>" placeholder="Seleziona la data" autocomplete="off" required>
                    </label>
                    <label>
                        <span>Numero ospiti attesi</span>
                        <input type="number" min="1" name="expected_guests" id="expectedGuests" value="<?= e((string) $formRecord['expected_guests']) ?>">
                    </label>
                    <label>
                        <span>Numero camere</span>
                        <input type="number" min="1" name="reserved_rooms" value="<?= e((string) $formRecord['reserved_rooms']) ?>">
                    </label>
                    <label>
                        <span>Canale prenotazione</span>
                        <select name="booking_channel">
                            <option value="">Seleziona</option>
                            <?php foreach ($channels as $value): ?>
                                <option value="<?= e($value) ?>" <?= $formRecord['booking_channel'] === $value ? 'selected' : '' ?>><?= e($value) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        <span>Prezzo per persona / giorno</span>
                        <input type="number" step="0.01" min="0" name="daily_price" value="<?= e((string) $formRecord['daily_price']) ?>" placeholder="Es. 65.00">
                    </label>
                    <label>
                        <span>Stato provenienza prenotazione</span>
                        <input list="state-options" name="booking_provenience_state_label" value="<?= e((string) $formRecord['booking_provenience_state_label']) ?>" placeholder="Seleziona o digita">
                    </label>
                    <label>
                        <span>Provincia provenienza (se Italia)</span>
                        <input list="province-options" name="booking_provenience_province" value="<?= e((string) $formRecord['booking_provenience_province']) ?>" placeholder="Seleziona o digita">
                    </label>
                    <label>
                        <span>Luogo provenienza prenotazione</span>
                        <input list="place-options" name="booking_provenience_place_label" value="<?= e((string) $formRecord['booking_provenience_place_label']) ?>" placeholder="Comune italiano, NUTS o località">
                    </label>
                </div>
            </div>

            <div class="anagrafica-section">
                <div class="anagrafica-section__header">
                    <div>
                        <h3>Capogruppo / primo ospite</h3>
                        <p class="muted">Questi dati saranno usati come riferimento principale del record.</p>
                    </div>
                </div>
                <?php $guestIndex = 0; $guestData = $leaderGuest; $isRepeaterGuest = false; $guestNumber = 1; require __DIR__ . '/includes/anagrafica_guest_fields.partial.php'; ?>
            </div>

            <div class="anagrafica-section">
                <div class="anagrafica-section__header">
                    <div>
                        <h3>Componenti aggiuntivi</h3>
                        <p class="muted">Aggiungi altri ospiti solo quando il record è di tipo Famiglia o Gruppo.</p>
                    </div>
                    <button class="btn btn-light" type="button" id="addGuestButton">Aggiungi componente</button>
                </div>
                <div class="anagrafica-repeater" id="guestRepeater">
                    <?php foreach ($additionalGuests as $guestLoopIndex => $guestLoop): ?>
                        <?php $guestIndex = $guestLoopIndex + 1; $guestData = $guestLoop; $isRepeaterGuest = true; $guestNumber = $guestLoopIndex + 2; require __DIR__ . '/includes/anagrafica_guest_fields.partial.php'; ?>
                    <?php endforeach; ?>
                </div>
            </div>

            <template id="guestTemplate">
                <div class="anagrafica-guest-card" data-guest-card>
                    <div class="anagrafica-guest-card__top">
                        <div>
                            <strong>Componente <span data-guest-number></span></strong>
                            <p class="muted">Questo ospite verrà collegato automaticamente al capogruppo.</p>
                        </div>
                        <button class="btn btn-light btn-sm" type="button" data-remove-guest>Rimuovi</button>
                    </div>
                    <div class="anagrafica-grid">
                        <label><span>Nome</span><input type="text" data-name="first_name" maxlength="100"></label>
                        <label><span>Cognome</span><input type="text" data-name="last_name" maxlength="100"></label>
                        <label><span>Sesso</span><select data-name="gender"><option value="M">Maschio</option><option value="F">Femmina</option></select></label>
                        <label><span>Data di nascita</span><input type="text" class="js-date" data-date-role="birth" data-name="birth_date" placeholder="Seleziona la data" autocomplete="off"></label>
                        <label><span>Cittadinanza</span><input list="state-options" data-name="citizenship_label" placeholder="Seleziona uno stato"></label>
                        <label><span>Stato di nascita</span><input list="state-options" data-name="birth_state_label" placeholder="Seleziona uno stato"></label>
                        <label><span>Provincia nascita (se Italia)</span><input list="province-options" data-name="birth_province" placeholder="Seleziona o digita"></label>
                        <label><span>Luogo/comune nascita</span><input list="city-options" data-name="birth_place_label" placeholder="Se Italia scegli il comune"></label>
                        <label><span>Stato di residenza</span><input list="state-options" data-name="residence_state_label" placeholder="Seleziona uno stato"></label>
                        <label><span>Provincia residenza (se Italia)</span><input list="province-options" data-name="residence_province" placeholder="Seleziona o digita"></label>
                        <label><span>Luogo residenza</span><input list="place-options" data-name="residence_place_label" placeholder="Comune italiano, NUTS o località"></label>
                        <label><span>Tipologia documento</span><select data-name="document_type_label"><option value="">Seleziona</option><?php foreach ($documentTypes as $value => $label): ?><option value="<?= e($label) ?>"><?= e($label) ?></option><?php endforeach; ?></select></label>
                        <label><span>N. documento</span><input type="text" data-name="document_number" maxlength="50"></label>
                        <label><span>Data documento</span><input type="text" class="js-date" data-date-role="document-issue" data-name="document_issue_date" placeholder="Seleziona la data" autocomplete="off"></label>
                        <label><span>Scadenza documento</span><input type="text" class="js-date" data-date-role="document-expiry" data-name="document_expiry_date" placeholder="Seleziona la data" autocomplete="off"></label>
                        <label><span>Luogo emissione documento</span><input list="city-options" data-name="document_issue_place" placeholder="Seleziona o digita"></label>
                        <label><span>Email</span><input type="email" data-name="email" maxlength="190"></label>
                        <label><span>Telefono</span><input type="text" data-name="phone" maxlength="40"></label>
                        <label><span>Tipo turismo</span><select data-name="tourism_type"><?php foreach ($tourismTypes as $value): ?><option value="<?= e($value) ?>"><?= e($value) ?></option><?php endforeach; ?></select></label>
                        <label><span>Mezzo di trasporto</span><select data-name="transport_type"><?php foreach ($transportTypes as $value): ?><option value="<?= e($value) ?>"><?= e($value) ?></option><?php endforeach; ?></select></label>
                        <label><span>Titolo di studio</span><select data-name="education_level"><option value="">Seleziona</option><?php foreach ($educationLevels as $value): ?><option value="<?= e($value) ?>"><?= e($value) ?></option><?php endforeach; ?></select></label>
                        <label><span>Professione</span><input type="text" data-name="profession" maxlength="120"></label>
                        <label><span>Codice esenzione imposta</span><input type="text" data-name="tax_exemption_code" maxlength="40"></label>
                    </div>
                </div>
            </template>

            <div class="anagrafica-actions">
                <button class="btn btn-primary" type="submit"><?= $formIsEdit ? 'Aggiorna anagrafica' : 'Salva anagrafica' ?></button>
            </div>
        </form>
    </section>
</div>

<datalist id="state-options"><?php foreach ($stateOptions as $code => $label): ?><option value="<?= e($label) ?>"><?php endforeach; ?></datalist>
<datalist id="province-options"><?php foreach ($province as $code => $provinceName): ?><option value="<?= e($provinceName) ?>"><?php endforeach; ?></datalist>
<datalist id="city-options"><?php foreach ($cityOptions as $city): ?><option value="<?= e($city) ?>"><?php endforeach; ?></datalist>
<datalist id="place-options"><?php foreach ($placeOptions as $place): ?><option value="<?= e($place) ?>"><?php endforeach; ?></datalist>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
