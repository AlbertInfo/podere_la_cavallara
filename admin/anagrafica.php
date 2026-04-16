<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/anagrafica-options.php';
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

$recordTableReady = false;
$records = [];
$editingRecord = null;
$editingGuests = [];

$editRecordId = max(0, (int) ($_GET['edit'] ?? 0));
$createdRecordId = max(0, (int) ($_GET['created'] ?? 0));
$updatedRecordId = max(0, (int) ($_GET['updated'] ?? 0));
$deletedRecordId = max(0, (int) ($_GET['deleted'] ?? 0));
$rowHighlightId = max($createdRecordId, $updatedRecordId);

try {
    $recordTableReady = (bool) $pdo->query("SHOW TABLES LIKE 'anagrafica_records'")->fetchColumn();
    if ($recordTableReady) {
        $records = $pdo->query(
            "SELECT ar.id, ar.record_type, ar.booking_reference, ar.arrival_date, ar.departure_date, ar.expected_guests, ar.reserved_rooms, ar.status, ar.created_at,
                    leader.first_name, leader.last_name
             FROM anagrafica_records ar
             LEFT JOIN anagrafica_guests leader ON leader.record_id = ar.id AND leader.is_group_leader = 1
             ORDER BY ar.arrival_date DESC, ar.id DESC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($editRecordId > 0) {
            $stmt = $pdo->prepare('SELECT * FROM anagrafica_records WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $editRecordId]);
            $editingRecord = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($editingRecord) {
                $guestStmt = $pdo->prepare('SELECT * FROM anagrafica_guests WHERE record_id = :record_id ORDER BY is_group_leader DESC, id ASC');
                $guestStmt->execute(['record_id' => $editRecordId]);
                $editingGuests = $guestStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
        }
    }
} catch (Throwable $e) {
    $recordTableReady = false;
    $records = [];
    $editingRecord = null;
    $editingGuests = [];
}

$cittadinanze = anagrafica_eu_citizenships();
$province = anagrafica_province_italiane();
$documentTypes = anagrafica_document_types();
$citta = anagrafica_citta_italiane_principali();
$channels = anagrafica_booking_channels();
$tourismTypes = anagrafica_tourism_types();
$transportTypes = anagrafica_transport_types();

$formIsEdit = $editingRecord !== null;
$formRecord = [
    'id' => $editingRecord['id'] ?? 0,
    'record_type' => $editingRecord['record_type'] ?? 'single',
    'booking_reference' => $editingRecord['booking_reference'] ?? '',
    'arrival_date' => anagrafica_form_date($editingRecord['arrival_date'] ?? ''),
    'departure_date' => anagrafica_form_date($editingRecord['departure_date'] ?? ''),
    'expected_guests' => (string) ($editingRecord['expected_guests'] ?? 1),
    'reserved_rooms' => (string) ($editingRecord['reserved_rooms'] ?? 1),
    'booking_channel' => $editingRecord['booking_channel'] ?? '',
    'daily_price' => $editingRecord['daily_price'] ?? '',
];

$leaderGuest = $editingGuests[0] ?? [];
$additionalGuests = $editingGuests ? array_slice($editingGuests, 1) : [];

$forceOpenForm = isset($_GET['new']) || $formIsEdit;
$basePageUrl = admin_url('anagrafica.php');
$newPageUrl = admin_url('anagrafica.php?new=1');

require_once __DIR__ . '/includes/header.php';
?>
<div class="booking-page anagrafica-shell">
    <section class="booking-hero anagrafica-hero">
        <div class="booking-hero-copy">
            <span class="eyebrow">Sezione anagrafica</span>
            <h1>Anagrafiche / prenotazioni</h1>
            <p class="muted">Gestisci le anagrafiche create, riaprile per modificarle e prepara il terreno ai futuri export.</p>
        </div>
        <div class="toolbar anagrafica-hero__actions">
            <a class="btn btn-primary" href="<?= e($newPageUrl) ?>" data-anagrafica-open-link>Nuova anagrafica</a>
            <a class="btn btn-light" href="<?= e(admin_url('index.php#registered-bookings')) ?>">Torna alle prenotazioni</a>
        </div>
    </section>

    <?php if (!$recordTableReady): ?>
        <section class="anagrafica-alert-card">
            <h2>Attivazione database richiesta</h2>
            <p class="muted">Prima di usare il salvataggio esegui la migration SQL della sezione anagrafica.</p>
            <div class="code">admin/database/2026-04-15_anagrafica_records.sql</div>
        </section>
    <?php endif; ?>

    <section class="card anagrafica-summary-card">
        <div class="section-title section-title--split anagrafica-summary-head">
            <div>
                <h2>Riepilogo anagrafiche</h2>
                <p class="muted">Clicca una riga per aprire il dettaglio e modificarlo.</p>
            </div>
            <div class="anagrafica-summary-meta"><?= count($records) ?> record</div>
        </div>

        <?php if (!$recordTableReady): ?>
            <div class="anagrafica-empty-state">
                <strong>Tabella non attiva</strong>
                <p class="muted">Completa prima la migration, poi qui vedrai le anagrafiche salvate.</p>
            </div>
        <?php elseif (empty($records)): ?>
            <div class="anagrafica-empty-state">
                <strong>Nessuna anagrafica presente</strong>
                <p class="muted">Clicca su “Nuova anagrafica” per creare il primo record.</p>
            </div>
        <?php else: ?>
            <div class="anagrafica-list" role="table" aria-label="Elenco anagrafiche create">
                <div class="anagrafica-list__head" role="rowgroup">
                    <div class="anagrafica-list__row anagrafica-list__row--head" role="row">
                        <div role="columnheader">Anagrafica</div>
                        <div role="columnheader">Arrivo</div>
                        <div role="columnheader">Partenza</div>
                        <div role="columnheader">Azioni</div>
                    </div>
                </div>
                <div class="anagrafica-list__body" role="rowgroup">
                    <?php foreach ($records as $record): ?>
                        <?php
                        $rowName = trim((string) (($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? '')));
                        $rowName = $rowName !== '' ? $rowName : 'Ospite da completare';
                        $rowClass = [];
                        if ((int) $record['id'] === $rowHighlightId) {
                            $rowClass[] = 'is-highlighted';
                        }
                        if ((int) $record['id'] === $deletedRecordId) {
                            $rowClass[] = 'is-deleted';
                        }
                        ?>
                        <article
                            class="anagrafica-list__row <?= e(implode(' ', $rowClass)) ?>"
                            role="row"
                            tabindex="0"
                            data-record-row
                            data-edit-url="<?= e(admin_url('anagrafica.php?edit=' . (int) $record['id'])) ?>"
                            aria-label="Apri modifica per <?= e($rowName) ?>"
                        >
                            <div class="anagrafica-list__main" role="cell">
                                <strong><?= e($rowName) ?></strong>
                                <div class="anagrafica-list__subline">
                                    <?php if (!empty($record['booking_reference'])): ?>
                                        <span>Rif. <?= e((string) $record['booking_reference']) ?></span>
                                    <?php endif; ?>
                                    <span><?= ($record['record_type'] ?? 'single') === 'group' ? 'Gruppo / famiglia' : 'Singolo' ?></span>
                                    <span><?= (int) ($record['expected_guests'] ?? 0) ?> ospiti</span>
                                </div>
                            </div>
                            <div role="cell"><span class="anagrafica-list__date"><?= e(date('d/m/Y', strtotime((string) $record['arrival_date']))) ?></span></div>
                            <div role="cell"><span class="anagrafica-list__date"><?= e(date('d/m/Y', strtotime((string) $record['departure_date']))) ?></span></div>
                            <div class="anagrafica-list__actions" role="cell" data-row-ignore>
                                <button type="button" class="anagrafica-icon-btn" title="Crea file ROSS1000" aria-label="Crea file ROSS1000" disabled>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"></path><path d="M14 3v6h6"></path><path d="M8 13h8"></path><path d="M8 17h6"></path></svg>
                                    <span class="anagrafica-icon-btn__label">ROSS1000</span>
                                </button>
                                <button type="button" class="anagrafica-icon-btn anagrafica-icon-btn--secondary" title="Crea file Alloggiati Web" aria-label="Crea file Alloggiati Web" disabled>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h16v12H4z"></path><path d="M8 20h8"></path><path d="M10 16v4"></path><path d="M8 8h8"></path><path d="M8 12h5"></path></svg>
                                    <span class="anagrafica-icon-btn__label">Alloggiati</span>
                                </button>
                                <form method="post" action="<?= e(admin_url('actions/delete-anagrafica.php')) ?>" class="anagrafica-delete-form" data-delete-form>
                                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>">
                                    <button type="submit" class="anagrafica-icon-btn anagrafica-icon-btn--danger" title="Elimina anagrafica" aria-label="Elimina anagrafica">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"></polyline><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path><path d="M10 11v6"></path><path d="M14 11v6"></path></svg>
                                    </button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </section>

    <section class="card anagrafica-form-card<?= $forceOpenForm ? ' is-open' : '' ?>" id="anagraficaFormPanel"<?= $forceOpenForm ? '' : ' hidden' ?> data-anagrafica-form-panel data-base-url="<?= e($basePageUrl) ?>" data-force-open="<?= $forceOpenForm ? '1' : '0' ?>">
        <div class="section-title section-title--split anagrafica-form-head">
            <div>
                <h2><?= $formIsEdit ? 'Modifica anagrafica' : 'Nuova anagrafica' ?></h2>
                <p class="muted"><?= $formIsEdit ? 'Aggiorna i dati e salva le modifiche.' : 'Compila il form solo quando devi registrare una nuova anagrafica o prenotazione.' ?></p>
            </div>
            <button class="btn btn-light" type="button" data-anagrafica-close aria-controls="anagraficaFormPanel">Chiudi modulo</button>
        </div>

        <form method="post" action="<?= e(admin_url('actions/create-anagrafica.php')) ?>" class="anagrafica-form" id="anagraficaForm" data-mode="<?= $formIsEdit ? 'edit' : 'create' ?>">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="record_id" value="<?= (int) $formRecord['id'] ?>">

            <div class="anagrafica-section">
                <div class="anagrafica-grid anagrafica-grid--top">
                    <label>
                        <span>Tipologia record</span>
                        <select name="record_type" id="recordType" required>
                            <option value="single" <?= $formRecord['record_type'] === 'single' ? 'selected' : '' ?>>Ospite singolo</option>
                            <option value="group" <?= $formRecord['record_type'] === 'group' ? 'selected' : '' ?>>Gruppo / famiglia</option>
                        </select>
                    </label>
                    <label>
                        <span>Riferimento prenotazione</span>
                        <input type="text" name="booking_reference" maxlength="50" value="<?= e((string) $formRecord['booking_reference']) ?>" placeholder="Es. PLC-2026-0012">
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
                        <input type="number" min="1" max="30" name="expected_guests" id="expectedGuests" value="<?= e((string) $formRecord['expected_guests']) ?>" required>
                    </label>
                    <label>
                        <span>Numero camere</span>
                        <input type="number" min="1" max="30" name="reserved_rooms" value="<?= e((string) $formRecord['reserved_rooms']) ?>" required>
                    </label>
                    <label>
                        <span>Canale prenotazione</span>
                        <select name="booking_channel">
                            <option value="">Seleziona</option>
                            <?php foreach ($channels as $value): ?>
                                <option value="<?= e($value) ?>" <?= (string) $formRecord['booking_channel'] === $value ? 'selected' : '' ?>><?= e($value) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <span>Prezzo per persona / giorno</span>
                        <input type="number" step="0.01" min="0" name="daily_price" value="<?= e((string) $formRecord['daily_price']) ?>" placeholder="Es. 65.00">
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
                        <h3>Componenti del gruppo</h3>
                        <p class="muted">Aggiungi altri ospiti solo quando il record è di tipo gruppo / famiglia.</p>
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
                            <p class="muted">Questo ospite verrà collegato al capogruppo.</p>
                        </div>
                        <button class="btn btn-light btn-sm" type="button" data-remove-guest>Rimuovi</button>
                    </div>
                    <div class="anagrafica-grid">
                        <label><span>Nome</span><input type="text" data-name="first_name" maxlength="100"></label>
                        <label><span>Cognome</span><input type="text" data-name="last_name" maxlength="100"></label>
                        <label><span>Sesso</span><select data-name="gender"><option value="M">Maschio</option><option value="F">Femmina</option></select></label>
                        <label><span>Data di nascita</span><input type="text" class="js-date" data-date-role="birth" data-name="birth_date" placeholder="Seleziona la data" autocomplete="off"></label>
                        <label><span>Cittadinanza</span><input list="citizenship-options" data-name="citizenship_label" placeholder="Seleziona o digita"></label>
                        <label><span>Provincia di residenza</span><input list="province-options" data-name="residence_province" placeholder="Seleziona o digita"></label>
                        <label><span>Luogo di residenza</span><input type="text" data-name="residence_place" maxlength="120"></label>
                        <label><span>Tipologia documento</span><select data-name="document_type"><?php foreach ($documentTypes as $value => $label): ?><option value="<?= e($value) ?>"><?= e($label) ?></option><?php endforeach; ?></select></label>
                        <label><span>N. documento</span><input type="text" data-name="document_number" maxlength="50"></label>
                        <label><span>Data documento</span><input type="text" class="js-date" data-date-role="document-issue" data-name="document_issue_date" placeholder="Seleziona la data" autocomplete="off"></label>
                        <label><span>Scadenza documento</span><input type="text" class="js-date" data-date-role="document-expiry" data-name="document_expiry_date" placeholder="Seleziona la data" autocomplete="off"></label>
                        <label><span>Luogo di emissione</span><input list="city-options" data-name="document_issue_place" placeholder="Seleziona o digita"></label>
                        <label><span>Email</span><input type="email" data-name="email" maxlength="190"></label>
                        <label><span>Telefono</span><input type="text" data-name="phone" maxlength="40"></label>
                        <label><span>Tipo turismo</span><select data-name="tourism_type"><?php foreach ($tourismTypes as $value): ?><option value="<?= e($value) ?>"><?= e($value) ?></option><?php endforeach; ?></select></label>
                        <label><span>Mezzo di trasporto</span><select data-name="transport_type"><?php foreach ($transportTypes as $value): ?><option value="<?= e($value) ?>"><?= e($value) ?></option><?php endforeach; ?></select></label>
                    </div>
                </div>
            </template>

            <div class="anagrafica-actions">
                <button class="btn btn-primary" type="submit"><?= $formIsEdit ? 'Aggiorna anagrafica' : 'Salva anagrafica' ?></button>
            </div>
        </form>
    </section>
</div>

<datalist id="citizenship-options"><?php foreach ($cittadinanze as $citizenship): ?><option value="<?= e($citizenship) ?>"><?php endforeach; ?></datalist>
<datalist id="province-options"><?php foreach ($province as $code => $provinceName): ?><option value="<?= e($provinceName) ?>"><?php endforeach; ?></datalist>
<datalist id="city-options"><?php foreach ($citta as $city): ?><option value="<?= e($city) ?>"><?php endforeach; ?></datalist>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
