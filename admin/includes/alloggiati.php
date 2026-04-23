<?php

declare(strict_types=1);

require_once __DIR__ . '/anagrafica-options.php';

function alloggiati_ws_config(): array
{
    $defaults = [
        'endpoint' => 'https://alloggiatiweb.poliziadistato.it/service/service.asmx',
        'wsdl' => 'https://alloggiatiweb.poliziadistato.it/service/service.asmx?wsdl',
        'utente' => '',
        'password' => '',
        'wskey' => '',
        'simulate_send_without_ws' => true,
    ];

    $configFile = __DIR__ . '/alloggiati-config.php';
    if (is_file($configFile)) {
        $config = require $configFile;
        if (is_array($config)) {
            return array_merge($defaults, $config);
        }
    }

    return $defaults;
}

function alloggiati_ws_config_ready(array $config): bool
{
    return trim((string) ($config['endpoint'] ?? '')) !== ''
        && trim((string) ($config['utente'] ?? '')) !== ''
        && trim((string) ($config['password'] ?? '')) !== ''
        && trim((string) ($config['wskey'] ?? '')) !== '';
}

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

function alloggiati_format_portal_date(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '';
    }
    $ts = strtotime($value);
    return $ts ? date('d/m/Y', $ts) : '';
}

function alloggiati_normalize_scalar($value): string
{
    return trim((string) ($value ?? ''));
}

function alloggiati_document_required(string $tipoAlloggiatoCode): bool
{
    return in_array($tipoAlloggiatoCode, ['16', '17', '18'], true);
}

function alloggiati_send_window_state(?string $arrivalDate): string
{
    $arrivalDate = substr(trim((string) ($arrivalDate ?? '')), 0, 10);
    if ($arrivalDate === '') {
        return 'invalid';
    }

    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    if ($arrivalDate > $today) {
        return 'future';
    }
    if ($arrivalDate < $yesterday) {
        return 'expired';
    }

    return 'sendable';
}

