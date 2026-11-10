<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Middleware\CorsMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

class CorsMiddlewareTest extends TestCase
{
    private function send(CorsMiddleware $middleware, string $method): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, '/api/menu')
            ->withHeader('Origin', 'http://evil.example');
        $handler = new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(200);
            }
        };

        return $middleware->process($request, $handler);
    }

    public function testDefaultSendsNoCorsHeaders(): void
    {
        foreach (['GET', 'OPTIONS'] as $method) {
            $response = $this->send(new CorsMiddleware(), $method);
            $this->assertFalse($response->hasHeader('Access-Control-Allow-Origin'), $method);
            $this->assertFalse($response->hasHeader('Access-Control-Allow-Methods'), $method);
        }
    }

    public function testConfiguredOriginIsSentOnResponsesAndPreflight(): void
    {
        foreach (['GET', 'OPTIONS'] as $method) {
            $response = $this->send(new CorsMiddleware('http://192.168.1.10:8080'), $method);
            $this->assertSame('http://192.168.1.10:8080', $response->getHeaderLine('Access-Control-Allow-Origin'), $method);
        }
    }

    public function testExplicitWildcardIsStillHonored(): void
    {
        $response = $this->send(new CorsMiddleware('*'), 'GET');
        $this->assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }
}
