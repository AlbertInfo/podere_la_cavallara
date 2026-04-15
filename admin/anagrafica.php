<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/anagrafica-options.php';
require_admin();

$pageTitle = 'Sezione anagrafica';

$recordTableReady = false;
$records = [];
try {
    $recordTableReady = (bool) $pdo->query("SHOW TABLES LIKE 'anagrafica_records'")->fetchColumn();
    if ($recordTableReady) {
        $sql = "
            SELECT
                ar.id,
                ar.record_type,
                ar.booking_reference,
                ar.arrival_date,
                ar.departure_date,
                ar.expected_guests,
                ar.reserved_rooms,
                ar.status,
                ar.created_at,
                leader.first_name,
                leader.last_name
            FROM anagrafica_records ar
            LEFT JOIN anagrafica_guests leader
                ON leader.record_id = ar.id
               AND leader.is_group_leader = 1
            ORDER BY ar.created_at DESC, ar.id DESC
        ";
        $records = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    $recordTableReady = false;
    $records = [];
}

$cittadinanze = anagrafica_eu_citizenships();
$province = anagrafica_province_italiane();
$documentTypes = anagrafica_document_types();
$citta = anagrafica_citta_italiane_principali();
$channels = anagrafica_booking_channels();
$tourismTypes = anagrafica_tourism_types();
$transportTypes = anagrafica_transport_types();

$createdRecordId = max(0, (int) ($_GET['created'] ?? 0));
$forceOpenForm = isset($_GET['new']) || !$recordTableReady;

require_once __DIR__ . '/includes/header.php';
?>
<div class="booking-page anagrafica-shell">
    <section class="booking-hero anagrafica-hero">
        <div class="booking-hero-copy">
            <span class="eyebrow">Sezione anagrafica</span>
            <h1>Anagrafiche / prenotazioni</h1>
            <p class="muted">Consulta le anagrafiche create e apri il modulo solo quando devi inserire un nuovo record.</p>
        </div>
        <div class="toolbar anagrafica-hero__actions">
            <button class="btn btn-primary" type="button" data-anagrafica-toggle aria-expanded="<?= $forceOpenForm ? 'true' : 'false' ?>" aria-controls="anagraficaFormPanel">
                Nuova anagrafica
            </button>
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
        <div class="section-title section-title--split">
            <div>
                <h2>Riepilogo anagrafiche</h2>
                <p class="muted">Ogni riga rappresenta una nuova anagrafica pronta per i futuri export.</p>
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
                        <div role="columnheader">Ospiti</div>
                        <div role="columnheader">Azioni</div>
                    </div>
                </div>
                <div class="anagrafica-list__body" role="rowgroup">
                    <?php foreach ($records as $record): ?>
                        <?php
                        $rowName = trim((string) (($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? '')));
                        $rowName = $rowName !== '' ? $rowName : 'Ospite da completare';
                        $rowClass = ((int) $record['id'] === $createdRecordId) ? ' is-highlighted' : '';
                        ?>
                        <article class="anagrafica-list__row<?= $rowClass ?>" role="row" data-record-row>
                            <div class="anagrafica-list__main" role="cell">
                                <strong><?= e($rowName) ?></strong>
                                <div class="anagrafica-list__subline">
                                    <?php if (!empty($record['booking_reference'])): ?>
                                        <span>Rif. <?= e((string) $record['booking_reference']) ?></span>
                                    <?php endif; ?>
                                    <span><?= ($record['record_type'] ?? 'single') === 'group' ? 'Gruppo / famiglia' : 'Singolo' ?></span>
                                </div>
                            </div>
                            <div role="cell">
                                <span class="anagrafica-list__date"><?= e(date('d/m/Y', strtotime((string) $record['arrival_date']))) ?></span>
                            </div>
                            <div role="cell">
                                <span class="anagrafica-list__date"><?= e(date('d/m/Y', strtotime((string) $record['departure_date']))) ?></span>
                            </div>
                            <div role="cell">
                                <span class="anagrafica-pill"><?= (int) ($record['expected_guests'] ?? 0) ?> ospiti</span>
                            </div>
                            <div class="anagrafica-list__actions" role="cell">
                                <button type="button" class="anagrafica-icon-btn" title="Crea file ROSS1000" aria-label="Crea file ROSS1000" disabled>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"></path><path d="M14 3v6h6"></path><path d="M8 13h8"></path><path d="M8 17h6"></path></svg>
                                    <span>ROSS1000</span>
                                </button>
                                <button type="button" class="anagrafica-icon-btn anagrafica-icon-btn--secondary" title="Crea file Alloggiati Web" aria-label="Crea file Alloggiati Web" disabled>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h16v12H4z"></path><path d="M8 20h8"></path><path d="M10 16v4"></path><path d="M8 8h8"></path><path d="M8 12h5"></path></svg>
                                    <span>Alloggiati</span>
                                </button>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </section>

    <section class="card anagrafica-form-card<?= $forceOpenForm ? ' is-open' : '' ?>" id="anagraficaFormPanel" data-anagrafica-form-panel>
        <div class="section-title section-title--split">
            <div>
                <h2>Nuova anagrafica</h2>
                <p class="muted">Compila il form solo quando devi registrare una nuova anagrafica o prenotazione.</p>
            </div>
            <button class="btn btn-light" type="button" data-anagrafica-close aria-controls="anagraficaFormPanel">Chiudi modulo</button>
        </div>

        <form method="post" action="<?= e(admin_url('actions/create-anagrafica.php')) ?>" class="anagrafica-form" id="anagraficaForm">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">

            <div class="anagrafica-section">
                <div class="anagrafica-grid anagrafica-grid--top">
                    <label>
                        <span>Tipologia record</span>
                        <select name="record_type" id="recordType" required>
                            <option value="single">Ospite singolo</option>
                            <option value="group">Gruppo / famiglia</option>
                        </select>
                    </label>
                    <label>
                        <span>Riferimento prenotazione</span>
                        <input type="text" name="booking_reference" maxlength="50" placeholder="Es. PLC-2026-0012">
                    </label>
                    <label>
                        <span>Data arrivo prevista</span>
                        <input type="text" name="arrival_date" class="js-date" data-date-role="arrival" placeholder="Seleziona la data" autocomplete="off" required>
                    </label>
                    <label>
                        <span>Data partenza prevista</span>
                        <input type="text" name="departure_date" class="js-date" data-date-role="departure" placeholder="Seleziona la data" autocomplete="off" required>
                    </label>
                    <label>
                        <span>Numero ospiti attesi</span>
                        <input type="number" name="expected_guests" id="expectedGuests" min="1" value="1" required>
                    </label>
                    <label>
                        <span>Numero camere</span>
                        <input type="number" name="reserved_rooms" min="1" value="1" required>
                    </label>
                    <label>
                        <span>Canale prenotazione</span>
                        <select name="booking_channel">
                            <?php foreach ($channels as $channel): ?>
                                <option value="<?= e($channel) ?>"><?= e($channel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <span>Prezzo giornaliero per persona</span>
                        <input type="number" name="daily_price" min="0" step="0.01" placeholder="0.00">
                    </label>
                </div>
            </div>

            <div class="anagrafica-section">
                <div class="anagrafica-section__header">
                    <div>
                        <h3>Capogruppo / primo ospite</h3>
                        <p class="muted">Nel caso gruppo sarà il record principale mostrato nel riepilogo.</p>
                    </div>
                </div>

                <?php $guestIndex = 0; include __DIR__ . '/includes/anagrafica_guest_fields.partial.php'; ?>
            </div>

            <div class="anagrafica-section">
                <div class="anagrafica-section__header">
                    <div>
                        <h3>Componenti gruppo / famiglia</h3>
                        <p class="muted">Aggiungi gli altri ospiti solo se il record è di tipo gruppo o famiglia.</p>
                    </div>
                    <button class="btn btn-light" type="button" id="addGuestButton">Aggiungi componente</button>
                </div>

                <div id="guestRepeater" class="anagrafica-repeater"></div>
            </div>

            <template id="guestTemplate">
                <div class="anagrafica-guest-card is-clone" data-guest-card>
                    <div class="anagrafica-guest-card__top">
                        <strong>Componente <span data-guest-number></span></strong>
                        <button type="button" class="btn btn-light btn-sm" data-remove-guest>Rimuovi</button>
                    </div>
                    <div class="anagrafica-grid">
                        <label><span>Nome</span><input type="text" data-name="first_name" maxlength="100"></label>
                        <label><span>Cognome</span><input type="text" data-name="last_name" maxlength="100"></label>
                        <label><span>Sesso</span><select data-name="gender"><option value="M">Maschio</option><option value="F">Femmina</option></select></label>
                        <label><span>Data di nascita</span><input type="text" class="js-date" data-date-role="birth" data-name="birth_date" placeholder="Seleziona la data" autocomplete="off"></label>
                        <label><span>Cittadinanza</span><input list="citizenship-options" data-name="citizenship_label" placeholder="Seleziona o digita"></label>
                        <label><span>Provincia di residenza</span><input list="province-options" data-name="residence_province" placeholder="Seleziona o digita"></label>
                        <label><span>Luogo di residenza</span><input type="text" data-name="residence_place" maxlength="120"></label>
                        <label><span>Tipologia documento</span><select data-name="document_type">
                            <?php foreach ($documentTypes as $value => $label): ?><option value="<?= e($value) ?>"><?= e($label) ?></option><?php endforeach; ?>
                        </select></label>
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
                <button class="btn btn-primary" type="submit">Salva anagrafica</button>
            </div>
        </form>
    </section>
</div>

<datalist id="citizenship-options">
    <?php foreach ($cittadinanze as $citizenship): ?><option value="<?= e($citizenship) ?>"><?php endforeach; ?>
</datalist>
<datalist id="province-options">
    <?php foreach ($province as $code => $provinceName): ?><option value="<?= e($provinceName) ?>"><?php endforeach; ?>
</datalist>
<datalist id="city-options">
    <?php foreach ($citta as $city): ?><option value="<?= e($city) ?>"><?php endforeach; ?>
</datalist>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
