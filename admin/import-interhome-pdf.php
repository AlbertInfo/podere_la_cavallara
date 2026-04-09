<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_admin();

$pageTitle = 'Importa PDF Interhome';
$importState = $_SESSION['interhome_import'] ?? null;

require_once __DIR__ . '/includes/header.php';
?>
<div class="booking-page interhome-shell">
    <div class="booking-hero">
        <div class="booking-hero-copy">
            <h1>Importa PDF Interhome</h1>
            <p class="muted">Carica il PDF arrivi dell’agenzia, verifica il riepilogo e poi lavora su ogni prenotazione con un flusso rapido e pulito.</p>
        </div>
        <a class="btn btn-light" href="<?= e(admin_url('index.php#registered-bookings')) ?>">Torna alle prenotazioni</a>
    </div>

    <section class="card interhome-upload-card">
        <div class="section-title">
            <div>
                <h2>Carica PDF</h2>
                <p class="muted">Il parser leggerà date, casa, cliente, recapiti, riferimento prenotazione e note collegate. Le prenotazioni già registrate non verranno mostrate.</p>
            </div>
        </div>

        <form class="interhome-upload-form" method="post" action="<?= e(admin_url('actions/parse-interhome-pdf.php')) ?>" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">

            <label class="interhome-file-drop" for="interhomePdfInput">
                <span class="interhome-file-title">Seleziona il PDF arrivi</span>
                <span class="interhome-file-subtitle">Formato .pdf, preferibilmente il documento originale Interhome.</span>
                <input id="interhomePdfInput" type="file" name="interhome_pdf" accept="application/pdf" required>
            </label>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Analizza PDF</button>
            </div>
        </form>
    </section>

    <?php if ($importState): ?>
        <div class="interhome-toolbar" style="margin-top:16px; display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
            <?php if (!empty($importState['pdf_url'])): ?>
                <button type="button" class="btn btn-light" data-pdf-toggle aria-expanded="true">Chiudi PDF</button>
            <?php endif; ?>

            <form method="post" action="<?= e(admin_url('actions/remove-interhome-row.php')) ?>" onsubmit="return confirm('Vuoi davvero svuotare l’elenco importato?');">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="clear_all" value="1">
                <button class="btn btn-light btn-sm" type="submit">Svuota elenco</button>
            </form>
        </div>

        <section class="interhome-summary-grid" style="margin-top:16px;">
            <article class="card interhome-summary-card is-blue">
                <span class="summary-label">Pagine lette</span>
                <strong><?= (int) ($importState['summary']['pages_read'] ?? 0) ?></strong>
                <span class="summary-meta">Documento: <?= e($importState['file_name'] ?? 'PDF caricato') ?></span>
            </article>

            <article class="card interhome-summary-card is-amber">
                <span class="summary-label">Prenotazioni trovate</span>
                <strong><?= (int) ($importState['summary']['parsed_total'] ?? 0) ?></strong>
                <span class="summary-meta">Tutte le righe rilevate dal parser</span>
            </article>

            <article class="card interhome-summary-card is-green">
                <span class="summary-label">Nuove prenotazioni</span>
                <strong><?= (int) ($importState['summary']['new_total'] ?? 0) ?></strong>
                <span class="summary-meta">Pronte per la revisione</span>
            </article>

            <article class="card interhome-summary-card is-purple">
                <span class="summary-label">Duplicati esclusi</span>
                <strong><?= (int) ($importState['summary']['duplicates_skipped'] ?? 0) ?></strong>
                <span class="summary-meta">Già presenti nelle prenotazioni registrate</span>
            </article>
        </section>

        <?php if (!empty($importState['rows'])): ?>
            <section class="card interhome-table-card" style="margin-top:20px;">
                <div class="section-title">
                    <div>
                        <h2>Nuove prenotazioni trovate</h2>
                        <p class="muted">Clicca una riga per aprire la scheda di verifica. Puoi anche eliminare subito le righe che non vuoi lavorare.</p>
                    </div>
                    <div class="toolbar">
                        <input class="search-input" type="search" placeholder="Cerca prenotazioni nel PDF..." data-table-filter="#interhome-import-table">
                    </div>
                </div>

                <div class="table-wrap">
                    <table id="interhome-import-table" class="interhome-import-table">
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th>Soggiorno</th>
                                <th>Casa</th>
                                <th>Persone</th>
                                <th>Riferimento</th>
                                <th>Contatti</th>
                                <th>Note</th>
                                <th>Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach (($importState['rows'] ?? []) as $row): ?>
                            <tr class="interhome-import-row" data-row-href="<?= e(admin_url('import-interhome-review.php?row=' . urlencode((string) $row['import_row_id']))) ?>" style="cursor:pointer;">
                                <td>
                                    <strong><?= e($row['customer_name']) ?></strong><br>
                                    <span class="small muted"><?= e($row['_language'] ?? '-') ?></span>
                                </td>
                                <td><?= e($row['stay_period']) ?></td>
                                <td>
                                    <strong><?= e($row['room_type']) ?></strong><br>
                                    <span class="small muted"><?= e($row['_raw_property'] ?? '') ?></span>
                                </td>
                                <td><?= (int) ($row['adults'] ?? 0) ?> adulti / <?= (int) ($row['children_count'] ?? 0) ?> bambini</td>
                                <td><?= e($row['external_reference']) ?></td>
                                <td>
                                    <?php if (!empty($row['customer_email'])): ?>
                                        <div><?= e($row['customer_email']) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($row['customer_phone'])): ?>
                                        <div class="small muted"><?= e($row['customer_phone']) ?></div>
                                    <?php endif; ?>
                                    <?php if (empty($row['customer_email']) && empty($row['customer_phone'])): ?>
                                        <span class="small muted">Non presenti</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($row['notes'])): ?>
                                        <span><?= e(mb_strimwidth((string) $row['notes'], 0, 80, '…')) ?></span>
                                    <?php else: ?>
                                        <span class="small muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="actions" onclick="event.stopPropagation();">
                                        <a class="btn btn-light btn-sm" href="<?= e(admin_url('import-interhome-review.php?row=' . urlencode((string) $row['import_row_id']))) ?>">Apri</a>

                                        <form method="post" action="<?= e(admin_url('actions/remove-interhome-row.php')) ?>" onsubmit="return confirm('Vuoi togliere questa prenotazione dall’elenco importato?');">
                                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="row_id" value="<?= e((string) $row['import_row_id']) ?>">
                                            <button class="btn btn-danger btn-sm" type="submit">Rimuovi</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <?php if (!empty($importState['pdf_url'])): ?>
            <section class="card interhome-pdf-card" data-pdf-workspace style="margin-top:20px;">
                <div class="section-title">
                    <div>
                        <h2>PDF di lavoro</h2>
                        <p class="muted">Usa il viewer per confrontare rapidamente i dati letti dal parser con il documento originale.</p>
                    </div>
                </div>

                <div class="interhome-pdf-panel" data-pdf-panel>
                    <iframe
                        src="<?= e($importState['pdf_url']) ?>"
                        title="PDF Interhome"
                        style="width:100%; height:900px; border:0; border-radius:12px; background:#fff;">
                    </iframe>
                </div>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const toggleBtn = document.querySelector('[data-pdf-toggle]');
    const pdfPanel = document.querySelector('[data-pdf-panel]');

    if (toggleBtn && pdfPanel) {
        toggleBtn.addEventListener('click', function () {
            const isHidden = pdfPanel.style.display === 'none';
            pdfPanel.style.display = isHidden ? 'block' : 'none';
            toggleBtn.textContent = isHidden ? 'Chiudi PDF' : 'Apri PDF';
            toggleBtn.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
        });
    }

    document.querySelectorAll('.interhome-import-row[data-row-href]').forEach(function (row) {
        row.addEventListener('click', function () {
            const href = row.getAttribute('data-row-href');
            if (href) {
                window.location.href = href;
            }
        });
    });
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>