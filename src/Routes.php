<?php
namespace App;

use Slim\App;
use App\Controllers\MenuController;
use App\Controllers\OrderController;

class Routes
{
    public function register(App $app): void
    {
        // API group
        $app->group('/api', function ($group) {
            // Menu
            $group->get('/menu', [MenuController::class, 'index']);
            $group->post('/items', [MenuController::class, 'store']);
            $group->patch('/items/{id}', [MenuController::class, 'updateAvailability']);

            // Orders
            $group->get('/orders', [OrderController::class, 'index']);
            $group->post('/orders', [OrderController::class, 'store']);
            $group->post('/orders/{id}/complete', [OrderController::class, 'complete']);
        });
    }
}