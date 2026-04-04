<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

require_admin();

$pageTitle = 'Rivedi prenotazione Interhome';
$rows = $_SESSION['interhome_import']['rows'] ?? [];
$id = (int) ($_GET['id'] ?? -1);
if (!isset($rows[$id]) || !is_array($rows[$id])) {
    set_flash('error', 'Prenotazione Interhome non trovata.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$row = $rows[$id];
require __DIR__ . '/includes/header.php';
?>
<div class="booking-page review-shell">
    <div class="booking-hero">
        <div class="booking-hero-copy">
            <h1>Rivedi prenotazione Interhome</h1>
            <p class="muted">Controlla i dati estratti dal PDF, modificali se necessario e conferma il salvataggio. Il campo soggiorno usa il datepicker già presente nel progetto.</p>
        </div>
        <div class="toolbar">
            <a class="btn btn-light" href="<?= e(admin_url('import-interhome-pdf.php')) ?>">Torna all’elenco</a>
        </div>
    </div>

    <div class="review-meta">
        <article class="review-meta-card">
            <span class="meta-label">Riferimento esterno</span>
            <strong><?= e((string) ($row['external_reference'] ?? '')) ?></strong>
        </article>
        <article class="review-meta-card">
            <span class="meta-label">Lingua</span>
            <strong><?= e((string) ($row['_language'] ?? '-')) ?></strong>
        </article>
        <article class="review-meta-card">
            <span class="meta-label">Origine</span>
            <strong>interhome_pdf</strong>
        </article>
    </div>

    <?php if (!empty($row['notes'])): ?>
        <div class="review-warning">È stata trovata una nota associata a questa prenotazione. Verrà precompilata nel campo note.</div>
    <?php endif; ?>

    <form class="booking-form" method="post" action="<?= e(admin_url('actions/create-prenotazione-from-interhome.php')) ?>">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="import_row_id" value="<?= $id ?>">

        <section class="form-section">
            <h2 class="form-section-title">Soggiorno</h2>
            <div class="booking-form-grid">
                <div class="full">
                    <label for="stay_period">Periodo</label>
                    <input id="stay_period" class="js-date-range" name="stay_period" type="text" value="<?= e((string) ($row['stay_period'] ?? '')) ?>" required>
                </div>
                <div>
                    <label for="room_type">Camera</label>
                    <select id="room_type" name="room_type" required>
                        <?php $rooms = ['Casa Domenico 1', 'Casa Domenico 2', 'Casa Domenico 1-2', 'Casa Riccardo 3', 'Casa Riccardo 4', 'Casa Alessandro 5', 'Casa Alessandro 6']; ?>
                        <?php foreach ($rooms as $room): ?>
                            <option value="<?= e($room) ?>" <?= (($row['room_type'] ?? '') === $room) ? 'selected' : '' ?>><?= e($room) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="status">Stato</label>
                    <select id="status" name="status">
                        <option value="confermata" selected>confermata</option>
                        <option value="in_attesa">in_attesa</option>
                    </select>
                </div>
            </div>
        </section>

        <section class="form-section">
            <h2 class="form-section-title">Ospite</h2>
            <div class="booking-form-grid">
                <div>
                    <label for="customer_name">Nome cliente</label>
                    <input id="customer_name" name="customer_name" type="text" value="<?= e((string) ($row['customer_name'] ?? '')) ?>" required>
                </div>
                <div>
                    <label for="customer_email">Email</label>
                    <input id="customer_email" name="customer_email" type="email" value="<?= e((string) ($row['customer_email'] ?? '')) ?>">
                </div>
                <div>
                    <label for="customer_phone">Telefono</label>
                    <input id="customer_phone" name="customer_phone" type="text" value="<?= e((string) ($row['customer_phone'] ?? '')) ?>">
                </div>
                <div>
                    <label for="external_reference">Riferimento prenotazione</label>
                    <input id="external_reference" class="readonly-field" name="external_reference" type="text" value="<?= e((string) ($row['external_reference'] ?? '')) ?>" readonly>
                </div>
                <div>
                    <label for="adults">Adulti</label>
                    <input id="adults" name="adults" type="number" min="0" value="<?= (int) ($row['adults'] ?? 0) ?>">
                </div>
                <div>
                    <label for="children_count">Bambini</label>
                    <input id="children_count" name="children_count" type="number" min="0" value="<?= (int) ($row['children_count'] ?? 0) ?>">
                </div>
            </div>
        </section>

        <section class="form-section">
            <h2 class="form-section-title">Gestione</h2>
            <div class="booking-form-grid">
                <div>
                    <label for="source">Origine</label>
                    <input id="source" class="readonly-field" name="source" type="text" value="interhome_pdf" readonly>
                </div>
                <div>
                    <label for="raw_property">Casa Interhome originale</label>
                    <input id="raw_property" class="readonly-field" type="text" value="<?= e((string) ($row['_raw_property'] ?? '')) ?>" readonly>
                </div>
                <div class="full">
                    <label for="notes">Note</label>
                    <textarea id="notes" name="notes"><?= e((string) ($row['notes'] ?? '')) ?></textarea>
                </div>
            </div>
            <div class="form-actions">
                <a class="btn btn-light" href="<?= e(admin_url('import-interhome-pdf.php')) ?>">Annulla</a>
                <button class="btn btn-primary" type="submit">Conferma e salva prenotazione</button>
            </div>
        </section>
    </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
