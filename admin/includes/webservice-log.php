<?php

declare(strict_types=1);

function webservice_logs_table_ready(PDO $pdo): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    try {
        $cache = (bool) $pdo->query("SHOW TABLES LIKE 'webservice_logs'")->fetchColumn();
    } catch (Throwable $e) {
        $cache = false;
    }
    return $cache;
}

function webservice_log_event(PDO $pdo, array $data): void
{
    if (!webservice_logs_table_ready($pdo)) {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO webservice_logs (
            service_name, scope_type, scope_ref, action_name, status, request_payload, response_payload, error_message, created_at
         ) VALUES (
            :service_name, :scope_type, :scope_ref, :action_name, :status, :request_payload, :response_payload, :error_message, NOW()
         )'
    );

    $stmt->execute([
        'service_name' => (string) ($data['service_name'] ?? ''),
        'scope_type' => (string) ($data['scope_type'] ?? ''),
        'scope_ref' => (string) ($data['scope_ref'] ?? ''),
        'action_name' => (string) ($data['action_name'] ?? ''),
        'status' => (string) ($data['status'] ?? ''),
        'request_payload' => (string) ($data['request_payload'] ?? ''),
        'response_payload' => (string) ($data['response_payload'] ?? ''),
        'error_message' => (string) ($data['error_message'] ?? ''),
    ]);
}
