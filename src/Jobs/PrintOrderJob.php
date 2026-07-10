<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Order;
use App\Services\PrintService;
use Monolog\Logger;
use Monolog\Handler\ErrorLogHandler;

class PrintOrderJob
{
    /**
     * Called by JobService to execute the job.
     */
    public function handle(array $data): void
    {
        $orderId = $data['order_id'] ?? null;
        if (!$orderId) {
            throw new \InvalidArgumentException('Missing order_id in job payload');
        }

        $order = Order::find($orderId);
        if (!$order) {
            throw new \RuntimeException("Order #{$orderId} not found");
        }

        $logger = new Logger('job');
        $logger->pushHandler(new ErrorLogHandler());

        $printService = new PrintService($logger);
        $printService->printOrder($order);
    }
}
