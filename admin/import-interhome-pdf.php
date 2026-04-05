<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

require_admin();

$pageTitle = 'Importa PDF Interhome';
$import = $_SESSION['interhome_import'] ?? null;
$rows = is_array($import['rows'] ?? null) ? $import['rows'] : [];
$summary = is_array($import['summary'] ?? null) ? $import['summary'] : [];
$filename = (string)($import['filename'] ?? '');

require_once __DIR__ . '/includes/header.php';
?>
<div class="import-shell">
    <div class="card upload-card">
        <div class="section-title">
            <div>
                <h2>Importa PDF Interhome</h2>
                <p class="muted">Carica un PDF Interhome. Il sistema legge in modo accurato periodo, casa, nominativo, contatti e riferimento prenotazione, escludendo automaticamente le prenotazioni già registrate.</p>
            </div>
        </div>

        <form class="upload-grid" action="<?= e(admin_url('actions/parse-interhome-pdf.php')) ?>" method="post" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <div class="full">
                <label>File PDF Interhome
                    <input type="file" name="pdf_file" accept="application/pdf" required>
                </label>
            </div>
            <div class="full">
                <button type="submit" class="btn btn-primary">Analizza PDF</button>
            </div>
        </form>
    </div>

    <?php if ($import): ?>
        <div class="import-summary-grid">
            <article class="summary-card summary-blue">
                <span class="summary-label">File analizzato</span>
                <strong><?= e($filename) ?></strong>
                <span class="summary-meta"><?= e((string)($import['generated_at'] ?? '')) ?></span>
            </article>
            <article class="summary-card summary-green">
                <span class="summary-label">Nuove prenotazioni</span>
                <strong><?= (int)($summary['found_total'] ?? 0) ?></strong>
                <span class="summary-meta">Pronte da confermare</span>
            </article>
            <article class="summary-card summary-amber">
                <span class="summary-label">Già registrate</span>
                <strong><?= (int)($summary['duplicates_skipped'] ?? 0) ?></strong>
                <span class="summary-meta">Escluse automaticamente</span>
            </article>
            <article class="summary-card summary-red">
                <span class="summary-label">Pagine lette</span>
                <strong><?= (int)($summary['pages'] ?? 0) ?></strong>
                <span class="summary-meta">Parsing completato</span>
            </article>
        </div>

        <div class="card import-table-card">
            <div class="section-title">
                <div>
                    <h2>Nuove prenotazioni trovate</h2>
                    <p class="muted">Sono mostrate solo le prenotazioni con riferimento univoco non già presenti in “Prenotazioni registrate”.</p>
                </div>
            </div>

            <?php if (!$rows): ?>
                <div class="empty-note">Non ci sono nuove prenotazioni nel file PDF.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="import-table">
                        <thead>
                            <tr>
                                <th>Periodo</th>
                                <th>Casa</th>
                                <th>Cliente</th>
                                <th>Persone</th>
                                <th>Telefono</th>
                                <th>Email</th>
                                <th>Riferimento</th>
                                <th>Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <tr class="import-row">
                                    <td><?= e($row['stay_period'] ?? '') ?></td>
                                    <td><?= e($row['room_type'] ?? '') ?></td>
                                    <td>
                                        <strong><?= e($row['customer_name'] ?? '') ?></strong>
                                        <?php if (!empty($row['notes'])): ?>
                                            <div class="interhome-meta-strip"><span class="import-note-badge">Nota presente</span></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= (int)($row['adults'] ?? 0) ?> adulti / <?= (int)($row['children_count'] ?? 0) ?> bambini</td>
                                    <td><?= e($row['customer_phone'] ?? '') ?></td>
                                    <td><?= e($row['customer_email'] ?? '') ?></td>
                                    <td><code class="code"><?= e($row['external_reference'] ?? '') ?></code></td>
                                    <td>
                                        <div class="actions">
                                            <a class="btn btn-primary btn-sm" href="<?= e(admin_url('import-interhome-review.php?ref=' . urlencode((string)($row['external_reference'] ?? '')))) ?>">Apri</a>
                                            <form method="post" action="<?= e(admin_url('actions/remove-interhome-row.php')) ?>" data-confirm="Vuoi rimuovere questa riga dall’elenco del PDF?">
                                                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="external_reference" value="<?= e((string)($row['external_reference'] ?? '')) ?>">
                                                <button type="submit" class="btn btn-danger btn-sm" title="Rimuovi riga">
                                                    <span aria-hidden="true"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg></span>
                                                    Cancella
                                                </button>
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
