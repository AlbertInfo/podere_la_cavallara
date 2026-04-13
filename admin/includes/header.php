<?php
$flash = function_exists('get_flash') ? get_flash() : null;
$currentAdmin = function_exists('current_admin') ? current_admin() : null;
$pageTitle = $pageTitle ?? ADMIN_APP_NAME;
$currentPath = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');

function admin_nav_active(array $targets, string $currentPath): string
{
    return in_array($currentPath, $targets, true) ? ' is-active' : '';
}

$sidebarCounters = [
    'registered_bookings' => 0,
    'booking_requests' => 0,
    'contact_requests' => 0,
    'clienti' => 0,
];

if ($currentAdmin && isset($pdo) && $pdo instanceof PDO) {
    try {
        $sidebarCounters['registered_bookings'] = (int) $pdo->query('SELECT COUNT(*) FROM prenotazioni')->fetchColumn();
        $sidebarCounters['booking_requests'] = (int) $pdo->query('SELECT COUNT(*) FROM booking_requests')->fetchColumn();
        $sidebarCounters['contact_requests'] = (int) $pdo->query('SELECT COUNT(*) FROM contact_requests')->fetchColumn();

        try {
            $sidebarCounters['clienti'] = (int) $pdo->query('SELECT COUNT(*) FROM clienti')->fetchColumn();
        } catch (Throwable $e) {
            $sidebarCounters['clienti'] = 0;
        }
    } catch (Throwable $e) {
        $sidebarCounters = [
            'registered_bookings' => 0,
            'booking_requests' => 0,
            'contact_requests' => 0,
            'clienti' => 0,
        ];
    }
}

$mobilePageMeta = [
    'index.php' => [
        'title' => 'Dashboard',
        'subtitle' => 'Panoramica rapida di prenotazioni, richieste e attività.',
    ],
    'clienti.php' => [
        'title' => 'Clienti',
        'subtitle' => 'Storico ospiti, contatti e anagrafiche sempre accessibili.',
    ],
    'new-prenotazione.php' => [
        'title' => 'Nuova prenotazione',
        'subtitle' => 'Inserisci una nuova prenotazione in modo chiaro e veloce.',
    ],
    'edit-prenotazione.php' => [
        'title' => 'Modifica prenotazione',
        'subtitle' => 'Aggiorna i dettagli della prenotazione senza cambiare il flusso operativo.',
    ],
    'import-interhome-pdf.php' => [
        'title' => 'Import PDF',
        'subtitle' => 'Carica, controlla e importa le prenotazioni Interhome.',
    ],
    'import-interhome-review.php' => [
        'title' => 'Verifica import',
        'subtitle' => 'Controlla i dati estratti prima di confermare l\'inserimento.',
    ],
    'file-manager.php' => [
        'title' => 'Archivio PDF',
        'subtitle' => 'Gestisci i PDF importati in modo ordinato e immediato.',
    ],
];

$mobilePageTitle = isset($mobilePageMeta[$currentPath]['title']) ? $mobilePageMeta[$currentPath]['title'] : 'Area admin';
$mobilePageSubtitle = isset($mobilePageMeta[$currentPath]['subtitle']) ? $mobilePageMeta[$currentPath]['subtitle'] : 'Gestione rapida del gestionale da mobile.';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#0f172a">
    <title><?= e($pageTitle) ?></title>
    <link rel="stylesheet" href="/admin/assets/css/admin-modern.css?v=41">
    <link rel="stylesheet" href="/admin/assets/css/interhome-import.css?v=95">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/flag-icon-css/7.5.0/css/flag-icons.min.css">
    <link rel="shortcut icon" href="<?= e(admin_url('assets/img/favicon.ico')) ?>" type="image/x-icon">
