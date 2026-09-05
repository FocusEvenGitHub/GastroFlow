<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\ApiResponse;
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
            return ApiResponse::error($response, 400, 'VALIDATION_FAILED', 'Validation failed', ['messages' => $errors]);
        }

        try {
            $order = $this->orderService->createOrder($data);
            $payload = ['success' => true, 'id' => $order->id, 'message' => 'Order created'];
            $response->getBody()->write(json_encode($payload));
            return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
        } catch (\DomainException $e) {
            // Nonexistent or unavailable menu item (spec 022) — bad input, not a conflict.
            return ApiResponse::error($response, 400, 'INVALID_ORDER_ITEM', $e->getMessage());
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() !== '23000') {
                // Not a duplicate-key violation (e.g. a deadlock/lock-wait
                // timeout under concurrency) — not this order_number's fault.
                throw $e;
            }
            // Unique constraint violation — order_number already used for this business_date
            return ApiResponse::error($response, 409, 'ORDER_NUMBER_TAKEN', 'Número da senha já utilizado hoje. Peça um novo número.');
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
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ApiResponse::error($response, 404, 'ORDER_NOT_FOUND', 'Pedido não encontrado');
        } catch (\DomainException $e) {
            return ApiResponse::error($response, 409, 'ORDER_CANCELLED', $e->getMessage());
        }
        $payload = ['success' => true, 'message' => 'Order completed'];
        $response->getBody()->write(json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function uncomplete(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        try {
            $this->orderService->uncompleteOrder($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ApiResponse::error($response, 404, 'ORDER_NOT_FOUND', 'Pedido não encontrado');
        } catch (\DomainException $e) {
            return ApiResponse::error($response, 409, 'ORDER_CANCELLED', $e->getMessage());
        }
        $payload = ['success' => true, 'message' => 'Order reopened'];
        $response->getBody()->write(json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function cancel(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        try {
            $this->orderService->cancelOrder($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ApiResponse::error($response, 404, 'ORDER_NOT_FOUND', 'Pedido não encontrado');
        } catch (\DomainException $e) {
            return ApiResponse::error($response, 409, 'ORDER_ALREADY_CANCELLED', $e->getMessage());
        }
        $payload = ['success' => true, 'message' => 'Order cancelled'];
        $response->getBody()->write(json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $data = $request->getParsedBody() ?? [];

        if (!$this->validator->validateOrderUpdate($data)) {
            $errors = $this->validator->errors();
            return ApiResponse::error($response, 400, 'VALIDATION_FAILED', 'Validation failed', ['messages' => $errors]);
        }

        try {
            $this->orderService->updateOrder($id, $data);
            $payload = ['success' => true, 'message' => 'Order updated'];
            $response->getBody()->write(json_encode($payload));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ApiResponse::error($response, 404, 'ORDER_NOT_FOUND', 'Pedido não encontrado');
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            // Unique constraint violation — order_number already used for this business_date
            return ApiResponse::error($response, 409, 'ORDER_NUMBER_TAKEN', 'Número da senha já utilizado hoje. Peça um novo número.');
        }
    }

    public function addItem(Request $request, Response $response, array $args): Response
    {
        $orderId = (int)$args['id'];
        $data = $request->getParsedBody() ?? [];

        if (!$this->validator->validateOrderItemAdd($data)) {
            $errors = $this->validator->errors();
            return ApiResponse::error($response, 400, 'VALIDATION_FAILED', 'Validation failed', ['messages' => $errors]);
        }

        try {
            $item = $this->orderService->addOrderItem($orderId, $data);
            $payload = ['success' => true, 'item' => $item];
            $response->getBody()->write(json_encode($payload));
            return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ApiResponse::error($response, 404, 'ORDER_OR_MENU_ITEM_NOT_FOUND', 'Pedido ou item de cardápio não encontrado');
        } catch (\DomainException $e) {
            // Unavailable menu item (spec 022) — bad input, not a conflict.
            return ApiResponse::error($response, 400, 'MENU_ITEM_UNAVAILABLE', $e->getMessage());
        }
    }

    public function updateItem(Request $request, Response $response, array $args): Response
    {
        $orderId = (int)$args['id'];
        $itemId = (int)$args['itemId'];
        $data = $request->getParsedBody() ?? [];

        if (!$this->validator->validateOrderItemUpdate($data)) {
            $errors = $this->validator->errors();
            return ApiResponse::error($response, 400, 'VALIDATION_FAILED', 'Validation failed', ['messages' => $errors]);
        }

        try {
            $this->orderService->updateOrderItem($orderId, $itemId, $data);
            $payload = ['success' => true, 'message' => 'Item updated'];
            $response->getBody()->write(json_encode($payload));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ApiResponse::error($response, 404, 'ORDER_ITEM_NOT_FOUND', 'Item não encontrado neste pedido');
        }
    }

    public function removeItem(Request $request, Response $response, array $args): Response
    {
        $orderId = (int)$args['id'];
        $itemId = (int)$args['itemId'];

        try {
            $this->orderService->removeOrderItem($orderId, $itemId);
            $payload = ['success' => true, 'message' => 'Item removed'];
            $response->getBody()->write(json_encode($payload));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ApiResponse::error($response, 404, 'ORDER_ITEM_NOT_FOUND', 'Item não encontrado neste pedido');
        } catch (\DomainException $e) {
            return ApiResponse::error($response, 409, 'LAST_ORDER_ITEM', $e->getMessage());
        }
    }

    public function print(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];

        try {
            $this->orderService->printOrder($id);
            $payload = ['success' => true, 'message' => 'Print job queued'];
            $response->getBody()->write(json_encode($payload));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ApiResponse::error($response, 404, 'ORDER_NOT_FOUND', 'Pedido não encontrado');
        }
    }
}