function alloggiati_send_window_message(string $windowState): string
{
    if ($windowState === 'future') {
        return 'Invio non disponibile: la data di arrivo è futura.';
    }
    if ($windowState === 'expired') {
        return 'Invio non disponibile: Alloggiati Web accetta solo schedine con arrivo di oggi o ieri.';
    }
    if ($windowState === 'invalid') {
        return 'Invio non disponibile: data di arrivo mancante o non valida.';
    }

    return '';
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
        'arrival_date_portal' => alloggiati_format_portal_date($arrivalDate),
        'permanence_days' => alloggiati_persistence_days($arrivalDate, $departureDate),
        'citizenship_code' => (string) ($guest['citizenship_code'] ?? ''),
        'birth_place_value' => alloggiati_birth_place_value($guest),
        'birth_city_code' => (string) ($guest['birth_city_code'] ?? ''),
        'birth_province_code' => (string) ($guest['birth_province'] ?? ''),
        'birth_state_code' => (string) ($guest['birth_state_code'] ?? ''),
        'last_name' => (string) ($guest['last_name'] ?? ''),
        'first_name' => (string) ($guest['first_name'] ?? ''),
        'birth_date' => substr((string) ($guest['birth_date'] ?? ''), 0, 10),
        'birth_date_xml' => alloggiati_format_date((string) ($guest['birth_date'] ?? '')),
        'birth_date_portal' => alloggiati_format_portal_date((string) ($guest['birth_date'] ?? '')),
        'gender' => (string) ($guest['gender'] ?? ''),
        'document_type_code' => (string) ($guest['document_type_code'] ?? ''),
        'document_type_label' => (string) ($guest['document_type_label'] ?? ''),
        'document_number' => (string) ($guest['document_number'] ?? ''),
        'document_issue_place_value' => alloggiati_document_issue_place_value($guest),
        'document_issue_place_code' => (string) ($guest['document_issue_place_code'] ?? ''),
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
        'birth_state_code' => 'Stato di nascita mancante.',
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

    $italyCode = anagrafica_default_italy_state_code();
    if ((string) ($payload['birth_state_code'] ?? '') === $italyCode) {
        if (alloggiati_normalize_scalar($payload['birth_city_code'] ?? '') === '') {
            $errors[] = 'Comune di nascita mancante.';
        }
        if (alloggiati_normalize_scalar($payload['birth_province_code'] ?? '') === '') {
            $errors[] = 'Provincia di nascita mancante.';
        }
    }

    $tipoAlloggiatoCode = (string) ($payload['tipo_alloggiato_code'] ?? '');
    if (alloggiati_document_required($tipoAlloggiatoCode)) {
        if (alloggiati_normalize_scalar($payload['document_type_code'] ?? '') === '') {
            $errors[] = 'Tipo documento mancante.';
        }
        if (alloggiati_normalize_scalar($payload['document_number'] ?? '') === '') {
            $errors[] = 'Numero documento mancante.';
        }
        if (alloggiati_normalize_scalar($payload['document_issue_place_code'] ?? '') === '') {
            $errors[] = 'Luogo rilascio documento mancante.';
        }
    }

    if (in_array($tipoAlloggiatoCode, ['19', '20'], true) && alloggiati_normalize_scalar($payload['leader_idswh'] ?? '') === '') {
        $errors[] = 'Il componente deve essere collegato al relativo capo famiglia / capo gruppo.';
    }

    $arrivalDate = (string) ($payload['arrival_date'] ?? '');
    $windowState = alloggiati_send_window_state($arrivalDate);
    $windowStatus = $windowState === 'future' ? 'bozza' : 'pronta';

    if ($errors) {
        return [
            'status' => 'errore',
            'errors' => array_values(array_unique($errors)),
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


function alloggiati_schedine_table_columns(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cache = [];
    $stmt = $pdo->query('SHOW COLUMNS FROM alloggiati_schedine');
    foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
        $field = (string) ($row['Field'] ?? '');
        if ($field !== '') {
            $cache[$field] = true;
        }
    }

    return $cache;
}

function alloggiati_sync_optional_schedina_columns(PDO $pdo, int $schedinaId, array $guest, array $payload): void
{
    $columns = alloggiati_schedine_table_columns($pdo);
    $assignments = [];
    $params = ['id' => $schedinaId];

    $map = [
        'first_name' => (string) ($guest['first_name'] ?? $payload['first_name'] ?? ''),
        'last_name' => (string) ($guest['last_name'] ?? $payload['last_name'] ?? ''),
        'gender' => (string) ($guest['gender'] ?? $payload['gender'] ?? ''),
        'birth_date' => substr((string) ($guest['birth_date'] ?? $payload['birth_date'] ?? ''), 0, 10),
        'birth_state_code' => (string) ($guest['birth_state_code'] ?? $payload['birth_state_code'] ?? ''),
        'birth_province' => (string) ($guest['birth_province'] ?? $payload['birth_province_code'] ?? ''),
        'birth_place_label' => (string) ($guest['birth_place_label'] ?? ''),
        'birth_place' => (string) ($guest['birth_place'] ?? ''),
        'birth_place_code' => (string) ($guest['birth_place_code'] ?? ''),
        'birth_city_code' => (string) ($guest['birth_city_code'] ?? $payload['birth_city_code'] ?? ''),
        'citizenship_label' => (string) ($guest['citizenship_label'] ?? ''),
        'citizenship_code' => (string) ($guest['citizenship_code'] ?? $payload['citizenship_code'] ?? ''),
        'residence_state_label' => (string) ($guest['residence_state_label'] ?? ''),
        'residence_state_code' => (string) ($guest['residence_state_code'] ?? ''),
        'residence_province' => (string) ($guest['residence_province'] ?? ''),
        'residence_place_label' => (string) ($guest['residence_place_label'] ?? ''),
        'residence_place' => (string) ($guest['residence_place'] ?? ''),
        'residence_place_code' => (string) ($guest['residence_place_code'] ?? ''),
        'tipoalloggiato_code' => (string) ($guest['tipoalloggiato_code'] ?? $guest['tipo_alloggiato_code'] ?? $payload['tipo_alloggiato_code'] ?? ''),
        'tipo_alloggiato_code' => (string) ($payload['tipo_alloggiato_code'] ?? $guest['tipo_alloggiato_code'] ?? $guest['tipoalloggiato_code'] ?? ''),
    ];

    foreach ($map as $column => $value) {
        if (!isset($columns[$column])) {
            continue;
        }
        $assignments[] = "`{$column}` = :{$column}";
        $params[$column] = $value !== '' ? $value : null;
    }

    if (!$assignments) {
        return;
    }

    $sql = 'UPDATE alloggiati_schedine SET ' . implode(', ', $assignments) . ' WHERE id = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
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
        $validationErrors = implode("
", $validation['errors']);
        $lastError = $validationErrors !== '' ? $validationErrors : null;

        if ($existing && (string) ($existing['status'] ?? '') === 'inviata' && (string) ($existing['payload_hash'] ?? '') === $payloadHash) {
            $status = 'inviata';
            $sentAt = $existing['sent_at'] ?? null;
            $validationErrors = null;
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

        $schedinaId = (int) ($existing['id'] ?? 0);
        if ($schedinaId <= 0) {
            $schedinaId = (int) $pdo->lastInsertId();
            if ($schedinaId <= 0) {
                $idStmt = $pdo->prepare('SELECT id FROM alloggiati_schedine WHERE guest_idswh = :guest_idswh LIMIT 1');
                $idStmt->execute(['guest_idswh' => $guestIdswh]);
                $schedinaId = (int) $idStmt->fetchColumn();
            }
        }
        if ($schedinaId > 0) {
            alloggiati_sync_optional_schedina_columns($pdo, $schedinaId, $guest, $payload);
        }
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

function alloggiati_is_exportable_status(string $status): bool
{
    return in_array($status, ['pronta', 'inviata', 'errore'], true);
}

function alloggiati_is_ws_sendable_status(string $status): bool
{
    return in_array($status, ['pronta', 'errore'], true);
}

function alloggiati_join_trace_records(array $lines): string
{
    return implode("\r\n", $lines);
}

function alloggiati_attach_runtime_fields(array $row): array
{
    $row['payload'] = json_decode((string) ($row['payload_json'] ?? ''), true) ?: [];
    $row['validation_errors_list'] = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) ($row['validation_errors'] ?? '')) ?: [])));
    $trace = alloggiati_build_trace_record($row['payload']);
    $row['trace_record'] = $trace['record'];
    $row['trace_errors'] = $trace['errors'];
    $row['trace_length'] = strlen((string) $trace['record']);
    $status = (string) ($row['status'] ?? '');
    $arrivalDate = (string) (($row['payload']['arrival_date'] ?? '') ?: ($row['arrival_date'] ?? ''));
    $windowState = alloggiati_send_window_state($arrivalDate);
    $row['send_window_state'] = $windowState;
    $row['send_window_message'] = alloggiati_send_window_message($windowState);
    $row['runtime_status'] = ($status === 'pronta' && $windowState === 'expired') ? 'storica' : $status;
    $row['can_generate_file'] = empty($trace['errors']) && alloggiati_is_exportable_status($status);
    $row['can_send_ws'] = empty($trace['errors']) && alloggiati_is_ws_sendable_status($status) && $windowState === 'sendable';
    return $row;
}

