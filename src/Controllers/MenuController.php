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
        if (!isset($data['name'], $data['price'], $data['category_name'])) {
            $response->getBody()->write(json_encode([
                'error' => 'Campos obrigatórios: name, price, category_name'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        try {
            $item = $this->menuService->addItem($data);
            $payload = ['success' => true, 'id' => $item->id, 'message' => 'Item adicionado'];
            $response->getBody()->write(json_encode($payload));
            return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
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