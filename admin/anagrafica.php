<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/anagrafica-options.php';
require_once __DIR__ . '/includes/ross1000-config.php';
require_once __DIR__ . '/includes/ross1000.php';
require_once __DIR__ . '/includes/alloggiati.php';
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
$alloggiatiTableReady = false;
$selectedSchedine = [];
$selectedSchedineCounts = ['total' => 0, 'bozza' => 0, 'pronta' => 0, 'inviata' => 0, 'errore' => 0];
$alloggiatiWsConfig = alloggiati_ws_config();
$alloggiatiWsReady = alloggiati_ws_config_ready($alloggiatiWsConfig);
$monthDayCount = count($days);
$monthOpenCount = 0;
$monthClosedCount = 0;
$monthFinalizedCount = 0;
foreach ($days as $snapshotCount) {
    if (!empty($snapshotCount['is_open'])) {
        $monthOpenCount++;
    } else {
        $monthClosedCount++;
    }
    if (!empty($snapshotCount['day_state']['is_finalized'])) {
        $monthFinalizedCount++;
    }
}
$monthPendingCount = max(0, $monthDayCount - $monthFinalizedCount);
$alloggiatiDayExportableCount = (int) ($selectedSchedineCounts['pronta'] + $selectedSchedineCounts['inviata']);
$alloggiatiDayDocumentCount = 0;
foreach ($selectedSchedine as $schedinaCount) {
    $payloadCount = is_array($schedinaCount['payload'] ?? null) ? $schedinaCount['payload'] : [];
    if (trim((string) ($payloadCount['document_number'] ?? '')) !== '') {
        $alloggiatiDayDocumentCount++;
    }
}

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


if ($recordTableReady && isset($days[$selectedDay])) {
    $selectedSnapshot = $days[$selectedDay] ?? null;
    $selectedRecords = (array) ($selectedSnapshot['touching_records'] ?? []);
}

try {
    if ($recordTableReady) {
        $alloggiatiTableReady = alloggiati_schedine_table_ready($pdo);
        if ($alloggiatiTableReady) {
            $selectedSchedine = alloggiati_sync_day($pdo, $selectedDay);
            $selectedSchedineCounts = alloggiati_day_status_counts($selectedSchedine);
        }
    }
} catch (Throwable $e) {
    $alloggiatiTableReady = false;
    $selectedSchedine = [];
    $selectedSchedineCounts = ['total' => 0, 'bozza' => 0, 'pronta' => 0, 'inviata' => 0, 'errore' => 0];
}

$formState = $_SESSION['_anagrafica_form_state'] ?? null;
unset($_SESSION['_anagrafica_form_state']);

$fieldErrors = is_array($formState['field_errors'] ?? null) ? $formState['field_errors'] : [];
$formMessages = is_array($formState['messages'] ?? null) ? $formState['messages'] : [];
$oldFormData = is_array($formState['data'] ?? null) ? $formState['data'] : null;

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
];

$leaderGuest = $editingGuests[0] ?? [];
$additionalGuests = $editingGuests ? array_slice($editingGuests, 1) : [];

if ($oldFormData) {
    $oldGuests = array_values(array_filter($oldFormData['guests'] ?? [], 'is_array'));
    $formRecord = [
        'id' => max(0, (int) ($oldFormData['record_id'] ?? 0)),
        'record_type' => (string) ($oldFormData['record_type'] ?? 'single'),
        'booking_reference' => (string) ($oldFormData['booking_reference'] ?? ''),
        'booking_received_date' => (string) ($oldFormData['booking_received_date'] ?? anagrafica_form_date($defaultBookingReceived)),
        'arrival_date' => (string) ($oldFormData['arrival_date'] ?? ''),
        'departure_date' => (string) ($oldFormData['departure_date'] ?? ''),
        'expected_guests' => (string) max(1, count($oldGuests) ?: (int) ($oldFormData['expected_guests'] ?? 1)),
        'reserved_rooms' => (string) ($oldFormData['reserved_rooms'] ?? 1),
    ];
    $leaderGuest = $oldGuests[0] ?? [];
    $additionalGuests = $oldGuests ? array_slice($oldGuests, 1) : [];
    $formIsEdit = $formRecord['id'] > 0;
}