function alloggiati_fetch_day_schedine(PDO $pdo, string $arrivalDate): array
{
    if (!alloggiati_schedine_table_ready($pdo)) {
        return [];
    }

    $stmt = $pdo->prepare('SELECT * FROM alloggiati_schedine WHERE arrival_date = :arrival_date ORDER BY record_id ASC, is_group_leader DESC, id ASC');
    $stmt->execute(['arrival_date' => $arrivalDate]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $row = alloggiati_attach_runtime_fields($row);
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
    return alloggiati_attach_runtime_fields($row);
}

function alloggiati_fetch_schedine_by_record(PDO $pdo, int $recordId): array
{
    $stmt = $pdo->prepare('SELECT * FROM alloggiati_schedine WHERE record_id = :record_id ORDER BY is_group_leader DESC, id ASC');
    $stmt->execute(['record_id' => $recordId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $row = alloggiati_attach_runtime_fields($row);
    }
    unset($row);
    return $rows;
}

function alloggiati_day_status_counts(array $schedine): array
{
    $counts = [
        'total' => count($schedine),
        'bozza' => 0,
        'pronta' => 0,
        'storica' => 0,
        'inviata' => 0,
        'errore' => 0,
    ];

    foreach ($schedine as $row) {
        $status = (string) ($row['runtime_status'] ?? ($row['status'] ?? 'bozza'));
        if (!isset($counts[$status])) {
            continue;
        }
        $counts[$status]++;
    }

    return $counts;
}


function alloggiati_record_overall_status(array $counts): string
{
    $errore = (int) ($counts['errore'] ?? 0);
    $pronta = (int) ($counts['pronta'] ?? 0);
    $storica = (int) ($counts['storica'] ?? 0);
    $inviata = (int) ($counts['inviata'] ?? 0);
    $bozza = (int) ($counts['bozza'] ?? 0);

    if ($errore > 0) {
        return 'errore';
    }
    if ($pronta > 0 && $inviata > 0) {
        return 'mista';
    }
    if ($pronta > 0) {
        return 'pronta';
    }
    if ($storica > 0 && $inviata > 0) {
        return 'mista';
    }
    if ($storica > 0) {
        return 'storica';
    }
    if ($bozza > 0) {
        return 'bozza';
    }
    if ($inviata > 0) {
        return 'inviata';
    }
    return 'bozza';
}

function alloggiati_record_kind_from_tipo(string $tipoCode): string
{
    if ($tipoCode === '17') {
        return 'famiglia';
    }
    if ($tipoCode === '18') {
        return 'gruppo';
    }
    return 'singolo';
}

function alloggiati_record_kind_from_rows(array $rows): string
{
    $hasFamily = false;
    $hasGroup = false;
    foreach ($rows as $row) {
        $payload = (array) ($row['payload'] ?? []);
        $tipoCode = (string) ($payload['tipo_alloggiato_code'] ?? '');
        if (in_array($tipoCode, ['17', '19'], true)) {
            $hasFamily = true;
        }
        if (in_array($tipoCode, ['18', '20'], true)) {
            $hasGroup = true;
        }
    }
    if ($hasFamily) {
        return 'famiglia';
    }
    if ($hasGroup) {
        return 'gruppo';
    }
    return 'singolo';
}

function alloggiati_record_kind_label(string $kind): string
{
    if ($kind === 'famiglia') {
        return 'Famiglia';
    }
    if ($kind === 'gruppo') {
        return 'Gruppo';
    }
    return 'Ospite singolo';
}

function alloggiati_group_schedine_by_record(array $schedine): array
{
    $schedine = alloggiati_sort_schedine_for_transmission($schedine);
    $grouped = [];

    foreach ($schedine as $schedina) {
        $recordId = (int) ($schedina['record_id'] ?? 0);
        if ($recordId <= 0) {
            continue;
        }
        if (!isset($grouped[$recordId])) {
            $grouped[$recordId] = [];
        }
        $grouped[$recordId][] = $schedina;
    }

    $bundles = [];
    foreach ($grouped as $recordId => $rows) {
        $leader = $rows[0] ?? [];
        foreach ($rows as $row) {
            if (!empty($row['is_group_leader'])) {
                $leader = $row;
                break;
            }
        }
        $components = array_values(array_filter($rows, static fn(array $row): bool => (int) ($row['id'] ?? 0) !== (int) ($leader['id'] ?? 0)));
        $counts = alloggiati_day_status_counts($rows);
        $payload = (array) ($leader['payload'] ?? []);
        $kind = alloggiati_record_kind_from_rows($rows);
        $traceErrors = [];
        $documentCount = 0;
        foreach ($rows as $row) {
            if (!empty($row['trace_errors'])) {
                foreach ((array) $row['trace_errors'] as $error) {
                    $traceErrors[] = trim((string) (($row['display_name'] ?? 'Schedina') . ': ' . $error));
                }
            }
            $rowPayload = (array) ($row['payload'] ?? []);
            if (trim((string) ($rowPayload['document_number'] ?? '')) !== '') {
                $documentCount++;
            }
        }

        $canGenerateFile = !empty($rows);
        $canSendWs = !empty($rows);
        foreach ($rows as $row) {
            if (empty($row['can_generate_file'])) {
                $canGenerateFile = false;
            }
            if (empty($row['can_send_ws'])) {
                $canSendWs = false;
            }
        }

        $bundles[] = [
            'record_id' => $recordId,
            'kind' => $kind,
            'kind_label' => alloggiati_record_kind_label($kind),
            'leader' => $leader,
            'components' => $components,
            'rows' => $rows,
            'people' => $rows,
            'counts' => $counts,
            'overall_status' => alloggiati_record_overall_status($counts),
            'line_count' => count($rows),
            'document_count' => $documentCount,
            'trace_errors' => array_values(array_unique(array_filter($traceErrors))),
            'display_name' => (string) ($leader['display_name'] ?? ($payload['display_name'] ?? ('Record #' . $recordId))),
            'arrival_date' => (string) ($leader['arrival_date'] ?? ($payload['arrival_date'] ?? '')),
            'arrival_date_portal' => (string) ($payload['arrival_date_portal'] ?? ''),
            'permanence_days' => (int) ($payload['permanence_days'] ?? 0),
            'can_generate_file' => $canGenerateFile,
            'can_send_ws' => $canSendWs,
        ];
    }

    return $bundles;
}

function alloggiati_ascii_upper(string $value): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    if ($value === '') {
        return '';
    }

    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($converted) && $converted !== '') {
            $value = $converted;
        }
    }

    $value = strtoupper($value);
    $value = preg_replace("/[^A-Z0-9 '\\-\\/]/", ' ', $value) ?? $value;
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return trim($value);
}

