<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\MenuService;

class MenuController
{
    private MenuService $menuService;

    public function __construct(MenuService $menuService)
    {
        $this->menuService = $menuService;
    }

    // GET /api/menu
    public function index(Request $request, Response $response): Response
    {
        $menu = $this->menuService->getFullMenu();
        $response->getBody()->write(json_encode($menu));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // POST /api/items
    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $validator = new \Valitron\Validator($data);
        $validator->rule('required', ['name', 'price', 'category_name']);
        $validator->rule('numeric', 'price');
        $validator->rule('array', 'ingredients');
        // at least one ingredient if ingredients array is provided
        $validator->rule(function($field, $value, $params, $fields) {
            if (!is_array($value)) return true;
            return count($value) >= 1;
        }, 'ingredients')->message('Informe pelo menos um ingrediente.');

        if (!$validator->validate()) {
            $response->getBody()->write(json_encode(['error' => 'Validation failed', 'messages' => $validator->errors()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // Find category
        $category = \App\Models\Category::where('name', $data['category_name'])->first();
        if (!$category) {
            $response->getBody()->write(json_encode(['error' => 'Categoria não encontrada.']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // Create menu item
        $item = \App\Models\MenuItem::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? '',
            'price' => $data['price'],
            'category_id' => $category->id,
            'available' => $data['available'] ?? true,
        ]);

        // Sync ingredients (with quantities)
        if (!empty($data['ingredients'])) {
            $sync = [];
            foreach ($data['ingredients'] as $ing) {
                $sync[$ing['id']] = ['quantity' => $ing['quantity']];
            }
            $item->ingredients()->sync($sync);
        }

        $item->load('ingredients');
        $response->getBody()->write(json_encode($item));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    // PATCH /api/items/{id}
    public function updateAvailability(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $data = $request->getParsedBody();
        if (!isset($data['available'])) {
            $response->getBody()->write(json_encode(['error' => "Campo 'available' obrigatório"]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        try {
            $newAvailable = (bool)$data['available'];
            $item = $this->menuService->updateAvailability($id, $newAvailable);
            $payload = ['success' => true, 'message' => 'Disponibilidade atualizada'];
            $response->getBody()->write(json_encode($payload));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
}