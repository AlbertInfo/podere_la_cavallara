<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_admin();

$rows = $_SESSION['interhome_import_rows'] ?? [];
$key = (string) ($_GET['row'] ?? '');
if ($key === '' || !isset($rows[$key])) {
    set_flash('error', 'Prenotazione Interhome non trovata o sessione scaduta.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$row = $rows[$key];
$pageTitle = 'Conferma prenotazione Interhome';
require_once __DIR__ . '/includes/header.php';
?>
<div class="booking-page">
    <div class="booking-hero">
        <div class="booking-hero-copy">
            <h1>Conferma prenotazione Interhome</h1>
            <p class="muted">Controlla i dati estratti dal PDF, correggi se necessario e salva la prenotazione nel gestionale.</p>
        </div>
        <a class="btn btn-light" href="<?= e(admin_url('import-interhome-pdf.php')) ?>">Torna all’elenco import</a>
    </div>

    <form class="booking-form" method="post" action="<?= e(admin_url('actions/create-prenotazione-from-interhome.php')) ?>">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="import_key" value="<?= e($key) ?>">

        <section class="form-section">
            <h2 class="form-section-title">Soggiorno</h2>
            <div class="booking-form-grid">
                <label>
                    Periodo soggiorno *
                    <input class="js-date-range" type="text" name="stay_period" value="<?= e($row['stay_period']) ?>" required>
                </label>

                <label>
                    Soluzione *
                    <?php $rooms = ['Casa Domenico 1','Casa Domenico 2','Casa Domenico 1-2','Casa Riccardo 3','Casa Riccardo 4','Casa Alessandro 5','Casa Alessandro 6']; ?>
                    <select name="room_type" required>
                        <option value="">Scegli soluzione</option>
                        <?php foreach ($rooms as $room): ?>
                            <option value="<?= e($room) ?>" <?= ($row['room_type'] ?? '') === $room ? 'selected' : '' ?>><?= e($room) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    Adulti *
                    <input type="number" name="adults" min="0" step="1" value="<?= (int) ($row['adults'] ?? 0) ?>" required>
                </label>

                <label>
                    Bambini
                    <input type="number" name="children_count" min="0" step="1" value="<?= (int) ($row['children_count'] ?? 0) ?>">
                </label>
            </div>
        </section>

        <section class="form-section">
            <h2 class="form-section-title">Ospite</h2>
            <div class="booking-form-grid">
                <label>
                    Nome e cognome *
                    <input type="text" name="customer_name" value="<?= e($row['customer_name'] ?? '') ?>" required>
                </label>

                <label>
                    Email
                    <input type="email" name="customer_email" value="<?= e($row['customer_email'] ?? '') ?>">
                </label>

                <label>
                    Telefono
                    <input type="text" name="customer_phone" value="<?= e($row['customer_phone'] ?? '') ?>">
                </label>
            </div>
        </section>

        <section class="form-section">
            <h2 class="form-section-title">Gestione prenotazione</h2>
            <div class="booking-form-grid">
                <label>
                    Stato *
                    <select name="status" required>
                        <option value="confermata" <?= ($row['status'] ?? '') === 'confermata' ? 'selected' : '' ?>>Confermata</option>
                        <option value="in_attesa">In attesa</option>
                        <option value="annullata">Annullata</option>
                    </select>
                </label>

                <label>
                    Origine *
                    <input type="text" name="source" value="<?= e($row['source'] ?? 'interhome_pdf') ?>" required>
                </label>

                <label>
                    Riferimento esterno
                    <input type="text" name="external_reference" value="<?= e($row['external_reference'] ?? '') ?>">
                </label>

                <label class="full">
                    Note
                    <textarea name="notes"><?= e($row['notes'] ?? '') ?></textarea>
                </label>
            </div>

            <div class="interhome-meta-strip">
                <span class="badge <?= ($row['status_icon'] ?? '') === 'new' ? 'success' : '' ?>">
                    <?= ($row['status_icon'] ?? '') === 'new' ? 'Icona PDF: verde' : 'Icona PDF: grigia' ?>
                </span>
                <?php if (!empty($row['_raw_property'])): ?>
                    <span class="badge">Origine PDF: <?= e($row['_raw_property']) ?></span>
                <?php endif; ?>
                <?php if (!empty($row['_language'])): ?>
                    <span class="badge">Lingua: <?= e($row['_language']) ?></span>
                <?php endif; ?>
            </div>
        </section>

        <div class="form-actions">
            <a class="btn btn-light" href="<?= e(admin_url('import-interhome-pdf.php')) ?>">Annulla</a>
            <button class="btn btn-primary" type="submit">Conferma e salva prenotazione</button>
        </div>
    </form>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