function alloggiati_pad_right(string $value, int $length): string
{
    $value = substr($value, 0, $length);
    return str_pad($value, $length, ' ', STR_PAD_RIGHT);
}

function alloggiati_pad_left_zero(string $value, int $length): string
{
    $value = preg_replace('/\D+/', '', $value) ?? $value;
    $value = substr($value, 0, $length);
    return str_pad($value, $length, '0', STR_PAD_LEFT);
}

function alloggiati_build_trace_record(array $payload): array
{
    $errors = [];

    $tipo = alloggiati_normalize_scalar($payload['tipo_alloggiato_code'] ?? '');
    $arrival = alloggiati_format_portal_date((string) ($payload['arrival_date'] ?? ''));
    $days = alloggiati_pad_left_zero((string) ((int) ($payload['permanence_days'] ?? 0)), 2);
    $lastName = alloggiati_pad_right(alloggiati_ascii_upper((string) ($payload['last_name'] ?? '')), 50);
    $firstName = alloggiati_pad_right(alloggiati_ascii_upper((string) ($payload['first_name'] ?? '')), 30);
    $gender = strtoupper(alloggiati_normalize_scalar($payload['gender'] ?? ''));
    $genderCode = $gender === 'M' ? '1' : ($gender === 'F' ? '2' : '');
    $birthDate = alloggiati_format_portal_date((string) ($payload['birth_date'] ?? ''));
    $birthStateCode = alloggiati_normalize_scalar($payload['birth_state_code'] ?? '');
    $birthCityCode = alloggiati_normalize_scalar($payload['birth_city_code'] ?? '');
    $birthProvinceCode = strtoupper(alloggiati_normalize_scalar($payload['birth_province_code'] ?? ''));
    $citizenshipCode = alloggiati_normalize_scalar($payload['citizenship_code'] ?? '');
    $documentTypeCode = alloggiati_normalize_scalar($payload['document_type_code'] ?? '');
    $documentNumber = alloggiati_pad_right(alloggiati_ascii_upper((string) ($payload['document_number'] ?? '')), 20);
    $issuePlaceCode = alloggiati_normalize_scalar($payload['document_issue_place_code'] ?? '');

    if ($tipo === '') $errors[] = 'Tipo alloggiato mancante.';
    if ($arrival === '') $errors[] = 'Data arrivo non valida.';
    if ($birthDate === '') $errors[] = 'Data nascita non valida.';
    if ($genderCode === '') $errors[] = 'Sesso non valido per il tracciato Alloggiati.';
    if ($birthStateCode === '') $errors[] = 'Stato di nascita mancante.';
    if ($citizenshipCode === '') $errors[] = 'Cittadinanza mancante.';

    $italyCode = anagrafica_default_italy_state_code();
    if ($birthStateCode === $italyCode) {
        if ($birthCityCode === '') $errors[] = 'Comune di nascita mancante per nato in Italia.';
        if ($birthProvinceCode === '') $errors[] = 'Provincia di nascita mancante per nato in Italia.';
    }

    if (alloggiati_document_required($tipo)) {
        if ($documentTypeCode === '') $errors[] = 'Tipo documento mancante.';
        if (trim($documentNumber) === '') $errors[] = 'Numero documento mancante.';
        if ($issuePlaceCode === '') $errors[] = 'Luogo rilascio documento mancante.';
    } else {
        $documentTypeCode = '';
        $documentNumber = str_repeat(' ', 20);
        $issuePlaceCode = '';
    }

    if ($errors) {
        return ['record' => '', 'errors' => array_values(array_unique($errors))];
    }

    $record = '';
    $record .= alloggiati_pad_right($tipo, 2);
    $record .= alloggiati_pad_right($arrival, 10);
    $record .= $days;
    $record .= $lastName;
    $record .= $firstName;
    $record .= $genderCode;
    $record .= alloggiati_pad_right($birthDate, 10);
    $record .= alloggiati_pad_right($birthStateCode === $italyCode ? $birthCityCode : '', 9);
    $record .= alloggiati_pad_right($birthStateCode === $italyCode ? $birthProvinceCode : '', 2);
    $record .= alloggiati_pad_right($birthStateCode, 9);
    $record .= alloggiati_pad_right($citizenshipCode, 9);
    $record .= alloggiati_pad_right($documentTypeCode, 5);
    $record .= $documentNumber;
    $record .= alloggiati_pad_right($issuePlaceCode, 9);

    if (strlen($record) !== 168) {
        return ['record' => $record, 'errors' => ['La riga schedina non rispetta i 168 caratteri previsti.']];
    }

    return ['record' => $record, 'errors' => []];
}

