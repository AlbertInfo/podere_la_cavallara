<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_admin();

$pageTitle = 'Importa PDF Interhome';
$importState = $_SESSION['interhome_import'] ?? null;
$showConfirmModal = !empty($importState['pending_confirmation']) && !empty($importState['rows']);

require_once __DIR__ . '/includes/header.php';
?>
<div class="booking-page interhome-shell">
    <div class="booking-hero">
        <div class="booking-hero-copy">
            <h1>Importa PDF Interhome</h1>
            <p class="muted">Carica il PDF arrivi dell’agenzia, verifica il riepilogo e poi lavora su ogni prenotazione con un flusso rapido, preciso e professionale.</p>
        </div>
        <a class="btn btn-light" href="<?= e(admin_url('index.php#registered-bookings')) ?>">Torna alle prenotazioni</a>
    </div>

    <section class="card interhome-upload-card">
        <div class="section-title">
            <div>
                <h2>Carica PDF</h2>
                <p class="muted">Il parser legge date, casa, nominativo, recapiti, riferimento prenotazione e note. Le prenotazioni già registrate non verranno mostrate.</p>
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
        <section class="interhome-summary-grid">
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
                <span class="summary-meta">Controllo su external_reference</span>
            </article>
        </section>

        <section class="card interhome-workbench">
            <div class="section-title">
                <div>
                    <h2>Area di lavoro PDF</h2>
                    <p class="muted">Visualizza il PDF direttamente dalla dashboard, rinomina il file di lavoro e confronta velocemente i dati con la tabella.</p>
                </div>
            </div>
            <div class="interhome-workbench-grid">
                <div class="interhome-pdf-panel">
                    <form method="post" action="<?= e(admin_url('actions/rename-interhome-file.php')) ?>" class="interhome-rename-form">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <label>
                            Nome sessione PDF
                            <input type="text" name="display_name" value="<?= e((string) ($importState['display_name'] ?? pathinfo((string) ($importState['file_name'] ?? 'Import PDF'), PATHINFO_FILENAME))) ?>" maxlength="120">
                        </label>
                        <button class="btn btn-light btn-sm" type="submit">Rinomina</button>
                    </form>
                    <div class="interhome-file-meta muted">
                        <span><strong>File:</strong> <?= e((string) ($importState['file_name'] ?? 'PDF caricato')) ?></span>
                        <span><strong>Sessione:</strong> <?= e((string) ($importState['display_name'] ?? 'Import PDF')) ?></span>
                        <span><strong>Caricato:</strong> <?= e((string) ($importState['uploaded_at'] ?? '')) ?></span>
                    </div>
                    <?php if (!empty($importState['pdf_url'])): ?>
                        <div class="interhome-pdf-viewer-wrap">
                            <iframe class="interhome-pdf-viewer" src="<?= e((string) $importState['pdf_url']) ?>#toolbar=1&navpanes=0"></iframe>
                        </div>
                    <?php else: ?>
                        <div class="empty-note">PDF non disponibile per l’anteprima.</div>
                    <?php endif; ?>
                </div>
                <div class="interhome-quick-help">
                    <div class="card interhome-tip-card">
                        <h3>Workflow consigliato</h3>
                        <ol>
                            <li>Controlla il totale prenotazioni trovate.</li>
                            <li>Confronta rapidamente il PDF nel viewer.</li>
                            <li>Apri una riga, verifica i dati e salva.</li>
                            <li>Se una riga non ti serve, eliminala col cestino.</li>
                        </ol>
                    </div>
                </div>
            </div>
        </section>

        <?php if (!empty($importState['rows'])): ?>
            <section class="card interhome-table-card">
                <div class="section-title">
                    <div>
                        <h2>Nuove prenotazioni trovate</h2>
                        <p class="muted">Clicca una riga per aprire il form modificabile e confermare l’inserimento in Prenotazioni registrate.</p>
                    </div>
                    <div class="toolbar">
                        <input class="search-input" type="search" placeholder="Cerca tra le righe importate..." data-table-filter="#interhome-import-table">
                    </div>
                </div>
                <div class="table-wrap">
                    <table id="interhome-import-table">
                        <thead>
                        <tr>
                            <th>Cliente</th>
                            <th>Soggiorno</th>
                            <th>Casa</th>
                            <th>Persone</th>
                            <th>Riferimento</th>
                            <th>Note</th>
                            <th>Azioni</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($importState['rows'] as $row): ?>
                            <tr class="interhome-import-row" data-row-href="<?= e(admin_url('import-interhome-review.php?row=' . urlencode((string) $row['import_row_id']))) ?>">
                                <td>
                                    <strong><?= e((string) ($row['customer_name'] ?? '')) ?></strong><br>
                                    <?php if (!empty($row['customer_email'])): ?>
                                        <a class="small contact-link" href="mailto:<?= e((string) $row['customer_email']) ?>"><?= e((string) $row['customer_email']) ?></a>
                                    <?php elseif (!empty($row['_language'])): ?>
                                        <span class="small muted"><?= e((string) $row['_language']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e((string) ($row['stay_period'] ?? '')) ?></td>
                                <td><?= e((string) ($row['room_type'] ?? '')) ?></td>
                                <td><?= (int) ($row['adults'] ?? 0) ?> adulti / <?= (int) ($row['children_count'] ?? 0) ?> bambini</td>
                                <td><span class="code"><?= e((string) ($row['external_reference'] ?? '')) ?></span></td>
                                <td><?= !empty($row['notes']) ? e((string) $row['notes']) : '<span class="small muted">Nessuna</span>' ?></td>
                                <td>
                                    <div class="actions interhome-inline-actions">
                                        <a class="btn btn-primary btn-sm" href="<?= e(admin_url('import-interhome-review.php?row=' . urlencode((string) $row['import_row_id']))) ?>">Apri</a>
                                        <form method="post" action="<?= e(admin_url('actions/remove-interhome-row.php')) ?>" class="js-row-action" data-confirm="Vuoi togliere questa riga dall’elenco importato?">
                                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="row_id" value="<?= e((string) $row['import_row_id']) ?>">
                                            <button class="btn btn-danger btn-sm" type="submit" aria-label="Togli riga">
                                                <span class="trash-icon" aria-hidden="true">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"></path><path d="M8 6V4h8v2"></path><path d="M19 6l-1 14H6L5 6"></path><path d="M10 11v6"></path><path d="M14 11v6"></path></svg>
                                                </span>
                                                <span>Elimina</span>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php else: ?>
            <section class="card empty-note interhome-empty-state">
                <h2>Nessuna nuova prenotazione</h2>
                <p class="muted">Non ci sono nuove prenotazioni nel file PDF. Tutti i riferimenti trovati risultano già presenti oppure il file non contiene nuove righe importabili.</p>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($showConfirmModal): ?>
<div class="interhome-modal-backdrop" data-interhome-modal>
    <div class="interhome-modal card" role="dialog" aria-modal="true" aria-labelledby="interhomeModalTitle">
        <h2 id="interhomeModalTitle">Analisi completata</h2>
        <p>Il parser ha trovato <strong><?= (int) ($importState['summary']['parsed_total'] ?? 0) ?></strong> prenotazioni nel PDF.</p>
        <p class="muted">Il numero ti sembra corretto? Se confermi, mostriamo la tabella completa per la revisione.</p>
        <div class="form-actions">
            <form method="post" action="<?= e(admin_url('actions/remove-interhome-row.php')) ?>">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="dismiss_modal" value="1">
                <button class="btn btn-light" type="submit">Chiudi e rivedi dopo</button>
            </form>
            <button class="btn btn-primary" type="button" data-interhome-modal-confirm>Il numero è corretto</button>
        </div>
    </div>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
