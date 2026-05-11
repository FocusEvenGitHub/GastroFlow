<?php
namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Category;

class CategoryController
{
    public function index(Request $request, Response $response): Response
    {
        $categories = Category::orderBy('name')->get();
        $response->getBody()->write(json_encode($categories));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        if (empty($data['name']) || empty($data['type'])) {
            $response->getBody()->write(json_encode(['error' => 'Name and type are required.']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        $cat = Category::create(['name' => $data['name'], 'type' => $data['type']]);
        $response->getBody()->write(json_encode($cat));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $cat = Category::findOrFail((int)$args['id']);
        $data = $request->getParsedBody();
        $cat->update($data);
        $response->getBody()->write(json_encode($cat));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $cat = Category::findOrFail((int)$args['id']);
        // prevent deletion if it has menu items
        if ($cat->menuItems()->count() > 0) {
            $response->getBody()->write(json_encode(['error' => 'Categoria possui pratos vinculados.']));
            return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
        }
        $cat->delete();
        $response->getBody()->write(json_encode(['success' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}