<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

require_admin();

$ref = trim((string)($_GET['ref'] ?? ''));
$rows = $_SESSION['interhome_import']['rows'] ?? [];
$current = null;

if (is_array($rows)) {
    foreach ($rows as $row) {
        if (trim((string)($row['external_reference'] ?? '')) === $ref) {
            $current = $row;
            break;
        }
    }
}

if (!$current) {
    set_flash('error', 'Prenotazione PDF non trovata o già rimossa.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$stayPeriodValue = trim((string)($current['stay_period'] ?? ''));
if ($stayPeriodValue === '' && !empty($current['check_in']) && !empty($current['check_out'])) {
    $stayPeriodValue = trim((string)$current['check_in']) . ' - ' . trim((string)$current['check_out']);
}

$pageTitle = 'Conferma prenotazione PDF';
require_once __DIR__ . '/includes/header.php';
?>
<div class="review-shell">
    <div class="booking-hero">
        <div class="booking-hero-copy">
            <h1>Conferma prenotazione da PDF</h1>
            <p class="muted">Controlla i dati estratti dal PDF, correggili se necessario e salva in “Prenotazioni registrate”.</p>
        </div>
        <div class="actions">
            <a class="btn btn-light" href="<?= e(admin_url('import-interhome-pdf.php')) ?>">Torna all’elenco</a>
            <form method="post" action="<?= e(admin_url('actions/remove-interhome-row.php')) ?>" data-confirm="Vuoi rimuovere questa riga dall’elenco del PDF?">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="external_reference" value="<?= e((string)($current['external_reference'] ?? '')) ?>">
                <button type="submit" class="btn btn-danger"><span aria-hidden="true"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg></span> Cancella</button>
            </form>
        </div>
    </div>

    <div class="review-meta">
        <div class="review-meta-card"><span class="meta-label">Riferimento prenotazione</span><strong><?= e((string)($current['external_reference'] ?? '')) ?></strong></div>
        <div class="review-meta-card"><span class="meta-label">Casa letta</span><strong><?= e((string)($current['room_type'] ?? '')) ?></strong></div>
        <div class="review-meta-card"><span class="meta-label">Periodo letto</span><strong><?= e($stayPeriodValue) ?></strong></div>
    </div>

    <form class="booking-form" method="post" action="<?= e(admin_url('actions/create-prenotazione-from-interhome.php')) ?>">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">

        <section class="form-section">
            <h2 class="form-section-title">Soggiorno</h2>
            <div class="booking-form-grid">
                <label>Periodo soggiorno *
                    <input class="js-date-range" type="text" name="stay_period" value="<?= e($stayPeriodValue) ?>" required>
                </label>

                <label>Soluzione *
                    <select name="room_type" required>
                        <?php $rooms = ['Casa Domenico 1','Casa Domenico 2','Casa Domenico 1-2','Casa Riccardo 3','Casa Riccardo 4','Casa Alessandro 5','Casa Alessandro 6']; ?>
                        <option value="">Scegli soluzione</option>
                        <?php foreach ($rooms as $room): ?>
                            <option value="<?= e($room) ?>" <?= (($current['room_type'] ?? '') === $room) ? 'selected' : '' ?>><?= e($room) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>Adulti *
                    <input type="number" name="adults" min="0" step="1" value="<?= (int)($current['adults'] ?? 0) ?>" required>
                </label>

                <label>Bambini
                    <input type="number" name="children_count" min="0" step="1" value="<?= (int)($current['children_count'] ?? 0) ?>">
                </label>
            </div>
        </section>

        <section class="form-section">
            <h2 class="form-section-title">Ospite</h2>
            <div class="booking-form-grid">
                <label>Nome e cognome *
                    <input type="text" name="customer_name" value="<?= e((string)($current['customer_name'] ?? '')) ?>" required>
                </label>

                <label>Email
                    <input type="email" name="customer_email" value="<?= e((string)($current['customer_email'] ?? '')) ?>">
                </label>

                <label>Telefono
                    <input type="text" name="customer_phone" value="<?= e((string)($current['customer_phone'] ?? '')) ?>">
                </label>

                <label>Riferimento prenotazione *
                    <input class="readonly-field" type="text" name="external_reference" value="<?= e((string)($current['external_reference'] ?? '')) ?>" readonly required>
                </label>
            </div>
        </section>

        <section class="form-section">
            <h2 class="form-section-title">Gestione prenotazione</h2>
            <div class="booking-form-grid">
                <label>Stato *
                    <select name="status" required>
                        <?php $statuses = ['confermata' => 'Confermata', 'in_attesa' => 'In attesa', 'annullata' => 'Annullata']; ?>
                        <?php foreach ($statuses as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= (($current['status'] ?? 'confermata') === $value) ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="full">Note
                    <textarea name="notes"><?= e((string)($current['notes'] ?? '')) ?></textarea>
                </label>
            </div>
        </section>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Conferma e salva prenotazione</button>
        </div>
    </form>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
