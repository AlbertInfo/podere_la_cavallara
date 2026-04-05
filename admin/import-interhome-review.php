<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_admin();
$pageTitle = 'Revisione prenotazione Interhome';
require_once __DIR__ . '/includes/header.php';

$rowKey = (string)($_GET['row'] ?? '');
$rows = $_SESSION['interhome_import']['rows'] ?? [];
$row = null;
if ($rowKey !== '' && isset($rows[(int)$rowKey])) {
    $row = $rows[(int)$rowKey];
}
if (!$row) {
    set_flash('error', 'Riga non trovata nell’import corrente.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$stayPeriod = (string)($row['stay_period'] ?? (($row['check_in'] ?? '') . ' - ' . ($row['check_out'] ?? '')));
?>
<div class="booking-page review-shell">
    <div class="booking-hero">
        <div class="booking-hero-copy">
            <h1>Revisione prenotazione Interhome</h1>
            <p class="muted">Controlla i dati letti dal PDF, modifica se necessario e conferma il salvataggio in Prenotazioni registrate.</p>
        </div>
        <a class="btn btn-light" href="<?= e(admin_url('import-interhome-pdf.php')) ?>">Torna all’elenco</a>
    </div>

    <div class="review-meta">
        <div class="review-meta-card">
            <span class="meta-label">Origine</span>
            <strong>interhome_pdf</strong>
        </div>
        <div class="review-meta-card">
            <span class="meta-label">Riferimento prenotazione</span>
            <strong><?= e((string)($row['external_reference'] ?? '')) ?></strong>
        </div>
        <div class="review-meta-card">
            <span class="meta-label">Pagina PDF</span>
            <strong><?= (int)($row['_page'] ?? 0) ?></strong>
        </div>
    </div>

    <?php if (!empty($row['notes'])): ?>
        <div class="review-warning">
            <strong>Nota associata:</strong> <?= e((string)$row['notes']) ?>
        </div>
    <?php endif; ?>

    <form class="booking-form" method="post" action="<?= e(admin_url('actions/create-prenotazione-from-interhome.php')) ?>">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="row_key" value="<?= e($rowKey) ?>">

        <section class="form-section">
            <h2 class="form-section-title">Soggiorno</h2>
            <div class="booking-form-grid">
                <label>
                    Periodo soggiorno *
                    <input class="js-date-range" type="text" name="stay_period" value="<?= e($stayPeriod) ?>" required>
                </label>

                <label>
                    Soluzione *
                    <select name="room_type" required>
                        <?php $options = ['Casa Domenico 1','Casa Domenico 2','Casa Domenico 1-2','Casa Riccardo 3','Casa Riccardo 4','Casa Alessandro 5','Casa Alessandro 6']; ?>
                        <option value="">Scegli soluzione</option>
                        <?php foreach ($options as $option): ?>
                            <option value="<?= e($option) ?>" <?= (($row['room_type'] ?? '') === $option) ? 'selected' : '' ?>><?= e($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    Adulti *
                    <input type="number" name="adults" min="0" step="1" value="<?= (int)($row['adults'] ?? 0) ?>" required>
                </label>

                <label>
                    Bambini
                    <input type="number" name="children_count" min="0" step="1" value="<?= (int)($row['children_count'] ?? 0) ?>">
                </label>
            </div>
        </section>

        <section class="form-section">
            <h2 class="form-section-title">Ospite</h2>
            <div class="booking-form-grid">
                <label>
                    Nome cliente *
                    <input type="text" name="customer_name" value="<?= e((string)($row['customer_name'] ?? '')) ?>" required>
                </label>
                <label>
                    Email
                    <input type="email" name="customer_email" value="<?= e((string)($row['customer_email'] ?? '')) ?>">
                </label>
                <label>
                    Telefono
                    <input type="text" name="customer_phone" value="<?= e((string)($row['customer_phone'] ?? '')) ?>">
                </label>
            </div>
        </section>

        <section class="form-section">
            <h2 class="form-section-title">Gestione prenotazione</h2>
            <div class="booking-form-grid">
                <label>
                    Stato *
                    <select name="status" required>
                        <?php $statuses = ['confermata' => 'Confermata', 'in_attesa' => 'In attesa', 'annullata' => 'Annullata']; ?>
                        <?php foreach ($statuses as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= (($row['status'] ?? 'confermata') === $value) ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    Riferimento prenotazione *
                    <input class="readonly-field" type="text" name="external_reference" value="<?= e((string)($row['external_reference'] ?? '')) ?>" required>
                </label>

                <label class="full">
                    Note
                    <textarea name="notes"><?= e((string)($row['notes'] ?? '')) ?></textarea>
                </label>
            </div>
        </section>

        <div class="form-actions">
            <form method="post" action="<?= e(admin_url('actions/remove-interhome-row.php')) ?>">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="row_key" value="<?= e($rowKey) ?>">
                <button class="btn btn-light" type="submit">Cancella</button>
            </form>
            <a class="btn btn-light" href="<?= e(admin_url('import-interhome-pdf.php')) ?>">Annulla</a>
            <button class="btn btn-primary" type="submit">Conferma e salva</button>
        </div>
    </form>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
