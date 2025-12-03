<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../common/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH); // Pega apenas o path

// GET /api/orders - Listar pedidos
if ($method === 'GET' && $path === '/api/orders') {
    $status = $_GET['status'] ?? 'pending';
    
    try {
        if ($status === 'all') {
            $stmt = $pdo->query("SELECT * FROM orders ORDER BY created_at DESC");
        } else {
            $stmt = $pdo->prepare("SELECT * FROM orders WHERE status = ? ORDER BY created_at ASC");
            $stmt->execute([$status]);
        }
        
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Para cada pedido, buscar os itens
        foreach ($orders as &$order) {
            $stmt = $pdo->prepare("
                SELECT oi.quantity, oi.notes, mi.name, mi.description
                FROM order_items oi
                JOIN menu_items mi ON oi.menu_item_id = mi.id
                WHERE oi.order_id = ?
            ");
            $stmt->execute([$order['id']]);
            $order['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        echo json_encode($orders);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => $e->getMessage()]);
    }
    exit;
}

// POST /api/orders/{id}/complete - Finalizar pedido (dar baixa)
if ($method === 'POST' && preg_match('#^/api/orders/(\d+)/complete$#', $path, $matches)) {
    $id = $matches[1];
    
    try {
        $stmt = $pdo->prepare("UPDATE orders SET status = 'done' WHERE id = ?");
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() > 0) {
            echo json_encode(["success" => true, "message" => "Pedido finalizado com sucesso"]);
        } else {
            echo json_encode(["error" => "Pedido não encontrado"]);
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => $e->getMessage()]);
    }
    exit;
}

// Rota para criar pedido (se ainda não existe)
if ($method === 'POST' && $path === '/api/orders') {
    // Aqui você pode implementar a criação de pedidos se precisar
    echo json_encode(["error" => "Criação de pedido não implementada"]);
    exit;
}

http_response_code(404);
echo json_encode(["error" => "Not found", "uri" => $uri, "method" => $method]);