function alloggiati_sort_schedine_for_transmission(array $schedine): array
{
    usort($schedine, static function (array $a, array $b): int {
        $aRecord = (int) ($a['record_id'] ?? 0);
        $bRecord = (int) ($b['record_id'] ?? 0);
        if ($aRecord !== $bRecord) {
            return $aRecord <=> $bRecord;
        }
        $aLeader = (int) ($a['is_group_leader'] ?? 0);
        $bLeader = (int) ($b['is_group_leader'] ?? 0);
        if ($aLeader !== $bLeader) {
            return $bLeader <=> $aLeader;
        }
        return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
    });
    return $schedine;
}

function alloggiati_collect_day_export(PDO $pdo, string $arrivalDate): array
{
    $schedine = alloggiati_sort_schedine_for_transmission(alloggiati_sync_day($pdo, $arrivalDate));
    $lines = [];
    $rows = [];
    $errors = [];

    foreach ($schedine as $schedina) {
        if (!empty($schedina['trace_errors'])) {
            $errors[] = (string) ($schedina['display_name'] ?? 'Schedina') . ': ' . implode(' ', $schedina['trace_errors']);
            continue;
        }
        if (!alloggiati_is_exportable_status((string) ($schedina['status'] ?? ''))) {
            continue;
        }
        $lines[] = (string) $schedina['trace_record'];
        $rows[] = $schedina;
    }

    if (count($lines) > 1000) {
        $errors[] = 'Il tracciato Alloggiati supporta al massimo 1000 righe per file.';
        $lines = array_slice($lines, 0, 1000);
        $rows = array_slice($rows, 0, 1000);
    }

    return [
        'schedine' => $rows,
        'content' => alloggiati_join_trace_records($lines),
        'line_count' => count($lines),
        'errors' => array_values(array_unique($errors)),
        'ws' => alloggiati_build_ws_previews($lines),
    ];
}

