<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/src/Exception.php';
require __DIR__ . '/src/PHPMailer.php';
require __DIR__ . '/src/SMTP.php';


require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/email.php';

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
    $name_contact     = trim($_POST['name_contact'] ?? '');
    $lastname_contact = trim($_POST['lastname_contact'] ?? '');
    $email_contact    = trim($_POST['email_contact'] ?? '');
    $phone_contact    = trim($_POST['phone_contact'] ?? '');
    $message_contact  = trim($_POST['message_contact'] ?? '');
    $verify_contact   = trim($_POST['verify_contact'] ?? '');

    // Validazione
    if ($name_contact === '') {
        echo '<div class="error_message">Inserisci il nome.</div>';
        exit();
    }

    if ($lastname_contact === '') {
        echo '<div class="error_message">Inserisci il cognome.</div>';
        exit();
    }

    if ($email_contact === '' || !filter_var($email_contact, FILTER_VALIDATE_EMAIL)) {
        echo '<div class="error_message">Inserisci un indirizzo email valido.</div>';
        exit();
    }

    if ($phone_contact === '') {
        echo '<div class="error_message">Inserisci un numero di telefono.</div>';
        exit();
    }

    if (!preg_match('/^[0-9+\s().-]{6,20}$/', $phone_contact)) {
        echo '<div class="error_message">Inserisci un numero di telefono valido.</div>';
        exit();
    }

    if ($message_contact === '') {
        echo '<div class="error_message">Inserisci il messaggio.</div>';
        exit();
    }

    if ($verify_contact !== '4') {
        echo '<div class="error_message">Il numero di verifica non è corretto.</div>';
        exit();
    }

    // Limite massimo 3 richieste contatto per email in un'ora
    $check = $pdo->prepare("
    SELECT created_at
    FROM contact_requests
    WHERE email_contact = :email_contact
      AND created_at >= (NOW() - INTERVAL 1 HOUR)
    ORDER BY created_at ASC
");
$check->execute([
    'email_contact' => $email_contact
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

   echo '<div class="error_message">Tentativi di richiesta esauriti. Riprova tra ' . $minutes . ' minuti e ' . $seconds . ' secondi.</div>';
    exit();
}

    // Template email admin
    $email_html = file_get_contents(__DIR__ . '/template-email.html');

    $safe_message_contact = nl2br(htmlspecialchars($message_contact, ENT_QUOTES, 'UTF-8'));

    $e_content = "
        <h3 style='margin-top:0;'>Nuova richiesta informazioni</h3>
        <table cellpadding='8' cellspacing='0' width='100%' style='border-collapse:collapse;'>
            <tr>
                <td style='border-bottom:1px solid #ddd; width:180px;'><strong>Nome</strong></td>
                <td style='border-bottom:1px solid #ddd;'>{$name_contact} {$lastname_contact}</td>
            </tr>
            <tr>
                <td style='border-bottom:1px solid #ddd;'><strong>Email</strong></td>
                <td style='border-bottom:1px solid #ddd;'>{$email_contact}</td>
            </tr>
            <tr>
                <td style='border-bottom:1px solid #ddd;'><strong>Telefono</strong></td>
                <td style='border-bottom:1px solid #ddd;'>{$phone_contact}</td>
            </tr>
            <tr>
                <td valign='top'><strong>Messaggio</strong></td>
                <td>{$safe_message_contact}</td>
            </tr>
        </table>
    ";

    $body = str_replace(['message', 'messaggio'], $e_content, $email_html);

    // Mail a te
    $mail->setFrom($MAIL_FROM, $MAIL_FROM_NAME);
    $mail->addAddress($MAIL_ADMIN, $MAIL_ADMIN_NAME);
    $mail->addReplyTo($email_contact, $name_contact . ' ' . $lastname_contact);
    $mail->isHTML(true);
    $mail->Subject = 'Nuova richiesta informazioni - Podere La Cavallara';
    $mail->MsgHTML($body);
    $mail->send();

    // Mail di conferma utente
    $mail->clearAddresses();
    $mail->clearReplyTos();

    $email_html_confirm = file_get_contents(__DIR__ . '/confirmation.html');

    $confirm_content = "
        <p>Gentile {$name_contact} {$lastname_contact},</p>
        <p>abbiamo ricevuto correttamente la tua richiesta di informazioni. Ti risponderemo al più presto.</p>
        <h3>Riepilogo della richiesta</h3>
        <table cellpadding='8' cellspacing='0' width='100%' style='border-collapse:collapse;'>
            <tr>
                <td style='border-bottom:1px solid #ddd; width:180px;'><strong>Email</strong></td>
                <td style='border-bottom:1px solid #ddd;'>{$email_contact}</td>
            </tr>
            <tr>
                <td style='border-bottom:1px solid #ddd;'><strong>Telefono</strong></td>
                <td style='border-bottom:1px solid #ddd;'>{$phone_contact}</td>
            </tr>
            <tr>
                <td valign='top'><strong>Messaggio</strong></td>
                <td>{$safe_message_contact}</td>
            </tr>
        </table>
    ";

    $confirm_body = str_replace(['message', 'messaggio'], $confirm_content, $email_html_confirm);

    $mail->setFrom($MAIL_FROM, $MAIL_FROM_NAME);
    $mail->addAddress($email_contact, $name_contact . ' ' . $lastname_contact);
    $mail->addReplyTo($MAIL_FROM, $MAIL_FROM_NAME);
    $mail->isHTML(true);
    $mail->Subject = 'Conferma richiesta informazioni - Podere La Cavallara';
    $mail->MsgHTML($confirm_body);
    $mail->send();

    // Salvataggio DB
    $insert = $pdo->prepare("
        INSERT INTO contact_requests (
            name_contact,
            lastname_contact,
            email_contact,
            phone_contact,
            message_contact,
            ip_address,
            user_agent
        ) VALUES (
            :name_contact,
            :lastname_contact,
            :email_contact,
            :phone_contact,
            :message_contact,
            :ip_address,
            :user_agent
        )
    ");

    $insert->execute([
        'name_contact' => $name_contact,
        'lastname_contact' => $lastname_contact,
        'email_contact' => $email_contact,
        'phone_contact' => $phone_contact,
        'message_contact' => $message_contact,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);

    echo '<div id="success_page" data-title="Messaggio inviato correttamente" data-text="Grazie per averci contattato. Ti risponderemo al più presto."></div>';

} catch (Exception $e) {
    echo '<div class="error_message">Impossibile inviare il messaggio. Errore: ' . htmlspecialchars($mail->ErrorInfo) . '</div>';
}