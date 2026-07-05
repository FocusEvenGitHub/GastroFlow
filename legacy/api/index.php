<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../common/db.php';



$method = $_SERVER['REQUEST_METHOD'];
$uri = strtok($_SERVER['REQUEST_URI'], '?');

if ($method === 'POST' && preg_match('#/api/orders$#', $uri)) {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data['table'] || !$data['items']) {
        http_response_code(400);
        echo json_encode(["error" => "Missing fields"]);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO orders (table_number, items) VALUES (?, ?)");
    $stmt->execute([$data['table'], $data['items']]);

    echo json_encode(["ok" => true, "id" => $pdo->lastInsertId()]);
    exit;
}

// GET /api/orders
if ($method === 'GET' && preg_match('#/api/orders$#', $uri)) {
    $status = $_GET['status'] ?? 'pending';

    if ($status == "all") {
        $stmt = $pdo->query("SELECT * FROM orders ORDER BY created_at DESC");
    } else {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE status = ? ORDER BY created_at ASC");
        $stmt->execute([$status]);
    }

    echo json_encode($stmt->fetchAll());
    exit;
}

// POST /api/orders/{id}/complete
if ($method === 'POST' && preg_match('#/api/orders/([0-9]+)/complete$#', $uri, $m)) {
    $id = $m[1];
    $stmt = $pdo->prepare("UPDATE orders SET status='done' WHERE id=?");
    $stmt->execute([$id]);

    echo json_encode(["ok" => true]);
    exit;
}

http_response_code(404);
echo json_encode(["error" => "Not found"]);
