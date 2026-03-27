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
        echo '<div class="error_message">Enter the dates.</div>';
        exit();
    }

    if ($rooms_booking === '') {
        echo '<div class="error_message">Select a accommodation.</div>';
        exit();
    }

    if ($adults_booking === '') {
        echo '<div class="error_message">Enter the number of adults.</div>';
        exit();
    }

    if ($childs_booking === '') {
        echo '<div class="error_message">Enter the number of children.</div>';
        exit();
    }

    if ($name_booking === '') {
        echo '<div class="error_message">Enter your name.</div>';
        exit();
    }

    if ($email_booking === '' || !filter_var($email_booking, FILTER_VALIDATE_EMAIL)) {
        echo '<div class="error_message">Enter a valid email address.</div>';
        exit();
    }

    if ($verify_booking !== '4') {
        echo '<div class="error_message">The verification number is not correct.</div>';
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

    echo '<div class="error_message"> You have reached the maximum number of requests allowed in the last hour. Please try again in ' . $minutes . ' minutes and ' . $seconds . ' seconds.</div>';
    exit();
}

    // Template email admin
    $email_html = file_get_contents(__DIR__ . '/template-email.html');

    $e_content = "
        <h3 style='margin-top:0;'>Nuova richiesta di prenotazione</h3>
        <table cellpadding='8' cellspacing='0' width='100%' style='border-collapse:collapse;'>
            <tr>
                <td style='border-bottom:1px solid #ddd; width:180px;'><strong>Name</strong></td>
                <td style='border-bottom:1px solid #ddd;'>{$name_booking}</td>
            </tr>
            <tr>
                <td style='border-bottom:1px solid #ddd;'><strong>Email</strong></td>
                <td style='border-bottom:1px solid #ddd;'>{$email_booking}</td>
            </tr>
            <tr>
                <td style='border-bottom:1px solid #ddd;'><strong>Date of stay</strong></td>
                <td style='border-bottom:1px solid #ddd;'>{$date_booking}</td>
            </tr>
            <tr>
                <td style='border-bottom:1px solid #ddd;'><strong>Accommodation</strong></td>
                <td style='border-bottom:1px solid #ddd;'>{$rooms_booking}</td>
            </tr>
            <tr>
                <td style='border-bottom:1px solid #ddd;'><strong>Adults</strong></td>
                <td style='border-bottom:1px solid #ddd;'>{$adults_booking}</td>
            </tr>
            <tr>
                <td><strong>Children</strong></td>
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

    $email_html_confirm = file_get_contents(__DIR__ . '/confirmation_EN.html');

    $confirm_content = "
        <p>Dear {$name_booking},</p>
        <p>We have successfully received your booking request. We will get back to you shortly with availability and further details.</p>
        <h3>Request Summary</h3>
        <table cellpadding='8' cellspacing='0' width='100%' style='border-collapse:collapse;'>
            <tr>
                <td style='border-bottom:1px solid #ddd; width:180px;'><strong>Email</strong></td>
                <td style='border-bottom:1px solid #ddd;'>{$email_booking}</td>
            </tr>
            <tr>
                <td style='border-bottom:1px solid #ddd;'><strong>Stay dates</strong></td>
                <td style='border-bottom:1px solid #ddd;'>{$date_booking}</td>
            </tr>
            <tr>
                <td style='border-bottom:1px solid #ddd;'><strong>Accommodation</strong></td>
                <td style='border-bottom:1px solid #ddd;'>{$rooms_booking}</td>
            </tr>
            <tr>
                <td style='border-bottom:1px solid #ddd;'><strong>Adults</strong></td>
                <td style='border-bottom:1px solid #ddd;'>{$adults_booking}</td>
            </tr>
            <tr>
                <td><strong>Children</strong></td>
                <td>{$childs_booking}</td>
            </tr>
        </table>
    ";

    $confirm_body = str_replace(['message', 'messaggio'], $confirm_content, $email_html_confirm);

    $mail->setFrom($MAIL_FROM, $MAIL_FROM_NAME);
    $mail->addAddress($email_booking, $name_booking);
    $mail->addReplyTo($MAIL_ADMIN, $MAIL_ADMIN_NAME);
    $mail->isHTML(true);
    $mail->Subject = 'Confirmation of booking request - Podere La Cavallara';
    $mail->MsgHTML($confirm_body);
    $mail->send();

    // Salvataggio DB
    $insert = $pdo->prepare("
        INSERT INTO booking_requests (
            date_booking,
            rooms_booking,
            adults_booking,
            childs_booking,
            name_booking,
            email_booking,
            ip_address,
            user_agent
        ) VALUES (
            :date_booking,
            :rooms_booking,
            :adults_booking,
            :childs_booking,
            :name_booking,
            :email_booking,
            :ip_address,
            :user_agent
        )
    ");

    $insert->execute([
        'date_booking' => $date_booking,
        'rooms_booking' => $rooms_booking,
        'adults_booking' => $adults_booking,
        'childs_booking' => $childs_booking,
        'name_booking' => $name_booking,
        'email_booking' => $email_booking,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);

    echo '<div id="success_page" data-title="Request sent successfully" data-text="We have received your booking request. We will respond to you as soon as possible with all the details."></div>';

} catch (Exception $e) {
    echo '<div class="error_message">Unable to send the request. Error: ' . htmlspecialchars($mail->ErrorInfo) . '</div>';
}