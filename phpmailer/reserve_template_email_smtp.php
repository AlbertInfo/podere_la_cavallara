<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/src/Exception.php';
require __DIR__ . '/src/PHPMailer.php';
require __DIR__ . '/src/SMTP.php';
require __DIR__ . '/db.php';

$mail = new PHPMailer(true);

try {
    // SMTP
    $mail->isSMTP();
    $mail->Host       = 'smtp-relay.brevo.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = '7816dd001@smtp-brevo.com';
    $mail->Password   = 'bsky6Xzs02wNBXH';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;
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
        SELECT COUNT(*) 
        FROM booking_requests 
        WHERE email_booking = :email_booking
    ");
    $check->execute([
        'email_booking' => $email_booking
    ]);

    $requestCount = (int)$check->fetchColumn();

    if ($requestCount >= 3) {
        echo '<div class="error_message">Hai già inviato il numero massimo di richieste di prenotazione consentite con questa email.</div>';
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
    $mail->setFrom('alb.stend97@gmail.com', 'Podere La Cavallara');
    $mail->addAddress('alb.stend97@gmail.com', 'Podere La Cavallara');
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

    $mail->setFrom('alb.stend97@gmail.com', 'Podere La Cavallara');
    $mail->addAddress($email_booking, $name_booking);
    $mail->addReplyTo('alb.stend97@gmail.com', 'Podere La Cavallara');
    $mail->isHTML(true);
    $mail->Subject = 'Conferma richiesta prenotazione - Podere La Cavallara';
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

    echo '<div id="success_page" data-title="Richiesta inviata correttamente" data-text="Abbiamo ricevuto la tua richiesta di prenotazione. Ti risponderemo al più presto con tutti i dettagli."></div>';

} catch (Exception $e) {
    echo '<div class="error_message">Impossibile inviare la richiesta. Errore: ' . htmlspecialchars($mail->ErrorInfo) . '</div>';
}