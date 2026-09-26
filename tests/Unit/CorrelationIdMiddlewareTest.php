<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Logging\RequestContext;
use App\Middleware\CorrelationIdMiddleware;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

class StubHandler implements RequestHandlerInterface
{
    private ?\Throwable $throws = null;

    public function __construct(private int $status = 200)
    {
    }

    public static function thatThrows(\Throwable $e): self
    {
        $handler = new self();
        $handler->throws = $e;
        return $handler;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->throws !== null) {
            throw $this->throws;
        }

        return (new ResponseFactory())->createResponse($this->status);
    }
}

class CorrelationIdMiddlewareTest extends TestCase
{
    /** @return array{0: CorrelationIdMiddleware, 1: RequestContext, 2: TestHandler} */
    private function makeMiddleware(): array
    {
        $context = new RequestContext();
        $handler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handler);

        return [new CorrelationIdMiddleware($context, $logger), $context, $handler];
    }

    public function testGeneratesA32CharacterHexIdWhenNoHeaderIsSupplied(): void
    {
        [$middleware] = $this->makeMiddleware();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/menu');

        $response = $middleware->process($request, new StubHandler());

        $id = $response->getHeaderLine(CorrelationIdMiddleware::HEADER_NAME);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $id);
    }

    public function testPropagatesAValidIncomingHeaderUnchanged(): void
    {
        [$middleware] = $this->makeMiddleware();
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/menu')
            ->withHeader(CorrelationIdMiddleware::HEADER_NAME, 'my-valid-id-123');

        $response = $middleware->process($request, new StubHandler());

        $this->assertSame('my-valid-id-123', $response->getHeaderLine(CorrelationIdMiddleware::HEADER_NAME));
    }

    public function testReplacesAnInvalidIncomingHeaderWithAGeneratedId(): void
    {
        // A raw CR/LF is rejected even earlier, by Slim's own PSR-7 header validation
        // (RFC 7230) — confirmed empirically, not assumed. A space is RFC-7230-legal in a
        // header value (so it reaches this middleware) but outside the deliberately stricter
        // charset this spec accepts, which is what this test actually exercises.
        [$middleware] = $this->makeMiddleware();
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/menu')
            ->withHeader(CorrelationIdMiddleware::HEADER_NAME, 'bad id with spaces');

        $response = $middleware->process($request, new StubHandler());

        $id = $response->getHeaderLine(CorrelationIdMiddleware::HEADER_NAME);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $id);
        $this->assertNotSame('bad id with spaces', $id);
    }

    public function testStoresTheResolvedIdOnTheSharedContext(): void
    {
        [$middleware, $context] = $this->makeMiddleware();
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/menu')
            ->withHeader(CorrelationIdMiddleware::HEADER_NAME, 'my-valid-id-123');

        $middleware->process($request, new StubHandler());

        $this->assertSame('my-valid-id-123', $context->getRequestId());
    }

    public function testLogsOneSummaryLineWithMethodPathAndStatus(): void
    {
        [$middleware, , $handler] = $this->makeMiddleware();
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/orders');

        $middleware->process($request, new StubHandler(201));

        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame('HTTP request', $records[0]->message);
        $this->assertSame('POST', $records[0]->context['method']);
        $this->assertSame('/api/orders', $records[0]->context['path']);
        $this->assertSame(201, $records[0]->context['status']);
        $this->assertArrayHasKey('duration_ms', $records[0]->context);
    }

    /** Spec 042 AC4 — the summary log still fires (with status 500) even when the inner handler throws. */
    public function testLogsTheSummaryLineEvenWhenTheHandlerThrows(): void
    {
        [$middleware, , $handler] = $this->makeMiddleware();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/orders');

        try {
            $middleware->process($request, StubHandler::thatThrows(new \RuntimeException('boom')));
            $this->fail('Expected the exception to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame(500, $records[0]->context['status']);
    }
}
