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

        // Dispara job de impressão assíncrono (apenas se print_ticket for true)
        $printTicket = isset($data['print_ticket']) ? (bool) $data['print_ticket'] : true;
        if ($printTicket) {
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
            throw new \DomainException('Não é possível remover o último item do pedido. Exclua o pedido inteiro.');
        }
        $this->triggerKitchenEvent('order.updated', $orderId);
    }

    public function deleteOrder(int $id): void
    {
        $this->orderRepo->deleteOrder($id);
        $this->triggerKitchenEvent('order.deleted', $id);
    }

    public function printOrder(int $id): void
    {
        // Garante que o pedido existe antes de enfileirar a impressão.
        Order::findOrFail($id);
        $this->jobService->dispatch('print', \App\Jobs\PrintOrderJob::class, [
            'order_id' => $id,
        ]);
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