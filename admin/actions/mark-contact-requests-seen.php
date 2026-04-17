<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

require_admin();

mark_contact_requests_seen($pdo);

$isAjax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

if ($isAjax) {
    http_response_code(204);
    exit;
}

header('Location: ' . admin_url('index.php#contact-requests'));
exit;
