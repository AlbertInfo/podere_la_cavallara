<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_admin();

function imported_pdf_table_exists(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM admin_imported_pdfs LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function format_bytes(?int $bytes): string
{
    $bytes = (int) $bytes;
    if ($bytes <= 0) {
        return '—';
    }

    $units = ['B', 'KB', 'MB', 'GB'];
    $power = (int) floor(log($bytes, 1024));
    $power = max(0, min($power, count($units) - 1));
    $value = $bytes / (1024 ** $power);

    return number_format($value, $power === 0 ? 0 : 2, ',', '.') . ' ' . $units[$power];
}

function pdf_status_badge_class(string $status): string
{
    return match ($status) {
        'parsed' => 'is-success',
        'failed' => 'is-danger',
        default => 'is-neutral',
    };
}

$pageTitle = 'Archivio PDF';
$search = trim((string) ($_GET['q'] ?? ''));
$tableReady = imported_pdf_table_exists($pdo);
$files = [];
$stats = [
    'total' => 0,
    'parsed' => 0,
    'failed' => 0,
    'total_size' => 0,
];

if ($tableReady) {
    $stats = [
        'total' => (int) $pdo->query('SELECT COUNT(*) FROM admin_imported_pdfs')->fetchColumn(),
        'parsed' => (int) $pdo->query("SELECT COUNT(*) FROM admin_imported_pdfs WHERE parser_status = 'parsed'")->fetchColumn(),
        'failed' => (int) $pdo->query("SELECT COUNT(*) FROM admin_imported_pdfs WHERE parser_status = 'failed'")->fetchColumn(),
        'total_size' => (int) $pdo->query('SELECT COALESCE(SUM(file_size), 0) FROM admin_imported_pdfs')->fetchColumn(),
    ];

    $sql = 'SELECT p.*, a.name AS uploaded_by_name
            FROM admin_imported_pdfs p
            LEFT JOIN admin_users a ON a.id = p.uploaded_by_admin_id';

    $params = [];
    if ($search !== '') {
        $sql .= ' WHERE (
            p.display_name LIKE :search
            OR p.original_name LIKE :search
            OR p.stored_name LIKE :search
        )';
        $params['search'] = '%' . $search . '%';
    }

    $sql .= ' ORDER BY p.created_at DESC LIMIT 250';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

require_once __DIR__ . '/includes/header.php';
?>
<style>
.pdf-manager-shell{display:grid;gap:20px}
.pdf-manager-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}
.pdf-kpi-card{
    padding:20px;border-radius:22px;border:1px solid rgba(219,228,240,.95);
    background:linear-gradient(180deg,#fff 0%,#f8fbff 100%);box-shadow:var(--shadow);
}
.pdf-kpi-card:nth-child(1){background:linear-gradient(180deg,#fff,#eff6ff)}
.pdf-kpi-card:nth-child(2){background:linear-gradient(180deg,#fff,#effcf4)}
.pdf-kpi-card:nth-child(3){background:linear-gradient(180deg,#fff,#fff1f2)}
.pdf-kpi-card:nth-child(4){background:linear-gradient(180deg,#fff,#faf5ff)}
.pdf-kpi-label{font-size:13px;color:var(--muted)}
.pdf-kpi-value{margin-top:8px;font-size:32px;font-weight:800}
.pdf-manager-toolbar{display:flex;gap:12px;flex-wrap:wrap;align-items:center}
.pdf-manager-toolbar form{flex:1;min-width:280px}
.pdf-manager-search{width:100%}
.pdf-manager-table table{min-width:1180px}
.pdf-file-cell{display:grid;gap:6px}
.pdf-file-name{font-size:16px;font-weight:800;color:#0f172a}
.pdf-file-meta{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.pdf-file-tag{
    display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;
    background:#eef2ff;color:#3730a3;font-size:12px;font-weight:700;
}
.pdf-status{
    display:inline-flex;align-items:center;padding:7px 11px;border-radius:999px;
    font-size:12px;font-weight:800;border:1px solid transparent;
}
.pdf-status.is-success{background:#ecfdf3;color:#166534;border-color:#bbf7d0}
.pdf-status.is-danger{background:#fef2f2;color:#991b1b;border-color:#fecaca}
.pdf-status.is-neutral{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe}
.pdf-summary-grid{display:grid;gap:6px}
.pdf-summary-grid strong{font-size:14px;color:#0f172a}
.pdf-empty-state{
    padding:28px;border-radius:22px;border:1px dashed #cbd5e1;background:#fff;text-align:center;color:var(--muted)
}
.pdf-rename-box{display:none;margin-top:12px;padding:14px;border-radius:16px;background:#f8fafc;border:1px solid #e2e8f0}
.pdf-rename-box.is-open{display:block}
.pdf-rename-form{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.pdf-rename-form input{flex:1;min-width:220px}
.pdf-alert-card{
    padding:22px;border-radius:22px;border:1px solid #fed7aa;background:linear-gradient(180deg,#fff 0%,#fff7ed 100%);
}
@media (max-width:1180px){.pdf-manager-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:760px){.pdf-manager-grid{grid-template-columns:1fr}}
</style>

<div class="booking-page pdf-manager-shell">
    <div class="booking-hero">
        <div class="booking-hero-copy">
            <h1>Archivio PDF importati</h1>
            <p class="muted">Qui trovi tutti i PDF Interhome importati dal gestionale. Puoi aprirli, rinominarli e cancellarli in modo ordinato.</p>
        </div>
        <div class="toolbar">
            <a class="btn btn-light" href="<?= e(admin_url('import-interhome-pdf.php')) ?>">Importa nuovo PDF</a>
            <a class="btn btn-primary" href="<?= e(admin_url('index.php#registered-bookings')) ?>">Torna alla dashboard</a>
        </div>
    </div>

    <?php if (!$tableReady): ?>
        <section class="pdf-alert-card">
            <h2 style="margin:0 0 8px;">Archivio non ancora attivato</h2>
            <p class="muted" style="margin-bottom:14px;">Per attivare il file manager esegui prima la migration SQL inclusa nel pacchetto. L'import dei PDF continuerà comunque a funzionare anche senza archivio.</p>
            <div class="code">2026-04-10_migration_pdf_file_manager.sql</div>
        </section>
    <?php else: ?>
        <section class="pdf-manager-grid">
            <article class="pdf-kpi-card">
                <div class="pdf-kpi-label">PDF archiviati</div>
                <div class="pdf-kpi-value"><?= (int) $stats['total'] ?></div>
                <div class="small muted">Totale file disponibili</div>
            </article>
            <article class="pdf-kpi-card">
                <div class="pdf-kpi-label">Import riusciti</div>
                <div class="pdf-kpi-value"><?= (int) $stats['parsed'] ?></div>
                <div class="small muted">Parser completato correttamente</div>
            </article>
            <article class="pdf-kpi-card">
                <div class="pdf-kpi-label">Import con errore</div>
                <div class="pdf-kpi-value"><?= (int) $stats['failed'] ?></div>
                <div class="small muted">Da verificare manualmente</div>
            </article>
            <article class="pdf-kpi-card">
                <div class="pdf-kpi-label">Spazio occupato</div>
                <div class="pdf-kpi-value" style="font-size:28px;"><?= e(format_bytes((int) $stats['total_size'])) ?></div>
                <div class="small muted">Somma dei file archiviati</div>
            </article>
        </section>

        <section class="card pdf-manager-table">
            <div class="section-title">
                <div>
                    <h2>Gestione file PDF</h2>
                    <p class="muted">Ricerca rapida per nome file e gestione completa dell'archivio import Interhome.</p>
                </div>
                <div class="pdf-manager-toolbar">
                    <form method="get" action="<?= e(admin_url('file-manager.php')) ?>">
                        <input class="search-input pdf-manager-search" type="search" name="q" value="<?= e($search) ?>" placeholder="Cerca per nome file o nome visualizzato...">
                    </form>
                    <form method="post" action="<?= e(admin_url('actions/backfill-imported-pdfs.php')) ?>" onsubmit="return confirm('Vuoi registrare nell'archivio i PDF già presenti sul server?');">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <button class="btn btn-light" type="submit">Importa file già presenti</button>
                    </form>
                    <?php if ($search !== ''): ?>
                        <a class="btn btn-light" href="<?= e(admin_url('file-manager.php')) ?>">Reset ricerca</a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (empty($files)): ?>
                <div class="pdf-empty-state">
                    <strong>Nessun PDF trovato.</strong>
                    <p style="margin-top:8px;">Prova con un altro termine oppure importa il primo PDF Interhome.</p>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>File</th>
                                <th>Importato il</th>
                                <th>Esito parser</th>
                                <th>Riepilogo</th>
                                <th>Dimensione</th>
                                <th>Admin</th>
                                <th>Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($files as $file): ?>
                            <?php
                            $displayName = trim((string) ($file['display_name'] ?? ''));
                            if ($displayName === '') {
                                $displayName = pathinfo((string) ($file['original_name'] ?? 'PDF Interhome'), PATHINFO_FILENAME);
                            }
                            $viewUrl = admin_url((string) ($file['relative_path'] ?? ''));
                            $parserStatus = (string) ($file['parser_status'] ?? 'uploaded');
                            ?>
                            <tr>
                                <td>
                                    <div class="pdf-file-cell">
                                        <div class="pdf-file-name"><?= e($displayName) ?></div>
                                        <div class="small muted">Originale: <?= e((string) ($file['original_name'] ?? '—')) ?></div>
                                        <div class="pdf-file-meta">
                                            <span class="pdf-file-tag">Interhome PDF</span>
                                            <?php if (!empty($file['stored_name'])): ?>
                                                <span class="small muted"><?= e((string) $file['stored_name']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <strong><?= e((string) ($file['created_at'] ?? '—')) ?></strong>
                                    <div class="small muted">Aggiornato: <?= e((string) ($file['updated_at'] ?? '—')) ?></div>
                                </td>
                                <td>
                                    <span class="pdf-status <?= e(pdf_status_badge_class($parserStatus)) ?>">
                                        <?= e(match ($parserStatus) {
                                            'parsed' => 'Import riuscito',
                                            'failed' => 'Errore parser',
                                            default => 'Caricato',
                                        }) ?>
                                    </span>
                                    <?php if (!empty($file['parser_error'])): ?>
                                        <div class="small muted" style="margin-top:8px; max-width:260px;"><?= e((string) $file['parser_error']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="pdf-summary-grid">
                                        <div><strong>Pagine lette:</strong> <?= (int) ($file['pages_read'] ?? 0) ?></div>
                                        <div><strong>Righe lette:</strong> <?= (int) ($file['parsed_total'] ?? 0) ?></div>
                                        <div><strong>Nuove:</strong> <?= (int) ($file['new_total'] ?? 0) ?></div>
                                        <div><strong>Duplicati esclusi:</strong> <?= (int) ($file['duplicates_skipped'] ?? 0) ?></div>
                                    </div>
                                </td>
                                <td>
                                    <strong><?= e(format_bytes((int) ($file['file_size'] ?? 0))) ?></strong>
                                    <div class="small muted"><?= e((string) ($file['mime_type'] ?? 'application/pdf')) ?></div>
                                </td>
                                <td>
                                    <strong><?= e((string) ($file['uploaded_by_name'] ?? 'Admin')) ?></strong>
                                </td>
                                <td>
                                    <div class="actions">
                                        <a class="btn btn-light btn-sm" href="<?= e($viewUrl) ?>" target="_blank" rel="noopener">Apri PDF</a>
                                        <button class="btn btn-light btn-sm" type="button" data-rename-toggle="pdf-rename-<?= (int) $file['id'] ?>">Rinomina</button>
                                        <form method="post" action="<?= e(admin_url('actions/delete-imported-pdf.php')) ?>" onsubmit="return confirm('Vuoi cancellare definitivamente questo PDF dall\'archivio?');">
                                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="pdf_id" value="<?= (int) $file['id'] ?>">
                                            <button class="btn btn-danger btn-sm" type="submit">Cancella</button>
                                        </form>
                                    </div>

                                    <div class="pdf-rename-box" id="pdf-rename-<?= (int) $file['id'] ?>">
                                        <form class="pdf-rename-form" method="post" action="<?= e(admin_url('actions/rename-imported-pdf.php')) ?>">
                                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="pdf_id" value="<?= (int) $file['id'] ?>">
                                            <input type="text" name="display_name" value="<?= e($displayName) ?>" maxlength="180" placeholder="Nuovo nome visualizzato">
                                            <button class="btn btn-primary btn-sm" type="submit">Salva nome</button>
                                        </form>
                                    </div>
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

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-rename-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            var targetId = button.getAttribute('data-rename-toggle');
            if (!targetId) return;
            var box = document.getElementById(targetId);
            if (!box) return;
            box.classList.toggle('is-open');
        });
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
