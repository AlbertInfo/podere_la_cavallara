<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function current_admin(): ?array
{
    return $_SESSION[ADMIN_SESSION_KEY] ?? null;
}

function require_admin(): void
{
    if (!current_admin()) {
        if (wants_json_response()) {
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Sessione non valida o scaduta.']);
            exit;
        }
        header('Location: ' . admin_url('login.php'));
        exit;
    }
}

function login_admin(array $admin): void
{
    session_regenerate_id(true);
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => (int) $admin['id'],
        'name' => (string) $admin['name'],
        'email' => (string) $admin['email'],
    ];
}

function logout_admin(): void
{
    unset($_SESSION[ADMIN_SESSION_KEY]);
    session_regenerate_id(true);
}

function find_admin_by_email(PDO $pdo, string $email): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM admin_users WHERE email = :email AND is_active = 1 LIMIT 1');
    $stmt->execute(['email' => $email]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    return $admin ?: null;
}

function send_password_reset_email(PDO $pdo, array $admin): bool
{
    global $MAIL_FROM, $MAIL_FROM_NAME, $MAIL_ADMIN, $MAIL_ADMIN_NAME, $SMTP_HOST, $SMTP_USER, $SMTP_PASS, $SMTP_PORT;

    $token = bin2hex(random_bytes(32));
    $tokenHash = password_hash($token, PASSWORD_DEFAULT);
    $expiresAt = (new DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare('INSERT INTO admin_password_resets (admin_user_id, token_hash, expires_at) VALUES (:admin_user_id, :token_hash, :expires_at)');
    $stmt->execute([
        'admin_user_id' => $admin['id'],
        'token_hash' => $tokenHash,
        'expires_at' => $expiresAt,
    ]);

    $resetLink = admin_url('reset-password.php') . '?token=' . urlencode($token) . '&email=' . urlencode($admin['email']);

    $subject = 'Recupero password area admin';
    $body = "<p>Ciao " . e($admin['name']) . ",</p>"
        . "<p>Hai richiesto il recupero password dell'area admin.</p>"
        . "<p><a href=\"" . e($resetLink) . "\">Clicca qui per impostare una nuova password</a></p>"
        . "<p>Il link scade tra 1 ora.</p>";

    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
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
            $mail->addAddress($admin['email'], $admin['name']);
            if (!empty($MAIL_ADMIN)) {
                $mail->addReplyTo($MAIL_ADMIN, $MAIL_ADMIN_NAME ?? $MAIL_FROM_NAME);
            }
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $body;
            return $mail->send();
        } catch (Throwable $e) {
            return false;
        }
    }

    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8\r\n";
    $headers .= 'From: ' . ($MAIL_FROM_NAME ?? 'Admin') . ' <' . $MAIL_FROM . ">\r\n";
    return @mail($admin['email'], $subject, $body, $headers);
}

function validate_password_reset(PDO $pdo, string $email, string $token): ?array
{
    $admin = find_admin_by_email($pdo, $email);
    if (!$admin) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM admin_password_resets WHERE admin_user_id = :admin_user_id AND used_at IS NULL AND expires_at >= NOW() ORDER BY id DESC');
    $stmt->execute(['admin_user_id' => $admin['id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        if (password_verify($token, $row['token_hash'])) {
            return ['admin' => $admin, 'reset' => $row];
        }
    }

    return null;
}

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}


function normalize_optional_email(string $email): ?string
{
    $email = trim($email);

    if ($email === '') {
        return null;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    return $email;
}

function normalize_optional_phone(string $phone): ?string
{
    $phone = trim($phone);

    if ($phone === '' || $phone === 'Non presente nel PDF') {
        return null;
    }

    $phone = preg_replace('/\s+/u', ' ', $phone) ?: $phone;
    $phone = trim($phone);

    return $phone === '' ? null : $phone;
}

function language_to_country_code(?string $language): ?string
{
    $language = trim((string) $language);

    if ($language === '') {
        return null;
    }

    $map = [
        'Italiano' => 'it',
        'Italian' => 'it',
        'IT' => 'it',
        'it' => 'it',

        'Inglese' => 'gb',
        'English' => 'gb',
        'EN' => 'gb',
        'en' => 'gb',

        'Tedesco' => 'de',
        'Deutsch' => 'de',
        'German' => 'de',
        'DE' => 'de',
        'de' => 'de',

        'Ceco' => 'cz',
        'Czech' => 'cz',
        'CZ' => 'cz',
        'cz' => 'cz',

        'Polacco' => 'pl',
        'Polish' => 'pl',
        'PL' => 'pl',
        'pl' => 'pl',

        'Olandese' => 'nl',
        'Dutch' => 'nl',
        'NL' => 'nl',
        'nl' => 'nl',

        'Francese' => 'fr',
        'French' => 'fr',
        'FR' => 'fr',
        'fr' => 'fr',

        'Spagnolo' => 'es',
        'Spanish' => 'es',
        'ES' => 'es',
        'es' => 'es',
    ];

    return isset($map[$language]) ? $map[$language] : null;
}

function normalize_country_code(?string $countryCode): ?string
{
    $countryCode = strtolower(trim((string) $countryCode));

    if ($countryCode === '') {
        return null;
    }

    $allowed = ['it', 'gb', 'de', 'cz', 'pl', 'nl', 'fr', 'es'];

    return in_array($countryCode, $allowed, true) ? $countryCode : null;
}