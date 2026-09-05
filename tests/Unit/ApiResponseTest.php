<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\ApiResponse;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

class ApiResponseTest extends TestCase
{
    public function testErrorProducesTheStandardShape(): void
    {
        $response = ApiResponse::error(new Response(), 404, 'ORDER_NOT_FOUND', 'Pedido não encontrado');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame([
            'success' => false,
            'error'   => 'Pedido não encontrado',
            'code'    => 'ORDER_NOT_FOUND',
        ], $body);
    }

    public function testErrorMergesExtraFields(): void
    {
        $response = ApiResponse::error(new Response(), 400, 'VALIDATION_FAILED', 'Validation failed', [
            'messages' => ['items' => ['Items Invalid']],
        ]);

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(['items' => ['Items Invalid']], $body['messages']);
        $this->assertSame('VALIDATION_FAILED', $body['code']);
    }

    public function testUnicodeIsNotEscaped(): void
    {
        $response = ApiResponse::error(new Response(), 404, 'ORDER_NOT_FOUND', 'Pedido não encontrado');

        $this->assertStringContainsString('não encontrado', (string) $response->getBody());
    }
}
