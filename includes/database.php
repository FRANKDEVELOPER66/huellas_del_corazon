<?php

// Hora de Guatemala (UTC-6): sin esto el servidor usa UTC y desde las 6 pm ya cree que es el día siguiente
date_default_timezone_set('America/Guatemala');

try {
    $host = $_ENV['DB_HOST'] ?? $_ENV['DB_SERVER'] ?? 'db';
    $port = $_ENV['DB_PORT'] ?? '3306';
    $user = $_ENV['DB_USER'] ?? 'root';
    $pass = $_ENV['DB_PASS'] ?? '';
    $database = $_ENV['DB_NAME'] ?? '';

    $db = new PDO("mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4", $user, $pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Lo mismo para MySQL (CURDATE, NOW, DATEDIFF...)
    $db->exec("SET time_zone = '-06:00'");
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode([
        "detalle" => $e->getMessage(),
        "mensaje" => "Error de conexión a la base de datos",
        "codigo" => 5
    ]);
    exit;
}
