<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'src/Exception.php';
require 'src/PHPMailer.php';
require 'src/SMTP.php';

$mail = new PHPMailer(true);

try {
    // SMTP
    $mail->isSMTP();
    $mail->Host       = 'smtp-relay.brevo.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = '7816dd001@smtp-brevo.com';
    $mail->Password   = 'bsky6Xzs02wNBXH';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587

;

    // Form fields
    $name_contact     = trim($_POST['name_contact'] ?? '');
    $lastname_contact = trim($_POST['lastname_contact'] ?? '');
    $email_contact    = trim($_POST['email_contact'] ?? '');
    $phone_contact    = trim($_POST['phone_contact'] ?? '');
    $message_contact  = trim($_POST['message_contact'] ?? '');
    $verify_contact   = trim($_POST['verify_contact'] ?? '');

    // Validation
    if ($name_contact === '') {
        echo '<div class="error_message">Inserisci il nome.</div>';
        exit();
    } elseif ($lastname_contact === '') {
        echo '<div class="error_message">Inserisci il cognome.</div>';
        exit();
    } elseif ($email_contact === '' || !filter_var($email_contact, FILTER_VALIDATE_EMAIL)) {
        echo '<div class="error_message">Inserisci un indirizzo email valido.</div>';
        exit();
    } elseif ($phone_contact === '') {
        echo '<div class="error_message">Inserisci un numero di telefono.</div>';
        exit();
    } elseif (!preg_match('/^[0-9+\s().-]{6,20}$/', $phone_contact)) {
        echo '<div class="error_message">Inserisci un numero di telefono valido.</div>';
        exit();
    } elseif ($message_contact === '') {
        echo '<div class="error_message">Inserisci il messaggio.</div>';
        exit();
    } elseif ($verify_contact === '') {
        echo '<div class="error_message">Inserisci il numero di verifica.</div>';
        exit();
    } elseif ($verify_contact !== '4') {
        echo '<div class="error_message">Il numero di verifica non è corretto.</div>';
        exit();
    }

    // Email admin
    $mail->setFrom('alb.stend97@gmail.com', 'Podere Cavallara');
    $mail->addAddress('alb.stend97@gmail.com', 'Podere Cavallara');
    $mail->addReplyTo($email_contact, $name_contact . ' ' . $lastname_contact);
    $mail->isHTML(true);
    $mail->Subject = 'Nuova richiesta contatto - Podere Cavallara';
    $mail->CharSet = 'UTF-8';

    $email_html = file_get_contents('template-email.html');

    $e_content = "
        Hai ricevuto una nuova richiesta di contatto da
        <strong>{$name_contact} {$lastname_contact}</strong>.<br><br>
        <strong>Email:</strong> {$email_contact}<br>
        <strong>Telefono:</strong> {$phone_contact}<br><br>
        <strong>Messaggio:</strong><br>{$message_contact}
    ";

    $body = str_replace('message', $e_content, $email_html);
    $mail->MsgHTML($body);
    $mail->send();

    // Email conferma cliente
    $mail->clearAddresses();
    $mail->clearReplyTos();

    $mail->setFrom('alb.stend97@gmail.com', 'Podere Cavallara');
    $mail->addAddress($email_contact, $name_contact . ' ' . $lastname_contact);
    $mail->addReplyTo('alb.stend97@gmail.com', 'Podere Cavallara');
    $mail->isHTML(true);
    $mail->Subject = 'Conferma richiesta contatto - Podere Cavallara';
    $mail->CharSet = 'UTF-8';

    $email_html_confirm = file_get_contents('confirmation.html');

    $confirm_content = "
        Gentile <strong>{$name_contact} {$lastname_contact}</strong>,<br><br>
        abbiamo ricevuto correttamente la tua richiesta di contatto.<br>
        Ti risponderemo al più presto.<br><br>
        <strong>Riepilogo del messaggio inviato:</strong><br>{$message_contact}
    ";

    $body = str_replace('message', $confirm_content, $email_html_confirm);
    $mail->MsgHTML($body);
    $mail->send();

    echo '<div id="success_page">
            <div class="icon icon--order-success svg">
                 <svg xmlns="http://www.w3.org/2000/svg" width="72px" height="72px">
                  <g fill="none" stroke="#8EC343" stroke-width="2">
                     <circle cx="36" cy="36" r="35" style="stroke-dasharray:240px, 240px; stroke-dashoffset: 480px;"></circle>
                     <path d="M17.417,37.778l9.93,9.909l25.444-25.393" style="stroke-dasharray:50px, 50px; stroke-dashoffset: 0px;"></path>
                  </g>
                 </svg>
             </div>
            <h5>Grazie!<span>Richiesta inviata correttamente.</span></h5>
        </div>';

} catch (Exception $e) {
    echo '<div class="error_message">Impossibile inviare il messaggio. Errore: ' . htmlspecialchars($mail->ErrorInfo) . '</div>';
}
?>