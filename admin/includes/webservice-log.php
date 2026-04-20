<?php

declare(strict_types=1);

function webservice_logs_table_ready(PDO $pdo): bool
{
    try {
        return (bool) $pdo->query("SHOW TABLES LIKE 'webservice_logs'")->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function webservice_logs_table_columns(PDO $pdo): array
{
    static $cache = [];

    $cacheKey = spl_object_hash($pdo);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    if (!webservice_logs_table_ready($pdo)) {
        return $cache[$cacheKey] = [];
    }

    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM webservice_logs');
        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $column) {
            $name = (string) ($column['Field'] ?? '');
            if ($name !== '') {
                $columns[$name] = true;
            }
        }
        return $cache[$cacheKey] = $columns;
    } catch (Throwable $e) {
        return $cache[$cacheKey] = [];
    }
}

function webservice_log_status(array $data): string
{
    $explicit = trim((string) ($data['status'] ?? ''));
    if ($explicit !== '') {
        return $explicit;
    }

    if (!empty($data['is_simulated'])) {
        return !empty($data['success']) ? 'simulated' : 'sim_error';
    }

    return !empty($data['success']) ? 'success' : 'error';
}

function webservice_log(PDO $pdo, array $data): void
{
    $columns = webservice_logs_table_columns($pdo);
    if (!$columns) {
        return;
    }

    $values = [
        'service_name' => (string) ($data['service_name'] ?? ''),
        'scope_type' => (string) ($data['scope_type'] ?? ''),
        'scope_ref' => (string) ($data['scope_ref'] ?? ''),
        'action_name' => (string) ($data['action_name'] ?? ''),
        'status' => webservice_log_status($data),
        'is_simulated' => !empty($data['is_simulated']) ? 1 : 0,
        'success' => !empty($data['success']) ? 1 : 0,
        'request_payload' => (string) ($data['request_payload'] ?? ''),
        'response_payload' => (string) ($data['response_payload'] ?? ''),
        'error_message' => (string) ($data['error_message'] ?? ''),
    ];

    $insertColumns = [];
    $placeholders = [];
    $params = [];

    foreach ($values as $column => $value) {
        if (!isset($columns[$column])) {
            continue;
        }
        $insertColumns[] = $column;
        $placeholders[] = ':' . $column;
        $params[$column] = $value;
    }

    if (!$insertColumns) {
        return;
    }

    if (isset($columns['created_at'])) {
        $insertColumns[] = 'created_at';
        $placeholders[] = 'NOW()';
    }

    $sql = 'INSERT INTO webservice_logs (' . implode(', ', $insertColumns) . ') VALUES (' . implode(', ', $placeholders) . ')';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    } catch (Throwable $e) {
        error_log('webservice_log failed: ' . $e->getMessage());
    }
}
