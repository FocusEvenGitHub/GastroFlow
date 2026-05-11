<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\MenuItem;
use App\Models\Ingredient;
use Illuminate\Support\Facades\DB;  // Actually use Illuminate\Database\Capsule\Manager as DB

class DishController
{
    // GET /api/admin/dishes/{id} – return dish with its ingredients
    public function show(Request $request, Response $response, array $args): Response
    {
        $dish = MenuItem::with('ingredients')->findOrFail((int)$args['id']);
        $data = $dish->toArray();
        $data['ingredients'] = $dish->ingredients->map(fn($i) => [
            'ingredient_id' => $i->id,
            'name' => $i->name,
            'unit' => $i->unit,
            'quantity' => $i->pivot->quantity,
        ]);
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // PUT /api/admin/dishes/{id} – update dish and its ingredients
    public function update(Request $request, Response $response, array $args): Response
    {
        $dish = MenuItem::findOrFail((int)$args['id']);
        $data = $request->getParsedBody();

        // Update basic dish fields
        $dish->update([
            'name' => $data['name'] ?? $dish->name,
            'description' => $data['description'] ?? $dish->description,
            'price' => $data['price'] ?? $dish->price,
            'category_id' => $data['category_id'] ?? $dish->category_id,
        ]);

        // Update ingredients
        if (isset($data['ingredients']) && is_array($data['ingredients'])) {
            if (empty($data['ingredients'])) {
                $response->getBody()->write(json_encode(['error' => 'O prato deve ter ao menos um ingrediente.']));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }

            $sync = [];
            foreach ($data['ingredients'] as $ing) {
                $sync[$ing['id']] = ['quantity' => $ing['quantity']];
            }
            $dish->ingredients()->sync($sync);
        }

        $dish->load('ingredients');
        $response->getBody()->write(json_encode($dish));
        return $response->withHeader('Content-Type', 'application/json');
    }
}