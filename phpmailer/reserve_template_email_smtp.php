<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/src/Exception.php';
require __DIR__ . '/src/PHPMailer.php';
require __DIR__ . '/src/SMTP.php';

$mail = new PHPMailer(true);

try {

    // SMTP Brevo
    $mail->isSMTP();
    $mail->Host       = 'smtp-relay.brevo.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = '7816dd001@smtp-brevo.com';
    $mail->Password   = 'bsky6Xzs02wNBXH';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    // Campi form
    $date_booking   = trim($_POST['date_booking'] ?? '');
    $rooms_booking  = trim($_POST['rooms_booking'] ?? '');
    $adults_booking = trim($_POST['adults_booking'] ?? '');
    $childs_booking = trim($_POST['childs_booking'] ?? '');
    $name_booking   = trim($_POST['name_booking'] ?? '');
    $email_booking  = trim($_POST['email_booking'] ?? '');
    $verify_booking = trim($_POST['verify_booking'] ?? '');

    // VALIDAZIONE
    if ($date_booking === '') {
        echo '<div class="error_message">Inserisci le date.</div>'; exit();
    } elseif ($rooms_booking === '') {
        echo '<div class="error_message">Seleziona una sistemazione.</div>'; exit();
    } elseif ($adults_booking === '') {
        echo '<div class="error_message">Inserisci numero adulti.</div>'; exit();
    } elseif ($name_booking === '') {
        echo '<div class="error_message">Inserisci il nome.</div>'; exit();
    } elseif ($email_booking === '' || !filter_var($email_booking, FILTER_VALIDATE_EMAIL)) {
        echo '<div class="error_message">Email non valida.</div>'; exit();
    } elseif ($verify_booking !== '4') {
        echo '<div class="error_message">Verifica errata.</div>'; exit();
    }

    // EMAIL A TE
    $mail->setFrom('alb.stend97@gmail.com', 'Podere La Cavallara');
    $mail->addAddress('alb.stend97@gmail.com');
    $mail->addReplyTo($email_booking, $name_booking);

    $mail->isHTML(true);
    $mail->Subject = 'Nuova richiesta prenotazione';

    $email_html = file_get_contents(__DIR__ . '/template-email.html');

    $e_content = "
    <h2>Nuova richiesta di prenotazione</h2>
    <p>Hai ricevuto una nuova richiesta dal sito.</p>

    <table cellpadding='6' cellspacing='0' border='1' style='border-collapse:collapse; width:100%;'>
        <tr>
            <td><strong>Nome</strong></td>
            <td>{$name_booking}</td>
        </tr>
        <tr>
            <td><strong>Email</strong></td>
            <td>{$email_booking}</td>
        </tr>
        <tr>
            <td><strong>Date soggiorno</strong></td>
            <td>{$date_booking}</td>
        </tr>
        <tr>
            <td><strong>Sistemazione</strong></td>
            <td>{$rooms_booking}</td>
        </tr>
        <tr>
            <td><strong>Adulti</strong></td>
            <td>{$adults_booking}</td>
        </tr>
        <tr>
            <td><strong>Bambini</strong></td>
            <td>{$childs_booking}</td>
        </tr>
    </table>
";

    $body = str_replace('message', $e_content, $email_html);
    $mail->MsgHTML($body);
    $mail->send();

    // EMAIL CLIENTE
    $mail->clearAddresses();
    $mail->clearReplyTos();

    $mail->setFrom('alb.stend97@gmail.com', 'Podere La Cavallara');
    $mail->addAddress($email_booking, $name_booking);
    $mail->addReplyTo('alb.stend97@gmail.com', 'Podere La Cavallara');

    $mail->Subject = 'Conferma richiesta prenotazione';

    $email_html_confirm = file_get_contents(__DIR__ . '/confirmation.html');

    $confirm_content = "
    <p>Gentile {$name_booking},</p>
    <p>abbiamo ricevuto correttamente la tua richiesta di prenotazione. Ti risponderemo al più presto.</p>

    <h3>Riepilogo della richiesta</h3>
    <table cellpadding='6' cellspacing='0' border='1' style='border-collapse:collapse; width:100%;'>
        <tr>
            <td><strong>Nome</strong></td>
            <td>{$name_booking}</td>
        </tr>
        <tr>
            <td><strong>Email</strong></td>
            <td>{$email_booking}</td>
        </tr>
        <tr>
            <td><strong>Date soggiorno</strong></td>
            <td>{$date_booking}</td>
        </tr>
        <tr>
            <td><strong>Sistemazione</strong></td>
            <td>{$rooms_booking}</td>
        </tr>
        <tr>
            <td><strong>Adulti</strong></td>
            <td>{$adults_booking}</td>
        </tr>
        <tr>
            <td><strong>Bambini</strong></td>
            <td>{$childs_booking}</td>
        </tr>
    </table>
";

    $body = str_replace('message', $confirm_content, $email_html_confirm);
    $mail->MsgHTML($body);
    $mail->send();

    echo '<div id="success_page">
            <h5>Grazie!<span>Richiesta inviata correttamente.</span></h5>
          </div>';

} catch (Exception $e) {
    echo '<div class="error_message">Errore: ' . $mail->ErrorInfo . '</div>';
}