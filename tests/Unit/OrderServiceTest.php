<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Jobs\PrintOrderJob;
use App\Models\Order;
use App\Repositories\OrderRepository;
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
            'table' => '5',
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

        $jobService = $this->createMock(JobService::class);
        $jobService->expects($this->once())
            ->method('dispatch')
            ->with('print', PrintOrderJob::class, ['order_id' => 42]);

        $service = new OrderService($orderRepo, $printService, $jobService);

        $result = $service->createOrder($data);

        $this->assertSame($order, $result);
    }
}
