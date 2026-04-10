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
];

if ($currentAdmin && isset($pdo) && $pdo instanceof PDO) {
    try {
        $sidebarCounters['registered_bookings'] = (int) $pdo->query('SELECT COUNT(*) FROM prenotazioni')->fetchColumn();
        $sidebarCounters['booking_requests'] = (int) $pdo->query('SELECT COUNT(*) FROM booking_requests')->fetchColumn();
        $sidebarCounters['contact_requests'] = (int) $pdo->query('SELECT COUNT(*) FROM contact_requests')->fetchColumn();
    } catch (Throwable $e) {
        $sidebarCounters = [
            'registered_bookings' => 0,
            'booking_requests' => 0,
            'contact_requests' => 0,
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    <link rel="stylesheet" href="/admin/assets/css/admin-modern.css?v=34">
    <link rel="stylesheet" href="/admin/assets/css/interhome-import.css?v=95">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/flag-icon-css/7.5.0/css/flag-icons.min.css">
    <link rel="shortcut icon" href="<?= e(admin_url('assets/img/favicon.ico')) ?>" type="image/x-icon">
</head>
<body>
    <div class="admin-app<?= $currentAdmin ? ' has-sidebar' : ' auth-layout' ?>">
        <?php if ($currentAdmin): ?>
            <aside class="admin-sidebar" id="adminSidebar">
                <a class="sidebar-brand" href="<?= e(admin_url('index.php')) ?>">
                    <img class="sidebar-logo sidebar-logo-desktop" src="<?= e(admin_url('assets/img/logo.svg')) ?>" alt="Podere La Cavallara">
                    <img class="sidebar-logo sidebar-logo-mobile" src="<?= e(admin_url('assets/img/logo.svg')) ?>" alt="Podere La Cavallara">
                </a>

                <nav class="sidebar-nav" aria-label="Menu area admin">
                    <a class="sidebar-link<?= admin_nav_active(['index.php'], $currentPath) ?>" href="<?= e(admin_url('index.php#overview')) ?>">
                        <span class="sidebar-link__content">Dashboard</span>
                    </a>

                    <a class="sidebar-link<?= admin_nav_active(['index.php'], $currentPath) ?>" href="<?= e(admin_url('index.php#registered-bookings')) ?>">
                        <span class="sidebar-link__content">Prenotazioni registrate</span>
                        <span class="sidebar-counter"><?= (int) $sidebarCounters['registered_bookings'] ?></span>
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
                    <button class="mobile-menu-toggle" type="button" id="mobileMenuToggle" aria-controls="adminSidebar" aria-expanded="false">
                        <span></span>
                        <span></span>
                        <span></span>
                    </button>
                    <a class="topbar-brand" href="<?= e(admin_url('index.php')) ?>">
                        <img src="<?= e(admin_url('assets/img/logo.svg')) ?>" alt="Podere La Cavallara">
                    </a>
                    <div class="topbar-actions">
                        <a class="btn btn-light btn-sm" href="<?= e(admin_url('file-manager.php')) ?>">Archivio PDF</a>
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
