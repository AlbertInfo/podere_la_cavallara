<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_admin();

$importState = $_SESSION['interhome_import'] ?? null;
$rowId = trim((string) ($_GET['row'] ?? ''));
$row = null;

if ($importState && !empty($importState['rows'])) {
  
    foreach ($importState['rows'] as $candidate) {
        if (($candidate['import_row_id'] ?? '') === $rowId) {
            $row = $candidate;
            var_dump($rowId);  // Controlla che l'ID passato sia quello giusto
            break;
        }
    }
}

if (!$row) {
    set_flash('error', 'Riga importata non trovata o già rimossa.');
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

$pageTitle = 'Verifica prenotazione PDF';
require_once __DIR__ . '/includes/header.php';

$rooms = [
    'Casa Domenico 1',
    'Casa Domenico 2',
    'Casa Domenico 1-2',
    'Casa Riccardo 3',
    'Casa Riccardo 4',
    'Casa Alessandro 5',
    'Casa Alessandro 6'
];

$statuses = [
    'confermata' => 'Confermata',
    'in_attesa' => 'In attesa',
    'annullata' => 'Annullata'
];

function review_state_badge_class(?string $state): string
{
    return match ((string) $state) {
        'new' => 'is-new',
        'cancelled' => 'is-cancelled',
        'modified' => 'is-modified',
        default => 'is-existing',
    };
}

function normalize_review_flag(?string $value, ?string $language = null): string
{
    $value = trim((string) $value);

    if ($value !== '' && preg_match('/^[\x{1F1E6}-\x{1F1FF}]{2}$/u', $value)) {
        return $value;
    }

    $map = [
        'IT' => '🇮🇹',
        'GB' => '🇬🇧',
        'EN' => '🇬🇧',
        'DE' => '🇩🇪',
        'CZ' => '🇨🇿',
        'PL' => '🇵🇱',
        'NL' => '🇳🇱',
        'FR' => '🇫🇷',
        'ES' => '🇪🇸',
        'Italiano' => '🇮🇹',
        'Inglese' => '🇬🇧',
        'Tedesco' => '🇩🇪',
        'Ceco' => '🇨🇿',
        'Polacco' => '🇵🇱',
        'Olandese' => '🇳🇱',
        'Francese' => '🇫🇷',
        'Spagnolo' => '🇪🇸',
    ];

    if ($value !== '' && isset($map[$value])) {
        return $map[$value];
    }

    $language = trim((string) $language);
    return $map[$language] ?? '';
}

$countryCode = normalize_review_flag($row['_country_flag'] ?? '', $row['_language'] ?? '');
$pdfState = (string) ($row['_pdf_state'] ?? 'existing');
$pdfStateLabel = (string) ($row['_pdf_state_label'] ?? 'Prenotazione esistente');
?>
<style>
.interhome-review-shell{
    display:grid;
    gap:20px;
}

.interhome-review-meta{
    padding:24px;
}

.interhome-review-meta-grid{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:16px;
}

.interhome-review-meta-item{
    background:#f8fbff;
    border:1px solid #dce7f5;
    border-radius:18px;
    padding:16px 18px;
    display:flex;
    flex-direction:column;
    gap:6px;
    min-height:96px;
}

.interhome-review-meta-item strong{
    font-size:1.06rem;
    line-height:1.35;
    color:#102341;
    word-break:break-word;
}

.interhome-review-meta .summary-label{
    display:block;
    font-size:12px;
    color:var(--muted);
    text-transform:uppercase;
    letter-spacing:.05em;
    margin-bottom:4px;
}

.interhome-review-inline{
    display:flex;
    align-items:center;
    gap:8px;
    flex-wrap:wrap;
}

.interhome-review-flag{
   
  display:inline-block;
  width:22px;
  height:16px;
  border-radius:3px;
  box-shadow:0 0 0 1px rgba(0,0,0,.08);

}

.interhome-review-badge{
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

.interhome-review-badge.is-new{
    background:#e8f8ee;
    color:#167c45;
    border-color:#b7e6c9;
}

.interhome-review-badge.is-existing{
    background:#f0f2f5;
    color:#48566a;
    border-color:#d8dde5;
}

.interhome-review-badge.is-modified{
    background:#ebf3ff;
    color:#1d5fd0;
    border-color:#bfd4ff;
}

.interhome-review-badge.is-cancelled{
    background:#ffefef;
    color:#c62828;
    border-color:#f3c2c2;
}

.interhome-review-hero-actions{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    align-items:center;
}

.interhome-review-parser-note{
    color:var(--muted);
    font-size:14px;
}

@media(max-width:1080px){
    .interhome-review-meta-grid{
        grid-template-columns:1fr 1fr;
    }
}

@media(max-width:680px){
    .interhome-review-meta-grid{
        grid-template-columns:1fr;
    }
}
</style>

<div class="booking-page interhome-review-shell">
    <div class="booking-hero">
        <div class="booking-hero-copy">
            <h1>Verifica prenotazione importata</h1>
            <p class="muted">Controlla i dati letti dal PDF, correggili se serve e inserisci la prenotazione tra quelle registrate.</p>
        </div>

        <div class="interhome-review-hero-actions">
            <form method="post" action="<?= e(admin_url('actions/remove-interhome-row.php')) ?>" data-confirm="Vuoi togliere questa prenotazione dall’elenco importato?" class="js-row-action">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="row_id" value="<?= e((string) $row['import_row_id']) ?>">
                <button class="btn btn-danger" type="submit">Cancella riga</button>
            </form>

            <a class="btn btn-light" href="<?= e(admin_url('import-interhome-pdf.php')) ?>">Torna all’elenco</a>
        </div>
    </div>

    <div class="card interhome-review-meta">
        <div class="interhome-review-meta-grid">
            <div class="interhome-review-meta-item">
                <span class="summary-label">Riferimento prenotazione</span>
                <strong><?= e((string) $row['external_reference']) ?></strong>
            </div>

            <div class="interhome-review-meta-item">
                <span class="summary-label">Pagina PDF</span>
                <strong><?= (int) ($row['_page'] ?? 0) ?></strong>
            </div>

            <div class="interhome-review-meta-item">
                <span class="summary-label">Origine</span>
                <strong><?= e((string) $row['source']) ?></strong>
            </div>

            <div class="interhome-review-meta-item">
                <span class="summary-label">Stato letto dal PDF</span>
                <div class="interhome-review-inline">
                    <span class="interhome-review-badge <?= e(review_state_badge_class($pdfState)) ?>">
                        <?= e($pdfStateLabel) ?>
                    </span>
                </div>
            </div>

            <div class="interhome-review-meta-item">
                <span class="summary-label">Lingua letta</span>
                <?php $countryCode = language_to_country_code($row['_language'] ?? ''); ?>
<div class="interhome-review-inline">
    <?php if ($countryCode !== ''): ?>
        <span class="fi fi-<?= e($countryCode) ?> interhome-review-flag" title="<?= e((string) ($row['_language'] ?? '')) ?>"></span>
    <?php endif; ?>
    <strong><?= e((string) ($row['_language'] ?? '-')) ?></strong>
</div>
            </div>

            <div class="interhome-review-meta-item">
                <span class="summary-label">Persone lette</span>
                <strong><?= e((string) ($row['_raw_people'] ?? '-')) ?></strong>
            </div>

            <div class="interhome-review-meta-item" style="grid-column:1 / -1;">
                <span class="summary-label">Casa letta dal parser</span>
                <strong><?= e((string) ($row['_raw_property'] ?? '-')) ?></strong>
            </div>
        </div>
    </div>

    <form class="booking-form" method="post" action="<?= e(admin_url('actions/create-prenotazione-from-interhome.php')) ?>" data-confirm="Confermi l’inserimento di questa prenotazione tra quelle registrate?">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="import_row_id" value="<?= e((string) $row['import_row_id']) ?>">

        <section class="form-section">
            <h2 class="form-section-title">Soggiorno</h2>
            <div class="booking-form-grid">
                <label>
                    Periodo soggiorno *
                    <input class="js-date-range" type="text" name="stay_period" value="<?= e((string) $row['stay_period']) ?>" required>
                </label>

                <label>
                    Soluzione *
                    <select name="room_type" required>
                        <option value="">Scegli soluzione</option>
                        <?php foreach ($rooms as $room): ?>
                            <option value="<?= e($room) ?>" <?= ((string) $row['room_type'] === $room) ? 'selected' : '' ?>><?= e($room) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    Adulti *
                    <input type="number" name="adults" min="0" step="1" value="<?= (int) ($row['adults'] ?? 0) ?>" required>
                </label>

                <label>
                    Bambini
                    <input type="number" name="children_count" min="0" step="1" value="<?= (int) ($row['children_count'] ?? 0) ?>">
                </label>
            </div>
        </section>

        <section class="form-section">
            <h2 class="form-section-title">Ospite</h2>
            <div class="booking-form-grid">
                <label>
                    Nome e cognome *
                    <input type="text" name="customer_name" value="<?= e((string) $row['customer_name']) ?>" required>
                </label>

                <label>
                    Email
                    <input type="email" name="customer_email" value="<?= e((string) ($row['customer_email'] ?? '')) ?>" placeholder="Non presente nel PDF">
                </label>

                <label>
                    Telefono
                    <input type="text" name="customer_phone" value="<?= e((string) ($row['customer_phone'] ?? '')) ?>" placeholder="Non presente nel PDF">
                </label>

                <label>
                    Riferimento esterno *
                    <input type="text" name="external_reference" value="<?= e((string) $row['external_reference']) ?>" required>
                </label>
            </div>
        </section>

        <section class="form-section">
            <h2 class="form-section-title">Gestione prenotazione</h2>
            <div class="booking-form-grid">
                <label>
                    Stato *
                    <select name="status" required>
                        <?php foreach ($statuses as $key => $label): ?>
                            <option value="<?= e($key) ?>" <?= ((string) $row['status'] === $key) ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    Origine *
                    <input type="text" name="source" value="interhome_pdf" readonly>
                </label>

                <label class="full">
                    Note
                    <textarea name="notes" placeholder="Annotazioni del parser o note inserite dall'admin"><?= e((string) ($row['notes'] ?? '')) ?></textarea>
                </label>
            </div>
        </section>

        <div class="form-actions">
            <a class="btn btn-light" href="<?= e(admin_url('import-interhome-pdf.php')) ?>">Torna all’elenco</a>
            <button class="btn btn-primary" type="submit">Conferma e salva</button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>