<?php

$DB_HOST = 'localhost';
$DB_NAME = 'u881781553_cavallara';
$DB_USER = 'u881781553_admin';
$DB_PASS = 'Poderecavallara26$';

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS
    );

    // errori in modalità exception
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

} catch (PDOException $e) {
    die("Errore connessione DB: " . $e->getMessage());
}