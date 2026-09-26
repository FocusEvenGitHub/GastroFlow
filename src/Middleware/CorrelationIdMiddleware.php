<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Logging\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Generates or propagates a request-correlation id for every request (spec 042), stores it on
 * the shared RequestContext (picked up by RequestIdProcessor for every subsequent log line),
 * echoes it back on the response, and logs one summary line per request.
 */
class CorrelationIdMiddleware implements MiddlewareInterface
{
    public const HEADER_NAME = 'X-Request-Id';

    /**
     * A request header is attacker-controlled input: an unvalidated value could carry a
     * newline or other control character into a log line. Accepted values are bounded and
     * restricted to a safe charset; anything else is replaced with a freshly generated id.
     */
    private const VALID_ID_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/';

    public function __construct(
        private readonly RequestContext $context,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requestId = $this->resolveRequestId($request);
        $this->context->setRequestId($requestId);

        $start = microtime(true);
        $status = 500;

        try {
            $response = $handler->handle($request);
            $status = $response->getStatusCode();
            return $response->withHeader(self::HEADER_NAME, $requestId);
        } finally {
            $this->logger->info('HTTP request', [
                'method'      => $request->getMethod(),
                'path'        => $request->getUri()->getPath(),
                'status'      => $status,
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            ]);
        }
    }

    private function resolveRequestId(ServerRequestInterface $request): string
    {
        $incoming = $request->getHeaderLine(self::HEADER_NAME);

        if ($incoming !== '' && preg_match(self::VALID_ID_PATTERN, $incoming) === 1) {
            return $incoming;
        }

        return bin2hex(random_bytes(16));
    }
}
