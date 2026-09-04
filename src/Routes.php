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
use App\Middleware\RoleMiddleware;

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
            $group->patch('/menu/reorder', [MenuController::class, 'reorder']);
            $group->get('/orders', [OrderController::class, 'index']);
            $group->post('/orders', [OrderController::class, 'store']);
            $group->patch('/orders/{id}', [OrderController::class, 'update']);
            $group->delete('/orders/{id}', [OrderController::class, 'destroy']);
            $group->post('/orders/{id}/items', [OrderController::class, 'addItem']);
            $group->patch('/orders/{id}/items/{itemId}', [OrderController::class, 'updateItem']);
            $group->delete('/orders/{id}/items/{itemId}', [OrderController::class, 'removeItem']);
            $group->post('/orders/{id}/print', [OrderController::class, 'print']);
            $group->post('/orders/{id}/complete', [OrderController::class, 'complete']);
            $group->post('/orders/{id}/uncomplete', [OrderController::class, 'uncomplete']);
            $group->get('/orders/next-number', [OrderController::class, 'nextNumber']);
            $group->get('/kitchen/food-summary', [KitchenController::class, 'foodCategorySummary']);
        });

        $app->post('/api/login', function ($request, $response) use ($secret) {
            $controller = new AuthController($secret);
            return $controller->login($request, $response);
        });

        $app->patch('/api/admin/account/password', function ($request, $response) use ($secret) {
            $controller = new AuthController($secret);
            return $controller->changePassword($request, $response);
        })->add($jwt);

        $adminOrManager = fn () => new RoleMiddleware(['admin', 'manager']);
        $adminOnly = fn () => new RoleMiddleware(['admin']);

        $app->group('/api/admin', function ($group) use ($adminOrManager, $adminOnly) {
            $group->get('/menu', [MenuController::class, 'index'])->add($adminOrManager());
            $group->post('/items', [MenuController::class, 'store'])->add($adminOrManager());
            $group->patch('/items/{id}', [MenuController::class, 'updateItem'])->add($adminOrManager());
            $group->get('/items/{id}/components', [MenuController::class, 'getComponents'])->add($adminOrManager());
            $group->put('/items/{id}/components', [MenuController::class, 'updateComponents'])->add($adminOrManager());
            $group->delete('/items/{id}', [MenuController::class, 'delete'])->add($adminOrManager());
            $group->get('/settings', [AdminController::class, 'getSettings'])->add($adminOnly());
            $group->put('/settings', [AdminController::class, 'updateSettings'])->add($adminOnly());
            $group->post('/settings/logo', [AdminController::class, 'uploadLogo'])->add($adminOnly());
            $group->get('/logs', [AdminController::class, 'getLogs'])->add($adminOnly());
            $group->post('/settings/test-print', [AdminController::class, 'testPrint'])->add($adminOnly());
            $group->get('/reports/sales',          [ReportController::class, 'sales'])->add($adminOrManager());
            $group->get('/reports/top-items',      [ReportController::class, 'topItems'])->add($adminOrManager());
            $group->get('/reports/dining-options', [ReportController::class, 'diningOptions'])->add($adminOrManager());
            $group->get('/reports/summary',        [ReportController::class, 'summary'])->add($adminOrManager());
            $group->get('/reports/peak-hours',     [ReportController::class, 'peakHours'])->add($adminOrManager());
            $group->get('/reports/prep-time',      [ReportController::class, 'prepTime'])->add($adminOrManager());
            $group->get('/reports/month-comparison', [ReportController::class, 'monthlyComparison'])->add($adminOrManager());
        })->add($jwt);
    }
}
