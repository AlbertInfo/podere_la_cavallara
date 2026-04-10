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
    $state = (string) $state;

    switch ($state) {
        case 'new':
            return 'is-new';
        case 'cancelled':
            return 'is-cancelled';
        case 'modified':
            return 'is-modified';
        default:
            return 'is-existing';
    }
}

function language_to_country_code(?string $language): string
{
    $language = trim((string) $language);

    switch ($language) {
        case 'Italiano':
            return 'it';
        case 'Inglese':
            return 'gb';
        case 'Tedesco':
            return 'de';
        case 'Ceco':
            return 'cz';
        case 'Polacco':
            return 'pl';
        case 'Olandese':
            return 'nl';
        case 'Francese':
            return 'fr';
        case 'Spagnolo':
            return 'es';
        default:
            return '';
    }
}
?>
<style>
.interhome-shell{
  display:grid;
  gap:20px;
}

.interhome-upload-card,
.interhome-table-card,
.interhome-pdf-card{
  border-top:5px solid var(--primary);
  overflow:hidden;
}

.interhome-upload-form{
  display:grid;
  gap:16px;
}

.interhome-file-drop{
  display:grid;
  gap:8px;
  padding:20px;
  border:1px dashed rgba(29,78,216,.32);
  border-radius:18px;
  background:linear-gradient(180deg,#f9fbff,#fff);
}

.interhome-file-title{
  font-weight:800;
  font-size:18px;
}

.interhome-file-subtitle{
  color:var(--muted);
  font-size:14px;
}

.interhome-summary-grid{
  display:grid;
  grid-template-columns:repeat(4,minmax(0,1fr));
  gap:16px;
}

.interhome-summary-card{
  display:grid;
  gap:8px;
  padding:22px;
  border-radius:20px;
  border:1px solid var(--line);
}

.interhome-summary-card strong{
  font-size:34px;
  line-height:1;
}

.interhome-summary-card.is-blue{background:linear-gradient(180deg,#fff,#eef5ff)}
.interhome-summary-card.is-green{background:linear-gradient(180deg,#fff,#effcf4)}
.interhome-summary-card.is-purple{background:linear-gradient(180deg,#fff,#f5f3ff)}
.interhome-summary-card.is-amber{background:linear-gradient(180deg,#fff,#fff7ed)}

.interhome-toolbar{
  display:flex;
  gap:10px;
  justify-content:flex-end;
  flex-wrap:wrap;
}

.interhome-table-card .section-title{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:16px;
  flex-wrap:wrap;
}

.interhome-import-table{
  width:100%;
  border-collapse:collapse;
  table-layout:fixed;
}

.interhome-import-table thead th{
  background:#f4f7fb;
  color:#51647f;
  font-size:12px;
  text-transform:uppercase;
  letter-spacing:.04em;
  text-align:left;
  padding:14px 16px;
  border-bottom:1px solid var(--line);
  white-space:nowrap;
}

.interhome-import-table tbody td{
  padding:16px;
  border-bottom:1px solid #e6edf7;
  vertical-align:top;
  overflow-wrap:anywhere;
}

.interhome-import-row{
  cursor:pointer;
}

.interhome-import-row:hover{
  background:#f8fbff;
}

.interhome-import-table th:nth-child(1),
.interhome-import-table td:nth-child(1){width:18%}
.interhome-import-table th:nth-child(2),
.interhome-import-table td:nth-child(2){width:12%}
.interhome-import-table th:nth-child(3),
.interhome-import-table td:nth-child(3){width:15%}
.interhome-import-table th:nth-child(4),
.interhome-import-table td:nth-child(4){width:9%}
.interhome-import-table th:nth-child(5),
.interhome-import-table td:nth-child(5){width:12%}
.interhome-import-table th:nth-child(6),
.interhome-import-table td:nth-child(6){width:20%}
.interhome-import-table th:nth-child(7),
.interhome-import-table td:nth-child(7){width:9%}
.interhome-import-table th:nth-child(8),
.interhome-import-table td:nth-child(8){width:10%}

.interhome-customer{
  display:grid;
  gap:6px;
}

.interhome-customer-top{
  display:flex;
  align-items:center;
  gap:8px;
  flex-wrap:wrap;
}

.interhome-flag{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-width:24px;
  height:24px;
  padding:0 6px;
  border-radius:999px;
  background:#f3f6fb;
  border:1px solid #d9e3ef;
  font-size:14px;
  line-height:1;
}

.interhome-state-badge{
  display:inline-flex;
  align-items:center;
  gap:6px;
  padding:6px 10px;
  border-radius:999px;
  font-size:12px;
  font-weight:700;
  border:1px solid transparent;
  width:max-content;
  max-width:100%;
}

.interhome-state-badge.is-new{
  background:#e8f8ee;
  color:#167c45;
  border-color:#b7e6c9;
}

.interhome-state-badge.is-existing{
  background:#f0f2f5;
  color:#48566a;
  border-color:#d8dde5;
}

.interhome-state-badge.is-modified{
  background:#ebf3ff;
  color:#1d5fd0;
  border-color:#bfd4ff;
}

.interhome-state-badge.is-cancelled{
  background:#ffefef;
  color:#c62828;
  border-color:#f3c2c2;
}

.interhome-muted{
  color:var(--muted);
  font-size:14px;
}

.interhome-room-code{
  display:block;
  margin-top:4px;
}

.interhome-notes{
  display:-webkit-box;
  -webkit-line-clamp:3;
  -webkit-box-orient:vertical;
  overflow:hidden;
  line-height:1.45;
  max-height:4.4em;
  word-break:break-word;
}

.interhome-actions{
  display:flex;
  flex-direction:column;
  gap:8px;
  align-items:flex-start;
}

.interhome-pdf-card .section-title{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:16px;
  flex-wrap:wrap;
}

.interhome-pdf-panel{
  margin-top:14px;
}

.interhome-pdf-frame{
  width:100%;
  height:1100px;
  border:1px solid var(--line);
  border-radius:18px;
  background:#fff;
}

@media(max-width:1180px){
  .interhome-summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
}

@media(max-width:900px){
  .interhome-import-table{
    table-layout:auto;
  }
}

@media(max-width:780px){
  .interhome-summary-grid{
    grid-template-columns:1fr;
  }

  .interhome-import-table thead{
    display:none;
  }

  .interhome-import-table,
  .interhome-import-table tbody,
  .interhome-import-table tr,
  .interhome-import-table td{
    display:block;
    width:100%;
  }

  .interhome-import-table tbody td{
    padding:10px 16px;
    border-bottom:0;
  }

  .interhome-import-row{
    border-bottom:1px solid #e6edf7;
  }

  .interhome-actions{
    flex-direction:row;
    flex-wrap:wrap;
  }

  .interhome-pdf-frame{
    height:780px;
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
                    <div class="toolbar" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                        <input class="search-input" type="search" placeholder="Cerca prenotazioni nel PDF..." data-table-filter="#interhome-import-table">
                        <?php if (!empty($importState['pdf_url'])): ?>
                            <button type="button" class="btn btn-light" data-pdf-toggle aria-expanded="false">Apri PDF</button>
                        <?php endif; ?>
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
    $countryCode = language_to_country_code($row['_language'] ?? ''); 
    ?>
                            <tr class="interhome-import-row" data-row-href="<?= e(admin_url('import-interhome-review.php?row=' . urlencode((string) $row['import_row_id']))) ?>">
                                <td>
                                    <div class="interhome-customer">
                                        <div class="interhome-customer-top">
                                             <strong><?= e($row['customer_name']) ?></strong>
                                            <?php if ($countryCode !== ''): ?>
                                                  <span class="interhome-flag" title="<?= e((string) ($row['_language'] ?? '')) ?>"><?= e($countryCode) ?></span>
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
                                    <span class="interhome-muted interhome-room-code"><?= e((string) ($row['_raw_property'] ?? '')) ?></span>
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

                                <td>
                                    <?php if (!empty($row['notes'])): ?>
                                        <div class="interhome-notes" title="<?= e((string) $row['notes']) ?>">
                                            <?= e((string) $row['notes']) ?>
                                        </div>
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

                <div class="interhome-pdf-panel" data-pdf-panel style="display:none;">
                    <iframe
                        class="interhome-pdf-frame"
                        src="<?= e($importState['pdf_url'] . '#zoom=page-width') ?>"
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