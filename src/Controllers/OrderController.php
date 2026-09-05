<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use App\Services\OrderService;
use App\Validators\OrderValidator;
use App\Settings;

class OrderController
{
    private OrderService $orderService;
    private OrderValidator $validator;
    private Settings $settings;
    private LoggerInterface $logger;

    public function __construct(OrderService $orderService, OrderValidator $validator, Settings $settings, LoggerInterface $logger)
    {
        $this->orderService = $orderService;
        $this->validator = $validator;
        $this->settings = $settings;
        $this->logger = $logger;
    }

    private function errorResponse(Response $response, \Throwable $e, int $status = 500): Response
    {
        $this->logger->error($e->getMessage(), ['exception' => get_class($e)]);

        $payload = $this->settings->isDebug()
            ? ['error' => $e->getMessage()]
            : ['success' => false, 'error' => 'Erro interno do servidor.', 'code' => 'INTERNAL_ERROR'];

        $response->getBody()->write(json_encode($payload));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
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
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() !== '23000') {
                // Not a duplicate-key violation (e.g. a deadlock/lock-wait
                // timeout under concurrency) — not this order_number's fault.
                return $this->errorResponse($response, $e);
            }
            // Unique constraint violation — order_number already used for this business_date
            $response->getBody()->write(json_encode([
                'error' => 'Número da senha já utilizado hoje. Peça um novo número.'
            ]));
            return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            return $this->errorResponse($response, $e);
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
            $response->getBody()->write(json_encode(['error' => 'Pedido não encontrado']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        } catch (\DomainException $e) {
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            return $this->errorResponse($response, $e);
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
            $response->getBody()->write(json_encode(['error' => 'Pedido não encontrado']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        } catch (\DomainException $e) {
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            return $this->errorResponse($response, $e);
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
            $response->getBody()->write(json_encode(['error' => 'Pedido não encontrado']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        } catch (\DomainException $e) {
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            return $this->errorResponse($response, $e);
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
            $response->getBody()->write(json_encode(['error' => 'Validation failed', 'messages' => $errors]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        try {
            $this->orderService->updateOrder($id, $data);
            $payload = ['success' => true, 'message' => 'Order updated'];
            $response->getBody()->write(json_encode($payload));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            $response->getBody()->write(json_encode(['error' => 'Pedido não encontrado']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() !== '23000') {
                return $this->errorResponse($response, $e);
            }
            // Unique constraint violation — order_number already used for this business_date
            $response->getBody()->write(json_encode([
                'error' => 'Número da senha já utilizado hoje. Peça um novo número.'
            ]));
            return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            return $this->errorResponse($response, $e);
        }
    }

    public function addItem(Request $request, Response $response, array $args): Response
    {
        $orderId = (int)$args['id'];
        $data = $request->getParsedBody() ?? [];

        if (!$this->validator->validateOrderItemAdd($data)) {
            $errors = $this->validator->errors();
            $response->getBody()->write(json_encode(['error' => 'Validation failed', 'messages' => $errors]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        try {
            $item = $this->orderService->addOrderItem($orderId, $data);
            $payload = ['success' => true, 'item' => $item];
            $response->getBody()->write(json_encode($payload));
            return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            $response->getBody()->write(json_encode(['error' => 'Pedido ou item de cardápio não encontrado']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            return $this->errorResponse($response, $e);
        }
    }

    public function updateItem(Request $request, Response $response, array $args): Response
    {
        $orderId = (int)$args['id'];
        $itemId = (int)$args['itemId'];
        $data = $request->getParsedBody() ?? [];

        if (!$this->validator->validateOrderItemUpdate($data)) {
            $errors = $this->validator->errors();
            $response->getBody()->write(json_encode(['error' => 'Validation failed', 'messages' => $errors]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        try {
            $this->orderService->updateOrderItem($orderId, $itemId, $data);
            $payload = ['success' => true, 'message' => 'Item updated'];
            $response->getBody()->write(json_encode($payload));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            $response->getBody()->write(json_encode(['error' => 'Item não encontrado neste pedido']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            return $this->errorResponse($response, $e);
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
            $response->getBody()->write(json_encode(['error' => 'Item não encontrado neste pedido']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        } catch (\DomainException $e) {
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            return $this->errorResponse($response, $e);
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
            $response->getBody()->write(json_encode(['error' => 'Pedido não encontrado']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            return $this->errorResponse($response, $e);
        }
    }
}
