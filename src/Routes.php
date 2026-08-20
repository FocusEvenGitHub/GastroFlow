<?php

declare(strict_types=1);

namespace App;

use Slim\App;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Controllers\MenuController;
use App\Controllers\OrderController;
use App\Controllers\AuthController;
use App\Controllers\KitchenController;
use App\Controllers\AdminController;
use App\Controllers\ReportController;
use App\Middleware\JwtMiddleware;

class Routes
{
    public function register(App $app): void
    {
        $secret = $_ENV['JWT_SECRET'] ?? throw new \RuntimeException('JWT_SECRET environment variable is not set.');
        $jwt = new JwtMiddleware($secret);

        $app->get('/', function (Request $request, Response $response) {
            return $response->withHeader('Location', '/cashier/')->withStatus(302);
        });

        $app->group('/api', function ($group) {
            $group->get('/menu', [MenuController::class, 'index']);
            $group->get('/orders', [OrderController::class, 'index']);
            $group->post('/orders', [OrderController::class, 'store']);
            $group->post('/orders/{id}/complete', [OrderController::class, 'complete']);
            $group->post('/orders/{id}/uncomplete', [OrderController::class, 'uncomplete']);
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
            $group->delete('/items/{id}', [MenuController::class, 'delete']);
            $group->get('/settings', [AdminController::class, 'getSettings']);
            $group->put('/settings', [AdminController::class, 'updateSettings']);
            $group->post('/settings/logo', [AdminController::class, 'uploadLogo']);
            $group->get('/logs', [AdminController::class, 'getLogs']);
            $group->post('/settings/test-print', [AdminController::class, 'testPrint']);
            $group->get('/reports/sales',          [ReportController::class, 'sales']);
            $group->get('/reports/top-items',      [ReportController::class, 'topItems']);
            $group->get('/reports/dining-options', [ReportController::class, 'diningOptions']);
            $group->get('/reports/summary',        [ReportController::class, 'summary']);
            $group->get('/reports/peak-hours',     [ReportController::class, 'peakHours']);
            $group->get('/reports/prep-time',      [ReportController::class, 'prepTime']);
            $group->get('/reports/month-comparison', [ReportController::class, 'monthlyComparison']);
        })->add($jwt);
    }
}
