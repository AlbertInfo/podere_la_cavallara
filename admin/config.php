<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/email.php';

require_once dirname(__DIR__) . '/phpmailer/src/Exception.php';
require_once dirname(__DIR__) . '/phpmailer/src/PHPMailer.php';
require_once dirname(__DIR__) . '/phpmailer/src/SMTP.php';

const ADMIN_APP_NAME = 'Podere La Cavallara | Admin';
const ADMIN_BASE_URL = 'https://palevioletred-fly-568261.hostingersite.com/admin';
const ADMIN_SESSION_KEY = 'admin_user';

const BOOKINGCOM_ENABLED = false;
const BOOKINGCOM_USERNAME = 'MACHINE_ACCOUNT_USERNAME';
const BOOKINGCOM_PASSWORD = 'MACHINE_ACCOUNT_PASSWORD';
const BOOKINGCOM_HOTEL_ID = 'HOTEL_ID';
const BOOKINGCOM_ENDPOINT = 'https://secure-supply-xml.booking.com/hotels/xml/reservations';

function admin_url(string $path = ''): string
{
    return rtrim(ADMIN_BASE_URL, '/') . '/' . ltrim($path, '/');
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['_admin_csrf'])) {
        $_SESSION['_admin_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_admin_csrf'];
}

function verify_csrf(): void
{
    $token = $_POST['_csrf'] ?? '';
    if (!$token || !hash_equals($_SESSION['_admin_csrf'] ?? '', $token)) {
        http_response_code(419);
        exit('Token CSRF non valido.');
    }
}

function set_flash(string $type, string $message): void
{
    $_SESSION['_flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array
{
    $flash = $_SESSION['_flash'] ?? null;
    unset($_SESSION['_flash']);
    return $flash;
}

function wants_json_response(): bool
{
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        return true;
    }
    if (isset($_SERVER['HTTP_ACCEPT']) && str_contains((string) $_SERVER['HTTP_ACCEPT'], 'application/json')) {
        return true;
    }
    return false;
}


const ICAL_ENABLED = true;

const ICAL_FEEDS = [
    [
        'label' => 'Casa Domenico 1',
        'url' => 'https://ws.interhome.com/ih/b2p/v0100/partners/IT04151/objects/IT5606.621.2/ical?hmac=5Zvs/F8EYG2uS1O8+D+Fz3aIQF/ExyIk4zQkcn3L6Lg=&ob=true',
        'source' => 'ical',
    ],
    [
        'label' => 'Casa Domenico 2',
        'url' => 'https://ws.interhome.com/ih/b2p/v0100/partners/IT04151/objects/IT5606.621.1/ical?hmac=UgL+alpyj4Vr0f6dOrquzY2TefJH/qyFQXJAD+t3eaI=&ob=true',
        'source' => 'ical',
    ],
    [
        'label' => 'Casa Riccardo 3',
        'url' => 'https://ws.interhome.com/ih/b2p/v0100/partners/IT04151/objects/IT5606.621.4/ical?hmac=BT6gsYgmHUpfvoxMfaswCL8Uy79VV4iYHlvY36dvrKw=&ob=true',
        'source' => 'ical',
    ],
    [
        'label' => 'Casa Riccardo 4',
        'url' => 'https://ws.interhome.com/ih/b2p/v0100/partners/IT04151/objects/IT5606.621.3/ical?hmac=cEKMJ10FjqpQjC7XPI/bwU24kr6MqIZTpYb9uI2E7JU=&ob=true',
        'source' => 'ical',
    ],
    [
        'label' => 'Casa Alessandro 5',
        'url' => 'https://ws.interhome.com/ih/b2p/v0100/partners/IT04151/objects/IT5606.621.6/ical?hmac=iltEPAReu/VYfMKH0j+MoTxoRSk1kTqMX48fF/hBbTg=&ob=true',
        'source' => 'ical',
    ],
    [
        'label' => 'Casa Alessandro 6',
        'url' => 'https://ws.interhome.com/ih/b2p/v0100/partners/IT04151/objects/IT5606.621.6/ical?hmac=iltEPAReu/VYfMKH0j+MoTxoRSk1kTqMX48fF/hBbTg=&ob=true',
        'source' => 'ical',
    ],
];