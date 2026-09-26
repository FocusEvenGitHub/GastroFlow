<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Attaches request_id (always, once CorrelationIdMiddleware has run) and user_id (only when
 * authenticated) to every log record's extra context, without every log call site needing to
 * pass them explicitly (spec 042).
 */
class RequestIdProcessor implements ProcessorInterface
{
    public function __construct(private readonly RequestContext $context)
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $requestId = $this->context->getRequestId();
        if ($requestId !== null) {
            $record->extra['request_id'] = $requestId;
        }

        $userId = $this->context->getUserId();
        if ($userId !== null) {
            $record->extra['user_id'] = $userId;
        }

        return $record;
    }
}
