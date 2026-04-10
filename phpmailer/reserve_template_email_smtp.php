<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/src/Exception.php';
require __DIR__ . '/src/PHPMailer.php';
require __DIR__ . '/src/SMTP.php';
$dbConfig   = dirname(__DIR__, 2) . '/config/database.php';
$mailConfig = dirname(__DIR__, 2) . '/config/email.php';

if (!file_exists($dbConfig)) {
    die('database.php non trovato in: ' . $dbConfig);
}

if (!file_exists($mailConfig)) {
    die('mail.php non trovato in: ' . $mailConfig);
}

require_once $dbConfig;
require_once $mailConfig;


function booking_requests_has_column(PDO $pdo, string $column): bool {
    static $cache = [];
    if (array_key_exists($column, $cache)) {
        return $cache[$column];
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'booking_requests' AND COLUMN_NAME = :column_name");
    $stmt->execute(['column_name' => $column]);
    $cache[$column] = ((int) $stmt->fetchColumn()) > 0;
    return $cache[$column];
}

function booking_parse_requested_dates(string $dateBooking): array {
    $dateBooking = trim($dateBooking);
    if ($dateBooking === '') {
        return [null, null];
    }

    $normalized = str_replace(' al ', ' - ', $dateBooking);
    $parts = array_values(array_filter(array_map('trim', explode(' - ', $normalized))));
    if (count($parts) < 2) {
        return [null, null];
    }

    $checkIn = DateTime::createFromFormat('d/m/Y', $parts[0]) ?: null;
    $checkOut = DateTime::createFromFormat('d/m/Y', $parts[1]) ?: null;

    return [
        $checkIn ? $checkIn->format('Y-m-d') : null,
        $checkOut ? $checkOut->format('Y-m-d') : null,
    ];
}

$mail = new PHPMailer(true);

try {
    // SMTP
    $mail->isSMTP();
$mail->Host       = $SMTP_HOST;
$mail->SMTPAuth   = true;
$mail->Username   = $SMTP_USER;
$mail->Password   = $SMTP_PASS;
$mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
$mail->Port       = $SMTP_PORT;
$mail->CharSet    = 'UTF-8';

    // Campi form
    $date_booking   = trim($_POST['date_booking'] ?? '');
    $rooms_booking  = trim($_POST['rooms_booking'] ?? '');
    $adults_booking = trim($_POST['adults_booking'] ?? '');
    $childs_booking = trim($_POST['childs_booking'] ?? '');
    $name_booking   = trim($_POST['name_booking'] ?? '');
    $email_booking  = trim($_POST['email_booking'] ?? '');
    $verify_booking = trim($_POST['verify_booking'] ?? '');

    // Validazione
    if ($date_booking === '') {
        echo '<div class="error_message">Inserisci le date.</div>';
        exit();
    }

    if ($rooms_booking === '') {
        echo '<div class="error_message">Seleziona una sistemazione.</div>';
        exit();
    }

    if ($adults_booking === '') {
        echo '<div class="error_message">Inserisci il numero di adulti.</div>';
        exit();
    }

    if ($childs_booking === '') {
        echo '<div class="error_message">Inserisci il numero di bambini.</div>';
        exit();
    }

    if ($name_booking === '') {
        echo '<div class="error_message">Inserisci il nome.</div>';
        exit();
    }

    if ($email_booking === '' || !filter_var($email_booking, FILTER_VALIDATE_EMAIL)) {
        echo '<div class="error_message">Inserisci un indirizzo email valido.</div>';
        exit();
    }

    if ($verify_booking !== '4') {
        echo '<div class="error_message">Il numero di verifica non è corretto.</div>';
        exit();
    }

    // Limite massimo 3 richieste prenotazione per email
   $check = $pdo->prepare("
    SELECT created_at
    FROM booking_requests
    WHERE email_booking = :email_booking
      AND created_at >= (NOW() - INTERVAL 1 HOUR)
    ORDER BY created_at ASC
");
$check->execute([
    'email_booking' => $email_booking
]);

$requests = $check->fetchAll();

if (count($requests) >= 3) {
    $firstRequestTime = new DateTime($requests[0]['created_at']);
    $unlockTime = clone $firstRequestTime;
    $unlockTime->modify('+1 hour');

    $now = new DateTime();
    $secondsRemaining = $unlockTime->getTimestamp() - $now->getTimestamp();

    if ($secondsRemaining < 0) {
        $secondsRemaining = 0;
    }

    $minutes = floor($secondsRemaining / 60);
    $seconds = $secondsRemaining % 60;

    echo '<div class="error_message">Hai raggiunto il numero massimo di richieste consentite nell’ultima ora. Riprova tra ' . $minutes . ' minuti e ' . $seconds . ' secondi.</div>';
    exit();
}

    // Template email admin
    $email_html = file_get_contents(__DIR__ . '/template-email.html');

    $e_content = "
        <h3 style='margin-top:0;'>Nuova richiesta di prenotazione</h3>
        <table cellpadding='8' cellspacing='0' width='100%' style='border-collapse:collapse;'>
            <tr>
                <td style='border-bottom:1px solid #ddd; width:180px;'><strong>Nome</strong></td>
                <td style='border-bottom:1px solid #ddd;'>{$name_booking}</td>
            </tr>
            <tr>
                <td style='border-bottom:1px solid #ddd;'><strong>Email</strong></td>
                <td style='border-bottom:1px solid #ddd;'>{$email_booking}</td>
            </tr>
            <tr>
                <td style='border-bottom:1px solid #ddd;'><strong>Date soggiorno</strong></td>
                <td style='border-bottom:1px solid #ddd;'>{$date_booking}</td>
            </tr>
            <tr>
                <td style='border-bottom:1px solid #ddd;'><strong>Sistemazione</strong></td>
                <td style='border-bottom:1px solid #ddd;'>{$rooms_booking}</td>
            </tr>
            <tr>
                <td style='border-bottom:1px solid #ddd;'><strong>Adulti</strong></td>
                <td style='border-bottom:1px solid #ddd;'>{$adults_booking}</td>
            </tr>
            <tr>
                <td><strong>Bambini</strong></td>
                <td>{$childs_booking}</td>
            </tr>
        </table>
    ";

    $body = str_replace(['message', 'messaggio'], $e_content, $email_html);

    // Mail a te
    $mail->setFrom($MAIL_FROM, $MAIL_FROM_NAME);
    $mail->addAddress($MAIL_ADMIN, $MAIL_ADMIN_NAME);
    $mail->addReplyTo($email_booking, $name_booking);
    $mail->isHTML(true);
    $mail->Subject = 'Nuova richiesta prenotazione - Podere La Cavallara';
    $mail->MsgHTML($body);
    $mail->send();

    // Mail di conferma utente
    $mail->clearAddresses();
    $mail->clearReplyTos();

    $email_html_confirm = file_get_contents(__DIR__ . '/confirmation.html');

    $confirm_content = "
        <p>Gentile {$name_booking},</p>
        <p>abbiamo ricevuto correttamente la tua richiesta di prenotazione. Ti risponderemo al più presto con disponibilità e dettagli.</p>
        <h3>Riepilogo della richiesta</h3>
        <table cellpadding='8' cellspacing='0' width='100%' style='border-collapse:collapse;'>
            <tr>
                <td style='border-bottom:1px solid #ddd; width:180px;'><strong>Email</strong></td>
                <td style='border-bottom:1px solid #ddd;'>{$email_booking}</td>
            </tr>
            <tr>
                <td style='border-bottom:1px solid #ddd;'><strong>Date soggiorno</strong></td>
                <td style='border-bottom:1px solid #ddd;'>{$date_booking}</td>
            </tr>
            <tr>
                <td style='border-bottom:1px solid #ddd;'><strong>Sistemazione</strong></td>
                <td style='border-bottom:1px solid #ddd;'>{$rooms_booking}</td>
            </tr>
            <tr>
                <td style='border-bottom:1px solid #ddd;'><strong>Adulti</strong></td>
                <td style='border-bottom:1px solid #ddd;'>{$adults_booking}</td>
            </tr>
            <tr>
                <td><strong>Bambini</strong></td>
                <td>{$childs_booking}</td>
            </tr>
        </table>
    ";

    $confirm_body = str_replace(['message', 'messaggio'], $confirm_content, $email_html_confirm);

    $mail->setFrom($MAIL_FROM, $MAIL_FROM_NAME);
    $mail->addAddress($email_booking, $name_booking);
    $mail->addReplyTo($MAIL_ADMIN, $MAIL_ADMIN_NAME);
    $mail->isHTML(true);
    $mail->Subject = 'Conferma richiesta prenotazione - Podere La Cavallara';
    $mail->MsgHTML($confirm_body);
    $mail->send();

    // Salvataggio DB
    [$requestedCheckIn, $requestedCheckOut] = booking_parse_requested_dates($date_booking);

    $columns = [
        'date_booking',
        'rooms_booking',
        'adults_booking',
        'childs_booking',
        'name_booking',
        'email_booking',
        'ip_address',
        'user_agent'
    ];

    $params = [
        'date_booking' => $date_booking,
        'rooms_booking' => $rooms_booking,
        'adults_booking' => $adults_booking,
        'childs_booking' => $childs_booking,
        'name_booking' => $name_booking,
        'email_booking' => $email_booking,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
    ];

    $optionalColumns = [
        'customer_language' => 'it',
        'source' => 'website_form_it',
        'requested_check_in' => $requestedCheckIn,
        'requested_check_out' => $requestedCheckOut,
        'requested_room_type' => $rooms_booking,
        'adults_count' => (int) $adults_booking,
        'children_count' => (int) $childs_booking,
    ];

    foreach ($optionalColumns as $column => $value) {
        if (booking_requests_has_column($pdo, $column)) {
            $columns[] = $column;
            $params[$column] = $value;
        }
    }

    $placeholders = array_map(static fn(string $column): string => ':' . $column, $columns);
    $insert = $pdo->prepare(
        'INSERT INTO booking_requests (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')'
    );

    $insert->execute($params);

    echo '<div id="success_page" data-title="Richiesta inviata correttamente" data-text="Abbiamo ricevuto la tua richiesta di prenotazione. Ti risponderemo al più presto con tutti i dettagli."></div>';

} catch (Exception $e) {
    echo '<div class="error_message">Impossibile inviare la richiesta. Errore: ' . htmlspecialchars($mail->ErrorInfo) . '</div>';
}