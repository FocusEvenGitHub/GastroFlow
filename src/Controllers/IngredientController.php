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
        $ingredients = Ingredient::with('category')
            ->orderBy('category_id')
            ->orderBy('name')
            ->get()
            ->map(function ($ing) {
                return [
                    'id'          => $ing->id,
                    'name'        => $ing->name,
                    'unit'        => $ing->unit,
                    'category_id' => $ing->category_id,
                    'category'    => $ing->category ? $ing->category->name : null,
                ];
            });

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
        try {
            $ingredient = Ingredient::findOrFail((int)$args['id']);
            $ingredient->delete();
            $response->getBody()->write(json_encode(['success' => true]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() == 23000) { // integrity constraint violation
                $response->getBody()->write(json_encode(['error' => 'Este ingrediente está em uso em um ou mais pratos.']));
                return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
            }
            throw $e;
        }
    }
}