</head>
<body data-page="<?= e($currentPath) ?>">
    <div class="admin-app<?= $currentAdmin ? ' has-sidebar' : ' auth-layout' ?>">
        <?php if ($currentAdmin): ?>
            <div class="mobile-sidebar-backdrop" id="mobileSidebarBackdrop"></div>
            <aside class="admin-sidebar" id="adminSidebar">
                <div class="sidebar-mobile-top">
                    <a class="sidebar-brand" href="<?= e(admin_url('index.php')) ?>">
                        <img class="sidebar-logo sidebar-logo-desktop" src="<?= e(admin_url('assets/img/logo.svg')) ?>" alt="Podere La Cavallara">
                        <img class="sidebar-logo sidebar-logo-mobile" src="<?= e(admin_url('assets/img/logo.svg')) ?>" alt="Podere La Cavallara">
                    </a>
                    <button class="sidebar-close" type="button" id="mobileSidebarClose" aria-label="Chiudi menu">
                        <span></span>
                        <span></span>
                    </button>
                </div>

                <div class="sidebar-mobile-hero">
                    <span class="sidebar-mobile-kicker">Area amministrazione</span>
                    <strong><?= e($currentAdmin['name'] ?? $currentAdmin['email'] ?? 'Admin') ?></strong>
                    <span>Accesso rapido alle sezioni principali del gestionale.</span>
                </div>

                <nav class="sidebar-nav" aria-label="Menu area admin">
                    <a class="sidebar-link<?= admin_nav_active(['index.php'], $currentPath) ?>" href="<?= e(admin_url('index.php#overview')) ?>">
                        <span class="sidebar-link__content">Dashboard</span>
                    </a>

                    <a class="sidebar-link<?= admin_nav_active(['index.php'], $currentPath) ?>" href="<?= e(admin_url('index.php#registered-bookings')) ?>">
                        <span class="sidebar-link__content">Prenotazioni registrate</span>
                        <span class="sidebar-counter"><?= (int) $sidebarCounters['registered_bookings'] ?></span>
                    </a>

                    <a class="sidebar-link<?= admin_nav_active(['clienti.php'], $currentPath) ?>" href="<?= e(admin_url('clienti.php')) ?>">
                        <span class="sidebar-link__content">Storico clienti</span>
                        <span class="sidebar-counter"><?= (int) $sidebarCounters['clienti'] ?></span>
                    </a>

                    <a class="sidebar-link<?= admin_nav_active(['new-prenotazione.php'], $currentPath) ?>" href="<?= e(admin_url('new-prenotazione.php')) ?>">
                        <span class="sidebar-link__content">Nuova prenotazione</span>
                    </a>

                    <a class="sidebar-link<?= admin_nav_active(['import-interhome-pdf.php', 'import-interhome-review.php'], $currentPath) ?>" href="<?= e(admin_url('import-interhome-pdf.php')) ?>">
                        <span class="sidebar-link__content">Importa PDF Interhome</span>
                    </a>

                    <a class="sidebar-link<?= admin_nav_active(['file-manager.php'], $currentPath) ?>" href="<?= e(admin_url('file-manager.php')) ?>">
                        <span class="sidebar-link__content">Archivio PDF</span>
                    </a>

                    <a class="sidebar-link<?= admin_nav_active(['index.php'], $currentPath) ?>" href="<?= e(admin_url('index.php#booking-requests')) ?>">
                        <span class="sidebar-link__content">Richieste prenotazione</span>
                        <?php if ($sidebarCounters['booking_requests'] > 0): ?>
                            <span class="sidebar-counter sidebar-counter--alert"><?= (int) $sidebarCounters['booking_requests'] ?></span>
                        <?php endif; ?>
                    </a>

                    <a class="sidebar-link<?= admin_nav_active(['index.php'], $currentPath) ?>" href="<?= e(admin_url('index.php#contact-requests')) ?>">
                        <span class="sidebar-link__content">Richieste contatto</span>
                        <?php if ($sidebarCounters['contact_requests'] > 0): ?>
                            <span class="sidebar-counter sidebar-counter--alert"><?= (int) $sidebarCounters['contact_requests'] ?></span>
                        <?php endif; ?>
                    </a>
                </nav>

                <div class="sidebar-footer">
                    <div class="sidebar-user">
                        <span class="sidebar-user-label">Connesso come</span>
                        <strong><?= e($currentAdmin['name'] ?? $currentAdmin['email'] ?? 'Admin') ?></strong>
                    </div>
                    <a class="btn btn-light btn-full" href="<?= e(admin_url('logout.php')) ?>">Logout</a>
                </div>
            </aside>
        <?php endif; ?>

        <div class="admin-main">
            <?php if ($currentAdmin): ?>
                <header class="admin-topbar">
                    <div class="topbar-main-row">
                        <button class="mobile-menu-toggle" type="button" id="mobileMenuToggle" aria-controls="adminSidebar" aria-expanded="false" aria-label="Apri menu">
                            <span></span>
                            <span></span>
                            <span></span>
                        </button>
                        <div class="topbar-page-meta">
                            <span class="topbar-page-kicker">Podere La Cavallara</span>
                            <strong><?= e($mobilePageTitle) ?></strong>
                        </div>
                        <div class="topbar-actions">
                            <a class="btn btn-light btn-sm topbar-action-desktop" href="<?= e(admin_url('clienti.php')) ?>">Clienti</a>
                            <a class="btn btn-light btn-sm topbar-action-desktop" href="<?= e(admin_url('file-manager.php')) ?>">Archivio PDF</a>
                            <a class="btn btn-light btn-sm topbar-action-desktop" href="<?= e(admin_url('import-interhome-pdf.php')) ?>">Importa PDF</a>
                            <a class="btn btn-primary btn-sm topbar-action-desktop" href="<?= e(admin_url('new-prenotazione.php')) ?>">Nuova prenotazione</a>
                        </div>
                    </div>
                    <div class="topbar-mobile-hero">
                        <div>
                            <h1><?= e($mobilePageTitle) ?></h1>
                            <p><?= e($mobilePageSubtitle) ?></p>
                        </div>
                        <div class="topbar-mobile-pills">
                            <a class="topbar-pill" href="<?= e(admin_url('new-prenotazione.php')) ?>">Nuova</a>
                            <a class="topbar-pill topbar-pill--ghost" href="<?= e(admin_url('index.php#registered-bookings')) ?>">Prenotazioni</a>
                        </div>
                    </div>
                </header>
            <?php endif; ?>

            <main class="admin-content">
                <?php if ($flash): ?>
                    <div class="flash <?= e($flash['type']) ?>">
                        <?= e($flash['message']) ?>
                    </div>
                <?php endif; ?>
