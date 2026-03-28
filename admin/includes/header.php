<?php
$flash = get_flash();
$admin = current_admin();
?><!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(ADMIN_APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(admin_url('assets/css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(admin_url('assets/css/admin-modern.css')) ?>">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <div class="brand">
            <div><img src="<?= e(admin_url('assets/img/logo.png')) ?>" alt="Podere La Cavallara"></div>
            <div>
                <strong>Admin Dashboard</strong>
                <div class="muted">Podere La Cavallara</div>
            </div>
        </div>
        <?php if ($admin): ?>
        <nav class="nav">
            <a href="#overview">Panoramica</a>
            <a href="#booking-requests">Richieste prenotazione</a>
            <a href="#contact-requests">Richieste contatto</a>
            <a href="#registered-bookings">Prenotazioni registrate</a>
            <a href="#bookingcom">Booking.com</a>
        </nav>
        <div class="sidebar-user">
            <div><strong><?= e($admin['name']) ?></strong></div>
            <div class="muted"><?= e($admin['email']) ?></div>
            <a class="btn btn-outline btn-sm" href="<?= e(admin_url('logout.php')) ?>">Esci</a>
        </div>
        <?php endif; ?>
    </aside>
    <main class="main-content">
        <header class="topbar">
            <div>
                <h1>Dashboard amministrazione</h1>
                <p class="muted">Gestione richieste, contatti e prenotazioni confermate.</p>
            </div>
        </header>
        <?php if ($flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
        <?php endif; ?>