$errorFor = static function (string $field) use ($fieldErrors): string {
    return (string) ($fieldErrors[$field] ?? '');
};

$fieldClass = static function (string $field) use ($fieldErrors): string {
    return isset($fieldErrors[$field]) ? ' is-invalid' : '';
};

$forceOpenForm = isset($_GET['new']) || $formIsEdit || (bool) $oldFormData;
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


$provinceNameToCode = [];
foreach ($province as $provinceCode => $provinceLabel) {
    $provinceNameToCode[$provinceLabel] = $provinceCode;
    $provinceNameToCode[$provinceCode] = $provinceCode;
}
ksort($provinceNameToCode);

$comuniByProvince = anagrafica_comune_labels_by_province();

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
                    <button class="btn btn-primary btn-sm ross-month-quick-export__button<?= $rossConfigReady ? '' : ' is-disabled' ?> js-confirm-modal-trigger" type="submit" <?= $rossConfigReady ? '' : 'disabled' ?> data-modal-template-id="rossMonthConfirmTemplate">
                        <span>Apri tutto il mese + esporta ROSS1000</span>
                    </button>
                    <small>Precompila i giorni non chiusi come aperti con la disponibilità standard e scarica l'XML mensile.</small>
                </form>
                <template id="rossMonthConfirmTemplate">
                    <div class="alloggiati-confirm">
                        <div class="alloggiati-confirm__grid">
                            <div><span>Mese</span><strong><?= e(anagrafica_month_label($monthStart)) ?></strong></div>
                            <div><span>Giorni nel mese</span><strong><?= (int) $monthDayCount ?></strong></div>
                            <div><span>Giorni già chiusi</span><strong><?= (int) $monthFinalizedCount ?></strong></div>
                            <div><span>Giorni da precompilare</span><strong><?= (int) $monthPendingCount ?></strong></div>
                            <div><span>Camere standard</span><strong><?= (int) ($config['camere_disponibili'] ?? 0) ?></strong></div>
                            <div><span>Letti standard</span><strong><?= (int) ($config['letti_disponibili'] ?? 0) ?></strong></div>
                        </div>
                        <p class="muted">L'azione lascia invariati i giorni già chiusi, precompila i restanti come aperti con disponibilità standard e scarica l'XML mensile ROSS1000.</p>
                    </div>
                </template>
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
                        <button class="btn btn-primary js-confirm-modal-trigger" type="submit" name="intent" value="close" data-modal-template-id="rossDayCloseTemplate">Chiudi giorno</button>
                    <?php endif; ?>
                </div>
                <template id="rossDayCloseTemplate">
                    <div class="alloggiati-confirm">
                        <div class="alloggiati-confirm__grid">
                            <div><span>Giorno</span><strong><?= e((new DateTimeImmutable($selectedDay))->format('d/m/Y')) ?></strong></div>
                            <div><span>Stato struttura</span><strong><?= $selectedDayOpen ? 'Aperta' : 'Chiusa' ?></strong></div>
                            <div><span>Camere occupate</span><strong><?= (int) ($selectedSnapshot['occupied_rooms'] ?? 0) ?></strong></div>
                            <div><span>Persone presenti</span><strong><?= (int) ($selectedSnapshot['present_guests'] ?? 0) ?></strong></div>
                            <div><span>Arrivi / Partenze</span><strong><?= (int) ($selectedSnapshot['arrivals_guests'] ?? 0) ?> / <?= (int) ($selectedSnapshot['departures_guests'] ?? 0) ?></strong></div>
                            <div><span>Disponibilità</span><strong><?= (int) $selectedDayAvailableRooms ?> cam. · <?= (int) $selectedDayAvailableBeds ?> letti</strong></div>
                        </div>
                        <p class="muted">Confermando, il giorno viene chiuso e potrà essere esportato con il riepilogo definitivo dei movimenti.</p>
                    </div>
                </template>
            </form>

            <div class="ross-day-export card-lite">
                <div class="section-title">
                    <h3>Export della giornata</h3>
                    <p class="muted">Quando il giorno è chiuso definitivamente puoi esportare il file giornaliero.</p>
                </div>
                <div class="ross-day-export__buttons">
                    <a class="btn btn-primary<?= $selectedDayFinalized ? '' : ' is-disabled' ?> js-confirm-modal-trigger" href="<?= $selectedDayFinalized ? e(admin_url('actions/generate-ross1000-day.php?month=' . rawurlencode($selectedMonth) . '&day=' . rawurlencode($selectedDay))) : '#' ?>" data-day-export-link data-modal-template-id="rossDayExportTemplate"<?= $selectedDayFinalized ? '' : ' aria-disabled="true" tabindex="-1"' ?>>Esporta ROSS1000</a>
                    <a class="btn btn-light" href="#alloggiatiDaySection">Schedine Alloggiati</a>
                </div>
                <dl class="ross-day-export__meta">
                    <div><dt>ROSS</dt><dd><?= !empty($selectedDayState['exported_ross_at']) ? e(date('d/m/Y H:i', strtotime((string) $selectedDayState['exported_ross_at']))) : 'Non esportato' ?></dd></div>
                    <div><dt>Alloggiati</dt><dd><?= !empty($selectedDayState['exported_alloggiati_at']) ? e(date('d/m/Y H:i', strtotime((string) $selectedDayState['exported_alloggiati_at']))) : 'Non esportato' ?></dd></div>
                </dl>
                <template id="rossDayExportTemplate">
                    <div class="alloggiati-confirm">
                        <div class="alloggiati-confirm__grid">
                            <div><span>Giorno</span><strong><?= e((new DateTimeImmutable($selectedDay))->format('d/m/Y')) ?></strong></div>
                            <div><span>Camere occupate</span><strong><?= (int) ($selectedSnapshot['occupied_rooms'] ?? 0) ?></strong></div>
                            <div><span>Persone presenti</span><strong><?= (int) ($selectedSnapshot['present_guests'] ?? 0) ?></strong></div>
                            <div><span>Arrivi / Partenze</span><strong><?= (int) ($selectedSnapshot['arrivals_guests'] ?? 0) ?> / <?= (int) ($selectedSnapshot['departures_guests'] ?? 0) ?></strong></div>
                            <div><span>Camere disponibili</span><strong><?= (int) $selectedDayAvailableRooms ?></strong></div>
                            <div><span>Letti disponibili</span><strong><?= (int) $selectedDayAvailableBeds ?></strong></div>
                        </div>
                        <p class="muted">Scaricherai il file XML ROSS1000 del giorno selezionato con la fotografia completa della giornata.</p>
                    </div>
                </template>
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

    
    <section class="card ross-day-detail" id="alloggiatiDaySection">
        <div class="ross-day-detail__head ross-surface">
            <div>
                <span class="eyebrow">Alloggiati Web</span>
                <h2>Schedine del giorno di arrivo <?= e((new DateTimeImmutable($selectedDay))->format('d/m/Y')) ?></h2>
                <p class="muted">La vista mostra le schedine generate dai record con data di arrivo uguale al giorno selezionato. Da qui puoi controllare stato, inviare tutto il giorno o una sola schedina.</p>
            </div>
            <div class="ross-day-detail__head-actions">
                <?php if ($alloggiatiTableReady): ?>
                    <form method="get" action="<?= e(admin_url('actions/generate-alloggiati-day.php')) ?>" class="alloggiati-day-send-form">
                        <input type="hidden" name="month" value="<?= e($selectedMonth) ?>">
                        <input type="hidden" name="day" value="<?= e($selectedDay) ?>">
                        <button class="btn btn-light<?= $selectedSchedineCounts['pronta'] > 0 || $selectedSchedineCounts['inviata'] > 0 ? '' : ' is-disabled' ?> js-alloggiati-modal-trigger" type="submit" <?= ($selectedSchedineCounts['pronta'] > 0 || $selectedSchedineCounts['inviata'] > 0) ? '' : 'disabled' ?> data-modal-template-id="alloggiatiDayFileTemplate">Genera file Alloggiati del giorno</button>
                    </form>
                    <form method="post" action="<?= e(admin_url('actions/send-alloggiati-day.php')) ?>" class="alloggiati-day-send-form">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="month" value="<?= e($selectedMonth) ?>">
                        <input type="hidden" name="day" value="<?= e($selectedDay) ?>">
                        <button class="btn btn-primary<?= $selectedSchedineCounts['pronta'] > 0 ? '' : ' is-disabled' ?> js-alloggiati-modal-trigger" type="submit" <?= $selectedSchedineCounts['pronta'] > 0 ? '' : 'disabled' ?> data-modal-template-id="alloggiatiDayConfirmTemplate">Invia tutte le schedine del giorno</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$alloggiatiTableReady): ?>
            <div class="anagrafica-empty-state">
                <strong>Tabella schedine Alloggiati non disponibile</strong>
                <p class="muted">Esegui la migration <code>admin/database/2026-04-18_alloggiati_schedine.sql</code> per attivare la gestione delle schedine.</p>
            </div>
        <?php else: ?>
            <div class="ross-day-stats">
                <article class="ross-day-stat"><span>Totale schedine</span><strong><?= (int) $selectedSchedineCounts['total'] ?></strong><small>generate dal giorno di arrivo</small></article>
                <article class="ross-day-stat"><span>Bozza</span><strong><?= (int) $selectedSchedineCounts['bozza'] ?></strong><small>arrivo futuro o non ancora inviabile</small></article>
                <article class="ross-day-stat"><span>Pronte</span><strong><?= (int) $selectedSchedineCounts['pronta'] ?></strong><small>inviabili ora</small></article>
                <article class="ross-day-stat"><span>Inviate / Errore</span><strong><?= (int) $selectedSchedineCounts['inviata'] ?> / <?= (int) $selectedSchedineCounts['errore'] ?></strong><small>stato aggiornato delle schedine</small></article>
            </div>
            <div class="ross-day-warning">
                <div><strong>Tracciato giornaliero pronto</strong> · Le schedine vengono esportate come file testuale a righe fisse da 168 caratteri, in linea con il tracciato Alloggiati. Le righe corrette possono essere inviate in blocco o singolarmente.</div>
                <div><strong>Web service</strong> · <?= $alloggiatiWsReady ? 'Configurazione WS presente.' : 'Predisposizione WS pronta: completa utente, password e WSKEY in includes/alloggiati-config.php per la finalizzazione.' ?></div>
            </div>

            <?php if (!$selectedSchedine): ?>
                <div class="anagrafica-empty-state">
                    <strong>Nessuna schedina per questo giorno</strong>
                    <p class="muted">Le schedine Alloggiati vengono generate per i record con data di arrivo uguale al giorno selezionato.</p>
                </div>
            <?php else: ?>
                <template id="alloggiatiDayFileTemplate">
                    <div class="alloggiati-confirm">
                        <div class="alloggiati-confirm__grid">
                            <div><span>Giorno</span><strong><?= e((new DateTimeImmutable($selectedDay))->format('d/m/Y')) ?></strong></div>
                            <div><span>Schedine esportabili</span><strong><?= (int) ($selectedSchedineCounts['pronta'] + $selectedSchedineCounts['inviata']) ?></strong></div>
                            <div><span>Schedine pronte</span><strong><?= (int) $selectedSchedineCounts['pronta'] ?></strong></div>
                            <div><span>Documenti valorizzati</span><strong><?= (int) $alloggiatiDayDocumentCount ?></strong></div>
                        </div>
                        <p class="muted">Il download contiene le schedine valide del giorno di arrivo selezionato, ordinate per record con il capo famiglia/gruppo prima dei componenti.</p>
                    </div>
                </template>

                <template id="alloggiatiDayConfirmTemplate">
                    <div class="alloggiati-confirm">
                        <div class="alloggiati-confirm__grid">
                            <div><span>Giorno</span><strong><?= e((new DateTimeImmutable($selectedDay))->format('d/m/Y')) ?></strong></div>
                            <div><span>Schedine pronte</span><strong><?= (int) $selectedSchedineCounts['pronta'] ?></strong></div>
                            <div><span>Schedine già inviate</span><strong><?= (int) $selectedSchedineCounts['inviata'] ?></strong></div>
                            <div><span>Schedine con errore</span><strong><?= (int) $selectedSchedineCounts['errore'] ?></strong></div>
                            <div><span>Documenti valorizzati</span><strong><?= (int) $alloggiatiDayDocumentCount ?></strong></div>
                            <div><span>WS</span><strong><?= $alloggiatiWsReady ? 'Predisposto' : 'Da configurare' ?></strong></div>
                        </div>
                        <p class="muted">Conferma l'invio delle schedine pronte del giorno selezionato. Il tracciato record e le richieste SOAP GenerateToken/Test/Send sono già predisposti nel backend.</p>
                    </div>
                </template>

                <div class="alloggiati-schedine-list">
                    <?php foreach ($selectedSchedine as $schedina): ?>
                        <?php
                        $payload = $schedina['payload'] ?? [];
                        $schedinaId = (int) ($schedina['id'] ?? 0);
                        $status = (string) ($schedina['status'] ?? 'bozza');
                        $statusLabelMap = ['bozza' => 'Bozza', 'pronta' => 'Pronta', 'inviata' => 'Inviata', 'errore' => 'Errore'];
                        $statusClassMap = ['bozza' => 'ross-badge', 'pronta' => 'ross-badge ross-badge--blue', 'inviata' => 'ross-badge ross-badge--green', 'errore' => 'ross-badge ross-badge--amber'];
                        $canSendSingle = in_array($status, ['pronta', 'errore'], true);
                        ?>
                        <article class="ross-record-row alloggiati-schedina-row">
                            <div class="ross-record-row__main">
                                <strong><?= e((string) ($schedina['display_name'] ?? ($payload['display_name'] ?? 'Schedina'))) ?></strong>
                                <div class="ross-record-row__subline">
                                    <span><?= e((string) ($payload['tipo_alloggiato_label'] ?? '')) ?></span>
                                    <span>Arrivo <?= e((string) ($payload['arrival_date_xml'] ?? '')) ?></span>
                                    <span>Permanenza <?= (int) ($payload['permanence_days'] ?? 0) ?> gg</span>
                                    <?php if (!empty($payload['document_type_label'])): ?><span><?= e((string) $payload['document_type_label']) ?> · <?= e((string) ($payload['document_number'] ?? '')) ?></span><?php endif; ?>
                                </div>
                                <?php if (!empty($schedina['last_error'])): ?>
                                    <div class="alloggiati-schedina-row__error"><?= nl2br(e((string) $schedina['last_error'])) ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="ross-record-row__badges" data-row-ignore>
                                <span class="<?= e($statusClassMap[$status] ?? 'ross-badge') ?>"><?= e($statusLabelMap[$status] ?? ucfirst($status)) ?></span>
                                <?php if (!empty($schedina['sent_at'])): ?><span class="ross-badge">Inviata <?= e(date('d/m H:i', strtotime((string) $schedina['sent_at']))) ?></span><?php endif; ?>
                            </div>
                            <div class="ross-record-row__actions" data-row-ignore>
                                <?php if (!empty($schedina['can_generate_file'])): ?>
                                    <form method="get" action="<?= e(admin_url('actions/generate-alloggiati-schedina.php')) ?>" class="alloggiati-single-send-form">
                                        <input type="hidden" name="month" value="<?= e($selectedMonth) ?>">
                                        <input type="hidden" name="day" value="<?= e($selectedDay) ?>">
                                        <input type="hidden" name="schedina_id" value="<?= $schedinaId ?>">
                                        <button class="btn btn-light btn-sm js-alloggiati-modal-trigger" type="submit" data-modal-template-id="alloggiatiSchedinaFile<?= $schedinaId ?>">Scarica tracciato</button>
                                    </form>
                                    <template id="alloggiatiSchedinaFile<?= $schedinaId ?>">
                                        <div class="alloggiati-confirm">
                                            <div class="alloggiati-confirm__grid">
                                                <div><span>Ospite</span><strong><?= e((string) ($schedina['display_name'] ?? ($payload['display_name'] ?? ''))) ?></strong></div>
                                                <div><span>Tipo</span><strong><?= e((string) ($payload['tipo_alloggiato_label'] ?? '')) ?></strong></div>
                                                <div><span>Arrivo</span><strong><?= e((string) ($payload['arrival_date_portal'] ?? '')) ?></strong></div>
                                                <?php if (!empty($payload['document_type_label'])): ?><div><span>Documento</span><strong><?= e((string) $payload['document_type_label']) ?> · <?= e((string) ($payload['document_number'] ?? '')) ?></strong></div><?php else: ?><div><span>Documento</span><strong>Non richiesto</strong></div><?php endif; ?>
                                            </div>
                                            <p class="muted">Scaricherai il tracciato record della schedina selezionata nel formato testuale previsto da Alloggiati Web.</p>
                                        </div>
                                    </template>
                                <?php else: ?>
                                    <button class="btn btn-light btn-sm is-disabled" type="button" disabled>Tracciato non disponibile</button>
                                <?php endif; ?>

                                <?php if ($canSendSingle): ?>
                                    <form method="post" action="<?= e(admin_url('actions/send-alloggiati-schedina.php')) ?>" class="alloggiati-single-send-form">
                                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="month" value="<?= e($selectedMonth) ?>">
                                        <input type="hidden" name="day" value="<?= e($selectedDay) ?>">
                                        <input type="hidden" name="schedina_id" value="<?= $schedinaId ?>">
                                        <button class="btn btn-light btn-sm js-alloggiati-modal-trigger" type="submit" data-modal-template-id="alloggiatiSchedinaConfirm<?= $schedinaId ?>"><?= $status === 'errore' ? 'Ritenta invio' : 'Invia schedina' ?></button>
                                    </form>
                                    <template id="alloggiatiSchedinaConfirm<?= $schedinaId ?>">
                                        <div class="alloggiati-confirm">
                                            <div class="alloggiati-confirm__grid">
                                                <div><span>Ospite</span><strong><?= e((string) ($schedina['display_name'] ?? ($payload['display_name'] ?? ''))) ?></strong></div>
                                                <div><span>Tipo</span><strong><?= e((string) ($payload['tipo_alloggiato_label'] ?? '')) ?></strong></div>
                                                <div><span>Arrivo</span><strong><?= e((string) ($payload['arrival_date_portal'] ?? '')) ?></strong></div>
                                                <div><span>Permanenza</span><strong><?= (int) ($payload['permanence_days'] ?? 0) ?> gg</strong></div>
                                            </div>
                                            <?php if (!empty($payload['document_type_label'])): ?>
                                                <p class="muted">Documento: <?= e((string) $payload['document_type_label']) ?> · <?= e((string) ($payload['document_number'] ?? '')) ?></p>
                                            <?php endif; ?>
                                            <p class="muted">Conferma l'invio della schedina selezionata. Il sistema usa il tracciato record corretto e prepara anche la richiesta SOAP di Test/Send per la fase finale del WS.</p>
                                        </div>
                                    </template>
                                <?php else: ?>
                                    <button class="btn btn-light btn-sm is-disabled" type="button" disabled><?= $status === 'inviata' ? 'Già inviata' : 'Non inviab.' ?></button>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <div class="anagrafica-modal" id="alloggiatiConfirmModal" hidden>
        <div class="anagrafica-modal__backdrop" data-modal-close></div>
        <div class="anagrafica-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="alloggiatiConfirmTitle">
            <div class="anagrafica-modal__header">
                <div>
                    <span class="eyebrow">Conferma azione</span>
                    <h3 id="alloggiatiConfirmTitle">Verifica riepilogo</h3>
                </div>
                <button class="btn btn-light btn-sm" type="button" data-modal-close>Chiudi</button>
            </div>
            <div class="anagrafica-modal__body" id="alloggiatiConfirmBody"></div>
            <div class="anagrafica-modal__actions">
                <button class="btn btn-light" type="button" data-modal-close>Annulla</button>
                <button class="btn btn-primary" type="button" id="alloggiatiConfirmSubmit">Conferma azione</button>
            </div>
        </div>
    </div>

