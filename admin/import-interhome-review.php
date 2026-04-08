<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_admin();

$importState = $_SESSION['interhome_import'] ?? null;
$rowId = trim((string) ($_GET['row'] ?? ''));
$row = null;
if ($importState && !empty($importState['rows'])) {
    foreach ($importState['rows'] as $candidate) {
        if (($candidate['import_row_id'] ?? '') === $rowId) {
            $row = $candidate;
            break;
        }
    }
}

if (!$row) {
    set_flash('error', 'Riga importata non trovata o già rimossa.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$pageTitle = 'Verifica prenotazione PDF';
require_once __DIR__ . '/includes/header.php';
$rooms = ['Casa Domenico 1','Casa Domenico 2','Casa Domenico 1-2','Casa Riccardo 3','Casa Riccardo 4','Casa Alessandro 5','Casa Alessandro 6'];
$statuses = ['confermata' => 'Confermata', 'in_attesa' => 'In attesa', 'annullata' => 'Annullata'];
?>
<div class="booking-page interhome-review-shell">
    <div class="booking-hero">
        <div class="booking-hero-copy">
            <h1>Verifica prenotazione importata</h1>
            <p class="muted">Controlla i dati letti dal PDF, correggili se serve e inserisci la prenotazione tra quelle registrate.</p>
        </div>
        <a class="btn btn-light" href="<?= e(admin_url('import-interhome-pdf.php')) ?>">Torna all’elenco</a>
    </div>

    <div class="card interhome-review-meta">
        <div><span class="summary-label">Riferimento prenotazione</span><strong><?= e($row['external_reference']) ?></strong></div>
        <div><span class="summary-label">Pagina PDF</span><strong><?= (int) ($row['_page'] ?? 0) ?></strong></div>
        <div><span class="summary-label">Origine</span><strong><?= e($row['source']) ?></strong></div>
    </div>

    <form class="booking-form" method="post" action="<?= e(admin_url('actions/create-prenotazione-from-interhome.php')) ?>" data-confirm="Confermi l’inserimento di questa prenotazione tra quelle registrate?">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="import_row_id" value="<?= e((string) $row['import_row_id']) ?>">

        <section class="form-section">
            <h2 class="form-section-title">Soggiorno</h2>
            <div class="booking-form-grid">
                <label>
                    Periodo soggiorno *
                    <input class="js-date-range" type="text" name="stay_period" value="<?= e($row['stay_period']) ?>" readonly required>
                </label>
                <label>
                    Soluzione *
                    <select name="room_type" required>
                        <option value="">Scegli soluzione</option>
                        <?php foreach ($rooms as $room): ?>
                            <option value="<?= e($room) ?>" <?= ($row['room_type'] === $room) ? 'selected' : '' ?>><?= e($room) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Adulti *
                    <input type="number" name="adults" min="0" step="1" value="<?= (int) $row['adults'] ?>" required>
                </label>
                <label>
                    Bambini
                    <input type="number" name="children_count" min="0" step="1" value="<?= (int) $row['children_count'] ?>">
                </label>
            </div>
        </section>

        <section class="form-section">
            <h2 class="form-section-title">Ospite</h2>
            <div class="booking-form-grid">
                <label>
                    Nome e cognome *
                    <input type="text" name="customer_name" value="<?= e($row['customer_name']) ?>" required>
                </label>
                <label>
                    Email *
                    <input type="email" name="customer_email" value="<?= e($row['customer_email']) ?>" required>
                </label>
                <label>
                    Telefono
                    <input type="text" name="customer_phone" value="<?= e($row['customer_phone']) ?>">
                </label>
                <label>
                    Riferimento esterno *
                    <input type="text" name="external_reference" value="<?= e($row['external_reference']) ?>" required>
                </label>
            </div>
        </section>

        <section class="form-section">
            <h2 class="form-section-title">Gestione prenotazione</h2>
            <div class="booking-form-grid">
                <label>
                    Stato *
                    <select name="status" required>
                        <?php foreach ($statuses as $key => $label): ?>
                            <option value="<?= e($key) ?>" <?= ($row['status'] === $key) ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Origine *
                    <input type="text" name="source" value="interhome_pdf" readonly>
                </label>
                <label class="full">
                    Note
                    <textarea name="notes"><?= e((string) ($row['notes'] ?? '')) ?></textarea>
                </label>
            </div>
        </section>

        <div class="form-actions">
            <form method="post" action="<?= e(admin_url('actions/remove-interhome-row.php')) ?>" data-confirm="Vuoi togliere questa prenotazione dall’elenco importato?" class="js-row-action">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="row_id" value="<?= e((string) $row['import_row_id']) ?>">
                <button class="btn btn-danger" type="submit">
                    <span class="trash-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"></path><path d="M8 6V4h8v2"></path><path d="M19 6l-1 14H6L5 6"></path><path d="M10 11v6"></path><path d="M14 11v6"></path></svg>
                    </span>
                    <span>Cancella riga</span>
                </button>
            </form>
            <a class="btn btn-light" href="<?= e(admin_url('import-interhome-pdf.php')) ?>">Torna all’elenco</a>
            <button class="btn btn-primary" type="submit">Conferma e salva</button>
        </div>
    </form>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
