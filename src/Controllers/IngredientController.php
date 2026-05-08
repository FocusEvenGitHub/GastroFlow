<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Ingredient;

class IngredientController
{
    // GET /api/admin/ingredients
    public function index(Request $request, Response $response): Response
    {
        $ingredients = Ingredient::orderBy('category')->orderBy('name')->get();
        $response->getBody()->write(json_encode($ingredients));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // POST /api/admin/ingredients
    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        if (empty($data['name']) || empty($data['unit'])) {
            $response->getBody()->write(json_encode(['error' => 'Name and unit are required.']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        $ingredient = Ingredient::create([
            'name' => $data['name'],
            'unit' => $data['unit'],
            'category' => $data['category'] ?? null,
        ]);
        $response->getBody()->write(json_encode($ingredient));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    // PUT /api/admin/ingredients/{id}
    public function update(Request $request, Response $response, array $args): Response
    {
        $ingredient = Ingredient::findOrFail((int)$args['id']);
        $data = $request->getParsedBody();
        $ingredient->update($data);
        $response->getBody()->write(json_encode($ingredient));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // DELETE /api/admin/ingredients/{id}
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $ingredient = Ingredient::findOrFail((int)$args['id']);
        $ingredient->delete();
        $response->getBody()->write(json_encode(['success' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}