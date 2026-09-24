<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\OrderRepository;
use App\Models\Order;

class OrderService
{
    private OrderRepository $orderRepo;
    private PrintService $printService;
    private JobService $jobService;

    public function __construct(OrderRepository $orderRepo, PrintService $printService, JobService $jobService)
    {
        $this->orderRepo = $orderRepo;
        $this->printService = $printService;
        $this->jobService = $jobService;
    }

    public function getOrders(string $status, ?string $date = null): array
    {
        return $this->orderRepo->getOrdersByStatus($status, $date);
    }

    public function createOrder(array $data): Order
    {
        $order = $this->orderRepo->createOrder($data);

        // Dispara job de impressão assíncrono (apenas se print_ticket for true).
        // Com a impressora bloqueada o job NÃO é enfileirado — sem isso o bloqueio seria
        // cosmético, e qualquer cliente da API continuaria empilhando jobs condenados.
        // O pedido em si é criado normalmente: a regra dura do roadmap diz que uma falha de
        // impressora nunca invalida um pedido (spec 008 FR8, spec 039).
        $printTicket = isset($data['print_ticket']) ? (bool) $data['print_ticket'] : true;
        if ($printTicket && !$this->printService->isPrintingBlocked()) {
            $this->jobService->dispatch('print', \App\Jobs\PrintOrderJob::class, [
                'order_id' => $order->id,
            ]);
        }

        // Dispara evento SSE para a cozinha
        $this->triggerKitchenEvent('order.created', $order->id);

        return $order;
    }

    public function completeOrder(int $id): void
    {
        $this->orderRepo->completeOrder($id);
        $this->triggerKitchenEvent('order.completed', $id);
    }

    public function uncompleteOrder(int $id): void
    {
        $this->orderRepo->uncompleteOrder($id);
        $this->triggerKitchenEvent('order.uncompleted', $id);
    }

    public function cancelOrder(int $id): void
    {
        $this->orderRepo->cancelOrder($id);
        $this->triggerKitchenEvent('order.cancelled', $id);
    }

    public function getNextNumber(): int
    {
        return $this->orderRepo->getNextNumber();
    }

    public function updateOrder(int $id, array $data): void
    {
        $this->orderRepo->updateOrder($id, $data);
        $this->triggerKitchenEvent('order.updated', $id);
    }

    public function addOrderItem(int $orderId, array $data): array
    {
        $item = $this->orderRepo->addOrderItem($orderId, $data);
        $this->triggerKitchenEvent('order.updated', $orderId);
        return $item;
    }

    public function updateOrderItem(int $orderId, int $itemId, array $data): void
    {
        $this->orderRepo->updateOrderItem($orderId, $itemId, $data);
        $this->triggerKitchenEvent('order.updated', $orderId);
    }

    public function removeOrderItem(int $orderId, int $itemId): void
    {
        $removed = $this->orderRepo->removeOrderItem($orderId, $itemId);
        if (!$removed) {
            throw new \DomainException('Não é possível remover o último item do pedido. Cancele o pedido inteiro.');
        }
        $this->triggerKitchenEvent('order.updated', $orderId);
    }

    /**
     * Enfileira a reimpressão de um pedido.
     *
     * @return bool false quando a impressão está bloqueada e nada foi enfileirado (spec 039).
     */
    public function printOrder(int $id): bool
    {
        // Garante que o pedido existe antes de enfileirar a impressão.
        Order::findOrFail($id);

        if ($this->printService->isPrintingBlocked()) {
            return false;
        }

        $this->jobService->dispatch('print', \App\Jobs\PrintOrderJob::class, [
            'order_id' => $id,
        ]);

        return true;
    }

    /**
     * Write a notification event for the SSE stream.
     */
    private function triggerKitchenEvent(string $type, int $orderId): void
    {
        $eventFile = sys_get_temp_dir() . '/gastroflow-events.json';
        $data = [
            'type'      => $type,
            'order_id'  => $orderId,
            'timestamp' => time(),
        ];
        @file_put_contents($eventFile, json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
