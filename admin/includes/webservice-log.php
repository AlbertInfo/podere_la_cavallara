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

function webservice_log(PDO $pdo, array $data): void
{
    if (!webservice_logs_table_ready($pdo)) {
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO webservice_logs (
        service_name, action_name, scope_type, scope_ref, is_simulated, success, request_payload, response_payload, error_message, created_at
    ) VALUES (
        :service_name, :action_name, :scope_type, :scope_ref, :is_simulated, :success, :request_payload, :response_payload, :error_message, NOW()
    )');

    $stmt->execute([
        'service_name' => (string) ($data['service_name'] ?? ''),
        'action_name' => (string) ($data['action_name'] ?? ''),
        'scope_type' => (string) ($data['scope_type'] ?? ''),
        'scope_ref' => (string) ($data['scope_ref'] ?? ''),
        'is_simulated' => !empty($data['is_simulated']) ? 1 : 0,
        'success' => !empty($data['success']) ? 1 : 0,
        'request_payload' => (string) ($data['request_payload'] ?? ''),
        'response_payload' => (string) ($data['response_payload'] ?? ''),
        'error_message' => (string) ($data['error_message'] ?? ''),
    ]);
}
