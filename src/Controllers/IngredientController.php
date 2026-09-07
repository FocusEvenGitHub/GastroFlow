<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\ApiResponse;
use App\Services\IngredientService;
use App\Validators\IngredientValidator;

class IngredientController
{
    public function __construct(
        private readonly IngredientService $ingredientService,
        private readonly IngredientValidator $validator,
    ) {
    }

    // GET /api/admin/ingredients
    public function index(Request $request, Response $response): Response
    {
        $ingredients = $this->ingredientService->getAll();
        $response->getBody()->write(json_encode($ingredients));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // POST /api/admin/ingredients
    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        if (!$this->validator->validateCreate($data)) {
            $errors = $this->validator->errors();
            return ApiResponse::error($response, 400, 'VALIDATION_FAILED', 'Validation failed', ['messages' => $errors]);
        }
        $ingredient = $this->ingredientService->create($data);
        $response->getBody()->write(json_encode($ingredient));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    // PUT /api/admin/ingredients/{id}
    public function update(Request $request, Response $response, array $args): Response
    {
        try {
            $this->ingredientService->findOrFail((int)$args['id']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ApiResponse::error($response, 404, 'INGREDIENT_NOT_FOUND', 'Ingredient not found.');
        }
        $data = $request->getParsedBody();
        if (empty($data)) {
            return ApiResponse::error($response, 400, 'EMPTY_PAYLOAD', 'Nenhum dado enviado');
        }
        if (!$this->validator->validateUpdate($data)) {
            $errors = $this->validator->errors();
            return ApiResponse::error($response, 400, 'VALIDATION_FAILED', 'Validation failed', ['messages' => $errors]);
        }
        $ingredient = $this->ingredientService->update((int)$args['id'], $data);
        $response->getBody()->write(json_encode($ingredient));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // DELETE /api/admin/ingredients/{id}
    public function destroy(Request $request, Response $response, array $args): Response
    {
        try {
            $this->ingredientService->delete((int)$args['id']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ApiResponse::error($response, 404, 'INGREDIENT_NOT_FOUND', 'Ingredient not found.');
        }
        $response->getBody()->write(json_encode(['success' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}