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