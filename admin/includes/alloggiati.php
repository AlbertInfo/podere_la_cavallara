<?php

declare(strict_types=1);

require_once __DIR__ . '/anagrafica-options.php';

function alloggiati_schedine_table_ready(PDO $pdo): bool
{
    try {
        return (bool) $pdo->query("SHOW TABLES LIKE 'alloggiati_schedine'")->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function alloggiati_record_ids_for_day(PDO $pdo, string $arrivalDate): array
{
    $stmt = $pdo->prepare('SELECT id FROM anagrafica_records WHERE arrival_date = :arrival_date ORDER BY id ASC');
    $stmt->execute(['arrival_date' => $arrivalDate]);
    return array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));
}

function alloggiati_fetch_record(PDO $pdo, int $recordId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM anagrafica_records WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $recordId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function alloggiati_fetch_record_guests(PDO $pdo, int $recordId): array
{
    $stmt = $pdo->prepare('SELECT * FROM anagrafica_guests WHERE record_id = :record_id ORDER BY is_group_leader DESC, id ASC');
    $stmt->execute(['record_id' => $recordId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function alloggiati_format_date(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '';
    }
    $ts = strtotime($value);
    return $ts ? date('Ymd', $ts) : '';
}

function alloggiati_normalize_scalar($value): string
{
    return trim((string) ($value ?? ''));
}

function alloggiati_document_required(string $tipoAlloggiatoCode): bool
{
    return in_array($tipoAlloggiatoCode, ['16', '17', '18'], true);
}

function alloggiati_persistence_days(?string $arrivalDate, ?string $departureDate): int
{
    $arrivalTs = strtotime((string) $arrivalDate);
    $departureTs = strtotime((string) $departureDate);
    if (!$arrivalTs || !$departureTs || $departureTs <= $arrivalTs) {
        return 1;
    }

    $days = (int) ceil(($departureTs - $arrivalTs) / 86400);
    $days = max(1, min(30, $days));
    return $days;
}

function alloggiati_birth_place_value(array $guest): string
{
    $italyCode = anagrafica_default_italy_state_code();
    if ((string) ($guest['birth_state_code'] ?? '') === $italyCode) {
        return alloggiati_normalize_scalar($guest['birth_city_code'] ?? '');
    }

    return alloggiati_normalize_scalar($guest['birth_state_code'] ?? '');
}

function alloggiati_document_issue_place_value(array $guest): string
{
    $code = alloggiati_normalize_scalar($guest['document_issue_place_code'] ?? '');
    if ($code !== '') {
        return $code;
    }

    return alloggiati_normalize_scalar($guest['document_issue_place'] ?? '');
}

function alloggiati_tipo_alloggiato_label(string $code): string
{
    $options = anagrafica_tipo_alloggiato_options();
    return (string) ($options[$code] ?? $code);
}

function alloggiati_build_schedina_payload(array $record, array $guest): array
{
    $tipoAlloggiato = (string) ($guest['tipoalloggiato_code'] ?? '');
    $arrivalDate = substr((string) ($record['arrival_date'] ?? ''), 0, 10);
    $departureDate = substr((string) ($record['departure_date'] ?? ''), 0, 10);
    $isLeader = (int) ($guest['is_group_leader'] ?? 0) === 1;

    return [
        'record_id' => (int) ($record['id'] ?? 0),
        'guest_row_id' => (int) ($guest['id'] ?? 0),
        'guest_idswh' => (string) ($guest['guest_idswh'] ?? ''),
        'leader_idswh' => (string) ($guest['leader_idswh'] ?? ''),
        'is_group_leader' => $isLeader,
        'tipo_alloggiato_code' => $tipoAlloggiato,
        'tipo_alloggiato_label' => alloggiati_tipo_alloggiato_label($tipoAlloggiato),
        'arrival_date' => $arrivalDate,
        'arrival_date_xml' => alloggiati_format_date($arrivalDate),
        'permanence_days' => alloggiati_persistence_days($arrivalDate, $departureDate),
        'citizenship_code' => (string) ($guest['citizenship_code'] ?? ''),
        'birth_place_value' => alloggiati_birth_place_value($guest),
        'last_name' => (string) ($guest['last_name'] ?? ''),
        'first_name' => (string) ($guest['first_name'] ?? ''),
        'birth_date' => substr((string) ($guest['birth_date'] ?? ''), 0, 10),
        'birth_date_xml' => alloggiati_format_date((string) ($guest['birth_date'] ?? '')),
        'gender' => (string) ($guest['gender'] ?? ''),
        'document_type_code' => (string) ($guest['document_type_code'] ?? ''),
        'document_type_label' => (string) ($guest['document_type_label'] ?? ''),
        'document_number' => (string) ($guest['document_number'] ?? ''),
        'document_issue_place_value' => alloggiati_document_issue_place_value($guest),
        'display_name' => trim((string) (($guest['first_name'] ?? '') . ' ' . ($guest['last_name'] ?? ''))),
    ];
}

function alloggiati_validate_schedina_payload(array $payload): array
{
    $errors = [];

    $required = [
        'guest_idswh' => 'ID SWH mancante.',
        'tipo_alloggiato_code' => 'Tipo alloggiato mancante.',
        'arrival_date_xml' => 'Data arrivo mancante.',
        'citizenship_code' => 'Cittadinanza mancante.',
        'birth_place_value' => 'Luogo di nascita mancante.',
        'last_name' => 'Cognome mancante.',
        'first_name' => 'Nome mancante.',
        'birth_date_xml' => 'Data di nascita mancante.',
        'gender' => 'Sesso mancante.',
    ];

    foreach ($required as $key => $message) {
        if (alloggiati_normalize_scalar($payload[$key] ?? '') === '') {
            $errors[] = $message;
        }
    }

    $permanence = (int) ($payload['permanence_days'] ?? 0);
    if ($permanence < 1 || $permanence > 30) {
        $errors[] = 'Permanenza non valida (1-30 giorni).';
    }

    if (alloggiati_document_required((string) ($payload['tipo_alloggiato_code'] ?? ''))) {
        if (alloggiati_normalize_scalar($payload['document_type_code'] ?? '') === '') {
            $errors[] = 'Tipo documento mancante.';
        }
        if (alloggiati_normalize_scalar($payload['document_number'] ?? '') === '') {
            $errors[] = 'Numero documento mancante.';
        }
        if (alloggiati_normalize_scalar($payload['document_issue_place_value'] ?? '') === '') {
            $errors[] = 'Luogo rilascio documento mancante.';
        }
    }

    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $arrivalDate = (string) ($payload['arrival_date'] ?? '');
    $windowStatus = 'pronta';
    if ($arrivalDate > $today) {
        $windowStatus = 'bozza';
    } elseif ($arrivalDate < $yesterday) {
        $errors[] = 'Data arrivo fuori finestra Alloggiati (oggi o ieri).';
        $windowStatus = 'errore';
    }

    if ($errors) {
        return [
            'status' => 'errore',
            'errors' => $errors,
        ];
    }

    return [
        'status' => $windowStatus,
        'errors' => [],
    ];
}

function alloggiati_payload_hash(array $payload): string
{
    return sha1((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function alloggiati_sync_record(PDO $pdo, int $recordId): array
{
    if (!alloggiati_schedine_table_ready($pdo)) {
        return [];
    }

    $record = alloggiati_fetch_record($pdo, $recordId);
    if (!$record) {
        $pdo->prepare('DELETE FROM alloggiati_schedine WHERE record_id = :record_id')->execute(['record_id' => $recordId]);
        return [];
    }

    $guests = alloggiati_fetch_record_guests($pdo, $recordId);
    $existingStmt = $pdo->prepare('SELECT * FROM alloggiati_schedine WHERE record_id = :record_id');
    $existingStmt->execute(['record_id' => $recordId]);
    $existingRows = [];
    foreach ($existingStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $existingRows[(string) ($row['guest_idswh'] ?? '')] = $row;
    }

    $keptGuestIds = [];
    $upsert = $pdo->prepare(
        'INSERT INTO alloggiati_schedine (
            record_id, guest_row_id, guest_idswh, leader_idswh, is_group_leader, arrival_date, tipo_alloggiato_code, display_name,
            payload_json, payload_hash, status, validation_errors, last_error, sent_at, last_attempt_at, attempt_count, created_at, updated_at
        ) VALUES (
            :record_id, :guest_row_id, :guest_idswh, :leader_idswh, :is_group_leader, :arrival_date, :tipo_alloggiato_code, :display_name,
            :payload_json, :payload_hash, :status, :validation_errors, :last_error, :sent_at, :last_attempt_at, :attempt_count, NOW(), NOW()
        ) ON DUPLICATE KEY UPDATE
            guest_row_id = VALUES(guest_row_id),
            leader_idswh = VALUES(leader_idswh),
            is_group_leader = VALUES(is_group_leader),
            arrival_date = VALUES(arrival_date),
            tipo_alloggiato_code = VALUES(tipo_alloggiato_code),
            display_name = VALUES(display_name),
            payload_json = VALUES(payload_json),
            payload_hash = VALUES(payload_hash),
            status = VALUES(status),
            validation_errors = VALUES(validation_errors),
            last_error = VALUES(last_error),
            sent_at = VALUES(sent_at),
            last_attempt_at = VALUES(last_attempt_at),
            attempt_count = VALUES(attempt_count),
            updated_at = NOW()'
    );

    foreach ($guests as $guest) {
        $payload = alloggiati_build_schedina_payload($record, $guest);
        $validation = alloggiati_validate_schedina_payload($payload);
        $payloadHash = alloggiati_payload_hash($payload);
        $guestIdswh = (string) $payload['guest_idswh'];
        $keptGuestIds[] = $guestIdswh;
        $existing = $existingRows[$guestIdswh] ?? null;

        $status = (string) $validation['status'];
        $sentAt = null;
        $lastAttemptAt = $existing['last_attempt_at'] ?? null;
        $attemptCount = (int) ($existing['attempt_count'] ?? 0);
        $validationErrors = implode("\n", $validation['errors']);
        $lastError = $validationErrors !== '' ? $validationErrors : null;

        if ($existing && (string) ($existing['status'] ?? '') === 'inviata' && (string) ($existing['payload_hash'] ?? '') === $payloadHash) {
            $status = 'inviata';
            $sentAt = $existing['sent_at'] ?? null;
            $lastError = null;
        }

        $upsert->execute([
            'record_id' => $recordId,
            'guest_row_id' => (int) ($payload['guest_row_id'] ?? 0),
            'guest_idswh' => $guestIdswh,
            'leader_idswh' => (string) ($payload['leader_idswh'] ?? ''),
            'is_group_leader' => !empty($payload['is_group_leader']) ? 1 : 0,
            'arrival_date' => (string) ($payload['arrival_date'] ?? ''),
            'tipo_alloggiato_code' => (string) ($payload['tipo_alloggiato_code'] ?? ''),
            'display_name' => (string) ($payload['display_name'] ?? ''),
            'payload_json' => (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'payload_hash' => $payloadHash,
            'status' => $status,
            'validation_errors' => $validationErrors !== '' ? $validationErrors : null,
            'last_error' => $lastError,
            'sent_at' => $sentAt,
            'last_attempt_at' => $lastAttemptAt,
            'attempt_count' => $attemptCount,
        ]);
    }

    if ($keptGuestIds) {
        $placeholders = implode(',', array_fill(0, count($keptGuestIds), '?'));
        $params = array_merge([$recordId], $keptGuestIds);
        $delete = $pdo->prepare("DELETE FROM alloggiati_schedine WHERE record_id = ? AND guest_idswh NOT IN ($placeholders)");
        $delete->execute($params);
    } else {
        $pdo->prepare('DELETE FROM alloggiati_schedine WHERE record_id = :record_id')->execute(['record_id' => $recordId]);
    }

    return alloggiati_fetch_schedine_by_record($pdo, $recordId);
}

function alloggiati_sync_day(PDO $pdo, string $arrivalDate): array
{
    if (!alloggiati_schedine_table_ready($pdo)) {
        return [];
    }

    foreach (alloggiati_record_ids_for_day($pdo, $arrivalDate) as $recordId) {
        alloggiati_sync_record($pdo, $recordId);
    }

    return alloggiati_fetch_day_schedine($pdo, $arrivalDate);
}

function alloggiati_fetch_day_schedine(PDO $pdo, string $arrivalDate): array
{
    if (!alloggiati_schedine_table_ready($pdo)) {
        return [];
    }

    $stmt = $pdo->prepare('SELECT * FROM alloggiati_schedine WHERE arrival_date = :arrival_date ORDER BY status = "errore" DESC, status = "pronta" DESC, is_group_leader DESC, id ASC');
    $stmt->execute(['arrival_date' => $arrivalDate]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $row['payload'] = json_decode((string) ($row['payload_json'] ?? ''), true) ?: [];
        $row['validation_errors_list'] = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) ($row['validation_errors'] ?? '')) ?: [])));
    }
    unset($row);
    return $rows;
}

function alloggiati_fetch_schedina(PDO $pdo, int $schedinaId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM alloggiati_schedine WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $schedinaId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $row['payload'] = json_decode((string) ($row['payload_json'] ?? ''), true) ?: [];
    $row['validation_errors_list'] = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) ($row['validation_errors'] ?? '')) ?: [])));
    return $row;
}

