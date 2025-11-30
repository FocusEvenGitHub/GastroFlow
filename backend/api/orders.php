<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../common/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = $_SERVER['REQUEST_URI'];

// POST /api/orders - Criar pedido
if ($method === 'POST' && preg_match('#/api/orders$#', $path)) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data['table'] || !$data['items'] || !is_array($data['items'])) {
        http_response_code(400);
        echo json_encode(["error" => "Missing fields"]);
        exit;
    }

    $pdo->beginTransaction();
    
    try {
        // Criar o pedido
        $stmt = $pdo->prepare("INSERT INTO orders (table_number) VALUES (?)");
        $stmt->execute([$data['table']]);
        $orderId = $pdo->lastInsertId();
        
        // Adicionar itens do pedido
        $stmt = $pdo->prepare("INSERT INTO order_items (order_id, menu_item_id, quantity, notes) VALUES (?, ?, ?, ?)");
        
        foreach ($data['items'] as $item) {
            if (!isset($item['id']) || !isset($item['quantity'])) {
                throw new Exception("Missing item fields");
            }
            $stmt->execute([
                $orderId, 
                $item['id'], 
                $item['quantity'], 
                $item['notes'] ?? ''
            ]);
        }
        
        $pdo->commit();
        echo json_encode(["ok" => true, "id" => $orderId]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(["error" => "Failed to create order: " . $e->getMessage()]);
    }
    exit;
}

// GET /api/orders - Listar pedidos
if ($method === 'GET' && preg_match('#/api/orders$#', $path)) {
    $status = $_GET['status'] ?? 'pending';
    
    if ($status == "all") {
        $stmt = $pdo->query("
            SELECT o.*, 
                   GROUP_CONCAT(CONCAT(oi.quantity, 'x ', mi.name, IF(oi.notes != '', CONCAT(' (', oi.notes, ')'), '')) SEPARATOR ', ') as items_description
            FROM orders o
            LEFT JOIN order_items oi ON oi.order_id = o.id
            LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id
            GROUP BY o.id
            ORDER BY o.created_at DESC
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT o.*, 
                   GROUP_CONCAT(CONCAT(oi.quantity, 'x ', mi.name, IF(oi.notes != '', CONCAT(' (', oi.notes, ')'), '')) SEPARATOR ', ') as items_description
            FROM orders o
            LEFT JOIN order_items oi ON oi.order_id = o.id
            LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id
            WHERE o.status = ?
            GROUP BY o.id
            ORDER BY o.created_at ASC
        ");
        $stmt->execute([$status]);
    }
    
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// GET /api/orders/{id} - Detalhes do pedido
if ($method === 'GET' && preg_match('#/api/orders/(\d+)$#', $path, $matches)) {
    $orderId = $matches[1];
    
    // Informações do pedido
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        http_response_code(404);
        echo json_encode(["error" => "Order not found"]);
        exit;
    }
    
    // Itens do pedido
    $stmt = $pdo->prepare("
        SELECT oi.*, mi.name, mi.price, mi.description
        FROM order_items oi
        JOIN menu_items mi ON oi.menu_item_id = mi.id
        WHERE oi.order_id = ?
    ");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $order['items'] = $items;
    echo json_encode($order);
    exit;
}

// POST /api/orders/{id}/complete - Completar pedido
if ($method === 'POST' && preg_match('#/api/orders/(\d+)/complete$#', $path, $matches)) {
    $id = $matches[1];
    $stmt = $pdo->prepare("UPDATE orders SET status='done' WHERE id=?");
    $stmt->execute([$id]);
    echo json_encode(["ok" => true]);
    exit;
}

http_response_code(404);
echo json_encode(["error" => "Not found"]);