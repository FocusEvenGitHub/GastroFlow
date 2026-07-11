<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\OrderService;
use App\Validators\OrderValidator;

class OrderController
{
    private OrderService $orderService;
    private OrderValidator $validator;

    public function __construct(OrderService $orderService, OrderValidator $validator)
    {
        $this->orderService = $orderService;
        $this->validator = $validator;
    }

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $status = $params['status'] ?? 'pending';
        $date = $params['date'] ?? null;
        $orders = $this->orderService->getOrders($status, $date);

        $response->getBody()->write(json_encode($orders));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        if (!$this->validator->validateOrderData($data)) {
            $errors = $this->validator->errors();
            $response->getBody()->write(json_encode(['error' => 'Validation failed', 'messages' => $errors]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        try {
            $order = $this->orderService->createOrder($data);
            $payload = ['success' => true, 'id' => $order->id, 'message' => 'Order created'];
            $response->getBody()->write(json_encode($payload));
            return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    public function nextNumber(Request $request, Response $response): Response
    {
        $next = $this->orderService->getNextNumber();
        $response->getBody()->write(json_encode(['next' => $next]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function complete(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        try {
            $this->orderService->completeOrder($id);
            $payload = ['success' => true, 'message' => 'Order completed'];
        } catch (\Throwable $e) {
            $payload = ['error' => $e->getMessage()];
            $status = 500;
        }
        $response->getBody()->write(json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status ?? 200);
    }

    public function uncomplete(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        try {
            $this->orderService->uncompleteOrder($id);
            $payload = ['success' => true, 'message' => 'Order reopened'];
        } catch (\Throwable $e) {
            $payload = ['error' => $e->getMessage()];
            $status = 500;
        }
        $response->getBody()->write(json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status ?? 200);
    }
}