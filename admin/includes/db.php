<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    die('Connessione database non disponibile.');
}
