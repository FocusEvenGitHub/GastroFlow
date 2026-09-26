<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Job;
use App\Models\Order;
use App\Services\PricingService;
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
        // Present only when JobService::dispatch() had a RequestContext with a request_id set
        // (i.e. the job was dispatched from an HTTP request) — closes the HTTP Request -> Order
        // -> Job -> Printing trace chain (spec 042). No handle() signature change: it just
        // rides along in the same $data array that already carries order_id.
        if (isset($data['request_id']) && is_string($data['request_id'])) {
            $jobContext['request_id'] = $data['request_id'];
        }

        $printService = new PrintService($logger, $settings, new PricingService());

        try {
            $printService->printOrder($order, $jobContext);
        } catch (\Throwable $e) {
            // Só conta quando o job falhou DE VEZ. Uma tentativa intermediária ainda vai ser
            // repetida pelo JobService (spec 008), e contá-la bloquearia a impressora por
            // causa de um único pedido (spec 039).
            if ($job !== null && $job->attempts >= $job->max_attempts) {
                $printService->recordPrintFailure($order->id, $e->getMessage(), $jobContext);
            }

            throw $e;
        }

        $printService->recordPrintSuccess();
    }
}
