<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\ApiResponse;
use App\Models\MenuItem;
use App\Models\Ingredient;

class DishController
{
    // GET /api/admin/dishes/{id} – return dish with its ingredients
    public function show(Request $request, Response $response, array $args): Response
    {
        try {
            $dish = MenuItem::with('ingredients')->findOrFail((int)$args['id']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ApiResponse::error($response, 404, 'DISH_NOT_FOUND', 'Dish not found.');
        }
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
        try {
            $dish = MenuItem::findOrFail((int)$args['id']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ApiResponse::error($response, 404, 'DISH_NOT_FOUND', 'Dish not found.');
        }
        $data = $request->getParsedBody();

        // Update basic dish fields
        $dish->update([
            'name' => $data['name'] ?? $dish->name,
            'description' => $data['description'] ?? $dish->description,
            'price' => $data['price'] ?? $dish->price,
            'category_id' => $data['category_id'] ?? $dish->category_id,
        ]);

        // Update ingredients (pivot table)
        if (isset($data['ingredients']) && is_array($data['ingredients'])) {
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