<section class="card anagrafica-form-card<?= $forceOpenForm ? ' is-open' : '' ?>" id="anagraficaFormCard"<?= $forceOpenForm ? '' : ' hidden' ?> data-force-open="<?= $forceOpenForm ? '1' : '0' ?>" data-base-url="<?= e($basePageUrl) ?>" data-selected-day="<?= e($selectedDay) ?>">
        <div class="section-title section-title--split">
            <div>
                <h2><?= $formIsEdit ? 'Modifica anagrafica' : 'Nuova anagrafica' ?></h2>
                <p class="muted"><?= $formIsEdit ? 'Aggiorna il record selezionato e ricalcola le giornate coinvolte.' : 'Compila il form solo quando devi registrare una nuova anagrafica o prenotazione.' ?></p>
            </div>
            <button class="btn btn-light" type="button" id="closeAnagraficaForm">Chiudi modulo</button>
        </div>

        <form class="anagrafica-form" method="post" action="<?= e(admin_url('actions/create-anagrafica.php')) ?>" id="anagraficaForm" novalidate>
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="record_id" value="<?= (int) $formRecord['id'] ?>">
            <input type="hidden" name="return_month" value="<?= e($selectedMonth) ?>">
            <input type="hidden" name="return_day" value="<?= e($selectedDay) ?>">
            <input type="hidden" name="expected_guests" id="expectedGuests" value="<?= e((string) $formRecord['expected_guests']) ?>">

            <?php if ($formMessages): ?>
                <div class="anagrafica-form-alert" role="alert">
                    <strong>Controlla i campi evidenziati</strong>
                    <ul>
                        <?php foreach ($formMessages as $message): ?>
                            <li><?= e((string) $message) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="anagrafica-section">
                <div class="anagrafica-section__header">
                    <div>
                        <h3>Dati soggiorno</h3>
                        <p class="muted">Il giorno selezionato alimenta registrazione prenotazione e data di arrivo. Compila solo i campi indispensabili ai tracciati.</p>
                    </div>
                </div>

                <div class="anagrafica-grid anagrafica-grid--compact">
                    <label class="anagrafica-field<?= e($fieldClass('record_type')) ?>">
                        <span>Tipologia record</span>
                        <select name="record_type" id="recordType">
                            <?php foreach ($recordTypeOptions as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= $formRecord['record_type'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($errorFor('record_type') !== ''): ?><small class="anagrafica-field-error"><?= e($errorFor('record_type')) ?></small><?php endif; ?>
                    </label>

                    <label class="anagrafica-field<?= e($fieldClass('booking_received_date')) ?>">
                        <span>Data registrazione prenotazione</span>
                        <input type="text" name="booking_received_date" class="js-date" data-date-role="booking-received" value="<?= e((string) $formRecord['booking_received_date']) ?>" placeholder="Seleziona la data" autocomplete="off" required>
                        <?php if ($errorFor('booking_received_date') !== ''): ?><small class="anagrafica-field-error"><?= e($errorFor('booking_received_date')) ?></small><?php endif; ?>
                    </label>

                    <label class="anagrafica-field<?= e($fieldClass('arrival_date')) ?>">
                        <span>Data arrivo prevista</span>
                        <input type="text" name="arrival_date" class="js-date" data-date-role="arrival" value="<?= e((string) $formRecord['arrival_date']) ?>" placeholder="Seleziona la data" autocomplete="off" required>
                        <?php if ($errorFor('arrival_date') !== ''): ?><small class="anagrafica-field-error"><?= e($errorFor('arrival_date')) ?></small><?php endif; ?>
                    </label>

                    <label class="anagrafica-field<?= e($fieldClass('departure_date')) ?>">
                        <span>Data partenza prevista</span>
                        <input type="text" name="departure_date" class="js-date" data-date-role="departure" value="<?= e((string) $formRecord['departure_date']) ?>" placeholder="Seleziona la data" autocomplete="off" required>
                        <?php if ($errorFor('departure_date') !== ''): ?><small class="anagrafica-field-error"><?= e($errorFor('departure_date')) ?></small><?php endif; ?>
                    </label>

                    <label class="anagrafica-field<?= e($fieldClass('reserved_rooms')) ?>">
                        <span>Numero camere prenotate</span>
                        <input type="number" min="1" name="reserved_rooms" value="<?= e((string) $formRecord['reserved_rooms']) ?>" required>
                        <?php if ($errorFor('reserved_rooms') !== ''): ?><small class="anagrafica-field-error"><?= e($errorFor('reserved_rooms')) ?></small><?php endif; ?>
                    </label>
                </div>
            </div>

            <div class="anagrafica-section">
                <div class="anagrafica-section__header">
                    <div>
                        <h3>Capogruppo / primo ospite</h3>
                        <p class="muted">Documento richiesto solo per ospite singolo o capogruppo/capofamiglia.</p>
                    </div>
                </div>
                <?php $guestIndex = 0; $guestData = $leaderGuest; $isRepeaterGuest = false; $guestNumber = 1; require __DIR__ . '/includes/anagrafica_guest_fields.partial.php'; ?>
            </div>

            <div class="anagrafica-section">
                <div class="anagrafica-section__header">
                    <div>
                        <h3>Componenti aggiuntivi</h3>
                        <p class="muted">Per famiglia o gruppo aggiungi un componente alla volta. Per i componenti non servono i dati del documento.</p>
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
                <div class="anagrafica-guest-card" data-guest-card data-guest-scope>
                    <div class="anagrafica-guest-card__top">
                        <div>
                            <strong>Componente <span data-guest-number></span></strong>
                            <p class="muted">Compila solo i dati essenziali per ROSS1000 e Alloggiati Web.</p>
                        </div>
                        <button class="btn btn-light btn-sm" type="button" data-remove-guest>Rimuovi</button>
                    </div>
                    <div class="anagrafica-grid">
                        <label class="anagrafica-field"><span>Nome</span><input type="text" data-name="first_name" maxlength="100" required></label>
                        <label class="anagrafica-field"><span>Cognome</span><input type="text" data-name="last_name" maxlength="100" required></label>
                        <label class="anagrafica-field"><span>Sesso</span><select data-name="gender" required><option value="">Seleziona</option><option value="M">Maschio</option><option value="F">Femmina</option></select></label>
                        <label class="anagrafica-field"><span>Data di nascita</span><input type="text" class="js-date" data-date-role="birth" data-name="birth_date" placeholder="Seleziona la data" autocomplete="off" required></label>
                        <label class="anagrafica-field"><span>Cittadinanza</span><input list="state-options" data-name="citizenship_label" placeholder="Seleziona uno stato" required></label>
                        <label class="anagrafica-field"><span>Stato di nascita</span><input list="state-options" data-state-role="birth" data-name="birth_state_label" placeholder="Seleziona uno stato" required></label>
                        <label class="anagrafica-field"><span>Provincia nascita (se Italia)</span><input list="province-options" data-province-role="birth" data-name="birth_province" placeholder="Seleziona provincia"></label>
                        <label class="anagrafica-field"><span>Comune nascita</span><input data-list-template="birth" data-place-role="birth" data-name="birth_place_label" placeholder="Se scegli Italia, seleziona il comune"></label>
                        <label class="anagrafica-field"><span>Stato di residenza</span><input list="state-options" data-state-role="residence" data-name="residence_state_label" placeholder="Seleziona uno stato" required></label>
                        <label class="anagrafica-field"><span>Provincia residenza (se Italia)</span><input list="province-options" data-province-role="residence" data-name="residence_province" placeholder="Seleziona provincia"></label>
                        <label class="anagrafica-field"><span>Comune / località residenza</span><input data-list-template="residence" data-place-role="residence" data-name="residence_place_label" placeholder="Comune italiano, NUTS o località" required></label>
                        <label class="anagrafica-field"><span>Tipo turismo</span><select data-name="tourism_type" required><option value="">Seleziona</option><?php foreach ($tourismTypes as $value): ?><option value="<?= e($value) ?>"><?= e($value) ?></option><?php endforeach; ?></select></label>
                        <label class="anagrafica-field"><span>Mezzo di trasporto</span><select data-name="transport_type" required><option value="">Seleziona</option><?php foreach ($transportTypes as $value): ?><option value="<?= e($value) ?>"><?= e($value) ?></option><?php endforeach; ?></select></label>
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

<script type="application/json" id="anagraficaProvinceMap"><?= json_encode($provinceNameToCode, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script type="application/json" id="anagraficaComuniByProvince"><?= json_encode($comuniByProvince, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