function alloggiati_fetch_schedine_by_record(PDO $pdo, int $recordId): array
{
    $stmt = $pdo->prepare('SELECT * FROM alloggiati_schedine WHERE record_id = :record_id ORDER BY is_group_leader DESC, id ASC');
    $stmt->execute(['record_id' => $recordId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function alloggiati_day_status_counts(array $schedine): array
{
    $counts = [
        'total' => count($schedine),
        'bozza' => 0,
        'pronta' => 0,
        'inviata' => 0,
        'errore' => 0,
    ];

    foreach ($schedine as $row) {
        $status = (string) ($row['status'] ?? 'bozza');
        if (!isset($counts[$status])) {
            continue;
        }
        $counts[$status]++;
    }

    return $counts;
}

function alloggiati_mark_schedina_sent(PDO $pdo, int $schedinaId): array
{
    $schedina = alloggiati_fetch_schedina($pdo, $schedinaId);
    if (!$schedina) {
        return ['sent' => 0, 'errors' => ['Schedina non trovata.'], 'arrival_date' => ''];
    }

    alloggiati_sync_record($pdo, (int) $schedina['record_id']);
    $schedina = alloggiati_fetch_schedina($pdo, $schedinaId);
    if (!$schedina) {
        return ['sent' => 0, 'errors' => ['Schedina non trovata dopo la sincronizzazione.'], 'arrival_date' => ''];
    }

    if ((string) $schedina['status'] !== 'pronta') {
        $errors = $schedina['validation_errors_list'] ?: ['La schedina non è pronta per l’invio.'];
        return ['sent' => 0, 'errors' => $errors, 'arrival_date' => (string) $schedina['arrival_date']];
    }

    $stmt = $pdo->prepare('UPDATE alloggiati_schedine SET status = :status, sent_at = NOW(), last_attempt_at = NOW(), attempt_count = attempt_count + 1, last_error = NULL, validation_errors = NULL, updated_at = NOW() WHERE id = :id');
    $stmt->execute([
        'status' => 'inviata',
        'id' => $schedinaId,
    ]);

    return ['sent' => 1, 'errors' => [], 'arrival_date' => (string) $schedina['arrival_date']];
}

function alloggiati_mark_day_sent(PDO $pdo, string $arrivalDate): array
{
    $schedine = alloggiati_sync_day($pdo, $arrivalDate);
    $readyIds = [];
    $errors = [];

    foreach ($schedine as $row) {
        if ((string) ($row['status'] ?? '') === 'pronta') {
            $readyIds[] = (int) $row['id'];
        } elseif ((string) ($row['status'] ?? '') === 'errore') {
            $errors[] = (string) ($row['display_name'] ?? 'Schedina') . ': ' . ((string) ($row['last_error'] ?? 'Errore di validazione'));
        }
    }

    if (!$readyIds) {
        return [
            'sent' => 0,
            'errors' => $errors ?: ['Nessuna schedina pronta da inviare per il giorno selezionato.'],
        ];
    }

    $placeholders = implode(',', array_fill(0, count($readyIds), '?'));
    $stmt = $pdo->prepare("UPDATE alloggiati_schedine SET status = 'inviata', sent_at = NOW(), last_attempt_at = NOW(), attempt_count = attempt_count + 1, last_error = NULL, validation_errors = NULL, updated_at = NOW() WHERE id IN ($placeholders)");
    $stmt->execute($readyIds);

    return [
        'sent' => count($readyIds),
        'errors' => $errors,
    ];
}
