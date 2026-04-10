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
                COUNT(p.id) AS bookings_count,
                MAX(p.created_at) AS last_booking_created_at,
                MAX(COALESCE(p.check_out, p.created_at)) AS last_activity_at
            FROM clienti c
            LEFT JOIN prenotazioni p ON p.cliente_id = c.id';
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

    $sql .= ' GROUP BY c.id ORDER BY COALESCE(last_booking_created_at, c.updated_at) DESC, c.updated_at DESC LIMIT 500';
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
.clienti-grid{display:grid;grid-template-columns:minmax(320px,380px) minmax(0,1fr);gap:20px;align-items:start}
.clienti-form-card{border-top:5px solid var(--purple)}
.clienti-table-card{border-top:5px solid var(--primary)}
.clienti-alert-card{padding:22px;border-radius:22px;border:1px solid #fed7aa;background:linear-gradient(180deg,#fff 0%,#fff7ed 100%)}
.clienti-search-form{min-width:280px;flex:1}
.clienti-empty-state{padding:28px;border-radius:22px;border:1px dashed #cbd5e1;background:#fff;text-align:center;color:var(--muted)}
.clienti-table table{min-width:1320px}
.clienti-inline-form{display:contents}
.clienti-name-cell{display:grid;gap:6px}
.clienti-meta{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.clienti-flag{
    display:inline-flex;align-items:center;justify-content:center;
    width:22px;height:16px;border-radius:3px;box-shadow:0 0 0 1px rgba(0,0,0,.08)
}
.clienti-pill{
    display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;
    background:#eef2ff;color:#3730a3;font-size:12px;font-weight:700
}
.clienti-summary{display:grid;gap:6px}
.clienti-summary strong{font-size:14px;color:#0f172a}
.clienti-table td input,
.clienti-table td select,
.clienti-form-card input,
.clienti-form-card select,
.clienti-form-card textarea{min-height:42px;padding:10px 12px;border-radius:14px}
.clienti-table td textarea{min-height:84px;padding:10px 12px;border-radius:14px;resize:vertical}
.clienti-table td{vertical-align:middle}
.clienti-table .actions{min-width:160px}
.clienti-note{font-size:13px;color:var(--muted);line-height:1.5}
.clienti-stat-badge{
    display:inline-flex;align-items:center;padding:7px 11px;border-radius:999px;
    background:#eff6ff;color:#1d4ed8;font-size:12px;font-weight:800
}
@media (max-width:1280px){.clienti-kpi-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media (max-width:1100px){.clienti-grid{grid-template-columns:1fr}.clienti-kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:760px){.clienti-kpi-grid{grid-template-columns:1fr}}
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

        <div class="clienti-grid">
            <section class="card clienti-form-card">
                <div class="section-title">
                    <div>
                        <h2>Nuovo cliente</h2>
                        <p class="muted">Inserisci un'anagrafica manualmente: sarà disponibile subito nello storico clienti.</p>
                    </div>
                </div>

                <form class="booking-form" method="post" action="<?= e(admin_url('actions/create-cliente.php')) ?>">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <div class="booking-form-grid">
                        <label>
                            Nome *
                            <input type="text" name="first_name" required>
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
                    <div class="form-actions">
                        <button class="btn btn-primary" type="submit">Salva cliente</button>
                    </div>
                </form>

                <div class="clienti-note" style="margin-top:14px;">
                    Le modifiche effettuate qui salvano l'anagrafica nel database. Per aggiornare in massa i clienti già presenti nelle prenotazioni, usa il pulsante di sincronizzazione accanto alla tabella.
                </div>
            </section>

            <section class="card clienti-table-card clienti-table">
                <div class="section-title">
                    <div>
                        <h2>Archivio clienti</h2>
                        <p class="muted">Ricerca veloce, modifica inline e panoramica immediata delle anagrafiche collegate alle prenotazioni.</p>
                    </div>
                    <div class="toolbar">
                        <form class="clienti-search-form" method="get" action="<?= e(admin_url('clienti.php')) ?>">
                            <input class="search-input" type="search" name="q" value="<?= e($search) ?>" placeholder="Cerca per nome, cognome, email o cellulare...">
                        </form>
                        <form method="post" action="<?= e(admin_url('actions/sync-clienti-from-prenotazioni.php')) ?>" onsubmit="return confirm('Vuoi sincronizzare lo storico clienti partendo dalle prenotazioni registrate?');">
                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                            <button class="btn btn-light" type="submit">Sincronizza da prenotazioni</button>
                        </form>
                        <?php if ($search !== ''): ?>
                            <a class="btn btn-light" href="<?= e(admin_url('clienti.php')) ?>">Reset ricerca</a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (empty($clienti)): ?>
                    <div class="clienti-empty-state">
                        <strong>Nessun cliente trovato.</strong>
                        <p style="margin-top:8px;">Inserisci un cliente manualmente oppure sincronizza lo storico dalle prenotazioni già registrate.</p>
                    </div>
                <?php else: ?>
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
                                            <div class="actions">
                                                <button class="btn btn-primary btn-sm" type="submit" form="cliente-form-<?= $clienteId ?>">Salva modifiche</button>
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
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
