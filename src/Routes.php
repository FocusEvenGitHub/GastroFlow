<?php
namespace App;

use Slim\App;
use App\Controllers\MenuController;
use App\Controllers\OrderController;
use App\Controllers\AuthController;
use App\Middleware\JwtMiddleware;

class Routes
{
    public function register(App $app): void
    {
        // Definir a chave secreta (use uma variável de ambiente na prática)
        $secret = $_ENV['JWT_SECRET'] ?? 'your-secret-key-change-me';

        // Middleware JWT
        $jwt = new JwtMiddleware($secret);

        // Rotas públicas
        $app->group('/api', function ($group) {
            // Cardápio
            $group->get('/menu', [MenuController::class, 'index']);
            // Pedidos (caixa e cozinha precisam criar/completar, mas sem autenticação por enquanto)
            $group->get('/orders', [OrderController::class, 'index']);
            $group->post('/orders', [OrderController::class, 'store']);
            $group->post('/orders/{id}/complete', [OrderController::class, 'complete']);
        });

        // Rota de login (pública)
        $app->post('/api/login', function ($request, $response) use ($secret) {
            $controller = new AuthController($secret);
            return $controller->login($request, $response);
        });

        // Grupo protegido (admin) – exige JWT
        $app->group('/api/admin', function ($group) {
            $group->get('/menu', [MenuController::class, 'index']);
            $group->post('/items', [MenuController::class, 'store']);
            $group->patch('/items/{id}', [MenuController::class, 'updateAvailability']);
        })->add($jwt); // middleware aplicado a todo o grupo
    }
}