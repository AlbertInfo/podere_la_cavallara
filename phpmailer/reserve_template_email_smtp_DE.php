<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/src/Exception.php';
require __DIR__ . '/src/PHPMailer.php';
require __DIR__ . '/src/SMTP.php';
// require __DIR__ . '/db.php';

$dbConfig   = dirname(__DIR__, 2) . '/config/database.php';
$mailConfig = dirname(__DIR__, 2) . '/config/mail.php';

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
        echo '<div class="error_message">Geben Sie die Daten ein.</div>';
        exit();
    }

    if ($rooms_booking === '') {
        echo '<div class="error_message">Geben Sie eine Unterkunft ein.</div>';
        exit();
    }

    if ($adults_booking === '') {
        echo '<div class="error_message">Geben Sie die Anzahl der Erwachsenen ein.</div>';
        exit();
    }

    if ($childs_booking === '') {
        echo '<div class="error_message">Geben Sie die Anzahl der Kinder ein.</div>';
        exit();
    }

    if ($name_booking === '') {
        echo '<div class="error_message">Geben Sie Ihren Namen ein.</div>';
        exit();
    }

    if ($email_booking === '' || !filter_var($email_booking, FILTER_VALIDATE_EMAIL)) {
        echo '<div class="error_message">Geben Sie eine gültige E-Mail-Adresse ein.</div>';
        exit();
    }

    if ($verify_booking !== '4') {
        echo '<div class="error_message">Die Verifizierungsnummer ist nicht korrekt.</div>';
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

    echo '<div class="error_message">Sie haben das maximale Anzahl von Anfragen in der letzten Stunde erreicht. Bitte versuchen Sie es in ' . $minutes . ' Minuten und ' . $seconds . ' Sekunden erneut.</div>';
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

    $email_html_confirm = file_get_contents(__DIR__ . '/confirmation_DE.html');

    $confirm_content = "
        <p>GeSehr geehrte/r {$name_booking},</p>
        <p>Wir haben Ihre Buchungsanfrage erfolgreich erhalten. Wir werden uns so schnell wie möglich mit Verfügbarkeit und weiteren Details bei Ihnen melden.</p>
        <h3>Zusammenfassung Ihrer Anfrage</h3>
        <table cellpadding='8' cellspacing='0' width='100%' style='border-collapse:collapse;'>
            <tr>
                <td style='border-bottom:1px solid #ddd; width:180px;'><strong>E-Mail</strong></td>
                <td style='border-bottom:1px solid #ddd;'>{$email_booking}</td>
            </tr>
            <tr>
                <td style='border-bottom:1px solid #ddd;'><strong>Aufenthaltsdaten</strong></td>
                <td style='border-bottom:1px solid #ddd;'>{$date_booking}</td>
            </tr>
            <tr>
                <td style='border-bottom:1px solid #ddd;'><strong>Unterkunft</strong></td>
                <td style='border-bottom:1px solid #ddd;'>{$rooms_booking}</td>
            </tr>
            <tr>
                <td style='border-bottom:1px solid #ddd;'><strong>Erwachsene</strong></td>
                <td style='border-bottom:1px solid #ddd;'>{$adults_booking}</td>
            </tr>
            <tr>
                <td><strong>Kinder</strong></td>
                <td>{$childs_booking}</td>
            </tr>
        </table>
    ";

    $confirm_body = str_replace(['message', 'messaggio'], $confirm_content, $email_html_confirm);

    $mail->setFrom($MAIL_FROM, $MAIL_FROM_NAME);
    $mail->addAddress($email_booking, $name_booking);
    $mail->addReplyTo($MAIL_ADMIN, $MAIL_ADMIN_NAME);
    $mail->isHTML(true);
    $mail->Subject = 'Buchungsanfrage bestätigen - Podere La Cavallara';
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

    echo '<div id="success_page" data-title="Buchungsanfrage erfolgreich gesendet" data-text="Wir haben Ihre Buchungsanfrage erhalten. Wir melden uns schnellstmöglich bei Ihnen zurück."></div>';

} catch (Exception $e) {
    echo '<div class="error_message">Anfrage konnte nicht gesendet werden. Fehler:' . htmlspecialchars($mail->ErrorInfo) . '</div>';
}