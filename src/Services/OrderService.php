<?php

namespace App\Services;

use App\Repositories\OrderRepository;
use App\Models\Order;

class OrderService
{
    private OrderRepository $orderRepo;
    private PrintService $printService;

    public function __construct(OrderRepository $orderRepo, PrintService $printService)
    {
        $this->orderRepo = $orderRepo;
        $this->printService = $printService;
    }

    public function getOrders(string $status): array
    {
        return $this->orderRepo->getOrdersByStatus($status);
    }

    public function createOrder(array $data): Order
    {
        $order = $this->orderRepo->createOrder($data);

        // Tenta imprimir o pedido (erro não quebra o fluxo)
        $this->printService->printOrder($order);

        return $order;
    }

    public function completeOrder(int $id): void
    {
        $this->orderRepo->completeOrder($id);
    }

    public function uncompleteOrder(int $id): void
    {
        $this->orderRepo->uncompleteOrder($id);
    }

    public function getNextNumber(): int
    {
        return $this->orderRepo->getNextNumber();
    }
}