<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/customer-sync.php';

require_admin();
verify_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . admin_url('anagrafica.php#aggiungi-ospite'));
    exit;
}

if (!customer_sync_table_exists($pdo)) {
    set_flash('error', 'Tabella clienti non disponibile. Esegui prima la migration dello storico clienti.');
    header('Location: ' . admin_url('anagrafica.php#aggiungi-ospite'));
    exit;
}

$firstName = trim((string) ($_POST['first_name'] ?? ''));
$lastName = trim((string) ($_POST['last_name'] ?? ''));
$emailInput = trim((string) ($_POST['email'] ?? ''));
$phoneInput = trim((string) ($_POST['phone'] ?? ''));
$countryCode = admin_normalize_country_code((string) ($_POST['guest_country_code'] ?? ''));
$guestLanguage = trim((string) ($_POST['guest_language'] ?? ''));
$notes = trim((string) ($_POST['notes'] ?? ''));

if ($firstName === '' || $lastName === '') {
    set_flash('error', 'Inserisci nome e cognome dell\'ospite per salvare l\'anagrafica.');
    header('Location: ' . admin_url('anagrafica.php#aggiungi-ospite'));
    exit;
}

$email = admin_normalize_optional_email($emailInput);
if ($emailInput !== '' && $email === null) {
    set_flash('error', 'Inserisci un indirizzo email valido oppure lascia il campo vuoto.');
    header('Location: ' . admin_url('anagrafica.php#aggiungi-ospite'));
    exit;
}

$phone = admin_normalize_optional_phone($phoneInput);
$customerId = customer_sync_upsert($pdo, [
    'first_name' => $firstName,
    'last_name' => $lastName,
    'email' => $email,
    'phone' => $phone,
    'guest_country_code' => $countryCode,
    'guest_language' => $guestLanguage,
    'notes' => $notes,
], 'anagrafica_admin');

if ($customerId === null) {
    set_flash('error', 'Non è stato possibile salvare l\'ospite nella sezione anagrafica.');
    header('Location: ' . admin_url('anagrafica.php#aggiungi-ospite'));
    exit;
}

set_flash('success', 'Ospite salvato correttamente nella sezione anagrafica.');
header('Location: ' . admin_url('anagrafica.php#aggiungi-ospite'));
exit;
