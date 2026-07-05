<?php
namespace App;

use Slim\App;
use App\Controllers\MenuController;
use App\Controllers\OrderController;
use App\Controllers\AuthController;
use App\Controllers\KitchenController;
use App\Middleware\JwtMiddleware;

class Routes
{
    public function register(App $app): void
    {
        $secret = $_ENV['JWT_SECRET'] ?? 'your-secret-key-change-me';
        $jwt = new JwtMiddleware($secret);

        $app->group('/api', function ($group) {
            $group->get('/menu', [MenuController::class, 'index']);
            $group->get('/orders', [OrderController::class, 'index']);
            $group->post('/orders', [OrderController::class, 'store']);
            $group->post('/orders/{id}/complete', [OrderController::class, 'complete']);
            $group->get('/orders/next-number', [OrderController::class, 'nextNumber']);
            $group->get('/kitchen/food-summary', [KitchenController::class, 'foodCategorySummary']);
        });

        $app->post('/api/login', function ($request, $response) use ($secret) {
            $controller = new AuthController($secret);
            return $controller->login($request, $response);
        });

        $app->group('/api/admin', function ($group) {
            $group->get('/menu', [MenuController::class, 'index']);
            $group->post('/items', [MenuController::class, 'store']);
            $group->patch('/items/{id}', [MenuController::class, 'updateItem']);
            $group->get('/items/{id}/components', [MenuController::class, 'getComponents']);
            $group->put('/items/{id}/components', [MenuController::class, 'updateComponents']);
        })->add($jwt);
    }
}
