<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/customer-sync.php';
require_admin();
verify_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . admin_url('clienti.php'));
    exit;
}

$clienteId = (int) ($_POST['cliente_id'] ?? 0);
if ($clienteId <= 0) {
    set_flash('error', 'Cliente non valido.');
    header('Location: ' . admin_url('clienti.php'));
    exit;
}

$firstName = trim((string) ($_POST['first_name'] ?? ''));
$lastName = trim((string) ($_POST['last_name'] ?? ''));
$emailInput = trim((string) ($_POST['email'] ?? ''));
$phoneInput = trim((string) ($_POST['phone'] ?? ''));
$countryCode = normalize_country_code((string) ($_POST['guest_country_code'] ?? ''));
$guestLanguage = trim((string) ($_POST['guest_language'] ?? ''));
$notes = trim((string) ($_POST['notes'] ?? ''));

if ($firstName === '' && $lastName === '') {
    set_flash('error', 'Inserisci almeno il nome o il cognome del cliente.');
    header('Location: ' . admin_url('clienti.php'));
    exit;
}

$email = normalize_optional_email($emailInput);
if ($emailInput !== '' && $email === null) {
    set_flash('error', 'Inserisci un indirizzo email valido oppure lascia il campo vuoto.');
    header('Location: ' . admin_url('clienti.php'));
    exit;
}

$phone = normalize_optional_phone($phoneInput);
$phoneNormalized = customer_sync_phone_key($phone);

$stmt = $pdo->prepare('UPDATE clienti SET
    first_name = :first_name,
    last_name = :last_name,
    email = :email,
    phone = :phone,
    phone_normalized = :phone_normalized,
    guest_country_code = :guest_country_code,
    guest_language = :guest_language,
    notes = :notes,
    updated_at = NOW()
WHERE id = :id LIMIT 1');

$stmt->execute([
    'first_name' => $firstName,
    'last_name' => $lastName,
    'email' => $email,
    'phone' => $phone,
    'phone_normalized' => $phoneNormalized,
    'guest_country_code' => $countryCode,
    'guest_language' => $guestLanguage !== '' ? $guestLanguage : null,
    'notes' => $notes !== '' ? $notes : null,
    'id' => $clienteId,
]);

set_flash('success', 'Cliente aggiornato correttamente.');
header('Location: ' . admin_url('clienti.php'));
exit;
