<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/anagrafica-options.php';
require_admin();

$pageTitle = 'Sezione anagrafica';

$recordTableReady = false;
try {
    $recordTableReady = (bool) $pdo->query("SHOW TABLES LIKE 'anagrafica_records'")->fetchColumn();
} catch (Throwable $e) {
    $recordTableReady = false;
}

$cittadinanze = anagrafica_eu_citizenships();
$province = anagrafica_province_italiane();
$documentTypes = anagrafica_document_types();
$citta = anagrafica_citta_italiane_principali();
$channels = anagrafica_booking_channels();
$tourismTypes = anagrafica_tourism_types();
$transportTypes = anagrafica_transport_types();

require_once __DIR__ . '/includes/header.php';
?>
<div class="booking-page anagrafica-shell">
    <section class="booking-hero anagrafica-hero">
        <div class="booking-hero-copy">
            <span class="eyebrow">Sezione anagrafica</span>
            <h1>Nuova anagrafica</h1>
            <p class="muted">Crea un record singolo o gruppo/famiglia già predisposto per i futuri export ROSS1000 e Alloggiati Web.</p>
        </div>
        <div class="toolbar">
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

    <section class="card anagrafica-form-card">
        <div class="section-title">
            <div>
                <h2>Testata anagrafica</h2>
                <p class="muted">Imposta il soggiorno e i dati base della prenotazione. I componenti del gruppo si aggiungono più sotto.</p>
            </div>
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
                        <input type="text" name="arrival_date" class="js-date" placeholder="gg/mm/aaaa" required>
                    </label>
                    <label>
                        <span>Data partenza prevista</span>
                        <input type="text" name="departure_date" class="js-date" placeholder="gg/mm/aaaa" required>
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
                        <p class="muted">Questi dati costituiscono il primo componente del record. Nel caso gruppo sarà il capogruppo.</p>
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
                        <label><span>Data di nascita</span><input type="text" class="js-date" data-name="birth_date" placeholder="gg/mm/aaaa"></label>
                        <label><span>Cittadinanza</span><input list="citizenship-options" data-name="citizenship_label" placeholder="Seleziona o digita"></label>
                        <label><span>Provincia di residenza</span><input list="province-options" data-name="residence_province" placeholder="Seleziona o digita"></label>
                        <label><span>Luogo di residenza</span><input type="text" data-name="residence_place" maxlength="120"></label>
                        <label><span>Tipologia documento</span><select data-name="document_type">
                            <?php foreach ($documentTypes as $value => $label): ?><option value="<?= e($value) ?>"><?= e($label) ?></option><?php endforeach; ?>
                        </select></label>
                        <label><span>N. documento</span><input type="text" data-name="document_number" maxlength="50"></label>
                        <label><span>Data documento</span><input type="text" class="js-date" data-name="document_issue_date" placeholder="gg/mm/aaaa"></label>
                        <label><span>Scadenza documento</span><input type="text" class="js-date" data-name="document_expiry_date" placeholder="gg/mm/aaaa"></label>
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
    <?php foreach ($province as $code => $province): ?><option value="<?= e($province) ?>"><?php endforeach; ?>
</datalist>
<datalist id="city-options">
    <?php foreach ($citta as $city): ?><option value="<?= e($city) ?>"><?php endforeach; ?>
</datalist>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
