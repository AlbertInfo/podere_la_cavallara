<?php
$flash = function_exists('get_flash') ? get_flash() : null;
$currentAdmin = function_exists('current_admin') ? current_admin() : null;
$pageTitle = $pageTitle ?? ADMIN_APP_NAME;
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= e(admin_url('assets/css/admin-modern.css')) ?>">
    <link rel="shortcut icon" href="assets/img/favicon.ico" type="image/x-icon">
</head>
<body>
<div class="admin-shell">
    <header class="admin-topbar">
        <a class="brand" href="<?= e(admin_url('index.php')) ?>">
            <img class="logo-desktop" src="<?= e(admin_url('assets/img/logo_sticky.png')) ?>" alt="Podere La Cavallara logo">
            <img class="logo-mobile" src="<?= e(admin_url('assets/img/logo_mobile.png')) ?>" alt="Podere La Cavallara logo" style="display:none;">
            <!-- <div class="brand-copy">
                <strong>Podere La Cavallara</strong>
                <span>Admin dashboard</span>
            </div> -->
        </a>

        <?php if ($currentAdmin): ?>
            <nav class="topbar-actions" aria-label="Navigazione admin">
                <a class="btn btn-light" href="<?= e(admin_url('index.php#booking-requests')) ?>">Richieste</a>
                <a class="btn btn-light" href="<?= e(admin_url('index.php#registered-bookings')) ?>">Prenotazioni</a>
                <a class="btn btn-primary" href="<?= e(admin_url('new-prenotazione.php')) ?>">Nuova prenotazione</a>
                <a class="btn btn-light" href="<?= e(admin_url('logout.php')) ?>">Esci</a>
            </nav>
        <?php endif; ?>
    </header>

    <div class="admin-wrap">
        <?php if ($flash): ?>
            <div class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
        <?php endif; ?>
