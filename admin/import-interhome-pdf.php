<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_admin();

$pageTitle = 'Importa PDF Interhome';
$importState = $_SESSION['interhome_import'] ?? null;

require_once __DIR__ . '/includes/header.php';

function interhome_state_badge_class(?string $state): string
{
    return match ($state) {
        'new' => 'is-new',
        'cancelled' => 'is-cancelled',
        'modified' => 'is-modified',
        default => 'is-existing',
    };
}
?>
<style>
.interhome-shell {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.interhome-upload-card,
.interhome-table-card,
.interhome-pdf-card {
    overflow: hidden;
}

.interhome-summary-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
}

.interhome-summary-card {
    padding: 22px;
    border-radius: 20px;
    border: 1px solid #dbe4f0;
    background: #fff;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.interhome-summary-card strong {
    font-size: 2rem;
    line-height: 1;
}

.interhome-summary-card.is-blue { background: #f3f7ff; }
.interhome-summary-card.is-amber { background: #fff8ec; }
.interhome-summary-card.is-green { background: #eefbf2; }
.interhome-summary-card.is-purple { background: #f6f0ff; }

.summary-label {
    font-size: .82rem;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: #6a7a90;
}

.summary-meta {
    color: #6a7a90;
    font-size: .92rem;
}

.interhome-file-drop {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 24px;
    border: 2px dashed #c8d6eb;
    border-radius: 18px;
    background: #f9fbfe;
    cursor: pointer;
}

.interhome-file-title {
    font-size: 1.05rem;
    font-weight: 700;
    color: #0f2240;
}

.interhome-file-subtitle {
    font-size: .95rem;
    color: #62738a;
}

.interhome-file-drop input[type="file"] {
    margin-top: 10px;
}

.interhome-toolbar {
    display: flex;
    gap: 12px;
    align-items: center;
    justify-content: flex-end;
    flex-wrap: wrap;
}

.interhome-import-table {
    width: 100%;
    border-collapse: collapse;
}

.interhome-import-table thead th {
    position: sticky;
    top: 0;
    z-index: 2;
    background: #f4f7fb;
    font-size: .82rem;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: #51647f;
    padding: 14px 16px;
    text-align: left;
    border-bottom: 1px solid #dce6f3;
}

.interhome-import-table tbody td {
    padding: 16px;
    border-bottom: 1px solid #e6edf7;
    vertical-align: top;
}

.interhome-import-row:hover {
    background: #f9fbff;
}

.interhome-customer {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.interhome-customer-top {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.interhome-flag {
    font-size: 1.1rem;
    line-height: 1;
}

.interhome-state-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 10px;
    border-radius: 999px;
    font-size: .78rem;
    font-weight: 700;
    white-space: nowrap;
    border: 1px solid transparent;
}

.interhome-state-badge.is-new {
    background: #e8f8ee;
    color: #167c45;
    border-color: #b7e6c9;
}

.interhome-state-badge.is-existing {
    background: #f0f2f5;
    color: #48566a;
    border-color: #d8dde5;
}

.interhome-state-badge.is-modified {
    background: #ebf3ff;
    color: #1d5fd0;
    border-color: #bfd4ff;
}

.interhome-state-badge.is-cancelled {
    background: #ffefef;
    color: #c62828;
    border-color: #f3c2c2;
}

.interhome-muted {
    color: #6d7d92;
    font-size: .92rem;
}

.interhome-notes {
    max-width: 340px;
    white-space: normal;
    word-break: break-word;
}

.interhome-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.interhome-pdf-panel {
    margin-top: 14px;
}

.interhome-pdf-frame {
    width: 100%;
    height: 950px;
    border: 0;
    border-radius: 18px;
    background: #fff;
}

.interhome-toggle-row {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 10px;
}

@media (max-width: 1200px) {
    .interhome-summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 760px) {
    .interhome-summary-grid {
        grid-template-columns: 1fr;
    }

    .interhome-import-table thead {
        display: none;
    }

    .interhome-import-table,
    .interhome-import-table tbody,
    .interhome-import-table tr,
    .interhome-import-table td {
        display: block;
        width: 100%;
    }

    .interhome-import-row {
        border-bottom: 1px solid #e6edf7;
    }

    .interhome-import-table tbody td {
        border-bottom: 0;
        padding: 10px 16px;
    }

    .interhome-pdf-frame {
        height: 720px;
    }
}
</style>

<div class="booking-page interhome-shell">
    <div class="booking-hero">
        <div class="booking-hero-copy">
            <h1>Importa PDF Interhome</h1>
            <p class="muted">Carica il PDF arrivi dell’agenzia, verifica il riepilogo e lavora le prenotazioni una per una.</p>
        </div>
        <a class="btn btn-light" href="<?= e(admin_url('index.php#registered-bookings')) ?>">Torna alle prenotazioni</a>
    </div>

    <section class="card interhome-upload-card">
        <div class="section-title">
            <div>
                <h2>Carica PDF</h2>
                <p class="muted">Il parser legge soggiorno, soluzione, cliente, contatti, riferimento, lingua, note e stato della riga nel PDF.</p>
            </div>
        </div>

        <form class="interhome-upload-form" method="post" action="<?= e(admin_url('actions/parse-interhome-pdf.php')) ?>" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">

            <label class="interhome-file-drop" for="interhomePdfInput">
                <span class="interhome-file-title">Seleziona il PDF arrivi</span>
                <span class="interhome-file-subtitle">Formato .pdf, preferibilmente il documento originale Interhome.</span>
                <input id="interhomePdfInput" type="file" name="interhome_pdf" accept="application/pdf" required>
            </label>

            <div class="form-actions" style="margin-top:16px;">
                <button class="btn btn-primary" type="submit">Analizza PDF</button>
            </div>
        </form>
    </section>

    <?php if ($importState): ?>
        <div class="interhome-toolbar">
            <form method="post" action="<?= e(admin_url('actions/remove-interhome-row.php')) ?>" onsubmit="return confirm('Vuoi davvero svuotare l’elenco importato?');">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="clear_all" value="1">
                <button class="btn btn-light btn-sm" type="submit">Svuota elenco</button>
            </form>
        </div>

        <section class="interhome-summary-grid">
            <article class="interhome-summary-card is-blue">
                <span class="summary-label">Pagine lette</span>
                <strong><?= (int) ($importState['summary']['pages_read'] ?? 0) ?></strong>
                <span class="summary-meta">Documento: <?= e($importState['file_name'] ?? 'PDF caricato') ?></span>
            </article>

            <article class="interhome-summary-card is-amber">
                <span class="summary-label">Prenotazioni trovate</span>
                <strong><?= (int) ($importState['summary']['parsed_total'] ?? 0) ?></strong>
                <span class="summary-meta">Tutte le righe rilevate dal parser</span>
            </article>

            <article class="interhome-summary-card is-green">
                <span class="summary-label">Nuove prenotazioni</span>
                <strong><?= (int) ($importState['summary']['new_total'] ?? 0) ?></strong>
                <span class="summary-meta">Pronte per la revisione</span>
            </article>

            <article class="interhome-summary-card is-purple">
                <span class="summary-label">Duplicati esclusi</span>
                <strong><?= (int) ($importState['summary']['duplicates_skipped'] ?? 0) ?></strong>
                <span class="summary-meta">Già presenti nelle prenotazioni registrate</span>
            </article>
        </section>

        <?php if (!empty($importState['rows'])): ?>
            <section class="card interhome-table-card">
                <div class="section-title">
                    <div>
                        <h2>Nuove prenotazioni trovate</h2>
                        <p class="muted">Le prenotazioni cancellate o modificate sono subito riconoscibili. Puoi rimuoverle senza aprire la scheda.</p>
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
                            <?php
                            $state = (string) ($row['_pdf_state'] ?? 'existing');
                            $stateLabel = (string) ($row['_pdf_state_label'] ?? 'Prenotazione esistente');
                            $flag = (string) ($row['_country_flag'] ?? '');
                            ?>
                            <tr class="interhome-import-row" data-row-href="<?= e(admin_url('import-interhome-review.php?row=' . urlencode((string) $row['import_row_id']))) ?>" style="cursor:pointer;">
                                <td>
                                    <div class="interhome-customer">
                                        <div class="interhome-customer-top">
                                            <strong><?= e($row['customer_name']) ?></strong>
                                            <?php if ($flag !== ''): ?>
                                                <span class="interhome-flag" title="<?= e((string) ($row['_language'] ?? '')) ?>"><?= e($flag) ?></span>
                                            <?php endif; ?>
                                        </div>

                                        <span class="interhome-state-badge <?= e(interhome_state_badge_class($state)) ?>">
                                            <?= e($stateLabel) ?>
                                        </span>

                                        <span class="interhome-muted"><?= e((string) ($row['_language'] ?? '-')) ?></span>
                                    </div>
                                </td>

                                <td><?= e($row['stay_period']) ?></td>

                                <td>
                                    <strong><?= e($row['room_type']) ?></strong><br>
                                    <span class="interhome-muted"><?= e((string) ($row['_raw_property'] ?? '')) ?></span>
                                </td>

                                <td><?= (int) ($row['adults'] ?? 0) ?> adulti / <?= (int) ($row['children_count'] ?? 0) ?> bambini</td>

                                <td><?= e($row['external_reference']) ?></td>

                                <td>
                                    <?php if (!empty($row['customer_email'])): ?>
                                        <div><?= e($row['customer_email']) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($row['customer_phone'])): ?>
                                        <div class="interhome-muted"><?= e($row['customer_phone']) ?></div>
                                    <?php endif; ?>
                                    <?php if (empty($row['customer_email']) && empty($row['customer_phone'])): ?>
                                        <span class="interhome-muted">Non presenti</span>
                                    <?php endif; ?>
                                </td>

                                <td class="interhome-notes">
                                    <?php if (!empty($row['notes'])): ?>
                                        <?= e(mb_strimwidth((string) $row['notes'], 0, 90, '…')) ?>
                                    <?php else: ?>
                                        <span class="interhome-muted">-</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <div class="interhome-actions" onclick="event.stopPropagation();">
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
            <section class="card interhome-pdf-card">
                <div class="section-title">
                    <div>
                        <h2>PDF di lavoro</h2>
                        <p class="muted">Apri e chiudi il viewer quando ti serve. La visualizzazione resta ampia e leggibile.</p>
                    </div>
                </div>

                <div class="interhome-toggle-row">
                    <button type="button" class="btn btn-light" data-pdf-toggle aria-expanded="false">Apri PDF</button>
                </div>

                <div class="interhome-pdf-panel" data-pdf-panel style="display:none;">
                    <iframe
                        class="interhome-pdf-frame"
                        src="<?= e($importState['pdf_url']) ?>"
                        title="PDF Interhome">
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
            const isOpen = pdfPanel.style.display !== 'none';
            pdfPanel.style.display = isOpen ? 'none' : 'block';
            toggleBtn.textContent = isOpen ? 'Apri PDF' : 'Chiudi PDF';
            toggleBtn.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
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