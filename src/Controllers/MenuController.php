<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\ApiResponse;
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
            return ApiResponse::error($response, 400, 'MISSING_REQUIRED_FIELDS', 'Campos obrigatórios: name, price, category_name');
        }
        if (!is_numeric($data['price'])) {
            // Code review fix: a non-numeric price used to silently become
            // R$0,00 (or, for a non-scalar value, an uncaught 500) inside
            // Money::fromReais() — reject it here instead, before it reaches
            // persistence at all.
            return ApiResponse::error($response, 400, 'INVALID_PRICE', 'Campo "price" deve ser numérico');
        }

        try {
            $item = $this->menuService->addItem($data);
            $payload = ['success' => true, 'id' => $item->id, 'message' => 'Item adicionado'];
            $response->getBody()->write(json_encode($payload));
            return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            // category_name doesn't match any existing category (MenuRepository::addItem()'s firstOrFail())
            return ApiResponse::error($response, 404, 'CATEGORY_NOT_FOUND', 'Categoria não encontrada');
        }
    }

    // PATCH /api/admin/items/{id}
    public function updateItem(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $data = $request->getParsedBody();

        if (empty($data)) {
            return ApiResponse::error($response, 400, 'EMPTY_PAYLOAD', 'Nenhum dado enviado');
        }
        if (isset($data['price']) && !is_numeric($data['price'])) {
            return ApiResponse::error($response, 400, 'INVALID_PRICE', 'Campo "price" deve ser numérico');
        }

        try {
            $this->menuService->updateItem($id, $data);
            $payload = ['success' => true, 'message' => 'Item atualizado'];
            $response->getBody()->write(json_encode($payload));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ApiResponse::error($response, 404, 'MENU_ITEM_NOT_FOUND', 'Item não encontrado');
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
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ApiResponse::error($response, 404, 'MENU_ITEM_NOT_FOUND', 'Item não encontrado');
        }
    }

    // PUT /api/admin/items/{id}/components
    public function updateComponents(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $data = $request->getParsedBody();

        if (!isset($data['components']) || !is_array($data['components'])) {
            return ApiResponse::error($response, 400, 'MISSING_REQUIRED_FIELDS', 'Campo "components" obrigatório');
        }

        try {
            $this->menuService->updateDishComponents($id, $data['components']);
            $payload = ['success' => true, 'message' => 'Componentes atualizados'];
            $response->getBody()->write(json_encode($payload));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ApiResponse::error($response, 404, 'MENU_ITEM_NOT_FOUND', 'Item não encontrado');
        }
    }

    // PATCH /api/menu/reorder
    public function reorder(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        if (!isset($data['category_name']) || !isset($data['item_ids']) || !is_array($data['item_ids']) || empty($data['item_ids'])) {
            return ApiResponse::error($response, 400, 'MISSING_REQUIRED_FIELDS', 'Campos obrigatórios: category_name, item_ids (array não vazio)');
        }

        try {
            $this->menuService->reorderItems($data['category_name'], $data['item_ids']);
            $payload = ['success' => true, 'message' => 'Ordem atualizada'];
            $response->getBody()->write(json_encode($payload));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ApiResponse::error($response, 404, 'CATEGORY_NOT_FOUND', 'Categoria não encontrada');
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
            return ApiResponse::error($response, 404, 'MENU_ITEM_NOT_FOUND', 'Item não encontrado');
        } catch (\Illuminate\Database\QueryException $e) {
            // Foreign key constraint — item vinculado a pedidos existentes
            return ApiResponse::error($response, 409, 'MENU_ITEM_IN_USE', 'Não é possível excluir este item, pois ele está vinculado a pedidos existentes.');
        }
    }
}
