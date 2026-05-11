<?php
namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\IngredientCategory;

class IngredientCategoryController
{
    public function index(Request $request, Response $response): Response
    {
        $cats = IngredientCategory::orderBy('name')->get();
        $response->getBody()->write(json_encode($cats));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        if (empty($data['name'])) {
            return $this->error($response, 'Name is required.');
        }
        $cat = IngredientCategory::create(['name' => $data['name']]);
        $response->getBody()->write(json_encode($cat));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $cat = IngredientCategory::findOrFail((int)$args['id']);
        $cat->update($request->getParsedBody());
        $response->getBody()->write(json_encode($cat));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $cat = IngredientCategory::findOrFail((int)$args['id']);
        if ($cat->ingredients()->count() > 0) {
            $response->getBody()->write(json_encode(['error' => 'Categoria possui ingredientes vinculados.']));
            return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
        }
        $cat->delete();
        $response->getBody()->write(json_encode(['success' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function error(Response $response, string $msg, int $code = 400): Response
    {
        $response->getBody()->write(json_encode(['error' => $msg]));
        return $response->withStatus($code)->withHeader('Content-Type', 'application/json');
    }
}