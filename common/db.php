<?php
require_once __DIR__ . '/config.php';

function getPDO() {
    global $dsn, $dbUser, $dbPass;
    
    try {
        $pdo = new PDO($dsn, $dbUser, $dbPass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (Exception $e) {
        die("Erro ao conectar: " . $e->getMessage());
    }
}

// Para compatibilidade com código existente
try {
    $pdo = getPDO();
} catch (Exception $e) {
    die("Erro ao conectar: " . $e->getMessage());
}