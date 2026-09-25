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
    private EventPublisher $events;

    public function __construct(
        OrderRepository $orderRepo,
        PrintService $printService,
        JobService $jobService,
        EventPublisher $events
    ) {
        $this->orderRepo = $orderRepo;
        $this->printService = $printService;
        $this->jobService = $jobService;
        $this->events = $events;
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
        $this->events->publish('order.created', ['order_id' => $order->id]);

        return $order;
    }

    public function completeOrder(int $id): void
    {
        $this->orderRepo->completeOrder($id);
        $this->events->publish('order.completed', ['order_id' => $id]);
    }

    public function uncompleteOrder(int $id): void
    {
        $this->orderRepo->uncompleteOrder($id);
        $this->events->publish('order.uncompleted', ['order_id' => $id]);
    }

    public function cancelOrder(int $id): void
    {
        $this->orderRepo->cancelOrder($id);
        $this->events->publish('order.cancelled', ['order_id' => $id]);
    }

    public function getNextNumber(): int
    {
        return $this->orderRepo->getNextNumber();
    }

    public function updateOrder(int $id, array $data): void
    {
        $this->orderRepo->updateOrder($id, $data);
        $this->events->publish('order.updated', ['order_id' => $id]);
    }

    public function addOrderItem(int $orderId, array $data): array
    {
        $item = $this->orderRepo->addOrderItem($orderId, $data);
        $this->events->publish('order.updated', ['order_id' => $orderId]);
        return $item;
    }

    public function updateOrderItem(int $orderId, int $itemId, array $data): void
    {
        $this->orderRepo->updateOrderItem($orderId, $itemId, $data);
        $this->events->publish('order.updated', ['order_id' => $orderId]);
    }

    public function removeOrderItem(int $orderId, int $itemId): void
    {
        $removed = $this->orderRepo->removeOrderItem($orderId, $itemId);
        if (!$removed) {
            throw new \DomainException('Não é possível remover o último item do pedido. Cancele o pedido inteiro.');
        }
        $this->events->publish('order.updated', ['order_id' => $orderId]);
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
}
