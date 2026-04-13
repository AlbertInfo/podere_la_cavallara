<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/customer-sync.php';
require_admin();

$pageTitle = 'Storico clienti';
$search = trim((string) ($_GET['q'] ?? ''));
$clientiTableReady = customer_sync_table_exists($pdo);

$countryOptions = [
    '' => 'Non specificata',
    'it' => 'Italia',
    'gb' => 'Regno Unito',
    'de' => 'Germania',
    'cz' => 'Repubblica Ceca',
    'pl' => 'Polonia',
    'nl' => 'Paesi Bassi',
    'fr' => 'Francia',
    'es' => 'Spagna',
];

$languageOptions = [
    '' => 'Non specificata',
    'Italiano' => 'Italiano',
    'Inglese' => 'Inglese',
    'Tedesco' => 'Tedesco',
    'Ceco' => 'Ceco',
    'Polacco' => 'Polacco',
    'Olandese' => 'Olandese',
    'Francese' => 'Francese',
    'Spagnolo' => 'Spagnolo',
];

$stats = [
    'total' => 0,
    'with_email' => 0,
    'with_phone' => 0,
    'linked_bookings' => 0,
    'bookings_without_customer' => 0,
];

$clienti = [];

if ($clientiTableReady) {
    $stats['total'] = (int) $pdo->query('SELECT COUNT(*) FROM clienti')->fetchColumn();
    $stats['with_email'] = (int) $pdo->query('SELECT COUNT(*) FROM clienti WHERE email IS NOT NULL AND email <> ""')->fetchColumn();
    $stats['with_phone'] = (int) $pdo->query('SELECT COUNT(*) FROM clienti WHERE phone IS NOT NULL AND phone <> ""')->fetchColumn();
    $stats['linked_bookings'] = (int) $pdo->query('SELECT COUNT(*) FROM prenotazioni WHERE cliente_id IS NOT NULL')->fetchColumn();
    $stats['bookings_without_customer'] = (int) $pdo->query('SELECT COUNT(*) FROM prenotazioni WHERE cliente_id IS NULL')->fetchColumn();

    $sql = 'SELECT
                c.*,
                COALESCE(ps.bookings_count, 0) AS bookings_count,
                ps.last_booking_created_at,
                ps.last_activity_at
            FROM clienti c
            LEFT JOIN (
                SELECT
                    cliente_id,
                    COUNT(id) AS bookings_count,
                    MAX(created_at) AS last_booking_created_at,
                    MAX(COALESCE(check_out, created_at)) AS last_activity_at
                FROM prenotazioni
                WHERE cliente_id IS NOT NULL
                GROUP BY cliente_id
            ) ps ON ps.cliente_id = c.id';
    $params = [];

    if ($search !== '') {
        $sql .= ' WHERE (
                    c.first_name LIKE :search
                    OR c.last_name LIKE :search
                    OR c.email LIKE :search
                    OR c.phone LIKE :search
                    OR CONCAT(TRIM(c.first_name), " ", TRIM(COALESCE(c.last_name, ""))) LIKE :search
                )';
        $params['search'] = '%' . $search . '%';
    }

    $sql .= ' ORDER BY COALESCE(ps.last_booking_created_at, c.updated_at) DESC, c.updated_at DESC LIMIT 500';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $clienti = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