function alloggiati_collect_single_export(PDO $pdo, int $schedinaId, ?array $allowedStatuses = null): array
{
    $allowedStatuses = $allowedStatuses ?: ['pronta', 'inviata', 'errore'];
    $allowedStatuses = array_values(array_unique(array_map('strval', $allowedStatuses)));

    $schedina = alloggiati_fetch_schedina($pdo, $schedinaId);
    if (!$schedina) {
        return ['schedina' => null, 'content' => '', 'line_count' => 0, 'errors' => ['Schedina non trovata.'], 'ws' => alloggiati_build_ws_previews([])];
    }

    alloggiati_sync_record($pdo, (int) $schedina['record_id']);
    $schedina = alloggiati_fetch_schedina($pdo, $schedinaId);
    if (!$schedina) {
        return ['schedina' => null, 'content' => '', 'line_count' => 0, 'errors' => ['Schedina non trovata dopo la sincronizzazione.'], 'ws' => alloggiati_build_ws_previews([])];
    }

    if (!empty($schedina['trace_errors'])) {
        return ['schedina' => $schedina, 'content' => '', 'line_count' => 0, 'errors' => $schedina['trace_errors'], 'ws' => alloggiati_build_ws_previews([])];
    }

    if (!in_array((string) ($schedina['status'] ?? ''), $allowedStatuses, true)) {
        return ['schedina' => $schedina, 'content' => '', 'line_count' => 0, 'errors' => ['La schedina non è disponibile per l’operazione richiesta.'], 'ws' => alloggiati_build_ws_previews([])];
    }

    $line = (string) $schedina['trace_record'];
    return ['schedina' => $schedina, 'content' => $line, 'line_count' => 1, 'errors' => [], 'ws' => alloggiati_build_ws_previews([$line])];
}


