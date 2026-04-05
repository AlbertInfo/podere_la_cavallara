<?php
$flash = function_exists('get_flash') ? get_flash() : null;
$currentAdmin = function_exists('current_admin') ? current_admin() : null;
$pageTitle = $pageTitle ?? ADMIN_APP_NAME;
$currentPath = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');

function admin_nav_active(array $targets, string $currentPath): string
{
    return in_array($currentPath, $targets, true) ? ' is-active' : '';
}
?>
<!DOCTYPE html>
<html lang="it">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    <link rel="stylesheet" href="/admin/assets/css/admin-modern.css?v=20">
    <link rel="stylesheet" href="/admin/assets/css/interhome-import.css?v=40">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="shortcut icon" href="<?= e(admin_url('assets/img/favicon.ico')) ?>" type="image/x-icon">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://npmcdn.com/flatpickr/dist/l10n/it.js"></script>
</head>

<body>
    <div class="admin-app <?= $currentAdmin ? 'has-sidebar' : 'auth-layout' ?>">
        <?php if ($currentAdmin): ?>
            <aside class="admin-sidebar" id="adminSidebar">
                <a class="sidebar-brand" href="<?= e(admin_url('index.php')) ?>">
                    <img class="sidebar-logo sidebar-logo-desktop" src="<?= e(admin_url('assets/img/logo.svg')) ?>" alt="Podere La Cavallara">
                    <img class="sidebar-logo sidebar-logo-mobile" src="<?= e(admin_url('assets/img/logo_mobile.svg')) ?>" alt="Podere La Cavallara">
                </a>

                <nav class="sidebar-nav" aria-label="Menu area admin">
                    <a class="sidebar-link<?= admin_nav_active(['index.php'], $currentPath) ?>" href="<?= e(admin_url('index.php#overview')) ?>">Dashboard</a>
                    <a class="sidebar-link<?= admin_nav_active(['index.php'], $currentPath) ?>" href="<?= e(admin_url('index.php#registered-bookings')) ?>">Prenotazioni registrate</a>
                    <a class="sidebar-link<?= admin_nav_active(['new-prenotazione.php'], $currentPath) ?>" href="<?= e(admin_url('new-prenotazione.php')) ?>">Nuova prenotazione</a>
                    <a class="sidebar-link<?= admin_nav_active(['import-interhome-pdf.php','import-interhome-review.php'], $currentPath) ?>" href="<?= e(admin_url('import-interhome-pdf.php')) ?>">Importa PDF Interhome</a>
                    <a class="sidebar-link<?= admin_nav_active(['index.php'], $currentPath) ?>" href="<?= e(admin_url('index.php#booking-requests')) ?>">Richieste prenotazione</a>
                    <a class="sidebar-link<?= admin_nav_active(['index.php'], $currentPath) ?>" href="<?= e(admin_url('index.php#contact-requests')) ?>">Richieste contatto</a>
                </nav>

                <div class="sidebar-footer">
                    <div class="sidebar-user">
                        <span class="sidebar-user-label">Connesso come</span>
                        <strong><?= e($currentAdmin['name'] ?? $currentAdmin['email'] ?? 'Admin') ?></strong>
                    </div>
                    <a class="btn btn-light btn-full" href="<?= e(admin_url('logout.php')) ?>">Esci</a>
                </div>
            </aside>
        <?php endif; ?>

        <div class="admin-main">
            <?php if ($currentAdmin): ?>
                <header class="admin-topbar">
                    <button class="mobile-menu-toggle" type="button" id="mobileMenuToggle" aria-controls="adminSidebar" aria-expanded="false">
                        <span></span>
                        <span></span>
                        <span></span>
                    </button>
                    <a class="topbar-brand" href="<?= e(admin_url('index.php')) ?>">
                        <img src="<?= e(admin_url('assets/img/logo_mobile.png')) ?>" alt="Podere La Cavallara">
                    </a>
                    <div class="topbar-actions">
                        <a class="btn btn-light btn-sm" href="<?= e(admin_url('import-interhome-pdf.php')) ?>">Importa PDF</a>
                        <a class="btn btn-primary btn-sm" href="<?= e(admin_url('new-prenotazione.php')) ?>">Nuova prenotazione</a>
                    </div>
                </header>
            <?php endif; ?>

            <main class="admin-content">
                <?php if ($flash): ?>
                    <div class="flash <?= e($flash['type']) ?>">
                        <?= e($flash['message']) ?>
                    </div>
                <?php endif; ?>
