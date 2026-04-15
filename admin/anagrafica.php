<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/customer-sync.php';

require_admin();

$pageTitle = 'Sezione anagrafica | ' . ADMIN_APP_NAME;
$anagraficaTableReady = customer_sync_table_exists($pdo);

$stats = [
    'total' => 0,
    'with_email' => 0,
    'with_phone' => 0,
    'international' => 0,
];
$recentGuests = [];

if ($anagraficaTableReady) {
    try {
        $stats = [
            'total' => (int) $pdo->query('SELECT COUNT(*) FROM clienti')->fetchColumn(),
            'with_email' => (int) $pdo->query("SELECT COUNT(*) FROM clienti WHERE email IS NOT NULL AND TRIM(email) <> ''")->fetchColumn(),
            'with_phone' => (int) $pdo->query("SELECT COUNT(*) FROM clienti WHERE phone IS NOT NULL AND TRIM(phone) <> ''")->fetchColumn(),
            'international' => (int) $pdo->query("SELECT COUNT(*) FROM clienti WHERE guest_country_code IS NOT NULL AND TRIM(guest_country_code) <> '' AND UPPER(guest_country_code) <> 'IT'")->fetchColumn(),
        ];

        $stmt = $pdo->query('SELECT id, first_name, last_name, email, phone, guest_country_code, guest_language, updated_at FROM clienti ORDER BY updated_at DESC, id DESC LIMIT 6');
        $recentGuests = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $stats = [
            'total' => 0,
            'with_email' => 0,
            'with_phone' => 0,
            'international' => 0,
        ];
        $recentGuests = [];
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="anagrafica-shell">
    <section class="anagrafica-hero">
        <div class="anagrafica-hero__copy">
            <span class="anagrafica-eyebrow">Nuovo modulo operativo</span>
            <h1>Sezione anagrafica</h1>
            <p>Uno spazio separato dalla logica amministrativa per gestire ospiti, dati documento e, nei prossimi step, caricamento immagini e scansione guidata.</p>
        </div>
        <div class="anagrafica-hero__actions">
            <a class="btn btn-light" href="<?= e(admin_url('clienti.php')) ?>">Apri storico clienti</a>
            <a class="btn btn-primary" href="#aggiungi-ospite">Aggiungi ospite</a>
        </div>
    </section>

    <nav class="anagrafica-subnav" aria-label="Sezioni area anagrafica">
        <a class="anagrafica-subnav__link is-active" href="#aggiungi-ospite">Aggiungi ospite</a>
        <a class="anagrafica-subnav__link is-disabled" href="#" aria-disabled="true">Upload documento <span>prossimo step</span></a>
        <a class="anagrafica-subnav__link is-disabled" href="#" aria-disabled="true">Scansione immagine <span>prossimo step</span></a>
    </nav>

    <section class="anagrafica-kpis" aria-label="Panoramica rapida sezione anagrafica">
        <article class="anagrafica-kpi-card">
            <span class="anagrafica-kpi-card__label">Ospiti in archivio</span>
            <strong class="anagrafica-kpi-card__value"><?= (int) $stats['total'] ?></strong>
            <span class="anagrafica-kpi-card__meta">Base dati disponibile per i prossimi export.</span>
        </article>
        <article class="anagrafica-kpi-card">
            <span class="anagrafica-kpi-card__label">Con email</span>
            <strong class="anagrafica-kpi-card__value"><?= (int) $stats['with_email'] ?></strong>
            <span class="anagrafica-kpi-card__meta">Contatti pronti per follow-up e comunicazioni.</span>
        </article>
        <article class="anagrafica-kpi-card">
            <span class="anagrafica-kpi-card__label">Con cellulare</span>
            <strong class="anagrafica-kpi-card__value"><?= (int) $stats['with_phone'] ?></strong>
            <span class="anagrafica-kpi-card__meta">Numeri già disponibili in anagrafica.</span>
        </article>
        <article class="anagrafica-kpi-card">
            <span class="anagrafica-kpi-card__label">Ospiti internazionali</span>
            <strong class="anagrafica-kpi-card__value"><?= (int) $stats['international'] ?></strong>
            <span class="anagrafica-kpi-card__meta">Indicatore utile in vista della compliance.</span>
        </article>
    </section>

    <div class="anagrafica-grid">
        <section class="anagrafica-panel anagrafica-panel--primary" id="aggiungi-ospite">
            <div class="anagrafica-panel__header">
                <div>
                    <span class="anagrafica-panel__kicker">Step 01</span>
                    <h2>Aggiungi ospite</h2>
                    <p>Creiamo da qui la base operativa del modulo. In questo form inseriremo poi upload documento, parsing OCR e compilazione assistita.</p>
                </div>
                <span class="anagrafica-status-pill">Pronto per evolvere</span>
            </div>

            <?php if (!$anagraficaTableReady): ?>
                <div class="anagrafica-empty-state">
                    <h3>Tabella clienti non ancora attivata</h3>
                    <p>Esegui prima la migration già prevista per lo storico clienti. Dopo l'attivazione il form salverà subito l'ospite nel database.</p>
                    <code>2026-04-10_migration_customer_history.sql</code>
                </div>
            <?php else: ?>
                <form class="anagrafica-form" method="post" action="<?= e(admin_url('actions/create-anagrafica-ospite.php')) ?>" novalidate>
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <div class="anagrafica-form__grid">
                        <label>
                            <span>Nome *</span>
                            <input type="text" name="first_name" required autocomplete="given-name" placeholder="Es. Mario">
                        </label>
                        <label>
                            <span>Cognome *</span>
                            <input type="text" name="last_name" required autocomplete="family-name" placeholder="Es. Rossi">
                        </label>
                        <label>
                            <span>Email</span>
                            <input type="email" name="email" autocomplete="email" placeholder="nome@dominio.it">
                        </label>
                        <label>
                            <span>Telefono</span>
                            <input type="text" name="phone" autocomplete="tel" placeholder="Es. +39 333 1234567">
                        </label>
                        <label>
                            <span>Paese ospite</span>
                            <input type="text" name="guest_country_code" maxlength="2" autocomplete="country" placeholder="IT" data-country-code-input>
                        </label>
                        <label>
                            <span>Lingua preferita</span>
                            <input type="text" name="guest_language" maxlength="10" placeholder="it, en, de...">
                        </label>
                        <label class="anagrafica-form__full">
                            <span>Note operative</span>
                            <textarea name="notes" rows="5" placeholder="Campo pronto per appunti interni, dettagli di accoglienza o note utili in vista dei prossimi workflow."></textarea>
                        </label>
                    </div>

                    <div class="anagrafica-form__footer">
                        <p>Nel prossimo step qui aggiungeremo caricamento fronte/retro documento, acquisizione da fotocamera e popolamento automatico dei campi.</p>
                        <div class="anagrafica-form__actions">
                            <button class="btn btn-light" type="reset" data-anagrafica-reset>Reset</button>
                            <button class="btn btn-primary" type="submit">Salva ospite</button>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </section>

        <aside class="anagrafica-side-stack">
            <section class="anagrafica-panel anagrafica-panel--secondary">
                <div class="anagrafica-panel__header compact">
                    <div>
                        <span class="anagrafica-panel__kicker">Architettura</span>
                        <h2>Direzione del modulo</h2>
                    </div>
                </div>
                <ul class="anagrafica-checklist">
                    <li>Dati ospite separati dalla logica commerciale delle prenotazioni</li>
                    <li>Form progettato per ricevere OCR e scansione immagine nei prossimi step</li>
                    <li>Base pronta per file ufficiali ROSS1000 e Alloggiati Web</li>
                </ul>
            </section>

            <section class="anagrafica-panel anagrafica-panel--secondary">
                <div class="anagrafica-panel__header compact">
                    <div>
                        <span class="anagrafica-panel__kicker">Ultimi inserimenti</span>
                        <h2>Ospiti recenti</h2>
                    </div>
                    <a class="anagrafica-inline-link" href="<?= e(admin_url('clienti.php')) ?>">Vedi storico completo</a>
                </div>

                <?php if ($recentGuests === []): ?>
                    <div class="anagrafica-empty-state small">
                        <p>Nessuna anagrafica recente disponibile.</p>
                    </div>
                <?php else: ?>
                    <div class="anagrafica-recent-list">
                        <?php foreach ($recentGuests as $guest): ?>
                            <article class="anagrafica-recent-item">
                                <div class="anagrafica-recent-item__identity">
                                    <strong><?= e(trim(((string) ($guest['first_name'] ?? '')) . ' ' . ((string) ($guest['last_name'] ?? '')))) ?></strong>
                                    <span><?= e((string) ($guest['email'] ?: $guest['phone'] ?: 'Contatto non disponibile')) ?></span>
                                </div>
                                <div class="anagrafica-recent-item__meta">
                                    <span class="anagrafica-mini-pill"><?= e((string) (($guest['guest_country_code'] ?: '—'))) ?></span>
                                    <time datetime="<?= e((string) ($guest['updated_at'] ?? '')) ?>"><?= e((string) date('d/m/Y', strtotime((string) ($guest['updated_at'] ?? 'now')))) ?></time>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </aside>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
