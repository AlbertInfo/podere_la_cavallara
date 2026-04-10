<?php

declare(strict_types=1);

function customer_sync_table_exists(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM clienti LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function customer_sync_has_column(PDO $pdo, string $table, string $column): bool
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

function customer_sync_phone_key(?string $phone): ?string
{
    $phone = admin_normalize_optional_phone((string) $phone);
    if ($phone === null) {
        return null;
    }

    $normalized = preg_replace('/[^0-9+]/u', '', $phone);
    $normalized = $normalized !== null ? trim($normalized) : '';
    return $normalized !== '' ? $normalized : null;
}

function customer_sync_split_name(string $fullName): array
{
    $normalized = preg_replace('/\s+/u', ' ', trim($fullName));
    $fullName = trim($normalized !== null ? $normalized : trim($fullName));

    if ($fullName === '') {
        return ['first_name' => '', 'last_name' => ''];
    }

    $parts = array_values(array_filter(explode(' ', $fullName), function ($part) {
        return $part !== '';
    }));

    if (count($parts) === 1) {
        return ['first_name' => $parts[0], 'last_name' => ''];
    }

    $firstName = array_shift($parts);
    if (!is_string($firstName)) {
        $firstName = '';
    }

    return [
        'first_name' => $firstName,
        'last_name' => implode(' ', $parts),
    ];
}

function customer_sync_full_name(array $row): string
{
    $firstName = trim((string) ($row['first_name'] ?? ''));
    $lastName = trim((string) ($row['last_name'] ?? ''));
    return trim($firstName . ' ' . $lastName);
}

function customer_sync_extract_booking_identity(array $booking): array
{
    $split = customer_sync_split_name(trim((string) ($booking['customer_name'] ?? '')));

    $email = admin_normalize_optional_email((string) ($booking['customer_email'] ?? ''));
    $phone = admin_normalize_optional_phone((string) ($booking['customer_phone'] ?? ''));
    $phoneNormalized = customer_sync_phone_key($phone);

    $guestLanguage = trim((string) ($booking['guest_language'] ?? ''));
    $guestCountryCode = admin_normalize_country_code((string) ($booking['guest_country_code'] ?? ''));

    $rawPayload = $booking['raw_payload'] ?? null;
    if (($guestLanguage === '' || $guestCountryCode === null) && is_string($rawPayload) && $rawPayload !== '') {
        $decoded = json_decode($rawPayload, true);
        if (is_array($decoded)) {
            if ($guestLanguage === '') {
                $guestLanguage = trim((string) (
                    $decoded['guest_language']
                    ?? $decoded['detected_language']
                    ?? ($decoded['original_row']['_language'] ?? '')
                ));
            }

            if ($guestCountryCode === null) {
                $candidateCountry = $decoded['guest_country_code'] ?? ($decoded['original_row']['guest_country_code'] ?? null);
                $guestCountryCode = admin_normalize_country_code(is_string($candidateCountry) ? $candidateCountry : '');
                if ($guestCountryCode === null && $guestLanguage !== '') {
                    $guestCountryCode = admin_normalize_country_code(customer_language_to_country_code($guestLanguage));
                }
            }
        }
    }

    return [
        'first_name' => $split['first_name'],
        'last_name' => $split['last_name'],
        'email' => $email,
        'phone' => $phone,
        'phone_normalized' => $phoneNormalized,
        'guest_language' => $guestLanguage !== '' ? $guestLanguage : null,
        'guest_country_code' => $guestCountryCode,
    ];
}

function customer_sync_find_existing(PDO $pdo, array $customer): ?array
{
    if (!customer_sync_table_exists($pdo)) {
        return null;
    }

    if (!empty($customer['email'])) {
        $stmt = $pdo->prepare('SELECT * FROM clienti WHERE LOWER(email) = LOWER(:email) ORDER BY id ASC LIMIT 1');
        $stmt->execute(['email' => $customer['email']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
    }

    if (!empty($customer['phone_normalized'])) {
        $stmt = $pdo->prepare('SELECT * FROM clienti WHERE phone_normalized = :phone_normalized ORDER BY id ASC LIMIT 1');
        $stmt->execute(['phone_normalized' => $customer['phone_normalized']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
    }

    if (!empty($customer['first_name']) || !empty($customer['last_name'])) {
        $stmt = $pdo->prepare('SELECT * FROM clienti WHERE first_name = :first_name AND last_name = :last_name ORDER BY id ASC LIMIT 1');
        $stmt->execute([
            'first_name' => (string) ($customer['first_name'] ?? ''),
            'last_name' => (string) ($customer['last_name'] ?? ''),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
    }

    return null;
}

function customer_sync_upsert(PDO $pdo, array $customer, string $source = 'prenotazione_sync'): ?int
{
    if (!customer_sync_table_exists($pdo)) {
        return null;
    }

    $existing = customer_sync_find_existing($pdo, $customer);

    $payload = [
        'first_name' => trim((string) ($customer['first_name'] ?? '')),
        'last_name' => trim((string) ($customer['last_name'] ?? '')),
        'email' => admin_normalize_optional_email((string) ($customer['email'] ?? '')),
        'phone' => admin_normalize_optional_phone((string) ($customer['phone'] ?? '')),
        'phone_normalized' => customer_sync_phone_key((string) ($customer['phone'] ?? '')),
        'guest_country_code' => admin_normalize_country_code((string) ($customer['guest_country_code'] ?? '')),
        'guest_language' => trim((string) ($customer['guest_language'] ?? '')) ?: null,
        'source' => trim($source) !== '' ? trim($source) : 'prenotazione_sync',
        'notes' => trim((string) ($customer['notes'] ?? '')) ?: null,
    ];

    if ($existing) {
        $update = [
            'id' => (int) $existing['id'],
            'first_name' => $payload['first_name'] !== '' ? $payload['first_name'] : (string) ($existing['first_name'] ?? ''),
            'last_name' => $payload['last_name'] !== '' ? $payload['last_name'] : (string) ($existing['last_name'] ?? ''),
            'email' => $payload['email'] !== null ? $payload['email'] : (((string) ($existing['email'] ?? '')) !== '' ? (string) $existing['email'] : null),
            'phone' => $payload['phone'] !== null ? $payload['phone'] : (((string) ($existing['phone'] ?? '')) !== '' ? (string) $existing['phone'] : null),
            'phone_normalized' => $payload['phone_normalized'] !== null ? $payload['phone_normalized'] : (((string) ($existing['phone_normalized'] ?? '')) !== '' ? (string) $existing['phone_normalized'] : null),
            'guest_country_code' => $payload['guest_country_code'] !== null ? $payload['guest_country_code'] : (((string) ($existing['guest_country_code'] ?? '')) !== '' ? (string) $existing['guest_country_code'] : null),
            'guest_language' => $payload['guest_language'] !== null ? $payload['guest_language'] : (((string) ($existing['guest_language'] ?? '')) !== '' ? (string) $existing['guest_language'] : null),
            'source' => ((string) ($existing['source'] ?? '')) !== '' ? (string) $existing['source'] : $payload['source'],
            'notes' => $payload['notes'] !== null ? $payload['notes'] : (((string) ($existing['notes'] ?? '')) !== '' ? (string) $existing['notes'] : null),
        ];

        $stmt = $pdo->prepare('UPDATE clienti SET
            first_name = :first_name,
            last_name = :last_name,
            email = :email,
            phone = :phone,
            phone_normalized = :phone_normalized,
            guest_country_code = :guest_country_code,
            guest_language = :guest_language,
            source = :source,
            notes = :notes,
            updated_at = NOW()
        WHERE id = :id LIMIT 1');
        $stmt->execute($update);

        return (int) $existing['id'];
    }

    $stmt = $pdo->prepare('INSERT INTO clienti (
        first_name,
        last_name,
        email,
        phone,
        phone_normalized,
        guest_country_code,
        guest_language,
        source,
        notes,
        created_at,
        updated_at
    ) VALUES (
        :first_name,
        :last_name,
        :email,
        :phone,
        :phone_normalized,
        :guest_country_code,
        :guest_language,
        :source,
        :notes,
        NOW(),
        NOW()
    )');

    $stmt->execute($payload);
    return (int) $pdo->lastInsertId();
}

function customer_sync_attach_to_booking(PDO $pdo, int $bookingId, ?int $customerId): void
{
    if ($bookingId <= 0 || $customerId === null || !customer_sync_has_column($pdo, 'prenotazioni', 'cliente_id')) {
        return;
    }

    $stmt = $pdo->prepare('UPDATE prenotazioni SET cliente_id = :cliente_id WHERE id = :id LIMIT 1');
    $stmt->execute([
        'cliente_id' => $customerId,
        'id' => $bookingId,
    ]);
}

function customer_sync_from_booking(PDO $pdo, array $booking, string $source = 'prenotazione_sync'): ?int
{
    $identity = customer_sync_extract_booking_identity($booking);
    if ($identity['first_name'] === '' && $identity['last_name'] === '' && $identity['email'] === null && $identity['phone'] === null) {
        return null;
    }

    return customer_sync_upsert($pdo, $identity, $source);
}

function customer_sync_booking_row(PDO $pdo, array $booking, string $source = 'prenotazione_sync'): ?int
{
    $bookingId = (int) ($booking['id'] ?? 0);
    $customerId = customer_sync_from_booking($pdo, $booking, $source);
    if ($bookingId > 0 && $customerId !== null) {
        customer_sync_attach_to_booking($pdo, $bookingId, $customerId);
    }
    return $customerId;
}
