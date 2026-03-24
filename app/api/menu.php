<?php
global $pdo;
header('Content-Type: application/json');
require_once __DIR__ . '/../common/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// GET /api/menu - Listar cardápio (todos os itens, ativos e inativos)
if ($method === 'GET' && $path === '/api/menu') {
    try {
        $stmt = $pdo->query("
        SELECT 
                c.id as category_id,
                c.name as category_name,
                c.type,
                mi.id as item_id,
                mi.name as item_name,
                mi.description as item_description,
                mi.price as item_price,
                mi.available
            FROM categories c
            LEFT JOIN menu_items mi ON mi.category_id = c.id
            ORDER BY c.type, c.name, mi.name
        ");

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $menu = [];
        foreach ($results as $row) {
            $categoryId = $row['category_id'];

            if (!isset($menu[$categoryId])) {
                $menu[$categoryId] = [
                    'category_name' => $row['category_name'],
                    'type' => $row['type'],
                    'items' => []
                ];
            }

            if ($row['item_id']) {
                $menu[$categoryId]['items'][] = [
                    'id' => $row['item_id'],
                    'name' => $row['item_name'],
                    'description' => $row['item_description'],
                    'price' => (float)$row['item_price'],
                    'available' => (bool)$row['available']  // incluído
                ];
            }
        }

        echo json_encode(array_values($menu));

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => $e->getMessage()]);
    }
    exit;
}

// POST /api/items - Adicionar novo item ao menu
if ($method === 'POST' && $path === '/api/items') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['name']) || !isset($input['price']) || !isset($input['category_name'])) {
        http_response_code(400);
        echo json_encode(["error" => "Campos obrigatórios: name, price, category_name"]);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT id FROM categories WHERE name = ?");
        $stmt->execute([$input['category_name']]);
        $category = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$category) {
            http_response_code(400);
            echo json_encode(["error" => "Categoria não encontrada"]);
            exit;
        }

        $category_id = $category['id'];
        $name = $input['name'];
        $description = $input['description'] ?? '';
        $price = floatval($input['price']);
        $available = isset($input['available']) ? (int)$input['available'] : 1;

        $stmt = $pdo->prepare("
            INSERT INTO menu_items (category_id, name, description, price, available)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$category_id, $name, $description, $price, $available]);

        $newId = $pdo->lastInsertId();

        echo json_encode([
            "success" => true,
            "id" => $newId,
            "message" => "Item adicionado com sucesso"
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => $e->getMessage()]);
    }
    exit;
}

// PATCH /api/items/{id} - Atualizar disponibilidade
if ($method === 'PATCH' && preg_match('#^/api/items/(\d+)$#', $path, $matches)) {
    $id = $matches[1];
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['available'])) {
        http_response_code(400);
        echo json_encode(["error" => "Campo 'available' obrigatório"]);
        exit;
    }

    try {
        // Verifica se o item existe
        $stmt = $pdo->prepare("SELECT available FROM menu_items WHERE id = ?");
        $stmt->execute([$id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            http_response_code(404);
            echo json_encode(["error" => "Item não encontrado"]);
            exit;
        }

        $newAvailable = (int)$input['available'];
        $currentAvailable = (int)$item['available'];

        if ($newAvailable === $currentAvailable) {
            // Já está no estado desejado
            echo json_encode(["success" => true, "message" => "Item já está " . ($newAvailable ? 'ativado' : 'desativado')]);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE menu_items SET available = ? WHERE id = ?");
        $stmt->execute([$newAvailable, $id]);

        echo json_encode(["success" => true, "message" => "Disponibilidade atualizada"]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => $e->getMessage()]);
    }
    exit;
}

// Se não encontrar a rota
http_response_code(404);
echo json_encode(["error" => "Endpoint não encontrado"]);