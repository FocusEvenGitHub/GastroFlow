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

    // POST /api/admin/items
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
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    // PATCH /api/admin/items/{id}
    public function updateItem(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $data = $request->getParsedBody();

        if (empty($data)) {
            $response->getBody()->write(json_encode(['error' => 'Nenhum dado enviado']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        try {
            $this->menuService->updateItem($id, $data);
            $payload = ['success' => true, 'message' => 'Item atualizado'];
            $response->getBody()->write(json_encode($payload));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    // GET /api/admin/items/{id}/components
    public function getComponents(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        try {
            $components = $this->menuService->getDishComponents($id);
            $response->getBody()->write(json_encode($components));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    // PUT /api/admin/items/{id}/components
    public function updateComponents(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $data = $request->getParsedBody();

        if (!isset($data['components']) || !is_array($data['components'])) {
            $response->getBody()->write(json_encode(['error' => 'Campo "components" obrigatório']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        try {
            $this->menuService->updateDishComponents($id, $data['components']);
            $payload = ['success' => true, 'message' => 'Componentes atualizados'];
            $response->getBody()->write(json_encode($payload));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    // DELETE /api/admin/items/{id}
    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];

        try {
            $this->menuService->deleteItem($id);
            $payload = ['success' => true, 'message' => 'Item excluído com sucesso'];
            $response->getBody()->write(json_encode($payload));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            $response->getBody()->write(json_encode(['error' => 'Item não encontrado']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        } catch (\Illuminate\Database\QueryException $e) {
            // Foreign key constraint — item vinculado a pedidos existentes
            $response->getBody()->write(json_encode([
                'error' => 'Não é possível excluir este item, pois ele está vinculado a pedidos existentes.'
            ]));
            return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
}
