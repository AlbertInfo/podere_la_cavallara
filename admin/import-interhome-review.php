<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_admin();

$import = $_SESSION['interhome_import'] ?? null;
$rowKey = trim((string) ($_GET['row'] ?? ''));

if (!$import || $rowKey === '') {
    set_flash('error', 'Nessuna prenotazione Interhome selezionata.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$selectedRow = null;
foreach (($import['rows'] ?? []) as $row) {
    if (($row['row_key'] ?? '') === $rowKey) {
        $selectedRow = $row;
        break;
    }
}

if (!$selectedRow) {
    set_flash('error', 'La prenotazione selezionata non è più disponibile.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$pageTitle = 'Conferma prenotazione Interhome';
require_once __DIR__ . '/includes/header.php';
?>
<div class="review-shell">
    <div class="booking-hero">
        <div class="booking-hero-copy">
            <h1>Conferma prenotazione Interhome</h1>
            <p class="muted">Controlla i dati estratti dal PDF, modifica se necessario e conferma il salvataggio nella sezione Prenotazioni registrate.</p>
        </div>
        <a class="btn btn-light" href="<?= e(admin_url('import-interhome-pdf.php')) ?>">Torna all’elenco PDF</a>
    </div>

    <div class="review-meta">
        <div class="review-meta-card">
            <span class="meta-label">Riferimento prenotazione</span>
            <strong><?= e($selectedRow['external_reference']) ?></strong>
        </div>
        <div class="review-meta-card">
            <span class="meta-label">Stato nel PDF</span>
            <strong><?= e($selectedRow['status_label']) ?></strong>
        </div>
        <div class="review-meta-card">
            <span class="meta-label">Origine</span>
            <strong>Interhome PDF</strong>
        </div>
    </div>

    <?php if (trim((string) $selectedRow['customer_email']) === ''): ?>
        <div class="review-warning">Nel PDF non è stata trovata un’email cliente. Inseriscila manualmente prima di confermare la prenotazione.</div>
    <?php endif; ?>

    <form class="booking-form" method="post" action="<?= e(admin_url('actions/create-prenotazione-from-interhome.php')) ?>">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="row_key" value="<?= e($selectedRow['row_key']) ?>">

        <section class="form-section">
            <h2 class="form-section-title">Soggiorno</h2>
            <div class="booking-form-grid">
                <label>
                    Periodo soggiorno *
                    <input class="js-date-range readonly-field" type="text" name="stay_period" value="<?= e($selectedRow['stay_period']) ?>" readonly required>
                </label>

                <label>
                    Soluzione *
                    <?php $rooms = ['Casa Domenico 1','Casa Domenico 2','Casa Domenico 1-2','Casa Riccardo 3','Casa Riccardo 4','Casa Alessandro 5','Casa Alessandro 6']; ?>
                    <select name="room_type" required>
                        <option value="">Scegli soluzione</option>
                        <?php foreach ($rooms as $room): ?>
                            <option value="<?= e($room) ?>" <?= $selectedRow['room_type'] === $room ? 'selected' : '' ?>><?= e($room) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    Adulti
                    <input type="number" name="adults" min="0" step="1" value="<?= (int) $selectedRow['adults'] ?>">
                </label>

                <label>
                    Bambini
                    <input type="number" name="children_count" min="0" step="1" value="<?= (int) $selectedRow['children_count'] ?>">
                </label>
            </div>
        </section>

        <section class="form-section">
            <h2 class="form-section-title">Ospite</h2>
            <div class="booking-form-grid">
                <label>
                    Nome e cognome *
                    <input type="text" name="customer_name" value="<?= e($selectedRow['customer_name']) ?>" required>
                </label>

                <label>
                    Email *
                    <input type="email" name="customer_email" value="<?= e($selectedRow['customer_email']) ?>" required>
                </label>

                <label>
                    Telefono
                    <input type="text" name="customer_phone" value="<?= e($selectedRow['customer_phone']) ?>">
                </label>

                <label>
                    Lingua
                    <input class="readonly-field" type="text" value="<?= e($selectedRow['language']) ?>" readonly>
                </label>
            </div>
        </section>

        <section class="form-section">
            <h2 class="form-section-title">Import e note</h2>
            <div class="booking-form-grid">
                <label>
                    Origine *
                    <input class="readonly-field" type="text" name="source" value="interhome_pdf" readonly required>
                </label>

                <label>
                    Riferimento esterno *
                    <input class="readonly-field" type="text" name="external_reference" value="<?= e($selectedRow['external_reference']) ?>" readonly required>
                </label>

                <label>
                    Stato *
                    <select name="status" required>
                        <option value="confermata" selected>Confermata</option>
                        <option value="in_attesa">In attesa</option>
                        <option value="annullata">Annullata</option>
                    </select>
                </label>

                <label class="full">
                    Note
                    <textarea name="notes"><?= e($selectedRow['notes']) ?></textarea>
                </label>
            </div>

            <div class="form-hint" style="margin-top:12px;">
                Casa vacanze PDF: <strong><?= e(trim($selectedRow['house_code'] . ' ' . $selectedRow['house_descriptor'] . ' ' . $selectedRow['house_external_code'])) ?></strong>
            </div>
        </section>

        <div class="form-actions">
            <a class="btn btn-light" href="<?= e(admin_url('import-interhome-pdf.php')) ?>">Annulla</a>
            <button class="btn btn-primary" type="submit">Conferma e salva prenotazione</button>
        </div>
    </form>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
