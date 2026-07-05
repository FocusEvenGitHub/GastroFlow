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
    
// POST /api/orders - Criar novo pedido
if ($method === 'POST' && $path === '/api/orders') {
    // Ler dados do corpo da requisição
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validar dados recebidos
    if (!isset($input['table']) || empty($input['table'])) {
        http_response_code(400);
        echo json_encode(["error" => "Número da mesa é obrigatório"]);
        exit;
    }
    
    if (!isset($input['items']) || !is_array($input['items']) || count($input['items']) === 0) {
        http_response_code(400);
        echo json_encode(["error" => "Adicione pelo menos um item ao pedido"]);
        exit;
    }
    
    try {
        // Iniciar transação
        $pdo->beginTransaction();
        
        // 1. Inserir o pedido principal (sem total, pois a coluna não existe)
        $stmt = $pdo->prepare("
            INSERT INTO orders (table_number, status, created_at) 
            VALUES (?, 'pending', NOW())
        ");
        $stmt->execute([$input['table']]);
        $orderId = $pdo->lastInsertId();
        
        $total = 0;
        
        // 2. Inserir os itens do pedido (sem price e subtotal, pois as colunas não existem)
        $stmtItem = $pdo->prepare("
            INSERT INTO order_items (order_id, menu_item_id, quantity, notes) 
            VALUES (?, ?, ?, ?)
        ");
        
        foreach ($input['items'] as $item) {
            // Buscar preço do item no menu
            $stmtPrice = $pdo->prepare("SELECT price FROM menu_items WHERE id = ?");
            $stmtPrice->execute([$item['id']]);
            $menuItem = $stmtPrice->fetch(PDO::FETCH_ASSOC);
            
            if (!$menuItem) {
                throw new Exception("Item do menu não encontrado: ID " . $item['id']);
            }
            
            $price = floatval($menuItem['price']);
            $quantity = intval($item['quantity']);
            $subtotal = $price * $quantity;
            $total += $subtotal;
            
            $stmtItem->execute([
                $orderId,
                $item['id'],
                $quantity,
                $item['notes'] ?? ''
            ]);
        }
        
        // Commit da transação
        $pdo->commit();
        
        // Responder com sucesso
        echo json_encode([
            "ok" => true,
            "success" => true,
            "id" => $orderId,
            "message" => "Pedido criado com sucesso",
            "order" => [
                "id" => $orderId,
                "table" => $input['table'],
                "total" => $total,
                "status" => "pending"
            ]
        ]);
        
    } catch (Exception $e) {
        // Rollback em caso de erro
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(["error" => "Erro ao criar pedido: " . $e->getMessage()]);
    }
    exit;
}

http_response_code(404);
echo json_encode(["error" => "Not found", "uri" => $uri, "method" => $method]);