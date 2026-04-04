<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_admin();

$import = $_SESSION['interhome_import'] ?? null;
$pageTitle = 'Importa PDF Interhome';
require_once __DIR__ . '/includes/header.php';
?>
<div class="import-shell">
    <div class="import-hero">
        <div>
            <h1>Importa PDF Interhome</h1>
            <p class="muted">Carica la lista arrivi Interhome, il sistema leggerà tutte le prenotazioni, escluderà quelle cancellate e quelle già presenti in archivio, poi ti permetterà di aprire e confermare solo le nuove.</p>
        </div>
        <div class="inline-badges">
            <span class="badge">PDF strutturato</span>
            <span class="badge success">Filtro duplicati automatico</span>
        </div>
    </div>

    <section class="card import-upload">
        <div class="section-title">
            <div>
                <h2>Carica il PDF</h2>
                <p class="muted">Sono supportate le liste arrivi Interhome in formato PDF testuale.</p>
            </div>
        </div>

        <form class="file-drop" method="post" action="<?= e(admin_url('actions/parse-interhome-pdf.php')) ?>" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <label>
                Seleziona PDF Interhome
                <input type="file" name="interhome_pdf" accept="application/pdf,.pdf" required>
            </label>
            <div class="form-actions" style="justify-content:flex-start;">
                <button class="btn btn-primary" type="submit">Analizza PDF</button>
            </div>
            <div class="form-hint">Il sistema mostrerà solo le prenotazioni nuove, escludendo automaticamente le righe cancellate e quelle già importate.</div>
        </form>
    </section>

    <?php if ($import): ?>
        <section class="import-summary">
            <article class="import-stat">
                <div class="label">File analizzato</div>
                <div class="value" style="font-size:18px;line-height:1.3;"><?= e($import['file_name'] ?? 'PDF caricato') ?></div>
            </article>
            <article class="import-stat">
                <div class="label">Prenotazioni trovate</div>
                <div class="value"><?= (int) ($import['stats']['total_found'] ?? 0) ?></div>
            </article>
            <article class="import-stat">
                <div class="label">Nuove importabili</div>
                <div class="value"><?= (int) ($import['stats']['new_rows'] ?? 0) ?></div>
            </article>
            <article class="import-stat">
                <div class="label">Escluse</div>
                <div class="value"><?= (int) (($import['stats']['duplicates'] ?? 0) + ($import['stats']['cancelled'] ?? 0)) ?></div>
            </article>
        </section>

        <section class="card import-table-card">
            <div class="section-title">
                <div>
                    <h2>Nuove prenotazioni rilevate</h2>
                    <p class="muted">Clicca una riga per aprire il form di verifica, modificare i dati se necessario e confermare il salvataggio.</p>
                </div>
                <div class="inline-badges">
                    <span class="badge">Duplicati esclusi: <?= (int) ($import['stats']['duplicates'] ?? 0) ?></span>
                    <span class="badge warning">Cancellate escluse: <?= (int) ($import['stats']['cancelled'] ?? 0) ?></span>
                </div>
            </div>

            <?php if (empty($import['rows'])): ?>
                <div class="flash success">Non ci sono nuove prenotazioni da importare. Il PDF contiene solo righe già presenti oppure prenotazioni cancellate.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table id="interhome-import-table">
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th>Soggiorno</th>
                                <th>Casa</th>
                                <th>Persone</th>
                                <th>Rif. esterno</th>
                                <th>Note</th>
                                <th>Stato PDF</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach (($import['rows'] ?? []) as $row): ?>
                            <tr class="import-row" data-row-href="<?= e(admin_url('import-interhome-review.php?row=' . urlencode($row['row_key']))) ?>">
                                <td>
                                    <strong><?= e($row['customer_name']) ?></strong><br>
                                    <span class="small muted"><?= e($row['customer_email'] !== '' ? $row['customer_email'] : 'Email non disponibile') ?></span>
                                </td>
                                <td><?= e($row['stay_period']) ?></td>
                                <td><?= e($row['room_type'] !== '' ? $row['room_type'] : 'Da verificare') ?></td>
                                <td><?= (int) $row['adults'] ?> adulti / <?= (int) $row['children_count'] ?> bambini</td>
                                <td><strong><?= e($row['external_reference']) ?></strong></td>
                                <td>
                                    <?php if (trim((string) $row['notes']) !== ''): ?>
                                        <span class="import-note-badge">Nota presente</span>
                                    <?php else: ?>
                                        <span class="small muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="import-status ok"><?= e($row['status_label']) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