function alloggiati_collect_record_export(PDO $pdo, int $recordId, ?array $allowedStatuses = null): array
{
    $allowedStatuses = $allowedStatuses ?: ['pronta', 'inviata', 'errore'];
    $allowedStatuses = array_values(array_unique(array_map('strval', $allowedStatuses)));

    $rows = alloggiati_sort_schedine_for_transmission(alloggiati_sync_record($pdo, $recordId));
    if (!$rows) {
        return ['record_id' => $recordId, 'schedine' => [], 'content' => '', 'line_count' => 0, 'errors' => ['Nessuna schedina collegata trovata per l’anagrafica selezionata.'], 'bundle' => null, 'ws' => alloggiati_build_ws_previews([])];
    }

    $bundle = alloggiati_group_schedine_by_record($rows)[0] ?? null;
    $lines = [];
    $errors = [];

    foreach ($rows as $schedina) {
        if (!empty($schedina['trace_errors'])) {
            $errors[] = (string) ($schedina['display_name'] ?? 'Schedina') . ': ' . implode(' ', (array) $schedina['trace_errors']);
            continue;
        }
        if (!in_array((string) ($schedina['status'] ?? ''), $allowedStatuses, true)) {
            $errors[] = (string) ($schedina['display_name'] ?? 'Schedina') . ': stato non disponibile per l’operazione richiesta.';
            continue;
        }
        $lines[] = (string) ($schedina['trace_record'] ?? '');
    }

    if (count($lines) !== count($rows)) {
        return ['record_id' => $recordId, 'schedine' => $rows, 'content' => '', 'line_count' => 0, 'errors' => array_values(array_unique($errors)), 'bundle' => $bundle, 'ws' => alloggiati_build_ws_previews([])];
    }

    return ['record_id' => $recordId, 'schedine' => $rows, 'content' => alloggiati_join_trace_records($lines), 'line_count' => count($lines), 'errors' => [], 'bundle' => $bundle, 'ws' => alloggiati_build_ws_previews($lines)];
}

function alloggiati_build_download_filename_for_record(array $bundle): string
{
    $leader = (array) ($bundle['leader'] ?? []);
    $arrival = str_replace('-', '', (string) ($bundle['arrival_date'] ?? ($leader['arrival_date'] ?? date('Y-m-d'))));
    $recordId = (int) ($bundle['record_id'] ?? 0);
    $name = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($bundle['display_name'] ?? 'anagrafica')) ?: 'anagrafica';
    return 'alloggiati-' . $arrival . '-record-' . $recordId . '-' . strtolower($name) . '.txt';
}

function alloggiati_build_download_filename_for_day(string $arrivalDate): string
{
    return 'alloggiati-' . str_replace('-', '', $arrivalDate) . '.txt';
}

