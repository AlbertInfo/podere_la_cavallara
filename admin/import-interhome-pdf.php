<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_admin();
$pageTitle = 'Importa PDF Interhome';
require_once __DIR__ . '/includes/header.php';

$data = $_SESSION['interhome_import'] ?? null;
$rows = $data['rows'] ?? [];
$summary = $data['summary'] ?? ['pages' => 0, 'found_total' => 0, 'cancelled_skipped' => 0, 'duplicates_skipped' => 0, 'missing_reference_skipped' => 0];
?>
<div class="booking-page import-shell">
    <div class="booking-hero">
        <div class="booking-hero-copy">
            <h1>Importa PDF Interhome</h1>
            <p class="muted">Carica il PDF degli arrivi. Il sistema mostrerà solo le prenotazioni nuove, con riferimento prenotazione valido, escludendo quelle marcate come “cancellata”.</p>
        </div>
        <a class="btn btn-primary" href="<?= e(admin_url('new-prenotazione.php')) ?>">Nuova prenotazione</a>
    </div>

    <div class="card upload-card">
        <form class="upload-grid" method="post" action="<?= e(admin_url('actions/parse-interhome-pdf.php')) ?>" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <label class="full">
                PDF Interhome *
                <input type="file" name="pdf_file" accept="application/pdf" required>
            </label>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Analizza PDF</button>
            </div>
        </form>
    </div>

    <?php if ($data): ?>
        <div class="import-summary-grid">
            <div class="summary-card summary-blue">
                <span class="summary-label">Pagine lette</span>
                <strong><?= (int)($summary['pages'] ?? 0) ?></strong>
                <span class="summary-meta">Documento: <?= e((string)($data['document_name'] ?? '')) ?></span>
            </div>
            <div class="summary-card summary-green">
                <span class="summary-label">Nuove prenotazioni</span>
                <strong><?= (int)($summary['found_total'] ?? 0) ?></strong>
                <span class="summary-meta">Pronte per la revisione</span>
            </div>
            <div class="summary-card summary-red">
                <span class="summary-label">Cancellate escluse</span>
                <strong><?= (int)($summary['cancelled_skipped'] ?? 0) ?></strong>
                <span class="summary-meta">Marker testuale “cancellata”</span>
            </div>
            <div class="summary-card summary-amber">
                <span class="summary-label">Duplicati esclusi</span>
                <strong><?= (int)($summary['duplicates_skipped'] ?? 0) ?></strong>
                <span class="summary-meta">+ senza riferimento: <?= (int)($summary['missing_reference_skipped'] ?? 0) ?></span>
            </div>
        </div>

        <div class="card import-table-card">
            <div class="section-title">
                <div>
                    <h2>Nuove prenotazioni trovate</h2>
                    <p class="muted">Clicca una riga per aprire il form modificabile e confermare l’inserimento in Prenotazioni registrate.</p>
                </div>
            </div>

            <?php if (!$rows): ?>
                <div class="empty-note">Nessuna prenotazione nuova disponibile dopo il filtro su cancellate, duplicati e riferimenti mancanti.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="import-table">
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th>Soggiorno</th>
                                <th>Casa</th>
                                <th>Persone</th>
                                <th>Riferimento</th>
                                <th>Note</th>
                                <th>Azione</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $idx => $row): ?>
                                <tr class="import-row">
                                    <td>
                                        <strong><?= e((string)($row['customer_name'] ?? '')) ?></strong><br>
                                        <span class="small muted"><?= e((string)($row['customer_email'] ?? '')) ?></span>
                                    </td>
                                    <td><?= e((string)($row['stay_period'] ?? '')) ?></td>
                                    <td><?= e((string)($row['room_type'] ?? '')) ?></td>
                                    <td><?= (int)($row['adults'] ?? 0) ?> adulti / <?= (int)($row['children_count'] ?? 0) ?> bambini</td>
                                    <td><span class="code"><?= e((string)($row['external_reference'] ?? '')) ?></span></td>
                                    <td><?= !empty($row['notes']) ? '<span class="import-note-badge">Presente</span>' : 'Nessuna' ?></td>
                                    <td>
                                        <div class="actions">
                                            <a class="btn btn-primary btn-sm" href="<?= e(admin_url('import-interhome-review.php?row=' . $idx)) ?>">Apri</a>
                                            <form method="post" action="<?= e(admin_url('actions/remove-interhome-row.php')) ?>">
                                                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="row_key" value="<?= e((string)$idx) ?>">
                                                <button class="btn btn-light btn-sm" type="submit">Cancella</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
