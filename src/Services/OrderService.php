<?php

namespace App\Services;

use App\Repositories\OrderRepository;
use App\Models\Order;

class OrderService
{
    private OrderRepository $orderRepo;

    public function __construct(OrderRepository $orderRepo)
    {
        $this->orderRepo = $orderRepo;
    }

    public function getOrders(string $status): array
    {
        return $this->orderRepo->getOrdersByStatus($status);
    }

    public function createOrder(array $data): Order
    {
        // Additional business rules could go here (e.g., check item availability)
        return $this->orderRepo->createOrder($data);
    }

    public function completeOrder(int $id): void
    {
        $this->orderRepo->completeOrder($id);
    }

    public function getNextNumber(): int
    {
        return $this->orderRepo->getNextNumber();
    }
}