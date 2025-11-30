<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../common/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = $_SERVER['REQUEST_URI'];

if ($method === 'GET' && preg_match('#/api/menu$#', $path)) {
    $category_filter = $_GET['category'] ?? null;
    
    $sql = "SELECT mi.*, c.name as category_name, c.type 
            FROM menu_items mi 
            JOIN categories c ON mi.category_id = c.id 
            WHERE mi.available = TRUE";
    
    if ($category_filter) {
        $sql .= " AND c.id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$category_filter]);
    } else {
        $stmt = $pdo->query($sql);
    }
    
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Agrupar por categoria
    $menu = [];
    foreach ($items as $item) {
        $categoryId = $item['category_id'];
        if (!isset($menu[$categoryId])) {
            $menu[$categoryId] = [
                'category_name' => $item['category_name'],
                'type' => $item['type'],
                'items' => []
            ];
        }
        $menu[$categoryId]['items'][] = $item;
    }
    
    echo json_encode(array_values($menu));
    exit;
}

if ($method === 'GET' && preg_match('#/api/categories$#', $path)) {
    $stmt = $pdo->query("SELECT * FROM categories ORDER BY type, name");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

http_response_code(404);
echo json_encode(["error" => "Not found"]);