<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../common/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// GET /api/menu - Listar cardápio
if ($method === 'GET' && $path === '/api/menu') {
    try {
        // Buscar categorias com itens
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
            LEFT JOIN menu_items mi ON mi.category_id = c.id AND mi.available = TRUE
            ORDER BY c.type, c.name, mi.name
        ");
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Organizar por categoria
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
                    'price' => $row['item_price']
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

// Se não encontrar a rota, retornar erro
http_response_code(404);
echo json_encode(["error" => "Endpoint não encontrado"]);