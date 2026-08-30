<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Job;
use App\Models\Order;
use App\Services\PrintService;
use App\Settings;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class PrintOrderJob
{
    /**
     * Called by JobService to execute the job.
     */
    public function handle(array $data, ?Job $job = null): void
    {
        $orderId = $data['order_id'] ?? null;
        if (!$orderId) {
            throw new \InvalidArgumentException('Missing order_id in job payload');
        }

        $order = Order::find($orderId);
        if (!$order) {
            throw new \RuntimeException("Order #{$orderId} not found");
        }

        $settings = new Settings();
        $logger = new Logger('print');
        $logDir = $settings->getLogDir();
        if (is_dir($logDir) || @mkdir($logDir, 0755, true) || is_dir($logDir)) {
            $logger->pushHandler(new StreamHandler($settings->getLogFile(), Logger::DEBUG));
        }
        $logger->pushHandler(new \Monolog\Handler\ErrorLogHandler());

        $jobContext = [];
        if ($job) {
            $jobContext = [
                'job_id'       => $job->id,
                'attempt'      => $job->attempts,
                'max_attempts' => $job->max_attempts,
            ];
        }

        $printService = new PrintService($logger, $settings);
        $printService->printOrder($order, $jobContext);
    }
}
