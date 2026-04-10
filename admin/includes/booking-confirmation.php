<?php

declare(strict_types=1);

function admin_db_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
    );
    $stmt->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);

    $cache[$key] = ((int) $stmt->fetchColumn()) > 0;
    return $cache[$key];
}

function admin_normalize_guest_language(?string $value): string
{
    $normalized = strtolower(trim((string) $value));
    if ($normalized === '') {
        return 'it';
    }

    return match ($normalized) {
        'it', 'ita', 'italian', 'italiano' => 'it',
        'de', 'deu', 'ger', 'german', 'tedesco', 'deutsch' => 'de',
        'en', 'eng', 'english', 'inglese' => 'en',
        default => 'it',
    };
}

function admin_infer_request_language(array $request): string
{
    $candidates = [
        $request['customer_language'] ?? null,
        $request['guest_language'] ?? null,
        $request['language'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        $normalized = admin_normalize_guest_language($candidate);
        if ($normalized !== 'it' || trim((string) $candidate) !== '') {
            return $normalized;
        }
    }

    $source = strtolower(trim((string) ($request['source'] ?? '')));
    if (str_contains($source, '_de') || str_contains($source, 'german') || str_contains($source, 'deutsch')) {
        return 'de';
    }
    if (str_contains($source, '_en') || str_contains($source, 'english')) {
        return 'en';
    }

    return 'it';
}

function admin_extract_stay_dates(string $stayPeriod): array
{
    $stayPeriod = trim($stayPeriod);
    if ($stayPeriod === '') {
        return [null, null, '', ''];
    }

    $normalized = str_replace(' al ', ' - ', $stayPeriod);
    $parts = array_values(array_filter(array_map('trim', explode(' - ', $normalized))));

    if (count($parts) < 2) {
        return [null, null, '', ''];
    }

    $checkIn = DateTime::createFromFormat('d/m/Y', $parts[0]) ?: null;
    $checkOut = DateTime::createFromFormat('d/m/Y', $parts[1]) ?: null;

    return [
        $checkIn ? $checkIn->format('Y-m-d') : null,
        $checkOut ? $checkOut->format('Y-m-d') : null,
        $checkIn ? $checkIn->format('d/m/Y') : $parts[0],
        $checkOut ? $checkOut->format('d/m/Y') : $parts[1],
    ];
}

function admin_booking_template_path(string $language): ?string
{
    $suffix = match ($language) {
        'de' => '_DE',
        'en' => '_EN',
        default => '',
    };

    $candidates = [
        dirname(__DIR__) . '/email-templates/confirmation' . $suffix . '.html',
        dirname(__DIR__, 3) . '/confirmation' . $suffix . '.html',
    ];

    foreach ($candidates as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    return null;
}

function admin_booking_confirmation_copy(string $language, array $booking): array
{
    $name = htmlspecialchars((string) ($booking['customer_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $email = trim((string) ($booking['customer_email'] ?? ''));
    $email = $email !== '' ? htmlspecialchars($email, ENT_QUOTES, 'UTF-8') : '—';
    $room = htmlspecialchars((string) ($booking['room_type'] ?? ''), ENT_QUOTES, 'UTF-8');
    $stay = htmlspecialchars((string) ($booking['stay_period'] ?? ''), ENT_QUOTES, 'UTF-8');
    $adults = (int) ($booking['adults'] ?? 0);
    $children = (int) ($booking['children_count'] ?? 0);

    $structureEmail = htmlspecialchars((string) ($GLOBALS['MAIL_ADMIN'] ?? $GLOBALS['MAIL_FROM'] ?? ''), ENT_QUOTES, 'UTF-8');
    $website = 'https://poderelacavallara.it';
    $websiteEscaped = htmlspecialchars($website, ENT_QUOTES, 'UTF-8');

    if ($language === 'de') {
        return [
            'subject' => 'Ihre Buchung ist bestätigt - Podere La Cavallara',
            'heading_from' => 'Vielen Dank für Ihre Kontaktaufnahme!',
            'heading_to' => 'Ihre Buchung ist bestätigt!',
            'section_from' => 'Anfrage',
            'section_to' => 'Buchungsbestätigung',
            'content' => "
                <p>Liebe/r {$name},</p>
                <p>herzlichen Glückwunsch, Ihre Buchung wurde erfolgreich bestätigt.</p>
                <p>Wir freuen uns darauf, Sie im <strong>Podere La Cavallara</strong> in Montefiascone willkommen zu heißen.</p>
                <h3 style='margin:26px 0 12px;'>Buchungsübersicht</h3>
                <table cellpadding='8' cellspacing='0' width='100%' style='border-collapse:collapse;'>
                    <tr>
                        <td style='border-bottom:1px solid #ddd; width:180px;'><strong>Name</strong></td>
                        <td style='border-bottom:1px solid #ddd;'>{$name}</td>
                    </tr>
                    <tr>
                        <td style='border-bottom:1px solid #ddd;'><strong>E-Mail</strong></td>
                        <td style='border-bottom:1px solid #ddd;'>{$email}</td>
                    </tr>
                    <tr>
                        <td style='border-bottom:1px solid #ddd;'><strong>Aufenthalt</strong></td>
                        <td style='border-bottom:1px solid #ddd;'>{$stay}</td>
                    </tr>
                    <tr>
                        <td style='border-bottom:1px solid #ddd;'><strong>Unterkunft</strong></td>
                        <td style='border-bottom:1px solid #ddd;'>{$room}</td>
                    </tr>
                    <tr>
                        <td style='border-bottom:1px solid #ddd;'><strong>Erwachsene</strong></td>
                        <td style='border-bottom:1px solid #ddd;'>{$adults}</td>
                    </tr>
                    <tr>
                        <td><strong>Kinder</strong></td>
                        <td>{$children}</td>
                    </tr>
                </table>
                <h3 style='margin:26px 0 12px;'>Ihre Unterkunft</h3>
                <table cellpadding='8' cellspacing='0' width='100%' style='border-collapse:collapse;'>
                    <tr>
                        <td style='border-bottom:1px solid #ddd; width:180px;'><strong>Struktur</strong></td>
                        <td style='border-bottom:1px solid #ddd;'>Podere La Cavallara</td>
                    </tr>
                    <tr>
                        <td style='border-bottom:1px solid #ddd;'><strong>Ort</strong></td>
                        <td style='border-bottom:1px solid #ddd;'>Montefiascone, Italien</td>
                    </tr>
                    <tr>
                        <td style='border-bottom:1px solid #ddd;'><strong>Website</strong></td>
                        <td style='border-bottom:1px solid #ddd;'><a href='{$websiteEscaped}' target='_blank' rel='noopener'>{$websiteEscaped}</a></td>
                    </tr>
                    <tr>
                        <td><strong>E-Mail</strong></td>
                        <td>{$structureEmail}</td>
                    </tr>
                </table>
                <p style='margin-top:20px;'>Bei Fragen können Sie gerne direkt auf diese E-Mail antworten.</p>
            ",
        ];
    }

    if ($language === 'en') {
        return [
            'subject' => 'Your booking is confirmed - Podere La Cavallara',
            'heading_from' => 'Thank you for contacting us!',
            'heading_to' => 'Your booking is confirmed!',
            'section_from' => 'Request',
            'section_to' => 'Booking confirmation',
            'content' => "
                <p>Dear {$name},</p>
                <p>great news: your booking has been successfully confirmed.</p>
                <p>We look forward to welcoming you to <strong>Podere La Cavallara</strong> in Montefiascone.</p>
                <h3 style='margin:26px 0 12px;'>Booking summary</h3>
                <table cellpadding='8' cellspacing='0' width='100%' style='border-collapse:collapse;'>
                    <tr>
                        <td style='border-bottom:1px solid #ddd; width:180px;'><strong>Name</strong></td>
                        <td style='border-bottom:1px solid #ddd;'>{$name}</td>
                    </tr>
                    <tr>
                        <td style='border-bottom:1px solid #ddd;'><strong>Email</strong></td>
                        <td style='border-bottom:1px solid #ddd;'>{$email}</td>
                    </tr>
                    <tr>
                        <td style='border-bottom:1px solid #ddd;'><strong>Stay dates</strong></td>
                        <td style='border-bottom:1px solid #ddd;'>{$stay}</td>
                    </tr>
                    <tr>
                        <td style='border-bottom:1px solid #ddd;'><strong>Accommodation</strong></td>
                        <td style='border-bottom:1px solid #ddd;'>{$room}</td>
                    </tr>
                    <tr>
                        <td style='border-bottom:1px solid #ddd;'><strong>Adults</strong></td>
                        <td style='border-bottom:1px solid #ddd;'>{$adults}</td>
                    </tr>
                    <tr>
                        <td><strong>Children</strong></td>
                        <td>{$children}</td>
                    </tr>
                </table>
                <h3 style='margin:26px 0 12px;'>Property details</h3>
                <table cellpadding='8' cellspacing='0' width='100%' style='border-collapse:collapse;'>
                    <tr>
                        <td style='border-bottom:1px solid #ddd; width:180px;'><strong>Property</strong></td>
                        <td style='border-bottom:1px solid #ddd;'>Podere La Cavallara</td>
                    </tr>
                    <tr>
                        <td style='border-bottom:1px solid #ddd;'><strong>Location</strong></td>
                        <td style='border-bottom:1px solid #ddd;'>Montefiascone, Italy</td>
                    </tr>
                    <tr>
                        <td style='border-bottom:1px solid #ddd;'><strong>Website</strong></td>
                        <td style='border-bottom:1px solid #ddd;'><a href='{$websiteEscaped}' target='_blank' rel='noopener'>{$websiteEscaped}</a></td>
                    </tr>
                    <tr>
                        <td><strong>Email</strong></td>
                        <td>{$structureEmail}</td>
                    </tr>
                </table>
                <p style='margin-top:20px;'>If you need anything, you can simply reply to this email.</p>
            ",
        ];
    }

    return [
        'subject' => 'Prenotazione confermata - Podere La Cavallara',
        'heading_from' => 'Grazie per averci contattato!',
        'heading_to' => 'La tua prenotazione è confermata!',
        'section_from' => 'Richiesta',
        'section_to' => 'Prenotazione confermata',
        'content' => "
            <p>Gentile {$name},</p>
            <p>complimenti, la tua prenotazione è stata confermata con successo.</p>
            <p>Ti aspettiamo a <strong>Podere La Cavallara</strong>, a Montefiascone, per il tuo soggiorno.</p>
            <h3 style='margin:26px 0 12px;'>Riepilogo prenotazione</h3>
            <table cellpadding='8' cellspacing='0' width='100%' style='border-collapse:collapse;'>
                <tr>
                    <td style='border-bottom:1px solid #ddd; width:180px;'><strong>Nome</strong></td>
                    <td style='border-bottom:1px solid #ddd;'>{$name}</td>
                </tr>
                <tr>
                    <td style='border-bottom:1px solid #ddd;'><strong>Email</strong></td>
                    <td style='border-bottom:1px solid #ddd;'>{$email}</td>
                </tr>
                <tr>
                    <td style='border-bottom:1px solid #ddd;'><strong>Soggiorno</strong></td>
                    <td style='border-bottom:1px solid #ddd;'>{$stay}</td>
                </tr>
                <tr>
                    <td style='border-bottom:1px solid #ddd;'><strong>Sistemazione</strong></td>
                    <td style='border-bottom:1px solid #ddd;'>{$room}</td>
                </tr>
                <tr>
                    <td style='border-bottom:1px solid #ddd;'><strong>Adulti</strong></td>
                    <td style='border-bottom:1px solid #ddd;'>{$adults}</td>
                </tr>
                <tr>
                    <td><strong>Bambini</strong></td>
                    <td>{$children}</td>
                </tr>
            </table>
            <h3 style='margin:26px 0 12px;'>Dati struttura</h3>
            <table cellpadding='8' cellspacing='0' width='100%' style='border-collapse:collapse;'>
                <tr>
                    <td style='border-bottom:1px solid #ddd; width:180px;'><strong>Struttura</strong></td>
                    <td style='border-bottom:1px solid #ddd;'>Podere La Cavallara</td>
                </tr>
                <tr>
                    <td style='border-bottom:1px solid #ddd;'><strong>Località</strong></td>
                    <td style='border-bottom:1px solid #ddd;'>Montefiascone, Italia</td>
                </tr>
                <tr>
                    <td style='border-bottom:1px solid #ddd;'><strong>Sito web</strong></td>
                    <td style='border-bottom:1px solid #ddd;'><a href='{$websiteEscaped}' target='_blank' rel='noopener'>{$websiteEscaped}</a></td>
                </tr>
                <tr>
                    <td><strong>Email</strong></td>
                    <td>{$structureEmail}</td>
                </tr>
            </table>
            <p style='margin-top:20px;'>Per qualsiasi necessità puoi rispondere direttamente a questa email.</p>
        ",
    ];
}

function admin_build_booking_confirmation_body(string $language, array $booking): string
{
    $copy = admin_booking_confirmation_copy($language, $booking);
    $templatePath = admin_booking_template_path($language);

    if ($templatePath === null) {
        return $copy['content'];
    }

    $html = (string) file_get_contents($templatePath);
    $html = str_replace($copy['heading_from'], $copy['heading_to'], $html);
    $html = str_replace($copy['section_from'], $copy['section_to'], $html);
    $html = str_replace(['message', 'messaggio'], $copy['content'], $html);

    return $html;
}

function admin_send_booking_confirmation_email(array $booking, string $language): array
{
    global $MAIL_FROM, $MAIL_FROM_NAME, $MAIL_ADMIN, $MAIL_ADMIN_NAME, $SMTP_HOST, $SMTP_USER, $SMTP_PASS, $SMTP_PORT;

    $customerEmail = trim((string) ($booking['customer_email'] ?? ''));
    if ($customerEmail === '' || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
        return [
            'success' => false,
            'skipped' => true,
            'message' => 'Email cliente assente o non valida.',
            'language' => $language,
        ];
    }

    $copy = admin_booking_confirmation_copy($language, $booking);
    $body = admin_build_booking_confirmation_body($language, $booking);

    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        return [
            'success' => false,
            'skipped' => false,
            'message' => 'PHPMailer non disponibile nel progetto admin.',
            'language' => $language,
        ];
    }

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = $SMTP_USER;
        $mail->Password = $SMTP_PASS;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $SMTP_PORT;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($MAIL_FROM, $MAIL_FROM_NAME);
        $mail->addAddress($customerEmail, (string) ($booking['customer_name'] ?? ''));
        if (!empty($MAIL_ADMIN)) {
            $mail->addReplyTo($MAIL_ADMIN, $MAIL_ADMIN_NAME ?? $MAIL_FROM_NAME);
        }
        $mail->isHTML(true);
        $mail->Subject = $copy['subject'];
        $mail->Body = $body;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $body));
        $mail->send();

        return [
            'success' => true,
            'skipped' => false,
            'message' => 'Email di conferma inviata correttamente.',
            'language' => $language,
        ];
    } catch (Throwable $e) {
        return [
            'success' => false,
            'skipped' => false,
            'message' => $e->getMessage(),
            'language' => $language,
        ];
    }
}
