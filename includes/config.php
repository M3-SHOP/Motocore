<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$host = '127.0.0.1';
$database = 'moto_marketplace';
$username = 'root';
$password = ''; // Altera se o teu MySQL tiver palavra-passe

$dsn = "mysql:host={$host};dbname={$database};charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    exit('Não foi possível ligar à base de dados. Verifica a configuração do MySQL.');
}