require_once __DIR__ . '/includes/header.php';
?>
<style>
.clienti-shell{display:grid;gap:20px}
.clienti-kpi-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:16px}
.clienti-kpi-card{
    padding:20px;border-radius:22px;border:1px solid rgba(219,228,240,.95);
    background:linear-gradient(180deg,#fff 0%,#f8fbff 100%);box-shadow:var(--shadow)
}
.clienti-kpi-card:nth-child(1){background:linear-gradient(180deg,#fff,#eff6ff)}
.clienti-kpi-card:nth-child(2){background:linear-gradient(180deg,#fff,#effcf4)}
.clienti-kpi-card:nth-child(3){background:linear-gradient(180deg,#fff,#fefce8)}
.clienti-kpi-card:nth-child(4){background:linear-gradient(180deg,#fff,#faf5ff)}
.clienti-kpi-card:nth-child(5){background:linear-gradient(180deg,#fff,#fff1f2)}
.clienti-kpi-label{font-size:13px;color:var(--muted)}
.clienti-kpi-value{margin-top:8px;font-size:30px;font-weight:800}
.clienti-alert-card{padding:22px;border-radius:22px;border:1px solid #fed7aa;background:linear-gradient(180deg,#fff 0%,#fff7ed 100%)}
.clienti-empty-state{padding:32px;border-radius:22px;border:1px dashed #cbd5e1;background:#fff;text-align:center;color:var(--muted)}
.clienti-table-card{border-top:5px solid var(--primary);overflow:hidden}
.clienti-table-card .section-title{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap}
.clienti-toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:flex-end;width:100%}
.clienti-search-form{min-width:300px;flex:1 1 360px;max-width:460px}
.clienti-search-form .search-input{width:100%}
.clienti-toolbar-group{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.clienti-modal-trigger{display:inline-flex;align-items:center;gap:10px}
.clienti-modal-trigger .btn{box-shadow:0 10px 24px rgba(37,99,235,.18)}
.clienti-table .table-wrap{width:100%;overflow-x:auto;overflow-y:hidden;-webkit-overflow-scrolling:touch}
.clienti-table table{width:100%;min-width:1180px;border-collapse:collapse;table-layout:fixed}
.clienti-table th,.clienti-table td{vertical-align:middle}
.clienti-table th{white-space:nowrap}
.clienti-table td{overflow-wrap:anywhere}
.clienti-table th:nth-child(1),.clienti-table td:nth-child(1){width:19%}
.clienti-table th:nth-child(2),.clienti-table td:nth-child(2){width:18%}
.clienti-table th:nth-child(3),.clienti-table td:nth-child(3){width:16%}
.clienti-table th:nth-child(4),.clienti-table td:nth-child(4){width:17%}
.clienti-table th:nth-child(5),.clienti-table td:nth-child(5){width:16%}
.clienti-table th:nth-child(6),.clienti-table td:nth-child(6){width:14%}
.clienti-name-cell,.clienti-summary{display:grid;gap:6px}
.clienti-summary strong{font-size:14px;color:#0f172a}
.clienti-meta{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.clienti-flag{display:inline-flex;align-items:center;justify-content:center;width:22px;height:16px;border-radius:3px;box-shadow:0 0 0 1px rgba(0,0,0,.08);flex:0 0 auto}
.clienti-pill{display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;background:#eef2ff;color:#3730a3;font-size:12px;font-weight:700;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.clienti-stat-badge{display:inline-flex;align-items:center;padding:7px 11px;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:12px;font-weight:800}
.clienti-table td input,
.clienti-table td select,
.clienti-table td textarea,
.clienti-modal-card input,
.clienti-modal-card select,
.clienti-modal-card textarea{
    width:100%;max-width:100%;min-height:42px;padding:10px 12px;border-radius:14px;box-sizing:border-box
}
.clienti-table td textarea{min-height:78px;resize:vertical}
.clienti-actions{display:grid;gap:8px}
.clienti-actions .btn{width:100%}
.clienti-mobile-list{display:none}
.clienti-mobile-card{
    display:grid;gap:14px;padding:18px;border-radius:24px;border:1px solid rgba(219,228,240,.95);
    background:linear-gradient(180deg,#fff 0%,#f8fbff 100%);box-shadow:0 16px 34px rgba(15,23,42,.08)
}
.clienti-mobile-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
.clienti-mobile-head-copy{display:grid;gap:8px}
.clienti-mobile-head-copy h3{margin:0;font-size:20px;line-height:1.08}
.clienti-mobile-badges{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.clienti-mobile-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.clienti-mobile-field{display:grid;gap:6px}
.clienti-mobile-field span{font-size:11px;letter-spacing:.05em;text-transform:uppercase;color:var(--muted);font-weight:800}
.clienti-mobile-meta{display:grid;gap:10px;padding:14px;border-radius:18px;background:#f8fbff;border:1px solid #e6edf7}
.clienti-mobile-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.clienti-mobile-actions form{margin:0}
.clienti-mobile-actions .btn,.clienti-mobile-actions form .btn{width:100%}
.clienti-note{font-size:13px;color:var(--muted);line-height:1.6}
.clienti-modal{
    position:fixed;inset:0;display:none;align-items:flex-start;justify-content:center;
    padding:40px 20px;background:rgba(15,23,42,.52);backdrop-filter:blur(6px);z-index:1200
}
.clienti-modal.is-open{display:flex}
.clienti-modal-backdrop{position:absolute;inset:0}
.clienti-modal-card{
    position:relative;z-index:1;width:min(760px,100%);max-height:calc(100vh - 80px);overflow:auto;
    border-radius:28px;border:1px solid rgba(191,219,254,.55);
    background:linear-gradient(180deg,#ffffff 0%,#f8fbff 100%);
    box-shadow:0 28px 80px rgba(15,23,42,.28);border-top:5px solid var(--purple)
}
.clienti-modal-header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:24px 24px 0}
.clienti-modal-copy h2{margin:0 0 8px;font-size:28px;color:#0f172a}
.clienti-modal-copy p{margin:0;color:var(--muted);max-width:560px;line-height:1.6}
.clienti-modal-close{
    width:42px;height:42px;border:1px solid rgba(203,213,225,.95);border-radius:999px;background:#fff;
    color:#334155;font-size:22px;line-height:1;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;
    box-shadow:0 10px 24px rgba(15,23,42,.08)
}
.clienti-modal-body{padding:22px 24px 24px}
.clienti-modal-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.clienti-modal-grid label{display:grid;gap:8px;font-weight:700;color:#334155}
.clienti-modal-grid label.full{grid-column:1 / -1}
.clienti-modal-footer{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding-top:6px}
.clienti-modal-note{font-size:13px;color:var(--muted);line-height:1.5;max-width:460px}
.clienti-modal-open-lock{overflow:hidden}
@media (max-width:1280px){
    .clienti-kpi-grid{grid-template-columns:repeat(3,minmax(0,1fr))}
}
@media (max-width:1100px){
    .clienti-kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
    .clienti-table table{min-width:1040px}
}
@media (max-width:760px){
    .clienti-kpi-grid{grid-template-columns:1fr}
    .clienti-search-form,.clienti-toolbar,.clienti-toolbar-group{width:100%;max-width:none}
    .clienti-toolbar{justify-content:stretch}
    .clienti-toolbar .btn{width:100%}
    .clienti-table .table-wrap{display:none}
    .clienti-mobile-list{display:grid;gap:14px}
    .clienti-mobile-grid{grid-template-columns:1fr}
    .clienti-mobile-actions{grid-template-columns:1fr}
    .clienti-modal{padding:16px}
    .clienti-modal-card{max-height:calc(100vh - 32px);border-radius:24px}
    .clienti-modal-header{padding:20px 20px 0}
    .clienti-modal-body{padding:18px 20px 20px}
    .clienti-modal-grid{grid-template-columns:1fr}
    .clienti-modal-grid label.full{grid-column:auto}
    .clienti-modal-footer{justify-content:stretch}
    .clienti-modal-footer .btn{width:100%}
}
</style>

<div class="booking-page clienti-shell">
    <div class="booking-hero">
        <div class="booking-hero-copy">
            <h1>Storico clienti</h1>
            <p class="muted">Uno spazio ordinato per gestire anagrafiche, contatti e storico degli ospiti partendo dalle prenotazioni già registrate.</p>
        </div>
        <div class="toolbar">
            <a class="btn btn-light" href="<?= e(admin_url('index.php#registered-bookings')) ?>">Torna alle prenotazioni</a>
        </div>
    </div>

    <?php if (!$clientiTableReady): ?>
        <section class="clienti-alert-card">
            <h2 style="margin:0 0 8px;">Storico clienti non ancora attivato</h2>
            <p class="muted" style="margin-bottom:14px;">Per attivare questa sezione esegui prima la migration SQL inclusa nel pacchetto. Dopo la migration potrai sincronizzare i clienti direttamente dalle prenotazioni già presenti.</p>
            <div class="code">2026-04-10_migration_customer_history.sql</div>
        </section>
    <?php else: ?>
        <section class="clienti-kpi-grid">
            <article class="clienti-kpi-card">
                <div class="clienti-kpi-label">Clienti totali</div>
                <div class="clienti-kpi-value"><?= (int) $stats['total'] ?></div>
                <div class="small muted">Anagrafiche archiviate</div>
            </article>
            <article class="clienti-kpi-card">
                <div class="clienti-kpi-label">Con email</div>
                <div class="clienti-kpi-value"><?= (int) $stats['with_email'] ?></div>
                <div class="small muted">Contatti digitali disponibili</div>
            </article>
            <article class="clienti-kpi-card">
                <div class="clienti-kpi-label">Con cellulare</div>
                <div class="clienti-kpi-value"><?= (int) $stats['with_phone'] ?></div>
                <div class="small muted">Numeri telefonici utili</div>
            </article>
            <article class="clienti-kpi-card">
                <div class="clienti-kpi-label">Prenotazioni collegate</div>
                <div class="clienti-kpi-value"><?= (int) $stats['linked_bookings'] ?></div>
                <div class="small muted">Record già agganciati allo storico</div>
            </article>
            <article class="clienti-kpi-card">
                <div class="clienti-kpi-label">Da sincronizzare</div>
                <div class="clienti-kpi-value"><?= (int) $stats['bookings_without_customer'] ?></div>
                <div class="small muted">Prenotazioni ancora senza anagrafica</div>
            </article>
        </section>

        <div class="clienti-modal" id="clienteCreateModal" aria-hidden="true">
            <div class="clienti-modal-backdrop" data-close-cliente-modal></div>
            <div class="clienti-modal-card" role="dialog" aria-modal="true" aria-labelledby="clienteCreateModalTitle">
                <div class="clienti-modal-header">
                    <div class="clienti-modal-copy">
                        <h2 id="clienteCreateModalTitle">Nuovo cliente</h2>
                        <p>Inserisci un'anagrafica manualmente con uno spazio ampio, ordinato e comodo da usare. Il cliente sarà disponibile subito nello storico.</p>
                    </div>
                    <button class="clienti-modal-close" type="button" aria-label="Chiudi" data-close-cliente-modal>&times;</button>
                </div>

                <div class="clienti-modal-body">
                    <form class="booking-form" method="post" action="<?= e(admin_url('actions/create-cliente.php')) ?>">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <div class="clienti-modal-grid">
                            <label>
                                Nome *
                                <input type="text" name="first_name" required data-cliente-modal-first-field>
                            </label>
                            <label>
                                Cognome
                                <input type="text" name="last_name">
                            </label>
                            <label>
                                Email
                                <input type="email" name="email" placeholder="cliente@email.it">
                            </label>
                            <label>
                                Cellulare
                                <input type="text" name="phone" placeholder="+39 ...">
                            </label>
                            <label>
                                Nazionalità / bandierina
                                <select name="guest_country_code">
                                    <?php foreach ($countryOptions as $value => $label): ?>
                                        <option value="<?= e($value) ?>"><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>
                                Lingua
                                <select name="guest_language">
                                    <?php foreach ($languageOptions as $value => $label): ?>
                                        <option value="<?= e($value) ?>"><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="full">
                                Note
                                <textarea name="notes" placeholder="Annotazioni utili sull'ospite, recapiti alternativi, dettagli utili..."></textarea>
                            </label>
                        </div>
                        <div class="clienti-modal-footer">
                            <div class="clienti-modal-note">Le modifiche effettuate qui salvano l'anagrafica nel database. Per aggiornare in massa i clienti già presenti nelle prenotazioni, usa il pulsante di sincronizzazione nella sezione archivio.</div>
                            <div class="clienti-toolbar-group">
                                <button class="btn btn-light" type="button" data-close-cliente-modal>Annulla</button>
                                <button class="btn btn-primary" type="submit">Salva cliente</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <section class="card clienti-table-card clienti-table">
            <div class="section-title">
                <div>
                    <h2>Archivio clienti</h2>
                    <p class="muted">Ricerca veloce, modifica inline, cancellazione diretta e panoramica immediata delle anagrafiche collegate alle prenotazioni.</p>
                </div>
                <div class="clienti-toolbar">
                    <form class="clienti-search-form" method="get" action="<?= e(admin_url('clienti.php')) ?>">
                        <input class="search-input" type="search" name="q" value="<?= e($search) ?>" placeholder="Cerca per nome, cognome, email o cellulare...">
                    </form>
                    <div class="clienti-toolbar-group">
                        <button class="btn btn-primary clienti-modal-trigger" type="button" data-open-cliente-modal>
                            <span>Nuovo cliente</span>
                        </button>
                        <form method="post" action="<?= e(admin_url('actions/sync-clienti-from-prenotazioni.php')) ?>" onsubmit="return confirm('Vuoi sincronizzare lo storico clienti partendo dalle prenotazioni registrate?');">
                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                            <button class="btn btn-light" type="submit">Sincronizza da prenotazioni</button>
                        </form>
                        <?php if ($search !== ''): ?>
                            <a class="btn btn-light" href="<?= e(admin_url('clienti.php')) ?>">Reset ricerca</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if (empty($clienti)): ?>
                <div class="clienti-empty-state">
                    <strong>Nessun cliente trovato.</strong>
                    <p style="margin-top:8px;">Usa il pulsante <strong>Nuovo cliente</strong> per inserire un'anagrafica manualmente oppure sincronizza lo storico dalle prenotazioni già registrate.</p>
                </div>
            <?php else: ?>
                <div class="clienti-mobile-list">
                    <?php foreach ($clienti as $cliente): ?>
                        <?php
                            $clienteId = (int) $cliente['id'];
                            $clienteCountry = strtolower(trim((string) ($cliente['guest_country_code'] ?? '')));
                            $clienteLanguage = trim((string) ($cliente['guest_language'] ?? ''));
                            $bookingsCount = (int) ($cliente['bookings_count'] ?? 0);
                        ?>
                        <article class="clienti-mobile-card">
                            <div class="clienti-mobile-head">
                                <div class="clienti-mobile-head-copy">
                                    <h3><?= e(trim((string) (($cliente['first_name'] ?? '') . ' ' . ($cliente['last_name'] ?? '')))) ?></h3>
                                    <div class="clienti-mobile-badges">
                                        <span class="clienti-stat-badge"><?= $bookingsCount ?> prenotazioni</span>
                                        <span class="small muted">ID #<?= $clienteId ?></span>
                                        <?php if ($clienteCountry !== ''): ?>
                                            <span class="fi fi-<?= e($clienteCountry) ?> clienti-flag" title="<?= e($clienteLanguage !== '' ? $clienteLanguage : strtoupper($clienteCountry)) ?>"></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <form id="cliente-mobile-form-<?= $clienteId ?>" method="post" action="<?= e(admin_url('actions/update-cliente.php')) ?>">
                                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="cliente_id" value="<?= $clienteId ?>">
                                <div class="clienti-mobile-grid">
                                    <label class="clienti-mobile-field">
                                        <span>Nome</span>
                                        <input type="text" name="first_name" value="<?= e((string) ($cliente['first_name'] ?? '')) ?>" required>
                                    </label>
                                    <label class="clienti-mobile-field">
                                        <span>Cognome</span>
                                        <input type="text" name="last_name" value="<?= e((string) ($cliente['last_name'] ?? '')) ?>" placeholder="Cognome">
                                    </label>
                                    <label class="clienti-mobile-field">
                                        <span>Email</span>
                                        <input type="email" name="email" value="<?= e((string) ($cliente['email'] ?? '')) ?>" placeholder="Email non disponibile">
                                    </label>
                                    <label class="clienti-mobile-field">
                                        <span>Cellulare</span>
                                        <input type="text" name="phone" value="<?= e((string) ($cliente['phone'] ?? '')) ?>" placeholder="Cellulare non disponibile">
                                    </label>
                                    <label class="clienti-mobile-field">
                                        <span>Nazionalità</span>
                                        <select name="guest_country_code">
                                            <?php foreach ($countryOptions as $value => $label): ?>
                                                <option value="<?= e($value) ?>" <?= $clienteCountry === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label class="clienti-mobile-field">
                                        <span>Lingua</span>
                                        <select name="guest_language">
                                            <?php foreach ($languageOptions as $value => $label): ?>
                                                <option value="<?= e($value) ?>" <?= $clienteLanguage === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                </div>
                                <label class="clienti-mobile-field">
                                    <span>Note</span>
                                    <textarea name="notes" placeholder="Note cliente..."><?= e((string) ($cliente['notes'] ?? '')) ?></textarea>
                                </label>
                            </form>

                            <div class="clienti-mobile-meta">
                                <strong><?= $bookingsCount ?> prenotazioni collegate</strong>
                                <span class="small muted">Ultima attività: <?= e((string) (($cliente['last_booking_created_at'] ?? '') !== '' ? $cliente['last_booking_created_at'] : $cliente['updated_at'])) ?></span>
                                <span class="clienti-pill"><?= e((string) ($cliente['source'] ?? 'manual_admin')) ?></span>
                            </div>
                            <div class="clienti-mobile-actions">
                                <button class="btn btn-primary" type="submit" form="cliente-mobile-form-<?= $clienteId ?>">Salva modifiche</button>
                                <form method="post" action="<?= e(admin_url('actions/delete-cliente.php')) ?>" onsubmit="return confirm('Vuoi davvero cancellare questo cliente? Le prenotazioni collegate resteranno in archivio ma saranno scollegate dal cliente.');">
                                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="cliente_id" value="<?= $clienteId ?>">
                                    <button class="btn btn-danger" type="submit">Cancella</button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th>Contatti</th>
                                <th>Nazionalità</th>
                                <th>Storico prenotazioni</th>
                                <th>Note</th>
                                <th>Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($clienti as $cliente): ?>
                                <?php
                                    $clienteId = (int) $cliente['id'];
                                    $clienteCountry = strtolower(trim((string) ($cliente['guest_country_code'] ?? '')));
                                    $clienteLanguage = trim((string) ($cliente['guest_language'] ?? ''));
                                    $bookingsCount = (int) ($cliente['bookings_count'] ?? 0);
                                ?>
                                <tr>
                                    <td>
                                        <div class="clienti-name-cell">
                                            <input type="text" name="first_name" value="<?= e((string) ($cliente['first_name'] ?? '')) ?>" form="cliente-form-<?= $clienteId ?>" required>
                                            <input type="text" name="last_name" value="<?= e((string) ($cliente['last_name'] ?? '')) ?>" form="cliente-form-<?= $clienteId ?>" placeholder="Cognome">
                                            <div class="clienti-meta">
                                                <span class="clienti-stat-badge"><?= $bookingsCount ?> prenotazioni</span>
                                                <span class="small muted">ID cliente #<?= $clienteId ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="clienti-summary">
                                            <input type="email" name="email" value="<?= e((string) ($cliente['email'] ?? '')) ?>" form="cliente-form-<?= $clienteId ?>" placeholder="Email non disponibile">
                                            <input type="text" name="phone" value="<?= e((string) ($cliente['phone'] ?? '')) ?>" form="cliente-form-<?= $clienteId ?>" placeholder="Cellulare non disponibile">
                                        </div>
                                    </td>
                                    <td>
                                        <div class="clienti-summary">
                                            <div class="clienti-meta">
                                                <?php if ($clienteCountry !== ''): ?>
                                                    <span class="fi fi-<?= e($clienteCountry) ?> clienti-flag" title="<?= e($clienteLanguage !== '' ? $clienteLanguage : strtoupper($clienteCountry)) ?>"></span>
                                                <?php endif; ?>
                                                <select name="guest_country_code" form="cliente-form-<?= $clienteId ?>">
                                                    <?php foreach ($countryOptions as $value => $label): ?>
                                                        <option value="<?= e($value) ?>" <?= $clienteCountry === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <select name="guest_language" form="cliente-form-<?= $clienteId ?>">
                                                <?php foreach ($languageOptions as $value => $label): ?>
                                                    <option value="<?= e($value) ?>" <?= $clienteLanguage === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="clienti-summary">
                                            <strong><?= $bookingsCount ?> prenotazioni collegate</strong>
                                            <span class="small muted">Ultima attività: <?= e((string) (($cliente['last_booking_created_at'] ?? '') !== '' ? $cliente['last_booking_created_at'] : $cliente['updated_at'])) ?></span>
                                            <span class="clienti-pill"><?= e((string) ($cliente['source'] ?? 'manual_admin')) ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <textarea name="notes" form="cliente-form-<?= $clienteId ?>" placeholder="Note cliente..."><?= e((string) ($cliente['notes'] ?? '')) ?></textarea>
                                    </td>
                                    <td>
                                        <div class="clienti-actions">
                                            <button class="btn btn-primary btn-sm" type="submit" form="cliente-form-<?= $clienteId ?>">Salva modifiche</button>
                                            <form method="post" action="<?= e(admin_url('actions/delete-cliente.php')) ?>" onsubmit="return confirm('Vuoi davvero cancellare questo cliente? Le prenotazioni collegate resteranno in archivio ma saranno scollegate dal cliente.');">
                                                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="cliente_id" value="<?= $clienteId ?>">
                                                <button class="btn btn-danger btn-sm" type="submit">Cancella</button>
                                            </form>
                                        </div>
                                        <form id="cliente-form-<?= $clienteId ?>" method="post" action="<?= e(admin_url('actions/update-cliente.php')) ?>">
                                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="cliente_id" value="<?= $clienteId ?>">
                                        </form>
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
    var modal = document.getElementById('clienteCreateModal');
    if (!modal) {
        return;
    }

    var openButtons = document.querySelectorAll('[data-open-cliente-modal]');
    var closeButtons = document.querySelectorAll('[data-close-cliente-modal]');
    var firstField = modal.querySelector('[data-cliente-modal-first-field]');

    function openModal() {
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('clienti-modal-open-lock');
        if (firstField) {
            setTimeout(function () {
                firstField.focus();
            }, 120);
        }
    }

    function closeModal() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('clienti-modal-open-lock');
    }

    for (var i = 0; i < openButtons.length; i++) {
        openButtons[i].addEventListener('click', openModal);
    }

    for (var j = 0; j < closeButtons.length; j++) {
        closeButtons[j].addEventListener('click', closeModal);
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' || event.keyCode === 27) {
            if (modal.classList.contains('is-open')) {
                closeModal();
            }
        }
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
