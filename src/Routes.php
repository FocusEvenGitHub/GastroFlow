<?php
namespace App;

use App\Controllers\CategoryController;
use App\Controllers\DishController;
use App\Controllers\IngredientCategoryController;
use App\Controllers\IngredientController;
use App\Controllers\KitchenController;
use Slim\App;
use App\Controllers\MenuController;
use App\Controllers\OrderController;
use App\Controllers\AuthController;
use App\Middleware\JwtMiddleware;

class Routes
{
    public function register(App $app): void
    {
        $secret = $_ENV['JWT_SECRET'];

        // Middleware JWT
        $jwt = new JwtMiddleware($secret);

        // Rotas públicas
        $app->group('/api', function ($group) {
            // Cardápio
            $group->get('/menu', [MenuController::class, 'index']);
            // Pedidos
            $group->get('/orders', [OrderController::class, 'index']);
            $group->post('/orders', [OrderController::class, 'store']);
            $group->post('/orders/{id}/complete', [OrderController::class, 'complete']);

            $group->get('/kitchen/ingredients-summary', [KitchenController::class, 'ingredientsSummary']);

            $group->get('/ingredients', [IngredientController::class, 'index']);
            $group->post('/ingredients', [IngredientController::class, 'store']);
            $group->put('/ingredients/{id}', [IngredientController::class, 'update']);
            $group->delete('/ingredients/{id}', [IngredientController::class, 'destroy']);

            $group->get('/dishes/{id}', [DishController::class, 'show']);
            $group->put('/dishes/{id}', [DishController::class, 'update']);
        });

        // Rota de login (pública)
        $app->post('/api/login', function ($request, $response) use ($secret) {
            $controller = new AuthController($secret);
            return $controller->login($request, $response);
        });

        // Admin protected routes (JWT required)
        $app->group('/api/admin', function ($group) {
            // Menu management
            $group->get('/menu', [MenuController::class, 'index']);
            $group->post('/items', [MenuController::class, 'store']);
            $group->patch('/items/{id}', [MenuController::class, 'updateAvailability']);

            // Ingredients CRUD
            $group->get('/ingredients', [IngredientController::class, 'index']);
            $group->post('/ingredients', [IngredientController::class, 'store']);
            $group->put('/ingredients/{id}', [IngredientController::class, 'update']);
            $group->delete('/ingredients/{id}', [IngredientController::class, 'destroy']);

            // Dish recipe management
            $group->get('/dishes/{id}', [DishController::class, 'show']);
            $group->put('/dishes/{id}', [DishController::class, 'update']);

            // Dish categories
            $group->get('/categories', [CategoryController::class, 'index']);
            $group->post('/categories', [CategoryController::class, 'store']);
            $group->put('/categories/{id}', [CategoryController::class, 'update']);
            $group->delete('/categories/{id}', [CategoryController::class, 'destroy']);

            // Ingredient categories
            $group->get('/ingredient-categories', [IngredientCategoryController::class, 'index']);
            $group->post('/ingredient-categories', [IngredientCategoryController::class, 'store']);
            $group->put('/ingredient-categories/{id}', [IngredientCategoryController::class, 'update']);
            $group->delete('/ingredient-categories/{id}', [IngredientCategoryController::class, 'destroy']);
        })->add($jwt);
    }
}