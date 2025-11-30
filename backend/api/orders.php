<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");

require_once "../common/db.php";

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->query("SELECT * FROM pedidos ORDER BY id DESC");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['mesa']) || !isset($data['descricao'])) {
        echo json_encode(["error" => "Campos obrigatórios não enviados"]);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO pedidos (mesa, descricao, status) VALUES (?, ?, 'pendente')");
    $stmt->execute([$data['mesa'], $data['descricao']]);

    echo json_encode(["success" => true]);
    exit;
}

echo json_encode(["error" => "Método não suportado"]);
