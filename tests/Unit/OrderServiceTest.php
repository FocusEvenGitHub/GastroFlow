<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Jobs\PrintOrderJob;
use App\Models\Order;
use App\Repositories\OrderRepository;
use App\Services\EventPublisher;
use App\Services\JobService;
use App\Services\OrderService;
use App\Services\PrintService;
use PHPUnit\Framework\TestCase;

class OrderServiceTest extends TestCase
{
    public function testCreateOrderWithValidDataDispatchesPrintJob(): void
    {
        $order = new Order();
        $order->id = 42;

        $data = [
            'order_number' => '5',
            'items' => [
                ['id' => 1, 'quantity' => 2],
            ],
        ];

        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->expects($this->once())
            ->method('createOrder')
            ->with($data)
            ->willReturn($order);

        $printService = $this->createMock(PrintService::class);
        $printService->method('isPrintingBlocked')->willReturn(false);

        $jobService = $this->createMock(JobService::class);
        $jobService->expects($this->once())
            ->method('dispatch')
            ->with('print', PrintOrderJob::class, ['order_id' => 42]);

        // Spec 041 — OrderService depends only on the EventPublisher interface, never on how
        // the event actually reaches the kitchen. A fake here proves that: this test touches
        // no database and no file.
        $events = $this->createMock(EventPublisher::class);
        $events->expects($this->once())
            ->method('publish')
            ->with('order.created', ['order_id' => 42]);

        $service = new OrderService($orderRepo, $printService, $jobService, $events);

        $result = $service->createOrder($data);

        $this->assertSame($order, $result);
    }

    /** Spec 041, AC1 — every mutating operation publishes its own event type. */
    public function testEachMutatingOperationPublishesItsEventType(): void
    {
        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->method('addOrderItem')->willReturn(['id' => 1]);
        $orderRepo->method('removeOrderItem')->willReturn(true);

        $events = $this->createMock(EventPublisher::class);
        $calls = [];
        $events->method('publish')->willReturnCallback(function (string $type, array $data) use (&$calls) {
            $calls[] = [$type, $data];
        });

        $service = new OrderService(
            $orderRepo,
            $this->createMock(PrintService::class),
            $this->createMock(JobService::class),
            $events
        );

        $service->completeOrder(1);
        $service->uncompleteOrder(2);
        $service->cancelOrder(3);
        $service->updateOrder(4, []);
        $service->addOrderItem(5, ['id' => 1, 'quantity' => 1]);
        $service->updateOrderItem(6, 1, ['quantity' => 2]);
        $service->removeOrderItem(7, 1);

        $this->assertSame([
            ['order.completed', ['order_id' => 1]],
            ['order.uncompleted', ['order_id' => 2]],
            ['order.cancelled', ['order_id' => 3]],
            ['order.updated', ['order_id' => 4]],
            ['order.updated', ['order_id' => 5]],
            ['order.updated', ['order_id' => 6]],
            ['order.updated', ['order_id' => 7]],
        ], $calls);
    }
}
