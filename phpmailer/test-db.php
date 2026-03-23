<?php

$DB_HOST = 'HOST_REALE';
$DB_NAME = 'u881781553_cavallara';
$DB_USER = 'u881781553_admin';
$DB_PASS = 'Poderecavallara26$';

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]
    );

    echo 'Connessione DB OK';
} catch (PDOException $e) {
    echo 'Errore connessione DB: ' . $e->getMessage();
}