<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/interhome_pdf_import.php';
require_admin();

$pageTitle = 'Importa PDF Interhome';
$rows = $_SESSION['interhome_import_rows'] ?? [];
$meta = $_SESSION['interhome_import_meta'] ?? [
    'parsed_total' => 0,
    'importable_total' => 0,
    'cancelled_total' => 0,
    'duplicates_total' => 0,
    'pages_total' => 0,
    'uploaded_name' => null,
];
require_once __DIR__ . '/includes/header.php';
?>
<div class="booking-page interhome-import-page">
    <div class="booking-hero">
        <div class="booking-hero-copy">
            <h1>Importa PDF Interhome</h1>
            <p class="muted">Carica la lista arrivi Interhome. Il sistema rileva automaticamente le prenotazioni cancellate dalla X rossa, scarta i duplicati già salvati e ti mostra solo le nuove prenotazioni da confermare.</p>
        </div>
        <a class="btn btn-light" href="<?= e(admin_url('index.php#registered-bookings')) ?>">Torna alle prenotazioni</a>
    </div>

    <section class="card upload-card">
        <form class="import-upload-form" method="post" action="<?= e(admin_url('actions/parse-interhome-pdf.php')) ?>" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <div class="upload-grid">
                <label class="full">
                    PDF Interhome *
                    <input type="file" name="interhome_pdf" accept="application/pdf" required>
                </label>
                <div class="form-meta-note full">Supportato: PDF con elenco arrivi Interhome. Le prenotazioni con X rossa vengono automaticamente escluse. Le note vengono associate alla prenotazione corretta se nel testo compare il riferimento prenotazione.</div>
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Analizza PDF</button>
            </div>
        </form>
    </section>

    <?php if (!empty($meta['uploaded_name'])): ?>
        <section class="import-summary-grid">
            <article class="summary-card summary-blue">
                <span class="summary-label">PDF caricato</span>
                <strong><?= e($meta['uploaded_name']) ?></strong>
                <span class="summary-meta"><?= (int) $meta['pages_total'] ?> pagine analizzate</span>
            </article>
            <article class="summary-card summary-green">
                <span class="summary-label">Nuove prenotazioni</span>
                <strong><?= (int) $meta['importable_total'] ?></strong>
                <span class="summary-meta">visibili in elenco</span>
            </article>
            <article class="summary-card summary-red">
                <span class="summary-label">Cancellate escluse</span>
                <strong><?= (int) $meta['cancelled_total'] ?></strong>
                <span class="summary-meta">icone con X rossa</span>
            </article>
            <article class="summary-card summary-amber">
                <span class="summary-label">Già presenti</span>
                <strong><?= (int) $meta['duplicates_total'] ?></strong>
                <span class="summary-meta">scartate per duplicato</span>
            </article>
        </section>
    <?php endif; ?>

    <?php if (!empty($rows)): ?>
        <section class="card" style="margin-top:20px;">
            <div class="section-title">
                <div>
                    <h2>Nuove prenotazioni individuate</h2>
                    <p class="muted">Clicca una riga per aprire il form completo e confermare la prenotazione.</p>
                </div>
                <input class="search-input" type="search" placeholder="Cerca prenotazioni trovate..." data-table-filter="#interhome-import-table">
            </div>

            <div class="table-wrap">
                <table id="interhome-import-table" class="import-table">
                    <thead>
                    <tr>
                        <th>Cliente</th>
                        <th>Soggiorno</th>
                        <th>Casa</th>
                        <th>Persone</th>
                        <th>Riferimento</th>
                        <th>Stato PDF</th>
                        <th>Note</th>
                        <th>Azione</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $key => $row): ?>
                        <tr class="import-row" data-row-link="<?= e(admin_url('import-interhome-review.php?row=' . urlencode((string) $key))) ?>">
                            <td>
                                <strong><?= e($row['customer_name']) ?></strong><br>
                                <span class="small muted"><?= e($row['customer_email'] ?: 'Email non presente') ?></span>
                            </td>
                            <td><?= e($row['stay_period']) ?></td>
                            <td><?= e($row['room_type'] ?: 'Da verificare') ?></td>
                            <td><?= (int) $row['adults'] ?> adulti<?= (int) $row['children_count'] > 0 ? ' / ' . (int) $row['children_count'] . ' bambini' : '' ?></td>
                            <td><span class="code"><?= e($row['external_reference'] ?: 'n/d') ?></span></td>
                            <td>
                                <?php if (($row['status_icon'] ?? '') === 'new'): ?>
                                    <span class="badge success">Nuova prenotazione</span>
                                <?php else: ?>
                                    <span class="badge">Prenotazione esistente</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($row['notes'])): ?>
                                    <span class="badge warning">Nota presente</span>
                                <?php else: ?>
                                    <span class="small muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><a class="btn btn-primary btn-sm" href="<?= e(admin_url('import-interhome-review.php?row=' . urlencode((string) $key))) ?>">Apri form</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php elseif (!empty($meta['uploaded_name'])): ?>
        <section class="card" style="margin-top:20px;">
            <h2>Nessuna nuova prenotazione da importare</h2>
            <p class="muted">Il PDF è stato analizzato correttamente, ma tutte le prenotazioni erano già presenti oppure cancellate.</p>
        </section>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
