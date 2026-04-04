<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

require_admin();

$pageTitle = 'Importa PDF Interhome';
$currentAdmin = current_admin();
$import = $_SESSION['interhome_import'] ?? null;
$rows = is_array($import['rows'] ?? null) ? $import['rows'] : [];
$summary = is_array($import['summary'] ?? null) ? $import['summary'] : ['pages' => 0, 'found_total' => 0, 'duplicates_skipped' => 0, 'cancelled_skipped' => 0];

require __DIR__ . '/includes/header.php';
?>
<div class="booking-page import-shell">
    <section class="card upload-card">
        <div class="section-title">
            <div>
                <h2>Importa PDF Interhome</h2>
                <p class="muted">Carica il PDF arrivi: il sistema legge il testo, esclude le prenotazioni segnate come <strong>cancellata</strong>, filtra i duplicati già presenti e ti mostra solo le nuove righe da confermare.</p>
            </div>
            <div class="toolbar">
                <a class="btn btn-light" href="<?= e(admin_url('new-prenotazione.php')) ?>">Nuova prenotazione manuale</a>
            </div>
        </div>

        <form class="upload-grid" method="post" action="<?= e(admin_url('actions/parse-interhome-pdf.php')) ?>" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <div class="full">
                <label for="interhome_pdf">PDF Interhome</label>
                <input id="interhome_pdf" type="file" name="interhome_pdf" accept="application/pdf" required>
                <div class="form-hint">Usa il PDF modificato che contiene la scritta <strong>cancellata</strong> sulle righe annullate.</div>
            </div>
            <div class="full">
                <button class="btn btn-primary" type="submit">Analizza PDF</button>
            </div>
        </form>
    </section>

    <?php if ($import): ?>
        <section class="import-summary-grid">
            <article class="summary-card summary-blue">
                <span class="summary-label">Pagine lette</span>
                <strong><?= (int) ($summary['pages'] ?? 0) ?></strong>
                <span class="summary-meta">Documento: <?= e((string) ($import['original_name'] ?? '')) ?></span>
            </article>
            <article class="summary-card summary-green">
                <span class="summary-label">Nuove prenotazioni</span>
                <strong><?= count($rows) ?></strong>
                <span class="summary-meta">Pronte per la revisione</span>
            </article>
            <article class="summary-card summary-red">
                <span class="summary-label">Cancellate escluse</span>
                <strong><?= (int) ($summary['cancelled_skipped'] ?? 0) ?></strong>
                <span class="summary-meta">Marker testuale “cancellata”</span>
            </article>
            <article class="summary-card summary-amber">
                <span class="summary-label">Duplicati esclusi</span>
                <strong><?= (int) ($summary['duplicates_skipped'] ?? 0) ?></strong>
                <span class="summary-meta">Controllo su external_reference</span>
            </article>
        </section>

        <section class="card import-table-card">
            <div class="section-title">
                <div>
                    <h2>Nuove prenotazioni trovate</h2>
                    <p class="muted">Clicca una riga per aprire il form modificabile e confermare l’inserimento in Prenotazioni registrate.</p>
                </div>
            </div>

            <?php if (!$rows): ?>
                <div class="empty-note">Nessuna nuova prenotazione disponibile da importare.</div>
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
                        <?php foreach ($rows as $index => $row): ?>
                            <tr class="import-row" data-href="<?= e(admin_url('import-interhome-review.php?id=' . $index)) ?>">
                                <td>
                                    <strong><?= e((string) ($row['customer_name'] ?? '')) ?></strong>
                                    <?php if (!empty($row['customer_email'])): ?>
                                        <div class="small muted"><?= e((string) $row['customer_email']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= e((string) ($row['stay_period'] ?? '')) ?></td>
                                <td><?= e((string) ($row['room_type'] ?? '')) ?></td>
                                <td><?= (int) ($row['adults'] ?? 0) ?> adulti / <?= (int) ($row['children_count'] ?? 0) ?> bambini</td>
                                <td><span class="code"><?= e((string) ($row['external_reference'] ?? '')) ?></span></td>
                                <td>
                                    <?php if (!empty($row['notes'])): ?>
                                        <span class="import-note-badge">Nota presente</span>
                                    <?php else: ?>
                                        <span class="muted small">Nessuna</span>
                                    <?php endif; ?>
                                </td>
                                <td><a class="btn btn-primary btn-sm" href="<?= e(admin_url('import-interhome-review.php?id=' . $index)) ?>">Apri</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