function alloggiati_build_download_filename_for_schedina(array $schedina): string
{
    $guestIdswh = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($schedina['guest_idswh'] ?? 'schedina')) ?: 'schedina';
    $arrival = str_replace('-', '', (string) ($schedina['arrival_date'] ?? date('Y-m-d')));
    return 'alloggiati-' . $arrival . '-' . $guestIdswh . '.txt';
}

function alloggiati_build_ws_previews(array $traceRecords): array
{
    $config = alloggiati_ws_config();
    $utente = (string) ($config['utente'] ?? 'UTENTE_ALLOGGIATI');
    $password = (string) ($config['password'] ?? 'PASSWORD_ALLOGGIATI');
    $wskey = (string) ($config['wskey'] ?? 'WSKEY_ALLOGGIATI');
    $token = 'TOKEN_DA_GENERARE';

    $strings = '';
    foreach ($traceRecords as $record) {
        $strings .= "
      <all:string>" . htmlspecialchars($record, ENT_XML1 | ENT_COMPAT, 'UTF-8') . "</all:string>";
    }

    $generateToken = sprintf(
        '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope" xmlns:all="AlloggiatiService">' .
        '
  <soap:Header/>' .
        '
  <soap:Body>' .
        '
    <all:GenerateToken>' .
        '
      <all:Utente>%s</all:Utente>' .
        '
      <all:Password>%s</all:Password>' .
        '
      <all:WsKey>%s</all:WsKey>' .
        '
    </all:GenerateToken>' .
        '
  </soap:Body>' .
        '
</soap:Envelope>',
        htmlspecialchars($utente, ENT_XML1 | ENT_COMPAT, 'UTF-8'),
        htmlspecialchars($password, ENT_XML1 | ENT_COMPAT, 'UTF-8'),
        htmlspecialchars($wskey, ENT_XML1 | ENT_COMPAT, 'UTF-8')
    );

    $test = sprintf(
        '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope" xmlns:all="AlloggiatiService">' .
        '
  <soap:Header/>' .
        '
  <soap:Body>' .
        '
    <all:Test>' .
        '
      <all:Utente>%s</all:Utente>' .
        '
      <all:token>%s</all:token>' .
        '
      <all:ElencoSchedine>%s' .
        '
      </all:ElencoSchedine>' .
        '
    </all:Test>' .
        '
  </soap:Body>' .
        '
</soap:Envelope>',
        htmlspecialchars($utente, ENT_XML1 | ENT_COMPAT, 'UTF-8'),
        htmlspecialchars($token, ENT_XML1 | ENT_COMPAT, 'UTF-8'),
        $strings
    );

    $send = sprintf(
        '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope" xmlns:all="AlloggiatiService">' .
        '
  <soap:Header/>' .
        '
  <soap:Body>' .
        '
    <all:Send>' .
        '
      <all:Utente>%s</all:Utente>' .
        '
      <all:token>%s</all:token>' .
        '
      <all:ElencoSchedine>%s' .
        '
      </all:ElencoSchedine>' .
        '
    </all:Send>' .
        '
  </soap:Body>' .
        '
</soap:Envelope>',
        htmlspecialchars($utente, ENT_XML1 | ENT_COMPAT, 'UTF-8'),
        htmlspecialchars($token, ENT_XML1 | ENT_COMPAT, 'UTF-8'),
        $strings
    );

    return [
        'config' => $config,
        'generate_token_xml' => $generateToken,
        'test_xml' => $test,
        'send_xml' => $send,
    ];
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

    if ((string) $schedina['status'] !== 'pronta' || !empty($schedina['trace_errors'])) {
        $errors = $schedina['trace_errors'] ?: ($schedina['validation_errors_list'] ?: ['La schedina non è pronta per l’invio.']);
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
    $bundle = alloggiati_collect_day_export($pdo, $arrivalDate);
    $schedine = $bundle['schedine'];
    $readyIds = [];
    $errors = array_values($bundle['errors'] ?? []);

    foreach ($schedine as $row) {
        if ((string) ($row['status'] ?? '') === 'pronta') {
            $readyIds[] = (int) $row['id'];
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
